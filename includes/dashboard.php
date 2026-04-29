<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_burst_dash_get_sales_orders_for_period( $start, $end ) {
	return dn_burst_dash_get_orders_for_period(
		$start,
		$end,
		array( 'wc-processing', 'wc-completed' )
	);
}
function dn_burst_dash_get_atc_option_key( $type, $date_key ) {
	return 'dn_atc_' . $type . '_' . $date_key;
}

function dn_burst_dash_get_client_ip() {
	$keys = array(
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_CLIENT_IP',
		'REMOTE_ADDR',
	);

	foreach ( $keys as $key ) {
		if ( empty( $_SERVER[ $key ] ) ) {
			continue;
		}

		$value = wp_unslash( $_SERVER[ $key ] );
		$parts = array_map( 'trim', explode( ',', $value ) );

		foreach ( $parts as $ip ) {
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}

	return 'unknown';
}
function dn_burst_dash_get_ignored_ips() {
	return array(
		'127.0.0.1',
		'::1',
		'113.161.10.20',
		'14.162.55.99',
		'171.234.9.118',
	);
}


function dn_burst_dash_is_ignored_ip( $ip = '' ) {
	if ( empty( $ip ) || 'unknown' === $ip ) {
		return false;
	}

	$ignored_ips = dn_burst_dash_get_ignored_ips();

	return in_array( $ip, $ignored_ips, true );
}

function dn_burst_dash_get_visitor_key() {
	$user_id = get_current_user_id();
	if ( $user_id > 0 ) {
		return 'user_' . $user_id;
	}

	$session_part = '';

	if ( function_exists( 'WC' ) && WC()->session ) {
		$customer_id = WC()->session->get_customer_id();
		if ( ! empty( $customer_id ) ) {
			$session_part = 'wc_' . $customer_id;
		}
	}

	if ( '' === $session_part ) {
		if ( empty( $_COOKIE['dn_atc_visitor'] ) ) {
			$token = wp_generate_password( 20, false, false );
			wc_setcookie( 'dn_atc_visitor', $token, time() + MONTH_IN_SECONDS );
			$_COOKIE['dn_atc_visitor'] = $token;
		}

		$session_part = 'cookie_' . sanitize_text_field( wp_unslash( $_COOKIE['dn_atc_visitor'] ) );
	}

	$ip = dn_burst_dash_get_client_ip();

	return md5( $session_part . '|' . $ip );
}

function dn_burst_dash_get_atc_dedupe_key( $product_id ) {
	return 'dn_atc_seen_' . absint( $product_id ) . '_' . dn_burst_dash_get_visitor_key();
}

add_action( 'woocommerce_add_to_cart', function(
	$cart_item_key,
	$product_id,
	$quantity,
	$variation_id,
	$variation,
	$cart_item_data
) {
	$client_ip = dn_burst_dash_get_client_ip();
	if ( dn_burst_dash_is_ignored_ip( $client_ip ) ) {
		return;
	}
	$target_product_id = $variation_id ? $variation_id : $product_id;

	$dedupe_key = dn_burst_dash_get_atc_dedupe_key( $target_product_id );
	$already_tracked = get_transient( $dedupe_key );
	
	
	if ( false !== $already_tracked ) {
		return;
	}

	$tz       = wp_timezone();
	$now      = new DateTimeImmutable( 'now', $tz );
	$date_key = $now->format( 'Y_m_d' );

	$hits_key = dn_burst_dash_get_atc_option_key( 'hits', $date_key );
	$qty_key  = dn_burst_dash_get_atc_option_key( 'qty', $date_key );

	$current_hits = (int) get_option( $hits_key, 0 );
	$current_qty  = (int) get_option( $qty_key, 0 );

	// Chỉ cộng 1 event cho sản phẩm này, dù click bao nhiêu lần
	update_option( $hits_key, $current_hits + 1, false );

	// Nếu bạn muốn quantity cũng chỉ ghi 1 thì để +1
	update_option( $qty_key, $current_qty + 1, false );

	// Đánh dấu đã ghi nhận sản phẩm này cho visitor này
	set_transient( $dedupe_key, 1, DAY_IN_SECONDS );
}, 10, 6 );

function dn_burst_dash_get_atc_stats_for_period( $start, $end ) {
	$tz = wp_timezone();

	$start_dt = ( new DateTimeImmutable( '@' . (int) $start ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
	$end_dt   = ( new DateTimeImmutable( '@' . (int) $end ) )->setTimezone( $tz )->setTime( 0, 0, 0 );

	$hits = 0;
	$qty  = 0;

	for ( $cursor = $start_dt; $cursor <= $end_dt; $cursor = $cursor->modify( '+1 day' ) ) {
		$date_key = $cursor->format( 'Y_m_d' );

		$hits += (int) get_option( dn_burst_dash_get_atc_option_key( 'hits', $date_key ), 0 );
		$qty  += (int) get_option( dn_burst_dash_get_atc_option_key( 'qty', $date_key ), 0 );
	}

	return array(
		'hits' => $hits,
		'qty'  => $qty,
	);
}


function dn_burst_dash_is_page() {
	if ( ! is_admin() ) {
		return false;
	}

	global $pagenow;

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

	return $pagenow === 'admin.php' && $page === 'dn-burst-funnel-stats';
}
function dn_burst_dash_get_created_products_count( $start, $end ) {
	$args = array(
		'post_type'      => 'product',
		'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'date_query'     => array(
			array(
				'after'     => gmdate( 'Y-m-d H:i:s', $start ),
				'before'    => gmdate( 'Y-m-d H:i:s', $end ),
				'inclusive' => true,
				'column'    => 'post_date_gmt',
			),
		),
	);

	$query = new WP_Query( $args );

	return (int) $query->found_posts;
}
function dn_burst_dash_get_burst_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'burst_statistics';
}

function dn_burst_dash_burst_table_exists() {
	global $wpdb;
	$table = dn_burst_dash_get_burst_table_name();
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

function dn_burst_dash_get_wc_paths() {
	$cart_path     = '/cart';
	$checkout_path = '/checkout';
	$product_base  = '/product';

	if ( function_exists( 'wc_get_cart_url' ) ) {
		$cart_url  = wc_get_cart_url();
		$cart_path = wp_parse_url( $cart_url, PHP_URL_PATH ) ?: $cart_path;
	}

	if ( function_exists( 'wc_get_checkout_url' ) ) {
		$checkout_url  = wc_get_checkout_url();
		$checkout_path = wp_parse_url( $checkout_url, PHP_URL_PATH ) ?: $checkout_path;
	}
	$permalinks = function_exists( 'wc_get_permalink_structure' ) ? wc_get_permalink_structure() : array();
	if ( ! empty( $permalinks['product_rewrite_slug'] ) ) {
		$product_base = '/' . trim( $permalinks['product_rewrite_slug'], '/' );
	}

	return array(
		'cart'      => untrailingslashit( $cart_path ),
		'checkout'  => untrailingslashit( $checkout_path ),
		'order_pay' => '/order-pay',
		'product_base' => untrailingslashit( $product_base ),
	);
}

function dn_burst_dash_get_range_data() {
	$range = isset( $_GET['dn_range'] ) ? sanitize_key( wp_unslash( $_GET['dn_range'] ) ) : 'today';
	$tz    = wp_timezone();
	$now   = new DateTimeImmutable( 'now', $tz );

	switch ( $range ) {
		case 'yesterday':
			$label = 'day before yesterday';

			$current_start_dt = $now->modify( '-1 day' )->setTime( 0, 0, 0 );
			$current_end_dt   = $now->modify( '-1 day' )->setTime( 23, 59, 59 );

			$previous_start_dt = $now->modify( '-2 day' )->setTime( 0, 0, 0 );
			$previous_end_dt   = $now->modify( '-2 day' )->setTime( 23, 59, 59 );
			break;

		case '7d':
			$label = 'previous 7 days';

			$current_start_dt = $now->setTime( 0, 0, 0 )->modify( '-6 days' );
			$current_end_dt   = $now->setTime( 23, 59, 59 );

			$previous_start_dt = $current_start_dt->modify( '-7 days' );
			$previous_end_dt   = $current_start_dt->modify( '-1 second' );
			break;

		case '30d':
			$label = 'previous 30 days';

			$current_start_dt = $now->setTime( 0, 0, 0 )->modify( '-29 days' );
			$current_end_dt   = $now->setTime( 23, 59, 59 );

			$previous_start_dt = $current_start_dt->modify( '-30 days' );
			$previous_end_dt   = $current_start_dt->modify( '-1 second' );
			break;

		case 'today':
		default:
			$range = 'today';
			$label = 'yesterday';

			$current_start_dt = $now->setTime( 0, 0, 0 );
			$current_end_dt   = $now->setTime( 23, 59, 59 );

			$previous_start_dt = $now->modify( '-1 day' )->setTime( 0, 0, 0 );
			$previous_end_dt   = $now->modify( '-1 day' )->setTime( 23, 59, 59 );
			break;
	}

	return array(
		'range'                => $range,
		'compare_label'        => $label,
		'current_start'        => $current_start_dt->getTimestamp(),
		'current_end'          => $current_end_dt->getTimestamp(),
		'previous_start'       => $previous_start_dt->getTimestamp(),
		'previous_end'         => $previous_end_dt->getTimestamp(),
		'current_start_mysql'  => $current_start_dt->format( 'Y-m-d H:i:s' ),
		'current_end_mysql'    => $current_end_dt->format( 'Y-m-d H:i:s' ),
		'previous_start_mysql' => $previous_start_dt->format( 'Y-m-d H:i:s' ),
		'previous_end_mysql'   => $previous_end_dt->format( 'Y-m-d H:i:s' ),
	);
}

function dn_burst_dash_format_percent_change( $current, $previous ) {
	$current  = (float) $current;
	$previous = (float) $previous;

	if ( $previous <= 0 ) {
		return $current > 0 ? 100 : 0;
	}

	return round( ( ( $current - $previous ) / $previous ) * 100, 1 );
}

function dn_burst_dash_like_path_sql( $path ) {
	global $wpdb;

	$path = untrailingslashit( $path );
	$a = '%' . $wpdb->esc_like( $path ) . '%';
	$b = '%' . $wpdb->esc_like( trailingslashit( $path ) ) . '%';

	return array( $a, $b );
}

/**
 * =========================
 * Burst queries
 * =========================
 */

function dn_burst_dash_burst_query_block( $start, $end, $where_sql = '', $params = array() ) {
	global $wpdb;

	$table = dn_burst_dash_get_burst_table_name();

	$sql = "
		SELECT
			COUNT(*) AS pageviews,
			COUNT(DISTINCT uid) AS visitors,
			COUNT(DISTINCT session_id) AS sessions
		FROM {$table}
		WHERE time >= %d
		  AND time <= %d
		  {$where_sql}
	";

	$args = array_merge( array( $start, $end ), $params );

	return $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
}

function dn_burst_dash_get_burst_period_stats( $start, $end ) {
	$paths = dn_burst_dash_get_wc_paths();

	$all = dn_burst_dash_burst_query_block( $start, $end );
	list( $product_like_a, $product_like_b ) = dn_burst_dash_like_path_sql( $paths['product_base'] );
	$product_visits = dn_burst_dash_burst_query_block(
		$start,
		$end,
		' AND (page_url LIKE %s OR page_url LIKE %s)',
		array( $product_like_a, $product_like_b )
	);

	list( $cart_like_a, $cart_like_b ) = dn_burst_dash_like_path_sql( $paths['cart'] );
	$cart = dn_burst_dash_burst_query_block(
		$start,
		$end,
		' AND (page_url LIKE %s OR page_url LIKE %s)',
		array( $cart_like_a, $cart_like_b )
	);

	list( $checkout_like_a, $checkout_like_b ) = dn_burst_dash_like_path_sql( $paths['checkout'] );
	$checkout = dn_burst_dash_burst_query_block(
		$start,
		$end,
		' AND (page_url LIKE %s OR page_url LIKE %s) AND page_url NOT LIKE %s',
		array( $checkout_like_a, $checkout_like_b, '%' . $paths['order_pay'] . '%' )
	);

	$payment = dn_burst_dash_burst_query_block(
		$start,
		$end,
		' AND (page_url LIKE %s OR page_url LIKE %s OR page_url LIKE %s)',
		array(
			'%' . $paths['order_pay'] . '%',
			'%payment%',
			'%pay-for-order%',
		)
	);

	return array(
		'paths'    => $paths,
		'visits'   => array(
			'pageviews' => (int) ( $all['pageviews'] ?? 0 ),
			'visitors'  => (int) ( $all['visitors'] ?? 0 ),
			'sessions'  => (int) ( $all['sessions'] ?? 0 ),
		),
		'product_visits' => array(
			'pageviews' => (int) ( $product_visits['pageviews'] ?? 0 ),
			'visitors'  => (int) ( $product_visits['visitors'] ?? 0 ),
			'sessions'  => (int) ( $product_visits['sessions'] ?? 0 ),
		),
		'cart'     => array(
			'pageviews' => (int) ( $cart['pageviews'] ?? 0 ),
			'visitors'  => (int) ( $cart['visitors'] ?? 0 ),
			'sessions'  => (int) ( $cart['sessions'] ?? 0 ),
		),
		'checkout' => array(
			'pageviews' => (int) ( $checkout['pageviews'] ?? 0 ),
			'visitors'  => (int) ( $checkout['visitors'] ?? 0 ),
			'sessions'  => (int) ( $checkout['sessions'] ?? 0 ),
		),
		'payment'  => array(
			'pageviews' => (int) ( $payment['pageviews'] ?? 0 ),
			'visitors'  => (int) ( $payment['visitors'] ?? 0 ),
			'sessions'  => (int) ( $payment['sessions'] ?? 0 ),
		),
	);
}

/**
 * =========================
 * WooCommerce queries
 * =========================
 */

function dn_burst_dash_get_orders_for_period( $start, $end, $statuses = array() ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}

	$args = array(
		'limit'  => -1,
		'return' => 'objects',
	);

	if ( ! empty( $statuses ) ) {
		$args['status'] = $statuses;
	}

	$orders   = wc_get_orders( $args );
	$filtered = array();

	$start = (int) $start;
	$end   = (int) $end;

	foreach ( $orders as $order ) {
		if ( ! $order instanceof WC_Order ) {
			continue;
		}

		$created = $order->get_date_created();
		if ( ! $created ) {
			continue;
		}

		$order_ts = $created->getTimestamp();

		if ( $order_ts >= $start && $order_ts <= $end ) {
			$filtered[] = $order;
		}
	}

	return $filtered;
}

function dn_burst_dash_sum_fee_by_keywords( $orders, $keywords = array() ) {
	$total = 0;

	foreach ( $orders as $order ) {
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$name = strtolower( $fee->get_name() );
			foreach ( $keywords as $keyword ) {
				if ( strpos( $name, strtolower( $keyword ) ) !== false ) {
					$total += (float) $fee->get_total();
					break;
				}
			}
		}
	}

	return $total;
}

