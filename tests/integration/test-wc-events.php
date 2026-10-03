<?php

function dn_bfs_it_products( $count = 2 ) {
	return wc_get_products(
		array(
			'limit'   => $count,
			'status'  => 'publish',
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'ids',
		)
	);
}

dn_bfs_it(
	'woocommerce add to cart records add_to_cart and cart once within 5 minutes',
	function () {
		list( $a, $b ) = dn_bfs_it_products();

		$_COOKIE['dnbfs_vid']    = dn_bfs_it_uid( 'visitor-1' );
		$_COOKIE['dnbfs_sid']    = dn_bfs_it_uid( 'session-1' );
		$_SERVER['HTTP_REFERER'] = home_url( '/product/x/?utm_campaign=sale-10&utm_source=facebook&utm_medium=cpc' );

		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $a, 1 );
		WC()->cart->add_to_cart( $a, 1 );
		WC()->cart->add_to_cart( $b, 2 );

		dn_bfs_assert_same( 2, dn_bfs_it_count( 'events', "type = 'add_to_cart'" ) );
		dn_bfs_assert_same( 2, dn_bfs_it_count( 'events', "type = 'cart'" ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'events', "type = 'add_to_cart' AND product_id = {$a} AND qty = 2" ) );

		$session = dn_bfs_store_get_session( dn_bfs_it_uid( 'session-1' ) );
		dn_bfs_assert_same( 'sale-10', $session['utm_campaign'] );
	}
);

dn_bfs_it(
	'add to cart without tracker cookies creates a visitor and session',
	function () {
		list( $a ) = dn_bfs_it_products( 1 );

		$result = dn_bfs_track_add_to_cart( $a, 1, 10.0, $a );

		dn_bfs_assert_true( $result['ok'], 'ok' );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'sessions' ) );
		dn_bfs_assert_true( (bool) preg_match( '/^[a-f0-9]{32}$/', $_COOKIE['dnbfs_vid'] ), 'cookie set' );
	}
);

dn_bfs_it(
	'bot add to cart is ignored and counted as blocked',
	function () {
		list( $a ) = dn_bfs_it_products( 1 );
		$_SERVER['HTTP_USER_AGENT'] = 'python-requests/2.31';

		dn_bfs_track_add_to_cart( $a, 1, 10.0, $a );

		dn_bfs_assert_same( 0, dn_bfs_it_count( 'events' ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'daily', "dimension = 'blocked' AND dim_value = 'bot'" ) );
	}
);

dn_bfs_it(
	'order is recorded once with session attribution and order meta',
	function () {
		list( $a ) = dn_bfs_it_products( 1 );

		$_COOKIE['dnbfs_vid']    = dn_bfs_it_uid( 'visitor-1' );
		$_COOKIE['dnbfs_sid']    = dn_bfs_it_uid( 'session-1' );
		$_SERVER['HTTP_REFERER'] = home_url( '/?utm_campaign=sale-10&utm_source=facebook&utm_medium=cpc' );

		$order = wc_create_order();
		$order->add_product( wc_get_product( $a ), 2 );
		$order->calculate_totals();
		$order->save();

		dn_bfs_track_order( $order );
		dn_bfs_track_order( $order );

		dn_bfs_assert_same( 1, dn_bfs_it_count( 'events', "type = 'order' AND order_id = " . $order->get_id() . " AND utm_campaign = 'sale-10'" ) );
		dn_bfs_assert_same( dn_bfs_it_uid( 'session-1' ), wc_get_order( $order->get_id() )->get_meta( '_dnbfs_session_uid' ) );
	}
);

dn_bfs_it(
	'order from an excluded role is not recorded',
	function () {
		list( $a ) = dn_bfs_it_products( 1 );

		$admin_id                    = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $admin_id, time() + HOUR_IN_SECONDS, 'logged_in' );

		$order = wc_create_order();
		$order->add_product( wc_get_product( $a ), 1 );
		$order->calculate_totals();
		$order->save();

		dn_bfs_track_order( $order );
		dn_bfs_assert_same( 0, dn_bfs_it_count( 'events', "type = 'order'" ) );
	}
);

dn_bfs_it(
	'force cart redirect overrides WooCommerce options',
	function () {
		dn_bfs_it_settings( array( 'force_cart_redirect' => 1 ) );
		dn_bfs_assert_same( 'yes', get_option( 'woocommerce_cart_redirect_after_add' ) );
		dn_bfs_assert_same( 'no', get_option( 'woocommerce_enable_ajax_add_to_cart' ) );

		dn_bfs_it_settings( array( 'force_cart_redirect' => 0 ) );
		update_option( 'woocommerce_cart_redirect_after_add', 'no' );
		dn_bfs_assert_same( 'no', get_option( 'woocommerce_cart_redirect_after_add' ) );
	}
);
