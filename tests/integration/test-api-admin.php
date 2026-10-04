<?php

require_once __DIR__ . '/admin-helpers.php';
require_once __DIR__ . '/api-helpers.php';

function dn_bfs_it_render_api_tab() {
	$_GET = array( 'page' => 'dn-burst-funnel-stats-settings', 'tab' => 'api' );
	ob_start();
	dn_bfs_render_settings_page();
	$html = ob_get_clean();
	$_GET = array();

	return $html;
}

dn_bfs_it(
	'the API tab creates a key, shows it exactly once and revokes it',
	function () {
		$user = dn_bfs_it_login_admin();

		dn_bfs_assert_same( 'api', array_keys( dn_bfs_settings_tabs() )[7] );

		$result = dn_bfs_api_key_task( 'create', array( 'name' => 'NestJS', 'scopes' => array( 'stats:read' ), 'allowed_ips' => "198.51.100.0/24\n", 'rate_limit' => '120' ), $user );
		dn_bfs_assert_same( array( 'tab' => 'api', 'notice' => 'key_created' ), $result );

		$keys = dn_bfs_api_list_keys();
		dn_bfs_assert_same( 1, count( $keys ) );
		dn_bfs_assert_same( 'NestJS', $keys[0]['name'] );
		dn_bfs_assert_same( array( 'stats:read' ), $keys[0]['scopes'] );
		dn_bfs_assert_same( array( '198.51.100.0/24' ), $keys[0]['allowed_ips'] );
		dn_bfs_assert_same( 120, $keys[0]['rate_limit'] );

		$html = dn_bfs_it_render_api_tab();
		dn_bfs_assert_same( 1, preg_match_all( '/dnbfs_[a-z0-9]{8}_[A-Za-z0-9]{32}/', $html, $matches ), 'full key shown once' );
		dn_bfs_assert_same( $keys[0]['id'], dn_bfs_api_find_key( $matches[0][0] )['id'], 'shown key works' );
		dn_bfs_assert_true( false === strpos( $html, $keys[0]['key_hash'] ), 'hash never rendered' );
		dn_bfs_assert_true( false !== strpos( $html, 'NestJS' ), 'listed' );
		dn_bfs_assert_true( false !== strpos( $html, untrailingslashit( rest_url( 'dnbfs/v1' ) ) ), 'base URL hint' );
		dn_bfs_assert_true( false !== strpos( $html, 'name="task" value="create"' ), 'create form' );

		$again = dn_bfs_it_render_api_tab();
		dn_bfs_assert_same( 0, preg_match( '/dnbfs_[a-z0-9]{8}_[A-Za-z0-9]{32}/', $again ), 'not shown again' );
		dn_bfs_assert_true( false !== strpos( $again, 'dnbfs_' . $keys[0]['prefix'] . '_' ), 'prefix still listed' );
		dn_bfs_assert_true( false !== strpos( $again, 'name="task" value="revoke"' ), 'revoke form for active key' );

		dn_bfs_assert_same( array( 'tab' => 'api', 'notice' => 'key_revoked' ), dn_bfs_api_key_task( 'revoke', array( 'key_id' => (string) $keys[0]['id'] ), $user ) );
		dn_bfs_assert_same( 'key_not_found', dn_bfs_api_key_task( 'revoke', array( 'key_id' => (string) $keys[0]['id'] ), $user )['notice'] );

		$revoked = dn_bfs_it_render_api_tab();
		dn_bfs_assert_true( false !== strpos( $revoked, 'Revoked' ), 'revoked status' );
		dn_bfs_assert_true( false === strpos( $revoked, 'name="task" value="revoke"' ), 'no revoke button for revoked keys' );
	}
);

dn_bfs_it(
	'API key task errors map to notices and reveals are per user',
	function () {
		$user  = dn_bfs_it_login_admin();
		$cases = array(
			'invalid_name'       => array( 'scopes' => array( 'stats:read' ) ),
			'invalid_scopes'     => array( 'name' => 'X' ),
			'invalid_ips'        => array( 'name' => 'X', 'scopes' => array( 'stats:read' ), 'allowed_ips' => 'nope' ),
			'invalid_rate_limit' => array( 'name' => 'X', 'scopes' => array( 'stats:read' ), 'rate_limit' => '5000' ),
		);

		foreach ( $cases as $code => $post ) {
			dn_bfs_assert_same( $code, dn_bfs_api_key_task( 'create', $post, $user )['notice'], $code );
			dn_bfs_assert_same( 'error', dn_bfs_settings_notice( $code )[0], $code . ' notice' );
		}

		dn_bfs_assert_same( 0, dn_bfs_it_count( 'api_keys' ) );
		dn_bfs_assert_same( 'invalid_task', dn_bfs_api_key_task( 'delete', array(), $user )['notice'] );
		dn_bfs_assert_same( 'success', dn_bfs_settings_notice( 'key_created' )[0] );
		dn_bfs_assert_same( 'success', dn_bfs_settings_notice( 'key_revoked' )[0] );
		dn_bfs_assert_same( 'error', dn_bfs_settings_notice( 'key_not_found' )[0] );

		dn_bfs_api_reveal_key_store( $user, 'dnbfs_example' );
		dn_bfs_assert_same( '', dn_bfs_api_reveal_key_take( $user + 1000 ), 'other user sees nothing' );
		dn_bfs_assert_same( 'dnbfs_example', dn_bfs_api_reveal_key_take( $user ) );
		dn_bfs_assert_same( '', dn_bfs_api_reveal_key_take( $user ), 'taken once' );
	}
);
