<?php
/**
 * Raw report engine: computes metrics for a time range, a dimension and filters
 * straight from the tracking tables. The aggregator and the report API share it.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_raw_session_column( $dimension ) {
	$map = array(
		'channel'  => 's.channel',
		'source'   => 's.utm_source',
		'medium'   => 's.utm_medium',
		'campaign' => 's.utm_campaign',
		'referrer' => 's.referrer_host',
		'device'   => 's.device',
		'browser'  => 's.browser',
		'os'       => 's.os',
		'country'  => 's.country',
		'city'     => 's.city',
		'entry'    => 's.entry_path',
		'exit'     => 's.exit_path',
	);

	return isset( $map[ $dimension ] ) ? $map[ $dimension ] : '';
}

/**
 * Events carry their own attribution copy (so fallback orders without a session
 * still group correctly); other dimensions come from the joined session.
 */
function dn_bfs_raw_event_column( $dimension ) {
	$own = array(
		'channel'  => 'e.channel',
		'source'   => 'e.utm_source',
		'medium'   => 'e.utm_medium',
		'campaign' => 'e.utm_campaign',
		'country'  => 'e.country',
		'device'   => 'e.device',
		'product'  => 'e.product_id',
	);

	if ( isset( $own[ $dimension ] ) ) {
		return $own[ $dimension ];
	}

	$column = dn_bfs_raw_session_column( $dimension );

	return '' === $column ? '' : 'COALESCE(' . $column . ", '')";
}

function dn_bfs_raw_filter_sql( $filters, $alias ) {
	global $wpdb;

	$sql = '';

	foreach ( dn_bfs_sanitize_filters( $filters ) as $dimension => $value ) {
		$column = 's' === $alias ? dn_bfs_raw_session_column( $dimension ) : dn_bfs_raw_event_column( $dimension );
		$sql   .= $wpdb->prepare( " AND {$column} = %s", $value );
	}

	return $sql;
}

/**
 * Adds metrics to the row whose key matches case-insensitively. The first-seen
 * spelling stays the row key (the display value). `$index` maps lower-cased
 * keys to row keys and must cover every key already in `$rows`.
 */
function dn_bfs_rows_add( &$rows, &$index, $key, $metrics ) {
	$key   = (string) $key;
	$lower = dn_bfs_dim_key( $key );

	if ( isset( $index[ $lower ] ) ) {
		$key          = $index[ $lower ];
		$rows[ $key ] = dn_bfs_add_metrics( $rows[ $key ], $metrics );
		return;
	}

	$index[ $lower ] = $key;
	$rows[ $key ]    = $metrics;
}

function dn_bfs_rows_index( $rows ) {
	$index = array();

	foreach ( array_keys( $rows ) as $key ) {
		$index[ dn_bfs_dim_key( $key ) ] = (string) $key;
	}

	return $index;
}

function dn_bfs_raw_collect( $results ) {
	$rows  = array();
	$index = array();

	foreach ( (array) $results as $result ) {
		dn_bfs_rows_add( $rows, $index, $result['dim_value'], dn_bfs_normalize_metrics( $result ) );
	}

	return $rows;
}

