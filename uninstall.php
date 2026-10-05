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

foreach ( array( 'dnbfs_aggregate', 'dnbfs_cleanup', 'dnbfs_backfill_orders', 'dn_burst_funnel_stats_refresh_cache' ) as $dn_bfs_hook ) {
	wp_clear_scheduled_hook( $dn_bfs_hook );
}

delete_metadata( 'user', 0, 'dnbfs_cards', '', true );

// Order tracking meta, in post storage and (when present) the HPOS meta table.
$dn_bfs_order_meta = array( '_dnbfs_session_uid', '_dnbfs_visitor_uid', '_dnbfs_excluded' );

foreach ( $dn_bfs_order_meta as $dn_bfs_meta_key ) {
	delete_post_meta_by_key( $dn_bfs_meta_key );
}

$dn_bfs_hpos_meta = $wpdb->prefix . 'wc_orders_meta';

if ( $dn_bfs_hpos_meta === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $dn_bfs_hpos_meta ) ) ) ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$dn_bfs_hpos_meta} WHERE meta_key IN (" . implode( ', ', array_fill( 0, count( $dn_bfs_order_meta ), '%s' ) ) . ')', $dn_bfs_order_meta ) );
}

$dn_bfs_uploads = wp_upload_dir( null, false );
$dn_bfs_dir     = trailingslashit( $dn_bfs_uploads['basedir'] ) . 'dnbfs';

if ( is_dir( $dn_bfs_dir ) ) {
	// Recursive, including dotfiles such as .htaccess.
	$dn_bfs_entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dn_bfs_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $dn_bfs_entries as $dn_bfs_entry ) {
		if ( $dn_bfs_entry->isDir() && ! $dn_bfs_entry->isLink() ) {
			rmdir( $dn_bfs_entry->getPathname() );
		} else {
			unlink( $dn_bfs_entry->getPathname() );
		}
	}

	unset( $dn_bfs_entries, $dn_bfs_entry );
	rmdir( $dn_bfs_dir );
}
