<?php
/**
 * Uninstall cleanup: tables, options, transients, cron events and GeoIP files.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'visitors', 'sessions', 'pageviews', 'events', 'daily', 'api_keys' ) as $dn_bfs_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'dnbfs_' . $dn_bfs_table );
}

$dn_bfs_option_patterns = array(
	'dnbfs\_%',
	'dn\_bfs\_%',
	'dn\_burst\_funnel\_stats\_%',
	'dn\_atc\_%',
	'\_transient\_dnbfs\_%',
	'\_transient\_timeout\_dnbfs\_%',
	'\_transient\_dn\_bfs\_%',
	'\_transient\_timeout\_dn\_bfs\_%',
	'\_transient\_dn\_atc\_%',
	'\_transient\_timeout\_dn\_atc\_%',
	'\_site\_transient\_dn\_burst\_funnel\_stats\_%',
	'\_site\_transient\_timeout\_dn\_burst\_funnel\_stats\_%',
);

foreach ( $dn_bfs_option_patterns as $dn_bfs_pattern ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $dn_bfs_pattern ) );
}

wp_cache_flush();

foreach ( array( 'dnbfs_aggregate', 'dnbfs_cleanup', 'dn_burst_funnel_stats_refresh_cache' ) as $dn_bfs_hook ) {
	wp_clear_scheduled_hook( $dn_bfs_hook );
}

delete_metadata( 'user', 0, 'dnbfs_cards', '', true );

$dn_bfs_uploads = wp_upload_dir( null, false );
$dn_bfs_dir     = trailingslashit( $dn_bfs_uploads['basedir'] ) . 'dnbfs';

if ( is_dir( $dn_bfs_dir ) ) {
	foreach ( (array) glob( $dn_bfs_dir . '/*' ) as $dn_bfs_file ) {
		if ( is_file( $dn_bfs_file ) ) {
			unlink( $dn_bfs_file );
		}
	}

	rmdir( $dn_bfs_dir );
}
