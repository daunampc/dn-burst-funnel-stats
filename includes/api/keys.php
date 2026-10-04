<?php
/**
 * API key storage: scopes, generation, hashing, lookup, creation and revocation.
 *
 * Keys look like dnbfs_<prefix8>_<secret32>. Only the prefix and an HMAC of the
 * full key are stored; the full key is shown once, right after creation.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_api_scope_names() {
	return array( 'stats:read', 'realtime:read' );
}

function dn_bfs_api_scopes() {
	return array(
		'stats:read'    => __( 'Statistics (summary, timeseries, breakdown, funnel)', 'dn-burst-funnel-stats' ),
		'realtime:read' => __( 'Visitors online right now', 'dn-burst-funnel-stats' ),
	);
}

function dn_bfs_api_parse_scopes( $value ) {
	$list = is_array( $value ) ? array_filter( $value, 'is_scalar' ) : explode( ',', (string) $value );
	$list = array_map( 'trim', array_map( 'strval', $list ) );

	return array_values( array_intersect( dn_bfs_api_scope_names(), $list ) );
}

function dn_bfs_api_parse_key( $key ) {
	if ( ! is_string( $key ) || ! preg_match( '/^dnbfs_([a-z0-9]{8})_([A-Za-z0-9]{32})$/', $key, $matches ) ) {
		return false;
	}

	return array(
		'prefix' => $matches[1],
		'secret' => $matches[2],
	);
}

function dn_bfs_api_key_hash( $key ) {
	return hash_hmac( 'sha256', (string) $key, wp_salt( 'auth' ) );
}

function dn_bfs_api_generate_key() {
	$prefix = strtolower( wp_generate_password( 8, false, false ) );

	return array(
		'prefix' => $prefix,
		'key'    => 'dnbfs_' . $prefix . '_' . wp_generate_password( 32, false, false ),
	);
}

function dn_bfs_api_sanitize_key_input( $input ) {
	$input = is_array( $input ) ? $input : array();
	$name  = isset( $input['name'] ) && is_scalar( $input['name'] ) ? dn_bfs_truncate( trim( sanitize_text_field( (string) $input['name'] ) ), 100 ) : '';

	if ( '' === $name ) {
		return dn_bfs_request_error( 'invalid_name', __( 'Give the key a name.', 'dn-burst-funnel-stats' ) );
	}

	$scopes = dn_bfs_api_parse_scopes( isset( $input['scopes'] ) ? $input['scopes'] : array() );

	if ( empty( $scopes ) ) {
		return dn_bfs_request_error( 'invalid_scopes', __( 'Choose at least one scope.', 'dn-burst-funnel-stats' ) );
	}

	$rules   = dn_bfs_normalize_lines( isset( $input['allowed_ips'] ) && is_scalar( $input['allowed_ips'] ) ? (string) $input['allowed_ips'] : '' );
	$invalid = array();

	foreach ( $rules as $rule ) {
		if ( ! dn_bfs_validate_ip_rule( $rule ) ) {
			$invalid[] = $rule;
		}
	}

	if ( ! empty( $invalid ) ) {
		/* translators: %s: comma-separated invalid IP rules. */
		return dn_bfs_request_error( 'invalid_ips', sprintf( __( 'These allowed IP entries are invalid: %s', 'dn-burst-funnel-stats' ), implode( ', ', $invalid ) ), 400, array( 'invalid' => $invalid ) );
	}

	$rate = isset( $input['rate_limit'] ) && is_scalar( $input['rate_limit'] ) ? trim( (string) $input['rate_limit'] ) : '';
	$rate = '' === $rate ? '60' : $rate;

	if ( ! ctype_digit( $rate ) || (int) $rate < 1 || (int) $rate > 1000 ) {
		return dn_bfs_request_error( 'invalid_rate_limit', __( 'The rate limit must be between 1 and 1000 requests per minute.', 'dn-burst-funnel-stats' ) );
	}

	return array(
		'name'        => $name,
		'scopes'      => $scopes,
		'allowed_ips' => $rules,
		'rate_limit'  => (int) $rate,
	);
}

