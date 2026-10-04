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

function dn_bfs_api_extract_key( $authorization, $header_key ) {
	if ( preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', (string) $authorization, $matches ) ) {
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
 * Fixed one-minute window per key, counted in a transient.
 */
function dn_bfs_api_rate_check( $key, $now ) {
	$limit  = max( 1, (int) $key['rate_limit'] );
	$window = (int) floor( $now / MINUTE_IN_SECONDS );
	$name   = 'dnbfs_api_rl_' . (int) $key['id'] . '_' . $window;
	$count  = (int) get_transient( $name );

	if ( $count >= $limit ) {
		return dn_bfs_request_error(
			'rate_limited',
			__( 'Too many requests for this API key. Try again later.', 'dn-burst-funnel-stats' ),
			429,
			array( 'retry_after' => max( 1, ( $window + 1 ) * MINUTE_IN_SECONDS - (int) $now ) )
		);
	}

	set_transient( $name, $count + 1, 2 * MINUTE_IN_SECONDS );

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
		return dn_bfs_request_error( 'https_required', __( 'The API only accepts HTTPS requests.', 'dn-burst-funnel-stats' ), 403 );
	}

	$raw = dn_bfs_api_extract_key( $request->get_header( 'authorization' ), $request->get_header( 'x_dnbfs_key' ) );

	if ( '' === $raw ) {
		return dn_bfs_request_error( 'missing_key', __( 'Send the API key in the Authorization: Bearer header or the X-DNBFS-Key header.', 'dn-burst-funnel-stats' ), 401 );
	}

	$key = dn_bfs_api_find_key( $raw );

	if ( null === $key || $key['revoked_at'] > 0 ) {
		return dn_bfs_request_error( 'invalid_key', __( 'The API key is invalid or has been revoked.', 'dn-burst-funnel-stats' ), 401 );
	}

	if ( ! dn_bfs_api_ip_allowed( dn_bfs_get_client_ip(), $key['allowed_ips'] ) ) {
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
