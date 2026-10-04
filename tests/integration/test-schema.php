<?php

dn_bfs_it(
	'schema creates all tracking tables',
	function () {
		global $wpdb;

		foreach ( array( 'visitors', 'sessions', 'pageviews', 'events', 'daily', 'api_keys' ) as $name ) {
			$table = dn_bfs_table( $name );
			dn_bfs_assert_same( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), $name );
		}

		dn_bfs_assert_same( DN_BURST_FUNNEL_STATS_SCHEMA_VERSION, (string) get_option( 'dn_burst_funnel_stats_schema_version' ), 'schema version' );
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

dn_bfs_it(
	'migration only bumps the schema version when every table exists',
	function () {
		global $wpdb;

		dn_bfs_assert_true( dn_bfs_schema_tables_exist(), 'all tables exist' );

		$skip_api_keys = function ( $queries ) {
			return array_filter(
				$queries,
				function ( $sql ) {
					return false === strpos( $sql, dn_bfs_table( 'api_keys' ) );
				}
			);
		};

		$wpdb->query( 'DROP TABLE IF EXISTS ' . dn_bfs_table( 'api_keys' ) );
		update_option( 'dn_burst_funnel_stats_schema_version', '3', false );
		add_filter( 'dbdelta_queries', $skip_api_keys );

		try {
			dn_burst_funnel_stats_maybe_migrate();
			dn_bfs_assert_true( ! dn_bfs_schema_tables_exist(), 'api_keys missing' );
			dn_bfs_assert_same( '3', (string) get_option( 'dn_burst_funnel_stats_schema_version' ), 'version kept' );
		} finally {
			remove_filter( 'dbdelta_queries', $skip_api_keys );
		}

		dn_burst_funnel_stats_maybe_migrate();
		dn_bfs_assert_true( dn_bfs_schema_tables_exist(), 'tables restored' );
		dn_bfs_assert_same( DN_BURST_FUNNEL_STATS_SCHEMA_VERSION, (string) get_option( 'dn_burst_funnel_stats_schema_version' ), 'version bumped' );
	}
);
