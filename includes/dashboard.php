<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_burst_dash_get_sales_orders_for_period( $start, $end ) {
    return dn_burst_dash_get_orders_for_period(
        $start,
        $end,
        dn_burst_dash_get_counted_order_statuses()
    );
}

function dn_burst_dash_get_data_last_changed() {
	$last_changed = get_option( 'dn_bfs_data_last_changed', '1' );

	return is_scalar( $last_changed ) ? (string) $last_changed : '1';
}

function dn_burst_dash_touch_data_changed() {
	update_option( 'dn_bfs_data_last_changed', sprintf( '%.6F', microtime( true ) ), false );
}

function dn_burst_dash_get_cache_version() {
	return dn_burst_dash_get_data_last_changed();
}

function dn_burst_dash_bump_cache_version() {
	dn_burst_dash_touch_data_changed();
}

function dn_burst_dash_send_nocache_headers() {
	if ( headers_sent() ) {
		return;
	}

	nocache_headers();
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'Pragma: no-cache' );
}

function dn_burst_dash_get_cache_key( $prefix, $range = null, $extra = array() ) {
	$range = is_array( $range ) ? $range : dn_burst_dash_get_range_data();
	$extra = is_array( $extra ) ? $extra : array();

	return 'dn_bfs_' . sanitize_key( $prefix ) . '_' . md5( wp_json_encode( array(
		'period'            => $range['period'],
		'compare'           => $range['compare'],
		'current_start'     => $range['current_start'],
		'current_end'       => $range['current_end'],
		'previous_start'    => $range['previous_start'],
		'previous_end'      => $range['previous_end'],
		'data_last_changed' => dn_burst_dash_get_data_last_changed(),
		'extra'             => $extra,
	) ) );
}

function dn_burst_dash_wrap_cache_payload( $payload ) {
	return array(
		'generated_at'       => microtime( true ),
		'data_last_changed' => dn_burst_dash_get_data_last_changed(),
		'payload'            => $payload,
	);
}

function dn_burst_dash_get_cached_payload( $cache_key, $ttl = 300 ) {
	$cached = get_transient( $cache_key );

	if ( ! is_array( $cached ) || ! array_key_exists( 'payload', $cached ) ) {
		return false;
	}

	$generated_at       = isset( $cached['generated_at'] ) ? (float) $cached['generated_at'] : 0;
	$cached_last_change = isset( $cached['data_last_changed'] ) ? (string) $cached['data_last_changed'] : '';
	$current_changed    = dn_burst_dash_get_data_last_changed();

	if ( $cached_last_change !== $current_changed ) {
		return false;
	}

	if ( $ttl > 0 && $generated_at > 0 && ( microtime( true ) - $generated_at ) > $ttl ) {
		return false;
	}

	return $cached['payload'];
}

function dn_burst_dash_clear_dashboard_cache() {
	global $wpdb;

	$wpdb->query(
		"
		DELETE FROM {$wpdb->options}
		WHERE option_name LIKE '_transient_dn_bfs_%'
			OR option_name LIKE '_transient_timeout_dn_bfs_%'
		"
	);
}

function dn_burst_dash_touch_refresh_time() {
	update_option( 'dn_burst_funnel_stats_last_refresh', current_time( 'timestamp' ), false );
}

function dn_burst_dash_refresh_dashboard_cache() {
	dn_burst_dash_touch_data_changed();
	dn_burst_dash_clear_dashboard_cache();
	dn_burst_dash_build_data();
	dn_burst_dash_get_chart_data( dn_burst_dash_get_range_data() );
	dn_burst_dash_touch_refresh_time();
}

function dn_burst_dash_schedule_refresh_event() {
	if ( ! wp_next_scheduled( 'dn_burst_funnel_stats_refresh_cache' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'dn_burst_funnel_stats_refresh_cache' );
	}
}
add_action( 'dn_burst_funnel_stats_refresh_cache', 'dn_burst_dash_refresh_dashboard_cache' );
function dn_burst_dash_get_atc_option_key( $type, $date_key ) {
	return 'dn_atc_' . $type . '_' . $date_key;
}

function dn_burst_dash_get_atc_url_groups_option_key( $date_key ) {
	return 'dn_atc_url_groups_' . $date_key;
}

function dn_burst_dash_get_client_ip() {
	return function_exists( 'dn_bfs_get_client_ip' ) ? dn_bfs_get_client_ip() : 'unknown';
}
function dn_burst_dash_get_ignored_ips() {
	$settings = function_exists( 'dn_bfs_get_tracking_settings' ) ? dn_bfs_get_tracking_settings() : array();

	return isset( $settings['excluded_ips'] ) ? (array) $settings['excluded_ips'] : array();
}


