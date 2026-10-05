<?php
/**
 * Report API shared by the admin dashboard and the public REST API.
 *
 * Days the aggregator has finished (up to `dnbfs_last_aggregated_date`) come
 * from dnbfs_daily; later days (today, or days the aggregator has not reached
 * yet) and multi-filter queries come from the raw engine. Multi-filter queries
 * (and filtered breakdowns) that start before the raw-data window are answered
 * orders-only from order events, with `traffic_available` false and traffic
 * metrics 0; every result carries `traffic_available`.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_report_cache_ttl() {
	return (int) apply_filters( 'dn_bfs_report_cache_ttl', 60 );
}

/**
 * Part of every report/API cache key. Purging data bumps it, which also
 * invalidates entries held in a persistent object cache (those cannot be
 * listed and deleted the way wp_options transients can).
 */
function dn_bfs_cache_generation() {
	return (int) get_option( 'dnbfs_cache_generation', 0 );
}

function dn_bfs_bump_cache_generation() {
	update_option( 'dnbfs_cache_generation', dn_bfs_cache_generation() + 1 );
}

/**
 * Report rows from `$source` (dn_bfs_raw_rows or dn_bfs_raw_order_rows), cached
 * for dn_bfs_report_cache_ttl() seconds. Order-only rows get their own keys.
 */
function dn_bfs_report_cached_rows( $source, $start, $end, $dimension, $filters ) {
	$ttl = dn_bfs_report_cache_ttl();

	if ( $ttl <= 0 ) {
		return call_user_func( $source, $start, $end, $dimension, $filters );
	}

	$parts = array( dn_bfs_cache_generation(), (int) $start, (int) floor( $end / $ttl ), $dimension, dn_bfs_sanitize_filters( $filters ) );

	if ( 'dn_bfs_raw_rows' !== $source ) {
		$parts[] = $source;
	}

	$key    = 'dnbfs_r_' . md5( wp_json_encode( $parts ) );
	$cached = get_transient( $key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$rows = call_user_func( $source, $start, $end, $dimension, $filters );
	set_transient( $key, $rows, $ttl );

	return $rows;
}

function dn_bfs_report_raw_rows( $start, $end, $dimension, $filters ) {
	return dn_bfs_report_cached_rows( 'dn_bfs_raw_rows', $start, $end, $dimension, $filters );
}

/**
 * Order metrics only (traffic metrics stay 0), from order events. Cleanup keeps
 * order events forever and they carry their own attribution, so these rows work
 * for any range and filter combination.
 */
function dn_bfs_report_order_rows( $start, $end, $dimension, $filters ) {
	return dn_bfs_report_cached_rows( 'dn_bfs_raw_order_rows', $start, $end, $dimension, $filters );
}

/**
 * Sort metric for a breakdown. Without traffic data, traffic metrics are all 0,
 * so rows are sorted by orders instead.
 */
function dn_bfs_report_breakdown_orderby( $orderby, $dimension, $traffic_available ) {
	$allowed = array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() );
	$orderby = in_array( $orderby, $allowed, true ) ? $orderby : ( 'product' === $dimension ? 'product_views' : 'pageviews' );

	if ( ! $traffic_available && in_array( $orderby, dn_bfs_traffic_metric_names(), true ) ) {
		$orderby = 'orders';
	}

	return $orderby;
}

function dn_bfs_report_error_out_of_retention() {
	return new WP_Error(
		'filter_out_of_retention',
		__( 'Combined filters are only available for dates that still have raw tracking data.', 'dn-burst-funnel-stats' ),
		array( 'status' => 422 )
	);
}

/**
 * Splits a period into the part covered by dnbfs_daily (dates up to the
 * aggregation watermark) and the live part read from raw data (from
 * `live_start_date` on). Unaggregated days whose raw data was already purged
 * cannot be recovered: the live part then starts at the first date that still
 * has raw data (dn_bfs_raw_available_from()) and `incomplete` is true.
 */
