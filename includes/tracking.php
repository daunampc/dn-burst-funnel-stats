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
		'headlesschrome',
		'phantomjs',
		'puppeteer',
		'playwright',
		'selenium',
		'lighthouse',
		'ptst',
		'python-requests',
		'python-urllib',
		'curl',
		'wget',
		'go-http-client',
		'axios',
		'node-fetch',
		'okhttp',
		'scrapy',
		'httpclient',
		'gptbot',
		'chatgpt-user',
		'oai-searchbot',
		'claudebot',
		'claude-web',
		'anthropic-ai',
		'perplexitybot',
		'ccbot',
		'bytespider',
		'amazonbot',
		'applebot',
		'meta-externalagent',
		'meta-externalfetcher',
		'facebookcatalog',
		'googleother',
		'google-inspectiontool',
		'storebot-google',
		'feedfetcher-google',
		'google-read-aloud',
		'apis-google',
		'linkedinbot',
		'twitterbot',
		'pinterestbot',
		'slackbot',
		'skypeuripreview',
		'redditbot',
		'embedly',
		'vkshare',
		'ia_archiver',
		'archive.org_bot',
		'seznambot',
		'sogou',
		'exabot',
		'360spider',
		'yisouspider',
		'coccocbot',
		'blexbot',
		'barkrowler',
		'dataforseobot',
		'serpstatbot',
		'seekport',
		'megaindex',
		'screaming frog',
		'sitebulb',
		'uptimerobot',
		'pingdom',
		'statuscake',
		'site24x7',
		'gtmetrix',
		'datadog',
		'newrelic',
		'headless',
		'java/',
		'libwww-perl',
		'guzzlehttp',
		'wordpress/',
		'postmanruntime',
		'insomnia',
		'httpie',
		'aiohttp',
		'python-httpx',
		'apache-httpclient',
		'colly',
		'zgrab',
		'masscan',
		'nmap',
		'nikto',
		'sqlmap',
		'wpscan',
		'censys',
		'netcraft',
	);
}

function dn_bfs_default_range_presets() {
	return array( 'today', 'yesterday', 'week_to_date', 'last_week', 'month_to_date', 'last_month', 'quarter_to_date', 'last_quarter', 'year_to_date', 'last_year' );
}

function dn_bfs_tracking_int_ranges() {
	return array(
		'session_timeout'         => array( 5, 240, 30 ),
		'cookie_days'             => array( 1, 730, 365 ),
		'dedupe_window'           => array( 60, 86400, 300 ),
		'reload_window'           => array( 0, 300, 10 ),
		'limit_pv_per_min'        => array( 10, 1000, 60 ),
		'limit_sessions_per_hour' => array( 1, 1000, 20 ),
		'limit_atc_per_min'       => array( 1, 500, 20 ),
		'limit_pv_per_session'    => array( 20, 5000, 300 ),
		'raw_retention_days'      => array( 7, 730, 90 ),
	);
}

