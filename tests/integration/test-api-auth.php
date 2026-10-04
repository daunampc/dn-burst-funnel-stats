<?php

require_once __DIR__ . '/api-helpers.php';

dn_bfs_it(
	'authentication accepts the Bearer and X-DNBFS-Key headers and rejects bad keys',
	function () {
		$created = dn_bfs_it_api_key();
		$now     = 1800000000;

		$key = dn_bfs_api_authenticate( dn_bfs_it_api_request( 'meta', array(), $created['key'] ), '', $now );
		dn_bfs_assert_same( $created['id'], is_wp_error( $key ) ? $key->get_error_code() : $key['id'], 'bearer' );

		$key = dn_bfs_api_authenticate( dn_bfs_it_api_request( 'meta', array(), $created['key'], 'header' ), '', $now );
		dn_bfs_assert_same( $created['id'], is_wp_error( $key ) ? $key->get_error_code() : $key['id'], 'x-dnbfs-key' );

		dn_bfs_assert_same( array( 'missing_key', 401 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( dn_bfs_it_api_request( 'meta' ), '', $now ) ) );
		dn_bfs_assert_same( array( 'invalid_key', 401 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( dn_bfs_it_api_request( 'meta', array(), 'dnbfs_' . $created['prefix'] . '_' . str_repeat( 'x', 32 ) ), '', $now ) ) );

		dn_bfs_api_revoke_key( $created['id'] );
		dn_bfs_assert_same( array( 'invalid_key', 401 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( dn_bfs_it_api_request( 'meta', array(), $created['key'] ), '', $now ) ), 'revoked' );
	}
);

dn_bfs_it(
	'a non-plugin Bearer token (e.g. a proxy or site login) falls back to X-DNBFS-Key',
	function () {
		$created = dn_bfs_it_api_key();
		$request = dn_bfs_it_api_request( 'meta', array(), $created['key'], 'header' );
		$request->set_header( 'authorization', 'Bearer eyJhbGciOiJIUzI1NiJ9.e30.signature' );

		$key = dn_bfs_api_authenticate( $request, '', 1800000000 );
		dn_bfs_assert_same( $created['id'], is_wp_error( $key ) ? $key->get_error_code() : $key['id'] );

		$request = dn_bfs_it_api_request( 'meta' );
		$request->set_header( 'authorization', 'Bearer eyJhbGciOiJIUzI1NiJ9.e30.signature' );
		dn_bfs_assert_same( array( 'missing_key', 401 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, '', 1800000000 ) ) );
	}
);

dn_bfs_it(
	'HTTPS is required outside local development hosts',
	function () {
		$created = dn_bfs_it_api_key();
		$request = dn_bfs_it_api_request( 'meta', array(), $created['key'] );
		$saved   = dn_bfs_it_save_server( dn_bfs_it_api_server_names() );

		try {
			unset( $_SERVER['HTTPS'] );
			dn_bfs_assert_true( ! dn_bfs_api_https_required(), 'the Docker site (localhost) is exempt' );

			add_filter( 'dn_bfs_api_require_https', '__return_true' );
			$error = dn_bfs_api_authenticate( $request, '', 1800000000 );
			dn_bfs_assert_same( array( 'https_required', 403 ), dn_bfs_it_error_pair( $error ) );
			dn_bfs_assert_true( false !== strpos( $error->get_error_message(), 'X-Forwarded-Proto' ), 'message explains proxy HTTPS detection' );
			dn_bfs_assert_true( false !== strpos( $error->get_error_message(), 'dn_bfs_api_require_https' ), 'message names the filter' );

			$_SERVER['HTTPS'] = 'on';
			dn_bfs_assert_same( array( 'ok', 200 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, '', 1800000000 ) ) );
		} finally {
			remove_filter( 'dn_bfs_api_require_https', '__return_true' );
			dn_bfs_it_restore_server( $saved );
		}
	}
);

dn_bfs_it(
	'allowed IPs, scopes and the per-key rate limit are enforced in order',
	function () {
		$created = dn_bfs_it_api_key( array( 'allowed_ips' => '198.51.100.0/24', 'scopes' => array( 'realtime:read' ), 'rate_limit' => '2' ) );
		$request = dn_bfs_it_api_request( 'stats/realtime', array(), $created['key'] );
		$now     = 1800000010; // 50 seconds before the next one-minute window.
		$saved   = dn_bfs_it_save_server( dn_bfs_it_api_server_names() );

		try {
			$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
			dn_bfs_assert_same( array( 'ip_not_allowed', 403 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, 'realtime:read', $now ) ) );

			$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
			dn_bfs_assert_same( array( 'insufficient_scope', 403 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, 'stats:read', $now ) ) );

			dn_bfs_assert_same( array( 'ok', 200 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, 'realtime:read', $now ) ), 'first' );
			dn_bfs_assert_same( array( 'ok', 200 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, 'realtime:read', $now ) ), 'second' );

			$limited = dn_bfs_api_authenticate( $request, 'realtime:read', $now );
			dn_bfs_assert_same( array( 'rate_limited', 429 ), dn_bfs_it_error_pair( $limited ) );
			dn_bfs_assert_same( 50, $limited->get_error_data()['retry_after'] );

			dn_bfs_assert_same( array( 'ok', 200 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, 'realtime:read', $now + 60 ) ), 'next window' );
		} finally {
			dn_bfs_it_restore_server( $saved );
		}
	}
);

