<?php

require_once __DIR__ . '/seed.php';

function dn_bfs_it_breakdown_range( $days_back ) {
	$today         = wp_date( 'Y-m-d', dn_bfs_it_now() );
	list( $start ) = dn_bfs_day_bounds( dn_bfs_date_shift( $today, -1 * $days_back ) );
	list( , $end ) = dn_bfs_day_bounds( $today );

	return array(
		'current_start'  => $start,
		'current_end'    => $end - 1,
		'previous_start' => $start,
		'previous_end'   => $start,
		'compare'        => 'none',
	);
}

function dn_bfs_it_seed_breakdown() {
	delete_option( 'dnbfs_last_aggregated_date' );
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'dnbfs_dirty_' ) . '%' ) );

	$yesterday = dn_bfs_it_day_noon( 1 );

	foreach ( array( 'sale-10', 'sale-10', 'spring' ) as $i => $campaign ) {
		$s = dn_bfs_it_seed_session( array( 'started_at' => $yesterday + $i, 'utm_campaign' => $campaign, 'device' => 0 === $i ? 'mobile' : 'desktop' ) );
		dn_bfs_it_seed_pageview( $s, '/', $yesterday + $i );
		dn_bfs_it_seed_event( $s, 'product_view', $yesterday + $i, array( 'product_id' => 101 + $i ) );
	}

	$today = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_now() - 30, 'utm_campaign' => 'spring' ) );
	dn_bfs_it_seed_pageview( $today, '/sale/', dn_bfs_it_now() - 30 );

	dn_bfs_aggregate_run( dn_bfs_it_now() );
}

dn_bfs_it_today(
	'breakdown merges daily rows with today and sorts',
	function () {
		dn_bfs_it_seed_breakdown();

		$result = dn_bfs_report_breakdown( dn_bfs_it_breakdown_range( 3 ), 'campaign', array(), 'sessions', 'desc' );

		dn_bfs_assert_same( 2, $result['total'] );
		dn_bfs_assert_same( array( 'sale-10', 'spring' ), array_column( $result['rows'], 'dim_value' ) );
		dn_bfs_assert_same( 2, $result['rows'][0]['sessions'] );
		dn_bfs_assert_same( 2, $result['rows'][1]['sessions'] );
		dn_bfs_assert_same( true, $result['estimated'] );
	}
);

dn_bfs_it_today(
	'breakdown with a filter uses raw data, paginates and labels products',
	function () {
		dn_bfs_it_seed_breakdown();

		$filtered = dn_bfs_report_breakdown( dn_bfs_it_breakdown_range( 3 ), 'device', array( 'campaign' => 'sale-10' ) );
		dn_bfs_assert_same( array( 'desktop', 'mobile' ), array_column( dn_bfs_sort_report_rows( $filtered['rows'], 'sessions', 'asc' ), 'dim_value' ) );
		dn_bfs_assert_same( false, $filtered['estimated'] );
		dn_bfs_assert_same( 2, array_sum( array_column( $filtered['rows'], 'sessions' ) ) );

		$page = dn_bfs_report_breakdown( dn_bfs_it_breakdown_range( 3 ), 'product', array(), 'product_views', 'desc', 1, 1 );
		dn_bfs_assert_same( 3, $page['total'] );
		dn_bfs_assert_same( 1, count( $page['rows'] ) );

		$error = dn_bfs_report_breakdown( dn_bfs_it_breakdown_range( 3 ), 'nope' );
		dn_bfs_assert_same( 'invalid_dimension', $error->get_error_code() );
	}
);

