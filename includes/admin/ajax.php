<?php
/**
 * Admin AJAX handlers for the dashboard.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_ajax_guard() {
	nocache_headers();

	if ( ! dn_bfs_admin_permission() ) {
		wp_send_json_error(
			array(
				'message' => __( 'You do not have permission to view these stats.', 'dn-burst-funnel-stats' ),
				'code'    => 'forbidden',
			),
			403
		);
	}

	if ( ! check_ajax_referer( 'dn_bfs_admin', 'nonce', false ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Security check failed. Refresh the page and try again.', 'dn-burst-funnel-stats' ),
				'code'    => 'invalid_nonce',
			),
			403
		);
	}
}

function dn_bfs_ajax_respond( $payload ) {
	if ( is_wp_error( $payload ) ) {
		$data = $payload->get_error_data();

		wp_send_json_error(
			array(
				'message' => $payload->get_error_message(),
				'code'    => $payload->get_error_code(),
			),
			is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400
		);
	}

	wp_send_json_success( $payload );
}

function dn_bfs_ajax_params() {
	return wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in dn_bfs_ajax_guard().
}

function dn_bfs_ajax_range_meta( $range ) {
	return array(
		'current_label'        => $range['current_label'],
		'current_range_label'  => $range['current_range_label'],
		'compare'              => $range['compare'],
		'compare_label'        => $range['compare_label'],
		'previous_range_label' => $range['previous_range_label'],
	);
}

function dn_bfs_ajax_tab_payload( $params ) {
	$request = dn_bfs_dash_request( $params );

	if ( is_wp_error( $request ) ) {
		return $request;
	}

	list( $range, $filters ) = $request;
	$tab                     = dn_bfs_dash_sanitize_tab( isset( $params['tab'] ) ? $params['tab'] : 'overview' );
	$tabs                    = dn_bfs_dash_tabs();

	return array(
		'html'  => dn_bfs_dash_tab_html( $tab, $range, $filters, $params ),
		'tab'   => $tab,
		'title' => $tabs[ $tab ]['label'],
		'range' => dn_bfs_ajax_range_meta( $range ),
	);
}

function dn_bfs_ajax_table_payload( $params ) {
	$tab = dn_bfs_dash_sanitize_tab( isset( $params['tab'] ) ? $params['tab'] : '' );

	if ( 'overview' === $tab ) {
		return dn_bfs_request_error( 'invalid_tab', __( 'This tab has no table.', 'dn-burst-funnel-stats' ) );
	}

	$request = dn_bfs_dash_request( $params );

	if ( is_wp_error( $request ) ) {
		return $request;
	}

	list( $range, $filters ) = $request;
	$tabs                    = dn_bfs_dash_tabs();
	$dimensions              = $tabs[ $tab ]['dimensions'];
	$dimension               = isset( $params['dimension'] ) && in_array( $params['dimension'], $dimensions, true ) ? $params['dimension'] : $dimensions[0];

	return array( 'html' => dn_bfs_dash_table_html( $tab, $dimension, $range, $filters, $params ) );
}

function dn_bfs_ajax_drilldown_payload( $params ) {
	$request = dn_bfs_dash_request( $params );

	if ( is_wp_error( $request ) ) {
		return $request;
	}

	list( $range, $filters ) = $request;

	return array(
		'html' => dn_bfs_dash_drilldown_html(
			$range,
			$filters,
			isset( $params['dimension'] ) && is_scalar( $params['dimension'] ) ? (string) $params['dimension'] : '',
			isset( $params['value'] ) && is_scalar( $params['value'] ) ? (string) $params['value'] : ''
		),
	);
}

function dn_bfs_ajax_realtime_payload() {
	return dn_bfs_report_realtime();
}

function dn_bfs_ajax_save_cards_payload( $user_id, $params ) {
	if ( ! empty( $params['reset'] ) ) {
		$cards = dn_bfs_save_user_cards( $user_id, null );
	} else {
		$cards = dn_bfs_save_user_cards( $user_id, isset( $params['cards'] ) && is_array( $params['cards'] ) ? array_values( $params['cards'] ) : array() );
	}

	return is_wp_error( $cards ) ? $cards : array( 'cards' => $cards );
}

function dn_bfs_ajax_filter_values_payload( $params ) {
	global $wpdb;

	$dimension = isset( $params['dimension'] ) ? (string) $params['dimension'] : '';

	if ( ! in_array( $dimension, dn_bfs_filter_dimensions(), true ) ) {
		return dn_bfs_request_error( 'invalid_dimension', __( 'Suggestions are only available for filter dimensions.', 'dn-burst-funnel-stats' ) );
	}

	$search = dn_bfs_truncate( sanitize_text_field( isset( $params['search'] ) && is_scalar( $params['search'] ) ? (string) $params['search'] : '' ), 100 );
	$now    = dn_bfs_now();
	$totals = array();

	$daily = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT dim_value, SUM(sessions) AS sessions FROM ' . dn_bfs_table( 'daily' ) . '
			WHERE dimension = %s AND date >= %s AND dim_value <> %s AND dim_value LIKE %s
			GROUP BY dim_hash, dim_value ORDER BY SUM(sessions) DESC, dim_value ASC LIMIT %d',
			$dimension,
			dn_bfs_date_shift( wp_date( 'Y-m-d', $now ), -90 ),
			'',
			'%' . $wpdb->esc_like( $search ) . '%',
			200
		),
		ARRAY_A
	);

	foreach ( (array) $daily as $row ) {
		$totals[ (string) $row['dim_value'] ] = (int) $row['sessions'];
	}

	list( $today_start ) = dn_bfs_day_bounds( wp_date( 'Y-m-d', $now ) );

	foreach ( dn_bfs_report_raw_rows( $today_start, $now + 1, $dimension, array() ) as $value => $metrics ) {
		$value = (string) $value;

		if ( '' === $value || ( '' !== $search && false === stripos( $value, $search ) ) ) {
			continue;
		}

		$totals[ $value ] = ( isset( $totals[ $value ] ) ? $totals[ $value ] : 0 ) + (int) $metrics['sessions'];
	}

	arsort( $totals );

	return array( 'values' => array_slice( array_map( 'strval', array_keys( $totals ) ), 0, 20 ) );
}

function dn_bfs_ajax_update_now_payload() {
	$result = dn_bfs_aggregate_run();
	$labels = dn_bfs_dash_status_labels();
	$ok     = ! empty( $result['ok'] );

	if ( $ok ) {
		$message = __( 'Data refreshed.', 'dn-burst-funnel-stats' );
	} elseif ( isset( $result['reason'] ) && 'locked' === $result['reason'] ) {
		$message = __( 'An update is already running. Try again in a minute.', 'dn-burst-funnel-stats' );
	} else {
		$message = __( 'The update failed. See Settings → System.', 'dn-burst-funnel-stats' );
	}

	return array(
		'ok'         => $ok,
		'message'    => $message,
		'lastUpdate' => $labels['last'],
		'nextUpdate' => $labels['next'],
	);
}

function dn_bfs_ajax_load_tab() {
	dn_bfs_ajax_guard();
	dn_bfs_ajax_respond( dn_bfs_ajax_tab_payload( dn_bfs_ajax_params() ) );
}
add_action( 'wp_ajax_dn_bfs_load_tab', 'dn_bfs_ajax_load_tab' );

function dn_bfs_ajax_table() {
	dn_bfs_ajax_guard();
	dn_bfs_ajax_respond( dn_bfs_ajax_table_payload( dn_bfs_ajax_params() ) );
}
add_action( 'wp_ajax_dn_bfs_table', 'dn_bfs_ajax_table' );

function dn_bfs_ajax_drilldown() {
	dn_bfs_ajax_guard();
	dn_bfs_ajax_respond( dn_bfs_ajax_drilldown_payload( dn_bfs_ajax_params() ) );
}
add_action( 'wp_ajax_dn_bfs_drilldown', 'dn_bfs_ajax_drilldown' );

function dn_bfs_ajax_realtime() {
	dn_bfs_ajax_guard();
	dn_bfs_ajax_respond( dn_bfs_ajax_realtime_payload() );
}
add_action( 'wp_ajax_dn_bfs_realtime', 'dn_bfs_ajax_realtime' );

function dn_bfs_ajax_save_cards() {
	dn_bfs_ajax_guard();
	dn_bfs_ajax_respond( dn_bfs_ajax_save_cards_payload( get_current_user_id(), dn_bfs_ajax_params() ) );
}
add_action( 'wp_ajax_dn_bfs_save_cards', 'dn_bfs_ajax_save_cards' );

function dn_bfs_ajax_filter_values() {
	dn_bfs_ajax_guard();
	dn_bfs_ajax_respond( dn_bfs_ajax_filter_values_payload( dn_bfs_ajax_params() ) );
}
add_action( 'wp_ajax_dn_bfs_filter_values', 'dn_bfs_ajax_filter_values' );

function dn_bfs_ajax_update_now() {
	dn_bfs_ajax_guard();
	dn_bfs_ajax_respond( dn_bfs_ajax_update_now_payload() );
}
add_action( 'wp_ajax_dn_bfs_update_now', 'dn_bfs_ajax_update_now' );
