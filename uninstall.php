<?php
/**
 * Uninstall cleanup.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dn_atc_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dn_atc_%' OR option_name LIKE '_transient_timeout_dn_atc_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name IN ('dn_burst_funnel_stats_schema_version', 'dn_burst_funnel_stats_tracking_settings', 'dn_burst_funnel_stats_url_tracking_settings', 'dn_burst_funnel_stats_last_refresh')" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dn_bfs_%' OR option_name LIKE '_transient_timeout_dn_bfs_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_dn_burst_funnel_stats_github_release_%' OR option_name LIKE '_site_transient_timeout_dn_burst_funnel_stats_github_release_%'" );

$timestamp = wp_next_scheduled( 'dn_burst_funnel_stats_refresh_cache' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'dn_burst_funnel_stats_refresh_cache' );
}