function dn_burst_dash_is_ignored_ip( $ip = '' ) {
	return function_exists( 'dn_bfs_is_ip_excluded' ) ? dn_bfs_is_ip_excluded( $ip ) : false;
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
		if ( empty( $_COOKIE['dn_atc_visitor'] ) || is_array( $_COOKIE['dn_atc_visitor'] ) ) {
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


function dn_burst_dash_is_bot_request() {
	return function_exists( 'dn_bfs_is_bot_request' ) ? dn_bfs_is_bot_request() : false;
}

function dn_burst_dash_is_bad_atc_request() {
	if ( wp_doing_cron() ) {
		return true;
	}

	if ( dn_burst_dash_is_bot_request() ) {
		return true;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] )
		? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
		: '';

	// Count POST add-to-cart requests only, avoiding bot/spam GET traffic.
	if ( 'POST' !== $method ) {
		return true;
	}

	// Allow WooCommerce AJAX add-to-cart.
	$wc_ajax = ( isset( $_GET['wc-ajax'] ) && ! is_array( $_GET['wc-ajax'] ) )
		? sanitize_key( wp_unslash( $_GET['wc-ajax'] ) )
		: '';

	if ( 'add_to_cart' === $wc_ajax ) {
		return false;
	}

	// Allow normal frontend requests.
	if ( ! is_admin() ) {
		return false;
	}

	// Allow valid admin-ajax add-to-cart actions.
	$action = ( isset( $_REQUEST['action'] ) && ! is_array( $_REQUEST['action'] ) )
		? sanitize_key( wp_unslash( $_REQUEST['action'] ) )
		: '';

	if ( wp_doing_ajax() && in_array( $action, array( 'woocommerce_add_to_cart', 'add_to_cart' ), true ) ) {
		return false;
	}

	return true;
}

function dn_burst_dash_atc_rate_limited( $ip ) {
	if ( empty( $ip ) || 'unknown' === $ip ) {
		return true;
	}

	$key   = 'dn_atc_rate_' . md5( $ip );
	$count = (int) get_transient( $key );

	// Limit to 5 add-to-cart writes per IP per minute.
	if ( $count >= 5 ) {
		return true;
	}

	set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

	return false;
}

function dn_burst_dash_get_request_url_for_params() {
	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
		: '';

	if ( '' !== $request_uri ) {
		return home_url( $request_uri );
	}

	return wp_get_referer() ? wp_get_referer() : '';
}

function dn_burst_dash_record_atc_url_groups( $date_key, $quantity ) {
	$url = dn_burst_dash_get_request_url_for_params();

	if ( '' === $url ) {
		return;
	}

	$option_key = dn_burst_dash_get_atc_url_groups_option_key( $date_key );
	$groups     = get_option( $option_key, array() );

	if ( ! is_array( $groups ) ) {
		$groups = array();
	}

	foreach ( array( 'campaign', 'source', 'medium' ) as $group ) {
		$value = dn_burst_dash_get_group_value_from_url( $url, $group );

		if ( ! isset( $groups[ $group ] ) || ! is_array( $groups[ $group ] ) ) {
			$groups[ $group ] = array();
		}

		if ( ! isset( $groups[ $group ][ $value ] ) || ! is_array( $groups[ $group ][ $value ] ) ) {
			$groups[ $group ][ $value ] = array(
				'hits' => 0,
				'qty'  => 0,
			);
		}

		$groups[ $group ][ $value ]['hits'] = absint( $groups[ $group ][ $value ]['hits'] ) + 1;
		$groups[ $group ][ $value ]['qty']  = absint( $groups[ $group ][ $value ]['qty'] ) + max( 1, absint( $quantity ) );
	}

	update_option( $option_key, $groups, false );
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
	$target_product_id = $variation_id ? $variation_id : $product_id;

	if (
		function_exists( 'dn_bfs_should_track_request' )
		&& ! dn_bfs_should_track_request( 'add_to_cart', array( 'product_id' => $target_product_id ) )
	) {
		return;
	}

	if ( dn_burst_dash_is_ignored_ip( $client_ip ) ) {
		return;
	}

	if ( dn_burst_dash_is_bad_atc_request() ) {
		return;
	}

	if ( dn_burst_dash_atc_rate_limited( $client_ip ) ) {
		return;
	}

	if (
		function_exists( 'dn_burst_funnel_stats_should_track_product' )
		&& ! dn_burst_funnel_stats_should_track_product( $target_product_id )
	) {
		return;
	}

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

	update_option( $hits_key, $current_hits + 1, false );
	update_option( $qty_key, $current_qty + max( 1, absint( $quantity ) ), false );
	dn_burst_dash_record_atc_url_groups( $date_key, $quantity );
	dn_burst_dash_touch_data_changed();

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

function dn_burst_dash_get_atc_url_group_stats_for_period( $start, $end, $group ) {
	$tz = wp_timezone();

	$start_dt = ( new DateTimeImmutable( '@' . (int) $start ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
	$end_dt   = ( new DateTimeImmutable( '@' . (int) $end ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
	$group    = dn_burst_dash_sanitize_group( $group );
	$stats    = array();

	for ( $cursor = $start_dt; $cursor <= $end_dt; $cursor = $cursor->modify( '+1 day' ) ) {
		$date_key = $cursor->format( 'Y_m_d' );
		$groups   = get_option( dn_burst_dash_get_atc_url_groups_option_key( $date_key ), array() );

		if ( empty( $groups[ $group ] ) || ! is_array( $groups[ $group ] ) ) {
			continue;
		}

		foreach ( $groups[ $group ] as $value => $row ) {
			$value = sanitize_text_field( (string) $value );

			if ( ! isset( $stats[ $value ] ) ) {
				$stats[ $value ] = array(
					'hits' => 0,
					'qty'  => 0,
				);
			}

			$stats[ $value ]['hits'] += isset( $row['hits'] ) ? absint( $row['hits'] ) : 0;
			$stats[ $value ]['qty']  += isset( $row['qty'] ) ? absint( $row['qty'] ) : 0;
		}
	}

	return $stats;
}


function dn_burst_dash_is_page() {
	return function_exists( 'dn_burst_funnel_stats_is_admin_page' ) && dn_burst_funnel_stats_is_admin_page();
}

function dn_burst_dash_get_query_value( $key, $default = '' ) {
	if ( ! isset( $_GET[ $key ] ) || is_array( $_GET[ $key ] ) ) {
		return $default;
	}

	return wp_unslash( $_GET[ $key ] );
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

	$product_bases = array();

	$permalinks = function_exists( 'wc_get_permalink_structure' ) ? wc_get_permalink_structure() : array();
	if ( ! empty( $permalinks['product_rewrite_slug'] ) ) {
		$product_base = '/' . trim( $permalinks['product_rewrite_slug'], '/' );
		$product_bases[] = $product_base;
	}

	$product_bases[] = '/product';
	$product_bases[] = '/products';
	$product_bases[] = '/shop';
	$product_bases[] = '/san-pham';

	$sample_products = get_posts( array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 5,
		'fields'         => 'ids',
	) );

	foreach ( $sample_products as $product_id ) {
		$url  = get_permalink( $product_id );
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! empty( $path ) ) {
			$parts = explode( '/', trim( $path, '/' ) );
			if ( ! empty( $parts[0] ) ) {
				$product_bases[] = '/' . $parts[0];
			}
		}
	}

	$product_bases = array_values( array_unique( array_filter( array_map( 'untrailingslashit', $product_bases ) ) ) );

	return array(
		'cart'          => untrailingslashit( $cart_path ),
		'checkout'      => untrailingslashit( $checkout_path ),
		'order_pay'     => '/order-pay',
		'product_base'  => untrailingslashit( $product_base ),
		'product_bases' => $product_bases,
	);
}

function dn_burst_dash_get_range_data() {
	return function_exists( 'dn_bfs_get_range_data_from_request' )
		? dn_bfs_get_range_data_from_request()
		: array();
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
	$product_where_parts = array();
    $product_params      = array();
    
    $product_bases = isset( $paths['product_bases'] ) && is_array( $paths['product_bases'] )
    	? $paths['product_bases']
    	: array( $paths['product_base'] );
    
    foreach ( $product_bases as $base ) {
    	list( $like_a, $like_b ) = dn_burst_dash_like_path_sql( $base );
    
    	$product_where_parts[] = 'page_url LIKE %s';
    	$product_params[]      = $like_a;
    
    	$product_where_parts[] = 'page_url LIKE %s';
    	$product_params[]      = $like_b;
    }
    
    $product_visits = dn_burst_dash_burst_query_block(
    	$start,
    	$end,
    	' AND (' . implode( ' OR ', $product_where_parts ) . ')',
    	$product_params
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

function dn_burst_dash_get_counted_order_statuses() {
    if ( ! function_exists( 'wc_get_order_statuses' ) ) {
        return array();
    }

    $excluded = apply_filters(
        'dn_burst_dash_excluded_order_statuses',
        array(
            'wc-cancelled',
        )
    );

    $statuses = array_keys( wc_get_order_statuses() );
    $statuses = array_diff( $statuses, $excluded );

    return array_values( $statuses );
}
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
    $counted_statuses = dn_burst_dash_get_counted_order_statuses();

    $sales_orders = dn_burst_dash_get_orders_for_period(
        $start,
        $end,
        $counted_statuses
    );

    $pending_orders = dn_burst_dash_get_orders_for_period(
        $start,
        $end,
        array( 'wc-pending', 'wc-on-hold' )
    );

    $fulfillment_orders = $sales_orders;

    $order_count = 0;
    $gross_sales = 0;
    $net_sales = 0;
    $items_sold = 0;
    $refunds = 0;
    $shipping_total = 0;
    $paid_total = 0;
    $balance_total = 0;
    $fulfillment_total = 0;

    foreach ( $sales_orders as $order ) {
        if ( ! $order instanceof WC_Order ) {
            continue;
        }

        $order_count++;
        $gross_sales += (float) $order->get_total();
        $refunds += (float) $order->get_total_refunded();
        $shipping_total += (float) $order->get_shipping_total();
        $items_sold += (int) $order->get_item_count();
        $paid_total += (float) $order->get_total();
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

    $net_sales = max( 0, $gross_sales - $refunds );
    $aov = $order_count > 0 ? $gross_sales / $order_count : 0;
    $aoi = $order_count > 0 ? $items_sold / $order_count : 0;

    $tip_total = dn_burst_dash_sum_fee_by_keywords(
        $sales_orders,
        array( 'tip', 'tips', 'gratuity' )
    );

    $insurance_fee = dn_burst_dash_sum_fee_by_keywords(
        $sales_orders,
        array( 'insurance' )
    );

    $profit_proxy = max( 0, $net_sales - $shipping_total );

    return array(
        'orders'             => (int) $order_count,
        'gross_sales'        => (float) $gross_sales,
        'net_sales'          => (float) $net_sales,
        'items_sold'         => (int) $items_sold,
        'aov'                => (float) $aov,
        'aoi'                => (float) $aoi,
        'pending_payment'    => count( $pending_orders ),
        'tip_total'          => (float) $tip_total,
        'profit_proxy'       => (float) $profit_proxy,
        'fulfillment_orders' => count( $fulfillment_orders ),
        'fulfillment_total'  => (float) $fulfillment_total,
        'paid_total'         => (float) $paid_total,
        'balance_total'      => (float) $balance_total,
        'insurance_fee'      => (float) $insurance_fee,
        'created_campaigns'  => dn_burst_dash_get_created_products_count( $start, $end ),
    );
}
/**
 * =========================
 * Data builder
 * =========================
 */

function dn_burst_dash_build_data() {
	$range = dn_burst_dash_get_range_data();
	$cache_key = dn_burst_dash_get_cache_key( 'dash', $range );
	$cached    = dn_burst_dash_get_cached_payload( $cache_key, 5 * MINUTE_IN_SECONDS );

	if ( false !== $cached ) {
		return $cached;
	}

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

	$data = array(
		'range'         => $range,
		'paths'         => $current_burst['paths'],
		'current_burst' => $current_burst,
		'previous_burst'=> $previous_burst,
		'current_wc'    => $current_wc,
		'previous_wc'   => $previous_wc,
		'cards'         => array(
			'visits' => array(
				'title'         => __( 'Visits', 'dn-burst-funnel-stats' ),
				'main'          => $visits_current,
				'secondary'     => '',
				'compare'       => $visits_previous,
				'change'        => null,
				'icon'          => 'eye',
			),
			'product_visits' => array(
				'title'         => __( 'Product Visits', 'dn-burst-funnel-stats' ),
				'main'          => $product_visits_current,
				'secondary'     => $visits_current > 0 ? round( ( $product_visits_current / $visits_current ) * 100, 1 ) . '%' : '0%',
				'compare'       => $product_visits_previous,
				'change'        => null,
				'icon'          => 'product',
			),
			'cart' => array(
				'title'         => __( 'Cart Visits', 'dn-burst-funnel-stats' ),
				'main'          => $cart_current,
				'secondary'     => $visits_current > 0 ? round( ( $cart_current / $visits_current ) * 100, 1 ) . '%' : '0%',
				'compare'       => $cart_previous,
				'change'        => null,
				'icon'          => 'cart',
			),
			'atc_events' => array(
				'title'         => __( 'Add To Cart', 'dn-burst-funnel-stats' ),
				'main'          => $atc_hits_current,
				'secondary' => $visits_current > 0
				? round( ( $atc_hits_current / $visits_current ) * 100, 1 ) . '%'
				: '0%',
				'compare'       => sprintf(
					/* translators: %1$s: previous Add To Cart hits, %2$s: previous Add To Cart quantity. */
					__( '%1$s / Qty: %2$s', 'dn-burst-funnel-stats' ),
					$atc_hits_previous,
					$atc_qty_previous
				),
				'change'        => dn_burst_dash_format_percent_change( $atc_hits_current, $atc_hits_previous ) . '%',
				'icon'          => 'cart',
			),
			'checkout' => array(
				'title'         => __( 'Checkout', 'dn-burst-funnel-stats' ),
				'main'          => $checkout_current,
				'secondary'     => $visits_current > 0 ? round( ( $checkout_current / $visits_current ) * 100, 1 ) . '%' : '0%',
				'compare'       => $checkout_previous,
				'change'        => null,
				'icon'          => 'checkout',
			),
// 			'payment' => array(
// 				'title'         => __( 'Add Payment Info', 'dn-burst-funnel-stats' ),
// 				'main'          => $payment_current,
// 				'secondary'     => $checkout_current > 0 ? round( ( $payment_current / $checkout_current ) * 100, 1 ) . '%' : '0%',
// 				'compare'       => $payment_previous,
// 				'change'        => null,
// 				'icon'          => 'wand',
// 			),
			'orders_aov' => array(
				'title'         => __( 'Orders/AOV', 'dn-burst-funnel-stats' ),
				'main'          => $orders_current,
				'secondary'     => wc_price( $current_wc['aov'] ),
				'compare'       => $orders_previous,
				'change'        => dn_burst_dash_format_percent_change( $orders_current, $orders_previous ) . '%',
				'icon'          => 'orders',
			),
			'items_aoi' => array(
				'title'         => __( 'Items/AOI', 'dn-burst-funnel-stats' ),
				'main'          => $items_current,
				'secondary'     => round( $current_wc['aoi'], 1 ),
				'compare'       => $items_previous . ' / ' . round( $previous_wc['aoi'], 1 ),
				'change'        => null,
				'icon'          => 'box',
			),
			'conversion' => array(
				'title'         => __( 'Conversion Rate', 'dn-burst-funnel-stats' ),
				'main'          => $conversion_current . '%',
				'secondary'     => '',
				'compare'       => $conversion_previous . '%',
				'change'        => null,
				'icon'          => 'check',
			),
			'campaigns' => array(
				'title'         => __( 'Created Campaigns', 'dn-burst-funnel-stats' ),
				'main'          => $current_wc['created_campaigns'],
				'secondary'     => '',
				'compare'       => 0,
				'change'        => null,
				'icon'          => 'megaphone',
			),
// 			'pending' => array(
// 				'title'         => __( 'Pending Payment', 'dn-burst-funnel-stats' ),
// 				'main'          => $current_wc['pending_payment'],
// 				'secondary'     => '',
// 				'compare'       => $previous_wc['pending_payment'],
// 				'change'        => null,
// 				'icon'          => 'pending',
// 			),
			'sales_tip' => array(
				'title'         => __( 'Sales/Tip', 'dn-burst-funnel-stats' ),
				'main'          => wc_price( $current_wc['gross_sales'] ),
				'secondary'     => wc_price( $current_wc['tip_total'] ),
				'compare'       => wc_price( $previous_wc['gross_sales'] ),
				'change'        => $current_wc['tip_total'] > 0 ? __( 'Tip', 'dn-burst-funnel-stats' ) : '',
				'icon'          => 'dollar',
			),
// 			'profits' => array(
// 				'title'         => __( 'Profits', 'dn-burst-funnel-stats' ),
// 				'main'          => wc_price( $current_wc['profit_proxy'] ),
// 				'secondary'     => '',
// 				'compare'       => wc_price( $previous_wc['profit_proxy'] ),
// 				'change'        => null,
// 				'icon'          => 'profit',
// 			),
			'fulfillment' => array(
				'title'         => __( 'Fulfillment Orders', 'dn-burst-funnel-stats' ),
				'main'          => $current_wc['fulfillment_orders'] . ' / ' . wc_price( $current_wc['fulfillment_total'] ),
				'secondary'     => '',
				'compare'       => $previous_wc['fulfillment_orders'] . ' / ' . wc_price( $previous_wc['fulfillment_total'] ),
				'change'        => null,
				'icon'          => 'card',
			),
			'paid_balance' => array(
				'title'         => __( 'Paid/Balance', 'dn-burst-funnel-stats' ),
				'main'          => wc_price( $current_wc['paid_total'] ) . ' / ' . wc_price( $current_wc['balance_total'] ),
				'secondary'     => '',
				'compare'       => wc_price( $previous_wc['paid_total'] ),
				'change'        => null,
				'icon'          => 'money',
			),
// 			'insurance' => array(
// 				'title'         => __( 'Insurance Fee', 'dn-burst-funnel-stats' ),
// 				'main'          => wc_price( $current_wc['insurance_fee'] ),
// 				'secondary'     => '',
// 				'compare'       => '',
// 				'change'        => null,
// 				'icon'          => 'shield',
// 			),
		),
	);

	set_transient( $cache_key, dn_burst_dash_wrap_cache_payload( $data ), 5 * MINUTE_IN_SECONDS );

	return $data;
}

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

function dn_burst_dash_get_tabs() {
	return array(
		'overview'  => esc_html__( 'Overview', 'dn-burst-funnel-stats' ),
		'brands'    => esc_html__( 'Brands', 'dn-burst-funnel-stats' ),
		'ad-urls'   => esc_html__( 'Ad URLs', 'dn-burst-funnel-stats' ),
		'countries' => esc_html__( 'Countries', 'dn-burst-funnel-stats' ),
		'devices'   => esc_html__( 'Devices', 'dn-burst-funnel-stats' ),
		'products'  => esc_html__( 'Products', 'dn-burst-funnel-stats' ),
	);
}

function dn_burst_dash_sanitize_tab( $tab ) {
	$tab = sanitize_key( $tab );

	return array_key_exists( $tab, dn_burst_dash_get_tabs() ) ? $tab : 'overview';
}

function dn_burst_dash_sanitize_group( $group ) {
	$group = sanitize_key( $group );

	return in_array( $group, array( 'campaign', 'source', 'medium' ), true ) ? $group : 'campaign';
}

function dn_burst_dash_format_money( $value ) {
	return function_exists( 'wc_price' ) ? wc_price( (float) $value ) : number_format_i18n( (float) $value, 2 );
}

function dn_burst_dash_get_burst_columns() {
	global $wpdb;

	static $columns = null;

	if ( null !== $columns ) {
		return $columns;
	}

	if ( ! dn_burst_dash_burst_table_exists() ) {
		$columns = array();
		return $columns;
	}

	$table   = dn_burst_dash_get_burst_table_name();
	$results = $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A );
	$columns = array();

	foreach ( (array) $results as $row ) {
		if ( ! empty( $row['Field'] ) ) {
			$columns[] = sanitize_key( $row['Field'] );
		}
	}

	return $columns;
}

function dn_burst_dash_get_url_group_params( $group ) {
	$params = array(
		'campaign' => array( 'utm_campaign', 'campaign' ),
		'source'   => array( 'utm_source', 'source' ),
		'medium'   => array( 'utm_medium', 'medium' ),
	);

	return isset( $params[ $group ] ) ? $params[ $group ] : $params['campaign'];
}

function dn_burst_dash_get_group_value_from_url( $url, $group ) {
	$query = wp_parse_url( $url, PHP_URL_QUERY );

	if ( empty( $query ) ) {
		return 'N/A';
	}

	parse_str( $query, $params );

	foreach ( dn_burst_dash_get_url_group_params( $group ) as $param ) {
		if ( isset( $params[ $param ] ) ) {
			$value = $params[ $param ];

			if ( is_array( $value ) ) {
				$value = reset( $value );
			}

			if ( '' === trim( (string) $value ) ) {
				continue;
			}

			return sanitize_text_field( wp_unslash( (string) $value ) );
		}
	}

	return 'N/A';
}

function dn_burst_dash_is_url_path_match( $url, $path ) {
	$url_path = wp_parse_url( $url, PHP_URL_PATH );

	if ( empty( $url_path ) || empty( $path ) ) {
		return false;
	}

	$url_path = untrailingslashit( $url_path );
	$path     = untrailingslashit( $path );

	return $url_path === $path || 0 === strpos( $url_path . '/', $path . '/' );
}

function dn_burst_dash_get_burst_url_rows( $start, $end ) {
	global $wpdb;

	if ( ! dn_burst_dash_burst_table_exists() ) {
		return array();
	}

	$table = dn_burst_dash_get_burst_table_name();

	return $wpdb->get_results(
		$wpdb->prepare(
			"
			SELECT page_url, uid, session_id
			FROM {$table}
			WHERE time >= %d
				AND time <= %d
			LIMIT 50000
			",
			(int) $start,
			(int) $end
		),
		ARRAY_A
	);
}

function dn_burst_dash_get_order_group_value( $order, $group ) {
	if ( ! $order instanceof WC_Order ) {
		return 'N/A';
	}

	$meta_keys = array(
		'campaign' => array( '_wc_order_attribution_utm_campaign', '_utm_campaign', 'utm_campaign', 'campaign' ),
		'source'   => array( '_wc_order_attribution_utm_source', '_utm_source', 'utm_source', 'source' ),
		'medium'   => array( '_wc_order_attribution_utm_medium', '_utm_medium', 'utm_medium', 'medium' ),
	);

	foreach ( $meta_keys[ $group ] as $meta_key ) {
		$value = $order->get_meta( $meta_key, true );

		if ( '' !== trim( (string) $value ) ) {
			return sanitize_text_field( (string) $value );
		}
	}

	return 'N/A';
}

function dn_burst_dash_get_url_tracking_rows( $group, $range ) {
	$group = dn_burst_dash_sanitize_group( $group );
	$paths = dn_burst_dash_get_wc_paths();
	$rows  = array();

	foreach ( dn_burst_dash_get_burst_url_rows( $range['current_start'], $range['current_end'] ) as $burst_row ) {
		$url   = isset( $burst_row['page_url'] ) ? (string) $burst_row['page_url'] : '';
		$value = dn_burst_dash_get_group_value_from_url( $url, $group );

		if ( ! isset( $rows[ $value ] ) ) {
			$rows[ $value ] = array(
				'url_param'        => $value,
				'visitor_keys'     => array(),
				'cart_keys'        => array(),
				'checkout_keys'    => array(),
				'tracked_atc'      => 0,
				'orders'           => 0,
				'items'            => 0,
				'sales'            => 0,
				'profits'          => 0,
				'conversion_rate'  => 0,
			);
		}

		$visitor_key = ! empty( $burst_row['uid'] ) ? (string) $burst_row['uid'] : ( ! empty( $burst_row['session_id'] ) ? (string) $burst_row['session_id'] : md5( $url ) );

		$rows[ $value ]['visitor_keys'][ $visitor_key ] = true;

		if ( dn_burst_dash_is_url_path_match( $url, $paths['cart'] ) ) {
			$rows[ $value ]['cart_keys'][ $visitor_key ] = true;
		}

		if ( dn_burst_dash_is_url_path_match( $url, $paths['checkout'] ) ) {
			$rows[ $value ]['checkout_keys'][ $visitor_key ] = true;
		}
	}

	foreach ( dn_burst_dash_get_sales_orders_for_period( $range['current_start'], $range['current_end'] ) as $order ) {
		$value = dn_burst_dash_get_order_group_value( $order, $group );

		if ( ! isset( $rows[ $value ] ) ) {
			$rows[ $value ] = array(
				'url_param'        => $value,
				'visitor_keys'     => array(),
				'cart_keys'        => array(),
				'checkout_keys'    => array(),
				'tracked_atc'      => 0,
				'orders'           => 0,
				'items'            => 0,
				'sales'            => 0,
				'profits'          => 0,
				'conversion_rate'  => 0,
			);
		}

		$total    = (float) $order->get_total();
		$shipping = (float) $order->get_shipping_total();

		$rows[ $value ]['orders']  += 1;
		$rows[ $value ]['items']   += (int) $order->get_item_count();
		$rows[ $value ]['sales']   += $total;
		$rows[ $value ]['profits'] += max( 0, $total - (float) $order->get_total_refunded() - $shipping );
	}

	foreach ( dn_burst_dash_get_atc_url_group_stats_for_period( $range['current_start'], $range['current_end'], $group ) as $value => $atc_row ) {
		if ( ! isset( $rows[ $value ] ) ) {
			$rows[ $value ] = array(
				'url_param'        => $value,
				'visitor_keys'     => array(),
				'cart_keys'        => array(),
				'checkout_keys'    => array(),
				'tracked_atc'      => 0,
				'orders'           => 0,
				'items'            => 0,
				'sales'            => 0,
				'profits'          => 0,
				'conversion_rate'  => 0,
			);
		}

		$rows[ $value ]['tracked_atc'] += isset( $atc_row['hits'] ) ? absint( $atc_row['hits'] ) : 0;
	}

	foreach ( $rows as $key => $row ) {
		$visits = count( $row['visitor_keys'] );

		$rows[ $key ]['visits']          = $visits;
		$rows[ $key ]['add_to_cart']     = ! empty( $row['tracked_atc'] ) ? absint( $row['tracked_atc'] ) : count( $row['cart_keys'] );
		$rows[ $key ]['checkout']        = count( $row['checkout_keys'] );
		$rows[ $key ]['conversion_rate'] = $visits > 0 ? round( ( (int) $row['orders'] / $visits ) * 100, 2 ) : 0;

		unset( $rows[ $key ]['visitor_keys'], $rows[ $key ]['cart_keys'], $rows[ $key ]['checkout_keys'], $rows[ $key ]['tracked_atc'] );
	}

	return array_values( $rows );
}

function dn_burst_dash_sort_rows( $rows, $orderby, $order ) {
	$orderby = sanitize_key( $orderby );
	$order   = 'asc' === strtolower( $order ) ? 'asc' : 'desc';

	usort(
		$rows,
		function( $a, $b ) use ( $orderby, $order ) {
			$a_value = isset( $a[ $orderby ] ) ? $a[ $orderby ] : '';
			$b_value = isset( $b[ $orderby ] ) ? $b[ $orderby ] : '';

			if ( is_numeric( $a_value ) && is_numeric( $b_value ) ) {
				$result = (float) $a_value <=> (float) $b_value;
			} else {
				$result = strcasecmp( (string) $a_value, (string) $b_value );
			}

			return 'asc' === $order ? $result : -$result;
		}
	);

	return $rows;
}

function dn_burst_dash_get_sort_url( $column, $current_orderby, $current_order, $extra = array() ) {
	$order = ( $column === $current_orderby && 'asc' === $current_order ) ? 'desc' : 'asc';

	return add_query_arg(
		array_merge(
			$extra,
			array(
				'orderby' => sanitize_key( $column ),
				'order'   => $order,
			)
		)
	);
}

function dn_burst_dash_render_url_tracking_table( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'group'    => 'campaign',
			'range'    => dn_burst_dash_get_range_data(),
			'orderby'  => 'visits',
			'order'    => 'desc',
			'paged'    => 1,
			'per_page' => 25,
		)
	);

	$group    = dn_burst_dash_sanitize_group( $args['group'] );
	$orderby  = sanitize_key( $args['orderby'] );
	$order    = 'asc' === strtolower( $args['order'] ) ? 'asc' : 'desc';
	$paged    = max( 1, absint( $args['paged'] ) );
	$per_page = max( 1, min( 100, absint( $args['per_page'] ) ) );
	$rows     = dn_burst_dash_sort_rows( dn_burst_dash_get_url_tracking_rows( $group, $args['range'] ), $orderby, $order );
	$total    = count( $rows );
	$pages    = max( 1, (int) ceil( $total / $per_page ) );
	$paged    = min( $paged, $pages );
	$rows     = array_slice( $rows, ( $paged - 1 ) * $per_page, $per_page );
	$columns  = array(
		'url_param'       => esc_html__( 'URL Param', 'dn-burst-funnel-stats' ),
		'visits'          => esc_html__( 'Visits', 'dn-burst-funnel-stats' ),
		'add_to_cart'     => esc_html__( 'Add To Cart', 'dn-burst-funnel-stats' ),
		'checkout'        => esc_html__( 'Checkout', 'dn-burst-funnel-stats' ),
		'orders'          => esc_html__( 'Orders', 'dn-burst-funnel-stats' ),
		'items'           => esc_html__( 'Items', 'dn-burst-funnel-stats' ),
		'sales'           => esc_html__( 'Sales', 'dn-burst-funnel-stats' ),
		'profits'         => esc_html__( 'Profits', 'dn-burst-funnel-stats' ),
		'conversion_rate' => esc_html__( 'Conversion Rate', 'dn-burst-funnel-stats' ),
	);
	?>
	<div class="dn-burst-table-wrap" data-dn-url-table data-group="<?php echo esc_attr( $group ); ?>">
		<table class="widefat striped dn-burst-data-table">
			<thead>
				<tr>
					<?php foreach ( $columns as $column_key => $label ) : ?>
						<th scope="col">
							<a href="<?php echo esc_url( dn_burst_dash_get_sort_url( $column_key, $orderby, $order, array( 'dn_group' => $group ) ) ); ?>" data-dn-sort="<?php echo esc_attr( $column_key ); ?>">
								<?php echo esc_html( $label ); ?>
								<?php if ( $column_key === $orderby ) : ?>
									<span class="screen-reader-text">
										<?php echo 'asc' === $order ? esc_html__( 'ascending', 'dn-burst-funnel-stats' ) : esc_html__( 'descending', 'dn-burst-funnel-stats' ); ?>
									</span>
								<?php endif; ?>
							</a>
						</th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="<?php echo esc_attr( count( $columns ) ); ?>"><?php esc_html_e( 'No URL tracking data is available for this period.', 'dn-burst-funnel-stats' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['url_param'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['visits'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['add_to_cart'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['checkout'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['orders'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['items'] ) ); ?></td>
							<td><?php echo wp_kses_post( dn_burst_dash_format_money( $row['sales'] ) ); ?></td>
							<td><?php echo wp_kses_post( dn_burst_dash_format_money( $row['profits'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['conversion_rate'], 2 ) . '%' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<span class="displaying-num">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of rows. */
								_n( '%d item', '%d items', $total, 'dn-burst-funnel-stats' ),
								$total
							)
						);
						?>
					</span>
					<?php if ( $paged > 1 ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'paged' => $paged - 1 ) ) ); ?>" data-dn-page="<?php echo esc_attr( $paged - 1 ); ?>"><?php esc_html_e( 'Previous', 'dn-burst-funnel-stats' ); ?></a>
					<?php endif; ?>
					<span class="paging-input"><?php echo esc_html( $paged . ' / ' . $pages ); ?></span>
					<?php if ( $paged < $pages ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'paged' => $paged + 1 ) ) ); ?>" data-dn-page="<?php echo esc_attr( $paged + 1 ); ?>"><?php esc_html_e( 'Next', 'dn-burst-funnel-stats' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

function dn_burst_dash_render_ad_urls_tab( $range = null ) {
	$range = is_array( $range ) ? $range : dn_burst_dash_get_range_data();
	$group = dn_burst_dash_sanitize_group( dn_burst_dash_get_query_value( 'dn_group', 'campaign' ) );
	?>
	<div class="dn-burst-panel dn-burst-panel-toolbar">
		<div>
			<h2><?php esc_html_e( 'Ad URLs', 'dn-burst-funnel-stats' ); ?></h2>
			<p class="description"><?php esc_html_e( 'URL parameter performance grouped by campaign, source, or medium.', 'dn-burst-funnel-stats' ); ?></p>
		</div>
		<fieldset class="dn-burst-radio-group" data-dn-url-group>
			<legend class="screen-reader-text"><?php esc_html_e( 'Group URL tracking data by', 'dn-burst-funnel-stats' ); ?></legend>
			<label><input type="radio" name="dn_group" value="campaign" <?php checked( 'campaign', $group ); ?> /> <?php esc_html_e( 'Campaign', 'dn-burst-funnel-stats' ); ?></label>
			<label><input type="radio" name="dn_group" value="source" <?php checked( 'source', $group ); ?> /> <?php esc_html_e( 'Source', 'dn-burst-funnel-stats' ); ?></label>
			<label><input type="radio" name="dn_group" value="medium" <?php checked( 'medium', $group ); ?> /> <?php esc_html_e( 'Medium', 'dn-burst-funnel-stats' ); ?></label>
		</fieldset>
	</div>

	<div data-dn-url-table-region>
		<?php
		dn_burst_dash_render_url_tracking_table(
			array(
				'group'   => $group,
				'range'   => $range,
				'orderby' => sanitize_key( dn_burst_dash_get_query_value( 'orderby', 'visits' ) ),
				'order'   => sanitize_key( dn_burst_dash_get_query_value( 'order', 'desc' ) ),
				'paged'   => absint( dn_burst_dash_get_query_value( 'paged', 1 ) ),
			)
		);
		?>
	</div>
	<?php
}

function dn_burst_dash_get_dashboard_simple_rows( $tab, $range ) {
	$rows = array();

	if ( 'countries' === $tab ) {
		foreach ( dn_burst_dash_get_sales_orders_for_period( $range['current_start'], $range['current_end'] ) as $order ) {
			$key = $order->get_billing_country() ? $order->get_billing_country() : 'N/A';

			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array( 'name' => $key, 'orders' => 0, 'items' => 0, 'sales' => 0 );
			}

			$rows[ $key ]['orders']++;
			$rows[ $key ]['items'] += (int) $order->get_item_count();
			$rows[ $key ]['sales'] += (float) $order->get_total();
		}
	} elseif ( 'products' === $tab || 'brands' === $tab ) {
		foreach ( dn_burst_dash_get_sales_orders_for_period( $range['current_start'], $range['current_end'] ) as $order ) {
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				$name    = $item->get_name();

				if ( 'brands' === $tab ) {
					$name = esc_html__( 'Unassigned', 'dn-burst-funnel-stats' );

					if ( $product ) {
						$terms = wp_get_post_terms( $product->get_id(), array( 'product_brand', 'pa_brand' ), array( 'fields' => 'names' ) );
						if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
							$name = (string) reset( $terms );
						}
					}
				}

				if ( ! isset( $rows[ $name ] ) ) {
					$rows[ $name ] = array( 'name' => $name, 'orders' => 0, 'items' => 0, 'sales' => 0 );
				}

				$rows[ $name ]['orders']++;
				$rows[ $name ]['items'] += (int) $item->get_quantity();
				$rows[ $name ]['sales'] += (float) $item->get_total();
			}
		}
	} elseif ( 'devices' === $tab && dn_burst_dash_burst_table_exists() ) {
		global $wpdb;

		$columns       = dn_burst_dash_get_burst_columns();
		$device_column = '';

		foreach ( array( 'device_type', 'device', 'browser' ) as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) {
				$device_column = $candidate;
				break;
			}
		}

		if ( $device_column ) {
			$table   = dn_burst_dash_get_burst_table_name();
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"
					SELECT {$device_column} AS name, COUNT(DISTINCT uid) AS visits
					FROM {$table}
					WHERE time >= %d AND time <= %d
					GROUP BY {$device_column}
					ORDER BY visits DESC
					LIMIT 50
					",
					$range['current_start'],
					$range['current_end']
				),
				ARRAY_A
			);

			foreach ( (array) $results as $result ) {
				$rows[] = array(
					'name'   => ! empty( $result['name'] ) ? $result['name'] : 'N/A',
					'visits' => (int) $result['visits'],
				);
			}
		}
	}

	if ( array_keys( $rows ) !== range( 0, count( $rows ) - 1 ) ) {
		$rows = array_values( $rows );
	}

	return dn_burst_dash_sort_rows( $rows, 'sales', 'desc' );
}