dn_bfs_it_today(
	'breakdown and summary ignore sessions after the range end when nothing is aggregated',
	function () {
		update_option( 'dnbfs_last_aggregated_date', dn_bfs_date_shift( wp_date( 'Y-m-d', dn_bfs_it_now() ), -3 ) );

		$old = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 2 ), 'utm_campaign' => 'old' ) );
		dn_bfs_it_seed_pageview( $old, '/', dn_bfs_it_day_noon( 2 ) );
		$today = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_now() - 30, 'utm_campaign' => 'today' ) );
		dn_bfs_it_seed_pageview( $today, '/', dn_bfs_it_now() - 30 );

		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'dnbfs_dirty_' ) . '%' ) );

		$today_date    = wp_date( 'Y-m-d', dn_bfs_it_now() );
		list( $start ) = dn_bfs_day_bounds( dn_bfs_date_shift( $today_date, -3 ) );
		list( , $end ) = dn_bfs_day_bounds( dn_bfs_date_shift( $today_date, -1 ) );
		$range         = array(
			'current_start'  => $start,
			'current_end'    => $end - 1,
			'previous_start' => $start,
			'previous_end'   => $start,
			'compare'        => 'none',
		);

		$result = dn_bfs_report_breakdown( $range, 'campaign' );
		dn_bfs_assert_same( array( 'old' ), array_column( $result['rows'], 'dim_value' ) );

		$summary = dn_bfs_report_summary( $range );
		dn_bfs_assert_same( 1, $summary['current']['sessions'] );
	}
);

dn_bfs_it_today(
	'breakdown labels products by title and falls back to the id',
	function () {
		dn_bfs_it_seed_breakdown();

		$post_id = wp_insert_post( array( 'post_type' => 'product', 'post_title' => 'Label & Test', 'post_status' => 'publish' ) );
		$missing = 999999;

		try {
			foreach ( array( $post_id, $missing, $missing ) as $product_id ) {
				$s = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_now() - 20 ) );
				dn_bfs_it_seed_event( $s, 'product_view', dn_bfs_it_now() - 20, array( 'product_id' => $product_id ) );
			}

			$result = dn_bfs_report_breakdown( dn_bfs_it_breakdown_range( 3 ), 'product', array(), 'product_views', 'desc', 50 );
			$labels = array_column( $result['rows'], 'label', 'dim_value' );

			dn_bfs_assert_same( 'Label & Test', $labels[ (string) $post_id ] );
			dn_bfs_assert_same( '#' . $missing, $labels[ (string) $missing ] );

			// Labels are added after sorting and slicing: a one-row page labels the top row.
			$second = dn_bfs_report_breakdown( dn_bfs_it_breakdown_range( 3 ), 'product', array(), 'product_views', 'desc', 1, 0 );
			dn_bfs_assert_same( array( array( (string) $missing, '#' . $missing ) ), array_map( null, array_column( $second['rows'], 'dim_value' ), array_column( $second['rows'], 'label' ) ) );

			$deleted = dn_bfs_report_label_rows( 'product', array( array( 'dim_value' => '0' ) ) );
			dn_bfs_assert_same( '(deleted product)', $deleted[0]['label'] );
			dn_bfs_assert_same( 'Facebook', dn_bfs_report_label_rows( 'source', array( array( 'dim_value' => 'Facebook' ) ) )[0]['label'] );
		} finally {
			wp_delete_post( $post_id, true );
		}
	}
);

dn_bfs_it_today(
	'realtime counts active sessions and their current pages',
	function () {
		$now    = dn_bfs_it_now();
		$active = dn_bfs_it_seed_session( array( 'started_at' => $now - 600, 'last_activity' => $now - 30, 'channel' => 'paid' ) );
		dn_bfs_it_seed_pageview( $active, '/', $now - 600 );
		dn_bfs_it_seed_pageview( $active, '/cart/', $now - 40 );
		$other = dn_bfs_it_seed_session( array( 'started_at' => $now - 100, 'last_activity' => $now - 100 ) );
		dn_bfs_it_seed_pageview( $other, '/cart/', $now - 100 );
		dn_bfs_it_seed_session( array( 'started_at' => $now - 3600, 'last_activity' => $now - 1000 ) );
		dn_bfs_it_seed_session( array( 'started_at' => $now - 50, 'last_activity' => $now - 50, 'is_spam' => 1 ) );
		dn_bfs_it_seed_session( array( 'started_at' => $now - 20, 'last_activity' => $now - 20, 'pageviews' => 0 ) );

		$realtime = dn_bfs_report_realtime( $now );

		dn_bfs_assert_same( 2, $realtime['online'] );
		dn_bfs_assert_same( array( array( 'path' => '/cart/', 'visitors' => 2 ) ), $realtime['pages'] );
		dn_bfs_assert_same( 2, array_sum( array_column( $realtime['channels'], 'visitors' ) ) );
	}
);

