<?php

require_once __DIR__ . '/seed.php';

add_filter( 'dn_bfs_report_cache_ttl', '__return_zero' );

function dn_bfs_it_range( $days_ago_start, $days_ago_end, $compare = 'previous_period' ) {
	$today = wp_date( 'Y-m-d', time() );
	list( $start ) = dn_bfs_day_bounds( dn_bfs_date_shift( $today, -1 * $days_ago_start ) );
	list( , $end ) = dn_bfs_day_bounds( dn_bfs_date_shift( $today, -1 * $days_ago_end ) );
	$days          = $days_ago_start - $days_ago_end + 1;

	return array(
		'current_start'  => $start,
		'current_end'    => $end - 1,
		'previous_start' => $start - $days * DAY_IN_SECONDS,
		'previous_end'   => $start - 1,
		'compare'        => $compare,
	);
}

function dn_bfs_it_seed_reporting_week() {
	delete_option( 'dnbfs_last_aggregated_date' );
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'dnbfs_dirty_' ) . '%' ) );

	foreach ( array( 3, 1 ) as $days_ago ) {
		$ts = dn_bfs_it_day_noon( $days_ago );
		$s  = dn_bfs_it_seed_session( array( 'started_at' => $ts, 'visitor_uid' => md5( 'loyal' ), 'is_new_visitor' => 3 === $days_ago ? 1 : 0, 'channel' => 'paid', 'device' => 'mobile' ) );
		dn_bfs_it_seed_pageview( $s, '/', $ts );
		dn_bfs_it_seed_event( $s, 'product_view', $ts, array( 'product_id' => 101 ) );
	}

	$today = dn_bfs_it_seed_session( array( 'started_at' => time() - 60, 'channel' => 'direct', 'device' => 'desktop' ) );
	dn_bfs_it_seed_pageview( $today, '/', time() - 60 );

	dn_bfs_aggregate_run( time() );
}

dn_bfs_it(
	'summary combines daily rows with today and counts visitors exactly within retention',
	function () {
		dn_bfs_it_seed_reporting_week();

		$summary = dn_bfs_report_summary( dn_bfs_it_range( 6, 0 ) );

		dn_bfs_assert_same( 3, $summary['current']['sessions'] );
		dn_bfs_assert_same( 3, $summary['current']['pageviews'] );
		dn_bfs_assert_same( 2, $summary['current']['visitors'] );
		dn_bfs_assert_same( 2, $summary['current']['product_views'] );
		dn_bfs_assert_same( false, $summary['estimated'] );
		dn_bfs_assert_same( 0, $summary['previous']['sessions'] );
		dn_bfs_assert_same( 100.0, $summary['change']['sessions'] );
	}
);

dn_bfs_it(
	'summary with one filter reads that dimension and none compare has no previous',
	function () {
		dn_bfs_it_seed_reporting_week();

		$summary = dn_bfs_report_summary( dn_bfs_it_range( 6, 0, 'none' ), array( 'channel' => 'paid' ) );

		dn_bfs_assert_same( 2, $summary['current']['sessions'] );
		dn_bfs_assert_same( 1, $summary['current']['visitors'] );
		dn_bfs_assert_same( null, $summary['previous'] );
	}
);

dn_bfs_it(
	'summary with two filters uses raw data and refuses ranges beyond retention',
	function () {
		dn_bfs_it_seed_reporting_week();

		$summary = dn_bfs_report_summary( dn_bfs_it_range( 6, 0, 'none' ), array( 'channel' => 'paid', 'device' => 'mobile' ) );
		dn_bfs_assert_same( 2, $summary['current']['sessions'] );

		$error = dn_bfs_report_summary( dn_bfs_it_range( 200, 0, 'none' ), array( 'channel' => 'paid', 'device' => 'mobile' ) );
		dn_bfs_assert_true( is_wp_error( $error ), 'error' );
		dn_bfs_assert_same( 'filter_out_of_retention', $error->get_error_code() );
	}
);

dn_bfs_it(
	'summary beyond retention without filters is estimated',
	function () {
		dn_bfs_it_seed_reporting_week();
		dn_bfs_it_settings( array( 'raw_retention_days' => 7 ) );

		$summary = dn_bfs_report_summary( dn_bfs_it_range( 30, 0, 'none' ) );

		dn_bfs_assert_same( true, $summary['estimated'] );
		dn_bfs_assert_same( 3, $summary['current']['visitors'] );
	}
);