function dn_bfs_api_create_key( $input, $now = null ) {
	global $wpdb;

	$clean = dn_bfs_api_sanitize_key_input( $input );

	if ( is_wp_error( $clean ) ) {
		return $clean;
	}

	$now = null === $now ? dn_bfs_now() : (int) $now;

	// The prefix is UNIQUE; retry on the (unlikely) collision.
	for ( $attempt = 0; $attempt < 5; $attempt++ ) {
		$generated = dn_bfs_api_generate_key();
		$inserted  = $wpdb->insert(
			dn_bfs_table( 'api_keys' ),
			array(
				'name'         => $clean['name'],
				'prefix'       => $generated['prefix'],
				'key_hash'     => dn_bfs_api_key_hash( $generated['key'] ),
				'scopes'       => implode( ',', $clean['scopes'] ),
				'allowed_ips'  => implode( "\n", $clean['allowed_ips'] ),
				'rate_limit'   => $clean['rate_limit'],
				'last_used_at' => 0,
				'created_at'   => $now,
				'revoked_at'   => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
		);

		if ( $inserted ) {
			return array(
				'id'     => (int) $wpdb->insert_id,
				'prefix' => $generated['prefix'],
				'key'    => $generated['key'],
			);
		}
	}

	return dn_bfs_request_error( 'key_create_failed', __( 'The key could not be saved. Please try again.', 'dn-burst-funnel-stats' ), 500 );
}

function dn_bfs_api_normalize_key_row( $row ) {
	return array(
		'id'           => (int) $row['id'],
		'name'         => (string) $row['name'],
		'prefix'       => (string) $row['prefix'],
		'key_hash'     => (string) $row['key_hash'],
		'scopes'       => dn_bfs_api_parse_scopes( (string) $row['scopes'] ),
		'allowed_ips'  => dn_bfs_normalize_lines( (string) $row['allowed_ips'] ),
		'rate_limit'   => max( 1, (int) $row['rate_limit'] ),
		'last_used_at' => (int) $row['last_used_at'],
		'created_at'   => (int) $row['created_at'],
		'revoked_at'   => (int) $row['revoked_at'],
	);
}

function dn_bfs_api_get_key( $id ) {
	global $wpdb;

	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . dn_bfs_table( 'api_keys' ) . ' WHERE id = %d', (int) $id ), ARRAY_A );

	return $row ? dn_bfs_api_normalize_key_row( $row ) : null;
}

/**
 * Key row for a full key string, revoked keys included (callers check revoked_at).
 */
function dn_bfs_api_find_key( $key ) {
	global $wpdb;

	$parts = dn_bfs_api_parse_key( $key );

	if ( false === $parts ) {
		return null;
	}

	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . dn_bfs_table( 'api_keys' ) . ' WHERE prefix = %s', $parts['prefix'] ), ARRAY_A );

	if ( ! $row || ! hash_equals( (string) $row['key_hash'], dn_bfs_api_key_hash( $key ) ) ) {
		return null;
	}

	return dn_bfs_api_normalize_key_row( $row );
}

function dn_bfs_api_list_keys() {
	global $wpdb;

	$rows = $wpdb->get_results( 'SELECT * FROM ' . dn_bfs_table( 'api_keys' ) . ' ORDER BY (revoked_at = 0) DESC, created_at DESC, id DESC', ARRAY_A );

	return array_map( 'dn_bfs_api_normalize_key_row', (array) $rows );
}

function dn_bfs_api_revoke_key( $id, $now = null ) {
	global $wpdb;

	$now     = null === $now ? dn_bfs_now() : (int) $now;
	$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . dn_bfs_table( 'api_keys' ) . ' SET revoked_at = %d WHERE id = %d AND revoked_at = 0', max( 1, $now ), (int) $id ) );

	if ( ! $updated ) {
		return dn_bfs_request_error( 'key_not_found', __( 'That key does not exist or is already revoked.', 'dn-burst-funnel-stats' ), 404 );
	}

	// The per-key rate-limit counter row (includes/api/auth.php).
	delete_option( dn_bfs_api_rate_option( $id ) );

	return true;
}
