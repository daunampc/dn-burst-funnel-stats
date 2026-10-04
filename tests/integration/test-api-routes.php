<?php

require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/api-helpers.php';

/**
 * Two sessions (VN direct, US referral) with one pageview each, today.
 */
function dn_bfs_it_api_seed_today() {
	$now   = dn_bfs_now();
	$today = wp_date( 'Y-m-d', $now );

	update_option( 'dnbfs_last_aggregated_date', dn_bfs_date_shift( $today, -1 ), false );

	foreach ( array( array( 'VN', 'direct', 120 ), array( 'US', 'referral', 90 ) ) as $row ) {
		$session = dn_bfs_it_seed_session( array( 'started_at' => $now - $row[2], 'country' => $row[0], 'channel' => $row[1] ) );
		dn_bfs_it_seed_pageview( $session, '/', $now - $row[2] );
	}

	return $today;
}

dn_bfs_it(
	'public routes are registered for GET only',
	function () {
		$routes = rest_get_server()->get_routes( 'dnbfs/v1' );

		foreach ( array( 'meta', 'stats/summary', 'stats/timeseries', 'stats/breakdown', 'stats/funnel', 'stats/realtime' ) as $endpoint ) {
			dn_bfs_assert_true( isset( $routes[ '/dnbfs/v1/' . $endpoint ] ), $endpoint );
			dn_bfs_assert_same( array( 'GET' => true ), $routes[ '/dnbfs/v1/' . $endpoint ][0]['methods'], $endpoint );
		}

		dn_bfs_assert_same( 404, rest_do_request( new WP_REST_Request( 'POST', '/dnbfs/v1/meta' ) )->get_status(), 'POST' );
	}
);

dn_bfs_it(
	'meta describes the plugin, the key and the API limits',
	function () {
		$created  = dn_bfs_it_api_key( array( 'scopes' => array( 'stats:read' ) ) );
		$response = dn_bfs_it_api_get( 'meta', array(), $created['key'], 'header' );
		$body     = $response->get_data();

		dn_bfs_assert_same( 200, $response->get_status() );
		dn_bfs_assert_same( array( 'data', 'meta' ), array_keys( $body ) );
		dn_bfs_assert_same( 1, $body['data']['api_version'] );
		dn_bfs_assert_same( $created['prefix'], $body['data']['key']['prefix'] );
		dn_bfs_assert_same( array( 'stats:read' ), $body['data']['key']['scopes'] );
		dn_bfs_assert_same( 366, $body['data']['max_range_days'] );
		dn_bfs_assert_same( dn_bfs_report_dimensions(), $body['data']['dimensions'] );
		dn_bfs_assert_same( dn_bfs_filter_dimensions(), $body['data']['filters'] );
		dn_bfs_assert_same( wp_timezone_string(), $body['meta']['timezone'] );
		dn_bfs_assert_same( get_woocommerce_currency(), $body['meta']['currency'] );
		dn_bfs_assert_same( null, $body['meta']['range'] );
		dn_bfs_assert_same( false, $body['meta']['estimated'] );
		dn_bfs_assert_same( 'no-store', $response->get_headers()['Cache-Control'] );
	}
);

dn_bfs_it_today(
	'summary returns the envelope with totals and an optional comparison',
	function () {
		$created = dn_bfs_it_api_key();
		$today   = dn_bfs_it_api_seed_today();

		$response = dn_bfs_it_api_get( 'stats/summary', array( 'start' => $today, 'end' => $today ), $created['key'] );
		$body     = $response->get_data();

		dn_bfs_assert_same( 200, $response->get_status() );
		dn_bfs_assert_same( 2, $body['data']['current']['sessions'] );
		dn_bfs_assert_same( 2, $body['data']['current']['visitors'] );
		dn_bfs_assert_same( null, $body['data']['previous'] );
		dn_bfs_assert_same( null, $body['data']['previous_range'] );
		dn_bfs_assert_same( array(), (array) $body['data']['change'] );
		dn_bfs_assert_same( array( 'start' => $today, 'end' => $today ), $body['meta']['range'] );
		dn_bfs_assert_same( false, $body['meta']['estimated'] );

		$compared = dn_bfs_it_api_get( 'stats/summary', array( 'start' => $today, 'end' => $today, 'compare' => 'previous_period' ), $created['key'] )->get_data();
		$yesterday = dn_bfs_date_shift( $today, -1 );
		dn_bfs_assert_same( 0, $compared['data']['previous']['sessions'] );
		dn_bfs_assert_same( array( 'start' => $yesterday, 'end' => $yesterday ), $compared['data']['previous_range'] );
		dn_bfs_assert_same( 100.0, $compared['data']['change']->sessions );

		$filtered = dn_bfs_it_api_get( 'stats/summary', array( 'start' => $today, 'end' => $today, 'filter' => array( 'country' => 'VN' ) ), $created['key'] )->get_data();
		dn_bfs_assert_same( 1, $filtered['data']['current']['sessions'], 'filter[country]' );
	}
);

