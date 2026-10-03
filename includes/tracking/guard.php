<?php
/**
 * Request validation and visitor exclusion rules for tracking hits.
 *
 * Pure functions only: no database access, no globals.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_page_types() {
	return array( 'home', 'product', 'category', 'cart', 'checkout', 'thankyou', 'other' );
}

function dn_bfs_guard_fail( $reason ) {
	return array(
		'ok'     => false,
		'reason' => $reason,
	);
}

function dn_bfs_guard_same_site( $server, $site_host ) {
	foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $header ) {
		if ( empty( $server[ $header ] ) ) {
			continue;
		}

		$host = wp_parse_url( (string) $server[ $header ], PHP_URL_HOST );

		return is_string( $host ) && dn_bfs_normalize_host( $host ) === dn_bfs_normalize_host( $site_host );
	}

	return false;
}

function dn_bfs_guard_validate_payload( $raw, $server, $site_host ) {
	$raw = (string) $raw;

	if ( ! isset( $server['REQUEST_METHOD'] ) || 'POST' !== strtoupper( (string) $server['REQUEST_METHOD'] ) ) {
		return dn_bfs_guard_fail( 'bad_method' );
	}

	if ( strlen( $raw ) > 2048 ) {
		return dn_bfs_guard_fail( 'too_large' );
	}

	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) ) {
		return dn_bfs_guard_fail( 'invalid_json' );
	}

	$type = isset( $data['t'] ) ? (string) $data['t'] : '';

	if ( ! in_array( $type, array( 'pv', 'ping' ), true ) ) {
		return dn_bfs_guard_fail( 'invalid_type' );
	}

	foreach ( array( 'vid', 'sid' ) as $key ) {
		if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) || ! preg_match( '/^[a-f0-9]{32}$/', $data[ $key ] ) ) {
			return dn_bfs_guard_fail( 'invalid_id' );
		}
	}

	if ( ! dn_bfs_guard_same_site( $server, $site_host ) ) {
		return dn_bfs_guard_fail( 'bad_origin' );
	}

	if ( 'ping' === $type ) {
		$pvid = isset( $data['pvid'] ) ? absint( $data['pvid'] ) : 0;

		if ( $pvid < 1 ) {
			return dn_bfs_guard_fail( 'invalid_pageview' );
		}

		return array(
			'ok'   => true,
			'data' => array(
				't'       => 'ping',
				'vid'     => $data['vid'],
				'sid'     => $data['sid'],
				'pvid'    => $pvid,
				'engaged' => max( 0, min( 1800, isset( $data['engaged'] ) ? (int) $data['engaged'] : 0 ) ),
			),
		);
	}

	$path = isset( $data['path'] ) && is_string( $data['path'] ) ? $data['path'] : '';
	$path = preg_replace( '/[\x00-\x1F\x7F]/', '', strtok( strtok( $path, '#' ), '?' ) );

	if ( '' === $path || '/' !== $path[0] || 0 === strpos( $path, '//' ) ) {
		return dn_bfs_guard_fail( 'invalid_path' );
	}

	$ref = isset( $data['ref'] ) && is_string( $data['ref'] ) ? substr( $data['ref'], 0, 1024 ) : '';
	if ( ! preg_match( '#^https?://#i', $ref ) ) {
		$ref = '';
	}

	$ptype = isset( $data['ptype'] ) && is_string( $data['ptype'] ) ? $data['ptype'] : 'other';

	return array(
		'ok'   => true,
		'data' => array(
			't'     => 'pv',
			'vid'   => $data['vid'],
			'sid'   => $data['sid'],
			'path'  => substr( $path, 0, 255 ),
			'query' => isset( $data['query'] ) && is_string( $data['query'] ) ? substr( ltrim( $data['query'], '?' ), 0, 1024 ) : '',
			'ref'   => $ref,
			'ptype' => in_array( $ptype, dn_bfs_page_types(), true ) ? $ptype : 'other',
			'pid'   => isset( $data['pid'] ) ? absint( $data['pid'] ) : 0,
			'sw'    => isset( $data['sw'] ) ? min( 10000, absint( $data['sw'] ) ) : 0,
		),
	);
}

function dn_bfs_guard_check_visitor( $ctx ) {
	$settings = $ctx['settings'];

	if ( empty( $settings['tracking_enabled'] ) ) {
		return 'disabled';
	}

	if ( array_intersect( (array) $ctx['roles'], (array) $settings['excluded_roles'] ) ) {
		return 'excluded_role';
	}

	$ip = (string) $ctx['ip'];

	if ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		foreach ( (array) $settings['excluded_ips'] as $rule ) {
			if ( dn_bfs_ip_in_cidr( $ip, $rule ) ) {
				return 'excluded_ip';
			}
		}
	}

	$ua = trim( (string) $ctx['ua_raw'] );

	if ( '' === $ua ) {
		return empty( $settings['block_empty_ua'] ) ? '' : 'empty_ua';
	}

	if ( ! empty( $settings['exclude_bots'] ) && dn_bfs_is_bot_user_agent( $ua, $settings['custom_bot_user_agents'] ) ) {
		return 'bot';
	}

	return '';
}

function dn_bfs_guard_is_page_selected( $hit, $settings ) {
	if ( empty( $settings['page_tracking_mode'] ) || 'selected' !== $settings['page_tracking_mode'] ) {
		return true;
	}

	if ( in_array( $hit['ptype'], array( 'product', 'cart', 'checkout', 'thankyou' ), true ) ) {
		return true;
	}

	return in_array( (int) $hit['pid'], array_map( 'absint', (array) $settings['selected_page_ids'] ), true );
}
