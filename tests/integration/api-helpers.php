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

/**
 * Snapshot of $_SERVER entries so a test can restore them in `finally`.
 */
function dn_bfs_it_save_server( $names ) {
	$saved = array();

	foreach ( $names as $name ) {
		$saved[ $name ] = array_key_exists( $name, $_SERVER ) ? array( true, $_SERVER[ $name ] ) : array( false, null );
	}

	return $saved;
}

function dn_bfs_it_restore_server( $saved ) {
	foreach ( $saved as $name => $entry ) {
		if ( $entry[0] ) {
			$_SERVER[ $name ] = $entry[1];
		} else {
			unset( $_SERVER[ $name ] );
		}
	}
}

function dn_bfs_it_api_server_names() {
	return array( 'HTTPS', 'REMOTE_ADDR', 'HTTP_CF_RAY', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' );
}

function dn_bfs_it_rate_row( $key_id ) {
	global $wpdb;

	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'dnbfs_api_rl_' . (int) $key_id ) );
}