dn_bfs_it(
	'timeseries returns one value per day including today',
	function () {
		dn_bfs_it_seed_reporting_week();

		$series = dn_bfs_report_timeseries( dn_bfs_it_range( 3, 0, 'none' ), array( 'sessions', 'bounce_rate' ) );

		dn_bfs_assert_same( 4, count( $series['labels'] ) );
		dn_bfs_assert_same( array( 1, 0, 1, 1 ), $series['series']['sessions'] );
		dn_bfs_assert_same( 4, count( $series['series']['bounce_rate'] ) );
	}
);

dn_bfs_it(
	'funnel lists the six steps in order',
	function () {
		dn_bfs_it_seed_reporting_week();

		$funnel = dn_bfs_report_funnel( dn_bfs_it_range( 6, 0, 'none' ) );

		dn_bfs_assert_same( array( 'visitors', 'product_views', 'atc', 'carts', 'checkouts', 'orders' ), array_column( $funnel, 'key' ) );
		dn_bfs_assert_same( 2, $funnel[0]['value'] );
		dn_bfs_assert_same( 2, $funnel[1]['value'] );
	}
);

function dn_bfs_it_seed_lagging_days( $days_ago_list ) {
	delete_option( 'dnbfs_last_aggregated_date' );
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'dnbfs_dirty_' ) . '%' ) );

	foreach ( $days_ago_list as $days_ago ) {
		$ts = 0 === $days_ago ? time() - 60 : dn_bfs_it_day_noon( $days_ago );
		$s  = dn_bfs_it_seed_session( array( 'started_at' => $ts, 'visitor_uid' => md5( 'lag' . $days_ago ) ) );
		dn_bfs_it_seed_pageview( $s, '/', $ts );
	}
}

dn_bfs_it(
	'reports read unaggregated past days from raw data when the aggregator lags',
	function () {
		dn_bfs_it_seed_lagging_days( array( 2, 1 ) );
		update_option( 'dnbfs_last_aggregated_date', dn_bfs_date_shift( wp_date( 'Y-m-d', time() ), -3 ), false );

		$summary = dn_bfs_report_summary( dn_bfs_it_range( 6, 0, 'none' ) );
		dn_bfs_assert_same( 2, $summary['current']['sessions'] );
		dn_bfs_assert_same( 2, $summary['current']['pageviews'] );
		dn_bfs_assert_same( 2, $summary['current']['visitors'] );
		dn_bfs_assert_same( false, $summary['estimated'] );

		$series = dn_bfs_report_timeseries( dn_bfs_it_range( 3, 0, 'none' ), array( 'sessions' ) );
		dn_bfs_assert_same( array( 0, 1, 1, 0 ), $series['series']['sessions'] );
	}
);

dn_bfs_it(
	'reports combine daily rows up to the watermark with raw data after it without double counting',
	function () {
		dn_bfs_it_seed_lagging_days( array( 2, 1, 0 ) );
		dn_bfs_aggregate_run( time() - DAY_IN_SECONDS );

		$two_days_ago = dn_bfs_date_shift( wp_date( 'Y-m-d', time() ), -2 );
		dn_bfs_assert_same( $two_days_ago, get_option( 'dnbfs_last_aggregated_date' ) );
		dn_bfs_assert_same( 1, dn_bfs_daily_totals( $two_days_ago, $two_days_ago, array() )['sessions'] );

		$summary = dn_bfs_report_summary( dn_bfs_it_range( 6, 0, 'none' ) );
		dn_bfs_assert_same( 3, $summary['current']['sessions'] );
		dn_bfs_assert_same( 3, $summary['current']['visitors'] );

		$series = dn_bfs_report_timeseries( dn_bfs_it_range( 3, 0, 'none' ), array( 'sessions' ) );
		dn_bfs_assert_same( array( 0, 1, 1, 1 ), $series['series']['sessions'] );
	}
);

dn_bfs_it(
	'a fully aggregated past range reads only daily rows',
	function () {
		dn_bfs_it_seed_lagging_days( array( 2, 1, 0 ) );
		dn_bfs_aggregate_run( time() );

		$range  = dn_bfs_it_range( 6, 2, 'none' );
		$period = dn_bfs_report_period( $range['current_start'], $range['current_end'], time() );
		dn_bfs_assert_same( false, $period['has_live'] );
		dn_bfs_assert_same( false, $period['incomplete'] );

		$summary = dn_bfs_report_summary( $range );
		dn_bfs_assert_same( 1, $summary['current']['sessions'] );
		dn_bfs_assert_same( 1, $summary['current']['pageviews'] );

		$series = dn_bfs_report_timeseries( $range, array( 'sessions' ) );
		dn_bfs_assert_same( array( 0, 0, 0, 0, 1 ), $series['series']['sessions'] );
	}
);