dn_bfs_it_today(
	'timeseries, breakdown, funnel and realtime return their data',
	function () {
		$created = dn_bfs_it_api_key();
		$today   = dn_bfs_it_api_seed_today();
		$range   = array( 'start' => $today, 'end' => $today );

		$series = dn_bfs_it_api_get( 'stats/timeseries', $range + array( 'metrics' => 'sessions,orders' ), $created['key'] )->get_data();
		dn_bfs_assert_same( array( $today ), $series['data']['labels'] );
		dn_bfs_assert_same( array( 'sessions', 'orders' ), array_keys( $series['data']['series'] ) );
		dn_bfs_assert_same( array( 2 ), $series['data']['series']['sessions'] );

		$table = dn_bfs_it_api_get( 'stats/breakdown', $range + array( 'dimension' => 'country', 'orderby' => 'sessions', 'limit' => '1', 'page' => '2' ), $created['key'] )->get_data();
		dn_bfs_assert_same( 'country', $table['data']['dimension'] );
		dn_bfs_assert_same( 2, $table['data']['total'] );
		dn_bfs_assert_same( 2, $table['data']['page'] );
		dn_bfs_assert_same( 1, $table['data']['limit'] );
		dn_bfs_assert_same( 2, $table['data']['pages'] );
		dn_bfs_assert_same( 1, count( $table['data']['rows'] ) );
		dn_bfs_assert_same( 'VN', $table['data']['rows'][0]['dim_value'], 'ties sort by value, page 2 holds VN' );

		$funnel = dn_bfs_it_api_get( 'stats/funnel', $range, $created['key'] )->get_data();
		dn_bfs_assert_same( array( 'visitors', 'product_views', 'atc', 'carts', 'checkouts', 'orders' ), array_column( $funnel['data']['steps'], 'key' ) );
		dn_bfs_assert_same( 2, $funnel['data']['steps'][0]['value'] );
		dn_bfs_assert_same( array( 'start' => $today, 'end' => $today ), $funnel['meta']['range'] );

		$live = dn_bfs_it_api_get( 'stats/realtime', array(), $created['key'] )->get_data();
		dn_bfs_assert_same( 2, $live['data']['online'] );
		dn_bfs_assert_same( null, $live['meta']['range'] );
	}
);

dn_bfs_it(
	'invalid parameters answer 422 with a code and message body',
	function () {
		$created = dn_bfs_it_api_key();
		$day     = wp_date( 'Y-m-d', dn_bfs_now() );
		$range   = array( 'start' => $day, 'end' => $day );

		update_option( 'dnbfs_last_aggregated_date', dn_bfs_date_shift( $day, -1 ), false );

		$cases = array(
			array( 'stats/summary', array(), 'invalid_date' ),
			array( 'stats/summary', array( 'start' => '2026-02-30', 'end' => '2026-03-01' ), 'invalid_date' ),
			array( 'stats/summary', array( 'start' => '2025-01-01', 'end' => '2026-01-02' ), 'range_too_long' ),
			array( 'stats/summary', $range + array( 'compare' => 'last_week' ), 'invalid_compare' ),
			array( 'stats/summary', $range + array( 'filter' => array( 'bogus' => 'x' ) ), 'invalid_filter' ),
			array( 'stats/timeseries', $range + array( 'metrics' => 'sessions,nope' ), 'invalid_metric' ),
			array( 'stats/breakdown', $range, 'invalid_dimension' ),
			array( 'stats/breakdown', $range + array( 'dimension' => 'country', 'orderby' => 'nope' ), 'invalid_orderby' ),
			array( 'stats/breakdown', $range + array( 'dimension' => 'country', 'order' => 'up' ), 'invalid_order' ),
			array( 'stats/breakdown', $range + array( 'dimension' => 'country', 'limit' => '501' ), 'invalid_limit' ),
			array( 'stats/breakdown', $range + array( 'dimension' => 'country', 'limit' => '0' ), 'invalid_limit' ),
			array( 'stats/breakdown', $range + array( 'dimension' => 'country', 'page' => '0' ), 'invalid_page' ),
			array( 'stats/summary', array( 'start' => dn_bfs_date_shift( $day, -365 ), 'end' => $day, 'filter' => array( 'channel' => 'direct', 'country' => 'VN' ) ), 'filter_out_of_retention' ),
		);

		foreach ( $cases as $case ) {
			$response = dn_bfs_it_api_get( $case[0], $case[1], $created['key'] );

			dn_bfs_assert_same( 422, $response->get_status(), $case[2] . ' status' );
			dn_bfs_assert_same( $case[2], $response->get_data()['code'], $case[0] );
			dn_bfs_assert_same( array( 'code', 'message' ), array_keys( $response->get_data() ), $case[2] . ' body' );
		}

		dn_bfs_assert_same( 200, dn_bfs_it_api_get( 'stats/summary', array( 'start' => '2025-01-01', 'end' => '2026-01-01' ), $created['key'] )->get_status(), '366 days' );
	}
);

