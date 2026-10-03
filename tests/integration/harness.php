<?php
/**
 * Tiny assertion harness for integration tests run through `wp eval-file`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DN_BFS_IT_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36' );

$GLOBALS['dn_bfs_it_results'] = array(
	'pass' => 0,
	'fail' => 0,
);
$GLOBALS['dn_bfs_it_now']     = null;

add_filter(
	'dn_bfs_now',
	function ( $now ) {
		return null === $GLOBALS['dn_bfs_it_now'] ? $now : $GLOBALS['dn_bfs_it_now'];
	}
);

function dn_bfs_it_set_now( $timestamp ) {
	$GLOBALS['dn_bfs_it_now'] = null === $timestamp ? null : (int) $timestamp;
}

function dn_bfs_it_uid( $seed ) {
	return md5( (string) $seed );
}

function dn_bfs_it_settings( $overrides = array() ) {
	delete_option( 'dn_burst_funnel_stats_tracking_settings' );
	update_option(
		'dn_burst_funnel_stats_tracking_settings',
		array_merge( dn_bfs_get_tracking_settings(), $overrides ),
		false
	);
}

function dn_bfs_it_reset() {
	global $wpdb;

	if ( function_exists( 'dn_bfs_table' ) ) {
		foreach ( array( 'visitors', 'sessions', 'pageviews', 'events', 'daily' ) as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . dn_bfs_table( $table ) );
		}
	}

	dn_bfs_it_settings( array() );
	dn_bfs_it_set_now( null );
	wp_set_current_user( 0 );

	$_COOKIE                    = array();
	$_SERVER['REMOTE_ADDR']     = '203.0.113.10';
	$_SERVER['HTTP_USER_AGENT'] = DN_BFS_IT_UA;
	$_SERVER['HTTP_REFERER']    = '';
	unset( $_SERVER['HTTP_CF_RAY'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_CF_IPCOUNTRY'] );
}

function dn_bfs_it( $name, $callback ) {
	dn_bfs_it_reset();

	try {
		$callback();
		$GLOBALS['dn_bfs_it_results']['pass']++;
		WP_CLI::log( 'PASS ' . $name );
	} catch ( Throwable $e ) {
		$GLOBALS['dn_bfs_it_results']['fail']++;
		WP_CLI::log( 'FAIL ' . $name . ': ' . $e->getMessage() );
	}
}

function dn_bfs_assert_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( trim( $message . ' expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ) );
	}
}

function dn_bfs_assert_true( $value, $message = '' ) {
	dn_bfs_assert_same( true, (bool) $value, $message );
}

function dn_bfs_it_count( $table, $where = '1=1' ) {
	global $wpdb;

	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . dn_bfs_table( $table ) . ' WHERE ' . $where );
}

function dn_bfs_it_collect( $body, $headers = array() ) {
	$request = new WP_REST_Request( 'POST', '/dnbfs/v1/collect' );
	$request->set_body( wp_json_encode( $body ) );
	$request->set_header( 'origin', home_url() );
	$request->set_header( 'user_agent', DN_BFS_IT_UA );

	foreach ( $headers as $key => $value ) {
		$request->set_header( $key, $value );
	}

	return rest_do_request( $request );
}

function dn_bfs_it_report() {
	$results = $GLOBALS['dn_bfs_it_results'];
	WP_CLI::log( sprintf( '%d passed, %d failed', $results['pass'], $results['fail'] ) );

	return $results['fail'] > 0 ? 1 : 0;
}