function dn_bfs_report_period( $start_ts, $end_ts, $now ) {
	$start_date = wp_date( 'Y-m-d', (int) $start_ts );
	$end_date   = wp_date( 'Y-m-d', (int) $end_ts );
	$today      = wp_date( 'Y-m-d', (int) $now );
	$yesterday  = dn_bfs_date_shift( $today, -1 );
	$last       = (string) get_option( 'dnbfs_last_aggregated_date', '' );
	$daily_end  = min( $end_date, $yesterday );

	if ( '' !== $last ) {
		$daily_end = min( $daily_end, $last );
	}

	$has_daily  = '' !== $last && $start_date <= $daily_end;
	$live_start = $has_daily ? max( $start_date, dn_bfs_date_shift( $daily_end, 1 ) ) : $start_date;
	$live_last  = min( $end_date, $today );
	$cutoff     = dn_bfs_raw_available_from( $now );
	$incomplete = false;

	if ( $live_start <= $live_last && $live_start < $cutoff ) {
		$live_start = $cutoff;
		$incomplete = true;
	}

	list( $period_start ) = dn_bfs_day_bounds( $start_date );
	list( , $last_end )   = dn_bfs_day_bounds( $end_date );
	list( $live_ts )      = dn_bfs_day_bounds( $live_start );

	return array(
		'start_date'      => $start_date,
		'end_date'        => $end_date,
		'today'           => $today,
		'daily_end'       => $daily_end,
		'has_daily'       => $has_daily,
		'has_today'       => $start_date <= $today && $end_date >= $today,
		'live_start_date' => $live_start,
		'live_start_ts'   => $live_ts,
		'has_live'        => $live_start <= $live_last,
		'incomplete'      => $incomplete,
		'start_ts'        => $period_start,
		'end_ts'          => min( $last_end, (int) $now + 1 ),
	);
}

function dn_bfs_daily_sum_sql() {
	$parts = array();

	foreach ( dn_bfs_metric_columns() as $column ) {
		$parts[] = "COALESCE(SUM({$column}), 0) AS {$column}";
	}

	return implode( ', ', $parts );
}

function dn_bfs_daily_filter_where( $filters ) {
	global $wpdb;

	$filters = dn_bfs_sanitize_filters( $filters );

	if ( empty( $filters ) ) {
		return "dimension = 'total'";
	}

	$dimension = key( $filters );

	return $wpdb->prepare( 'dimension = %s AND dim_hash = %s', $dimension, dn_bfs_dim_hash( $filters[ $dimension ] ) );
}

function dn_bfs_daily_totals( $start_date, $end_date, $filters ) {
	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT ' . dn_bfs_daily_sum_sql() . ' FROM ' . dn_bfs_table( 'daily' ) . ' WHERE date >= %s AND date <= %s AND ' . dn_bfs_daily_filter_where( $filters ),
			$start_date,
			$end_date
		),
		ARRAY_A
	);

	return dn_bfs_normalize_metrics( is_array( $row ) ? $row : array() );
}

function dn_bfs_daily_series( $start_date, $end_date, $filters ) {
	global $wpdb;

	$results = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT date, ' . dn_bfs_daily_sum_sql() . ' FROM ' . dn_bfs_table( 'daily' ) . ' WHERE date >= %s AND date <= %s AND ' . dn_bfs_daily_filter_where( $filters ) . ' GROUP BY date',
			$start_date,
			$end_date
		),
		ARRAY_A
	);

	$series = array();

	foreach ( (array) $results as $row ) {
		$series[ $row['date'] ] = dn_bfs_normalize_metrics( $row );
	}

	return $series;
}

function dn_bfs_daily_breakdown( $start_date, $end_date, $dimension ) {
	global $wpdb;

	$results = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT dim_value, ' . dn_bfs_daily_sum_sql() . ' FROM ' . dn_bfs_table( 'daily' ) . ' WHERE date >= %s AND date <= %s AND dimension = %s GROUP BY dim_hash, dim_value',
			$start_date,
			$end_date,
			$dimension
		),
		ARRAY_A
	);

	return dn_bfs_raw_collect( $results );
}

