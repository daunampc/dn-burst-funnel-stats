<?php
/**
 * Report metric, dimension and filter definitions. Pure functions only.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_lower( $value ) {
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
}

/**
 * Case- and accent-insensitive key for a dimension value (matches the
 * utf8mb4_unicode_520_ci collation used by GROUP BY and filters).
 */
function dn_bfs_dim_key( $value ) {
	return dn_bfs_lower( function_exists( 'remove_accents' ) ? remove_accents( (string) $value, 'en_US' ) : (string) $value );
}

/**
 * Daily row key for a dimension value: values are grouped case- and accent-insensitively.
 */
function dn_bfs_dim_hash( $value ) {
	return md5( dn_bfs_dim_key( $value ) );
}

function dn_bfs_metric_columns() {
	return array( 'pageviews', 'visitors', 'sessions', 'new_visitors', 'bounces', 'duration_sum', 'product_views', 'atc', 'carts', 'checkouts', 'orders', 'revenue', 'items', 'tips', 'paid', 'balance' );
}

function dn_bfs_money_columns() {
	return array( 'revenue', 'tips', 'paid', 'balance' );
}

function dn_bfs_order_columns() {
	return array( 'orders', 'revenue', 'items', 'tips', 'paid', 'balance' );
}

function dn_bfs_empty_metrics() {
	$metrics = array();

	foreach ( dn_bfs_metric_columns() as $column ) {
		$metrics[ $column ] = in_array( $column, dn_bfs_money_columns(), true ) ? 0.0 : 0;
	}

	return $metrics;
}

function dn_bfs_normalize_metrics( $row ) {
	$metrics = dn_bfs_empty_metrics();

	foreach ( dn_bfs_metric_columns() as $column ) {
		if ( ! isset( $row[ $column ] ) ) {
			continue;
		}

		$metrics[ $column ] = in_array( $column, dn_bfs_money_columns(), true ) ? round( (float) $row[ $column ], 4 ) : (int) $row[ $column ];
	}

	return $metrics;
}

function dn_bfs_add_metrics( $a, $b ) {
	$sum = dn_bfs_empty_metrics();

	foreach ( dn_bfs_metric_columns() as $column ) {
		$value = ( isset( $a[ $column ] ) ? $a[ $column ] : 0 ) + ( isset( $b[ $column ] ) ? $b[ $column ] : 0 );

		$sum[ $column ] = in_array( $column, dn_bfs_money_columns(), true ) ? round( (float) $value, 4 ) : (int) $value;
	}

	return $sum;
}

function dn_bfs_session_dimensions() {
	return array( 'channel', 'source', 'medium', 'campaign', 'referrer', 'device', 'browser', 'os', 'country', 'city', 'entry', 'exit' );
}

function dn_bfs_report_dimensions() {
	return array_merge( array( 'page', 'product' ), dn_bfs_session_dimensions() );
}

function dn_bfs_aggregate_dimensions() {
	return array_merge( array( 'total' ), dn_bfs_report_dimensions() );
}

function dn_bfs_order_dimensions() {
	return array( 'total', 'channel', 'source', 'medium', 'campaign', 'country', 'device', 'product' );
}

function dn_bfs_filter_dimensions() {
	return array( 'channel', 'source', 'medium', 'campaign', 'device', 'country' );
}

function dn_bfs_sanitize_filters( $filters ) {
	$clean = array();

	if ( ! is_array( $filters ) ) {
		return $clean;
	}

	foreach ( $filters as $dimension => $value ) {
		$dimension = sanitize_key( $dimension );

		if ( ! in_array( $dimension, dn_bfs_filter_dimensions(), true ) || ! is_scalar( $value ) ) {
			continue;
		}

		$value = dn_bfs_truncate( sanitize_text_field( (string) $value ), 191 );

		if ( '' !== $value ) {
			$clean[ $dimension ] = $value;
		}
	}

	ksort( $clean );

	return $clean;
}

function dn_bfs_derived_metric_names() {
	return array( 'returning_visitors', 'bounce_rate', 'avg_duration', 'pages_per_session', 'conversion_rate', 'aov', 'aoi' );
}

/**
 * Metrics that need raw tracking data (sessions, pageviews, funnel events):
 * every stored and derived metric except order metrics and aov/aoi.
 */
function dn_bfs_traffic_metric_names() {
	return array_values( array_diff( dn_bfs_metric_names(), dn_bfs_order_columns(), array( 'aov', 'aoi' ) ) );
}

function dn_bfs_compare_modes() {
	return array( 'none', 'previous_period', 'previous_year' );
}

function dn_bfs_default_metrics() {
	return array( 'sessions', 'orders', 'revenue' );
}

function dn_bfs_metric_names() {
	return array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() );
}

function dn_bfs_derive_metrics( $m ) {
	$sessions = (int) $m['sessions'];
	$visitors = (int) $m['visitors'];
	$orders   = (int) $m['orders'];

	return array_merge(
		$m,
		array(
			'returning_visitors' => max( 0, $visitors - (int) $m['new_visitors'] ),
			'bounce_rate'        => $sessions > 0 ? round( $m['bounces'] / $sessions * 100, 1 ) : 0.0,
			'avg_duration'       => $sessions > 0 ? (int) round( $m['duration_sum'] / $sessions ) : 0,
			'pages_per_session'  => $sessions > 0 ? round( $m['pageviews'] / $sessions, 2 ) : 0.0,
			'conversion_rate'    => $visitors > 0 ? round( $orders / $visitors * 100, 2 ) : 0.0,
			'aov'                => $orders > 0 ? round( $m['revenue'] / $orders, 2 ) : 0.0,
			'aoi'                => $orders > 0 ? round( $m['items'] / $orders, 2 ) : 0.0,
		)
	);
}

function dn_bfs_percent_change( $current, $previous ) {
	$current  = (float) $current;
	$previous = (float) $previous;

	if ( $previous <= 0 ) {
		return $current > 0 ? 100.0 : 0.0;
	}

	return round( ( $current - $previous ) / $previous * 100, 1 );
}

function dn_bfs_date_shift( $date, $days ) {
	$day = new DateTimeImmutable( $date . ' 00:00:00', new DateTimeZone( 'UTC' ) );

	return $day->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' days' )->format( 'Y-m-d' );
}

function dn_bfs_dates_between( $start, $end ) {
	$dates = array();

	for ( $cursor = $start; $cursor <= $end; $cursor = dn_bfs_date_shift( $cursor, 1 ) ) {
		$dates[] = $cursor;
	}

	return $dates;
}

function dn_bfs_sort_report_rows( $rows, $orderby, $order ) {
	$direction = 'asc' === strtolower( (string) $order ) ? 1 : -1;

	usort(
		$rows,
		function ( $a, $b ) use ( $orderby, $direction ) {
			$left  = isset( $a[ $orderby ] ) ? (float) $a[ $orderby ] : 0.0;
			$right = isset( $b[ $orderby ] ) ? (float) $b[ $orderby ] : 0.0;

			if ( $left === $right ) {
				return strcmp( (string) $a['dim_value'], (string) $b['dim_value'] );
			}

			return ( $left < $right ? -1 : 1 ) * $direction;
		}
	);

	return $rows;
}