dn_bfs_it(
	'spoofed Cloudflare and X-Forwarded-For headers cannot satisfy an IP allow-list',
	function () {
		$created = dn_bfs_it_api_key( array( 'allowed_ips' => '198.51.100.0/24' ) );
		$request = dn_bfs_it_api_request( 'meta', array(), $created['key'] );
		$saved   = dn_bfs_it_save_server( dn_bfs_it_api_server_names() );

		try {
			$_SERVER['REMOTE_ADDR']           = '203.0.113.10';
			$_SERVER['HTTP_CF_RAY']           = '8a1b2c3d4e5f-LAX';
			$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
			$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.7';
			dn_bfs_assert_same( '198.51.100.7', dn_bfs_get_client_ip(), 'tracking helper still trusts CF headers in auto mode' );
			dn_bfs_assert_same( array( 'ip_not_allowed', 403 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, '', 1800000000 ) ), 'auto mode' );

			dn_bfs_it_settings( array( 'client_ip_source' => 'x_forwarded_for' ) );
			dn_bfs_assert_same( array( 'ip_not_allowed', 403 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, '', 1800000000 ) ), 'x_forwarded_for mode' );
		} finally {
			dn_bfs_it_restore_server( $saved );
		}
	}
);

dn_bfs_it(
	'CF-Connecting-IP is honoured only when the connection comes from Cloudflare',
	function () {
		$created = dn_bfs_it_api_key( array( 'allowed_ips' => '198.51.100.0/24' ) );
		$request = dn_bfs_it_api_request( 'meta', array(), $created['key'] );
		$saved   = dn_bfs_it_save_server( dn_bfs_it_api_server_names() );
		$ranges  = function () {
			return array( '192.0.2.0/24' );
		};

		try {
			$_SERVER['REMOTE_ADDR']           = '172.70.10.20'; // 172.64.0.0/13.
			$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
			dn_bfs_assert_same( '198.51.100.7', dn_bfs_api_client_ip() );
			dn_bfs_assert_same( array( 'ok', 200 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, '', 1800000000 ) ) );

			add_filter( 'dn_bfs_api_cloudflare_ranges', $ranges );
			dn_bfs_assert_same( '172.70.10.20', dn_bfs_api_client_ip(), 'ranges are filterable' );
		} finally {
			remove_filter( 'dn_bfs_api_cloudflare_ranges', $ranges );
			dn_bfs_it_restore_server( $saved );
		}
	}
);

dn_bfs_it(
	'X-Forwarded-For is used only behind a trusted proxy, right-most untrusted hop first',
	function () {
		$created = dn_bfs_it_api_key( array( 'allowed_ips' => '198.51.100.0/24' ) );
		$request = dn_bfs_it_api_request( 'meta', array(), $created['key'] );
		$saved   = dn_bfs_it_save_server( dn_bfs_it_api_server_names() );
		$proxies = function () {
			return array( '10.0.0.0/8' );
		};

		try {
			$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
			dn_bfs_assert_same( array( 'ip_not_allowed', 403 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, '', 1800000000 ) ), 'no trusted proxy' );

			add_filter( 'dn_bfs_api_trusted_proxies', $proxies );
			dn_bfs_assert_same( array( 'ok', 200 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, '', 1800000000 ) ), 'trusted proxy' );

			$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 203.0.113.10, 10.1.1.1';
			dn_bfs_assert_same( '203.0.113.10', dn_bfs_api_client_ip() );
			dn_bfs_assert_same( array( 'ip_not_allowed', 403 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, '', 1800000000 ) ), 'spoofed left-most entry' );
		} finally {
			remove_filter( 'dn_bfs_api_trusted_proxies', $proxies );
			dn_bfs_it_restore_server( $saved );
		}
	}
);

