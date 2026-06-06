<?php
/**
 * AJAX handlers.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validate AJAX permissions.
 *
 * @return true|WP_Error
 */
function dn_burst_funnel_stats_validate_ajax_request() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', esc_html__( 'You do not have permission to view this report.', 'dn-burst-funnel-stats' ) );
	}

	$nonce = sanitize_text_field( dn_burst_funnel_stats_get_post_value( 'nonce' ) );

	if ( ! wp_verify_nonce( $nonce, 'dn_burst_funnel_stats_ajax' ) ) {
		return new WP_Error( 'invalid_nonce', esc_html__( 'Security check failed. Please refresh the page and try again.', 'dn-burst-funnel-stats' ) );
	}

	return true;
}

/**
 * Send AJAX error when validation fails.
 *
 * @param true|WP_Error $validation Validation result.
 * @return bool
 */
function dn_burst_funnel_stats_maybe_send_ajax_error( $validation ) {
	if ( ! is_wp_error( $validation ) ) {
		return false;
	}

	wp_send_json_error(
		array(
			'message' => $validation->get_error_message(),
		),
		403
	);
}

function dn_burst_funnel_stats_get_post_value( $key, $default = '' ) {
	if ( ! isset( $_POST[ $key ] ) || is_array( $_POST[ $key ] ) ) {
		return $default;
	}

	return wp_unslash( $_POST[ $key ] );
}

/**
 * Apply sanitized date range POST values to the current request.
 *
 * @return void
 */
function dn_burst_funnel_stats_ajax_apply_date_request() {
	$period  = function_exists( 'dn_bfs_sanitize_date_period' )
		? dn_bfs_sanitize_date_period( dn_burst_funnel_stats_get_post_value( 'period', 'month_to_date' ) )
		: 'month_to_date';
	$compare = function_exists( 'dn_bfs_sanitize_compare_mode' )
		? dn_bfs_sanitize_compare_mode( dn_burst_funnel_stats_get_post_value( 'compare', 'previous_year' ) )
		: 'previous_year';
	$start   = sanitize_text_field( dn_burst_funnel_stats_get_post_value( 'start' ) );
	$end     = sanitize_text_field( dn_burst_funnel_stats_get_post_value( 'end' ) );

	$_GET['dn_period']  = $period;
	$_GET['dn_compare'] = $compare;
	$_GET['dn_start']   = $start;
	$_GET['dn_end']     = $end;
}

/**
 * AJAX: load a dashboard tab.
 *
 * @return void
 */
function dn_burst_funnel_stats_ajax_load_tab() {
	$validation = dn_burst_funnel_stats_validate_ajax_request();
	dn_burst_funnel_stats_maybe_send_ajax_error( $validation );

	$tab = dn_burst_dash_sanitize_tab( dn_burst_funnel_stats_get_post_value( 'tab', 'overview' ) );
	dn_burst_funnel_stats_ajax_apply_date_request();

	ob_start();
	$range = dn_burst_dash_get_range_data();
	dn_burst_dash_render_tab_content( $tab, $range );
	$html = ob_get_clean();

	wp_send_json_success(
		array(
			'html'  => $html,
			'tab'   => $tab,
			'range' => $range,
		)
	);
}
add_action( 'wp_ajax_dn_burst_funnel_stats_load_tab', 'dn_burst_funnel_stats_ajax_load_tab' );

/**
 * AJAX: load URL tracking table.
 *
 * @return void
 */
function dn_burst_funnel_stats_ajax_url_tracking() {
	$validation = dn_burst_funnel_stats_validate_ajax_request();
	dn_burst_funnel_stats_maybe_send_ajax_error( $validation );

	$group   = dn_burst_dash_sanitize_group( dn_burst_funnel_stats_get_post_value( 'group', 'campaign' ) );
	$orderby = sanitize_key( dn_burst_funnel_stats_get_post_value( 'orderby', 'visits' ) );
	$order   = sanitize_key( dn_burst_funnel_stats_get_post_value( 'order', 'desc' ) );
	$paged   = absint( dn_burst_funnel_stats_get_post_value( 'paged', 1 ) );

	dn_burst_funnel_stats_ajax_apply_date_request();

	ob_start();
	dn_burst_dash_render_url_tracking_table(
		array(
			'group'   => $group,
			'range'   => dn_burst_dash_get_range_data(),
			'orderby' => $orderby,
			'order'   => $order,
			'paged'   => $paged,
		)
	);
	$html = ob_get_clean();

	wp_send_json_success(
		array(
			'html' => $html,
		)
	);
}
add_action( 'wp_ajax_dn_burst_funnel_stats_url_tracking', 'dn_burst_funnel_stats_ajax_url_tracking' );

/**
 * AJAX: clear and rebuild dashboard cache.
 *
 * @return void
 */
function dn_burst_funnel_stats_ajax_update_now() {
	$validation = dn_burst_funnel_stats_validate_ajax_request();
	dn_burst_funnel_stats_maybe_send_ajax_error( $validation );
	dn_burst_funnel_stats_ajax_apply_date_request();

	dn_burst_dash_refresh_dashboard_cache();
	$status = dn_burst_dash_get_data_status();

	wp_send_json_success(
		array(
			'message'    => esc_html__( 'Data refreshed successfully.', 'dn-burst-funnel-stats' ),
			'lastUpdate' => $status['last_update'] ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['last_update'] ) : '',
			'nextUpdate' => $status['next_update'] ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['next_update'] ) : '',
		)
	);
}
add_action( 'wp_ajax_dn_burst_funnel_stats_update_now', 'dn_burst_funnel_stats_ajax_update_now' );
