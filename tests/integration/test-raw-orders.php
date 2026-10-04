<?php

require_once __DIR__ . '/seed.php';

function dn_bfs_it_order( $product_id, $qty, $status, $tip = 0.0 ) {
	$order = wc_create_order();
	$order->add_product( wc_get_product( $product_id ), $qty );

	if ( $tip > 0 ) {
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Tip' );
		$fee->set_total( $tip );
		$order->add_item( $fee );
	}

	$order->calculate_totals();
	$order->set_status( $status );
	$order->save();

	return $order;
}

function dn_bfs_it_first_product() {
	return (int) wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' ) )[0];
}

dn_bfs_it(
	'order rows use current WooCommerce status for sales, paid and balance',
	function () {
		dn_bfs_raw_get_order( 0, true );

		$product = dn_bfs_it_first_product();
		$price   = (float) wc_get_product( $product )->get_price();
		$day     = dn_bfs_it_day_noon( 1 );
		$session = dn_bfs_it_seed_session( array( 'started_at' => $day, 'channel' => 'paid', 'utm_campaign' => 'sale-10', 'browser' => 'Safari' ) );

		$processing = dn_bfs_it_order( $product, 2, 'processing', 3.0 );
		$pending    = dn_bfs_it_order( $product, 1, 'pending' );
		$cancelled  = dn_bfs_it_order( $product, 5, 'cancelled' );

		dn_bfs_it_seed_event( $session, 'order', $day, array( 'order_id' => $processing->get_id() ) );
		dn_bfs_it_seed_event( $session, 'order', $day + 1, array( 'order_id' => $pending->get_id() ) );
		dn_bfs_it_seed_event( $session, 'order', $day + 2, array( 'order_id' => $cancelled->get_id() ) );

		wc_create_refund( array( 'order_id' => $processing->get_id(), 'amount' => 5 ) );
		dn_bfs_raw_get_order( 0, true );

		list( $start, $end ) = dn_bfs_it_day_range( 1 );
		$total = dn_bfs_raw_order_rows( $start, $end, 'total', array() )[''];

		dn_bfs_assert_same( 2, $total['orders'] );
		dn_bfs_assert_same( 3, $total['items'] );
		dn_bfs_assert_same( round( $price * 3 + 3.0 - 5, 4 ), $total['revenue'] );
		dn_bfs_assert_same( 3.0, $total['tips'] );
		dn_bfs_assert_same( round( (float) $processing->get_total(), 4 ), $total['paid'] );
		dn_bfs_assert_same( round( (float) $pending->get_total(), 4 ), $total['balance'] );

		$browsers = dn_bfs_raw_order_rows( $start, $end, 'browser', array() );
		dn_bfs_assert_same( 2, $browsers['Safari']['orders'] );
	}
);

dn_bfs_it(
	'fallback orders without a session group by their own attribution and spam sessions still count',
	function () {
		dn_bfs_raw_get_order( 0, true );

		$product  = dn_bfs_it_first_product();
		$day      = dn_bfs_it_day_noon( 1 );
		$spam     = dn_bfs_it_seed_session( array( 'started_at' => $day, 'is_spam' => 1, 'utm_campaign' => 'spam-camp' ) );
		$fallback = array(
			'id'           => 0,
			'visitor_uid'  => '',
			'channel'      => 'paid',
			'utm_source'   => 'google',
			'utm_medium'   => 'cpc',
			'utm_campaign' => 'spring',
			'country'      => 'US',
			'device'       => '',
		);

		$a = dn_bfs_it_order( $product, 1, 'completed' );
		$b = dn_bfs_it_order( $product, 1, 'completed' );
		dn_bfs_it_seed_event( $fallback, 'order', $day, array( 'order_id' => $a->get_id() ) );
		dn_bfs_it_seed_event( $spam, 'order', $day, array( 'order_id' => $b->get_id() ) );

		list( $start, $end ) = dn_bfs_it_day_range( 1 );
		$campaigns = dn_bfs_raw_order_rows( $start, $end, 'campaign', array() );

		dn_bfs_assert_same( 1, $campaigns['spring']['orders'] );
		dn_bfs_assert_same( 1, $campaigns['spam-camp']['orders'] );
		dn_bfs_assert_same( 1, dn_bfs_raw_order_rows( $start, $end, 'total', array( 'campaign' => 'spring' ) )['']['orders'] );
		dn_bfs_assert_same( 2, dn_bfs_raw_order_rows( $start, $end, 'city', array() )['']['orders'] );
	}
);

dn_bfs_it(
	'product order rows count each order once per product with line totals',
	function () {
		dn_bfs_raw_get_order( 0, true );

		$product = dn_bfs_it_first_product();
		$day     = dn_bfs_it_day_noon( 1 );
		$session = dn_bfs_it_seed_session( array( 'started_at' => $day ) );
		$order   = dn_bfs_it_order( $product, 3, 'processing' );

		dn_bfs_it_seed_event( $session, 'order', $day, array( 'order_id' => $order->get_id() ) );

		list( $start, $end ) = dn_bfs_it_day_range( 1 );
		$rows = dn_bfs_raw_order_rows( $start, $end, 'product', array() );
		$row  = $rows[ (string) $product ];

		dn_bfs_assert_same( 1, $row['orders'] );
		dn_bfs_assert_same( 3, $row['items'] );
		dn_bfs_assert_same( round( (float) $order->get_subtotal(), 4 ), $row['revenue'] );
		dn_bfs_assert_same( array(), dn_bfs_raw_order_rows( $start, $end, 'page', array() ) );
	}
);