function dn_burst_dash_render_simple_tab( $tab, $range ) {
	$tabs  = dn_burst_dash_get_tabs();
	$rows  = dn_burst_dash_get_dashboard_simple_rows( $tab, $range );
	$title = isset( $tabs[ $tab ] ) ? $tabs[ $tab ] : esc_html__( 'Report', 'dn-burst-funnel-stats' );
	?>
	<div class="dn-burst-panel">
		<h2><?php echo esc_html( $title ); ?></h2>
		<table class="widefat striped dn-burst-data-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'dn-burst-funnel-stats' ); ?></th>
					<?php if ( 'devices' === $tab ) : ?>
						<th scope="col"><?php esc_html_e( 'Visits', 'dn-burst-funnel-stats' ); ?></th>
					<?php else : ?>
						<th scope="col"><?php esc_html_e( 'Orders', 'dn-burst-funnel-stats' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Items', 'dn-burst-funnel-stats' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Sales', 'dn-burst-funnel-stats' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="<?php echo esc_attr( 'devices' === $tab ? 2 : 4 ); ?>"><?php esc_html_e( 'No data is available for this period.', 'dn-burst-funnel-stats' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<?php if ( 'devices' === $tab ) : ?>
								<td><?php echo esc_html( number_format_i18n( $row['visits'] ) ); ?></td>
							<?php else : ?>
								<td><?php echo esc_html( number_format_i18n( $row['orders'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row['items'] ) ); ?></td>
								<td><?php echo wp_kses_post( dn_burst_dash_format_money( $row['sales'] ) ); ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}

function dn_burst_dash_get_date_keys_for_range( $range ) {
	$tz       = wp_timezone();
	$start_dt = ( new DateTimeImmutable( '@' . (int) $range['current_start'] ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
	$end_dt   = ( new DateTimeImmutable( '@' . (int) $range['current_end'] ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
	$keys     = array();

	for ( $cursor = $start_dt; $cursor <= $end_dt; $cursor = $cursor->modify( '+1 day' ) ) {
		$keys[ $cursor->format( 'Y-m-d' ) ] = array(
			'label' => $cursor->format( 'M j' ),
			'ymd'   => $cursor->format( 'Y_m_d' ),
		);
	}

	return $keys;
}

function dn_burst_dash_get_daily_burst_visits( $range ) {
	global $wpdb;

	$daily = array();

	if ( ! dn_burst_dash_burst_table_exists() ) {
		return $daily;
	}

	$table = dn_burst_dash_get_burst_table_name();
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"
			SELECT DATE(FROM_UNIXTIME(time)) AS day_key, COUNT(DISTINCT uid) AS visits
			FROM {$table}
			WHERE time >= %d AND time <= %d
			GROUP BY day_key
			",
			$range['current_start'],
			$range['current_end']
		),
		ARRAY_A
	);

	foreach ( (array) $rows as $row ) {
		if ( ! empty( $row['day_key'] ) ) {
			$daily[ $row['day_key'] ] = (int) $row['visits'];
		}
	}

	return $daily;
}

function dn_burst_dash_get_daily_order_stats( $range ) {
	$daily = array();

	foreach ( dn_burst_dash_get_sales_orders_for_period( $range['current_start'], $range['current_end'] ) as $order ) {
		if ( ! $order instanceof WC_Order || ! $order->get_date_created() ) {
			continue;
		}

		$key = $order->get_date_created()->date_i18n( 'Y-m-d' );

		if ( ! isset( $daily[ $key ] ) ) {
			$daily[ $key ] = array(
				'orders'  => 0,
				'sales'   => 0,
				'profits' => 0,
			);
		}

		$total = (float) $order->get_total();

		$daily[ $key ]['orders']++;
		$daily[ $key ]['sales']   += max( 0, $total - (float) $order->get_total_refunded() );
		$daily[ $key ]['profits'] += max( 0, $total - (float) $order->get_total_refunded() - (float) $order->get_shipping_total() );
	}

	return $daily;
}

function dn_burst_dash_get_chart_data( $range = null ) {
	$range     = is_array( $range ) ? $range : dn_burst_dash_get_range_data();
	$cache_key = dn_burst_dash_get_cache_key( 'charts', $range );
	$cached    = dn_burst_dash_get_cached_payload( $cache_key, 5 * MINUTE_IN_SECONDS );

	if ( false !== $cached ) {
		return $cached;
	}

	$date_keys   = dn_burst_dash_get_date_keys_for_range( $range );
	$visits_by_day = dn_burst_dash_get_daily_burst_visits( $range );
	$orders_by_day = dn_burst_dash_get_daily_order_stats( $range );
	$labels      = array();
	$sales       = array();
	$profits     = array();
	$orders      = array();
	$conversions = array();
	$daily_visits = array();

	foreach ( $date_keys as $day_key => $date_data ) {
		$visits     = isset( $visits_by_day[ $day_key ] ) ? (int) $visits_by_day[ $day_key ] : 0;
		$order_data = isset( $orders_by_day[ $day_key ] ) ? $orders_by_day[ $day_key ] : array( 'orders' => 0, 'sales' => 0, 'profits' => 0 );

		$labels[]      = $date_data['label'];
		$sales[]       = round( (float) $order_data['sales'], 2 );
		$profits[]     = round( (float) $order_data['profits'], 2 );
		$orders[]      = (int) $order_data['orders'];
		$daily_visits[] = $visits;
		$conversions[] = $visits > 0 ? round( ( (int) $order_data['orders'] / $visits ) * 100, 2 ) : 0;
	}

	$data       = dn_burst_dash_build_data();
	$top_rows   = array_slice( dn_burst_dash_sort_rows( dn_burst_dash_get_url_tracking_rows( 'campaign', $range ), 'sales', 'desc' ), 0, 8 );
	$top_labels = array();
	$top_sales  = array();

	foreach ( $top_rows as $row ) {
		$top_labels[] = (string) $row['url_param'];
		$top_sales[]  = round( (float) $row['sales'], 2 );
	}

	$chart_data = array(
		'labels'     => $labels,
		'sales'      => array(
			'labels'   => $labels,
			'format'   => 'money',
			'netSales' => $sales,
			'profits'  => $profits,
			'orders'   => $orders,
			'series'   => array(
				array(
					'label'  => esc_html__( 'Net sales', 'dn-burst-funnel-stats' ),
					'values' => $sales,
					'color'  => '#2271b1',
					'format' => 'money',
					'axis'   => 'left',
				),
				array(
					'label'  => esc_html__( 'Profit', 'dn-burst-funnel-stats' ),
					'values' => $profits,
					'color'  => '#00a32a',
					'format' => 'money',
					'axis'   => 'left',
				),
				array(
					'label'  => esc_html__( 'Orders', 'dn-burst-funnel-stats' ),
					'values' => $orders,
					'color'  => '#7f54b3',
					'format' => 'integer',
					'axis'   => 'right',
				),
			),
		),
		'funnel'     => array(
			'format' => 'integer',
			'labels' => array(
				esc_html__( 'Visits', 'dn-burst-funnel-stats' ),
				esc_html__( 'Add To Cart', 'dn-burst-funnel-stats' ),
				esc_html__( 'Checkout', 'dn-burst-funnel-stats' ),
				esc_html__( 'Orders', 'dn-burst-funnel-stats' ),
			),
			'values' => array(
				(int) $data['cards']['visits']['main'],
				(int) $data['cards']['atc_events']['main'],
				(int) $data['cards']['checkout']['main'],
				(int) $data['current_wc']['orders'],
			),
		),
		'conversion' => array(
			'labels' => $labels,
			'values' => $conversions,
			'format' => 'percent',
			'series' => array(
				array(
					'label'  => esc_html__( 'Conversion rate', 'dn-burst-funnel-stats' ),
					'values' => $conversions,
					'color'  => '#d63638',
					'format' => 'percent',
					'axis'   => 'left',
				),
			),
		),
		'topUrls'    => array(
			'labels' => $top_labels,
			'values' => $top_sales,
			'format' => 'money',
			'series' => array(
				array(
					'label'  => esc_html__( 'Sales', 'dn-burst-funnel-stats' ),
					'values' => $top_sales,
					'color'  => '#2271b1',
					'format' => 'money',
					'axis'   => 'left',
				),
			),
		),
	);

	set_transient( $cache_key, dn_burst_dash_wrap_cache_payload( $chart_data ), 5 * MINUTE_IN_SECONDS );

	return $chart_data;
}

function dn_burst_dash_chart_series_total( $series ) {
	$total = 0;

	foreach ( (array) $series as $value ) {
		$total += (float) $value;
	}

	return $total;
}

function dn_burst_dash_format_chart_value( $value, $format = 'integer' ) {
	if ( 'money' === $format ) {
		return dn_burst_dash_format_money( $value );
	}

	if ( 'percent' === $format ) {
		return number_format_i18n( (float) $value, 1 ) . '%';
	}

	return number_format_i18n( (float) $value );
}

function dn_burst_dash_get_chart_subtitle( $type ) {
	$subtitles = array(
		'sales'      => esc_html__( 'Net sales, profit, and order volume for the selected range.', 'dn-burst-funnel-stats' ),
		'funnel'     => esc_html__( 'Step-by-step movement from visits through completed orders.', 'dn-burst-funnel-stats' ),
		'conversion' => esc_html__( 'Daily order conversion from tracked visits.', 'dn-burst-funnel-stats' ),
		'top-sales'  => esc_html__( 'Campaign URL parameters ranked by WooCommerce sales.', 'dn-burst-funnel-stats' ),
	);

	return isset( $subtitles[ $type ] ) ? $subtitles[ $type ] : '';
}

function dn_burst_dash_get_chart_total( $type, $chart_data ) {
	if ( 'sales' === $type && isset( $chart_data['netSales'] ) ) {
		return dn_burst_dash_format_money( dn_burst_dash_chart_series_total( $chart_data['netSales'] ) );
	}

	if ( 'conversion' === $type && ! empty( $chart_data['values'] ) ) {
		$values = array_map( 'floatval', (array) $chart_data['values'] );
		$count  = count( $values );

		return $count > 0 ? dn_burst_dash_format_chart_value( array_sum( $values ) / $count, 'percent' ) : '';
	}

	if ( 'top-sales' === $type && isset( $chart_data['values'] ) ) {
		return dn_burst_dash_format_money( dn_burst_dash_chart_series_total( $chart_data['values'] ) );
	}

	if ( 'funnel' === $type && ! empty( $chart_data['values'] ) ) {
		$values = array_values( (array) $chart_data['values'] );

		return dn_burst_dash_format_chart_value( end( $values ), 'integer' );
	}

	return '';
}

function dn_burst_dash_get_chart_total_label( $type ) {
	$labels = array(
		'sales'      => esc_html__( 'Total sales', 'dn-burst-funnel-stats' ),
		'funnel'     => esc_html__( 'Orders', 'dn-burst-funnel-stats' ),
		'conversion' => esc_html__( 'Avg. rate', 'dn-burst-funnel-stats' ),
		'top-sales'  => esc_html__( 'Campaign sales', 'dn-burst-funnel-stats' ),
	);

	return isset( $labels[ $type ] ) ? $labels[ $type ] : '';
}

function dn_burst_dash_chart_has_values( $chart_data ) {
	$values = array();

	if ( ! empty( $chart_data['series'] ) ) {
		foreach ( (array) $chart_data['series'] as $series ) {
			$values = array_merge( $values, isset( $series['values'] ) ? (array) $series['values'] : array() );
		}
	} elseif ( isset( $chart_data['values'] ) ) {
		$values = (array) $chart_data['values'];
	}

	foreach ( $values as $value ) {
		if ( (float) $value > 0 ) {
			return true;
		}
	}

	return false;
}

function dn_burst_dash_normalize_chart_payload( $type, $chart_data ) {
	$chart_data = is_array( $chart_data ) ? $chart_data : array();

	if ( ! empty( $chart_data['series'] ) ) {
		return $chart_data;
	}

	if ( 'sales' === $type ) {
		$chart_data['format'] = 'money';
		$chart_data['series'] = array();

		if ( isset( $chart_data['netSales'] ) ) {
			$chart_data['series'][] = array(
				'label'  => esc_html__( 'Net sales', 'dn-burst-funnel-stats' ),
				'values' => $chart_data['netSales'],
				'color'  => '#2271b1',
				'format' => 'money',
				'axis'   => 'left',
			);
		}

		if ( isset( $chart_data['profits'] ) ) {
			$chart_data['series'][] = array(
				'label'  => esc_html__( 'Profit', 'dn-burst-funnel-stats' ),
				'values' => $chart_data['profits'],
				'color'  => '#00a32a',
				'format' => 'money',
				'axis'   => 'left',
			);
		}

		if ( isset( $chart_data['orders'] ) ) {
			$chart_data['series'][] = array(
				'label'  => esc_html__( 'Orders', 'dn-burst-funnel-stats' ),
				'values' => $chart_data['orders'],
				'color'  => '#7f54b3',
				'format' => 'integer',
				'axis'   => 'right',
			);
		}
	}

	if ( 'conversion' === $type && isset( $chart_data['values'] ) ) {
		$chart_data['format'] = 'percent';
		$chart_data['series'] = array(
			array(
				'label'  => esc_html__( 'Conversion rate', 'dn-burst-funnel-stats' ),
				'values' => $chart_data['values'],
				'color'  => '#d63638',
				'format' => 'percent',
				'axis'   => 'left',
			),
		);
	}

	if ( 'top-sales' === $type && isset( $chart_data['values'] ) ) {
		$chart_data['format'] = 'money';
		$chart_data['series'] = array(
			array(
				'label'  => esc_html__( 'Sales', 'dn-burst-funnel-stats' ),
				'values' => $chart_data['values'],
				'color'  => '#2271b1',
				'format' => 'money',
				'axis'   => 'left',
			),
		);
	}

	return $chart_data;
}

function dn_burst_dash_render_chart_legend( $type, $chart_data ) {
	if ( 'funnel' === $type ) {
		$labels = isset( $chart_data['labels'] ) ? array_values( (array) $chart_data['labels'] ) : array();
		$values = isset( $chart_data['values'] ) ? array_values( (array) $chart_data['values'] ) : array();
		$base   = isset( $values[0] ) ? max( 1, (float) $values[0] ) : 1;
		$colors = array( '#2271b1', '#00a32a', '#dba617', '#7f54b3' );
		?>
		<div class="dn-burst-funnel-legend" aria-label="<?php echo esc_attr__( 'Funnel step details', 'dn-burst-funnel-stats' ); ?>">
			<?php foreach ( $labels as $index => $label ) : ?>
				<?php
				$value          = isset( $values[ $index ] ) ? (float) $values[ $index ] : 0;
				$previous_value = $index > 0 && isset( $values[ $index - 1 ] ) ? (float) $values[ $index - 1 ] : $base;
				$step_rate      = $previous_value > 0 ? ( $value / $previous_value ) * 100 : 0;
				$visit_rate     = $base > 0 ? ( $value / $base ) * 100 : 0;
				?>
				<div class="dn-burst-funnel-legend-row">
					<span class="dn-burst-chart-legend-label">
						<span class="dn-burst-chart-legend-dot" style="background-color: <?php echo esc_attr( $colors[ $index % count( $colors ) ] ); ?>"></span>
						<span><?php echo esc_html( $label ); ?></span>
					</span>
					<strong><?php echo esc_html( number_format_i18n( $value ) ); ?></strong>
					<span><?php echo esc_html( sprintf( __( '%1$s step, %2$s of visits', 'dn-burst-funnel-stats' ), number_format_i18n( $step_rate, 1 ) . '%', number_format_i18n( $visit_rate, 1 ) . '%' ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return;
	}

	$series = ! empty( $chart_data['series'] ) ? (array) $chart_data['series'] : array(
		array(
			'label'  => isset( $chart_data['label'] ) ? $chart_data['label'] : '',
			'values' => isset( $chart_data['values'] ) ? $chart_data['values'] : array(),
			'color'  => '#2271b1',
			'format' => isset( $chart_data['format'] ) ? $chart_data['format'] : 'integer',
		),
	);
	?>
	<div class="dn-burst-chart-legend" aria-label="<?php echo esc_attr__( 'Chart legend', 'dn-burst-funnel-stats' ); ?>">
		<?php foreach ( $series as $row ) : ?>
			<?php
			$label  = isset( $row['label'] ) ? (string) $row['label'] : '';
			$format = isset( $row['format'] ) ? (string) $row['format'] : ( isset( $chart_data['format'] ) ? (string) $chart_data['format'] : 'integer' );
			$total  = dn_burst_dash_chart_series_total( isset( $row['values'] ) ? $row['values'] : array() );
			$color  = isset( $row['color'] ) ? (string) $row['color'] : '#2271b1';
			?>
			<div class="dn-burst-chart-legend-row">
				<span class="dn-burst-chart-legend-label">
					<span class="dn-burst-chart-legend-dot" style="background-color: <?php echo esc_attr( $color ); ?>"></span>
					<span><?php echo esc_html( $label ); ?></span>
				</span>
				<strong><?php echo wp_kses_post( dn_burst_dash_format_chart_value( $total, $format ) ); ?></strong>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

function dn_burst_dash_render_chart_panel( $title, $type, $chart_data ) {
	$chart_data         = dn_burst_dash_normalize_chart_payload( $type, $chart_data );
	$chart_data['type'] = $type;
	$total              = dn_burst_dash_get_chart_total( $type, $chart_data );
	$total_label        = dn_burst_dash_get_chart_total_label( $type );
	$subtitle           = dn_burst_dash_get_chart_subtitle( $type );
	$has_values         = dn_burst_dash_chart_has_values( $chart_data );
	?>
	<div class="dn-burst-panel dn-burst-chart-panel <?php echo $has_values ? '' : 'is-empty'; ?>" data-dn-chart-panel>
		<div class="dn-burst-chart-header">
			<div>
				<h2 class="dn-burst-chart-title"><?php echo esc_html( $title ); ?></h2>
				<?php if ( '' !== $subtitle ) : ?>
					<p class="dn-burst-chart-subtitle"><?php echo esc_html( $subtitle ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( '' !== $total ) : ?>
				<div class="dn-burst-chart-total">
					<span><?php echo esc_html( $total_label ); ?></span>
					<strong><?php echo wp_kses_post( $total ); ?></strong>
				</div>
			<?php endif; ?>
		</div>
		<div class="dn-burst-chart-body">
			<canvas
				class="dn-burst-chart"
				height="250"
				data-dn-chart="<?php echo esc_attr( $type ); ?>"
				data-chart="<?php echo esc_attr( wp_json_encode( $chart_data ) ); ?>"
			></canvas>
			<p class="dn-burst-chart-empty" <?php echo $has_values ? 'hidden' : ''; ?>><?php esc_html_e( 'No chart data is available for this period yet.', 'dn-burst-funnel-stats' ); ?></p>
			<div class="dn-burst-chart-tooltip" role="status" aria-live="polite" hidden></div>
		</div>
		<?php dn_burst_dash_render_chart_legend( $type, $chart_data ); ?>
	</div>
	<?php
}

function dn_burst_dash_render_charts( $range ) {
	$chart_data = dn_burst_dash_get_chart_data( $range );
	$sales      = isset( $chart_data['sales'] ) ? $chart_data['sales'] : array();

	if ( empty( $sales['labels'] ) && ! empty( $chart_data['labels'] ) ) {
		$sales['labels'] = $chart_data['labels'];
	}
	?>
	<div class="dn-burst-chart-grid">
		<?php dn_burst_dash_render_chart_panel( esc_html__( 'Sales / Orders', 'dn-burst-funnel-stats' ), 'sales', $sales ); ?>
		<?php dn_burst_dash_render_chart_panel( esc_html__( 'Funnel', 'dn-burst-funnel-stats' ), 'funnel', $chart_data['funnel'] ); ?>
		<?php dn_burst_dash_render_chart_panel( esc_html__( 'Conversion Rate', 'dn-burst-funnel-stats' ), 'conversion', $chart_data['conversion'] ); ?>
		<?php dn_burst_dash_render_chart_panel( esc_html__( 'Top Campaigns by Sales', 'dn-burst-funnel-stats' ), 'top-sales', $chart_data['topUrls'] ); ?>
	</div>
	<?php
}

function dn_burst_dash_render_date_picker( $range, $page_slug, $tab = 'overview' ) {
	$presets = function_exists( 'dn_bfs_get_date_presets' ) ? dn_bfs_get_date_presets() : array();
	?>
	<div class="dn-burst-date-control" data-dn-date-control>
		<button type="button" class="button dn-burst-date-toggle" data-dn-date-toggle>
			<span class="dn-burst-date-title">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %1$s: preset label, %2$s: formatted date range. */
						__( '%1$s (%2$s)', 'dn-burst-funnel-stats' ),
						$range['current_label'],
						$range['current_range_label']
					)
				);
				?>
			</span>
			<?php if ( 'none' !== $range['compare'] ) : ?>
				<span class="dn-burst-date-compare"><?php echo esc_html( $range['compare_label'] . ' (' . $range['previous_range_label'] . ')' ); ?></span>
			<?php endif; ?>
		</button>

		<form method="get" class="dn-burst-date-popover" data-dn-date-popover hidden>
			<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
			<input type="hidden" name="dn_tab" value="<?php echo esc_attr( $tab ); ?>" />
			<h2><?php esc_html_e( 'Select a date range', 'dn-burst-funnel-stats' ); ?></h2>
			<div class="dn-burst-date-tabs">
				<button type="button" class="button is-active" data-dn-date-mode="presets"><?php esc_html_e( 'Presets', 'dn-burst-funnel-stats' ); ?></button>
				<button type="button" class="button" data-dn-date-mode="custom"><?php esc_html_e( 'Custom', 'dn-burst-funnel-stats' ); ?></button>
			</div>

			<div class="dn-burst-date-pane is-active" data-dn-date-pane="presets">
				<?php foreach ( $presets as $period_key => $period_label ) : ?>
					<?php if ( 'custom' === $period_key ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<label>
						<input type="radio" name="dn_period" value="<?php echo esc_attr( $period_key ); ?>" <?php checked( $range['period'], $period_key ); ?> />
						<?php echo esc_html( $period_label ); ?>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="dn-burst-date-pane" data-dn-date-pane="custom">
				<label class="dn-burst-custom-radio">
					<input type="radio" name="dn_period" value="custom" <?php checked( $range['period'], 'custom' ); ?> />
					<?php esc_html_e( 'Custom range', 'dn-burst-funnel-stats' ); ?>
				</label>
				<label>
					<?php esc_html_e( 'Start date', 'dn-burst-funnel-stats' ); ?>
					<input type="date" name="dn_start" value="<?php echo esc_attr( $range['custom_start'] ); ?>" />
				</label>
				<label>
					<?php esc_html_e( 'End date', 'dn-burst-funnel-stats' ); ?>
					<input type="date" name="dn_end" value="<?php echo esc_attr( $range['custom_end'] ); ?>" />
				</label>
			</div>

			<fieldset class="dn-burst-compare-options">
				<legend><?php esc_html_e( 'Compare', 'dn-burst-funnel-stats' ); ?></legend>
				<label><input type="radio" name="dn_compare" value="previous_period" <?php checked( $range['compare'], 'previous_period' ); ?> /> <?php esc_html_e( 'Previous period', 'dn-burst-funnel-stats' ); ?></label>
				<label><input type="radio" name="dn_compare" value="previous_year" <?php checked( $range['compare'], 'previous_year' ); ?> /> <?php esc_html_e( 'Previous year', 'dn-burst-funnel-stats' ); ?></label>
				<label><input type="radio" name="dn_compare" value="none" <?php checked( $range['compare'], 'none' ); ?> /> <?php esc_html_e( 'None', 'dn-burst-funnel-stats' ); ?></label>
			</fieldset>

			<button type="submit" class="button button-primary" data-dn-date-update><?php esc_html_e( 'Update', 'dn-burst-funnel-stats' ); ?></button>
		</form>
	</div>
	<?php
}

function dn_burst_dash_get_dashboard_url( $page_slug, $tab, $range ) {
	$args = array(
		'page'       => sanitize_key( $page_slug ),
		'dn_tab'     => sanitize_key( $tab ),
		'dn_period'  => sanitize_key( $range['period'] ),
		'dn_compare' => sanitize_key( $range['compare'] ),
	);

	if ( 'custom' === $range['period'] ) {
		$args['dn_start'] = sanitize_text_field( $range['custom_start'] );
		$args['dn_end']   = sanitize_text_field( $range['custom_end'] );
	}

	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

function dn_burst_dash_get_data_status() {
	$last_update = (int) get_option( 'dn_burst_funnel_stats_last_refresh', 0 );
	$next_update = wp_next_scheduled( 'dn_burst_funnel_stats_refresh_cache' );

	return array(
		'last_update' => $last_update,
		'next_update' => $next_update,
	);
}

function dn_burst_dash_render_data_status_panel( $compact = false ) {
	$status = dn_burst_dash_get_data_status();

	if ( $compact ) {
		?>
		<div class="dn-burst-data-status" data-dn-status-panel>
			<div class="dn-burst-data-status-card">
				<div class="dn-burst-data-status-item">
					<span><?php esc_html_e( 'Last updated', 'dn-burst-funnel-stats' ); ?></span>
					<strong data-dn-last-update>
						<?php echo $status['last_update'] ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['last_update'] ) ) : esc_html__( 'Not refreshed yet', 'dn-burst-funnel-stats' ); ?>
					</strong>
				</div>
				<div class="dn-burst-data-status-item">
					<span><?php esc_html_e( 'Next update', 'dn-burst-funnel-stats' ); ?></span>
					<strong data-dn-next-update>
						<?php echo $status['next_update'] ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['next_update'] ) ) : esc_html__( 'Not scheduled', 'dn-burst-funnel-stats' ); ?>
					</strong>
				</div>
				<button type="button" class="button dn-burst-data-status-button" data-dn-update-now><?php esc_html_e( 'Update now', 'dn-burst-funnel-stats' ); ?></button>
			</div>
			<div class="dn-burst-status-message" data-dn-status-message aria-live="polite"></div>
		</div>
		<?php
		return;
	}
	?>
	<div class="dn-burst-panel dn-burst-status-panel" data-dn-status-panel>
		<div>
			<h2><?php esc_html_e( 'Data status', 'dn-burst-funnel-stats' ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'Last updated', 'dn-burst-funnel-stats' ); ?>:</strong>
				<span data-dn-last-update>
					<?php echo $status['last_update'] ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['last_update'] ) ) : esc_html__( 'Not refreshed yet', 'dn-burst-funnel-stats' ); ?>
				</span>
			</p>
			<p>
				<strong><?php esc_html_e( 'Next update', 'dn-burst-funnel-stats' ); ?>:</strong>
				<span data-dn-next-update>
					<?php echo $status['next_update'] ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['next_update'] ) ) : esc_html__( 'Not scheduled', 'dn-burst-funnel-stats' ); ?>
				</span>
			</p>
		</div>
		<button type="button" class="button" data-dn-update-now><?php esc_html_e( 'Update now', 'dn-burst-funnel-stats' ); ?></button>
		<div class="dn-burst-status-message" data-dn-status-message aria-live="polite"></div>
	</div>
	<?php
}

function dn_burst_dash_render_overview_tab( $data ) {
	$compare_label = $data['range']['compare_label'];
	?>
	<div class="dn-burst-grid">
		<?php
		foreach ( $data['cards'] as $card ) {
			dn_burst_dash_render_card( $card, $compare_label );
		}
		?>
	</div>

	<?php dn_burst_dash_render_charts( $data['range'] ); ?>

	<div class="dn-burst-meta">
		<strong><?php esc_html_e( 'Matching paths:', 'dn-burst-funnel-stats' ); ?></strong><br />
		<?php esc_html_e( 'Cart:', 'dn-burst-funnel-stats' ); ?> <code><?php echo esc_html( $data['paths']['cart'] ); ?></code><br />
		<?php esc_html_e( 'Checkout:', 'dn-burst-funnel-stats' ); ?> <code><?php echo esc_html( $data['paths']['checkout'] ); ?></code><br />
		<?php esc_html_e( 'Payment contains:', 'dn-burst-funnel-stats' ); ?> <code>/order-pay</code>, <code>payment</code>, <code>pay-for-order</code><br /><br />
		<?php esc_html_e( 'Product base:', 'dn-burst-funnel-stats' ); ?> <code><?php echo esc_html( $data['paths']['product_base'] ); ?></code><br />
		<strong><?php esc_html_e( 'Note:', 'dn-burst-funnel-stats' ); ?></strong>
		<?php esc_html_e( 'Visits, Add To Cart, Checkout, and payment-related metrics use Burst page visit data where available. Orders, items, sales, and balance metrics use WooCommerce orders.', 'dn-burst-funnel-stats' ); ?>
	</div>
	<?php
}

function dn_burst_dash_render_tab_content( $tab, $range = null ) {
	$tab   = dn_burst_dash_sanitize_tab( $tab );
	$range = is_array( $range ) ? $range : dn_burst_dash_get_range_data();

	if ( ! dn_burst_dash_burst_table_exists() ) {
		printf(
			'<div class="dn-burst-empty notice notice-warning"><p>%s</p></div>',
			esc_html__( 'The Burst Statistics table is not available yet. Visit data and URL tracking reports will appear after Burst starts recording data.', 'dn-burst-funnel-stats' )
		);

		if ( in_array( $tab, array( 'overview', 'ad-urls', 'devices' ), true ) ) {
			return;
		}
	}

	if ( 'overview' === $tab ) {
		dn_burst_dash_render_overview_tab( dn_burst_dash_build_data() );
		return;
	}

	if ( 'ad-urls' === $tab ) {
		dn_burst_dash_render_ad_urls_tab( $range );
		return;
	}

	dn_burst_dash_render_simple_tab( $tab, $range );
}

/**
 * =========================
 * Render
 * =========================
 */

function dn_burst_dash_render_page() {
	dn_burst_dash_send_nocache_headers();
	dn_burst_dash_touch_data_changed();

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$range_data = dn_burst_dash_get_range_data();
	$range      = $range_data['range'];
	$tab        = dn_burst_dash_sanitize_tab( dn_burst_dash_get_query_value( 'dn_tab', 'overview' ) );
	$tabs       = dn_burst_dash_get_tabs();
	$tab_label  = isset( $tabs[ $tab ] ) ? $tabs[ $tab ] : esc_html__( 'Overview', 'dn-burst-funnel-stats' );
	?>
	<div class="wrap dn-burst-wrap dn-burst-funnel-stats" data-dn-dashboard>
		<header class="dn-burst-topbar">
			<h1 class="dn-burst-topbar-title"><?php echo esc_html( $tab_label ); ?></h1>
			<div class="dn-burst-topbar-actions" aria-label="<?php echo esc_attr__( 'Dashboard actions', 'dn-burst-funnel-stats' ); ?>">
				<button type="button" class="dn-burst-topbar-action" aria-label="<?php echo esc_attr__( 'Activity', 'dn-burst-funnel-stats' ); ?>">
					<span class="dashicons dashicons-flag" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Activity', 'dn-burst-funnel-stats' ); ?></span>
				</button>
				<button type="button" class="dn-burst-topbar-action" aria-label="<?php echo esc_attr__( 'Finish setup', 'dn-burst-funnel-stats' ); ?>">
					<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Finish setup', 'dn-burst-funnel-stats' ); ?></span>
				</button>
			</div>
		</header>

		<div class="dn-burst-dashboard-toolbar">
			<div class="dn-burst-dashboard-toolbar-section dn-burst-date-range-control">
				<div class="dn-burst-toolbar-label"><?php esc_html_e( 'Date range:', 'dn-burst-funnel-stats' ); ?></div>
				<?php dn_burst_dash_render_date_picker( $range_data, 'dn-burst-funnel-stats', $tab ); ?>
			</div>
			<div class="dn-burst-dashboard-toolbar-section dn-burst-data-status-control">
				<div class="dn-burst-toolbar-label"><?php esc_html_e( 'Data status:', 'dn-burst-funnel-stats' ); ?></div>
				<?php dn_burst_dash_render_data_status_panel( true ); ?>
			</div>
		</div>

		<div class="dn-burst-dashboard-content">
			<nav class="nav-tab-wrapper dn-burst-tabs" aria-label="<?php echo esc_attr__( 'Dashboard tabs', 'dn-burst-funnel-stats' ); ?>">
				<?php foreach ( $tabs as $tab_key => $tab_name ) : ?>
					<a
						href="<?php echo esc_url( dn_burst_dash_get_dashboard_url( 'dn-burst-funnel-stats', $tab_key, $range_data ) ); ?>"
						class="nav-tab <?php echo $tab_key === $tab ? 'nav-tab-active' : ''; ?>"
						data-dn-tab="<?php echo esc_attr( $tab_key ); ?>"
					>
						<?php echo esc_html( $tab_name ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="dn-burst-tab-content" data-dn-tab-content aria-live="polite">
				<?php dn_burst_dash_render_tab_content( $tab, $range_data ); ?>
			</div>
		</div>
	</div>
	<?php
}

function dn_burst_dash_render_url_tracking_page() {
	dn_burst_dash_send_nocache_headers();
	dn_burst_dash_touch_data_changed();

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$range_data = dn_burst_dash_get_range_data();
	$range      = $range_data['range'];
	?>
	<div class="wrap dn-burst-wrap" data-dn-dashboard>
		<div class="dn-burst-toolbar">
			<h1 class="dn-burst-title"><?php esc_html_e( 'URL Tracking', 'dn-burst-funnel-stats' ); ?></h1>
			<?php dn_burst_dash_render_date_picker( $range_data, 'dn-burst-funnel-stats-url-tracking', 'ad-urls' ); ?>
		</div>

		<div class="dn-burst-tab-content" data-dn-tab-content aria-live="polite">
			<?php dn_burst_dash_render_ad_urls_tab( $range_data ); ?>
		</div>
	</div>
	<?php
}
