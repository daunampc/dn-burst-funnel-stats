<?php
/**
 * Per-request tracking context shared by the collector and WooCommerce hooks.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DN_BFS_COOKIE_VISITOR', 'dnbfs_vid' );
define( 'DN_BFS_COOKIE_SESSION', 'dnbfs_sid' );

function dn_bfs_now() {
	return (int) apply_filters( 'dn_bfs_now', time() );
}

function dn_bfs_site_host() {
	return dn_bfs_normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
}

/**
 * Per-day client fingerprint (IP + user-agent), never the raw IP.
 */
function dn_bfs_ip_hash( $ip, $now, $user_agent = '' ) {
	if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return '';
	}

	$option = 'dnbfs_salt_' . wp_date( 'Y-m-d', $now );
	$salt   = get_option( $option );

	if ( ! $salt ) {
		add_option( $option, wp_generate_password( 64, true, true ), '', 'no' );
		$salt = get_option( $option );
	}

	return hash_hmac( 'sha256', $ip . '|' . (string) $user_agent, (string) $salt );
}

/**
 * Roles of the logged-in visitor. Reads the auth cookie directly because REST
 * requests without a nonce run as user 0.
 */
function dn_bfs_request_roles() {
	$user_id = wp_validate_auth_cookie( '', 'logged_in' );

	if ( ! $user_id ) {
		return array();
	}

	$user = get_userdata( $user_id );

	return $user ? array_values( (array) $user->roles ) : array();
}

function dn_bfs_request_context( $now, $ua_raw ) {
	$ip = dn_bfs_get_client_ip();

	return array(
		'now'       => (int) $now,
		'settings'  => dn_bfs_get_tracking_settings(),
		'ip'        => $ip,
		'ip_hash'   => dn_bfs_ip_hash( $ip, $now, $ua_raw ),
		'ua_raw'    => (string) $ua_raw,
		'ua'        => dn_bfs_parse_user_agent( $ua_raw ),
		'roles'     => dn_bfs_request_roles(),
		'site_host' => dn_bfs_site_host(),
	);
}