function dn_bfs_report_period_metrics( $start_ts, $end_ts, $filters, $now ) {
	$filters      = dn_bfs_sanitize_filters( $filters );
	$period       = dn_bfs_report_period( $start_ts, $end_ts, $now );
	$in_retention = $period['start_date'] >= dn_bfs_raw_available_from( $now );
	$metrics      = dn_bfs_empty_metrics();

	if ( $period['start_date'] > $period['today'] ) {
		return array(
			'metrics'           => dn_bfs_derive_metrics( $metrics ),
			'estimated'         => false,
			'traffic_available' => true,
		);
	}

	if ( count( $filters ) > 1 ) {
		// Beyond the raw window only order events are left: orders-only (spec 8.3).
		if ( ! $in_retention ) {
			$rows = dn_bfs_report_order_rows( $period['start_ts'], $period['end_ts'], 'total', $filters );

			return array(
				'metrics'           => dn_bfs_derive_metrics( isset( $rows[''] ) ? $rows[''] : $metrics ),
				'estimated'         => false,
				'traffic_available' => false,
			);
		}

		$rows    = dn_bfs_report_raw_rows( $period['start_ts'], $period['end_ts'], 'total', $filters );
		$metrics = isset( $rows[''] ) ? $rows[''] : $metrics;
	} else {
		if ( $period['has_daily'] ) {
			$metrics = dn_bfs_add_metrics( $metrics, dn_bfs_daily_totals( $period['start_date'], $period['daily_end'], $filters ) );
		}

		if ( $period['has_live'] ) {
			$rows    = dn_bfs_report_raw_rows( $period['live_start_ts'], $period['end_ts'], 'total', $filters );
			$metrics = dn_bfs_add_metrics( $metrics, isset( $rows[''] ) ? $rows[''] : dn_bfs_empty_metrics() );
		}
	}

	$estimated = $period['incomplete'];

	if ( $in_retention ) {
		$metrics = array_merge( $metrics, dn_bfs_raw_distinct_visitor_counts( $period['start_ts'], $period['end_ts'], $filters ) );
	} else {
		$estimated = $estimated || $period['start_date'] !== $period['end_date'];
	}

	return array(
		'metrics'           => dn_bfs_derive_metrics( $metrics ),
		'estimated'         => $estimated,
		'traffic_available' => true,
	);
}

function dn_bfs_report_summary( $range, $filters = array(), $now = null ) {
	$now     = null === $now ? dn_bfs_now() : (int) $now;
	$current = dn_bfs_report_period_metrics( $range['current_start'], $range['current_end'], $filters, $now );

	if ( is_wp_error( $current ) ) {
		return $current;
	}

	$previous = null;
	$change   = array();

	if ( isset( $range['compare'] ) && 'none' !== $range['compare'] ) {
		$previous = dn_bfs_report_period_metrics( $range['previous_start'], $range['previous_end'], $filters, $now );

		if ( is_wp_error( $previous ) ) {
			return $previous;
		}

		$traffic = dn_bfs_traffic_metric_names();

		foreach ( $current['metrics'] as $key => $value ) {
			// No traffic change against (or from) a period without traffic data.
			$withheld       = ( ! $current['traffic_available'] || ! $previous['traffic_available'] ) && in_array( $key, $traffic, true );
			$change[ $key ] = $withheld ? 0.0 : dn_bfs_percent_change( $value, $previous['metrics'][ $key ] );
		}
	}

	$previous_traffic = null === $previous ? null : $previous['traffic_available'];

	return array(
		'current'                    => $current['metrics'],
		'previous'                   => null === $previous ? null : $previous['metrics'],
		'change'                     => $change,
		'estimated'                  => $current['estimated'] || ( null !== $previous && $previous['estimated'] ),
		'traffic_available'          => $current['traffic_available'] && false !== $previous_traffic,
		'current_traffic_available'  => $current['traffic_available'],
		'previous_traffic_available' => $previous_traffic,
	);
}

