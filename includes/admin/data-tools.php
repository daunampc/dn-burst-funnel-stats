<?php
/**
 * Data tools behind Settings → Data and GeoIP.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_data_stats() {
	global $wpdb;

	$tables = array();

	foreach ( dn_bfs_schema_tables() as $name ) {
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT TABLE_ROWS AS rows_estimate, DATA_LENGTH + INDEX_LENGTH AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				dn_bfs_table( $name )
			),
			ARRAY_A
		);

		$tables[ $name ] = array(
			'rows'  => $row ? (int) $row['rows_estimate'] : 0,
			'bytes' => $row ? (int) $row['bytes'] : 0,
		);
	}

	$settings = dn_bfs_get_tracking_settings();
	$error    = get_option( 'dnbfs_aggregate_last_error', null );

	return array(
		'tables'             => $tables,
		'last_aggregated'    => (string) get_option( 'dnbfs_last_aggregated_date', '' ),
		'raw_available_from' => dn_bfs_raw_available_from( dn_bfs_now() ),
		'retention_days'     => (int) $settings['raw_retention_days'],
		'dirty_dates'        => count( dn_bfs_get_dirty_dates() ),
		'last_error'         => is_array( $error ) ? $error : null,
	);
}

function dn_bfs_reaggregate_range( $start, $end ) {
	if ( ! dn_bfs_valid_date_string( $start ) || ! dn_bfs_valid_date_string( $end ) || $start > $end ) {
		return dn_bfs_request_error( 'invalid_date', __( 'Use valid start and end dates in YYYY-MM-DD format.', 'dn-burst-funnel-stats' ) );
	}

	$dates = dn_bfs_dates_between( $start, $end );

	if ( count( $dates ) > 92 ) {
		return dn_bfs_request_error( 'range_too_long', __( 'Re-aggregate at most 92 days at a time.', 'dn-burst-funnel-stats' ) );
	}

	$closable = dn_bfs_last_closable_date( dn_bfs_now() );
	$queued   = 0;

	foreach ( $dates as $date ) {
		if ( $date <= $closable ) {
			dn_bfs_mark_dirty_date( $date );
			$queued++;
		}
	}

	$result    = dn_bfs_aggregate_run();
	$dirty     = dn_bfs_get_dirty_dates();
	$remaining = 0;

	foreach ( $dates as $date ) {
		// A date rebuilt in this run counts as done even if it stays marked dirty.
		if ( $date <= $closable && in_array( $date, $dirty, true ) && ! in_array( $date, (array) $result['processed'], true ) ) {
			$remaining++;
		}
	}

	return array(
		'queued'    => $queued,
		'remaining' => $remaining,
		'result'    => $result,
	);
}

function dn_bfs_purge_all_data( $confirm ) {
	global $wpdb;

	if ( 'DELETE' !== $confirm ) {
		return dn_bfs_request_error( 'confirm_required', __( 'Type DELETE to confirm.', 'dn-burst-funnel-stats' ) );
	}

	foreach ( array( 'visitors', 'sessions', 'pageviews', 'events', 'daily' ) as $name ) {
		$wpdb->query( 'TRUNCATE TABLE ' . dn_bfs_table( $name ) );
	}

	foreach ( dn_bfs_get_dirty_dates() as $date ) {
		delete_option( 'dnbfs_dirty_' . $date );
	}

	foreach ( array( 'dnbfs_last_aggregated_date', 'dnbfs_aggregate_last_error' ) as $option ) {
		delete_option( $option );
	}

	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_dnbfs_r_' ) . '%' ) );

	foreach ( (array) $names as $name ) {
		delete_transient( substr( $name, strlen( '_transient_' ) ) );
	}

	return true;
}

function dn_bfs_export_settings() {
	$tracking = dn_bfs_get_tracking_settings();
	unset( $tracking['maxmind_license_key'], $tracking['invalid_excluded_ips'] );

	return array(
		'meta'     => array(
			'plugin'         => 'dn-burst-funnel-stats',
			'plugin_version' => DN_BURST_FUNNEL_STATS_VERSION,
			'schema_version' => DN_BURST_FUNNEL_STATS_SCHEMA_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'site_url'       => home_url(),
		),
		'settings' => array(
			'tracking'           => $tracking,
			'woocommerce_report' => dn_bfs_get_wc_report_settings(),
		),
	);
}

function dn_bfs_import_settings( $payload ) {
	if ( ! is_array( $payload ) || ! isset( $payload['meta']['plugin'] ) || 'dn-burst-funnel-stats' !== $payload['meta']['plugin'] || ! isset( $payload['settings'] ) || ! is_array( $payload['settings'] ) ) {
		return dn_bfs_request_error( 'invalid_import', __( 'This file is not a DN Burst Funnel Stats export.', 'dn-burst-funnel-stats' ) );
	}

	$imported = array();

	if ( isset( $payload['settings']['tracking'] ) && is_array( $payload['settings']['tracking'] ) ) {
		$incoming = $payload['settings']['tracking'];
		unset( $incoming['maxmind_license_key'] );

		update_option( 'dn_burst_funnel_stats_tracking_settings', dn_bfs_sanitize_tracking_settings( array_merge( dn_bfs_get_tracking_settings(), $incoming ) ), false );
		$imported[] = 'tracking';
	}

	if ( isset( $payload['settings']['woocommerce_report'] ) && is_array( $payload['settings']['woocommerce_report'] ) ) {
		update_option( 'dn_burst_funnel_stats_wc_report_settings', dn_bfs_sanitize_wc_report_settings( $payload['settings']['woocommerce_report'] ), false );
		$imported[] = 'woocommerce_report';
	}

	return array( 'imported' => $imported );
}

function dn_bfs_geoip_update_now() {
	$settings = dn_bfs_get_tracking_settings();

	if ( '' === $settings['maxmind_license_key'] ) {
		return dn_bfs_request_error( 'no_license', __( 'Add a MaxMind license key first.', 'dn-burst-funnel-stats' ) );
	}

	$result           = dn_bfs_geoip_update( $settings['maxmind_license_key'] );
	$result['status'] = dn_bfs_geoip_status();

	return $result;
}

function dn_bfs_blocked_stats( $days = 7, $now = null ) {
	global $wpdb;

	$now   = null === $now ? dn_bfs_now() : (int) $now;
	$since = dn_bfs_date_shift( wp_date( 'Y-m-d', $now ), -1 * ( max( 1, (int) $days ) - 1 ) );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT dim_value, SUM(pageviews) AS total FROM ' . dn_bfs_table( 'daily' ) . " WHERE dimension = 'blocked' AND date >= %s GROUP BY dim_hash, dim_value ORDER BY total DESC, dim_value ASC",
			$since
		),
		ARRAY_A
	);
	$stats = array();

	foreach ( (array) $rows as $row ) {
		$stats[ (string) $row['dim_value'] ] = (int) $row['total'];
	}

	return $stats;
}
