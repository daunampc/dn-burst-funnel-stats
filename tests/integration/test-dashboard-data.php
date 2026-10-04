<?php

require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/admin-helpers.php';

function dn_bfs_it_dash_order( $product_id, $qty, $status ) {
	$order = wc_create_order();
	$order->add_product( wc_get_product( $product_id ), $qty );
	$order->calculate_totals();
	$order->set_status( $status );
	$order->save();

	return $order;
}

function dn_bfs_it_seed_dashboard_day() {
	$now     = dn_bfs_it_now();
	$product = (int) wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' ) )[0];
	$session = dn_bfs_it_seed_session( array( 'started_at' => $now - 120, 'utm_campaign' => 'sale-10', 'channel' => 'paid', 'pageviews' => 2, 'is_bounce' => 0, 'duration' => 65 ) );

	dn_bfs_it_seed_pageview( $session, '/', $now - 120 );
	dn_bfs_it_seed_pageview( $session, '/product/a/', $now - 100 );
	dn_bfs_it_seed_event( $session, 'product_view', $now - 100, array( 'product_id' => $product ) );
	dn_bfs_it_seed_event( $session, 'add_to_cart', $now - 90, array( 'product_id' => $product, 'qty' => 1 ) );
	dn_bfs_it_seed_event( $session, 'cart', $now - 90, array( 'product_id' => $product, 'qty' => 1 ) );
	dn_bfs_it_seed_event( $session, 'checkout_start', $now - 80 );

	$order = dn_bfs_it_dash_order( $product, 2, 'processing' );
	dn_bfs_it_seed_event( $session, 'order', $now - 60, array( 'order_id' => $order->get_id() ) );
	dn_bfs_raw_get_order( 0, true );

	return $order;
}

dn_bfs_it(
	'user cards default to every card, save order and validate keys',
	function () {
		$user = dn_bfs_it_login_admin();
		delete_user_meta( $user, 'dnbfs_cards' );

		dn_bfs_assert_same( dn_bfs_dashboard_card_keys(), dn_bfs_get_user_cards( $user ) );
		dn_bfs_assert_same( array( 'orders_aov', 'visitors' ), dn_bfs_save_user_cards( $user, array( 'orders_aov', 'visitors', 'orders_aov' ) ) );
		dn_bfs_assert_same( array(), dn_bfs_save_user_cards( $user, array() ) );
		dn_bfs_assert_same( 'invalid_card', dn_bfs_save_user_cards( $user, array( 'nope' ) )->get_error_code() );
		dn_bfs_assert_same( dn_bfs_dashboard_card_keys(), dn_bfs_save_user_cards( $user, null ) );
	}
);

dn_bfs_it_today(
	'cards are built from the summary with comparison and help text',
	function () {
		$order   = dn_bfs_it_seed_dashboard_day();
		$summary = dn_bfs_report_summary( dn_bfs_calculate_date_range( 'today', 'previous_period' ) );
		$cards   = dn_bfs_dashboard_cards( $summary );

		dn_bfs_assert_same( dn_bfs_dashboard_card_keys(), array_keys( $cards ) );
		dn_bfs_assert_same( '1', $cards['visitors']['main'] );
		dn_bfs_assert_same( '0', $cards['visitors']['compare'] );
		dn_bfs_assert_same( '+100.0%', $cards['visitors']['change'] );
		dn_bfs_assert_true( false !== strpos( $cards['atc']['secondary'], '1' ), 'cart count shown' );
		dn_bfs_assert_same( '1m 05s', $cards['avg_duration']['main'] );
		dn_bfs_assert_same( '1', $cards['orders_aov']['main'] );
		dn_bfs_assert_same( wp_strip_all_tags( wc_price( $summary['current']['revenue'] ) ), wp_strip_all_tags( $cards['sales_tip']['main'] ) );
		dn_bfs_assert_true( $summary['current']['revenue'] > 0 && abs( $summary['current']['revenue'] - (float) $order->get_total() ) < 0.01, 'revenue equals the order total' );
		dn_bfs_assert_true( '' !== $cards['paid_balance']['help'], 'help text' );

		$none = dn_bfs_dashboard_cards( dn_bfs_report_summary( dn_bfs_calculate_date_range( 'today', 'none' ) ) );
		dn_bfs_assert_same( '', $none['visitors']['compare'] );
		dn_bfs_assert_same( '', $none['visitors']['change'] );
	}
);

dn_bfs_it_today(
	'chart payloads follow the original chart format',
	function () {
		dn_bfs_it_seed_dashboard_day();
		$charts = dn_bfs_dashboard_charts( dn_bfs_calculate_date_range( 'today', 'none' ), array() );

		dn_bfs_assert_same( 1, count( $charts['sales']['labels'] ) );
		dn_bfs_assert_same( array( 'left', 'right' ), array_column( $charts['sales']['series'], 'axis' ) );
		dn_bfs_assert_same( array( 1, 1, 1, 1, 1 ), $charts['funnel']['values'] );
		dn_bfs_assert_same( 5, count( $charts['funnel']['labels'] ) );
		dn_bfs_assert_same( array( 'sale-10' ), $charts['top']['labels'] );
		dn_bfs_assert_same( 'percent', $charts['conversion']['format'] );
		dn_bfs_assert_same( false, $charts['estimated'] );
	}
);