dn_bfs_it(
	'the rate limit counter keeps one atomic row per key and reaches the limit across calls',
	function () {
		$key = dn_bfs_api_get_key( dn_bfs_it_api_key( array( 'rate_limit' => '3' ) )['id'] );
		$now = 1800000000; // Start of minute 30000000.

		for ( $i = 1; $i <= 3; $i++ ) {
			dn_bfs_assert_true( true === dn_bfs_api_rate_check( $key, $now + $i ), 'call ' . $i );
			dn_bfs_assert_same( '30000000:' . $i, dn_bfs_it_rate_row( $key['id'] ), 'count after call ' . $i );
		}

		$limited = dn_bfs_api_rate_check( $key, $now + 15 );
		dn_bfs_assert_same( array( 'rate_limited', 429 ), dn_bfs_it_error_pair( $limited ) );
		dn_bfs_assert_same( 45, $limited->get_error_data()['retry_after'] );
		dn_bfs_assert_same( array( 'rate_limited', 429 ), dn_bfs_it_error_pair( dn_bfs_api_rate_check( $key, $now + 59 ) ), 'still limited' );

		dn_bfs_assert_true( true === dn_bfs_api_rate_check( $key, $now + 60 ), 'new minute' );
		dn_bfs_assert_same( '30000001:1', dn_bfs_it_rate_row( $key['id'] ), 'same row reset' );
		dn_bfs_assert_same( 'no', $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", 'dnbfs_api_rl_' . $key['id'] ) ) );
		dn_bfs_assert_same( '1', $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE %s", 'dnbfs\_api\_rl\_%' ) ), 'rows do not accumulate' );

		dn_bfs_api_revoke_key( $key['id'] );
		dn_bfs_assert_same( null, dn_bfs_it_rate_row( $key['id'] ), 'revoking deletes the counter' );
	}
);

dn_bfs_it(
	'a late request from the previous minute does not reset the counter',
	function () {
		$key = dn_bfs_api_get_key( dn_bfs_it_api_key( array( 'rate_limit' => '10' ) )['id'] );
		$now = 1800000060; // Start of minute 30000001.

		dn_bfs_api_rate_check( $key, $now );
		dn_bfs_api_rate_check( $key, $now + 1 );
		dn_bfs_assert_same( '30000001:2', dn_bfs_it_rate_row( $key['id'] ), 'two counted' );

		dn_bfs_api_rate_check( $key, $now - 1 ); // Late, from minute 30000000.
		dn_bfs_assert_same( '30000001:3', dn_bfs_it_rate_row( $key['id'] ), 'stored (newer) minute is incremented, not reset' );

		dn_bfs_api_rate_check( $key, $now + 120 );
		dn_bfs_assert_same( '30000003:1', dn_bfs_it_rate_row( $key['id'] ), 'older stored minute resets' );
	}
);

dn_bfs_it(
	'with a persistent object cache the rate limit uses an atomic cache increment',
	function () {
		$key      = dn_bfs_api_get_key( dn_bfs_it_api_key( array( 'rate_limit' => '2' ) )['id'] );
		$now      = 1800000000;
		$previous = wp_using_ext_object_cache( true );

		try {
			dn_bfs_assert_true( true === dn_bfs_api_rate_check( $key, $now ), 'first' );
			dn_bfs_assert_true( true === dn_bfs_api_rate_check( $key, $now + 1 ), 'second' );
			dn_bfs_assert_same( array( 'rate_limited', 429 ), dn_bfs_it_error_pair( dn_bfs_api_rate_check( $key, $now + 2 ) ) );
			dn_bfs_assert_true( true === dn_bfs_api_rate_check( $key, $now + 60 ), 'next window' );
			dn_bfs_assert_same( null, dn_bfs_it_rate_row( $key['id'] ), 'no option row' );
		} finally {
			// The global starts as null in WP-CLI, and passing null would not reset it.
			wp_using_ext_object_cache( (bool) $previous );
		}
	}
);

dn_bfs_it(
	'last_used_at is written at most once per minute',
	function () {
		$created = dn_bfs_it_api_key();
		$request = dn_bfs_it_api_request( 'meta', array(), $created['key'] );
		$now     = 1800000000;

		dn_bfs_api_authenticate( $request, '', $now );
		dn_bfs_assert_same( $now, dn_bfs_api_get_key( $created['id'] )['last_used_at'] );

		dn_bfs_api_authenticate( $request, '', $now + 30 );
		dn_bfs_assert_same( $now, dn_bfs_api_get_key( $created['id'] )['last_used_at'], 'within a minute' );

		dn_bfs_api_authenticate( $request, '', $now + 61 );
		dn_bfs_assert_same( $now + 61, dn_bfs_api_get_key( $created['id'] )['last_used_at'], 'after a minute' );
	}
);
