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

function dn_bfs_api_openapi_operation( $summary, $scope, $parameters, $data_schema ) {
	$error     = array( '$ref' => '#/components/responses/Error' );
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
		'401' => $error,
		'403' => $error,
	);

	if ( ! empty( $parameters ) ) {
		$responses['422'] = $error;
	}

	$responses['429'] = $error;

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
	$metrics = array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() );
	$period  = array( 'type' => 'object', 'nullable' => true, 'properties' => array( 'start' => $date, 'end' => $date ) );
	$error   = array( '$ref' => '#/components/responses/Error' );
	$range   = array(
		dn_bfs_api_openapi_param( 'start', 'First day, YYYY-MM-DD in the store timezone.', $date, true ),
		dn_bfs_api_openapi_param( 'end', 'Last day, YYYY-MM-DD in the store timezone. At most ' . dn_bfs_api_max_range_days() . ' days including both ends.', $date, true ),
		array(
			'name'        => 'filter',
			'in'          => 'query',
			'style'       => 'deepObject',
			'explode'     => true,
			'description' => 'filter[dimension]=value. Dimensions: ' . implode( ', ', dn_bfs_filter_dimensions() ) . '. Two or more filters only work while raw data is kept (422 filter_out_of_retention otherwise).',
			'schema'      => array( 'type' => 'object', 'additionalProperties' => $string ),
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

	return array(
		'openapi'    => '3.0.3',
		'info'       => array(
			'title'       => 'DN Burst Funnel Stats API',
			'version'     => DN_BURST_FUNNEL_STATS_VERSION,
			'description' => 'Read-only WooCommerce funnel statistics for server-to-server use: HTTPS, API key, no CORS. Successful statistics are cached for 60 seconds.',
		),
		'servers'    => array( array( 'url' => untrailingslashit( rest_url( dn_bfs_api_namespace() ) ) ) ),
		'security'   => array( array( 'bearerAuth' => array() ), array( 'keyHeader' => array() ) ),
		'paths'      => array(
			'/meta'             => array(
				'get' => dn_bfs_api_openapi_operation( 'Plugin, key and API limits', '', array(), array( 'type' => 'object' ) ),
			),
			'/stats/summary'    => array(
				'get' => dn_bfs_api_openapi_operation(
					'Totals for a date range, with an optional comparison',
					'stats:read',
					array_merge( $range, array( dn_bfs_api_openapi_param( 'compare', 'Comparison period.', array( 'type' => 'string', 'enum' => array( 'none', 'previous_period', 'previous_year' ), 'default' => 'none' ) ) ) ),
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
					array_merge( $range, array( dn_bfs_api_openapi_param( 'metrics', 'Comma-separated metrics: ' . implode( ', ', $metrics ) . '.', array( 'type' => 'string', 'default' => 'sessions,orders,revenue' ) ) ) ),
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
							dn_bfs_api_openapi_param( 'limit', 'Rows per page.', array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 25 ) ),
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
						'401' => $error,
						'403' => $error,
						'429' => $error,
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
				'Error' => array(
					'type'       => 'object',
					'required'   => array( 'code', 'message' ),
					'properties' => array( 'code' => $string, 'message' => $string ),
				),
				'Meta'  => array(
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
				'Error' => array(
					'description' => '401 missing/invalid/revoked key; 403 missing scope, IP not allowed or HTTPS required; 422 invalid parameters; 429 rate limited (see Retry-After).',
					'content'     => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/Error' ) ) ),
				),
			),
		),
	);
}

function dn_bfs_api_endpoint_openapi( $params, $key, $now ) {
	unset( $params, $key, $now );

	return dn_bfs_api_openapi_document();
}
