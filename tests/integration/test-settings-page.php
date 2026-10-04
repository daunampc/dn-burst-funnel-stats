<?php

require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/admin-helpers.php';

dn_bfs_it(
	'every settings group has field definitions matching the model keys',
	function () {
		$fields = dn_bfs_settings_fields();

		foreach ( dn_bfs_settings_groups() as $group => $keys ) {
			dn_bfs_assert_same( $keys, array_column( $fields[ $group ], 'key' ), $group );
		}

		dn_bfs_assert_same( array( 'general', 'tracking', 'antispam', 'woocommerce', 'geoip', 'data', 'system' ), array_keys( dn_bfs_settings_tabs() ) );
	}
);

dn_bfs_it(
	'form input fills unchecked checkboxes and empty lists',
	function () {
		$input = dn_bfs_settings_input_from_post( 'antispam', array( 'dn_bfs' => array( 'dedupe_window' => '600', 'custom_bot_user_agents' => "spider\nfoo" ) ) );

		dn_bfs_assert_same( 600, $input['dedupe_window'] );
		dn_bfs_assert_same( 0, $input['exclude_bots'] );
		dn_bfs_assert_same( 0, $input['block_empty_ua'] );
		dn_bfs_assert_same( "spider\nfoo", $input['custom_bot_user_agents'] );

		$tracking = dn_bfs_settings_input_from_post( 'tracking', array( 'dn_bfs' => array() ) );
		dn_bfs_assert_same( array(), $tracking['excluded_roles'] );
		dn_bfs_assert_same( array(), $tracking['selected_page_ids'] );
	}
);

dn_bfs_it(
	'saving from a posted form stores the whole group',
	function () {
		dn_bfs_assert_same( 'saved', dn_bfs_settings_save_from_post( 'antispam', array( 'dn_bfs' => array( 'dedupe_window' => '600', 'exclude_bots' => '1' ) ) ) );

		$settings = dn_bfs_get_tracking_settings();
		dn_bfs_assert_same( 600, $settings['dedupe_window'] );
		dn_bfs_assert_same( 1, $settings['exclude_bots'] );
		dn_bfs_assert_same( 0, $settings['block_empty_ua'] );
		dn_bfs_assert_same( 'invalid_group', dn_bfs_settings_save_from_post( 'nope', array() ) );
	}
);

dn_bfs_it_today(
	'data tools re-aggregate, purge with confirmation and round-trip settings',
	function () {
		dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 2 ) ) );
		$day = wp_date( 'Y-m-d', dn_bfs_it_day_noon( 2 ) );

		$result = dn_bfs_reaggregate_range( $day, $day );
		dn_bfs_assert_same( 1, $result['queued'] );
		dn_bfs_assert_same( 'range_too_long', dn_bfs_reaggregate_range( '2025-01-01', '2025-12-31' )->get_error_code() );
		dn_bfs_assert_same( 'invalid_date', dn_bfs_reaggregate_range( 'x', $day )->get_error_code() );

		dn_bfs_assert_same( 'confirm_required', dn_bfs_purge_all_data( 'yes' )->get_error_code() );
		dn_bfs_assert_same( true, dn_bfs_purge_all_data( 'DELETE' ) );
		dn_bfs_assert_same( 0, dn_bfs_it_count( 'sessions' ) );

		dn_bfs_it_settings( array( 'maxmind_license_key' => 'SECRET_KEY', 'session_timeout' => 45 ) );
		$export = dn_bfs_export_settings();
		dn_bfs_assert_true( ! isset( $export['settings']['tracking']['maxmind_license_key'] ), 'no license' );

		dn_bfs_it_settings( array( 'maxmind_license_key' => 'SECRET_KEY', 'session_timeout' => 30 ) );
		dn_bfs_assert_same( array( 'tracking', 'woocommerce_report' ), dn_bfs_import_settings( $export )['imported'] );
		dn_bfs_assert_same( 45, dn_bfs_get_tracking_settings()['session_timeout'] );
		dn_bfs_assert_same( 'SECRET_KEY', dn_bfs_get_tracking_settings()['maxmind_license_key'] );
		dn_bfs_assert_same( 'invalid_import', dn_bfs_import_settings( array( 'meta' => array( 'plugin' => 'x' ) ) )->get_error_code() );

		dn_bfs_assert_true( isset( dn_bfs_data_stats()['tables']['sessions'] ), 'stats' );
	}
);

dn_bfs_it_today(
	'data tasks map to notices and blocked stats sum reasons',
	function () {
		dn_bfs_assert_same( array( 'tab' => 'data', 'notice' => 'confirm_required' ), dn_bfs_settings_data_task( 'purge', array( 'confirm' => 'no' ), array() ) );
		dn_bfs_assert_same( array( 'tab' => 'data', 'notice' => 'missing_file' ), dn_bfs_settings_data_task( 'import', array(), array() ) );
		dn_bfs_assert_same( array( 'tab' => 'geoip', 'notice' => 'no_license' ), dn_bfs_settings_data_task( 'geoip_update', array(), array() ) );

		dn_bfs_store_count_blocked( 'bot', dn_bfs_it_now() );
		dn_bfs_store_count_blocked( 'bot', dn_bfs_it_now() );
		dn_bfs_store_count_blocked( 'bad_origin', dn_bfs_it_now() );
		dn_bfs_assert_same( array( 'bot' => 2, 'bad_origin' => 1 ), dn_bfs_blocked_stats( 7 ) );

		dn_bfs_assert_same( 'success', dn_bfs_settings_notice( 'saved' )[0] );
		dn_bfs_assert_same( 'error', dn_bfs_settings_notice( 'geoip_checksum_mismatch' )[0] );
	}
);

dn_bfs_it(
	'settings page renders each tab',
	function () {
		dn_bfs_it_login_admin();

		foreach ( array( 'general', 'tracking', 'antispam', 'woocommerce', 'geoip', 'data', 'system' ) as $tab ) {
			$_GET = array( 'page' => 'dn-burst-funnel-stats-settings', 'tab' => $tab );

			add_filter( 'pre_http_request', '__return_empty_array' );
			ob_start();
			dn_bfs_render_settings_page();
			$html = ob_get_clean();
			remove_filter( 'pre_http_request', '__return_empty_array' );

			dn_bfs_assert_true( (bool) preg_match( '/nav-tab nav-tab-active"[^>]*>/', $html ), $tab . ' active tab' );

			if ( isset( dn_bfs_settings_groups()[ $tab ] ) ) {
				foreach ( dn_bfs_settings_groups()[ $tab ] as $key ) {
					dn_bfs_assert_true( false !== strpos( $html, 'dn_bfs[' . $key . ']' ), $tab . ': ' . $key );
				}
			}
		}

		dn_bfs_assert_true( false !== strpos( $html, 'dn-burst-status-badge' ), 'system checks' );
		$_GET = array();
	}
);