function dn_burst_dash_get_wc_period_stats( $start, $end ) {
	$sales_orders = dn_burst_dash_get_orders_for_period(
		$start,
		$end,
		array( 'wc-processing', 'wc-completed' )
	);

	$pending_orders = dn_burst_dash_get_orders_for_period(
		$start,
		$end,
		array( 'wc-pending', 'wc-on-hold' )
	);

	$fulfillment_orders = dn_burst_dash_get_orders_for_period(
		$start,
		$end,
		array( 'wc-processing' )
	);

	$order_count       = 0;
	$gross_sales       = 0;
	$net_sales         = 0;
	$items_sold        = 0;
	$refunds           = 0;
	$shipping_total    = 0;
	$paid_total        = 0;
	$balance_total     = 0;
	$fulfillment_total = 0;

	foreach ( $sales_orders as $order ) {
		if ( ! $order instanceof WC_Order ) {
			continue;
		}

		$order_count++;
		$gross_sales    += (float) $order->get_total();
		$refunds        += (float) $order->get_total_refunded();
		$shipping_total += (float) $order->get_shipping_total();
		$items_sold     += (int) $order->get_item_count();
		$paid_total     += (float) $order->get_total();
	}

	foreach ( $pending_orders as $order ) {
		if ( ! $order instanceof WC_Order ) {
			continue;
		}
		$balance_total += (float) $order->get_total();
	}

	foreach ( $fulfillment_orders as $order ) {
		if ( ! $order instanceof WC_Order ) {
			continue;
		}
		$fulfillment_total += (float) $order->get_total();
	}

	$net_sales      = max( 0, $gross_sales - $refunds );
	$aov            = $order_count > 0 ? $gross_sales / $order_count : 0;
	$aoi            = $order_count > 0 ? $items_sold / $order_count : 0;
	$tip_total      = dn_burst_dash_sum_fee_by_keywords( $sales_orders, array( 'tip', 'tips', 'gratuity' ) );
	$insurance_fee  = dn_burst_dash_sum_fee_by_keywords( $sales_orders, array( 'insurance' ) );
	$profit_proxy   = max( 0, $net_sales - $shipping_total );

	return array(
		'orders'               => (int) $order_count,
		'gross_sales'          => (float) $gross_sales,
		'net_sales'            => (float) $net_sales,
		'items_sold'           => (int) $items_sold,
		'aov'                  => (float) $aov,
		'aoi'                  => (float) $aoi,
		'pending_payment'      => count( $pending_orders ),
		'tip_total'            => (float) $tip_total,
		'profit_proxy'         => (float) $profit_proxy,
		'fulfillment_orders'   => count( $fulfillment_orders ),
		'fulfillment_total'    => (float) $fulfillment_total,
		'paid_total'           => (float) $paid_total,
		'balance_total'        => (float) $balance_total,
		'insurance_fee'        => (float) $insurance_fee,
		'created_campaigns'    => dn_burst_dash_get_created_products_count( $start, $end ),
	);
}

