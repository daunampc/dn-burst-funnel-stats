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
	'HTTPS is required outside local development hosts',
	function () {
		$created = dn_bfs_it_api_key();
		$request = dn_bfs_it_api_request( 'meta', array(), $created['key'] );

		dn_bfs_assert_true( ! dn_bfs_api_https_required(), 'the Docker site (localhost) is exempt' );

		add_filter( 'dn_bfs_api_require_https', '__return_true' );
		dn_bfs_assert_same( array( 'https_required', 403 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, '', 1800000000 ) ) );

		$_SERVER['HTTPS'] = 'on';
		dn_bfs_assert_same( array( 'ok', 200 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, '', 1800000000 ) ) );
		remove_filter( 'dn_bfs_api_require_https', '__return_true' );
	}
);

dn_bfs_it(
	'allowed IPs, scopes and the per-key rate limit are enforced in order',
	function () {
		$created = dn_bfs_it_api_key( array( 'allowed_ips' => '198.51.100.0/24', 'scopes' => array( 'realtime:read' ), 'rate_limit' => '2' ) );
		$request = dn_bfs_it_api_request( 'stats/realtime', array(), $created['key'] );
		$now     = 1800000010; // 50 seconds before the next one-minute window.

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
