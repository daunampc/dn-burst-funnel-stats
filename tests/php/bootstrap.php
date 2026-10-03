<?php
/**
 * Minimal WordPress stubs so pure plugin functions can be unit tested.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['dn_bfs_test_options'] = array();

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['dn_bfs_test_options'] ) ? $GLOBALS['dn_bfs_test_options'][ $key ] : $default;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

function sanitize_text_field( $value ) {
	$value = strip_tags( (string) $value );
	$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );

	return trim( $value );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function trailingslashit( $value ) {
	return untrailingslashit( $value ) . '/';
}

$dn_bfs_root = dirname( __DIR__, 2 );

require_once $dn_bfs_root . '/includes/tracking.php';

foreach ( array( 'ua-parser', 'channel', 'guard', 'geo' ) as $dn_bfs_file ) {
	$dn_bfs_path = $dn_bfs_root . '/includes/tracking/' . $dn_bfs_file . '.php';

	if ( file_exists( $dn_bfs_path ) ) {
		require_once $dn_bfs_path;
	}
}