/**
 * =========================
 * Data builder
 * =========================
 */

function dn_burst_dash_build_data() {
	$range = dn_burst_dash_get_range_data();

	$current_burst = dn_burst_dash_get_burst_period_stats( $range['current_start'], $range['current_end'] );
	$previous_burst = dn_burst_dash_get_burst_period_stats( $range['previous_start'], $range['previous_end'] );

	$current_wc = dn_burst_dash_get_wc_period_stats( $range['current_start'], $range['current_end'] );
	$previous_wc = dn_burst_dash_get_wc_period_stats( $range['previous_start'], $range['previous_end'] );
	
	
	$visits_current   = $current_burst['visits']['visitors'];
	$visits_previous  = $previous_burst['visits']['visitors'];
	
	$product_visits_current  = $current_burst['product_visits']['visitors'];
	$product_visits_previous = $previous_burst['product_visits']['visitors'];
	
	$cart_current     = $current_burst['cart']['visitors'];
	$cart_previous    = $previous_burst['cart']['visitors'];

	$checkout_current = $current_burst['checkout']['visitors'];
	$checkout_previous= $previous_burst['checkout']['visitors'];

	$payment_current  = $current_burst['payment']['visitors'];
	$payment_previous = $previous_burst['payment']['visitors'];
	
	$current_atc  = dn_burst_dash_get_atc_stats_for_period( $range['current_start'], $range['current_end'] );
	$previous_atc = dn_burst_dash_get_atc_stats_for_period( $range['previous_start'], $range['previous_end'] );

	$orders_current   = $current_wc['orders'];
	$orders_previous  = $previous_wc['orders'];

	$items_current    = $current_wc['items_sold'];
	$items_previous   = $previous_wc['items_sold'];

	$conversion_current  = $visits_current > 0 ? round( ( $orders_current / $visits_current ) * 100, 1 ) : 0;
	$conversion_previous = $visits_previous > 0 ? round( ( $orders_previous / $visits_previous ) * 100, 1 ) : 0;
	
	$atc_hits_current   = $current_atc['hits'];
	$atc_hits_previous  = $previous_atc['hits'];
	$atc_qty_current    = $current_atc['qty'];
	$atc_qty_previous   = $previous_atc['qty'];

	return array(
		'range'         => $range,
		'paths'         => $current_burst['paths'],
		'current_burst' => $current_burst,
		'previous_burst'=> $previous_burst,
		'current_wc'    => $current_wc,
		'previous_wc'   => $previous_wc,
		'cards'         => array(
			'visits' => array(
				'title'         => 'Visits',
				'main'          => $visits_current,
				'secondary'     => '',
				'compare'       => $visits_previous,
				'change'        => null,
				'icon'          => 'eye',
			),
			'product_visits' => array(
				'title'         => 'Product Visits',
				'main'          => $product_visits_current,
				'secondary'     => $visits_current > 0 ? round( ( $product_visits_current / $visits_current ) * 100, 1 ) . '%' : '0%',
				'compare'       => $product_visits_previous,
				'change'        => null,
				'icon'          => 'product',
			),
			'cart' => array(
				'title'         => 'Cart Visits',
				'main'          => $cart_current,
				'secondary'     => $visits_current > 0 ? round( ( $cart_current / $visits_current ) * 100, 1 ) . '%' : '0%',
				'compare'       => $cart_previous,
				'change'        => null,
				'icon'          => 'cart',
			),
			'atc_events' => array(
				'title'         => 'Add To Cart',
				'main'          => $atc_hits_current,
				'secondary' => $visits_current > 0
				? round( ( $atc_hits_current / $visits_current ) * 100, 1 ) . '%'
				: '0%',
				'compare'       => $atc_hits_previous . ' / Qty: ' . $atc_qty_previous,
				'change'        => dn_burst_dash_format_percent_change( $atc_hits_current, $atc_hits_previous ) . '%',
				'icon'          => 'cart',
			),
			'checkout' => array(
				'title'         => 'Checkout',
				'main'          => $checkout_current,
				'secondary'     => $visits_current > 0 ? round( ( $checkout_current / $visits_current ) * 100, 1 ) . '%' : '0%',
				'compare'       => $checkout_previous,
				'change'        => null,
				'icon'          => 'checkout',
			),
// 			'payment' => array(
// 				'title'         => 'Add Payment Info',
// 				'main'          => $payment_current,
// 				'secondary'     => $checkout_current > 0 ? round( ( $payment_current / $checkout_current ) * 100, 1 ) . '%' : '0%',
// 				'compare'       => $payment_previous,
// 				'change'        => null,
// 				'icon'          => 'wand',
// 			),
			'orders_aov' => array(
				'title'         => 'Orders/AOV',
				'main'          => $orders_current,
				'secondary'     => wc_price( $current_wc['aov'] ),
				'compare'       => $orders_previous,
				'change'        => dn_burst_dash_format_percent_change( $orders_current, $orders_previous ) . '%',
				'icon'          => 'orders',
			),
			'items_aoi' => array(
				'title'         => 'Items/AOI',
				'main'          => $items_current,
				'secondary'     => round( $current_wc['aoi'], 1 ),
				'compare'       => $items_previous . ' / ' . round( $previous_wc['aoi'], 1 ),
				'change'        => null,
				'icon'          => 'box',
			),
			'conversion' => array(
				'title'         => 'Conversion Rate',
				'main'          => $conversion_current . '%',
				'secondary'     => '',
				'compare'       => $conversion_previous . '%',
				'change'        => null,
				'icon'          => 'check',
			),
			'campaigns' => array(
				'title'         => 'Created Campaigns',
				'main'          => $current_wc['created_campaigns'],
				'secondary'     => '',
				'compare'       => 0,
				'change'        => null,
				'icon'          => 'megaphone',
			),
// 			'pending' => array(
// 				'title'         => 'Pending Payment',
// 				'main'          => $current_wc['pending_payment'],
// 				'secondary'     => '',
// 				'compare'       => $previous_wc['pending_payment'],
// 				'change'        => null,
// 				'icon'          => 'pending',
// 			),
			'sales_tip' => array(
				'title'         => 'Sales/Tip',
				'main'          => wc_price( $current_wc['gross_sales'] ),
				'secondary'     => wc_price( $current_wc['tip_total'] ),
				'compare'       => wc_price( $previous_wc['gross_sales'] ),
				'change'        => $current_wc['tip_total'] > 0 ? 'Tip' : '',
				'icon'          => 'dollar',
			),
// 			'profits' => array(
// 				'title'         => 'Profits',
// 				'main'          => wc_price( $current_wc['profit_proxy'] ),
// 				'secondary'     => '',
// 				'compare'       => wc_price( $previous_wc['profit_proxy'] ),
// 				'change'        => null,
// 				'icon'          => 'profit',
// 			),
			'fulfillment' => array(
				'title'         => 'Fulfillment Orders',
				'main'          => $current_wc['fulfillment_orders'] . ' / ' . wc_price( $current_wc['fulfillment_total'] ),
				'secondary'     => '',
				'compare'       => $previous_wc['fulfillment_orders'] . ' / ' . wc_price( $previous_wc['fulfillment_total'] ),
				'change'        => null,
				'icon'          => 'card',
			),
			'paid_balance' => array(
				'title'         => 'Paid/Balance',
				'main'          => wc_price( $current_wc['paid_total'] ) . ' / ' . wc_price( $current_wc['balance_total'] ),
				'secondary'     => '',
				'compare'       => wc_price( $previous_wc['paid_total'] ),
				'change'        => null,
				'icon'          => 'money',
			),
// 			'insurance' => array(
// 				'title'         => 'Insurance Fee',
// 				'main'          => wc_price( $current_wc['insurance_fee'] ),
// 				'secondary'     => '',
// 				'compare'       => '',
// 				'change'        => null,
// 				'icon'          => 'shield',
// 			),
		),
	);
}

