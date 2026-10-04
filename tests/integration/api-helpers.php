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
