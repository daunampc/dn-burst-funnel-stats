<?php

require_once __DIR__ . '/api-helpers.php';

dn_bfs_it(
	'creating a key stores only its prefix and HMAC and returns the full key once',
	function () {
		global $wpdb;

		$created = dn_bfs_it_api_key( array( 'name' => 'NestJS', 'allowed_ips' => "198.51.100.0/24\n\n2001:db8::/32", 'rate_limit' => '120' ) );
		$parts   = dn_bfs_api_parse_key( $created['key'] );

		dn_bfs_assert_true( false !== $parts, 'key format' );
		dn_bfs_assert_same( $parts['prefix'], $created['prefix'] );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . dn_bfs_table( 'api_keys' ) . ' WHERE id = %d', $created['id'] ), ARRAY_A );
		dn_bfs_assert_same( $created['prefix'], $row['prefix'] );
		dn_bfs_assert_same( hash_hmac( 'sha256', $created['key'], wp_salt( 'auth' ) ), $row['key_hash'] );
		dn_bfs_assert_true( false === strpos( implode( '|', $row ), $parts['secret'] ), 'secret never stored' );

		$key = dn_bfs_api_get_key( $created['id'] );
		dn_bfs_assert_same( 'NestJS', $key['name'] );
		dn_bfs_assert_same( array( 'stats:read', 'realtime:read' ), $key['scopes'] );
		dn_bfs_assert_same( array( '198.51.100.0/24', '2001:db8::/32' ), $key['allowed_ips'] );
		dn_bfs_assert_same( 120, $key['rate_limit'] );
		dn_bfs_assert_same( 0, $key['last_used_at'] );
		dn_bfs_assert_same( 0, $key['revoked_at'] );
		dn_bfs_assert_true( $key['created_at'] > 0, 'created_at' );
	}
);

dn_bfs_it(
	'keys are found by the full key only, listed and revoked once',
	function () {
		$created = dn_bfs_it_api_key();

		dn_bfs_assert_same( $created['id'], dn_bfs_api_find_key( $created['key'] )['id'] );
		dn_bfs_assert_same( null, dn_bfs_api_find_key( 'dnbfs_' . $created['prefix'] . '_' . str_repeat( 'A', 32 ) ), 'wrong secret' );
		dn_bfs_assert_same( null, dn_bfs_api_find_key( 'not-a-key' ), 'garbage' );
		dn_bfs_assert_same( null, dn_bfs_api_find_key( strtoupper( $created['key'] ) ), 'case matters' );

		$second = dn_bfs_it_api_key( array( 'name' => 'Second' ) );
		dn_bfs_assert_same( 2, count( dn_bfs_api_list_keys() ) );

		dn_bfs_assert_same( true, dn_bfs_api_revoke_key( $created['id'], 1700000000 ) );
		dn_bfs_assert_same( 1700000000, dn_bfs_api_get_key( $created['id'] )['revoked_at'] );
		dn_bfs_assert_same( 1700000000, dn_bfs_api_find_key( $created['key'] )['revoked_at'], 'find returns revoked keys so auth can reject them' );
		dn_bfs_assert_same( 'key_not_found', dn_bfs_api_revoke_key( $created['id'] )->get_error_code(), 'twice' );
		dn_bfs_assert_same( 'key_not_found', dn_bfs_api_revoke_key( 999999 )->get_error_code(), 'unknown' );

		dn_bfs_assert_same( array( $second['id'], $created['id'] ), array_column( dn_bfs_api_list_keys(), 'id' ), 'active keys first' );
	}
);

dn_bfs_it(
	'key input is validated and defaults to 60 requests per minute from any IP',
	function () {
		$cases = array(
			'invalid_name'       => array( 'name' => '   ' ),
			'invalid_scopes'     => array( 'scopes' => array( 'admin:write' ) ),
			'invalid_ips'        => array( 'allowed_ips' => "198.51.100.0/24\nnot-an-ip" ),
			'invalid_rate_limit' => array( 'rate_limit' => '0' ),
		);

		foreach ( $cases as $code => $override ) {
			$result = dn_bfs_api_create_key( array_merge( dn_bfs_it_api_key_defaults(), $override ) );
			dn_bfs_assert_same( $code, is_wp_error( $result ) ? $result->get_error_code() : 'created', $code );
		}

		foreach ( array( '1001', 'abc', '-5', '1.5' ) as $rate ) {
			$result = dn_bfs_api_create_key( array_merge( dn_bfs_it_api_key_defaults(), array( 'rate_limit' => $rate ) ) );
			dn_bfs_assert_same( 'invalid_rate_limit', is_wp_error( $result ) ? $result->get_error_code() : 'created', $rate );
		}

		dn_bfs_assert_same( 0, dn_bfs_it_count( 'api_keys' ) );

		$default = dn_bfs_api_create_key( array( 'name' => 'Defaults', 'scopes' => 'stats:read' ) );
		$key     = dn_bfs_api_get_key( $default['id'] );
		dn_bfs_assert_same( 60, $key['rate_limit'] );
		dn_bfs_assert_same( array(), $key['allowed_ips'] );
		dn_bfs_assert_same( array( 'stats:read' ), $key['scopes'] );
	}
);