/**
 * =========================
 * Menu
 * =========================
 */

add_action( 'admin_menu', function() {
	add_submenu_page(
		'burst',
		'Funnel Stats',
		'Funnel Stats',
		'manage_options',
		'dn-burst-funnel-stats',
		'dn_burst_dash_render_page'
	);
}, 99 );

/**
 * =========================
 * CSS
 * =========================
 */

add_action( 'admin_head', function() {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( ! dn_burst_dash_is_page() ) {
		return;
	}
	?>
	<style>
		.dn-burst-wrap{
			padding:20px 20px 0 0;
		}
		.dn-burst-toolbar{
			display:flex;
			justify-content:space-between;
			align-items:center;
			margin:0 0 20px;
			gap:16px;
			flex-wrap:wrap;
		}
		.dn-burst-title{
			font-size:24px;
			font-weight:600;
			margin:0;
			color:#1d2327;
		}
		.dn-burst-filters{
			display:flex;
			gap:8px;
			flex-wrap:wrap;
		}
		.dn-burst-filters a{
			display:inline-flex;
			align-items:center;
			justify-content:center;
			min-height:36px;
			padding:0 14px;
			background:#fff;
			border:1px solid #dcdcde;
			border-radius:8px;
			text-decoration:none;
			color:#2c3338;
			box-shadow:none;
		}
		.dn-burst-filters a.is-active{
			background:#2271b1;
			border-color:#2271b1;
			color:#fff;
		}
		.dn-burst-grid{
			display:grid;
			grid-template-columns:repeat(4,minmax(240px,1fr));
			gap:22px;
		}
		.dn-burst-card{
			background:#fff;
			border:1px solid #edf0f4;
			border-radius:8px;
			padding:24px;
			min-height:140px;
			box-sizing:border-box;
			position:relative;
		}
		.dn-burst-card-title{
			margin:0 0 18px;
			font-size:15px;
			line-height:1.4;
			font-weight:500;
			color:#8a9aa8;
		}
		.dn-burst-card-icon{
			position:absolute;
			top:24px;
			right:24px;
			width:42px;
			height:42px;
			border-radius:4px;
			background:#dfe0ff;
			display:flex;
			align-items:center;
			justify-content:center;
			color:#6b73ff;
			font-size:18px;
			font-weight:700;
		}
		.dn-burst-main-line{
			display:flex;
			align-items:baseline;
			gap:8px;
			flex-wrap:wrap;
			margin:0 0 18px;
		}
		.dn-burst-main{
			font-size:18px;
			line-height:1.2;
			font-weight:700;
			color:#646f7b;
		}
		.dn-burst-main.is-large{
			font-size:40px;
		}
		.dn-burst-secondary{
			font-size:16px;
			line-height:1.2;
			color:#646f7b;
		}
		.dn-burst-secondary.is-highlight{
			color:#ff8500;
		}
		.dn-burst-compare{
			font-size:15px;
			line-height:1.5;
			color:#8897a5;
		}
		.dn-burst-compare strong{
			color:#1ea7fd;
			font-weight:500;
			margin-right:6px;
		}
		.dn-burst-change{
			display:inline-block;
			margin-left:2px;
			padding:1px 6px;
			border-radius:4px;
			background:#dcfce7;
			color:#10b981;
			font-size:12px;
			line-height:1.4;
			font-weight:600;
			vertical-align:middle;
		}
		.dn-burst-meta{
			margin-top:22px;
			padding:18px;
			background:#fff;
			border:1px solid #edf0f4;
			border-radius:8px;
			color:#50575e;
		}
		.dn-burst-meta code{
			font-size:12px;
		}
		@media (max-width: 1400px){
			.dn-burst-grid{
				grid-template-columns:repeat(3,minmax(240px,1fr));
			}
		}
		@media (max-width: 1100px){
			.dn-burst-grid{
				grid-template-columns:repeat(2,minmax(240px,1fr));
			}
		}
		@media (max-width: 782px){
			.dn-burst-grid{
				grid-template-columns:1fr;
			}
		}
	</style>
	<?php
});

