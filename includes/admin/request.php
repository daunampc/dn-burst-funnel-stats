<?php
/**
 * Admin permissions and validation of report parameters (shared with the public API).
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_admin_capability() {
	return (string) apply_filters( 'dn_bfs_capability', 'manage_options' );
}

function dn_bfs_admin_permission() {
	return current_user_can( dn_bfs_admin_capability() );
}

function dn_bfs_request_error( $code, $message, $status = 400, $extra = array() ) {
	return new WP_Error( $code, $message, array_merge( array( 'status' => (int) $status ), $extra ) );
}

function dn_bfs_valid_date_string( $value ) {
	if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
		return false;
	}

	$date = DateTime::createFromFormat( '!Y-m-d', $value );

	return $date && $date->format( 'Y-m-d' ) === $value;
}

function dn_bfs_parse_range( $params, $max_days = 731 ) {
	$settings = dn_bfs_get_tracking_settings();
	$period   = isset( $params['period'] ) && is_string( $params['period'] ) && '' !== $params['period'] ? $params['period'] : $settings['default_date_range'];
	$compare  = isset( $params['compare'] ) && is_string( $params['compare'] ) && '' !== $params['compare'] ? $params['compare'] : $settings['default_compare'];

	if ( ! array_key_exists( $period, dn_bfs_get_date_presets() ) ) {
		return dn_bfs_request_error( 'invalid_period', __( 'Unknown date range.', 'dn-burst-funnel-stats' ) );
	}

	if ( ! in_array( $compare, dn_bfs_compare_modes(), true ) ) {
		return dn_bfs_request_error( 'invalid_compare', __( 'Unknown comparison mode.', 'dn-burst-funnel-stats' ) );
	}

	$start = '';
	$end   = '';

	if ( 'custom' === $period ) {
		$start = isset( $params['start'] ) ? $params['start'] : '';
		$end   = isset( $params['end'] ) ? $params['end'] : '';

		if ( ! dn_bfs_valid_date_string( $start ) || ! dn_bfs_valid_date_string( $end ) || $start > $end ) {
			return dn_bfs_request_error( 'invalid_date', __( 'Use valid start and end dates in YYYY-MM-DD format.', 'dn-burst-funnel-stats' ) );
		}

		$utc  = new DateTimeZone( 'UTC' );
		$span = (int) ( new DateTimeImmutable( $start . ' 00:00:00', $utc ) )->diff( new DateTimeImmutable( $end . ' 00:00:00', $utc ) )->days;

		if ( $span > (int) $max_days - 1 ) {
			/* translators: %d: maximum number of days in a custom range. */
			return dn_bfs_request_error( 'range_too_long', sprintf( __( 'Custom ranges can cover at most %d days.', 'dn-burst-funnel-stats' ), (int) $max_days ) );
		}
	}

	return dn_bfs_calculate_date_range( $period, $compare, $start, $end );
}

function dn_bfs_parse_filters( $params ) {
	$raw = isset( $params['filter'] ) ? $params['filter'] : array();

	if ( ! is_array( $raw ) ) {
		return dn_bfs_request_error( 'invalid_filter', __( 'Filters must be sent as filter[dimension]=value.', 'dn-burst-funnel-stats' ) );
	}

	foreach ( $raw as $dimension => $value ) {
		if ( ! in_array( $dimension, dn_bfs_filter_dimensions(), true ) ) {
			/* translators: %s: filter dimension. */
			return dn_bfs_request_error( 'invalid_filter', sprintf( __( 'Unknown filter: %s.', 'dn-burst-funnel-stats' ), $dimension ) );
		}

		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			/* translators: %s: filter dimension. */
			return dn_bfs_request_error( 'invalid_filter', sprintf( __( 'Filter %s needs a value.', 'dn-burst-funnel-stats' ), $dimension ) );
		}
	}

	return dn_bfs_sanitize_filters( $raw );
}

function dn_bfs_parse_metrics( $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $metric ) {
			if ( ! is_scalar( $metric ) ) {
				return dn_bfs_request_error( 'invalid_metric', __( 'Metrics must be a list of metric names.', 'dn-burst-funnel-stats' ) );
			}
		}
	}

	$metrics = array_filter( array_map( 'trim', is_array( $value ) ? array_map( 'strval', $value ) : explode( ',', (string) $value ) ), 'strlen' );

	if ( empty( $metrics ) ) {
		return dn_bfs_default_metrics();
	}

	$allowed = dn_bfs_metric_names();

	foreach ( $metrics as $metric ) {
		if ( ! in_array( $metric, $allowed, true ) ) {
			/* translators: %s: metric name. */
			return dn_bfs_request_error( 'invalid_metric', sprintf( __( 'Unknown metric: %s.', 'dn-burst-funnel-stats' ), (string) $metric ) );
		}
	}

	return array_values( array_unique( $metrics ) );
}

function dn_bfs_params_from_query( $query ) {
	$pick = function ( $key ) use ( $query ) {
		return isset( $query[ $key ] ) && is_string( $query[ $key ] ) ? $query[ $key ] : '';
	};

	return array(
		'period'  => $pick( 'dn_period' ),
		'compare' => $pick( 'dn_compare' ),
		'start'   => $pick( 'dn_start' ),
		'end'     => $pick( 'dn_end' ),
		'tab'     => $pick( 'dn_tab' ),
		'filter'  => isset( $query['dn_filter'] ) && is_array( $query['dn_filter'] ) ? $query['dn_filter'] : array(),
	);
}
