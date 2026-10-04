<?php

dn_bfs_it(
	'schema 6 migration removes legacy Burst-era data',
	function () {
		update_option( 'dn_atc_hits_2026_01_01', 5, false );
		update_option( 'dn_bfs_data_last_changed', '1', false );
		update_option( 'dn_burst_funnel_stats_last_refresh', time(), false );
		update_option( 'dn_burst_funnel_stats_url_tracking_settings', array( 'default_group' => 'campaign' ), false );
		set_transient( 'dn_bfs_dash_test', 1, 60 );
		wp_schedule_event( time() + 60, 'hourly', 'dn_burst_funnel_stats_refresh_cache' );
		update_option( 'dn_burst_funnel_stats_schema_version', '5', false );

		dn_burst_funnel_stats_maybe_migrate();

		dn_bfs_assert_same( '6', get_option( 'dn_burst_funnel_stats_schema_version' ) );
		dn_bfs_assert_true( false === get_option( 'dn_atc_hits_2026_01_01' ), 'atc option' );
		dn_bfs_assert_true( false === get_option( 'dn_bfs_data_last_changed' ), 'cache version' );
		dn_bfs_assert_true( false === get_option( 'dn_burst_funnel_stats_last_refresh' ), 'refresh time' );
		dn_bfs_assert_true( false === get_option( 'dn_burst_funnel_stats_url_tracking_settings' ), 'url tracking settings' );
		dn_bfs_assert_true( false === get_transient( 'dn_bfs_dash_test' ), 'legacy transient' );
		dn_bfs_assert_true( false === wp_next_scheduled( 'dn_burst_funnel_stats_refresh_cache' ), 'legacy cron' );
	}
);

dn_bfs_it(
	'plugin version is 3.0.0 and no legacy tracking helpers remain',
	function () {
		dn_bfs_assert_same( '3.0.0', DN_BURST_FUNNEL_STATS_VERSION );

		foreach ( array( 'dn_bfs_is_ip_excluded', 'dn_bfs_is_bot_request', 'dn_bfs_is_selected_page_request', 'dn_bfs_should_track_request' ) as $function ) {
			dn_bfs_assert_true( ! function_exists( $function ), $function );
		}

		dn_bfs_assert_true( function_exists( 'dn_bfs_should_track_product' ), 'product selection kept' );
	}
);