/**
 * =========================
 * UI helpers
 * =========================
 */

function dn_burst_dash_icon( $icon ) {
	$map = array(
		'eye'       => '◉',
		'cart'      => '🛒',
		'checkout'  => '🛍',
		'wand'      => '✚',
		'orders'    => '⮂',
		'box'       => '⬢',
		'check'     => '◔',
		'megaphone' => '📣',
		'pending'   => '⌛',
		'dollar'    => '$',
		'profit'    => '$',
		'card'      => '▣',
		'money'     => '$',
		'shield'    => '$',
		'product'   => '◫',
	);

	return isset( $map[ $icon ] ) ? $map[ $icon ] : '•';
}

function dn_burst_dash_render_card( $card, $compare_label ) {
	$main = (string) $card['main'];
	$is_large = strlen( wp_strip_all_tags( $main ) ) <= 6 || strpos( $main, '%' ) !== false || is_numeric( str_replace( ',', '', $main ) );

	$secondary = (string) $card['secondary'];
	$compare   = (string) $card['compare'];
	$change    = (string) $card['change'];
	?>
	<div class="dn-burst-card">
		<div class="dn-burst-card-icon"><?php echo esc_html( dn_burst_dash_icon( $card['icon'] ) ); ?></div>
		<h3 class="dn-burst-card-title"><?php echo esc_html( $card['title'] ); ?></h3>

		<div class="dn-burst-main-line">
			<div class="dn-burst-main <?php echo $is_large ? 'is-large' : ''; ?>">
				<?php echo wp_kses_post( $main ); ?>
			</div>

			<?php if ( '' !== $secondary ) : ?>
				<div class="dn-burst-secondary <?php echo ( strpos( $secondary, '%' ) !== false && 'Checkout' === $card['title'] ) ? 'is-highlight' : ''; ?>">
					<?php echo wp_kses_post( $secondary ); ?>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $change ) : ?>
				<span class="dn-burst-change"><?php echo esc_html( $change ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( '' !== $compare ) : ?>
			<div class="dn-burst-compare">
				<strong><?php echo wp_kses_post( $compare ); ?></strong> <?php echo esc_html( $compare_label ); ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * =========================
 * Render
 * =========================
 */

function dn_burst_dash_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! dn_burst_dash_burst_table_exists() ) {
		echo '<div class="wrap"><div class="notice notice-error"><p>Không tìm thấy bảng Burst Statistics.</p></div></div>';
		return;
	}

	$data = dn_burst_dash_build_data();
	$range = $data['range']['range'];
	$compare_label = $data['range']['compare_label'];
	?>
	<div class="wrap dn-burst-wrap">
		<div class="dn-burst-toolbar">
			<h1 class="dn-burst-title">Burst Funnel Stats</h1>

			<div class="dn-burst-filters">
				<a class="<?php echo ( 'today' === $range ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=dn-burst-funnel-stats&dn_range=today' ) ); ?>">Today</a>
				<a class="<?php echo ( 'yesterday' === $range ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=dn-burst-funnel-stats&dn_range=yesterday' ) ); ?>">Yesterday</a>
				<a class="<?php echo ( '7d' === $range ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=dn-burst-funnel-stats&dn_range=7d' ) ); ?>">7 days</a>
				<a class="<?php echo ( '30d' === $range ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=dn-burst-funnel-stats&dn_range=30d' ) ); ?>">30 days</a>
		    </div>
		</div>

		<div class="dn-burst-grid">
			<?php
			foreach ( $data['cards'] as $card ) {
				dn_burst_dash_render_card( $card, $compare_label );
			}
			?>
		</div>

		<div class="dn-burst-meta">
			<strong>Matching paths:</strong><br>
			Cart: <code><?php echo esc_html( $data['paths']['cart'] ); ?></code><br>
			Checkout: <code><?php echo esc_html( $data['paths']['checkout'] ); ?></code><br>
			Payment contains: <code>/order-pay</code>, <code>payment</code>, <code>pay-for-order</code><br><br>
			Product base: <code><?php echo esc_html( $data['paths']['product_base'] ); ?></code><br>
			<strong>Lưu ý:</strong> các card như Visits / Add To Cart / Checkout / Add Payment Info lấy từ Burst page visits.
			Các card Orders/AOV, Items/AOI, Pending Payment, Sales, Paid/Balance... lấy từ WooCommerce orders.
			Created Campaigns hiện đang để 0 vì snippet này chưa nối sang nguồn campaign riêng.<br>
        @2026
		</div>
	</div>
	<?php
}
