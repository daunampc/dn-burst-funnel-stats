<?php
/**
 * Tracking helpers and request exclusions.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_default_bot_keywords() {
	return array(
		'bot',
		'crawler',
		'spider',
		'slurp',
		'bingpreview',
		'facebookexternalhit',
		'whatsapp',
		'telegrambot',
		'discordbot',
		'googlebot',
		'adsbot-google',
		'mediapartners-google',
		'bingbot',
		'duckduckbot',
		'baiduspider',
		'yandexbot',
		'ahrefsbot',
		'semrushbot',
		'mj12bot',
		'dotbot',
		'petalbot',
	);
}

function dn_bfs_get_tracking_settings() {
	$settings = get_option( 'dn_burst_funnel_stats_tracking_settings', array() );

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	return wp_parse_args(
		$settings,
		array(
			'page_tracking_mode'       => 'full',
			'selected_page_ids'        => array(),
			'product_tracking_mode'    => 'all',
			'selected_product_ids'     => array(),
			'excluded_ips'             => array(),
			'invalid_excluded_ips'     => array(),
			'exclude_bots'             => 1,
			'custom_bot_user_agents'   => array(),
			'default_date_range'       => 'month_to_date',
			'default_compare'          => 'previous_year',
			'tracking_enabled'         => 1,
		)
	);
}

function dn_bfs_normalize_lines( $value ) {
	if ( is_array( $value ) ) {
		$lines = $value;
	} else {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
	}

	$clean = array();

	foreach ( (array) $lines as $line ) {
		$line = trim( sanitize_text_field( (string) $line ) );

		if ( '' !== $line ) {
			$clean[] = $line;
		}
	}

	return array_values( array_unique( $clean ) );
}

function dn_bfs_validate_ip_rule( $rule ) {
	$rule = trim( (string) $rule );

	if ( false === strpos( $rule, '/' ) ) {
		return false !== filter_var( $rule, FILTER_VALIDATE_IP );
	}

	$parts = explode( '/', $rule, 2 );
	$ip    = trim( $parts[0] );
	$bits  = isset( $parts[1] ) ? absint( $parts[1] ) : 0;

	if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return false;
	}

	$is_ipv6 = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
	$max     = $is_ipv6 ? 128 : 32;

	return $bits >= 0 && $bits <= $max;
}

function dn_bfs_sanitize_tracking_settings( $settings ) {
	$settings = is_array( $settings ) ? $settings : array();

	$page_tracking_mode = isset( $settings['page_tracking_mode'] ) ? sanitize_key( $settings['page_tracking_mode'] ) : 'full';
	if ( ! in_array( $page_tracking_mode, array( 'full', 'selected' ), true ) ) {
		$page_tracking_mode = 'full';
	}

	$product_tracking_mode = isset( $settings['product_tracking_mode'] ) ? sanitize_key( $settings['product_tracking_mode'] ) : 'all';
	if ( ! in_array( $product_tracking_mode, array( 'all', 'selected' ), true ) ) {
		$product_tracking_mode = 'all';
	}

	$selected_page_ids = isset( $settings['selected_page_ids'] ) && is_array( $settings['selected_page_ids'] )
		? array_map( 'absint', $settings['selected_page_ids'] )
		: array();

	$selected_product_ids = isset( $settings['selected_product_ids'] ) && is_array( $settings['selected_product_ids'] )
		? array_map( 'absint', $settings['selected_product_ids'] )
		: array();

	$raw_ips = isset( $settings['excluded_ips'] ) ? wp_unslash( $settings['excluded_ips'] ) : array();
	$ips     = dn_bfs_normalize_lines( $raw_ips );
	$valid   = array();
	$invalid = array();

	foreach ( $ips as $ip_rule ) {
		if ( dn_bfs_validate_ip_rule( $ip_rule ) ) {
			$valid[] = $ip_rule;
		} else {
			$invalid[] = $ip_rule;
		}
	}

	$custom_bots = isset( $settings['custom_bot_user_agents'] ) ? wp_unslash( $settings['custom_bot_user_agents'] ) : array();
	$compare     = isset( $settings['default_compare'] ) ? sanitize_key( $settings['default_compare'] ) : 'previous_year';

	if ( ! in_array( $compare, array( 'none', 'previous_period', 'previous_year' ), true ) ) {
		$compare = 'previous_year';
	}

	return array(
		'page_tracking_mode'       => $page_tracking_mode,
		'selected_page_ids'        => array_values( array_filter( array_unique( $selected_page_ids ) ) ),
		'product_tracking_mode'    => $product_tracking_mode,
		'selected_product_ids'     => array_values( array_filter( array_unique( $selected_product_ids ) ) ),
		'excluded_ips'             => array_values( array_unique( $valid ) ),
		'invalid_excluded_ips'     => array_values( array_unique( $invalid ) ),
		'exclude_bots'             => empty( $settings['exclude_bots'] ) ? 0 : 1,
		'custom_bot_user_agents'   => dn_bfs_normalize_lines( $custom_bots ),
		'default_date_range'       => 'month_to_date',
		'default_compare'          => $compare,
		'tracking_enabled'         => isset( $settings['tracking_enabled'] ) ? ( empty( $settings['tracking_enabled'] ) ? 0 : 1 ) : 1,
	);
}

function dn_bfs_get_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';

	if ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return $ip;
	}

	return 'unknown';
}

function dn_bfs_get_current_user_agent() {
	return isset( $_SERVER['HTTP_USER_AGENT'] )
		? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
		: '';
}

function dn_bfs_ip_in_cidr( $ip, $cidr ) {
	if ( false === strpos( $cidr, '/' ) ) {
		return $ip === $cidr;
	}

	list( $range_ip, $bits ) = explode( '/', $cidr, 2 );
	$bits      = absint( $bits );
	$ip_bin    = inet_pton( $ip );
	$range_bin = inet_pton( $range_ip );

	if ( false === $ip_bin || false === $range_bin || strlen( $ip_bin ) !== strlen( $range_bin ) ) {
		return false;
	}

	$bytes     = (int) floor( $bits / 8 );
	$remainder = $bits % 8;

	if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $range_bin, 0, $bytes ) ) {
		return false;
	}

	if ( 0 === $remainder ) {
		return true;
	}

	$mask = chr( ( 0xff << ( 8 - $remainder ) ) & 0xff );

	return ( $ip_bin[ $bytes ] & $mask ) === ( $range_bin[ $bytes ] & $mask );
}

function dn_bfs_is_ip_excluded( $ip = '' ) {
	$ip = '' === $ip ? dn_bfs_get_client_ip() : trim( (string) $ip );

	if ( '' === $ip || 'unknown' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return false;
	}

	$settings = dn_bfs_get_tracking_settings();

	foreach ( (array) $settings['excluded_ips'] as $rule ) {
		if ( dn_bfs_ip_in_cidr( $ip, $rule ) ) {
			return true;
		}
	}

	return false;
}

function dn_bfs_is_bot_request() {
	$settings = dn_bfs_get_tracking_settings();

	if ( empty( $settings['exclude_bots'] ) ) {
		return false;
	}

	$user_agent = strtolower( dn_bfs_get_current_user_agent() );

	if ( '' === $user_agent ) {
		return true;
	}

	$keywords = array_merge(
		dn_bfs_default_bot_keywords(),
		(array) $settings['custom_bot_user_agents']
	);

	foreach ( $keywords as $keyword ) {
		$keyword = strtolower( trim( (string) $keyword ) );

		if ( '' !== $keyword && false !== strpos( $user_agent, $keyword ) ) {
			return true;
		}
	}

	return false;
}

function dn_bfs_is_selected_page_request() {
	$settings = dn_bfs_get_tracking_settings();

	if ( 'selected' !== $settings['page_tracking_mode'] ) {
		return true;
	}

	if ( is_page( array_map( 'absint', $settings['selected_page_ids'] ) ) ) {
		return true;
	}

	if ( function_exists( 'is_product' ) && is_product() ) {
		return true;
	}

	return false;
}

function dn_bfs_should_track_product( $product_id ) {
	$settings   = dn_bfs_get_tracking_settings();
	$product_id = absint( $product_id );

	if ( 'selected' !== $settings['product_tracking_mode'] ) {
		return true;
	}

	return in_array( $product_id, array_map( 'absint', $settings['selected_product_ids'] ), true );
}

function dn_bfs_should_track_request( $context = 'frontend', $args = array() ) {
	$settings = dn_bfs_get_tracking_settings();

	if ( empty( $settings['tracking_enabled'] ) ) {
		return false;
	}

	if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return false;
	}

	if ( is_admin() && ! wp_doing_ajax() ) {
		return false;
	}

	if ( dn_bfs_is_ip_excluded( dn_bfs_get_client_ip() ) || dn_bfs_is_bot_request() ) {
		return false;
	}

	if ( 'add_to_cart' === $context && ! empty( $args['product_id'] ) ) {
		return dn_bfs_should_track_product( $args['product_id'] );
	}

	return dn_bfs_is_selected_page_request();
}
