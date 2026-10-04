<?php

require_once __DIR__ . '/seed.php';

function dn_bfs_it_daily( $date, $dimension, $value = '' ) {
	global $wpdb;

	return $wpdb->get_row(
		$wpdb->prepare( 'SELECT * FROM ' . dn_bfs_table( 'daily' ) . ' WHERE date = %s AND dimension = %s AND dim_hash = %s', $date, $dimension, dn_bfs_dim_hash( $value ) ),
		ARRAY_A
	);
}

function dn_bfs_it_clear_dirty_dates() {
	global $wpdb;

	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'dnbfs_dirty_' ) . '%' ) );

	foreach ( $names as $name ) {
		delete_option( $name );
	}
}

function dn_bfs_it_reset_aggregator_state() {
	delete_option( 'dnbfs_last_aggregated_date' );
	dn_bfs_it_clear_dirty_dates();
	delete_option( 'dnbfs_aggregate_lock' );
	delete_option( 'dnbfs_aggregate_last_error' );
	remove_all_actions( 'dn_bfs_before_aggregate_day' );
	remove_all_filters( 'dn_bfs_aggregate_time_budget' );
	dn_bfs_raw_get_order( 0, true );
}

dn_bfs_it(
	'aggregate day writes every dimension and keeps blocked counters',
	function () {
		dn_bfs_it_reset_aggregator_state();
		$day_ts = dn_bfs_it_day_noon( 1 );
		$date   = wp_date( 'Y-m-d', $day_ts );

		$session = dn_bfs_it_seed_session( array( 'started_at' => $day_ts, 'channel' => 'paid', 'utm_campaign' => 'sale-10', 'pageviews' => 2, 'is_bounce' => 0 ) );
		dn_bfs_it_seed_pageview( $session, '/', $day_ts );
		dn_bfs_it_seed_pageview( $session, '/cart/', $day_ts + 10 );
		dn_bfs_it_seed_event( $session, 'product_view', $day_ts, array( 'product_id' => 101 ) );
		dn_bfs_store_count_blocked( 'bot', $day_ts );

		dn_bfs_assert_same( 'full', dn_bfs_aggregate_day( $date, time() ) );
		dn_bfs_assert_same( 'full', dn_bfs_aggregate_day( $date, time() ) );

		dn_bfs_assert_same( '1', dn_bfs_it_daily( $date, 'total' )['sessions'] );
		dn_bfs_assert_same( '2', dn_bfs_it_daily( $date, 'total' )['pageviews'] );
		dn_bfs_assert_same( '1', dn_bfs_it_daily( $date, 'campaign', 'sale-10' )['sessions'] );
		dn_bfs_assert_same( '1', dn_bfs_it_daily( $date, 'page', '/cart/' )['pageviews'] );
		dn_bfs_assert_same( '1', dn_bfs_it_daily( $date, 'product', '101' )['product_views'] );
		dn_bfs_assert_same( '1', dn_bfs_it_daily( $date, 'blocked', 'bot' )['pageviews'] );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'daily', $GLOBALS['wpdb']->prepare( "date = %s AND dimension = 'total'", $date ) ) );
	}
);

dn_bfs_it(
	'dates older than raw retention only recompute order columns',
	function () {
		global $wpdb;

		dn_bfs_it_reset_aggregator_state();
		dn_bfs_it_settings( array( 'raw_retention_days' => 7 ) );

		$day_ts = dn_bfs_it_day_noon( 10 );
		$date   = wp_date( 'Y-m-d', $day_ts );
		$wpdb->insert( dn_bfs_table( 'daily' ), array( 'date' => $date, 'dimension' => 'total', 'dim_hash' => md5( '' ), 'dim_value' => '', 'sessions' => 5, 'orders' => 9 ) );

		$product = (int) wc_get_products( array( 'limit' => 1, 'return' => 'ids' ) )[0];
		$order   = wc_create_order();
		$order->add_product( wc_get_product( $product ), 1 );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->save();
		dn_bfs_it_seed_event( array( 'id' => 0, 'visitor_uid' => '', 'channel' => 'direct', 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'country' => '', 'device' => '' ), 'order', $day_ts, array( 'order_id' => $order->get_id() ) );

		dn_bfs_assert_same( 'orders', dn_bfs_aggregate_day( $date, time() ) );

		$row = dn_bfs_it_daily( $date, 'total' );
		dn_bfs_assert_same( '5', $row['sessions'] );
		dn_bfs_assert_same( '1', $row['orders'] );
	}
);

