<?php

dn_bfs_it(
	'uninstall drops tables, options, crons and the GeoIP folder',
	function () {
		global $wpdb;

		update_option( 'dnbfs_last_aggregated_date', '2026-01-01', false );
		update_option( 'dn_bfs_data_last_changed', '1', false );
		update_option( 'dn_atc_hits_2026_01_01', 3, false );
		set_transient( 'dnbfs_r_test', array( 1 ), 60 );
		dn_bfs_api_rate_check( array( 'id' => 987, 'rate_limit' => 5 ), 1800000000 );
		dn_bfs_assert_same( '30000000:1', get_option( 'dnbfs_api_rl_987' ), 'rate-limit counter row exists' );
		dn_bfs_schedule_crons();
		wp_mkdir_p( dirname( dn_bfs_geo_db_path() ) . '/nested' );
		file_put_contents( dn_bfs_geo_db_path(), 'x' );
		file_put_contents( dirname( dn_bfs_geo_db_path() ) . '/.htaccess', 'x' );
		file_put_contents( dirname( dn_bfs_geo_db_path() ) . '/nested/file.tmp', 'x' );

		$order = wc_create_order();
		$order->update_meta_data( '_dnbfs_session_uid', 'sid' );
		$order->update_meta_data( '_dnbfs_visitor_uid', 'vid' );
		$order->save();
		$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
		$has_hpos  = $hpos_meta === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_meta ) );

		if ( $has_hpos ) {
			$wpdb->insert( $hpos_meta, array( 'order_id' => $order->get_id(), 'meta_key' => '_dnbfs_visitor_uid', 'meta_value' => 'vid' ) );
		}

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'dn-burst-funnel-stats/dn-burst-funnel-stats.php' );
		}

		include dirname( __DIR__, 2 ) . '/uninstall.php';

		try {
			foreach ( dn_bfs_schema_tables() as $name ) {
				dn_bfs_assert_same( null, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', dn_bfs_table( $name ) ) ), $name );
			}

			dn_bfs_assert_true( false === get_option( 'dnbfs_last_aggregated_date' ), 'dnbfs option' );
			dn_bfs_assert_true( false === get_option( 'dn_bfs_data_last_changed' ), 'dn_bfs option' );
			dn_bfs_assert_true( false === get_option( 'dn_atc_hits_2026_01_01' ), 'legacy option' );
			dn_bfs_assert_true( false === get_option( 'dn_burst_funnel_stats_tracking_settings' ), 'settings' );
			dn_bfs_assert_true( false === get_transient( 'dnbfs_r_test' ), 'transient' );
			dn_bfs_assert_true( false === get_option( 'dnbfs_api_rl_987' ), 'api rate-limit counter' );
			dn_bfs_assert_true( false === wp_next_scheduled( 'dnbfs_aggregate' ), 'cron' );
			dn_bfs_assert_true( ! file_exists( dn_bfs_geo_db_path() ), 'geoip file' );
			dn_bfs_assert_true( ! file_exists( dirname( dn_bfs_geo_db_path() ) ), 'geoip folder with dotfiles and subfolders' );
			dn_bfs_assert_same( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)", '_dnbfs_session_uid', '_dnbfs_visitor_uid' ) ), 'order postmeta' );

			if ( $has_hpos ) {
				dn_bfs_assert_same( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$hpos_meta} WHERE meta_key IN (%s, %s)", '_dnbfs_session_uid', '_dnbfs_visitor_uid' ) ), 'hpos order meta' );
			}
		} finally {
			// Restore the environment for the remaining tests.
			dn_bfs_install_schema();
			update_option( 'dn_burst_funnel_stats_schema_version', DN_BURST_FUNNEL_STATS_SCHEMA_VERSION, false );
			dn_bfs_it_settings( array() );
			dn_bfs_schedule_crons();
			$order->delete( true );
		}
	}
);
