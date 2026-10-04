<?php
/**
 * Public read-only REST API (dnbfs/v1): routes, parameters, envelope and cache.
 *
 * Every route shares one callback that authenticates the key itself, so that
 * errors keep the documented {code, message} body instead of WordPress's
 * {code, message, data} shape.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_api_namespace() {
	return 'dnbfs/v1';
}

function dn_bfs_api_max_range_days() {
	return 366;
}

function dn_bfs_api_cache_ttl() {
	return (int) apply_filters( 'dn_bfs_api_cache_ttl', 60 );
}

/**
 * Endpoint => scope ('' = any valid key), handler and whether results are cached.
 */
function dn_bfs_api_endpoints() {
	return array(
		'meta'             => array( 'scope' => '', 'handler' => 'dn_bfs_api_endpoint_meta', 'cache' => false ),
		'stats/summary'    => array( 'scope' => 'stats:read', 'handler' => 'dn_bfs_api_endpoint_summary', 'cache' => true ),
		'stats/timeseries' => array( 'scope' => 'stats:read', 'handler' => 'dn_bfs_api_endpoint_timeseries', 'cache' => true ),
		'stats/breakdown'  => array( 'scope' => 'stats:read', 'handler' => 'dn_bfs_api_endpoint_breakdown', 'cache' => true ),
		'stats/funnel'     => array( 'scope' => 'stats:read', 'handler' => 'dn_bfs_api_endpoint_funnel', 'cache' => true ),
		'stats/realtime'   => array( 'scope' => 'realtime:read', 'handler' => 'dn_bfs_api_endpoint_realtime', 'cache' => false ),
	);
}

function dn_bfs_api_register_routes() {
	foreach ( array_keys( dn_bfs_api_endpoints() ) as $endpoint ) {
		register_rest_route(
			dn_bfs_api_namespace(),
			'/' . preg_quote( $endpoint, '@' ),
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'dn_bfs_api_rest_callback',
				// API keys are checked inside the callback (see the file header).
				'permission_callback' => '__return_true',
			)
		);
	}
}
add_action( 'rest_api_init', 'dn_bfs_api_register_routes' );

function dn_bfs_api_endpoint_from_route( $route ) {
	$prefix = '/' . dn_bfs_api_namespace() . '/';
	$route  = untrailingslashit( (string) $route );

	if ( 0 !== strpos( $route, $prefix ) ) {
		return '';
	}

	$endpoint = substr( $route, strlen( $prefix ) );

	return array_key_exists( $endpoint, dn_bfs_api_endpoints() ) ? $endpoint : '';
}

function dn_bfs_api_query_text( $query, $name ) {
	return isset( $query[ $name ] ) && is_scalar( $query[ $name ] ) ? trim( (string) $query[ $name ] ) : '';
}

/**
 * Whole number in [1, $max]; $default when empty; null when invalid.
 */
function dn_bfs_api_positive_int( $value, $default, $max ) {
	if ( '' === $value ) {
		return $default;
	}

	if ( ! ctype_digit( $value ) || strlen( $value ) > 6 || (int) $value < 1 || (int) $value > $max ) {
		return null;
	}

	return (int) $value;
}

function dn_bfs_api_breakdown_params( $query ) {
	$dimension = dn_bfs_api_query_text( $query, 'dimension' );

	if ( ! in_array( $dimension, dn_bfs_report_dimensions(), true ) ) {
		/* translators: %s: comma-separated dimension names. */
		return dn_bfs_request_error( 'invalid_dimension', sprintf( __( 'Use one of these dimensions: %s.', 'dn-burst-funnel-stats' ), implode( ', ', dn_bfs_report_dimensions() ) ) );
	}

	$orderby = dn_bfs_api_query_text( $query, 'orderby' );

	if ( '' !== $orderby && ! in_array( $orderby, array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() ), true ) ) {
		/* translators: %s: metric name. */
		return dn_bfs_request_error( 'invalid_orderby', sprintf( __( 'Cannot sort by %s.', 'dn-burst-funnel-stats' ), $orderby ) );
	}

	$order = strtolower( dn_bfs_api_query_text( $query, 'order' ) );
	$order = '' === $order ? 'desc' : $order;

	if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
		return dn_bfs_request_error( 'invalid_order', __( 'Order must be asc or desc.', 'dn-burst-funnel-stats' ) );
	}

	$limit = dn_bfs_api_positive_int( dn_bfs_api_query_text( $query, 'limit' ), 25, 500 );

	if ( null === $limit ) {
		return dn_bfs_request_error( 'invalid_limit', __( 'Limit must be a whole number from 1 to 500.', 'dn-burst-funnel-stats' ) );
	}

	$page = dn_bfs_api_positive_int( dn_bfs_api_query_text( $query, 'page' ), 1, 100000 );

	if ( null === $page ) {
		return dn_bfs_request_error( 'invalid_page', __( 'Page must be a whole number starting at 1.', 'dn-burst-funnel-stats' ) );
	}

	return array(
		'dimension' => $dimension,
		'orderby'   => $orderby,
		'order'     => $order,
		'limit'     => $limit,
		'page'      => $page,
	);
}

