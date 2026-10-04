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

function dn_bfs_parse_range( $params ) {
	$settings = dn_bfs_get_tracking_settings();
	$period   = isset( $params['period'] ) && is_string( $params['period'] ) && '' !== $params['period'] ? $params['period'] : $settings['default_date_range'];
	$compare  = isset( $params['compare'] ) && is_string( $params['compare'] ) && '' !== $params['compare'] ? $params['compare'] : $settings['default_compare'];

	if ( ! array_key_exists( $period, dn_bfs_get_date_presets() ) ) {
		return dn_bfs_request_error( 'invalid_period', __( 'Unknown date range.', 'dn-burst-funnel-stats' ) );
	}

	if ( ! in_array( $compare, array( 'none', 'previous_period', 'previous_year' ), true ) ) {
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

		if ( count( dn_bfs_dates_between( $start, $end ) ) > 731 ) {
			return dn_bfs_request_error( 'range_too_long', __( 'Custom ranges can cover at most 731 days.', 'dn-burst-funnel-stats' ) );
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
	$metrics = is_array( $value ) ? $value : array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );

	if ( empty( $metrics ) ) {
		return array( 'sessions', 'orders', 'revenue' );
	}

	$allowed = array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() );

	foreach ( $metrics as $metric ) {
		if ( ! in_array( $metric, $allowed, true ) ) {
			/* translators: %s: metric name. */
			return dn_bfs_request_error( 'invalid_metric', sprintf( __( 'Unknown metric: %s.', 'dn-burst-funnel-stats' ), (string) $metric ) );
		}
	}

	return array_values( array_unique( $metrics ) );
}

function dn_bfs_range_meta( $range ) {
	return array(
		'period'               => $range['period'],
		'compare'              => $range['compare'],
		'start'                => $range['custom_start'],
		'end'                  => $range['custom_end'],
		'label'                => $range['current_label'],
		'range_label'          => $range['current_range_label'],
		'compare_label'        => $range['compare_label'],
		'previous_range_label' => $range['previous_range_label'],
	);
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
