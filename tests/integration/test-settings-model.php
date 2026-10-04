<?php

function dn_bfs_it_group_values( $group ) {
	return dn_bfs_get_settings_group( $group )['values'];
}

dn_bfs_it(
	'settings groups expose every key and reject unknown groups',
	function () {
		dn_bfs_assert_same( array( 'general', 'tracking', 'antispam', 'woocommerce', 'geoip', 'data' ), array_keys( dn_bfs_settings_groups() ) );

		foreach ( dn_bfs_settings_groups() as $group => $keys ) {
			dn_bfs_assert_same( $keys, array_keys( dn_bfs_it_group_values( $group ) ), $group );
		}

		$error = dn_bfs_get_settings_group( 'nope' );
		dn_bfs_assert_same( 'invalid_group', $error->get_error_code() );
		dn_bfs_assert_same( 404, $error->get_error_data()['status'] );
	}
);

dn_bfs_it(
	'saving a group requires every key and rejects unknown keys',
	function () {
		$missing = dn_bfs_save_settings_group( 'general', array( 'tracking_enabled' => 1 ) );
		dn_bfs_assert_same( 'missing_keys', $missing->get_error_code() );
		dn_bfs_assert_same( array( 'default_date_range', 'default_compare' ), $missing->get_error_data()['missing'] );

		$unknown = dn_bfs_save_settings_group( 'general', array( 'tracking_enabled' => 1, 'default_date_range' => 'today', 'default_compare' => 'none', 'hack' => 1 ) );
		dn_bfs_assert_same( 'unknown_keys', $unknown->get_error_code() );

		$saved = dn_bfs_save_settings_group( 'general', array( 'tracking_enabled' => 0, 'default_date_range' => 'last_week', 'default_compare' => 'previous_period' ) );
		dn_bfs_assert_same( 0, $saved['values']['tracking_enabled'] );
		dn_bfs_assert_same( 'last_week', dn_bfs_get_tracking_settings()['default_date_range'] );
	}
);

dn_bfs_it(
	'tracking group keeps empty lists and reports invalid IP rules',
	function () {
		$values                   = dn_bfs_it_group_values( 'tracking' );
		$values['excluded_roles'] = array();
		$values['excluded_ips']   = array( '10.0.0.0/8', 'not-an-ip' );

		$saved = dn_bfs_save_settings_group( 'tracking', $values );

		dn_bfs_assert_same( array(), $saved['values']['excluded_roles'] );
		dn_bfs_assert_same( array( '10.0.0.0/8' ), $saved['values']['excluded_ips'] );
		dn_bfs_assert_same( array( 'not-an-ip' ), $saved['meta']['invalid_excluded_ips'] );
	}
);

dn_bfs_it(
	'license key is masked, kept by the mask and clears the backoff when changed',
	function () {
		$values                        = dn_bfs_it_group_values( 'geoip' );
		$values['maxmind_license_key'] = 'ABC_123';
		dn_bfs_save_settings_group( 'geoip', $values );

		dn_bfs_assert_same( DN_BFS_SECRET_MASK, dn_bfs_it_group_values( 'geoip' )['maxmind_license_key'] );

		update_option( 'dnbfs_geoip_attempted_at', time(), false );
		$values                        = dn_bfs_it_group_values( 'geoip' );
		$values['maxmind_license_key'] = DN_BFS_SECRET_MASK;
		dn_bfs_save_settings_group( 'geoip', $values );

		dn_bfs_assert_same( 'ABC_123', dn_bfs_get_tracking_settings()['maxmind_license_key'] );
		dn_bfs_assert_true( false !== get_option( 'dnbfs_geoip_attempted_at' ), 'kept backoff when unchanged' );

		$values['maxmind_license_key'] = 'NEW_KEY';
		dn_bfs_save_settings_group( 'geoip', $values );
		dn_bfs_assert_true( false === get_option( 'dnbfs_geoip_attempted_at' ), 'backoff cleared' );

		$values['maxmind_license_key'] = '';
		dn_bfs_save_settings_group( 'geoip', $values );
		dn_bfs_assert_same( '', dn_bfs_it_group_values( 'geoip' )['maxmind_license_key'] );
	}
);

dn_bfs_it(
	'woocommerce group writes report settings and tracking flags to their own options',
	function () {
		$values                            = dn_bfs_it_group_values( 'woocommerce' );
		$values['force_cart_redirect']     = 1;
		$values['paid_statuses']           = array( 'wc-completed' );
		$values['tip_keywords']            = array( 'Tiền Boa' );

		$saved = dn_bfs_save_settings_group( 'woocommerce', $values );

		dn_bfs_assert_same( 1, dn_bfs_get_tracking_settings()['force_cart_redirect'] );
		dn_bfs_assert_same( array( 'wc-completed' ), dn_bfs_get_wc_report_settings()['paid_statuses'] );
		dn_bfs_assert_same( array( 'tiền boa' ), $saved['values']['tip_keywords'] );
		dn_bfs_assert_true( isset( $saved['meta']['order_statuses']['wc-refunded'] ), 'statuses listed' );
		delete_option( 'dn_burst_funnel_stats_wc_report_settings' );
	}
);

dn_bfs_it(
	'data and geoip groups expose status meta',
	function () {
		$data = dn_bfs_get_settings_group( 'data' );
		dn_bfs_assert_same( 90, $data['values']['raw_retention_days'] );
		dn_bfs_assert_same( dn_bfs_raw_available_from( dn_bfs_now() ), $data['meta']['raw_available_from'] );

		$geo = dn_bfs_get_settings_group( 'geoip' );
		dn_bfs_assert_same( false, $geo['meta']['status']['license_set'] );
	}
);