dn_bfs_it_today(
	'dimension values group case-insensitively across daily rows, live rows and filters',
	function () {
		delete_option( 'dnbfs_last_aggregated_date' );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'dnbfs_dirty_' ) . '%' ) );

		foreach ( array( array( 2, 'Facebook' ), array( 1, 'facebook' ), array( 1, 'faceBOOK' ), array( 0, 'FACEBOOK' ) ) as $i => $seed ) {
			$ts = 0 === $seed[0] ? dn_bfs_it_now() - 30 : dn_bfs_it_day_noon( $seed[0] ) + $i;
			$s  = dn_bfs_it_seed_session( array( 'started_at' => $ts, 'utm_source' => $seed[1] ) );
			dn_bfs_it_seed_pageview( $s, '/', $ts );
		}

		dn_bfs_aggregate_run( dn_bfs_it_now() );
		dn_bfs_assert_same( dn_bfs_date_shift( wp_date( 'Y-m-d', dn_bfs_it_now() ), -1 ), get_option( 'dnbfs_last_aggregated_date' ) );

		foreach ( array( 'facebook', 'FACEBOOK' ) as $value ) {
			$summary = dn_bfs_report_summary( dn_bfs_it_breakdown_range( 3 ), array( 'source' => $value ) );
			dn_bfs_assert_same( 4, $summary['current']['sessions'], 'filter ' . $value );
		}

		$result = dn_bfs_report_breakdown( dn_bfs_it_breakdown_range( 3 ), 'source', array(), 'sessions', 'desc' );
		dn_bfs_assert_same( 1, $result['total'], 'one source row' );
		dn_bfs_assert_same( 4, $result['rows'][0]['sessions'] );
		dn_bfs_assert_same( 'facebook', strtolower( $result['rows'][0]['dim_value'] ) );

		$merged = dn_bfs_raw_merge_rows( array( 'Facebook' => dn_bfs_normalize_metrics( array( 'sessions' => 1 ) ) ), array( 'facebook' => dn_bfs_normalize_metrics( array( 'sessions' => 2 ) ) ) );
		dn_bfs_assert_same( array( 'Facebook' ), array_keys( $merged ) );
		dn_bfs_assert_same( 3, $merged['Facebook']['sessions'] );
	}
);

dn_bfs_it_today(
	'dimension values group accent-insensitively like the database collation',
	function () {
		delete_option( 'dnbfs_last_aggregated_date' );
		dn_bfs_it_clear_dirty_dates();

		foreach ( array( array( 2, 'khuyến mãi' ), array( 1, 'khuyen mai' ), array( 1, 'Khuyến Mãi' ), array( 0, 'KHUYẾN MÃI' ) ) as $i => $seed ) {
			$ts = 0 === $seed[0] ? dn_bfs_it_now() - 30 : dn_bfs_it_day_noon( $seed[0] ) + $i;
			$s  = dn_bfs_it_seed_session( array( 'started_at' => $ts, 'utm_campaign' => $seed[1] ) );
			dn_bfs_it_seed_pageview( $s, '/', $ts );
		}

		dn_bfs_aggregate_run( dn_bfs_it_now() );
		dn_bfs_assert_same( dn_bfs_date_shift( wp_date( 'Y-m-d', dn_bfs_it_now() ), -1 ), get_option( 'dnbfs_last_aggregated_date' ) );

		foreach ( array( 'khuyen mai', 'khuyến mãi', 'KHUYẾN MÃI' ) as $value ) {
			$summary = dn_bfs_report_summary( dn_bfs_it_breakdown_range( 3 ), array( 'campaign' => $value ) );
			dn_bfs_assert_same( 4, $summary['current']['sessions'], 'filter ' . $value );
		}

		$result = dn_bfs_report_breakdown( dn_bfs_it_breakdown_range( 3 ), 'campaign', array(), 'sessions', 'desc' );
		dn_bfs_assert_same( 1, $result['total'], 'one campaign row' );
		dn_bfs_assert_same( 4, $result['rows'][0]['sessions'] );

		$filtered = dn_bfs_report_breakdown( dn_bfs_it_breakdown_range( 3 ), 'device', array( 'campaign' => 'khuyen mai' ) );
		dn_bfs_assert_same( 4, array_sum( array_column( $filtered['rows'], 'sessions' ) ), 'raw engine groups by accent-insensitive filter' );
	}
);
