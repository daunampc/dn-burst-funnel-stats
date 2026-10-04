<?php

require_once __DIR__ . '/seed.php';

dn_bfs_it_today(
	'cleanup purges raw rows older than retention but keeps order events and recent data',
	function () {
		dn_bfs_it_settings( array( 'raw_retention_days' => 7 ) );
		update_option( 'dnbfs_last_aggregated_date', dn_bfs_date_shift( wp_date( 'Y-m-d', dn_bfs_it_now() ), -1 ), false );

		$old    = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 10 ) ) );
		$recent = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 2 ) ) );
		dn_bfs_it_seed_pageview( $old, '/', dn_bfs_it_day_noon( 10 ) );
		dn_bfs_it_seed_pageview( $recent, '/', dn_bfs_it_day_noon( 2 ) );
		dn_bfs_it_seed_event( $old, 'product_view', dn_bfs_it_day_noon( 10 ), array( 'product_id' => 5 ) );
		dn_bfs_it_seed_event( $old, 'order', dn_bfs_it_day_noon( 10 ), array( 'order_id' => 999001 ) );

		$result = dn_bfs_cleanup_run( dn_bfs_it_now() );

		dn_bfs_assert_same( 1, $result['sessions'] );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'sessions' ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'pageviews' ) );
		dn_bfs_assert_same( 0, dn_bfs_it_count( 'events', "type = 'product_view'" ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'events', "type = 'order'" ) );
	}
);

dn_bfs_it_today(
	'cleanup never purges days that were not aggregated yet',
	function () {
		dn_bfs_it_settings( array( 'raw_retention_days' => 7 ) );
		update_option( 'dnbfs_last_aggregated_date', dn_bfs_date_shift( wp_date( 'Y-m-d', dn_bfs_it_now() ), -12 ), false );

		dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 10 ) ) );
		dn_bfs_cleanup_run( dn_bfs_it_now() );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'sessions' ) );

		delete_option( 'dnbfs_last_aggregated_date' );
		dn_bfs_cleanup_run( dn_bfs_it_now() );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'sessions' ) );
	}
);

dn_bfs_it_today(
	'cleanup removes visitors unseen for 400 days and salts older than yesterday',
	function () {
		global $wpdb;

		$now = dn_bfs_it_now();
		$wpdb->insert( dn_bfs_table( 'visitors' ), array( 'visitor_uid' => md5( 'old' ), 'first_seen' => $now - 500 * DAY_IN_SECONDS, 'last_seen' => $now - 401 * DAY_IN_SECONDS, 'sessions_count' => 1 ) );
		$wpdb->insert( dn_bfs_table( 'visitors' ), array( 'visitor_uid' => md5( 'new' ), 'first_seen' => $now, 'last_seen' => $now, 'sessions_count' => 1 ) );

		$today     = wp_date( 'Y-m-d', $now );
		$yesterday = dn_bfs_date_shift( $today, -1 );
		$old_day   = dn_bfs_date_shift( $today, -5 );

		foreach ( array( $today, $yesterday, $old_day ) as $date ) {
			update_option( 'dnbfs_salt_' . $date, 'salt', false );
		}

		$result = dn_bfs_cleanup_run( $now );

		dn_bfs_assert_same( 1, $result['visitors'] );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'visitors' ) );
		dn_bfs_assert_true( false === get_option( 'dnbfs_salt_' . $old_day ), 'old salt gone' );
		dn_bfs_assert_same( 'salt', get_option( 'dnbfs_salt_' . $yesterday ) );
		dn_bfs_assert_same( 'salt', get_option( 'dnbfs_salt_' . $today ) );
	}
);

dn_bfs_it(
	'expired report and API cache transients are deleted; unexpired ones and other transients stay',
	function () {
		global $wpdb;

		foreach ( array( 'dnbfs_api_c_old', 'dnbfs_api_c_new', 'dnbfs_r_old', 'dnbfs_r_new', 'other_old' ) as $name ) {
			set_transient( $name, array( 1 ), 600 );
		}

		foreach ( array( 'dnbfs_api_c_old', 'dnbfs_r_old', 'other_old' ) as $name ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => time() - 10 ), array( 'option_name' => '_transient_timeout_' . $name ) );
		}

		dn_bfs_assert_same( 4, dn_bfs_purge_expired_cache_transients(), 'value + timeout rows of two transients' );

		$left = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s ORDER BY option_name", '%' . $wpdb->esc_like( 'dnbfs_api_c_' ) . '%', '%' . $wpdb->esc_like( 'dnbfs_r_' ) . '%', '%' . $wpdb->esc_like( 'other_old' ) ) );
		dn_bfs_assert_same(
			array( '_transient_dnbfs_api_c_new', '_transient_dnbfs_r_new', '_transient_other_old', '_transient_timeout_dnbfs_api_c_new', '_transient_timeout_dnbfs_r_new', '_transient_timeout_other_old' ),
			$left
		);

		delete_option( '_transient_other_old' );
		delete_option( '_transient_timeout_other_old' );
	}
);
