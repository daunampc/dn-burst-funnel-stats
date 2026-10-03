<?php

dn_bfs_it(
	'schema creates all tracking tables',
	function () {
		global $wpdb;

		foreach ( array( 'visitors', 'sessions', 'pageviews', 'events', 'daily', 'api_keys' ) as $name ) {
			$table = dn_bfs_table( $name );
			dn_bfs_assert_same( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), $name );
		}

		dn_bfs_assert_same( '4', (string) get_option( 'dn_burst_funnel_stats_schema_version' ), 'schema version' );
	}
);

dn_bfs_it(
	'ip hash is stable per day and changes across days',
	function () {
		$day1 = strtotime( '2026-10-01 12:00:00 UTC' );

		dn_bfs_assert_same( dn_bfs_ip_hash( '203.0.113.9', $day1 ), dn_bfs_ip_hash( '203.0.113.9', $day1 + 60 ) );
		dn_bfs_assert_true( dn_bfs_ip_hash( '203.0.113.9', $day1 ) !== dn_bfs_ip_hash( '203.0.113.9', $day1 + 2 * DAY_IN_SECONDS ), 'differs across days' );
		dn_bfs_assert_true( dn_bfs_ip_hash( '203.0.113.9', $day1, 'UA-A' ) !== dn_bfs_ip_hash( '203.0.113.9', $day1, 'UA-B' ), 'differs across user agents' );
		dn_bfs_assert_same( '', dn_bfs_ip_hash( 'unknown', $day1 ) );
	}
);