dn_bfs_it(
	'order status change and spam marking flag past dates as dirty',
	function () {
		dn_bfs_it_reset_aggregator_state();
		$day_ts  = dn_bfs_it_day_noon( 2 );
		$date    = wp_date( 'Y-m-d', $day_ts );
		$session = dn_bfs_it_seed_session( array( 'started_at' => $day_ts ) );

		$product = (int) wc_get_products( array( 'limit' => 1, 'return' => 'ids' ) )[0];
		$order   = wc_create_order();
		$order->add_product( wc_get_product( $product ), 1 );
		$order->calculate_totals();
		$order->save();
		dn_bfs_it_seed_event( $session, 'order', $day_ts, array( 'order_id' => $order->get_id() ) );

		$order->update_status( 'cancelled' );
		dn_bfs_assert_same( array( $date ), dn_bfs_get_dirty_dates() );

		dn_bfs_it_clear_dirty_dates();
		dn_bfs_store_mark_spam( (int) $session['id'] );
		dn_bfs_assert_same( array( $date ), dn_bfs_get_dirty_dates() );

		dn_bfs_mark_dirty_date( $date );
		dn_bfs_assert_same( array( $date ), dn_bfs_get_dirty_dates() );

		dn_bfs_mark_dirty_date( wp_date( 'Y-m-d', time() ) );
		dn_bfs_mark_dirty_date( 'not-a-date' );
		dn_bfs_assert_same( array( $date ), dn_bfs_get_dirty_dates() );
	}
);

dn_bfs_it(
	'aggregate run catches up from the first tracked date and drains dirty dates',
	function () {
		dn_bfs_it_reset_aggregator_state();

		dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 3 ) ) );
		$result    = dn_bfs_aggregate_run( time() );
		$yesterday = dn_bfs_date_shift( wp_date( 'Y-m-d', time() ), -1 );

		dn_bfs_assert_true( $result['ok'], 'ok' );
		dn_bfs_assert_same( 3, count( $result['processed'] ) );
		dn_bfs_assert_same( $yesterday, get_option( 'dnbfs_last_aggregated_date' ) );

		$dirty = dn_bfs_date_shift( $yesterday, -5 );
		dn_bfs_mark_dirty_date( $dirty );
		$again = dn_bfs_aggregate_run( time() );

		dn_bfs_assert_same( array( $dirty ), $again['processed'] );
		dn_bfs_assert_same( array(), dn_bfs_get_dirty_dates() );
	}
);

dn_bfs_it(
	'aggregate run respects the lock and an empty install starts at yesterday',
	function () {
		dn_bfs_it_reset_aggregator_state();

		update_option( 'dnbfs_aggregate_lock', ( time() + 300 ) . '|other', false );
		dn_bfs_assert_same( 'locked', dn_bfs_aggregate_run( time() )['reason'] );
		delete_option( 'dnbfs_aggregate_lock' );

		$result = dn_bfs_aggregate_run( time() );
		dn_bfs_assert_same( array(), $result['processed'] );
		dn_bfs_assert_same( dn_bfs_date_shift( wp_date( 'Y-m-d', time() ), -1 ), get_option( 'dnbfs_last_aggregated_date' ) );
		dn_bfs_assert_true( false === get_option( 'dnbfs_aggregate_lock' ), 'lock released' );
	}
);

