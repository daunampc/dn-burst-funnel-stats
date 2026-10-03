<?php
/**
 * WooCommerce order-status rules used by revenue reports.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_wc_report_defaults() {
	return array(
		'sales_excluded_statuses' => array( 'wc-cancelled', 'wc-failed', 'wc-checkout-draft' ),
		'paid_statuses'           => array( 'wc-processing', 'wc-completed' ),
		'balance_statuses'        => array( 'wc-pending', 'wc-on-hold' ),
		'tip_keywords'            => array( 'tip', 'tips', 'gratuity' ),
	);
}

function dn_bfs_sanitize_order_statuses( $value ) {
	$statuses = array();

	foreach ( (array) $value as $status ) {
		$status = sanitize_key( $status );

		if ( 0 === strpos( $status, 'wc-' ) && strlen( $status ) > 3 ) {
			$statuses[] = $status;
		}
	}

	return array_values( array_unique( $statuses ) );
}

function dn_bfs_sanitize_wc_report_settings( $settings ) {
	$settings = is_array( $settings ) ? $settings : array();
	$clean    = dn_bfs_wc_report_defaults();

	foreach ( array( 'sales_excluded_statuses', 'paid_statuses', 'balance_statuses' ) as $key ) {
		if ( array_key_exists( $key, $settings ) ) {
			$clean[ $key ] = dn_bfs_sanitize_order_statuses( $settings[ $key ] );
		}
	}

	if ( array_key_exists( 'tip_keywords', $settings ) ) {
		$clean['tip_keywords'] = array_values( array_unique( array_map( 'strtolower', dn_bfs_normalize_lines( $settings['tip_keywords'] ) ) ) );
	}

	return $clean;
}

function dn_bfs_get_wc_report_settings() {
	$saved = get_option( 'dn_burst_funnel_stats_wc_report_settings', array() );

	return dn_bfs_sanitize_wc_report_settings( is_array( $saved ) ? $saved : array() );
}

function dn_bfs_order_status_key( $order ) {
	return 'wc-' . $order->get_status();
}
