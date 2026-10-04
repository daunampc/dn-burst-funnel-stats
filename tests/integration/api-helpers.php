<?php
/**
 * Helpers for public API integration tests.
 */

function dn_bfs_it_api_key_defaults() {
	return array(
		'name'        => 'Test key',
		'scopes'      => array( 'stats:read', 'realtime:read' ),
		'allowed_ips' => '',
		'rate_limit'  => '60',
	);
}

function dn_bfs_it_api_key( $overrides = array() ) {
	$created = dn_bfs_api_create_key( array_merge( dn_bfs_it_api_key_defaults(), $overrides ) );

	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( 'API key: ' . $created->get_error_code() );
	}

	return $created;
}

function dn_bfs_it_api_request( $route, $query = array(), $key = '', $via = 'bearer' ) {
	$request = new WP_REST_Request( 'GET', '/dnbfs/v1/' . ltrim( $route, '/' ) );
	$request->set_query_params( $query );

	if ( '' !== $key ) {
		if ( 'bearer' === $via ) {
			$request->set_header( 'authorization', 'Bearer ' . $key );
		} else {
			$request->set_header( 'x_dnbfs_key', $key );
		}
	}

	return $request;
}

function dn_bfs_it_error_pair( $result ) {
	if ( ! is_wp_error( $result ) ) {
		return array( 'ok', 200 );
	}

	$data = $result->get_error_data();

	return array( $result->get_error_code(), is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0 );
}