function dn_bfs_raw_traffic_rows( $start, $end, $dimension, $filters ) {
	global $wpdb;

	$sessions  = dn_bfs_table( 'sessions' );
	$pageviews = dn_bfs_table( 'pageviews' );
	$where_s   = dn_bfs_raw_filter_sql( $filters, 's' );

	if ( 'product' === $dimension ) {
		return array();
	}

	if ( 'page' === $dimension ) {
		return dn_bfs_raw_collect(
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.path AS dim_value, COUNT(*) AS pageviews, COUNT(DISTINCT p.visitor_uid) AS visitors,
						COUNT(DISTINCT p.session_id) AS sessions, COALESCE(SUM(p.time_on_page), 0) AS duration_sum
					FROM {$pageviews} p INNER JOIN {$sessions} s ON s.id = p.session_id
					WHERE p.time >= %d AND p.time < %d AND s.is_spam = 0 {$where_s}
					GROUP BY p.path",
					(int) $start,
					(int) $end
				),
				ARRAY_A
			)
		);
	}

	$column = 'total' === $dimension ? "''" : dn_bfs_raw_session_column( $dimension );

	if ( '' === $column ) {
		return array();
	}

	$session_rows = dn_bfs_raw_collect(
		$wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$column} AS dim_value, COUNT(*) AS sessions, COUNT(DISTINCT s.visitor_uid) AS visitors,
					COUNT(DISTINCT CASE WHEN s.is_new_visitor = 1 THEN s.visitor_uid END) AS new_visitors,
					SUM(CASE WHEN s.is_bounce = 1 AND s.pageviews = 1 THEN 1 ELSE 0 END) AS bounces,
					COALESCE(SUM(s.duration), 0) AS duration_sum
				FROM {$sessions} s
				WHERE s.started_at >= %d AND s.started_at < %d AND s.is_spam = 0 AND s.pageviews > 0 {$where_s}
				GROUP BY dim_value",
				(int) $start,
				(int) $end
			),
			ARRAY_A
		)
	);

	$pageview_rows = dn_bfs_raw_collect(
		$wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$column} AS dim_value, COUNT(*) AS pageviews
				FROM {$pageviews} p INNER JOIN {$sessions} s ON s.id = p.session_id
				WHERE p.time >= %d AND p.time < %d AND s.is_spam = 0 {$where_s}
				GROUP BY dim_value",
				(int) $start,
				(int) $end
			),
			ARRAY_A
		)
	);

	return dn_bfs_raw_merge_rows( $session_rows, $pageview_rows );
}

function dn_bfs_raw_event_rows( $start, $end, $dimension, $filters ) {
	global $wpdb;

	if ( 'page' === $dimension ) {
		return array();
	}

	$column = 'total' === $dimension ? "''" : dn_bfs_raw_event_column( $dimension );

	if ( '' === $column ) {
		return array();
	}

	$product_only = 'product' === $dimension ? ' AND e.product_id > 0' : '';

	return dn_bfs_raw_collect(
		$wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$column} AS dim_value,
					SUM(CASE WHEN e.type = 'product_view' THEN 1 ELSE 0 END) AS product_views,
					SUM(CASE WHEN e.type = 'add_to_cart' THEN 1 ELSE 0 END) AS atc,
					SUM(CASE WHEN e.type = 'cart' THEN 1 ELSE 0 END) AS carts,
					COUNT(DISTINCT CASE WHEN e.type = 'checkout_start' THEN e.session_id END) AS checkouts
				FROM " . dn_bfs_table( 'events' ) . ' e LEFT JOIN ' . dn_bfs_table( 'sessions' ) . " s ON s.id = e.session_id
				WHERE e.time >= %d AND e.time < %d
					AND e.type IN ('product_view', 'add_to_cart', 'cart', 'checkout_start')
					AND (s.id IS NULL OR s.is_spam = 0)" . dn_bfs_raw_filter_sql( $filters, 'e' ) . $product_only . '
				GROUP BY dim_value',
				(int) $start,
				(int) $end
			),
			ARRAY_A
		)
	);
}

function dn_bfs_raw_distinct_visitor_counts( $start, $end, $filters ) {
	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT COUNT(DISTINCT s.visitor_uid) AS visitors, COUNT(DISTINCT CASE WHEN s.is_new_visitor = 1 THEN s.visitor_uid END) AS new_visitors
			FROM ' . dn_bfs_table( 'sessions' ) . ' s
			WHERE s.started_at >= %d AND s.started_at < %d AND s.is_spam = 0 AND s.pageviews > 0' . dn_bfs_raw_filter_sql( $filters, 's' ),
			(int) $start,
			(int) $end
		),
		ARRAY_A
	);

	return array(
		'visitors'     => isset( $row['visitors'] ) ? (int) $row['visitors'] : 0,
		'new_visitors' => isset( $row['new_visitors'] ) ? (int) $row['new_visitors'] : 0,
	);
}

