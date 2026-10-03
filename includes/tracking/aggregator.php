<?php
/**
 * Daily aggregation of raw tracking data into dnbfs_daily.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_day_bounds( $date ) {
	$start = new DateTimeImmutable( $date . ' 00:00:00', wp_timezone() );

	return array( $start->getTimestamp(), $start->modify( '+1 day' )->getTimestamp() );
}

function dn_bfs_raw_cutoff_date( $now ) {
	$settings = dn_bfs_get_tracking_settings();

	return dn_bfs_date_shift( wp_date( 'Y-m-d', (int) $now ), -1 * (int) $settings['raw_retention_days'] );
}

function dn_bfs_daily_write_rows( $date, $dimension, $rows, $columns = array() ) {
	global $wpdb;

	$columns = empty( $columns ) ? dn_bfs_metric_columns() : array_values( $columns );
	$table   = dn_bfs_table( 'daily' );
	$updates = array();

	foreach ( $columns as $column ) {
		$updates[] = "{$column} = VALUES({$column})";
	}

	foreach ( $rows as $value => $metrics ) {
		$value        = dn_bfs_truncate( (string) $value, 255 );
		$placeholders = array();
		$args         = array( $date, $dimension, md5( $value ), $value );

		foreach ( $columns as $column ) {
			$placeholders[] = in_array( $column, dn_bfs_money_columns(), true ) ? '%f' : '%d';
			$args[]         = $metrics[ $column ];
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (date, dimension, dim_hash, dim_value, " . implode( ', ', $columns ) . ')
				VALUES (%s, %s, %s, %s, ' . implode( ', ', $placeholders ) . ')
				ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates ),
				$args
			)
		);
	}
}

function dn_bfs_aggregate_day( $date, $now ) {
	global $wpdb;

	list( $start, $end ) = dn_bfs_day_bounds( $date );
	$table               = dn_bfs_table( 'daily' );

	if ( $date >= dn_bfs_raw_cutoff_date( $now ) ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE date = %s AND dimension <> 'blocked'", $date ) );

		foreach ( dn_bfs_aggregate_dimensions() as $dimension ) {
			dn_bfs_daily_write_rows( $date, $dimension, dn_bfs_raw_rows( $start, $end, $dimension, array() ) );
		}

		return 'full';
	}

	// Raw traffic is gone; order events are kept forever, so only order columns are rebuilt.
	$resets = array();

	foreach ( dn_bfs_order_columns() as $column ) {
		$resets[] = "{$column} = 0";
	}

	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $resets ) . " WHERE date = %s AND dimension <> 'blocked'", $date ) );

	foreach ( dn_bfs_order_dimensions() as $dimension ) {
		dn_bfs_daily_write_rows( $date, $dimension, dn_bfs_raw_order_rows( $start, $end, $dimension, array() ), dn_bfs_order_columns() );
	}

	return 'orders';
}

function dn_bfs_mark_dirty_date( $date, $now = 0 ) {
	$now = $now ? (int) $now : dn_bfs_now();

	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) || $date >= wp_date( 'Y-m-d', $now ) ) {
		return;
	}

	$dirty = get_option( 'dnbfs_dirty_dates', array() );
	$dirty = is_array( $dirty ) ? $dirty : array();

	$dirty[ $date ] = true;
	update_option( 'dnbfs_dirty_dates', $dirty, false );
}

function dn_bfs_mark_order_dirty( $order_id ) {
	global $wpdb;

	$time = $wpdb->get_var( $wpdb->prepare( 'SELECT time FROM ' . dn_bfs_table( 'events' ) . ' WHERE order_id = %d', (int) $order_id ) );

	if ( $time ) {
		dn_bfs_mark_dirty_date( wp_date( 'Y-m-d', (int) $time ) );
	}
}
add_action( 'woocommerce_order_status_changed', 'dn_bfs_mark_order_dirty', 10, 1 );
add_action( 'woocommerce_order_refunded', 'dn_bfs_mark_order_dirty', 10, 1 );

function dn_bfs_mark_spam_session_dirty( $session_id, $started_at ) {
	unset( $session_id );

	if ( $started_at ) {
		dn_bfs_mark_dirty_date( wp_date( 'Y-m-d', (int) $started_at ) );
	}
}
add_action( 'dn_bfs_session_marked_spam', 'dn_bfs_mark_spam_session_dirty', 10, 2 );

function dn_bfs_first_tracked_date() {
	global $wpdb;

	$times = array_filter(
		array(
			(int) $wpdb->get_var( 'SELECT MIN(started_at) FROM ' . dn_bfs_table( 'sessions' ) ),
			(int) $wpdb->get_var( 'SELECT MIN(time) FROM ' . dn_bfs_table( 'events' ) ),
		)
	);

	return empty( $times ) ? '' : wp_date( 'Y-m-d', min( $times ) );
}

function dn_bfs_aggregate_run( $now = null, $max_days = 31 ) {
	$now  = null === $now ? dn_bfs_now() : (int) $now;
	$lock = (int) get_option( 'dnbfs_aggregate_lock', 0 );

	if ( $lock > $now ) {
		return array(
			'ok'        => false,
			'reason'    => 'locked',
			'processed' => array(),
		);
	}

	update_option( 'dnbfs_aggregate_lock', $now + 10 * MINUTE_IN_SECONDS, false );

	$today     = wp_date( 'Y-m-d', $now );
	$yesterday = dn_bfs_date_shift( $today, -1 );
	$last      = (string) get_option( 'dnbfs_last_aggregated_date', '' );
	$processed = array();

	if ( '' === $last ) {
		$first = dn_bfs_first_tracked_date();
		$last  = '' === $first || $first > $yesterday ? $yesterday : dn_bfs_date_shift( $first, -1 );
		update_option( 'dnbfs_last_aggregated_date', $last, false );
	}

	for ( $cursor = dn_bfs_date_shift( $last, 1 ); $cursor <= $yesterday && count( $processed ) < $max_days; $cursor = dn_bfs_date_shift( $cursor, 1 ) ) {
		dn_bfs_aggregate_day( $cursor, $now );
		update_option( 'dnbfs_last_aggregated_date', $cursor, false );
		$processed[] = $cursor;
	}

	$dirty = get_option( 'dnbfs_dirty_dates', array() );
	$dirty = is_array( $dirty ) ? $dirty : array();
	$done  = 0;

	foreach ( array_keys( $dirty ) as $date ) {
		if ( $done >= $max_days ) {
			break;
		}

		if ( $date < $today && ! in_array( $date, $processed, true ) ) {
			dn_bfs_aggregate_day( $date, $now );
			$processed[] = $date;
		}

		unset( $dirty[ $date ] );
		$done++;
	}

	update_option( 'dnbfs_dirty_dates', $dirty, false );
	delete_option( 'dnbfs_aggregate_lock' );

	return array(
		'ok'        => true,
		'reason'    => '',
		'processed' => $processed,
	);
}

function dn_bfs_cron_aggregate() {
	dn_bfs_aggregate_run();
}
add_action( 'dnbfs_aggregate', 'dn_bfs_cron_aggregate' );

function dn_bfs_schedule_crons() {
	if ( ! wp_next_scheduled( 'dnbfs_aggregate' ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'dnbfs_aggregate' );
	}

	if ( ! wp_next_scheduled( 'dnbfs_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dnbfs_cleanup' );
	}
}

function dn_bfs_unschedule_crons() {
	wp_clear_scheduled_hook( 'dnbfs_aggregate' );
	wp_clear_scheduled_hook( 'dnbfs_cleanup' );
}