function dn_bfs_report_timeseries( $range, $metrics, $filters = array(), $now = null ) {
	$now     = null === $now ? dn_bfs_now() : (int) $now;
	$filters = dn_bfs_sanitize_filters( $filters );
	$period  = dn_bfs_report_period( $range['current_start'], $range['current_end'], $now );
	$allowed = array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() );
	$metrics = array_values( array_intersect( (array) $metrics, $allowed ) );
	$last    = $period['end_date'] < $period['today'] ? $period['end_date'] : $period['today'];
	$labels  = dn_bfs_dates_between( $period['start_date'], $last );
	$by_day  = array();
	$traffic = true;

	if ( count( $filters ) > 1 ) {
		// Beyond the raw window only order events are left: orders-only (spec 8.3).
		$traffic = $period['start_date'] >= dn_bfs_raw_available_from( $now );
		$source  = $traffic ? 'dn_bfs_report_raw_rows' : 'dn_bfs_report_order_rows';

		foreach ( $labels as $date ) {
			list( $start, $end ) = dn_bfs_day_bounds( $date );
			$rows                = call_user_func( $source, $start, min( $end, $now + 1 ), 'total', $filters );
			$by_day[ $date ]     = isset( $rows[''] ) ? $rows[''] : dn_bfs_empty_metrics();
		}
	} else {
		if ( $period['has_daily'] ) {
			$by_day = dn_bfs_daily_series( $period['start_date'], $period['daily_end'], $filters );
		}

		if ( $period['has_live'] ) {
			foreach ( $labels as $date ) {
				if ( $date < $period['live_start_date'] ) {
					continue;
				}

				list( $start, $end ) = dn_bfs_day_bounds( $date );
				$rows                = dn_bfs_report_raw_rows( $start, min( $end, $now + 1 ), 'total', $filters );
				$by_day[ $date ]     = isset( $rows[''] ) ? $rows[''] : dn_bfs_empty_metrics();
			}
		}
	}

	$series = array_fill_keys( $metrics, array() );

	foreach ( $labels as $date ) {
		$day = dn_bfs_derive_metrics( isset( $by_day[ $date ] ) ? $by_day[ $date ] : dn_bfs_empty_metrics() );

		foreach ( $metrics as $metric ) {
			$series[ $metric ][] = $day[ $metric ];
		}
	}

	return array(
		'labels'            => $labels,
		'series'            => $series,
		'estimated'         => count( $filters ) <= 1 && $period['incomplete'],
		'traffic_available' => $traffic,
	);
}

function dn_bfs_report_funnel_steps( $metrics ) {
	$steps = array();

	foreach ( array( 'visitors', 'product_views', 'atc', 'carts', 'checkouts', 'orders' ) as $key ) {
		$steps[] = array(
			'key'   => $key,
			'value' => (int) $metrics[ $key ],
		);
	}

	return $steps;
}

function dn_bfs_report_funnel( $range, $filters = array(), $now = null ) {
	$range['compare'] = 'none';
	$summary          = dn_bfs_report_summary( $range, $filters, $now );

	if ( is_wp_error( $summary ) ) {
		return $summary;
	}

	return array(
		'steps'             => dn_bfs_report_funnel_steps( $summary['current'] ),
		'estimated'         => $summary['estimated'],
		'traffic_available' => $summary['traffic_available'],
	);
}

/**
 * Adds a `label` to each (already paginated) breakdown row. Product rows are
 * labelled with the plain-text product title, '#<id>' when the product is gone,
 * and '(deleted product)' for order lines without a product (id 0).
 */
function dn_bfs_report_label_rows( $dimension, $rows ) {
	if ( 'product' !== $dimension ) {
		foreach ( $rows as $i => $row ) {
			$rows[ $i ] = array_merge( array( 'dim_value' => $row['dim_value'], 'label' => (string) $row['dim_value'] ), $row );
		}

		return $rows;
	}

	$ids = array_filter( array_map( 'intval', array_column( $rows, 'dim_value' ) ) );

	if ( ! empty( $ids ) ) {
		_prime_post_caches( $ids, false, false );
	}

	foreach ( $rows as $i => $row ) {
		$id    = (int) $row['dim_value'];
		$title = $id > 0 ? html_entity_decode( (string) get_post_field( 'post_title', $id ), ENT_QUOTES, 'UTF-8' ) : '';

		if ( 0 === $id ) {
			$label = __( '(deleted product)', 'dn-burst-funnel-stats' );
		} else {
			$label = '' !== $title ? $title : '#' . $id;
		}

		$rows[ $i ] = array_merge( array( 'dim_value' => $row['dim_value'], 'label' => $label ), $row );
	}

	return $rows;
}

