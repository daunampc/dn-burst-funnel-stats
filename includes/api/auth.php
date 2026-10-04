<?php
/**
 * Public API request authentication: key headers, HTTPS, IP allow-list,
 * scopes, per-key rate limit and last-used tracking.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Bearer token is used only when it looks like a plugin key, so a proxy or
 * site login sending its own Bearer token does not hide X-DNBFS-Key.
 */
function dn_bfs_api_extract_key( $authorization, $header_key ) {
	if ( preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', (string) $authorization, $matches ) && 0 === strpos( $matches[1], 'dnbfs_' ) ) {
		return $matches[1];
	}

	return trim( (string) $header_key );
}

function dn_bfs_api_is_local_host( $host ) {
	$host = strtolower( trim( (string) $host, "[] \t" ) );

	if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
		return true;
	}

	return (bool) preg_match( '/\.(test|localhost)$/', $host );
}

/**
 * Based on the configured site address, never on the request's Host header.
 */
function dn_bfs_api_https_required() {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

	return (bool) apply_filters( 'dn_bfs_api_require_https', ! dn_bfs_api_is_local_host( $host ), $host );
}

function dn_bfs_api_ip_allowed( $ip, $rules ) {
	$rules = array_filter( array_map( 'trim', array_map( 'strval', (array) $rules ) ), 'strlen' );

	if ( empty( $rules ) ) {
		return true;
	}

	if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return false;
	}

	foreach ( $rules as $rule ) {
		if ( dn_bfs_ip_in_cidr( $ip, $rule ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Cloudflare's published edge ranges (https://www.cloudflare.com/ips-v4 and ips-v6).
 */
function dn_bfs_api_default_cloudflare_ranges() {
	return array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);
}

function dn_bfs_api_ip_in_ranges( $ip, $ranges ) {
	foreach ( (array) $ranges as $range ) {
		if ( dn_bfs_ip_in_cidr( $ip, trim( (string) $range ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Client IP for API allow-lists. Unlike the tracking helper it trusts no header
 * by default: CF-Connecting-IP only when the connection comes from a Cloudflare
 * range, X-Forwarded-For only when it comes from a trusted proxy (walked
 * right-to-left, skipping trusted proxies and Cloudflare hops).
 */
/**
 * Normalises an address taken from REMOTE_ADDR or a proxy header: strips
 * brackets and ports ("1.2.3.4:80", "[2001:db8::1]:443") and unmaps
 * IPv4-mapped IPv6 ("::ffff:1.2.3.4"). Returns '' when it is not an IP.
 */
function dn_bfs_api_normalize_ip( $value ) {
	$value = trim( (string) $value );

	if ( preg_match( '/^\[([^\]]+)\](?::\d{1,5})?$/', $value, $m ) ) {
		$value = $m[1];
	} elseif ( 1 === substr_count( $value, ':' ) && preg_match( '/^([^:]+):\d{1,5}$/', $value, $m ) ) {
		$value = $m[1];
	}

	if ( false === filter_var( $value, FILTER_VALIDATE_IP ) ) {
		return '';
	}

	$bin = inet_pton( $value );

	if ( 16 === strlen( $bin ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $bin, 0, 12 ) ) {
		return inet_ntop( substr( $bin, 12 ) );
	}

	return $value;
}

function dn_bfs_api_resolve_client_ip( $server, $trusted_proxies, $cloudflare_ranges ) {
	$remote = isset( $server['REMOTE_ADDR'] ) ? dn_bfs_api_normalize_ip( $server['REMOTE_ADDR'] ) : '';

	if ( '' === $remote ) {
		return 'unknown';
	}

	if ( dn_bfs_api_ip_in_ranges( $remote, $cloudflare_ranges ) ) {
		$connecting = isset( $server['HTTP_CF_CONNECTING_IP'] ) ? dn_bfs_api_normalize_ip( $server['HTTP_CF_CONNECTING_IP'] ) : '';

		return '' !== $connecting ? $connecting : $remote;
	}

	if ( empty( $server['HTTP_X_FORWARDED_FOR'] ) || ! dn_bfs_api_ip_in_ranges( $remote, $trusted_proxies ) ) {
		return $remote;
	}

	$trusted = array_merge( (array) $trusted_proxies, (array) $cloudflare_ranges );
	$client  = $remote;

	foreach ( array_reverse( explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] ) ) as $hop ) {
		$hop = dn_bfs_api_normalize_ip( $hop );

		// A malformed hop means the chain can no longer be trusted past this point.
		if ( '' === $hop ) {
			break;
		}

		$client = $hop;

		if ( ! dn_bfs_api_ip_in_ranges( $hop, $trusted ) ) {
			break;
		}
	}

	return $client;
}

function dn_bfs_api_client_ip() {
	$cloudflare = (array) apply_filters( 'dn_bfs_api_cloudflare_ranges', dn_bfs_api_default_cloudflare_ranges() );
	$proxies    = (array) apply_filters( 'dn_bfs_api_trusted_proxies', array() );

	return dn_bfs_api_resolve_client_ip( wp_unslash( $_SERVER ), $proxies, $cloudflare );
}

function dn_bfs_api_rate_option( $key_id ) {
	return 'dnbfs_api_rl_' . (int) $key_id;
}

/**
 * Atomically counts this request in the key's current minute and returns the
 * new count, or false when the counter could not be written.
 *
 * With a persistent object cache: wp_cache_add + wp_cache_incr. Otherwise one
 * option row per key holding "<minute>:<count>", updated by a single UPDATE that
 * resets on a new minute or increments; LAST_INSERT_ID(expr) hands back the
 * value this connection wrote, so concurrent requests each see their own count.
 */
function dn_bfs_api_rate_increment( $key_id, $window ) {
	global $wpdb;

	if ( wp_using_ext_object_cache() ) {
		$name = 'rl_' . (int) $key_id . '_' . (int) $window;
		wp_cache_add( $name, 0, 'dnbfs_api', 2 * MINUTE_IN_SECONDS );
		$count = wp_cache_incr( $name, 1, 'dnbfs_api' );

		if ( false !== $count ) {
			return (int) $count;
		}
	}

	// Keep the counter statements and the read-back on the primary under HyperDB / LudicrousDB.
	if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
		$wpdb->send_reads_to_masters();
	}

	$option = dn_bfs_api_rate_option( $key_id );
	$minute = (string) (int) $window;
	$stored = "SUBSTRING_INDEX( option_value, ':', 1 )";
	// Reset only when the stored minute is older; a late request from a previous minute increments the stored one.
	$update = $wpdb->prepare(
		"UPDATE {$wpdb->options} SET option_value = CONCAT( IF( CAST( {$stored} AS UNSIGNED ) < %d, %s, {$stored} ), ':', LAST_INSERT_ID( IF( CAST( {$stored} AS UNSIGNED ) < %d, 1, CAST( SUBSTRING_INDEX( option_value, ':', -1 ) AS UNSIGNED ) + 1 ) ) ) WHERE option_name = %s",
		(int) $window,
		$minute,
		(int) $window,
		$option
	);

	for ( $attempt = 0; $attempt < 2; $attempt++ ) {
		if ( 1 === (int) $wpdb->query( $update ) ) {
			$count = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );

			// A count below 1 can only be a stale read or an anomaly: treat it as a failed write.
			return $count >= 1 ? $count : false;
		}

		if ( 0 === $attempt ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", $option, $minute . ':0' ) );
		}
	}

	return false;
}

/**
 * Fixed one-minute window per key (minutes of dn_bfs_now()).
 */
function dn_bfs_api_rate_check( $key, $now ) {
	$limit  = max( 1, (int) $key['rate_limit'] );
	$window = (int) floor( $now / MINUTE_IN_SECONDS );
	$count  = dn_bfs_api_rate_increment( $key['id'], $window );

	// Fail open if the counter could not be written: a broken options table must not lock out every key.
	if ( false === $count ) {
		static $logged = false;

		if ( ! $logged ) {
			$logged = true;
			error_log( 'DN Burst Funnel Stats: API rate-limit counter unavailable; failing open.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	if ( false !== $count && $count > $limit ) {
		return dn_bfs_request_error(
			'rate_limited',
			__( 'Too many requests for this API key. Try again later.', 'dn-burst-funnel-stats' ),
			429,
			array( 'retry_after' => max( 1, ( $window + 1 ) * MINUTE_IN_SECONDS - (int) $now ) )
		);
	}

	return true;
}

function dn_bfs_api_touch_key( $key, $now ) {
	global $wpdb;

	if ( (int) $now - (int) $key['last_used_at'] < MINUTE_IN_SECONDS ) {
		return false;
	}

	return (bool) $wpdb->query(
		$wpdb->prepare(
			'UPDATE ' . dn_bfs_table( 'api_keys' ) . ' SET last_used_at = %d WHERE id = %d AND last_used_at <= %d',
			(int) $now,
			(int) $key['id'],
			(int) $now - MINUTE_IN_SECONDS
		)
	);
}

function dn_bfs_api_authenticate( WP_REST_Request $request, $scope, $now ) {
	if ( dn_bfs_api_https_required() && ! is_ssl() ) {
		return dn_bfs_request_error( 'https_required', __( 'The API only accepts HTTPS requests. Behind a proxy or load balancer that terminates HTTPS, make WordPress detect it (for example set $_SERVER[\'HTTPS\'] = \'on\' in wp-config.php when X-Forwarded-Proto is https), or adjust the dn_bfs_api_require_https filter.', 'dn-burst-funnel-stats' ), 403 );
	}

	$raw = dn_bfs_api_extract_key( $request->get_header( 'authorization' ), $request->get_header( 'x_dnbfs_key' ) );

	if ( '' === $raw ) {
		return dn_bfs_request_error( 'missing_key', __( 'Send the API key in the Authorization: Bearer header or the X-DNBFS-Key header.', 'dn-burst-funnel-stats' ), 401 );
	}

	$key = dn_bfs_api_find_key( $raw );

	if ( null === $key || $key['revoked_at'] > 0 ) {
		return dn_bfs_request_error( 'invalid_key', __( 'The API key is invalid or has been revoked.', 'dn-burst-funnel-stats' ), 401 );
	}

	if ( ! dn_bfs_api_ip_allowed( dn_bfs_api_client_ip(), $key['allowed_ips'] ) ) {
		return dn_bfs_request_error( 'ip_not_allowed', __( 'This API key cannot be used from your IP address.', 'dn-burst-funnel-stats' ), 403 );
	}

	if ( '' !== $scope && ! in_array( $scope, $key['scopes'], true ) ) {
		/* translators: %s: scope name. */
		return dn_bfs_request_error( 'insufficient_scope', sprintf( __( 'This API key needs the %s scope.', 'dn-burst-funnel-stats' ), $scope ), 403 );
	}

	$allowed = dn_bfs_api_rate_check( $key, $now );

	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}

	dn_bfs_api_touch_key( $key, $now );

	return $key;
}
