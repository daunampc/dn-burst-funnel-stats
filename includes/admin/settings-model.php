<?php
/**
 * Settings groups behind the admin Settings screen.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DN_BFS_SECRET_MASK', '********' );

function dn_bfs_settings_groups() {
	return array(
		'general'     => array( 'tracking_enabled', 'default_date_range', 'default_compare' ),
		'tracking'    => array( 'excluded_roles', 'excluded_ips', 'client_ip_source', 'page_tracking_mode', 'selected_page_ids', 'product_tracking_mode', 'selected_product_ids', 'session_timeout', 'cookie_days' ),
		'antispam'    => array( 'dedupe_window', 'reload_window', 'limit_pv_per_min', 'limit_sessions_per_hour', 'limit_atc_per_min', 'limit_pv_per_session', 'exclude_bots', 'block_empty_ua', 'custom_bot_user_agents' ),
		'woocommerce' => array( 'force_cart_redirect', 'sales_excluded_statuses', 'paid_statuses', 'balance_statuses', 'tip_keywords' ),
		'geoip'       => array( 'prefer_cloudflare', 'maxmind_license_key' ),
		'data'        => array( 'raw_retention_days' ),
	);
}

function dn_bfs_wc_report_setting_keys() {
	return array( 'sales_excluded_statuses', 'paid_statuses', 'balance_statuses', 'tip_keywords' );
}

function dn_bfs_geoip_status() {
	$path     = dn_bfs_geo_db_path();
	$settings = dn_bfs_get_tracking_settings();

	return array(
		'database'     => file_exists( $path ),
		'size'         => file_exists( $path ) ? (int) filesize( $path ) : 0,
		'updated_at'   => (int) get_option( 'dnbfs_geoip_updated_at', 0 ),
		'attempted_at' => (int) get_option( 'dnbfs_geoip_attempted_at', 0 ),
		'last_error'   => (string) get_option( 'dnbfs_geoip_last_error', '' ),
		'license_set'  => '' !== $settings['maxmind_license_key'],
	);
}

function dn_bfs_settings_group_meta( $group, $tracking ) {
	switch ( $group ) {
		case 'general':
			$presets = dn_bfs_get_date_presets();
			unset( $presets['custom'] );

			return array( 'presets' => $presets );
		case 'tracking':
			return array(
				'invalid_excluded_ips' => array_values( (array) $tracking['invalid_excluded_ips'] ),
				'roles'                => wp_roles()->get_names(),
			);
		case 'woocommerce':
			return array( 'order_statuses' => function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array() );
		case 'geoip':
			return array( 'status' => dn_bfs_geoip_status() );
		case 'data':
			return array(
				'raw_available_from' => dn_bfs_raw_available_from( dn_bfs_now() ),
				'last_aggregated'    => (string) get_option( 'dnbfs_last_aggregated_date', '' ),
			);
	}

	return array();
}

function dn_bfs_get_settings_group( $group ) {
	$groups = dn_bfs_settings_groups();

	if ( ! isset( $groups[ $group ] ) ) {
		return new WP_Error( 'invalid_group', __( 'Unknown settings group.', 'dn-burst-funnel-stats' ), array( 'status' => 404 ) );
	}

	$tracking = dn_bfs_get_tracking_settings();
	$wc       = dn_bfs_get_wc_report_settings();
	$values   = array();

	foreach ( $groups[ $group ] as $key ) {
		$values[ $key ] = in_array( $key, dn_bfs_wc_report_setting_keys(), true ) ? $wc[ $key ] : $tracking[ $key ];
	}

	if ( array_key_exists( 'maxmind_license_key', $values ) ) {
		$values['maxmind_license_key'] = '' !== $values['maxmind_license_key'] ? DN_BFS_SECRET_MASK : '';
	}

	return array(
		'values' => $values,
		'meta'   => dn_bfs_settings_group_meta( $group, $tracking ),
	);
}

function dn_bfs_save_settings_group( $group, $input ) {
	$groups = dn_bfs_settings_groups();

	if ( ! isset( $groups[ $group ] ) ) {
		return new WP_Error( 'invalid_group', __( 'Unknown settings group.', 'dn-burst-funnel-stats' ), array( 'status' => 404 ) );
	}

	if ( ! is_array( $input ) ) {
		return new WP_Error( 'invalid_settings', __( 'Settings must be an object.', 'dn-burst-funnel-stats' ), array( 'status' => 400 ) );
	}

	$keys    = $groups[ $group ];
	$missing = array_values( array_diff( $keys, array_keys( $input ) ) );
	$unknown = array_values( array_diff( array_keys( $input ), $keys ) );

	if ( $missing ) {
		return new WP_Error( 'missing_keys', __( 'Every setting in the group must be sent.', 'dn-burst-funnel-stats' ), array( 'status' => 400, 'missing' => $missing ) );
	}

	if ( $unknown ) {
		return new WP_Error( 'unknown_keys', __( 'Unknown settings were sent.', 'dn-burst-funnel-stats' ), array( 'status' => 400, 'unknown' => $unknown ) );
	}

	$tracking_input = array();
	$wc_input       = array();

	foreach ( $keys as $key ) {
		if ( in_array( $key, dn_bfs_wc_report_setting_keys(), true ) ) {
			$wc_input[ $key ] = $input[ $key ];
		} else {
			$tracking_input[ $key ] = $input[ $key ];
		}
	}

	if ( $tracking_input ) {
		$current = dn_bfs_get_tracking_settings();

		if ( array_key_exists( 'maxmind_license_key', $tracking_input ) && DN_BFS_SECRET_MASK === $tracking_input['maxmind_license_key'] ) {
			$tracking_input['maxmind_license_key'] = $current['maxmind_license_key'];
		}

		$clean = dn_bfs_sanitize_tracking_settings( array_merge( $current, $tracking_input ) );
		update_option( 'dn_burst_funnel_stats_tracking_settings', $clean, false );

		if ( $clean['maxmind_license_key'] !== $current['maxmind_license_key'] ) {
			delete_option( 'dnbfs_geoip_attempted_at' );
		}
	}

	if ( $wc_input ) {
		update_option(
			'dn_burst_funnel_stats_wc_report_settings',
			dn_bfs_sanitize_wc_report_settings( array_merge( dn_bfs_get_wc_report_settings(), $wc_input ) ),
			false
		);
	}

	return dn_bfs_get_settings_group( $group );
}