dn_bfs_it(
	'authentication failures answer 401, 403 and 429 with the spec body',
	function () {
		dn_bfs_it_set_now( 1800000010 );

		$missing = dn_bfs_it_api_get( 'meta' );
		dn_bfs_assert_same( 401, $missing->get_status() );
		dn_bfs_assert_same( array( 'code' => $missing->get_data()['code'], 'message' => $missing->get_data()['message'] ), $missing->get_data() );
		dn_bfs_assert_same( 'missing_key', $missing->get_data()['code'] );
		dn_bfs_assert_true( isset( $missing->get_headers()['WWW-Authenticate'] ), 'WWW-Authenticate' );

		$scoped = dn_bfs_it_api_key( array( 'scopes' => array( 'realtime:read' ), 'rate_limit' => '1' ) );
		$denied = dn_bfs_it_api_get( 'stats/summary', array(), $scoped['key'] );
		dn_bfs_assert_same( 403, $denied->get_status() );
		dn_bfs_assert_same( 'insufficient_scope', $denied->get_data()['code'] );

		dn_bfs_assert_same( 200, dn_bfs_it_api_get( 'meta', array(), $scoped['key'] )->get_status(), 'within the limit' );

		$limited = dn_bfs_it_api_get( 'meta', array(), $scoped['key'] );
		dn_bfs_assert_same( 429, $limited->get_status() );
		dn_bfs_assert_same( 'rate_limited', $limited->get_data()['code'] );
		dn_bfs_assert_same( '50', $limited->get_headers()['Retry-After'] );
	}
);

/**
 * Runs $callback as if a persistent object cache were installed (the API
 * response cache is only used then). Transients then live in the runtime cache.
 */
function dn_bfs_it_with_object_cache( $callback ) {
	$previous = wp_using_ext_object_cache( true );

	try {
		$callback();
	} finally {
		// The global starts as null in WP-CLI, and passing null would not reset it.
		wp_using_ext_object_cache( (bool) $previous );
		wp_cache_flush();
	}
}

function dn_bfs_it_api_cache_rows() {
	global $wpdb;

	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_dnbfs_api_c_' ) . '%' ) );
}

dn_bfs_it_today(
	'with a persistent object cache stats responses are cached per endpoint and parameters; realtime is not; purge clears the cache',
	function () {
		dn_bfs_it_with_object_cache(
			function () {
				$created = dn_bfs_it_api_key();
				$today   = dn_bfs_it_api_seed_today();
				$query   = array( 'start' => $today, 'end' => $today );

				dn_bfs_assert_same( 2, dn_bfs_it_api_get( 'stats/summary', $query, $created['key'] )->get_data()['data']['current']['sessions'] );
				dn_bfs_assert_same( 2, dn_bfs_it_api_get( 'stats/realtime', array(), $created['key'] )->get_data()['data']['online'] );

				$extra = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_now() - 30 ) );
				dn_bfs_it_seed_pageview( $extra, '/', dn_bfs_now() - 30 );

				dn_bfs_assert_same( 2, dn_bfs_it_api_get( 'stats/summary', $query, $created['key'] )->get_data()['data']['current']['sessions'], 'cached' );
				dn_bfs_assert_same( 3, dn_bfs_it_api_get( 'stats/summary', $query + array( 'compare' => 'previous_period' ), $created['key'] )->get_data()['data']['current']['sessions'], 'other parameters' );
				dn_bfs_assert_same( 3, dn_bfs_it_api_get( 'stats/funnel', $query, $created['key'] )->get_data()['data']['steps'][0]['value'], 'other endpoint' );
				dn_bfs_assert_same( 3, dn_bfs_it_api_get( 'stats/realtime', array(), $created['key'] )->get_data()['data']['online'], 'realtime not cached' );
				dn_bfs_assert_same( 0, dn_bfs_it_api_cache_rows(), 'nothing written to wp_options' );

				dn_bfs_assert_same( true, dn_bfs_purge_all_data( 'DELETE' ) );
				dn_bfs_assert_same( 0, dn_bfs_it_api_get( 'stats/summary', $query, $created['key'] )->get_data()['data']['current']['sessions'], 'purge clears the API cache' );
			}
		);
	}
);