dn_bfs_it(
	'product revenue and items net out item refunds',
	function () {
		dn_bfs_raw_get_order( 0, true );

		$product = dn_bfs_it_first_product();
		$price   = (float) wc_get_product( $product )->get_price();
		$day     = dn_bfs_it_day_noon( 1 );
		$session = dn_bfs_it_seed_session( array( 'started_at' => $day ) );
		$order   = dn_bfs_it_order( $product, 3, 'processing' );
		$item_id = current( $order->get_items() )->get_id();

		dn_bfs_it_seed_event( $session, 'order', $day, array( 'order_id' => $order->get_id() ) );
		wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => $price,
				'line_items' => array( $item_id => array( 'qty' => 1, 'refund_total' => $price ) ),
			)
		);
		dn_bfs_raw_get_order( 0, true );

		list( $start, $end ) = dn_bfs_it_day_range( 1 );
		$row = dn_bfs_raw_order_rows( $start, $end, 'product', array() )[ (string) $product ];

		dn_bfs_assert_same( 2, $row['items'] );
		dn_bfs_assert_same( round( $price * 2, 4 ), $row['revenue'] );
	}
);

dn_bfs_it(
	'raw rows merge traffic, events and orders',
	function () {
		dn_bfs_raw_get_order( 0, true );

		$product = dn_bfs_it_first_product();
		$day     = dn_bfs_it_day_noon( 1 );
		$session = dn_bfs_it_seed_session( array( 'started_at' => $day, 'channel' => 'social' ) );
		dn_bfs_it_seed_pageview( $session, '/', $day );
		dn_bfs_it_seed_event( $session, 'add_to_cart', $day, array( 'product_id' => $product, 'qty' => 1 ) );
		$order = dn_bfs_it_order( $product, 1, 'completed' );
		dn_bfs_it_seed_event( $session, 'order', $day, array( 'order_id' => $order->get_id() ) );

		list( $start, $end ) = dn_bfs_it_day_range( 1 );
		$social = dn_bfs_raw_rows( $start, $end, 'channel', array() )['social'];

		dn_bfs_assert_same( 1, $social['sessions'] );
		dn_bfs_assert_same( 1, $social['pageviews'] );
		dn_bfs_assert_same( 1, $social['atc'] );
		dn_bfs_assert_same( 1, $social['orders'] );
		dn_bfs_assert_same( array(), dn_bfs_raw_rows( $start, $end, 'nope', array() ) );
	}
);

dn_bfs_it(
	'multi-dimension order rows and the aggregated day match per-dimension order rows',
	function () {
		dn_bfs_raw_get_order( 0, true );
		delete_option( 'dnbfs_last_aggregated_date' );

		$product = dn_bfs_it_first_product();
		$day     = dn_bfs_it_day_noon( 1 );
		$date    = wp_date( 'Y-m-d', $day );
		$paid    = dn_bfs_it_seed_session( array( 'started_at' => $day, 'channel' => 'paid', 'browser' => 'Safari' ) );
		$direct  = dn_bfs_it_seed_session( array( 'started_at' => $day, 'channel' => 'direct', 'browser' => 'Chrome' ) );

		$first  = dn_bfs_it_order( $product, 2, 'processing', 3.0 );
		$second = dn_bfs_it_order( $product, 1, 'pending' );
		$third  = dn_bfs_it_order( $product, 4, 'completed' );
		wc_create_refund( array( 'order_id' => $third->get_id(), 'amount' => 2 ) );

		dn_bfs_it_seed_event( $paid, 'order', $day, array( 'order_id' => $first->get_id() ) );
		dn_bfs_it_seed_event( $paid, 'order', $day + 1, array( 'order_id' => $second->get_id() ) );
		dn_bfs_it_seed_event( $direct, 'order', $day + 2, array( 'order_id' => $third->get_id() ) );
		dn_bfs_raw_get_order( 0, true );

		list( $start, $end ) = dn_bfs_it_day_range( 1 );
		$dimensions          = array( 'total', 'channel', 'browser', 'product' );
		$multi               = dn_bfs_raw_order_rows_multi( $start, $end, $dimensions );

		dn_bfs_assert_same( 2, count( $multi['channel'] ) );

		foreach ( $dimensions as $dimension ) {
			$expected = dn_bfs_raw_order_rows( $start, $end, $dimension, array() );
			dn_bfs_assert_same( $expected, $multi[ $dimension ], $dimension );
		}

		dn_bfs_assert_same( 'full', dn_bfs_aggregate_day( $date, time() ) );

		foreach ( $dimensions as $dimension ) {
			foreach ( dn_bfs_raw_order_rows( $start, $end, $dimension, array() ) as $value => $metrics ) {
				$row = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( 'SELECT * FROM ' . dn_bfs_table( 'daily' ) . ' WHERE date = %s AND dimension = %s AND dim_hash = %s', $date, $dimension, md5( (string) $value ) ), ARRAY_A );

				foreach ( dn_bfs_order_columns() as $column ) {
					dn_bfs_assert_same( $metrics[ $column ], dn_bfs_normalize_metrics( $row )[ $column ], $dimension . '/' . $value . '/' . $column );
				}
			}
		}
	}
);