dn_bfs_it(
	'formatting helpers',
	function () {
		dn_bfs_assert_same( '2m 05s', dn_bfs_dash_duration( 125 ) );
		dn_bfs_assert_same( '45s', dn_bfs_dash_duration( 45 ) );
		dn_bfs_assert_same( '0s', dn_bfs_dash_duration( -5 ) );
		dn_bfs_assert_same( '', dn_bfs_dash_change( array( 'previous' => null, 'change' => array() ), 'visitors' ) );
		dn_bfs_assert_same( '-25.0%', dn_bfs_dash_change( array( 'previous' => array(), 'change' => array( 'visitors' => -25.0 ) ), 'visitors' ) );
	}
);

dn_bfs_it_today(
	'tip, balance and refund-net card values follow the money contract',
	function () {
		dn_bfs_raw_get_order( 0, true );

		$now     = dn_bfs_it_now();
		$product = (int) wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' ) )[0];
		$price   = (float) wc_get_product( $product )->get_price();
		$session = dn_bfs_it_seed_session( array( 'started_at' => $now - 120 ) );

		$paid = wc_create_order();
		$paid->add_product( wc_get_product( $product ), 2 );
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Tip' );
		$fee->set_total( 3.0 );
		$paid->add_item( $fee );
		$paid->calculate_totals();
		$paid->set_status( 'processing' );
		$paid->save();

		$pending = wc_create_order();
		$pending->add_product( wc_get_product( $product ), 1 );
		$pending->calculate_totals();
		$pending->set_status( 'pending' );
		$pending->save();

		dn_bfs_it_seed_event( $session, 'order', $now - 60, array( 'order_id' => $paid->get_id() ) );
		dn_bfs_it_seed_event( $session, 'order', $now - 50, array( 'order_id' => $pending->get_id() ) );
		wc_create_refund( array( 'order_id' => $paid->get_id(), 'amount' => 5 ) );
		dn_bfs_raw_get_order( 0, true );

		$cards = dn_bfs_dashboard_cards( dn_bfs_report_summary( dn_bfs_calculate_date_range( 'today', 'none' ) ) );
		$plain = function ( $money ) {
			return wp_strip_all_tags( wc_price( $money ) );
		};

		$sales = $price * 3 + 3.0 - 5;
		$paidv = (float) $paid->get_total() - 5;

		dn_bfs_assert_same( $plain( $sales ), wp_strip_all_tags( $cards['sales_tip']['main'] ), 'sales net of refund, pending included' );
		dn_bfs_assert_same( 'Tip: ' . $plain( 3.0 ), wp_strip_all_tags( $cards['sales_tip']['secondary'] ), 'tip is gross' );
		dn_bfs_assert_same( $plain( $paidv ) . ' / ' . $plain( (float) $pending->get_total() ), wp_strip_all_tags( $cards['paid_balance']['main'] ), 'paid net of refund / balance' );
	}
);

dn_bfs_it_today(
	'refunding the tip fee line keeps tips gross and lowers sales',
	function () {
		dn_bfs_raw_get_order( 0, true );

		$now     = dn_bfs_it_now();
		$product = (int) wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' ) )[0];
		$price   = (float) wc_get_product( $product )->get_price();
		$session = dn_bfs_it_seed_session( array( 'started_at' => $now - 120 ) );

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product ), 1 );
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Tip' );
		$fee->set_total( 3.0 );
		$order->add_item( $fee );
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->save();

		$fee_id = (int) array_keys( $order->get_items( 'fee' ) )[0];
		dn_bfs_it_seed_event( $session, 'order', $now - 60, array( 'order_id' => $order->get_id() ) );
		wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => 3.0,
				'line_items' => array(
					$fee_id => array(
						'qty'          => 0,
						'refund_total' => 3.0,
						'refund_tax'   => array(),
					),
				),
			)
		);
		dn_bfs_raw_get_order( 0, true );

		$summary = dn_bfs_report_summary( dn_bfs_calculate_date_range( 'today', 'none' ) );

		dn_bfs_assert_same( 3.0, (float) wc_get_order( $order->get_id() )->get_total_refunded(), 'the refund hit the order' );
		dn_bfs_assert_same( 3.0, (float) $summary['current']['tips'], 'tip stays gross after its own fee line is refunded' );
		dn_bfs_assert_same( round( $price, 2 ), round( (float) $summary['current']['revenue'], 2 ), 'sales are net of the refunded tip' );
	}
);