function dn_bfs_raw_get_order( $order_id, $reset = false ) {
	static $orders = array();

	if ( $reset ) {
		$orders = array();
		return false;
	}

	$order_id = (int) $order_id;

	if ( ! array_key_exists( $order_id, $orders ) ) {
		if ( count( $orders ) >= 500 ) {
			$orders = array();
		}

		$order               = $order_id > 0 ? wc_get_order( $order_id ) : false;
		$orders[ $order_id ] = $order instanceof WC_Order ? $order : false;
	}

	return $orders[ $order_id ];
}

function dn_bfs_order_tip_total( $order, $keywords ) {
	$total = 0.0;

	foreach ( $order->get_items( 'fee' ) as $fee ) {
		$name = dn_bfs_lower( $fee->get_name() );

		foreach ( (array) $keywords as $keyword ) {
			if ( '' !== $keyword && false !== strpos( $name, dn_bfs_lower( $keyword ) ) ) {
				$total += (float) $fee->get_total();
				break;
			}
		}
	}

	return $total;
}

/**
 * Per-order sale metrics, computed once per order and shared by every dimension.
 * Money is net of refunds: revenue and paid are total - refunded, items are
 * line quantities minus refunded quantities; tips stay gross (fee totals).
 * Trashed orders and orders in an unregistered status are not sales, paid or balance.
 *
 * @return array{is_sale: bool, metrics: array, products: array} `products` maps
 *               product id => metrics for sale orders (empty otherwise).
 */
function dn_bfs_raw_order_metrics( $order, $settings, $with_products ) {
	$status      = dn_bfs_order_status_key( $order );
	$known       = isset( $settings['order_statuses'] ) ? $settings['order_statuses'] : array_keys( wc_get_order_statuses() );
	$is_order    = 'wc-trash' !== $status && in_array( $status, $known, true );
	$is_sale     = $is_order && ! in_array( $status, $settings['sales_excluded_statuses'], true );
	$order_total = (float) $order->get_total();
	$net_total   = max( 0.0, $order_total - (float) $order->get_total_refunded() );
	$metrics     = array_fill_keys( dn_bfs_order_columns(), 0 );
	$products    = array();

	if ( $is_sale ) {
		$metrics['orders']  = 1;
		$metrics['revenue'] = $net_total;
		$metrics['tips']    = dn_bfs_order_tip_total( $order, $settings['tip_keywords'] );

		foreach ( $order->get_items() as $item ) {
			$metrics['items'] += max( 0, (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) ) );
		}
	}

	if ( $is_order && in_array( $status, $settings['paid_statuses'], true ) ) {
		$metrics['paid'] = $net_total;
	}

	if ( $is_order && in_array( $status, $settings['balance_statuses'], true ) ) {
		$metrics['balance'] = $order_total;
	}

	if ( $is_sale && $with_products ) {
		foreach ( $order->get_items() as $item ) {
			$key = (string) (int) $item->get_product_id();

			if ( ! isset( $products[ $key ] ) ) {
				$products[ $key ] = array(
					'orders'  => 1,
					'revenue' => 0.0,
					'items'   => 0,
				);
			}

			// Product revenue = line totals after discounts minus item refunds, excluding tax/shipping/fees.
			$products[ $key ]['revenue'] += max( 0.0, (float) $item->get_total() - abs( (float) $order->get_total_refunded_for_item( $item->get_id() ) ) );
			$products[ $key ]['items']   += max( 0, (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) ) );
		}
	}

	return array(
		'is_sale'  => $is_sale,
		'metrics'  => $metrics,
		'products' => $products,
	);
}

/**
 * Order rows for several dimensions from ONE query over the range's order
 * events: each order is loaded and measured once, then fanned out.
 *
 * @return array dimension => ( dim_value => metrics ).
 */
