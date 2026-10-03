<?php

dn_bfs_it(
	'uninstall drops tables, options, crons and the GeoIP folder',
	function () {
		global $wpdb;

		update_option( 'dnbfs_last_aggregated_date', '2026-01-01', false );
		update_option( 'dn_bfs_data_last_changed', '1', false );
		update_option( 'dn_atc_hits_2026_01_01', 3, false );
		set_transient( 'dnbfs_r_test', array( 1 ), 60 );
		dn_bfs_schedule_crons();
		wp_mkdir_p( dirname( dn_bfs_geo_db_path() ) );
		file_put_contents( dn_bfs_geo_db_path(), 'x' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'dn-burst-funnel-stats/dn-burst-funnel-stats.php' );
		}

		include dirname( __DIR__, 2 ) . '/uninstall.php';

		foreach ( dn_bfs_schema_tables() as $name ) {
			dn_bfs_assert_same( null, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', dn_bfs_table( $name ) ) ), $name );
		}

		dn_bfs_assert_true( false === get_option( 'dnbfs_last_aggregated_date' ), 'dnbfs option' );
		dn_bfs_assert_true( false === get_option( 'dn_bfs_data_last_changed' ), 'dn_bfs option' );
		dn_bfs_assert_true( false === get_option( 'dn_atc_hits_2026_01_01' ), 'legacy option' );
		dn_bfs_assert_true( false === get_option( 'dn_burst_funnel_stats_tracking_settings' ), 'settings' );
		dn_bfs_assert_true( false === get_transient( 'dnbfs_r_test' ), 'transient' );
		dn_bfs_assert_true( false === wp_next_scheduled( 'dnbfs_aggregate' ), 'cron' );
		dn_bfs_assert_true( ! file_exists( dn_bfs_geo_db_path() ), 'geoip file' );

		// Restore the environment for the remaining tests.
		dn_bfs_install_schema();
		update_option( 'dn_burst_funnel_stats_schema_version', DN_BURST_FUNNEL_STATS_SCHEMA_VERSION, false );
		dn_bfs_it_settings( array() );
		dn_bfs_schedule_crons();
	}
);
