<?php
/**
 * Uninstall cleanup.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dn_atc_hits_%' OR option_name LIKE 'dn_atc_qty_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dn_atc_seen_%' OR option_name LIKE '_transient_timeout_dn_atc_seen_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_dn_burst_funnel_stats_github_release_%' OR option_name LIKE '_site_transient_timeout_dn_burst_funnel_stats_github_release_%'" );
