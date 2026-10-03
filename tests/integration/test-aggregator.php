<?php

require_once __DIR__ . '/seed.php';

function dn_bfs_it_daily( $date, $dimension, $value = '' ) {
	global $wpdb;

	return $wpdb->get_row(
		$wpdb->prepare( 'SELECT * FROM ' . dn_bfs_table( 'daily' ) . ' WHERE date = %s AND dimension = %s AND dim_hash = %s', $date, $dimension, md5( $value ) ),
		ARRAY_A
	);
}

function dn_bfs_it_reset_aggregator_state() {
	delete_option( 'dnbfs_last_aggregated_date' );
	delete_option( 'dnbfs_dirty_dates' );
	delete_option( 'dnbfs_aggregate_lock' );
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
		dn_bfs_assert_true( isset( get_option( 'dnbfs_dirty_dates' )[ $date ] ), 'order date dirty' );

		delete_option( 'dnbfs_dirty_dates' );
		dn_bfs_store_mark_spam( (int) $session['id'] );
		dn_bfs_assert_true( isset( get_option( 'dnbfs_dirty_dates' )[ $date ] ), 'spam date dirty' );

		dn_bfs_mark_dirty_date( wp_date( 'Y-m-d', time() ) );
		dn_bfs_assert_true( ! isset( get_option( 'dnbfs_dirty_dates' )[ wp_date( 'Y-m-d', time() ) ] ), 'today never dirty' );
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
		update_option( 'dnbfs_dirty_dates', array( $dirty => true ), false );
		$again = dn_bfs_aggregate_run( time() );

		dn_bfs_assert_same( array( $dirty ), $again['processed'] );
		dn_bfs_assert_same( array(), get_option( 'dnbfs_dirty_dates' ) );
	}
);

dn_bfs_it(
	'aggregate run respects the lock and an empty install starts at yesterday',
	function () {
		dn_bfs_it_reset_aggregator_state();

		update_option( 'dnbfs_aggregate_lock', time() + 300, false );
		dn_bfs_assert_same( 'locked', dn_bfs_aggregate_run( time() )['reason'] );
		delete_option( 'dnbfs_aggregate_lock' );

		$result = dn_bfs_aggregate_run( time() );
		dn_bfs_assert_same( array(), $result['processed'] );
		dn_bfs_assert_same( dn_bfs_date_shift( wp_date( 'Y-m-d', time() ), -1 ), get_option( 'dnbfs_last_aggregated_date' ) );
		dn_bfs_assert_true( false === get_option( 'dnbfs_aggregate_lock' ), 'lock released' );
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
