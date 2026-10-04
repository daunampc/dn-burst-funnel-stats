<?php

require_once __DIR__ . '/api-helpers.php';

function dn_bfs_it_openapi_param( $operation, $name ) {
	foreach ( $operation['parameters'] as $param ) {
		if ( $name === $param['name'] ) {
			return $param;
		}
	}

	dn_bfs_assert_true( false, 'parameter ' . $name . ' documented' );

	return array();
}

function dn_bfs_it_openapi_codes( $doc, $component ) {
	$response = $doc['components']['responses'][ $component ];
	$codes    = $response['content']['application/json']['schema']['properties']['code']['enum'];

	foreach ( $codes as $code ) {
		dn_bfs_assert_true( false !== strpos( $response['description'], $code ), $component . ' description lists ' . $code );
	}

	return $codes;
}

dn_bfs_it(
	'openapi.json describes every endpoint and needs a key',
	function () {
		$created  = dn_bfs_it_api_key( array( 'scopes' => array( 'realtime:read' ) ) );
		$response = dn_bfs_it_api_get( 'openapi.json', array(), $created['key'] );
		$doc      = $response->get_data();

		dn_bfs_assert_same( 200, $response->get_status() );
		dn_bfs_assert_same( '3.0.3', $doc['openapi'] );
		dn_bfs_assert_same( untrailingslashit( rest_url( 'dnbfs/v1' ) ), $doc['servers'][0]['url'] );
		dn_bfs_assert_same( array( '/meta', '/stats/summary', '/stats/timeseries', '/stats/breakdown', '/stats/funnel', '/stats/realtime', '/openapi.json' ), array_keys( $doc['paths'] ) );
		dn_bfs_assert_same( array( 'bearerAuth', 'keyHeader' ), array_keys( $doc['components']['securitySchemes'] ) );
		dn_bfs_assert_same( 'X-DNBFS-Key', $doc['components']['securitySchemes']['keyHeader']['name'] );
		dn_bfs_assert_true( false !== wp_json_encode( $doc ), 'JSON encodable' );

		$breakdown = $doc['paths']['/stats/breakdown']['get'];
		dn_bfs_assert_same( array( 'start', 'end', 'filter', 'dimension', 'orderby', 'order', 'limit', 'page' ), array_column( $breakdown['parameters'], 'name' ) );

		$metrics = array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() );
		dn_bfs_assert_same( dn_bfs_report_dimensions(), dn_bfs_it_openapi_param( $breakdown, 'dimension' )['schema']['enum'] );
		dn_bfs_assert_same( $metrics, dn_bfs_it_openapi_param( $breakdown, 'orderby' )['schema']['enum'] );
		dn_bfs_assert_same( array( 'asc', 'desc' ), dn_bfs_it_openapi_param( $breakdown, 'order' )['schema']['enum'] );
		dn_bfs_assert_same( 500, dn_bfs_it_openapi_param( $breakdown, 'limit' )['schema']['maximum'] );
		dn_bfs_assert_same( 25, dn_bfs_it_openapi_param( $breakdown, 'limit' )['schema']['default'] );
		dn_bfs_assert_same( dn_bfs_filter_dimensions(), array_keys( dn_bfs_it_openapi_param( $breakdown, 'filter' )['schema']['properties'] ) );
		dn_bfs_assert_same( array( 'none', 'previous_period', 'previous_year' ), dn_bfs_it_openapi_param( $doc['paths']['/stats/summary']['get'], 'compare' )['schema']['enum'] );
		dn_bfs_assert_same( 'sessions,orders,revenue', dn_bfs_it_openapi_param( $doc['paths']['/stats/timeseries']['get'], 'metrics' )['schema']['default'] );

		$meta = $doc['paths']['/meta']['get']['responses']['200']['content']['application/json']['schema']['properties']['data'];
		dn_bfs_assert_same( array( 'api_version', 'plugin_version', 'site_url', 'key', 'metrics', 'dimensions', 'filters', 'max_range_days', 'last_aggregated_date', 'raw_available_from' ), array_keys( $meta['properties'] ) );

		$refs = array(
			'401' => 'Unauthorized',
			'403' => 'Forbidden',
			'422' => 'Unprocessable',
			'429' => 'RateLimited',
		);

		foreach ( $refs as $status => $component ) {
			dn_bfs_assert_same( '#/components/responses/' . $component, $doc['paths']['/stats/summary']['get']['responses'][ $status ]['$ref'], 'summary ' . $status );
		}

		dn_bfs_assert_true( ! isset( $doc['paths']['/stats/realtime']['get']['responses']['422'] ), 'realtime has no 422' );
		dn_bfs_assert_same( '#/components/responses/RateLimited', $doc['paths']['/stats/realtime']['get']['responses']['429']['$ref'] );

		dn_bfs_assert_same( array( 'missing_key', 'invalid_key' ), dn_bfs_it_openapi_codes( $doc, 'Unauthorized' ) );
		dn_bfs_assert_same( array( 'insufficient_scope', 'ip_not_allowed', 'https_required' ), dn_bfs_it_openapi_codes( $doc, 'Forbidden' ) );
		dn_bfs_assert_same(
			array( 'invalid_date', 'range_too_long', 'invalid_compare', 'invalid_filter', 'invalid_metric', 'invalid_dimension', 'invalid_orderby', 'invalid_order', 'invalid_limit', 'invalid_page', 'filter_out_of_retention' ),
			dn_bfs_it_openapi_codes( $doc, 'Unprocessable' )
		);
		dn_bfs_assert_same( array( 'rate_limited' ), dn_bfs_it_openapi_codes( $doc, 'RateLimited' ) );
		dn_bfs_assert_true( isset( $doc['components']['responses']['Unauthorized']['headers']['WWW-Authenticate'] ), '401 declares WWW-Authenticate' );
		dn_bfs_assert_true( isset( $doc['components']['responses']['RateLimited']['headers']['Retry-After'] ), '429 declares Retry-After' );

		dn_bfs_assert_same( 401, dn_bfs_it_api_get( 'openapi.json' )->get_status(), 'needs a key' );
	}
);
