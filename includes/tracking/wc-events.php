<?php
/**
 * WooCommerce server-side events: add to cart (+ cart) and orders.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets a tracking cookie the way tracker.js does: path /, no domain, readable by JS.
 */
function dn_bfs_wc_set_cookie( $name, $value, $lifetime ) {
	if ( ! headers_sent() ) {
		setrawcookie(
			$name,
			rawurlencode( $value ),
			array(
				'expires'  => time() + $lifetime,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	$_COOKIE[ $name ] = $value;
}

function dn_bfs_wc_read_cookie_id( $name ) {
	$value = isset( $_COOKIE[ $name ] ) && is_string( $_COOKIE[ $name ] ) ? sanitize_key( wp_unslash( $_COOKIE[ $name ] ) ) : '';

	return preg_match( '/^[a-f0-9]{32}$/', $value ) ? $value : '';
}

function dn_bfs_wc_cookie_id( $name, $lifetime ) {
	$value = dn_bfs_wc_read_cookie_id( $name );

	if ( '' === $value ) {
		$value = bin2hex( random_bytes( 16 ) );
		dn_bfs_wc_set_cookie( $name, $value, $lifetime );
	}

	return $value;
}

/**
 * Page the visitor is on. Classic (non-AJAX) requests use the current URL so
 * `?add-to-cart=ID&utm_*` landings keep their attribution; AJAX and REST
 * requests fall back to the same-site referer.
 */
function dn_bfs_wc_request_location() {
	$referer   = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
	$ref_host  = dn_bfs_referrer_host( $referer );
	$site_host = dn_bfs_site_host();
	$is_ajax   = wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! empty( $_GET['wc-ajax'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$location  = array(
		'path'  => '/',
		'query' => '',
		'ref'   => '',
	);

	if ( ! $is_ajax ) {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$url = (string) $uri;
	} elseif ( '' !== $referer && $ref_host === $site_host ) {
		$url = $referer;
	} else {
		return $location;
	}

	$path              = (string) wp_parse_url( $url, PHP_URL_PATH );
	$location['path']  = '' === $path ? '/' : substr( $path, 0, 255 );
	$location['query'] = (string) wp_parse_url( $url, PHP_URL_QUERY );

	if ( ! $is_ajax && '' !== $ref_host && $ref_host !== $site_host ) {
		$location['ref'] = $referer;
	}

	return $location;
}

function dn_bfs_wc_session_hit() {
	$settings = dn_bfs_get_tracking_settings();
	$location = dn_bfs_wc_request_location();
	$timeout  = (int) $settings['session_timeout'] * MINUTE_IN_SECONDS;
	$is_new   = '' === dn_bfs_wc_read_cookie_id( DN_BFS_COOKIE_SESSION );
	$vid      = dn_bfs_wc_cookie_id( DN_BFS_COOKIE_VISITOR, (int) $settings['cookie_days'] * DAY_IN_SECONDS );
	$sid      = dn_bfs_wc_cookie_id( DN_BFS_COOKIE_SESSION, $timeout );

	if ( $is_new ) {
		// Same format as tracker.js (raw utm_campaign) so the next pageview keeps this session id.
		$params = array();
		parse_str( $location['query'], $params );
		$campaign = isset( $params['utm_campaign'] ) && is_scalar( $params['utm_campaign'] ) ? (string) $params['utm_campaign'] : '';
		dn_bfs_wc_set_cookie( DN_BFS_COOKIE_META, wp_date( 'Y-m-d', dn_bfs_now() ) . '~' . $campaign, $timeout );
	}

	return array(
		'vid'   => $vid,
		'sid'   => $sid,
		'path'  => $location['path'],
		'query' => $location['query'],
		'ref'   => $location['ref'],
	);
}

function dn_bfs_wc_context( $now ) {
	return dn_bfs_request_context( $now, dn_bfs_get_current_user_agent() );
}

function dn_bfs_track_add_to_cart( $product_id, $qty, $value, $check_product_id = 0 ) {
	$now    = dn_bfs_now();
	$ctx    = dn_bfs_wc_context( $now );
	$reason = dn_bfs_guard_check_visitor( $ctx );

	if ( '' !== $reason ) {
		dn_bfs_store_count_blocked( $reason, $now );
		return dn_bfs_store_result( false, $reason );
	}

	if ( ! dn_bfs_should_track_product( $check_product_id ? $check_product_id : $product_id ) ) {
		return dn_bfs_store_result( false, 'not_selected' );
	}

	$session = dn_bfs_store_ensure_session( dn_bfs_wc_session_hit(), $ctx );

	if ( ! $session['ok'] ) {
		dn_bfs_store_count_blocked( $session['reason'], $now );
		return $session;
	}

	$result = dn_bfs_store_track_add_to_cart( $session['session'], (int) $product_id, (int) $qty, (float) $value, $ctx );

	if ( ! $result['ok'] && 'duplicate' !== $result['reason'] ) {
		dn_bfs_store_count_blocked( $result['reason'], $now );
	}

	return $result;
}

function dn_bfs_wc_on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id ) {
	unset( $cart_item_key );

	$target  = $variation_id ? (int) $variation_id : (int) $product_id;
	$product = wc_get_product( $target );
	$price   = $product ? (float) wc_get_price_to_display( $product ) : 0.0;
	$qty     = max( 1, (int) $quantity );

	// Record the parent product so dedupe and the product_view join share one id.
	dn_bfs_track_add_to_cart( (int) $product_id, $qty, $price * $qty, (int) $product_id );
}
add_action( 'woocommerce_add_to_cart', 'dn_bfs_wc_on_add_to_cart', 10, 4 );

/**
 * Builds a session-shaped array from WooCommerce order attribution meta, for
 * orders without a tracked session. session_id is 0, country comes from the
 * billing address and device is ''.
 */
function dn_bfs_wc_order_fallback_session( $order, $visitor_uid ) {
	$utm = array(
		'source'     => strtolower( (string) $order->get_meta( '_wc_order_attribution_utm_source' ) ),
		'medium'     => strtolower( (string) $order->get_meta( '_wc_order_attribution_utm_medium' ) ),
		'campaign'   => (string) $order->get_meta( '_wc_order_attribution_utm_campaign' ),
		'paid_click' => false,
	);

	return array(
		'id'           => 0,
		'visitor_uid'  => $visitor_uid,
		'channel'      => dn_bfs_classify_channel( $utm, '', dn_bfs_site_host() ),
		'utm_source'   => substr( $utm['source'], 0, 191 ),
		'utm_medium'   => substr( $utm['medium'], 0, 191 ),
		'utm_campaign' => substr( $utm['campaign'], 0, 191 ),
		'country'      => substr( (string) $order->get_billing_country(), 0, 2 ),
		'device'       => '',
	);
}

/**
 * Records a placed order. Orders are server-confirmed revenue, so only the
 * site-owner exclusions apply (disabled, excluded role, excluded IP), not the
 * bot or empty user-agent filters.
 */
function dn_bfs_track_order( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return dn_bfs_store_result( false, 'invalid_order' );
	}

	$now    = dn_bfs_now();
	$ctx    = dn_bfs_wc_context( $now );
	$reason = dn_bfs_guard_check_visitor( $ctx );

	if ( '' !== $reason && ! in_array( $reason, array( 'bot', 'empty_ua' ), true ) ) {
		return dn_bfs_store_result( false, $reason );
	}

	if ( '' !== (string) $order->get_meta( '_dnbfs_visitor_uid' ) ) {
		return dn_bfs_store_result( false, 'duplicate' );
	}

	$vid      = dn_bfs_wc_read_cookie_id( DN_BFS_COOKIE_VISITOR );
	$sid      = dn_bfs_wc_read_cookie_id( DN_BFS_COOKIE_SESSION );
	$existing = '' !== $sid ? dn_bfs_store_get_session( $sid ) : null;

	if ( $existing && $existing['visitor_uid'] === $vid && '1' !== (string) $existing['is_spam'] ) {
		$session = $existing;
	} else {
		$session = dn_bfs_wc_order_fallback_session( $order, $vid );
		$sid     = '';
	}

	$inserted = dn_bfs_store_insert_event(
		$session,
		'order',
		array(
			'order_id' => $order->get_id(),
			'qty'      => (int) $order->get_item_count(),
			'value'    => (float) $order->get_total(),
		),
		$now
	);

	if ( $inserted <= 0 && ! dn_bfs_store_order_event_exists( $order->get_id() ) ) {
		return dn_bfs_store_result( false, 'db_error' );
	}

	$order->update_meta_data( '_dnbfs_session_uid', $sid );
	$order->update_meta_data( '_dnbfs_visitor_uid', $vid );
	$order->save_meta_data();

	return dn_bfs_store_result( $inserted > 0, $inserted > 0 ? '' : 'duplicate' );
}

function dn_bfs_wc_on_checkout_order_processed( $order_id, $posted_data = array(), $order = null ) {
	unset( $posted_data );
	dn_bfs_track_order( $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );
}
add_action( 'woocommerce_checkout_order_processed', 'dn_bfs_wc_on_checkout_order_processed', 10, 3 );

function dn_bfs_wc_on_store_api_order_processed( $order ) {
	dn_bfs_track_order( $order );
}
add_action( 'woocommerce_store_api_checkout_order_processed', 'dn_bfs_wc_on_store_api_order_processed', 10, 1 );

function dn_bfs_wc_force_cart_redirect( $value ) {
	$settings = dn_bfs_get_tracking_settings();

	return empty( $settings['force_cart_redirect'] ) ? $value : 'yes';
}
add_filter( 'pre_option_woocommerce_cart_redirect_after_add', 'dn_bfs_wc_force_cart_redirect' );

function dn_bfs_wc_force_no_ajax_add_to_cart( $value ) {
	$settings = dn_bfs_get_tracking_settings();

	return empty( $settings['force_cart_redirect'] ) ? $value : 'no';
}
add_filter( 'pre_option_woocommerce_enable_ajax_add_to_cart', 'dn_bfs_wc_force_no_ajax_add_to_cart' );