function dn_bfs_report_breakdown( $range, $dimension, $filters = array(), $orderby = '', $order = 'desc', $limit = 25, $offset = 0, $now = null ) {
	$now = null === $now ? dn_bfs_now() : (int) $now;

	if ( ! in_array( $dimension, dn_bfs_report_dimensions(), true ) ) {
		return new WP_Error( 'invalid_dimension', __( 'Unknown report dimension.', 'dn-burst-funnel-stats' ), array( 'status' => 422 ) );
	}

	$filters   = dn_bfs_sanitize_filters( $filters );
	$period    = dn_bfs_report_period( $range['current_start'], $range['current_end'], $now );
	$rows      = array();
	$estimated = false;
	$traffic   = true;

	if ( ! empty( $filters ) ) {
		if ( $period['start_date'] >= dn_bfs_raw_available_from( $now ) ) {
			$rows = dn_bfs_report_raw_rows( $period['start_ts'], $period['end_ts'], $dimension, $filters );
		} elseif ( in_array( $dimension, dn_bfs_order_dimensions(), true ) ) {
			// Beyond the raw window only order events (and their attribution) are left.
			$rows    = dn_bfs_report_order_rows( $period['start_ts'], $period['end_ts'], $dimension, $filters );
			$traffic = false;
		} else {
			return dn_bfs_report_error_out_of_retention();
		}
	} else {
		if ( $period['has_daily'] ) {
			$rows = dn_bfs_daily_breakdown( $period['start_date'], $period['daily_end'], $dimension );
		}

		if ( $period['has_live'] ) {
			$rows = dn_bfs_raw_merge_rows( $rows, dn_bfs_report_raw_rows( $period['live_start_ts'], $period['end_ts'], $dimension, array() ) );
		}

		// Per-row visitors are summed across days, so multi-day breakdowns are estimates.
		$estimated = $period['incomplete'] || $period['start_date'] !== $period['end_date'];
	}

	$list = array();

	foreach ( $rows as $value => $metrics ) {
		$list[] = array_merge( array( 'dim_value' => (string) $value ), dn_bfs_derive_metrics( $metrics ) );
	}

	$list  = dn_bfs_sort_report_rows( $list, dn_bfs_report_breakdown_orderby( $orderby, $dimension, $traffic ), $order );
	$limit = max( 1, min( 500, (int) $limit ) );

	return array(
		'rows'              => dn_bfs_report_label_rows( $dimension, array_slice( $list, max( 0, (int) $offset ), $limit ) ),
		'total'             => count( $list ),
		'estimated'         => $estimated,
		'traffic_available' => $traffic,
	);
}

function dn_bfs_report_realtime( $now = null ) {
	global $wpdb;

	$now       = null === $now ? dn_bfs_now() : (int) $now;
	$since     = $now - 5 * MINUTE_IN_SECONDS;
	$sessions  = dn_bfs_table( 'sessions' );
	$pageviews = dn_bfs_table( 'pageviews' );

	$online = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sessions} WHERE last_activity >= %d AND is_spam = 0 AND pageviews > 0", $since ) );

	$pages = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.path AS path, COUNT(*) AS visitors
			FROM {$pageviews} p
			INNER JOIN (
				SELECT session_id, MAX(id) AS last_id FROM {$pageviews} WHERE time >= %d GROUP BY session_id
			) latest ON latest.last_id = p.id
			INNER JOIN {$sessions} s ON s.id = p.session_id
			WHERE s.last_activity >= %d AND s.is_spam = 0
			GROUP BY p.path ORDER BY visitors DESC, p.path ASC LIMIT 10",
			$now - 30 * MINUTE_IN_SECONDS,
			$since
		),
		ARRAY_A
	);

	$channels = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT channel, COUNT(*) AS visitors FROM {$sessions} WHERE last_activity >= %d AND is_spam = 0 AND pageviews > 0 GROUP BY channel ORDER BY visitors DESC, channel ASC LIMIT 10",
			$since
		),
		ARRAY_A
	);

	$cast = function ( $rows, $key ) {
		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				$key       => (string) $row[ $key ],
				'visitors' => (int) $row['visitors'],
			);
		}

		return $out;
	};

	return array(
		'online'   => $online,
		'pages'    => $cast( $pages, 'path' ),
		'channels' => $cast( $channels, 'channel' ),
	);
}