function dn_bfs_get_tracking_settings() {
	$settings = get_option( 'dn_burst_funnel_stats_tracking_settings', array() );

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	$defaults = array(
		'page_tracking_mode'     => 'full',
		'selected_page_ids'      => array(),
		'product_tracking_mode'  => 'all',
		'selected_product_ids'   => array(),
		'excluded_ips'           => array(),
		'invalid_excluded_ips'   => array(),
		'exclude_bots'           => 1,
		'custom_bot_user_agents' => array(),
		'default_date_range'     => 'today',
		'default_compare'        => 'previous_year',
		'tracking_enabled'       => 1,
		'excluded_roles'         => array( 'administrator', 'shop_manager' ),
		'client_ip_source'       => 'auto',
		'block_empty_ua'         => 1,
		'force_cart_redirect'    => 0,
		'prefer_cloudflare'      => 1,
		'maxmind_license_key'    => '',
	);

	foreach ( dn_bfs_tracking_int_ranges() as $key => $range ) {
		$defaults[ $key ] = $range[2];
	}

	$settings = wp_parse_args( $settings, $defaults );

	foreach ( dn_bfs_tracking_int_ranges() as $key => $range ) {
		$settings[ $key ] = (int) $settings[ $key ];
	}

	return $settings;
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

/**
 * Parses an IP rule ("ip" or "ip/bits") into array( ip, bits ), or false when
 * malformed. Whitespace around the parts is ignored; the prefix must be plain
 * digits within the address family (32 / 128). A bare IP means a full-length prefix.
 * Shared by the validator and the matcher so they always agree.
 */
function dn_bfs_parse_ip_rule( $rule ) {
	$rule = trim( (string) $rule );
	$bits = null;

	if ( false !== strpos( $rule, '/' ) ) {
		$parts = explode( '/', $rule, 2 );
		$rule  = trim( $parts[0] );
		$bits  = trim( $parts[1] );

		if ( ! ctype_digit( $bits ) ) {
			return false;
		}
	}

	if ( false === filter_var( $rule, FILTER_VALIDATE_IP ) ) {
		return false;
	}

	$max = false !== filter_var( $rule, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? 128 : 32;

	if ( null === $bits ) {
		return array( $rule, $max );
	}

	return strlen( $bits ) <= 3 && (int) $bits <= $max ? array( $rule, (int) $bits ) : false;
}

function dn_bfs_validate_ip_rule( $rule ) {
	return false !== dn_bfs_parse_ip_rule( $rule );
}

function dn_bfs_sanitize_tracking_settings( $settings ) {
	$settings = is_array( $settings ) ? $settings : array();

	$current  = dn_bfs_get_tracking_settings();
	$new_keys = array_merge(
		array_keys( dn_bfs_tracking_int_ranges() ),
		array( 'excluded_roles', 'client_ip_source', 'block_empty_ua', 'force_cart_redirect', 'prefer_cloudflare', 'maxmind_license_key' )
	);

	// Keys introduced in schema 4 are kept from the saved value when a form does not post them.
	foreach ( $new_keys as $key ) {
		if ( ! array_key_exists( $key, $settings ) ) {
			$settings[ $key ] = $current[ $key ];
		}
	}

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

	$clean = array(
		'page_tracking_mode'     => $page_tracking_mode,
		'selected_page_ids'      => array_values( array_filter( array_unique( $selected_page_ids ) ) ),
		'product_tracking_mode'  => $product_tracking_mode,
		'selected_product_ids'   => array_values( array_filter( array_unique( $selected_product_ids ) ) ),
		'excluded_ips'           => array_values( array_unique( $valid ) ),
		'invalid_excluded_ips'   => array_values( array_unique( $invalid ) ),
		'exclude_bots'           => empty( $settings['exclude_bots'] ) ? 0 : 1,
		'custom_bot_user_agents' => dn_bfs_normalize_lines( $custom_bots ),
		'default_date_range'     => isset( $settings['default_date_range'] ) && in_array( $settings['default_date_range'], dn_bfs_default_range_presets(), true ) ? $settings['default_date_range'] : 'today',
		'default_compare'        => $compare,
		'tracking_enabled'       => isset( $settings['tracking_enabled'] ) ? ( empty( $settings['tracking_enabled'] ) ? 0 : 1 ) : 1,
		'excluded_roles'         => array_values( array_filter( array_unique( array_map( 'sanitize_key', (array) $settings['excluded_roles'] ) ) ) ),
		'client_ip_source'       => in_array( $settings['client_ip_source'], array( 'auto', 'remote_addr', 'x_forwarded_for', 'x_real_ip' ), true ) ? $settings['client_ip_source'] : 'auto',
		'block_empty_ua'         => empty( $settings['block_empty_ua'] ) ? 0 : 1,
		'force_cart_redirect'    => empty( $settings['force_cart_redirect'] ) ? 0 : 1,
		'prefer_cloudflare'      => empty( $settings['prefer_cloudflare'] ) ? 0 : 1,
		'maxmind_license_key'    => substr( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $settings['maxmind_license_key'] ), 0, 64 ),
	);

	foreach ( dn_bfs_tracking_int_ranges() as $key => $range ) {
		$clean[ $key ] = max( $range[0], min( $range[1], (int) $settings[ $key ] ) );
	}

	return $clean;
}

function dn_bfs_resolve_client_ip( $server, $source ) {
	$candidates = array();

	if ( 'x_forwarded_for' === $source && ! empty( $server['HTTP_X_FORWARDED_FOR'] ) ) {
		$candidates = explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] );
	} elseif ( 'x_real_ip' === $source && ! empty( $server['HTTP_X_REAL_IP'] ) ) {
		$candidates = array( $server['HTTP_X_REAL_IP'] );
	} elseif ( 'auto' === $source && ! empty( $server['HTTP_CF_RAY'] ) && ! empty( $server['HTTP_CF_CONNECTING_IP'] ) ) {
		$candidates = array( $server['HTTP_CF_CONNECTING_IP'] );
	}

	$candidates[] = isset( $server['REMOTE_ADDR'] ) ? $server['REMOTE_ADDR'] : '';

	foreach ( $candidates as $candidate ) {
		$candidate = trim( (string) $candidate );

		if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
			return $candidate;
		}
	}

	return 'unknown';
}

function dn_bfs_get_client_ip() {
	$settings = dn_bfs_get_tracking_settings();

	return dn_bfs_resolve_client_ip( wp_unslash( $_SERVER ), $settings['client_ip_source'] );
}

function dn_bfs_get_current_user_agent() {
	return isset( $_SERVER['HTTP_USER_AGENT'] )
		? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
		: '';
}

function dn_bfs_ip_in_cidr( $ip, $cidr ) {
	$ip   = (string) $ip;
	$rule = dn_bfs_parse_ip_rule( $cidr );

	if ( false === $rule || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return false;
	}

	$bits      = $rule[1];
	$ip_bin    = inet_pton( $ip );
	$range_bin = inet_pton( $rule[0] );

	if ( strlen( $ip_bin ) !== strlen( $range_bin ) ) {
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

function dn_bfs_is_bot_user_agent( $user_agent, $custom = array() ) {
	$user_agent = strtolower( trim( (string) $user_agent ) );

	if ( '' === $user_agent ) {
		return false;
	}

	foreach ( array_merge( dn_bfs_default_bot_keywords(), (array) $custom ) as $keyword ) {
		$keyword = strtolower( trim( (string) $keyword ) );

		if ( '' !== $keyword && false !== strpos( $user_agent, $keyword ) ) {
			return true;
		}
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
