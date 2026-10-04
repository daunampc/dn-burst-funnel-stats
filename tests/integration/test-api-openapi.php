<?php

require_once __DIR__ . '/api-helpers.php';

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
		dn_bfs_assert_same( array( 'start', 'end', 'filter', 'dimension', 'orderby', 'order', 'limit', 'page' ), array_column( $doc['paths']['/stats/breakdown']['get']['parameters'], 'name' ) );
		dn_bfs_assert_same( 500, $doc['paths']['/stats/breakdown']['get']['parameters'][6]['schema']['maximum'] );
		dn_bfs_assert_true( isset( $doc['paths']['/stats/summary']['get']['responses']['422'] ), 'summary documents 422' );
		dn_bfs_assert_true( isset( $doc['paths']['/stats/realtime']['get']['responses']['429'] ), 'realtime documents 429' );
		dn_bfs_assert_true( false !== wp_json_encode( $doc ), 'JSON encodable' );

		dn_bfs_assert_same( 401, dn_bfs_it_api_get( 'openapi.json' )->get_status(), 'needs a key' );
	}
);
