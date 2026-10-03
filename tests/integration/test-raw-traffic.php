<?php

require_once __DIR__ . '/seed.php';

function dn_bfs_it_seed_traffic_day( $day_ts ) {
	$paid = dn_bfs_it_seed_session(
		array(
			'started_at'   => $day_ts,
			'channel'      => 'paid',
			'utm_source'   => 'facebook',
			'utm_medium'   => 'cpc',
			'utm_campaign' => 'sale-10',
			'device'       => 'mobile',
			'pageviews'    => 3,
			'is_bounce'    => 0,
			'duration'     => 90,
			'entry_path'   => '/product/a/',
			'exit_path'    => '/checkout/',
		)
	);
	$direct = dn_bfs_it_seed_session( array( 'started_at' => $day_ts + 60, 'is_new_visitor' => 0 ) );
	$spam   = dn_bfs_it_seed_session( array( 'started_at' => $day_ts + 120, 'is_spam' => 1, 'pageviews' => 50 ) );
	$silent = dn_bfs_it_seed_session( array( 'started_at' => $day_ts + 180, 'pageviews' => 0 ) );

	dn_bfs_it_seed_pageview( $paid, '/product/a/', $day_ts, array( 'time_on_page' => 40 ) );
	dn_bfs_it_seed_pageview( $paid, '/cart/', $day_ts + 30, array( 'time_on_page' => 20 ) );
	dn_bfs_it_seed_pageview( $paid, '/checkout/', $day_ts + 60, array( 'time_on_page' => 30 ) );
	dn_bfs_it_seed_pageview( $direct, '/', $day_ts + 60 );
	dn_bfs_it_seed_pageview( $spam, '/', $day_ts + 120 );

	dn_bfs_it_seed_event( $paid, 'product_view', $day_ts, array( 'product_id' => 101 ) );
	dn_bfs_it_seed_event( $direct, 'product_view', $day_ts + 60, array( 'product_id' => 101 ) );
	dn_bfs_it_seed_event( $paid, 'add_to_cart', $day_ts + 20, array( 'product_id' => 101, 'qty' => 1 ) );
	dn_bfs_it_seed_event( $paid, 'cart', $day_ts + 20, array( 'product_id' => 101, 'qty' => 1 ) );
	dn_bfs_it_seed_event( $paid, 'checkout_start', $day_ts + 60 );
	dn_bfs_it_seed_event( $paid, 'checkout_start', $day_ts + 61 );
	dn_bfs_it_seed_event( $spam, 'product_view', $day_ts + 120, array( 'product_id' => 101 ) );
	dn_bfs_it_seed_event( $silent, 'add_to_cart', $day_ts + 180, array( 'product_id' => 102, 'qty' => 1 ) );
	dn_bfs_it_seed_event( $silent, 'cart', $day_ts + 180, array( 'product_id' => 102, 'qty' => 1 ) );
}

dn_bfs_it(
	'raw traffic totals exclude spam and zero-pageview sessions',
	function () {
		dn_bfs_it_seed_traffic_day( dn_bfs_it_day_noon( 1 ) );
		list( $start, $end ) = dn_bfs_it_day_range( 1 );

		$total = dn_bfs_raw_traffic_rows( $start, $end, 'total', array() )[''];

		dn_bfs_assert_same( 2, $total['sessions'] );
		dn_bfs_assert_same( 2, $total['visitors'] );
		dn_bfs_assert_same( 1, $total['new_visitors'] );
		dn_bfs_assert_same( 4, $total['pageviews'] );
		dn_bfs_assert_same( 1, $total['bounces'] );
		dn_bfs_assert_same( 90, $total['duration_sum'] );
	}
);

dn_bfs_it(
	'raw traffic groups by session dimensions and respects filters',
	function () {
		dn_bfs_it_seed_traffic_day( dn_bfs_it_day_noon( 1 ) );
		list( $start, $end ) = dn_bfs_it_day_range( 1 );

		$channels = dn_bfs_raw_traffic_rows( $start, $end, 'channel', array() );
		dn_bfs_assert_same( 1, $channels['paid']['sessions'] );
		dn_bfs_assert_same( 3, $channels['paid']['pageviews'] );
		dn_bfs_assert_same( 1, $channels['direct']['sessions'] );

		$filtered = dn_bfs_raw_traffic_rows( $start, $end, 'total', array( 'campaign' => 'sale-10' ) )[''];
		dn_bfs_assert_same( 1, $filtered['sessions'] );

		$entry = dn_bfs_raw_traffic_rows( $start, $end, 'entry', array() );
		dn_bfs_assert_same( 1, $entry['/product/a/']['sessions'] );
	}
);

dn_bfs_it(
	'raw page rows count pageviews, visitors, sessions and time on page',
	function () {
		dn_bfs_it_seed_traffic_day( dn_bfs_it_day_noon( 1 ) );
		list( $start, $end ) = dn_bfs_it_day_range( 1 );

		$pages = dn_bfs_raw_traffic_rows( $start, $end, 'page', array() );

		dn_bfs_assert_same( 1, $pages['/cart/']['pageviews'] );
		dn_bfs_assert_same( 20, $pages['/cart/']['duration_sum'] );
		dn_bfs_assert_same( 1, $pages['/']['pageviews'] );
		dn_bfs_assert_true( ! isset( $pages['/']['bounces'] ) || 0 === $pages['/']['bounces'], 'no bounces on page rows' );
	}
);

dn_bfs_it(
	'raw event rows count views, add to cart, cart and distinct checkouts',
	function () {
		dn_bfs_it_seed_traffic_day( dn_bfs_it_day_noon( 1 ) );
		list( $start, $end ) = dn_bfs_it_day_range( 1 );

		$total = dn_bfs_raw_event_rows( $start, $end, 'total', array() )[''];
		dn_bfs_assert_same( 2, $total['product_views'] );
		dn_bfs_assert_same( 2, $total['atc'] );
		dn_bfs_assert_same( 2, $total['carts'] );
		dn_bfs_assert_same( 1, $total['checkouts'] );

		$products = dn_bfs_raw_event_rows( $start, $end, 'product', array() );
		dn_bfs_assert_same( 2, $products['101']['product_views'] );
		dn_bfs_assert_same( 1, $products['102']['atc'] );
		dn_bfs_assert_true( ! isset( $products['0'] ), 'checkout_start has no product row' );

		$paid = dn_bfs_raw_event_rows( $start, $end, 'total', array( 'channel' => 'paid' ) )[''];
		dn_bfs_assert_same( 1, $paid['product_views'] );
	}
);

dn_bfs_it(
	'distinct visitors across days counts a returning visitor once',
	function () {
		$first = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 2 ), 'visitor_uid' => md5( 'same' ) ) );
		dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 1 ), 'visitor_uid' => md5( 'same' ), 'is_new_visitor' => 0 ) );
		dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 1 ), 'visitor_uid' => md5( 'other' ) ) );
		unset( $first );

		list( $start ) = dn_bfs_it_day_range( 2 );
		list( , $end ) = dn_bfs_it_day_range( 1 );

		dn_bfs_assert_same( 2, dn_bfs_raw_distinct_visitors( $start, $end, array() ) );
		dn_bfs_assert_same( 2, dn_bfs_raw_distinct_visitors( $start, $end, array(), true ) );
		dn_bfs_assert_same( 2, dn_bfs_raw_distinct_visitors( $start, $end, array( 'country' => 'VN', 'device' => 'desktop' ) ) );
		dn_bfs_assert_same( 0, dn_bfs_raw_distinct_visitors( $start, $end, array( 'device' => 'mobile' ) ) );
	}
);
