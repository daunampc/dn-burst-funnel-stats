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

/**
 * First date that still has raw tracking data. Cleanup never purges days after
 * the aggregation watermark, so a lagging watermark extends raw availability
 * past the retention cutoff.
 */
function dn_bfs_raw_available_from( $now ) {
	$cutoff = dn_bfs_raw_cutoff_date( $now );
	$last   = (string) get_option( 'dnbfs_last_aggregated_date', '' );

	return '' === $last ? $cutoff : min( $cutoff, dn_bfs_date_shift( $last, 1 ) );
}

function dn_bfs_daily_write_rows( $date, $dimension, $rows, $columns = array() ) {
	global $wpdb;

	$columns = empty( $columns ) ? dn_bfs_metric_columns() : array_values( $columns );
	$table   = dn_bfs_table( 'daily' );
	$updates = array();
	$formats = array( '%s', '%s', '%s', '%s' );

	foreach ( $columns as $column ) {
		$updates[] = "{$column} = VALUES({$column})";
		$formats[] = in_array( $column, dn_bfs_money_columns(), true ) ? '%f' : '%d';
	}

	$tuple = '(' . implode( ', ', $formats ) . ')';

	foreach ( array_chunk( $rows, 200, true ) as $chunk ) {
		$tuples = array();
		$args   = array();

		foreach ( $chunk as $value => $metrics ) {
			$value    = dn_bfs_truncate( (string) $value, 255 );
			$tuples[] = $tuple;
			array_push( $args, $date, $dimension, md5( $value ), $value );

			foreach ( $columns as $column ) {
				$args[] = $metrics[ $column ];
			}
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (date, dimension, dim_hash, dim_value, " . implode( ', ', $columns ) . ')
				VALUES ' . implode( ', ', $tuples ) . '
				ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates ),
				$args
			)
		);
	}
}

/**
 * @param string|null $raw_from First date with raw data; pass the value computed
 *                              before a run moves the watermark.
 */
function dn_bfs_aggregate_day( $date, $now, $raw_from = null ) {
	global $wpdb;

	do_action( 'dn_bfs_before_aggregate_day', $date );

	list( $start, $end ) = dn_bfs_day_bounds( $date );
	$table               = dn_bfs_table( 'daily' );

	$raw_from = null === $raw_from ? dn_bfs_raw_available_from( $now ) : (string) $raw_from;

	if ( $date >= $raw_from ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE date = %s AND dimension <> 'blocked'", $date ) );

		foreach ( dn_bfs_aggregate_dimensions() as $dimension ) {
			dn_bfs_daily_write_rows( $date, $dimension, dn_bfs_raw_rows( $start, $end, $dimension, array() ) );
		}

		return 'full';
	}

	// Raw traffic is gone; order events are kept forever, so only order columns of order dimensions are rebuilt.
	$resets     = array();
	$dimensions = dn_bfs_order_dimensions();

	foreach ( dn_bfs_order_columns() as $column ) {
		$resets[] = "{$column} = 0";
	}

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET " . implode( ', ', $resets ) . " WHERE date = %s AND dimension <> 'blocked' AND dimension IN (" . implode( ', ', array_fill( 0, count( $dimensions ), '%s' ) ) . ')',
			array_merge( array( $date ), $dimensions )
		)
	);

	foreach ( $dimensions as $dimension ) {
		dn_bfs_daily_write_rows( $date, $dimension, dn_bfs_raw_order_rows( $start, $end, $dimension, array() ), dn_bfs_order_columns() );
	}

	return 'orders';
}

function dn_bfs_is_date_string( $date ) {
	return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date );
}

/**
 * One autoload=no option per dirty date (`dnbfs_dirty_<Y-m-d>`), so concurrent
 * marks never overwrite each other and the runner can clear a date before
 * rebuilding it (a re-mark during the rebuild survives for the next run).
 */
function dn_bfs_mark_dirty_date( $date, $now = 0 ) {
	$now = $now ? (int) $now : dn_bfs_now();

	if ( ! dn_bfs_is_date_string( $date ) || $date >= wp_date( 'Y-m-d', $now ) ) {
		return;
	}

	add_option( 'dnbfs_dirty_' . $date, 1, '', 'no' );
}

/**
 * Sorted suffixes of every `dnbfs_dirty_*` option (Y-m-d when written by dn_bfs_mark_dirty_date()).
 */
function dn_bfs_get_dirty_dates() {
	global $wpdb;

	$prefix = 'dnbfs_dirty_';
	$names  = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	$dates  = array();

	foreach ( (array) $names as $name ) {
		$dates[] = substr( (string) $name, strlen( $prefix ) );
	}

	sort( $dates, SORT_STRING );

	return $dates;
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

function dn_bfs_option_cache_forget( $name ) {
	wp_cache_delete( $name, 'options' );

	$notoptions = wp_cache_get( 'notoptions', 'options' );

	if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
		unset( $notoptions[ $name ] );
		wp_cache_set( 'notoptions', $notoptions, 'options' );
	}
}

function dn_bfs_aggregate_lock_read() {
	global $wpdb;

	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'dnbfs_aggregate_lock' ) );

	return null === $value ? null : (string) $value;
}