/**
 * Validated, normalized parameters (also the cache key) for an endpoint.
 */
function dn_bfs_api_request_params( $endpoint, $query ) {
	if ( 0 !== strpos( $endpoint, 'stats/' ) || 'stats/realtime' === $endpoint ) {
		return array();
	}

	$query   = is_array( $query ) ? $query : array();
	$compare = 'stats/summary' === $endpoint ? dn_bfs_api_query_text( $query, 'compare' ) : '';
	$range   = dn_bfs_parse_range(
		array(
			'period'  => 'custom',
			'compare' => '' === $compare ? 'none' : $compare,
			'start'   => dn_bfs_api_query_text( $query, 'start' ),
			'end'     => dn_bfs_api_query_text( $query, 'end' ),
		),
		dn_bfs_api_max_range_days()
	);

	if ( is_wp_error( $range ) ) {
		return $range;
	}

	$filters = dn_bfs_parse_filters( array( 'filter' => isset( $query['filter'] ) ? $query['filter'] : array() ) );

	if ( is_wp_error( $filters ) ) {
		return $filters;
	}

	$params = array(
		'start'   => $range['custom_start'],
		'end'     => $range['custom_end'],
		'compare' => $range['compare'],
		'filters' => $filters,
	);

	if ( 'stats/timeseries' === $endpoint ) {
		$metrics = dn_bfs_parse_metrics( isset( $query['metrics'] ) ? $query['metrics'] : '' );

		if ( is_wp_error( $metrics ) ) {
			return $metrics;
		}

		$params['metrics'] = $metrics;
	}

	if ( 'stats/breakdown' === $endpoint ) {
		$table = dn_bfs_api_breakdown_params( $query );

		if ( is_wp_error( $table ) ) {
			return $table;
		}

		$params = array_merge( $params, $table );
	}

	return $params;
}

function dn_bfs_api_range( $params ) {
	return dn_bfs_calculate_date_range( 'custom', $params['compare'], $params['start'], $params['end'] );
}

function dn_bfs_api_envelope( $data, $params, $estimated ) {
	return array(
		'data' => $data,
		'meta' => array(
			'timezone'  => wp_timezone_string(),
			'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'range'     => isset( $params['start'] ) ? array( 'start' => $params['start'], 'end' => $params['end'] ) : null,
			'estimated' => (bool) $estimated,
		),
	);
}

function dn_bfs_api_endpoint_meta( $params, $key, $now ) {
	unset( $params );

	return dn_bfs_api_envelope(
		array(
			'api_version'          => 1,
			'plugin_version'       => DN_BURST_FUNNEL_STATS_VERSION,
			'site_url'             => home_url( '/' ),
			'key'                  => array(
				'name'       => $key['name'],
				'prefix'     => $key['prefix'],
				'scopes'     => $key['scopes'],
				'rate_limit' => $key['rate_limit'],
			),
			'metrics'              => array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() ),
			'dimensions'           => dn_bfs_report_dimensions(),
			'filters'              => dn_bfs_filter_dimensions(),
			'max_range_days'       => dn_bfs_api_max_range_days(),
			'last_aggregated_date' => (string) get_option( 'dnbfs_last_aggregated_date', '' ),
			'raw_available_from'   => dn_bfs_raw_available_from( $now ),
		),
		array(),
		false
	);
}

function dn_bfs_api_endpoint_summary( $params, $key, $now ) {
	unset( $key );

	$range  = dn_bfs_api_range( $params );
	$report = dn_bfs_report_summary( $range, $params['filters'], $now );

	if ( is_wp_error( $report ) ) {
		return $report;
	}

	$previous_range = null;

	if ( 'none' !== $params['compare'] ) {
		$previous_range = array(
			'start' => wp_date( 'Y-m-d', $range['previous_start'] ),
			'end'   => wp_date( 'Y-m-d', $range['previous_end'] ),
		);
	}

	return dn_bfs_api_envelope(
		array(
			'current'        => $report['current'],
			'previous'       => $report['previous'],
			'change'         => (object) $report['change'],
			'previous_range' => $previous_range,
		),
		$params,
		$report['estimated']
	);
}

function dn_bfs_api_endpoint_timeseries( $params, $key, $now ) {
	unset( $key );

	$report = dn_bfs_report_timeseries( dn_bfs_api_range( $params ), $params['metrics'], $params['filters'], $now );

	if ( is_wp_error( $report ) ) {
		return $report;
	}

	return dn_bfs_api_envelope(
		array(
			'labels' => $report['labels'],
			'series' => $report['series'],
		),
		$params,
		$report['estimated']
	);
}