dn_bfs_it_today(
	'without a persistent object cache API responses are not cached in wp_options',
	function () {
		$created = dn_bfs_it_api_key();
		$today   = dn_bfs_it_api_seed_today();
		$query   = array( 'start' => $today, 'end' => $today );

		dn_bfs_assert_same( 2, dn_bfs_it_api_get( 'stats/summary', $query, $created['key'] )->get_data()['data']['current']['sessions'] );
		dn_bfs_assert_same( 0, dn_bfs_it_api_cache_rows(), 'no API cache transient' );

		$extra = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_now() - 30 ) );
		dn_bfs_it_seed_pageview( $extra, '/', dn_bfs_now() - 30 );

		dn_bfs_assert_same( 3, dn_bfs_it_api_get( 'stats/summary', $query, $created['key'] )->get_data()['data']['current']['sessions'], 'fresh result' );
	}
);

dn_bfs_it_today(
	'a cached stats response still enforces the key scope',
	function () {
		dn_bfs_it_with_object_cache(
			function () {
				$stats    = dn_bfs_it_api_key( array( 'scopes' => array( 'stats:read' ) ) );
				$realtime = dn_bfs_it_api_key( array( 'name' => 'Realtime only', 'scopes' => array( 'realtime:read' ) ) );
				$today    = dn_bfs_it_api_seed_today();
				$query    = array( 'start' => $today, 'end' => $today );

				dn_bfs_assert_same( 200, dn_bfs_it_api_get( 'stats/summary', $query, $stats['key'] )->get_status(), 'warm' );

				$extra = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_now() - 30 ) );
				dn_bfs_it_seed_pageview( $extra, '/', dn_bfs_now() - 30 );
				dn_bfs_assert_same( 2, dn_bfs_it_api_get( 'stats/summary', $query, $stats['key'] )->get_data()['data']['current']['sessions'], 'served from the cache' );

				$denied = dn_bfs_it_api_get( 'stats/summary', $query, $realtime['key'] );
				dn_bfs_assert_same( 403, $denied->get_status() );
				dn_bfs_assert_same( array( 'code' => 'insufficient_scope', 'message' => $denied->get_data()['message'] ), $denied->get_data() );
			}
		);
	}
);

dn_bfs_it(
	'endpoint lookup ignores route case, so case variants are treated as the real endpoint',
	function () {
		dn_bfs_assert_same( 'stats/summary', dn_bfs_api_endpoint_from_route( '/dnbfs/v1/STATS/Summary' ) );
		dn_bfs_assert_same( 'openapi.json', dn_bfs_api_endpoint_from_route( '/DNBFS/v1/OpenAPI.json/' ) );
		dn_bfs_assert_same( '', dn_bfs_api_endpoint_from_route( '/dnbfs/v1/nope' ) );

		rest_get_server();
		dn_bfs_api_strip_cors( false, null, new WP_REST_Request( 'GET', '/dnbfs/v1/STATS/summary' ) );
		dn_bfs_assert_true( false === has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ), 'CORS stripped for a case variant' );
		add_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

		dn_bfs_assert_same( 200, dn_bfs_it_api_get( 'STATS/realtime', array(), dn_bfs_it_api_key()['key'] )->get_status(), 'case variant answers' );
	}
);

dn_bfs_it(
	'public routes never send CORS headers; the collector keeps WordPress defaults',
	function () {
		rest_get_server();
		dn_bfs_assert_true( false !== has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ), 'core CORS filter present' );

		dn_bfs_api_strip_cors( false, null, new WP_REST_Request( 'POST', '/dnbfs/v1/collect' ) );
		dn_bfs_assert_true( false !== has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ), 'collector untouched' );

		dn_bfs_api_strip_cors( false, null, new WP_REST_Request( 'GET', '/dnbfs/v1/stats/summary' ) );
		dn_bfs_assert_true( false === has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ), 'removed for the public API' );

		add_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
	}
);