dn_bfs_it(
	'a date marked dirty during a run survives for the next run',
	function () {
		dn_bfs_it_reset_aggregator_state();

		$yesterday = dn_bfs_date_shift( wp_date( 'Y-m-d', time() ), -1 );
		$first     = dn_bfs_date_shift( $yesterday, -3 );
		$other     = dn_bfs_date_shift( $yesterday, -6 );
		$fired     = false;
		update_option( 'dnbfs_last_aggregated_date', $yesterday, false );
		dn_bfs_mark_dirty_date( $first );

		add_action(
			'dn_bfs_before_aggregate_day',
			function ( $date ) use ( &$fired, $first, $other ) {
				if ( ! $fired && $date === $first ) {
					$fired = true;
					dn_bfs_mark_dirty_date( $first );
					dn_bfs_mark_dirty_date( $other );
				}
			}
		);

		$result = dn_bfs_aggregate_run( time() );

		dn_bfs_assert_true( $fired, 'hook fired' );
		dn_bfs_assert_same( array( $first ), $result['processed'] );
		dn_bfs_assert_same( array( $other, $first ), dn_bfs_get_dirty_dates() );

		remove_all_actions( 'dn_bfs_before_aggregate_day' );
		$next = dn_bfs_aggregate_run( time() );

		dn_bfs_assert_same( array( $other, $first ), $next['processed'] );
		dn_bfs_assert_same( array(), dn_bfs_get_dirty_dates() );
	}
);

dn_bfs_it(
	'aggregate run caps catch-up days at max_days',
	function () {
		dn_bfs_it_reset_aggregator_state();

		$first = wp_date( 'Y-m-d', dn_bfs_it_day_noon( 5 ) );
		dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 5 ) ) );
		$result = dn_bfs_aggregate_run( time(), 2 );

		dn_bfs_assert_same( array( $first, dn_bfs_date_shift( $first, 1 ) ), $result['processed'] );
		dn_bfs_assert_same( dn_bfs_date_shift( $first, 1 ), get_option( 'dnbfs_last_aggregated_date' ) );
	}
);

dn_bfs_it(
	'a zero time budget still processes one day per run',
	function () {
		dn_bfs_it_reset_aggregator_state();
		add_filter( 'dn_bfs_aggregate_time_budget', '__return_zero' );

		$first = wp_date( 'Y-m-d', dn_bfs_it_day_noon( 3 ) );
		dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 3 ) ) );
		dn_bfs_mark_dirty_date( dn_bfs_date_shift( $first, -4 ) );

		dn_bfs_assert_same( array( $first ), dn_bfs_aggregate_run( time() )['processed'] );
		dn_bfs_assert_same( array( dn_bfs_date_shift( $first, 1 ) ), dn_bfs_aggregate_run( time() )['processed'] );
		dn_bfs_assert_same( array( dn_bfs_date_shift( $first, 2 ) ), dn_bfs_aggregate_run( time() )['processed'] );
		dn_bfs_assert_same( array( dn_bfs_date_shift( $first, -4 ) ), dn_bfs_aggregate_run( time() )['processed'] );
		dn_bfs_assert_same( array(), dn_bfs_get_dirty_dates() );
		remove_filter( 'dn_bfs_aggregate_time_budget', '__return_zero' );
	}
);

dn_bfs_it(
	'an expired lock is taken over and a live foreign lock is left untouched',
	function () {
		dn_bfs_it_reset_aggregator_state();

		update_option( 'dnbfs_aggregate_lock', ( time() - 10 ) . '|old', false );
		dn_bfs_assert_true( dn_bfs_aggregate_run( time() )['ok'], 'expired lock taken over' );
		dn_bfs_assert_true( false === get_option( 'dnbfs_aggregate_lock' ), 'lock released after takeover' );

		$foreign = ( time() + 300 ) . '|foreign';
		update_option( 'dnbfs_aggregate_lock', $foreign, false );
		$result = dn_bfs_aggregate_run( time() );

		dn_bfs_assert_true( ! $result['ok'], 'not ok' );
		dn_bfs_assert_same( 'locked', $result['reason'] );
		dn_bfs_assert_same( $foreign, get_option( 'dnbfs_aggregate_lock' ) );
	}
);

