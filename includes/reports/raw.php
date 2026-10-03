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

function dn_bfs_raw_collect( $results ) {
	$rows = array();

	foreach ( (array) $results as $result ) {
		$key          = (string) $result['dim_value'];
		$rows[ $key ] = isset( $rows[ $key ] ) ? dn_bfs_add_metrics( $rows[ $key ], dn_bfs_normalize_metrics( $result ) ) : dn_bfs_normalize_metrics( $result );
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

	foreach ( $pageview_rows as $key => $row ) {
		$session_rows[ $key ] = isset( $session_rows[ $key ] ) ? dn_bfs_add_metrics( $session_rows[ $key ], $row ) : $row;
	}

	return $session_rows;
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

function dn_bfs_raw_distinct_visitors( $start, $end, $filters, $new_only = false ) {
	global $wpdb;

	$new_sql = $new_only ? ' AND s.is_new_visitor = 1' : '';

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(DISTINCT s.visitor_uid) FROM ' . dn_bfs_table( 'sessions' ) . " s
			WHERE s.started_at >= %d AND s.started_at < %d AND s.is_spam = 0 AND s.pageviews > 0{$new_sql}" . dn_bfs_raw_filter_sql( $filters, 's' ),
			(int) $start,
			(int) $end
		)
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

function dn_bfs_raw_order_rows( $start, $end, $dimension, $filters ) {
	global $wpdb;

	if ( 'page' === $dimension ) {
		return array();
	}

	$column = 'total' === $dimension ? "''" : dn_bfs_raw_event_column( $dimension );

	if ( '' === $column ) {
		return array();
	}

	if ( 'product' === $dimension ) {
		$column = "''";
	}

	// Orders are confirmed revenue: spam sessions are not excluded here.
	$results = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT e.order_id, {$column} AS dim_value
			FROM " . dn_bfs_table( 'events' ) . ' e LEFT JOIN ' . dn_bfs_table( 'sessions' ) . " s ON s.id = e.session_id
			WHERE e.type = 'order' AND e.time >= %d AND e.time < %d" . dn_bfs_raw_filter_sql( $filters, 'e' ),
			(int) $start,
			(int) $end
		),
		ARRAY_A
	);

	$settings = dn_bfs_get_wc_report_settings();
	$sums     = array();

	foreach ( (array) $results as $result ) {
		$order = dn_bfs_raw_get_order( (int) $result['order_id'] );

		if ( ! $order ) {
			continue;
		}

		$status   = dn_bfs_order_status_key( $order );
		$is_sale  = ! in_array( $status, $settings['sales_excluded_statuses'], true );
		$order_total = (float) $order->get_total();

		if ( 'product' === $dimension ) {
			if ( ! $is_sale ) {
				continue;
			}

			$seen = array();

			foreach ( $order->get_items() as $item ) {
				$key = (string) (int) $item->get_product_id();

				if ( ! isset( $sums[ $key ] ) ) {
					$sums[ $key ] = dn_bfs_empty_metrics();
				}

				if ( ! isset( $seen[ $key ] ) ) {
					$sums[ $key ]['orders']++;
					$seen[ $key ] = true;
				}

				// Product revenue = line totals after discounts minus item refunds, excluding tax/shipping/fees.
				$sums[ $key ]['revenue'] += max( 0.0, (float) $item->get_total() - abs( (float) $order->get_total_refunded_for_item( $item->get_id() ) ) );
				$sums[ $key ]['items']   += max( 0, (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) ) );
			}

			continue;
		}

		$key = (string) $result['dim_value'];

		if ( ! isset( $sums[ $key ] ) ) {
			$sums[ $key ] = dn_bfs_empty_metrics();
		}

		if ( $is_sale ) {
			$sums[ $key ]['orders']++;
			$sums[ $key ]['revenue'] += max( 0.0, $order_total - (float) $order->get_total_refunded() );
			$sums[ $key ]['items']   += (int) $order->get_item_count();
			$sums[ $key ]['tips']    += dn_bfs_order_tip_total( $order, $settings['tip_keywords'] );
		}

		if ( in_array( $status, $settings['paid_statuses'], true ) ) {
			$sums[ $key ]['paid'] += $order_total;
		}

		if ( in_array( $status, $settings['balance_statuses'], true ) ) {
			$sums[ $key ]['balance'] += $order_total;
		}
	}

	return array_map( 'dn_bfs_normalize_metrics', $sums );
}

function dn_bfs_raw_rows( $start, $end, $dimension, $filters ) {
	if ( ! in_array( $dimension, dn_bfs_aggregate_dimensions(), true ) ) {
		return array();
	}

	$rows = array();

	foreach ( array( 'dn_bfs_raw_traffic_rows', 'dn_bfs_raw_event_rows', 'dn_bfs_raw_order_rows' ) as $source ) {
		foreach ( call_user_func( $source, $start, $end, $dimension, $filters ) as $key => $metrics ) {
			$key          = (string) $key;
			$rows[ $key ] = isset( $rows[ $key ] ) ? dn_bfs_add_metrics( $rows[ $key ], $metrics ) : $metrics;
		}
	}

	return $rows;
}