function dn_bfs_aggregate_lock_expiry( $value ) {
	return (int) explode( '|', (string) $value, 2 )[0];
}

/**
 * Lock value is "<expiry>|<token>". Inserted with INSERT IGNORE so only one
 * process can win; refresh/release are compare-and-swap on the stored value.
 *
 * @return string|false Lock value owned by the caller, or false when locked.
 */
function dn_bfs_aggregate_lock_acquire( $now ) {
	global $wpdb;

	$value = ( (int) $now + 10 * MINUTE_IN_SECONDS ) . '|' . wp_generate_password( 12, false );

	for ( $attempt = 0; $attempt < 2; $attempt++ ) {
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'dnbfs_aggregate_lock', $value ) );
		dn_bfs_option_cache_forget( 'dnbfs_aggregate_lock' );

		if ( 1 === (int) $inserted ) {
			return $value;
		}

		$current = dn_bfs_aggregate_lock_read();

		if ( null !== $current && dn_bfs_aggregate_lock_expiry( $current ) > (int) $now ) {
			return false;
		}

		if ( null !== $current ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", 'dnbfs_aggregate_lock', $current ) );
		}
	}

	return false;
}

/**
 * @return string|false New lock value, or false when the lock is no longer ours.
 */
function dn_bfs_aggregate_lock_refresh( $value, $now ) {
	global $wpdb;

	$parts = explode( '|', (string) $value, 2 );
	$next  = ( (int) $now + 10 * MINUTE_IN_SECONDS ) . '|' . ( isset( $parts[1] ) ? $parts[1] : '' );

	if ( $next === $value ) {
		return $value;
	}

	$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $next, 'dnbfs_aggregate_lock', $value ) );
	dn_bfs_option_cache_forget( 'dnbfs_aggregate_lock' );

	return 1 === (int) $updated ? $next : false;
}

function dn_bfs_aggregate_lock_release( $value ) {
	global $wpdb;

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", 'dnbfs_aggregate_lock', $value ) );
	dn_bfs_option_cache_forget( 'dnbfs_aggregate_lock' );
}

/**
 * Rebuilds dirty dates and catches up to yesterday. Stops starting new days
 * after `$max_days` (per phase) or once the `dn_bfs_aggregate_time_budget`
 * (seconds, default 25) is spent; the budget is checked before each day except
 * the first, so every run makes progress even with a budget of 0.
 */
function dn_bfs_aggregate_run( $now = null, $max_days = 31 ) {
	$started = microtime( true );
	$now     = null === $now ? dn_bfs_now() : (int) $now;
	$lock    = dn_bfs_aggregate_lock_acquire( $now );

	if ( false === $lock ) {
		return array(
			'ok'        => false,
			'reason'    => 'locked',
			'processed' => array(),
		);
	}

	$budget    = (int) apply_filters( 'dn_bfs_aggregate_time_budget', 25 );
	$today     = wp_date( 'Y-m-d', $now );
	$yesterday = dn_bfs_date_shift( $today, -1 );
	$last      = (string) get_option( 'dnbfs_last_aggregated_date', '' );
	$processed = array();
	$stop      = false;

	if ( '' === $last ) {
		$first = dn_bfs_first_tracked_date();
		$last  = '' === $first || $first > $yesterday ? $yesterday : dn_bfs_date_shift( $first, -1 );
		update_option( 'dnbfs_last_aggregated_date', $last, false );
	}

	// Fixed for the whole run: advancing the watermark must not shrink it mid-run.
	$raw_from = dn_bfs_raw_available_from( $now );

	$can_start = function () use ( &$processed, &$stop, $started, $budget ) {
		return ! $stop && ( empty( $processed ) || microtime( true ) - $started <= $budget );
	};

	$run_day = function ( $date ) use ( &$processed, &$stop, &$lock, $now, $raw_from ) {
		dn_bfs_raw_get_order( 0, true );
		dn_bfs_aggregate_day( $date, $now, $raw_from );
		$processed[] = $date;
		$lock        = dn_bfs_aggregate_lock_refresh( $lock, dn_bfs_now() );
		$stop        = false === $lock;
	};

	for ( $cursor = dn_bfs_date_shift( $last, 1 ); $cursor <= $yesterday && count( $processed ) < $max_days && $can_start(); $cursor = dn_bfs_date_shift( $cursor, 1 ) ) {
		$run_day( $cursor );
		update_option( 'dnbfs_last_aggregated_date', $cursor, false );
	}

	$done = 0;

	foreach ( dn_bfs_get_dirty_dates() as $date ) {
		if ( ! dn_bfs_is_date_string( $date ) ) {
			delete_option( 'dnbfs_dirty_' . $date );
			continue;
		}

		// Today is still filling up; already-rebuilt dates may have been re-marked mid-run, so keep both for the next run.
		if ( $date >= $today || in_array( $date, $processed, true ) ) {
			continue;
		}

		if ( $done >= $max_days || ! $can_start() ) {
			break;
		}

		delete_option( 'dnbfs_dirty_' . $date );
		$run_day( $date );
		$done++;
	}

	if ( false !== $lock ) {
		dn_bfs_aggregate_lock_release( $lock );
	}

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