dn_bfs_it(
	'orders-only rebuild leaves non-order dimensions and blocked rows alone',
	function () {
		global $wpdb;

		dn_bfs_it_reset_aggregator_state();
		dn_bfs_it_settings( array( 'raw_retention_days' => 7 ) );

		$date = wp_date( 'Y-m-d', dn_bfs_it_day_noon( 10 ) );
		$wpdb->insert( dn_bfs_table( 'daily' ), array( 'date' => $date, 'dimension' => 'browser', 'dim_hash' => dn_bfs_dim_hash( 'Chrome' ), 'dim_value' => 'Chrome', 'sessions' => 3, 'orders' => 4 ) );
		$wpdb->insert( dn_bfs_table( 'daily' ), array( 'date' => $date, 'dimension' => 'blocked', 'dim_hash' => md5( 'bot' ), 'dim_value' => 'bot', 'pageviews' => 7, 'orders' => 2 ) );
		$wpdb->insert( dn_bfs_table( 'daily' ), array( 'date' => $date, 'dimension' => 'channel', 'dim_hash' => md5( 'paid' ), 'dim_value' => 'paid', 'sessions' => 2, 'orders' => 5 ) );

		dn_bfs_assert_same( 'orders', dn_bfs_aggregate_day( $date, time() ) );

		dn_bfs_assert_same( '4', dn_bfs_it_daily( $date, 'browser', 'Chrome' )['orders'] );
		dn_bfs_assert_same( '3', dn_bfs_it_daily( $date, 'browser', 'Chrome' )['sessions'] );
		dn_bfs_assert_same( '2', dn_bfs_it_daily( $date, 'blocked', 'bot' )['orders'] );
		dn_bfs_assert_same( '7', dn_bfs_it_daily( $date, 'blocked', 'bot' )['pageviews'] );
		dn_bfs_assert_same( '0', dn_bfs_it_daily( $date, 'channel', 'paid' )['orders'] );
		dn_bfs_assert_same( '2', dn_bfs_it_daily( $date, 'channel', 'paid' )['sessions'] );
	}
);

dn_bfs_it(
	'daily writes are batched beyond 200 rows',
	function () {
		dn_bfs_it_reset_aggregator_state();
		$day_ts  = dn_bfs_it_day_noon( 1 );
		$date    = wp_date( 'Y-m-d', $day_ts );
		$session = dn_bfs_it_seed_session( array( 'started_at' => $day_ts, 'pageviews' => 250, 'is_bounce' => 0 ) );

		for ( $i = 0; $i < 250; $i++ ) {
			dn_bfs_it_seed_pageview( $session, '/p-' . $i . '/', $day_ts + $i );
		}

		dn_bfs_assert_same( 'full', dn_bfs_aggregate_day( $date, time() ) );
		dn_bfs_assert_same( 250, dn_bfs_it_count( 'daily', $GLOBALS['wpdb']->prepare( "date = %s AND dimension = 'page'", $date ) ) );
		dn_bfs_assert_same( '1', dn_bfs_it_daily( $date, 'page', '/p-249/' )['pageviews'] );
		dn_bfs_assert_same( '250', dn_bfs_it_daily( $date, 'total' )['pageviews'] );
	}
);

dn_bfs_it(
	'crons are scheduled',
	function () {
		dn_bfs_schedule_crons();

		dn_bfs_assert_true( (bool) wp_next_scheduled( 'dnbfs_aggregate' ), 'aggregate scheduled' );
		dn_bfs_assert_true( (bool) wp_next_scheduled( 'dnbfs_cleanup' ), 'cleanup scheduled' );
	}
);

