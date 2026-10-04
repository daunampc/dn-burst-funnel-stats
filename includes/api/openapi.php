<?php
/**
 * OpenAPI 3.0 description of the public REST API, served at /openapi.json.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_api_openapi_param( $name, $description, $schema, $required = false ) {
	return array(
		'name'        => $name,
		'in'          => 'query',
		'required'    => (bool) $required,
		'description' => $description,
		'schema'      => $schema,
	);
}

function dn_bfs_api_openapi_error_response( $description, $codes, $headers = array() ) {
	$response = array(
		'description' => $description . ' Error codes: ' . implode( ', ', $codes ) . '.',
		'content'     => array(
			'application/json' => array(
				'schema' => array(
					'type'       => 'object',
					'required'   => array( 'code', 'message' ),
					'properties' => array(
						'code'    => array( 'type' => 'string', 'enum' => $codes ),
						'message' => array( 'type' => 'string' ),
					),
				),
			),
		),
	);

	if ( ! empty( $headers ) ) {
		$response['headers'] = $headers;
	}

	return $response;
}

function dn_bfs_api_openapi_operation( $summary, $scope, $parameters, $data_schema ) {
	$responses = array(
		'200' => array(
			'description' => 'OK',
			'content'     => array(
				'application/json' => array(
					'schema' => array(
						'type'       => 'object',
						'required'   => array( 'data', 'meta' ),
						'properties' => array(
							'data' => $data_schema,
							'meta' => array( '$ref' => '#/components/schemas/Meta' ),
						),
					),
				),
			),
		),
		'401' => array( '$ref' => '#/components/responses/Unauthorized' ),
		'403' => array( '$ref' => '#/components/responses/Forbidden' ),
	);

	if ( ! empty( $parameters ) ) {
		$responses['422'] = array( '$ref' => '#/components/responses/Unprocessable' );
	}

	$responses['429'] = array( '$ref' => '#/components/responses/RateLimited' );

	return array(
		'summary'     => $summary,
		'description' => '' === $scope ? 'Any valid API key.' : 'Requires the ' . $scope . ' scope.',
		'parameters'  => $parameters,
		'responses'   => $responses,
	);
}

function dn_bfs_api_openapi_document() {
	$date    = array( 'type' => 'string', 'format' => 'date' );
	$string  = array( 'type' => 'string' );
	$integer = array( 'type' => 'integer' );
	$numbers = array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'number' ) );
	$metrics = dn_bfs_metric_names();
	$period  = array( 'type' => 'object', 'nullable' => true, 'properties' => array( 'start' => $date, 'end' => $date ) );
	$range   = array(
		dn_bfs_api_openapi_param( 'start', 'First day, YYYY-MM-DD in the store timezone.', $date, true ),
		dn_bfs_api_openapi_param( 'end', 'Last day, YYYY-MM-DD in the store timezone. At most ' . dn_bfs_api_max_range_days() . ' days including both ends.', $date, true ),
		array(
			'name'        => 'filter',
			'in'          => 'query',
			'style'       => 'deepObject',
			'explode'     => true,
			'description' => 'filter[dimension]=value. Dimensions: ' . implode( ', ', dn_bfs_filter_dimensions() ) . '. Two or more filters only work while raw data is kept (422 filter_out_of_retention otherwise).',
			'schema'      => array(
				'type'                 => 'object',
				'properties'           => array_fill_keys( dn_bfs_filter_dimensions(), $string ),
				'additionalProperties' => false,
			),
		),
	);
	$rows    = array(
		'type'  => 'array',
		'items' => array(
			'type'                 => 'object',
			'properties'           => array( 'dim_value' => $string, 'label' => $string ),
			'additionalProperties' => array( 'type' => 'number' ),
		),
	);
	$visits  = function ( $name ) use ( $string, $integer ) {
		return array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( $name => $string, 'visitors' => $integer ) ) );
	};

	$meta    = array(
		'type'       => 'object',
		'properties' => array(
			'api_version'          => $integer,
			'plugin_version'       => $string,
			'site_url'             => array( 'type' => 'string', 'format' => 'uri' ),
			'key'                  => array(
				'type'       => 'object',
				'properties' => array(
					'name'       => $string,
					'prefix'     => $string,
					'scopes'     => array( 'type' => 'array', 'items' => $string ),
					'rate_limit' => array( 'type' => 'integer', 'description' => 'Requests per minute.' ),
				),
			),
			'metrics'              => array( 'type' => 'array', 'items' => $string ),
			'dimensions'           => array( 'type' => 'array', 'items' => $string ),
			'filters'              => array( 'type' => 'array', 'items' => $string ),
			'max_range_days'       => $integer,
			'last_aggregated_date' => array( 'type' => 'string', 'description' => 'YYYY-MM-DD, empty before the first aggregation.' ),
			'raw_available_from'   => array( 'type' => 'string', 'format' => 'date' ),
		),
	);

	return array(
		'openapi'    => '3.0.3',
		'info'       => array(
			'title'       => 'DN Burst Funnel Stats API',
			'version'     => DN_BURST_FUNNEL_STATS_VERSION,
			'description' => 'Read-only WooCommerce funnel statistics for server-to-server use: HTTPS, API key, no CORS. Successful statistics are cached for 60 seconds when the site has a persistent object cache.',
		),
		'servers'    => array( array( 'url' => untrailingslashit( rest_url( dn_bfs_api_namespace() ) ) ) ),
		'security'   => array( array( 'bearerAuth' => array() ), array( 'keyHeader' => array() ) ),
		'paths'      => array(
			'/meta'             => array(
				'get' => dn_bfs_api_openapi_operation( 'Plugin, key and API limits', '', array(), $meta ),
			),
			'/stats/summary'    => array(
				'get' => dn_bfs_api_openapi_operation(
					'Totals for a date range, with an optional comparison',
					'stats:read',
					array_merge( $range, array( dn_bfs_api_openapi_param( 'compare', 'Comparison period.', array( 'type' => 'string', 'enum' => dn_bfs_compare_modes(), 'default' => 'none' ) ) ) ),
					array(
						'type'       => 'object',
						'properties' => array(
							'current'        => $numbers,
							'previous'       => array_merge( $numbers, array( 'nullable' => true ) ),
							'change'         => $numbers,
							'previous_range' => $period,
						),
					)
				),
			),
			'/stats/timeseries' => array(
				'get' => dn_bfs_api_openapi_operation(
					'Daily values per metric',
					'stats:read',
					array_merge( $range, array( dn_bfs_api_openapi_param( 'metrics', 'Comma-separated metrics: ' . implode( ', ', $metrics ) . '.', array( 'type' => 'string', 'default' => implode( ',', dn_bfs_default_metrics() ) ) ) ) ),
					array(
						'type'       => 'object',
						'properties' => array(
							'labels' => array( 'type' => 'array', 'items' => $date ),
							'series' => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'array', 'items' => array( 'type' => 'number' ) ) ),
						),
					)
				),
			),
			'/stats/breakdown'  => array(
				'get' => dn_bfs_api_openapi_operation(
					'Rows for one dimension, sorted and paginated',
					'stats:read',
					array_merge(
						$range,
						array(
							dn_bfs_api_openapi_param( 'dimension', 'Dimension to group by.', array( 'type' => 'string', 'enum' => dn_bfs_report_dimensions() ), true ),
							dn_bfs_api_openapi_param( 'orderby', 'Metric to sort by.', array( 'type' => 'string', 'enum' => $metrics ) ),
							dn_bfs_api_openapi_param( 'order', 'Sort direction.', array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ) ),
							dn_bfs_api_openapi_param( 'limit', 'Rows per page.', array( 'type' => 'integer', 'minimum' => 1, 'maximum' => dn_bfs_api_limit_max(), 'default' => dn_bfs_api_limit_default() ) ),
							dn_bfs_api_openapi_param( 'page', 'Page number, starting at 1.', array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ) ),
						)
					),
					array(
						'type'       => 'object',
						'properties' => array(
							'dimension' => $string,
							'rows'      => $rows,
							'total'     => $integer,
							'page'      => $integer,
							'limit'     => $integer,
							'pages'     => $integer,
						),
					)
				),
			),
			'/stats/funnel'     => array(
				'get' => dn_bfs_api_openapi_operation(
					'Funnel steps: visitors, product views, add to cart, cart, checkout, orders',
					'stats:read',
					$range,
					array(
						'type'       => 'object',
						'properties' => array(
							'steps' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'key' => $string, 'value' => $integer ) ) ),
						),
					)
				),
			),
			'/stats/realtime'   => array(
				'get' => dn_bfs_api_openapi_operation(
					'Visitors online in the last 5 minutes (not cached)',
					'realtime:read',
					array(),
					array(
						'type'       => 'object',
						'properties' => array(
							'online'   => $integer,
							'pages'    => $visits( 'path' ),
							'channels' => $visits( 'channel' ),
						),
					)
				),
			),
			'/openapi.json'     => array(
				'get' => array(
					'summary'     => 'This document',
					'description' => 'Any valid API key.',
					'responses'   => array(
						'200' => array( 'description' => 'OpenAPI 3.0 document', 'content' => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ) ),
						'401' => array( '$ref' => '#/components/responses/Unauthorized' ),
						'403' => array( '$ref' => '#/components/responses/Forbidden' ),
						'429' => array( '$ref' => '#/components/responses/RateLimited' ),
					),
				),
			),
		),
		'components' => array(
			'securitySchemes' => array(
				'bearerAuth' => array( 'type' => 'http', 'scheme' => 'bearer', 'description' => 'Authorization: Bearer dnbfs_<prefix>_<secret>' ),
				'keyHeader'  => array( 'type' => 'apiKey', 'in' => 'header', 'name' => 'X-DNBFS-Key' ),
			),
			'schemas'         => array(
				'Meta' => array(
					'type'       => 'object',
					'required'   => array( 'timezone', 'currency', 'range', 'estimated' ),
					'properties' => array(
						'timezone'  => $string,
						'currency'  => $string,
						'range'     => $period,
						'estimated' => array( 'type' => 'boolean', 'description' => 'True when visitor counts were summed per day.' ),
					),
				),
			),
			'responses'       => array(
				'Unauthorized'  => dn_bfs_api_openapi_error_response( 'The API key is missing or not valid.', array( 'missing_key', 'invalid_key' ), array( 'WWW-Authenticate' => array( 'description' => 'Authentication challenge.', 'schema' => array( 'type' => 'string' ) ) ) ),
				'Forbidden'     => dn_bfs_api_openapi_error_response( 'The key is not allowed to make this request.', array( 'insufficient_scope', 'ip_not_allowed', 'https_required' ) ),
				'Unprocessable' => dn_bfs_api_openapi_error_response(
					'A query parameter is invalid.',
					array( 'invalid_date', 'range_too_long', 'invalid_compare', 'invalid_filter', 'invalid_metric', 'invalid_dimension', 'invalid_orderby', 'invalid_order', 'invalid_limit', 'invalid_page', 'filter_out_of_retention' )
				),
				'RateLimited'   => dn_bfs_api_openapi_error_response( 'The key exceeded its rate limit.', array( 'rate_limited' ), array( 'Retry-After' => array( 'description' => 'Seconds to wait before retrying.', 'schema' => array( 'type' => 'integer' ) ) ) ),
			),
		),
	);
}

function dn_bfs_api_endpoint_openapi( $params, $key, $now ) {
	unset( $params, $key, $now );

	return dn_bfs_api_openapi_document();
}