function dn_bfs_api_endpoint_breakdown( $params, $key, $now ) {
	unset( $key );

	$report = dn_bfs_report_breakdown(
		dn_bfs_api_range( $params ),
		$params['dimension'],
		$params['filters'],
		$params['orderby'],
		$params['order'],
		$params['limit'],
		( $params['page'] - 1 ) * $params['limit'],
		$now
	);

	if ( is_wp_error( $report ) ) {
		return $report;
	}

	return dn_bfs_api_envelope(
		array(
			'dimension' => $params['dimension'],
			'rows'      => $report['rows'],
			'total'     => $report['total'],
			'page'      => $params['page'],
			'limit'     => $params['limit'],
			'pages'     => (int) ceil( $report['total'] / $params['limit'] ),
		),
		$params,
		$report['estimated']
	);
}

function dn_bfs_api_endpoint_funnel( $params, $key, $now ) {
	unset( $key );

	$summary = dn_bfs_report_summary( dn_bfs_api_range( $params ), $params['filters'], $now );

	if ( is_wp_error( $summary ) ) {
		return $summary;
	}

	return dn_bfs_api_envelope( array( 'steps' => dn_bfs_report_funnel_steps( $summary['current'] ) ), $params, $summary['estimated'] );
}

function dn_bfs_api_endpoint_realtime( $params, $key, $now ) {
	unset( $params, $key );

	return dn_bfs_api_envelope( dn_bfs_report_realtime( $now ), array(), false );
}

function dn_bfs_api_response( $body, $status = 200 ) {
	$response = new WP_REST_Response( $body, $status );
	$response->header( 'Cache-Control', 'no-store' );

	return $response;
}

function dn_bfs_api_error_response( $error ) {
	$data   = $error->get_error_data();
	$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

	// The shared request.php validators answer 400; the public API reports invalid parameters as 422.
	if ( 400 === $status ) {
		$status = 422;
	}

	$response = dn_bfs_api_response(
		array(
			'code'    => (string) $error->get_error_code(),
			'message' => $error->get_error_message(),
		),
		$status
	);

	if ( 401 === $status ) {
		$response->header( 'WWW-Authenticate', 'Bearer realm="dnbfs"' );
	}

	if ( 429 === $status && is_array( $data ) && isset( $data['retry_after'] ) ) {
		$response->header( 'Retry-After', (string) (int) $data['retry_after'] );
	}

	return $response;
}

function dn_bfs_api_rest_callback( WP_REST_Request $request ) {
	$endpoint = dn_bfs_api_endpoint_from_route( $request->get_route() );

	if ( '' === $endpoint ) {
		return dn_bfs_api_error_response( dn_bfs_request_error( 'rest_no_route', __( 'No API endpoint matches this URL.', 'dn-burst-funnel-stats' ), 404 ) );
	}

	$endpoints = dn_bfs_api_endpoints();
	$config    = $endpoints[ $endpoint ];
	$now       = dn_bfs_now();
	$key       = dn_bfs_api_authenticate( $request, $config['scope'], $now );

	if ( is_wp_error( $key ) ) {
		return dn_bfs_api_error_response( $key );
	}

	$params = dn_bfs_api_request_params( $endpoint, $request->get_query_params() );

	if ( is_wp_error( $params ) ) {
		return dn_bfs_api_error_response( $params );
	}

	$ttl       = $config['cache'] ? dn_bfs_api_cache_ttl() : 0;
	$cache_key = 'dnbfs_api_c_' . md5( (string) wp_json_encode( array( $endpoint, $params ) ) );

	if ( $ttl > 0 ) {
		$cached = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return dn_bfs_api_response( $cached );
		}
	}

	$body = call_user_func( $config['handler'], $params, $key, $now );

	if ( is_wp_error( $body ) ) {
		return dn_bfs_api_error_response( $body );
	}

	if ( $ttl > 0 ) {
		set_transient( $cache_key, $body, $ttl );
	}

	return dn_bfs_api_response( $body );
}

/**
 * Server-to-server API: never answer with CORS headers (core adds them in
 * rest_send_cors_headers at priority 10 and in WP_REST_Server::serve_request).
 */
function dn_bfs_api_strip_cors( $served, $result, $request ) {
	unset( $result );

	if ( ! $request instanceof WP_REST_Request || '' === dn_bfs_api_endpoint_from_route( $request->get_route() ) ) {
		return $served;
	}

	remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

	if ( ! headers_sent() ) {
		foreach ( array( 'Access-Control-Allow-Origin', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Headers', 'Access-Control-Expose-Headers' ) as $header ) {
			header_remove( $header );
		}
	}

	return $served;
}
add_filter( 'rest_pre_serve_request', 'dn_bfs_api_strip_cors', 0, 3 );