dn_bfs_it(
	'a lagging watermark keeps unpurged days beyond retention fully rebuildable and reportable',
	function () {
		dn_bfs_it_reset_aggregator_state();
		dn_bfs_it_settings( array( 'raw_retention_days' => 7 ) );

		$today  = wp_date( 'Y-m-d', time() );
		$day_ts = dn_bfs_it_day_noon( 15 );
		$date   = wp_date( 'Y-m-d', $day_ts );
		update_option( 'dnbfs_last_aggregated_date', dn_bfs_date_shift( $today, -20 ), false );

		$session = dn_bfs_it_seed_session( array( 'started_at' => $day_ts ) );
		dn_bfs_it_seed_pageview( $session, '/', $day_ts );

		dn_bfs_assert_same( dn_bfs_date_shift( $today, -19 ), dn_bfs_raw_available_from( time() ) );

		list( $start, $end ) = dn_bfs_day_bounds( $date );
		$before              = dn_bfs_report_period( $start, $end - 1, time() );
		dn_bfs_assert_same( false, $before['incomplete'], 'incomplete before aggregation' );
		dn_bfs_assert_same( $date, $before['live_start_date'] );

		$result = dn_bfs_aggregate_run( time() );
		dn_bfs_assert_true( $result['ok'], 'ok' );

		$row = dn_bfs_it_daily( $date, 'total' );
		dn_bfs_assert_true( is_array( $row ), 'total row written' );
		dn_bfs_assert_same( '1', $row['sessions'] );
		dn_bfs_assert_same( '1', $row['pageviews'] );

		$after = dn_bfs_report_period( $start, $end - 1, time() );
		dn_bfs_assert_same( false, $after['incomplete'], 'incomplete after aggregation' );
		dn_bfs_assert_same( 1, dn_bfs_report_summary( array( 'current_start' => $start, 'current_end' => $end - 1, 'compare' => 'none' ) )['current']['sessions'] );
	}
);

function dn_bfs_it_fail_daily_inserts( $query ) {
	return 0 === strpos( ltrim( $query ), 'INSERT INTO ' . dn_bfs_table( 'daily' ) ) ? '' : $query;
}

dn_bfs_it(
	'a failed daily write rolls the day back and keeps the date dirty',
	function () {
		dn_bfs_it_reset_aggregator_state();

		$yesterday = dn_bfs_date_shift( wp_date( 'Y-m-d', time() ), -1 );
		$day_ts    = dn_bfs_it_day_noon( 3 );
		$date      = wp_date( 'Y-m-d', $day_ts );
		dn_bfs_it_seed_session( array( 'started_at' => $day_ts ) );
		dn_bfs_assert_same( 'full', dn_bfs_aggregate_day( $date, time() ) );

		dn_bfs_it_seed_session( array( 'started_at' => $day_ts + 60 ) );
		update_option( 'dnbfs_last_aggregated_date', $yesterday, false );
		dn_bfs_mark_dirty_date( $date );

		add_filter( 'query', 'dn_bfs_it_fail_daily_inserts' );

		try {
			dn_bfs_assert_same( false, dn_bfs_aggregate_day( $date, time() ), 'aggregate_day result' );
			dn_bfs_assert_same( '1', dn_bfs_it_daily( $date, 'total' )['sessions'], 'rolled back' );

			$result = dn_bfs_aggregate_run( time() );
		} finally {
			remove_filter( 'query', 'dn_bfs_it_fail_daily_inserts' );
		}

		dn_bfs_assert_same( false, $result['ok'] );
		dn_bfs_assert_same( 'write_failed', $result['reason'] );
		dn_bfs_assert_same( array(), $result['processed'] );
		dn_bfs_assert_same( '1', dn_bfs_it_daily( $date, 'total' )['sessions'], 'rolled back in run' );
		dn_bfs_assert_same( array( $date ), dn_bfs_get_dirty_dates() );

		dn_bfs_aggregate_run( time() );
		dn_bfs_assert_same( '2', dn_bfs_it_daily( $date, 'total' )['sessions'], 'retried' );
		dn_bfs_assert_same( array(), dn_bfs_get_dirty_dates() );
	}
);

function dn_bfs_it_break_daily_inserts( $query ) {
	return 0 === strpos( ltrim( $query ), 'INSERT INTO ' . dn_bfs_table( 'daily' ) ) ? str_replace( 'INSERT INTO ' . dn_bfs_table( 'daily' ), 'INSERT INTO ' . dn_bfs_table( 'daily_missing' ), $query ) : $query;
}