function dn_bfs_raw_order_rows_query( $start, $end, $dimensions, $filters ) {
	global $wpdb;

	$columns = array();
	$selects = array( 'e.order_id' );
	$out     = array();

	foreach ( array_values( (array) $dimensions ) as $index => $dimension ) {
		$out[ $dimension ] = array();

		if ( 'page' === $dimension ) {
			continue;
		}

		if ( 'product' === $dimension ) {
			$columns[ $dimension ] = '';
			continue;
		}

		$column = 'total' === $dimension ? "''" : dn_bfs_raw_event_column( $dimension );

		if ( '' === $column ) {
			continue;
		}

		$columns[ $dimension ] = 'dim_' . $index;
		$selects[]             = "{$column} AS dim_{$index}";
	}

	if ( empty( $columns ) ) {
		return $out;
	}

	// Orders are confirmed revenue: spam sessions are not excluded here.
	$results = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT ' . implode( ', ', $selects ) . '
			FROM ' . dn_bfs_table( 'events' ) . ' e LEFT JOIN ' . dn_bfs_table( 'sessions' ) . " s ON s.id = e.session_id
			WHERE e.type = 'order' AND e.time >= %d AND e.time < %d" . dn_bfs_raw_filter_sql( $filters, 'e' ),
			(int) $start,
			(int) $end
		),
		ARRAY_A
	);

	$settings                   = dn_bfs_get_wc_report_settings();
	$settings['order_statuses'] = array_keys( wc_get_order_statuses() );
	$with_products              = isset( $columns['product'] );

	$indexes = array_fill_keys( array_keys( $out ), array() );

	foreach ( (array) $results as $result ) {
		$order = dn_bfs_raw_get_order( (int) $result['order_id'] );

		if ( ! $order ) {
			continue;
		}

		$measured = dn_bfs_raw_order_metrics( $order, $settings, $with_products );

		foreach ( $columns as $dimension => $alias ) {
			$adds = 'product' === $dimension ? $measured['products'] : array( (string) $result[ $alias ] => $measured['metrics'] );

			foreach ( $adds as $key => $metrics ) {
				$key   = (string) $key;
				$lower = dn_bfs_dim_key( $key );

				if ( ! isset( $indexes[ $dimension ][ $lower ] ) ) {
					$indexes[ $dimension ][ $lower ] = $key;
					$out[ $dimension ][ $key ]       = dn_bfs_empty_metrics();
				}

				$key = $indexes[ $dimension ][ $lower ];

				foreach ( $metrics as $column => $value ) {
					$out[ $dimension ][ $key ][ $column ] += $value;
				}
			}
		}
	}

	foreach ( $out as $dimension => $rows ) {
		$out[ $dimension ] = array_map( 'dn_bfs_normalize_metrics', $rows );
	}

	return $out;
}

function dn_bfs_raw_order_rows_multi( $start, $end, array $dimensions ) {
	return dn_bfs_raw_order_rows_query( $start, $end, $dimensions, array() );
}

function dn_bfs_raw_order_rows( $start, $end, $dimension, $filters ) {
	$rows = dn_bfs_raw_order_rows_query( $start, $end, array( $dimension ), $filters );

	return $rows[ $dimension ];
}

function dn_bfs_raw_merge_rows( $rows, $more ) {
	$index = dn_bfs_rows_index( $rows );

	foreach ( $more as $key => $metrics ) {
		dn_bfs_rows_add( $rows, $index, $key, $metrics );
	}

	return $rows;
}

function dn_bfs_raw_rows( $start, $end, $dimension, $filters ) {
	if ( ! in_array( $dimension, dn_bfs_aggregate_dimensions(), true ) ) {
		return array();
	}

	$rows = array();

	foreach ( array( 'dn_bfs_raw_traffic_rows', 'dn_bfs_raw_event_rows', 'dn_bfs_raw_order_rows' ) as $source ) {
		$rows = dn_bfs_raw_merge_rows( $rows, call_user_func( $source, $start, $end, $dimension, $filters ) );
	}

	return $rows;
}
