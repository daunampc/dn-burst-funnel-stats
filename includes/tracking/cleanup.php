<?php
/**
 * Daily housekeeping: raw-data retention, stale visitors, old salts, GeoIP refresh.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_cleanup_delete( $sql_with_limit ) {
	global $wpdb;

	$total = 0;

	for ( $batch = 0; $batch < 200; $batch++ ) {
		$deleted = (int) $wpdb->query( $sql_with_limit );
		$total  += $deleted;

		if ( $deleted < 5000 ) {
			break;
		}
	}

	return $total;
}

function dn_bfs_purge_salts( $now ) {
	global $wpdb;

	$keep    = array(
		'dnbfs_salt_' . wp_date( 'Y-m-d', $now ),
		'dnbfs_salt_' . dn_bfs_date_shift( wp_date( 'Y-m-d', $now ), -1 ),
	);
	$names   = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'dnbfs_salt_' ) . '%' ) );
	$removed = 0;

	foreach ( (array) $names as $name ) {
		if ( ! in_array( $name, $keep, true ) && delete_option( $name ) ) {
			$removed++;
		}
	}

	return $removed;
}

/**
 * Deletes expired report (dnbfs_r_) and API response (dnbfs_api_c_) transients
 * from wp_options, value and timeout rows together, in bounded batches. Runs
 * hourly with aggregation so these short-lived caches cannot pile up until
 * WordPress's daily expired-transient sweep.
 *
 * @return int Rows deleted.
 */
function dn_bfs_purge_expired_cache_transients() {
	global $wpdb;

	$now   = time(); // Transient timeouts are stored against the real clock.
	$total = 0;

	foreach ( array( 'dnbfs_api_c_', 'dnbfs_r_' ) as $prefix ) {
		$timeout_prefix = '_transient_timeout_' . $prefix;

		for ( $batch = 0; $batch < 50; $batch++ ) {
			$timeouts = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 1000", $wpdb->esc_like( $timeout_prefix ) . '%', $now ) );

			if ( empty( $timeouts ) ) {
				break;
			}

			$names = array();

			foreach ( $timeouts as $timeout ) {
				$names[] = $timeout;
				$names[] = '_transient_' . substr( $timeout, strlen( '_transient_timeout_' ) );
			}

			$total += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (" . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')', $names ) );

			if ( count( $timeouts ) < 1000 ) {
				break;
			}
		}
	}

	return $total;
}

function dn_bfs_cleanup_run( $now = null ) {
	global $wpdb;

	$now    = null === $now ? dn_bfs_now() : (int) $now;
	$result = array(
		'pageviews' => 0,
		'events'    => 0,
		'sessions'  => 0,
		'visitors'  => 0,
		'salts'     => 0,
	);

	$last = (string) get_option( 'dnbfs_last_aggregated_date', '' );

	// Never drop raw rows for a day that has not been aggregated yet.
	if ( '' !== $last ) {
		list( $before ) = dn_bfs_day_bounds( dn_bfs_raw_available_from( $now ) );

		$result['pageviews'] = dn_bfs_cleanup_delete( $wpdb->prepare( 'DELETE FROM ' . dn_bfs_table( 'pageviews' ) . ' WHERE time < %d LIMIT 5000', $before ) );
		$result['events']    = dn_bfs_cleanup_delete( $wpdb->prepare( 'DELETE FROM ' . dn_bfs_table( 'events' ) . " WHERE time < %d AND type <> 'order' LIMIT 5000", $before ) );
		$result['sessions']  = dn_bfs_cleanup_delete( $wpdb->prepare( 'DELETE FROM ' . dn_bfs_table( 'sessions' ) . ' WHERE started_at < %d LIMIT 5000', $before ) );
	}

	$result['visitors'] = dn_bfs_cleanup_delete( $wpdb->prepare( 'DELETE FROM ' . dn_bfs_table( 'visitors' ) . ' WHERE last_seen < %d LIMIT 5000', $now - 400 * DAY_IN_SECONDS ) );
	$result['salts']    = dn_bfs_purge_salts( $now );

	if ( function_exists( 'dn_bfs_maybe_update_geoip' ) ) {
		dn_bfs_maybe_update_geoip( $now );
	}

	return $result;
}

function dn_bfs_cron_cleanup() {
	dn_bfs_cleanup_run();
}
add_action( 'dnbfs_cleanup', 'dn_bfs_cron_cleanup' );