dn_bfs_it(
	'a failed catch-up day stops the run without advancing the watermark and records the error',
	function () {
		dn_bfs_it_reset_aggregator_state();

		$today = wp_date( 'Y-m-d', time() );
		$last  = dn_bfs_date_shift( $today, -4 );
		update_option( 'dnbfs_last_aggregated_date', $last, false );
		dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 3 ) ) );

		add_filter( 'query', 'dn_bfs_it_break_daily_inserts' );
		$suppressed = $GLOBALS['wpdb']->suppress_errors( true );

		try {
			$result = dn_bfs_aggregate_run( time() );
		} finally {
			$GLOBALS['wpdb']->suppress_errors( $suppressed );
			remove_filter( 'query', 'dn_bfs_it_break_daily_inserts' );
		}

		dn_bfs_assert_same( false, $result['ok'] );
		dn_bfs_assert_same( 'write_failed', $result['reason'] );
		dn_bfs_assert_same( array(), $result['processed'] );
		dn_bfs_assert_same( $last, get_option( 'dnbfs_last_aggregated_date' ) );
		dn_bfs_assert_true( false === get_option( 'dnbfs_aggregate_lock' ), 'lock released' );

		$error = get_option( 'dnbfs_aggregate_last_error' );
		dn_bfs_assert_true( is_array( $error ), 'error stored' );
		dn_bfs_assert_same( dn_bfs_date_shift( $last, 1 ), $error['date'] );
		dn_bfs_assert_true( false !== strpos( $error['message'], 'daily_missing' ), 'database error recorded: ' . $error['message'] );
		dn_bfs_assert_true( $error['time'] > 0, 'error time' );

		$next = dn_bfs_aggregate_run( time() );
		dn_bfs_assert_true( $next['ok'], 'recovered' );
		dn_bfs_assert_same( 3, count( $next['processed'] ) );
		dn_bfs_assert_same( dn_bfs_date_shift( $today, -1 ), get_option( 'dnbfs_last_aggregated_date' ) );
		dn_bfs_assert_true( false === get_option( 'dnbfs_aggregate_last_error' ), 'error cleared' );
	}
);

dn_bfs_it(
	'trashed orders are not sales and trash, untrash, delete and edits flag the order date dirty',
	function () {
		dn_bfs_it_reset_aggregator_state();
		$day_ts  = dn_bfs_it_day_noon( 2 );
		$date    = wp_date( 'Y-m-d', $day_ts );
		$session = dn_bfs_it_seed_session( array( 'started_at' => $day_ts ) );

		$product = (int) wc_get_products( array( 'limit' => 1, 'return' => 'ids' ) )[0];
		$order   = wc_create_order();
		$order->add_product( wc_get_product( $product ), 1 );
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->save();
		$order_id = $order->get_id();
		dn_bfs_it_seed_event( $session, 'order', $day_ts, array( 'order_id' => $order_id ) );

		list( $start, $end ) = dn_bfs_day_bounds( $date );
		dn_bfs_assert_same( 1, dn_bfs_raw_order_rows( $start, $end, 'total', array() )['']['orders'], 'counted before trash' );

		dn_bfs_it_clear_dirty_dates();
		$order->set_customer_note( 'edited' );
		$order->save();
		dn_bfs_assert_same( array( $date ), dn_bfs_get_dirty_dates(), 'edit' );

		dn_bfs_it_clear_dirty_dates();
		wc_get_order( $order_id )->delete( false );
		dn_bfs_assert_same( array( $date ), dn_bfs_get_dirty_dates(), 'trash' );

		dn_bfs_raw_get_order( 0, true );
		$trashed = dn_bfs_raw_order_rows( $start, $end, 'total', array() )[''];
		dn_bfs_assert_same( 'trash', wc_get_order( $order_id )->get_status() );
		dn_bfs_assert_same( 0, $trashed['orders'], 'trashed orders' );
		dn_bfs_assert_same( 0.0, $trashed['revenue'], 'trashed revenue' );
		dn_bfs_assert_same( 0.0, $trashed['paid'], 'trashed paid' );

		dn_bfs_it_clear_dirty_dates();
		wc_get_order( $order_id )->untrash();
		dn_bfs_assert_same( array( $date ), dn_bfs_get_dirty_dates(), 'untrash' );

		dn_bfs_it_clear_dirty_dates();
		wc_get_order( $order_id )->delete( true );
		dn_bfs_assert_same( array( $date ), dn_bfs_get_dirty_dates(), 'delete' );

		dn_bfs_raw_get_order( 0, true );
		dn_bfs_assert_same( array(), dn_bfs_raw_order_rows( $start, $end, 'total', array() ), 'deleted orders' );
	}
);
