<?php
/**
 * Dashboard date range helpers.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_get_date_presets() {
	return array(
		'today'           => esc_html__( 'Today', 'dn-burst-funnel-stats' ),
		'yesterday'       => esc_html__( 'Yesterday', 'dn-burst-funnel-stats' ),
		'week_to_date'    => esc_html__( 'Week to date', 'dn-burst-funnel-stats' ),
		'last_week'       => esc_html__( 'Last week', 'dn-burst-funnel-stats' ),
		'month_to_date'   => esc_html__( 'Month to date', 'dn-burst-funnel-stats' ),
		'last_month'      => esc_html__( 'Last month', 'dn-burst-funnel-stats' ),
		'quarter_to_date' => esc_html__( 'Quarter to date', 'dn-burst-funnel-stats' ),
		'last_quarter'    => esc_html__( 'Last quarter', 'dn-burst-funnel-stats' ),
		'year_to_date'    => esc_html__( 'Year to date', 'dn-burst-funnel-stats' ),
		'last_year'       => esc_html__( 'Last year', 'dn-burst-funnel-stats' ),
		'custom'          => esc_html__( 'Custom', 'dn-burst-funnel-stats' ),
	);
}

function dn_bfs_get_query_value( $key, $default = '' ) {
	if ( ! isset( $_GET[ $key ] ) || is_array( $_GET[ $key ] ) ) {
		return $default;
	}

	return wp_unslash( $_GET[ $key ] );
}

function dn_bfs_sanitize_date_period( $period ) {
	$period = sanitize_key( $period );

	if ( '7d' === $period ) {
		return 'week_to_date';
	}

	if ( '30d' === $period ) {
		return 'month_to_date';
	}

	return array_key_exists( $period, dn_bfs_get_date_presets() ) ? $period : 'month_to_date';
}

function dn_bfs_sanitize_compare_mode( $compare ) {
	$compare = sanitize_key( $compare );

	return in_array( $compare, array( 'none', 'previous_period', 'previous_year' ), true ) ? $compare : 'previous_year';
}

function dn_bfs_get_request_period() {
	$settings = function_exists( 'dn_bfs_get_tracking_settings' ) ? dn_bfs_get_tracking_settings() : array();
	$default  = isset( $settings['default_date_range'] ) ? $settings['default_date_range'] : 'month_to_date';

	if ( isset( $_GET['dn_period'] ) ) {
		return dn_bfs_sanitize_date_period( dn_bfs_get_query_value( 'dn_period' ) );
	}

	if ( isset( $_GET['dn_range'] ) ) {
		return dn_bfs_sanitize_date_period( dn_bfs_get_query_value( 'dn_range' ) );
	}

	return dn_bfs_sanitize_date_period( $default );
}

function dn_bfs_get_request_compare() {
	$settings = function_exists( 'dn_bfs_get_tracking_settings' ) ? dn_bfs_get_tracking_settings() : array();
	$default  = isset( $settings['default_compare'] ) ? $settings['default_compare'] : 'previous_year';

	return isset( $_GET['dn_compare'] )
		? dn_bfs_sanitize_compare_mode( dn_bfs_get_query_value( 'dn_compare' ) )
		: dn_bfs_sanitize_compare_mode( $default );
}

function dn_bfs_parse_custom_date( $value, $fallback ) {
	$value = sanitize_text_field( (string) $value );

	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
		return $fallback;
	}

	try {
		return new DateTimeImmutable( $value, wp_timezone() );
	} catch ( Exception $e ) {
		return $fallback;
	}
}

function dn_bfs_get_quarter_start_month( $month ) {
	return ( (int) floor( ( $month - 1 ) / 3 ) * 3 ) + 1;
}

function dn_bfs_format_range_label( $start_dt, $end_dt ) {
	$same_year = $start_dt->format( 'Y' ) === $end_dt->format( 'Y' );
	$same_mon  = $start_dt->format( 'M Y' ) === $end_dt->format( 'M Y' );

	if ( $same_mon ) {
		return sprintf(
			'%1$s %2$s - %3$s, %4$s',
			$start_dt->format( 'M' ),
			$start_dt->format( 'j' ),
			$end_dt->format( 'j' ),
			$end_dt->format( 'Y' )
		);
	}

	if ( $same_year ) {
		return sprintf(
			'%1$s %2$s - %3$s %4$s, %5$s',
			$start_dt->format( 'M' ),
			$start_dt->format( 'j' ),
			$end_dt->format( 'M' ),
			$end_dt->format( 'j' ),
			$end_dt->format( 'Y' )
		);
	}

	return sprintf(
		'%1$s %2$s, %3$s - %4$s %5$s, %6$s',
		$start_dt->format( 'M' ),
		$start_dt->format( 'j' ),
		$start_dt->format( 'Y' ),
		$end_dt->format( 'M' ),
		$end_dt->format( 'j' ),
		$end_dt->format( 'Y' )
	);
}

function dn_bfs_calculate_date_range( $period = '', $compare = '', $custom_start = '', $custom_end = '' ) {
	$period  = dn_bfs_sanitize_date_period( $period );
	$compare = dn_bfs_sanitize_compare_mode( $compare );
	$tz      = wp_timezone();
	$now     = new DateTimeImmutable( 'now', $tz );
	$today   = $now->setTime( 0, 0, 0 );

	switch ( $period ) {
		case 'today':
			$current_start_dt = $today;
			$current_end_dt   = $today->setTime( 23, 59, 59 );
			break;
		case 'yesterday':
			$current_start_dt = $today->modify( '-1 day' );
			$current_end_dt   = $current_start_dt->setTime( 23, 59, 59 );
			break;
		case 'week_to_date':
			$current_start_dt = $today->modify( 'monday this week' );
			$current_end_dt   = $today->setTime( 23, 59, 59 );
			break;
		case 'last_week':
			$current_start_dt = $today->modify( 'monday last week' );
			$current_end_dt   = $current_start_dt->modify( '+6 days' )->setTime( 23, 59, 59 );
			break;
		case 'last_month':
			$current_start_dt = $today->modify( 'first day of last month' );
			$current_end_dt   = $today->modify( 'last day of last month' )->setTime( 23, 59, 59 );
			break;
		case 'quarter_to_date':
			$quarter_month    = dn_bfs_get_quarter_start_month( (int) $today->format( 'n' ) );
			$current_start_dt = $today->setDate( (int) $today->format( 'Y' ), $quarter_month, 1 );
			$current_end_dt   = $today->setTime( 23, 59, 59 );
			break;
		case 'last_quarter':
			$quarter_month    = dn_bfs_get_quarter_start_month( (int) $today->format( 'n' ) );
			$current_start_dt = $today->setDate( (int) $today->format( 'Y' ), $quarter_month, 1 )->modify( '-3 months' );
			$current_end_dt   = $current_start_dt->modify( '+3 months -1 day' )->setTime( 23, 59, 59 );
			break;
		case 'year_to_date':
			$current_start_dt = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
			$current_end_dt   = $today->setTime( 23, 59, 59 );
			break;
		case 'last_year':
			$current_start_dt = $today->setDate( (int) $today->format( 'Y' ) - 1, 1, 1 );
			$current_end_dt   = $today->setDate( (int) $today->format( 'Y' ) - 1, 12, 31 )->setTime( 23, 59, 59 );
			break;
		case 'custom':
			$fallback_start   = $today->modify( '-29 days' );
			$fallback_end     = $today;
			$current_start_dt = dn_bfs_parse_custom_date( $custom_start, $fallback_start )->setTime( 0, 0, 0 );
			$current_end_dt   = dn_bfs_parse_custom_date( $custom_end, $fallback_end )->setTime( 23, 59, 59 );

			if ( $current_end_dt < $current_start_dt ) {
				$current_end_dt = $current_start_dt->setTime( 23, 59, 59 );
			}
			break;
		case 'month_to_date':
		default:
			$period           = 'month_to_date';
			$current_start_dt = $today->modify( 'first day of this month' );
			$current_end_dt   = $today->setTime( 23, 59, 59 );
			break;
	}

	$days = max( 1, (int) floor( ( $current_end_dt->getTimestamp() - $current_start_dt->getTimestamp() ) / DAY_IN_SECONDS ) + 1 );

	if ( 'previous_year' === $compare ) {
		$previous_start_dt = $current_start_dt->modify( '-1 year' );
		$previous_end_dt   = $current_end_dt->modify( '-1 year' );
		$compare_label     = __( 'vs. Previous year', 'dn-burst-funnel-stats' );
	} elseif ( 'previous_period' === $compare ) {
		$previous_end_dt   = $current_start_dt->modify( '-1 second' );
		$previous_start_dt = $previous_end_dt->modify( '-' . ( $days - 1 ) . ' days' )->setTime( 0, 0, 0 );
		$compare_label     = __( 'vs. Previous period', 'dn-burst-funnel-stats' );
	} else {
		$previous_start_dt = $current_start_dt;
		$previous_end_dt   = $current_start_dt;
		$compare_label     = __( 'No comparison', 'dn-burst-funnel-stats' );
	}

	$presets = dn_bfs_get_date_presets();

	return array(
		'range'                => $period,
		'period'               => $period,
		'compare'              => $compare,
		'compare_label'        => $compare_label,
		'current_label'        => isset( $presets[ $period ] ) ? $presets[ $period ] : $presets['month_to_date'],
		'current_range_label'  => dn_bfs_format_range_label( $current_start_dt, $current_end_dt ),
		'previous_range_label' => 'none' === $compare ? '' : dn_bfs_format_range_label( $previous_start_dt, $previous_end_dt ),
		'current_start'        => $current_start_dt->getTimestamp(),
		'current_end'          => $current_end_dt->getTimestamp(),
		'previous_start'       => $previous_start_dt->getTimestamp(),
		'previous_end'         => $previous_end_dt->getTimestamp(),
		'current_start_mysql'  => $current_start_dt->format( 'Y-m-d H:i:s' ),
		'current_end_mysql'    => $current_end_dt->format( 'Y-m-d H:i:s' ),
		'previous_start_mysql' => $previous_start_dt->format( 'Y-m-d H:i:s' ),
		'previous_end_mysql'   => $previous_end_dt->format( 'Y-m-d H:i:s' ),
		'custom_start'         => $current_start_dt->format( 'Y-m-d' ),
		'custom_end'           => $current_end_dt->format( 'Y-m-d' ),
		'days'                 => $days,
	);
}

function dn_bfs_get_range_data_from_request() {
	return dn_bfs_calculate_date_range(
		dn_bfs_get_request_period(),
		dn_bfs_get_request_compare(),
		dn_bfs_get_query_value( 'dn_start' ),
		dn_bfs_get_query_value( 'dn_end' )
	);
}
