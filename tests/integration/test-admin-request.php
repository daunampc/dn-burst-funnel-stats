<?php

require_once __DIR__ . '/admin-helpers.php';

dn_bfs_it(
	'range parsing accepts presets, custom ranges and defaults',
	function () {
		$range = dn_bfs_parse_range( array( 'period' => 'last_week', 'compare' => 'none' ) );
		dn_bfs_assert_same( 'last_week', $range['period'] );
		dn_bfs_assert_same( 'none', $range['compare'] );

		$custom = dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '2026-01-01', 'end' => '2026-01-31' ) );
		dn_bfs_assert_same( '2026-01-01', $custom['custom_start'] );
		dn_bfs_assert_same( '2026-01-31', $custom['custom_end'] );

		dn_bfs_it_settings( array( 'default_date_range' => 'yesterday', 'default_compare' => 'previous_period' ) );
		$default = dn_bfs_parse_range( array() );
		dn_bfs_assert_same( 'yesterday', $default['period'] );
		dn_bfs_assert_same( 'previous_period', $default['compare'] );

		$meta = dn_bfs_range_meta( $custom );
		dn_bfs_assert_same( array( 'period', 'compare', 'start', 'end', 'label', 'range_label', 'compare_label', 'previous_range_label' ), array_keys( $meta ) );
	}
);

dn_bfs_it(
	'invalid range, filter and metric parameters return specific errors',
	function () {
		$cases = array(
			array( dn_bfs_parse_range( array( 'period' => 'nope' ) ), 'invalid_period' ),
			array( dn_bfs_parse_range( array( 'compare' => 'nope' ) ), 'invalid_compare' ),
			array( dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '2026-13-01', 'end' => '2026-01-02' ) ), 'invalid_date' ),
			array( dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '2026-02-01', 'end' => '2026-01-01' ) ), 'invalid_date' ),
			array( dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '2020-01-01', 'end' => '2026-01-01' ) ), 'range_too_long' ),
			array( dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '2026-02-30', 'end' => '2026-03-05' ) ), 'invalid_date' ),
			array( dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '2024-01-01', 'end' => '2026-01-02' ) ), 'range_too_long' ),
			array( dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '0001-01-01', 'end' => '9999-12-31' ) ), 'range_too_long' ),
			array( dn_bfs_parse_metrics( array( 'sessions', array( 'x' ) ) ), 'invalid_metric' ),
			array( dn_bfs_parse_filters( array( 'filter' => array( 'browser' => 'Chrome' ) ) ), 'invalid_filter' ),
			array( dn_bfs_parse_filters( array( 'filter' => array( 'campaign' => ' ' ) ) ), 'invalid_filter' ),
			array( dn_bfs_parse_filters( array( 'filter' => 'campaign' ) ), 'invalid_filter' ),
			array( dn_bfs_parse_metrics( 'sessions,nope' ), 'invalid_metric' ),
		);

		foreach ( $cases as $case ) {
			dn_bfs_assert_true( is_wp_error( $case[0] ), $case[1] );
			dn_bfs_assert_same( $case[1], $case[0]->get_error_code() );
			dn_bfs_assert_same( 400, $case[0]->get_error_data()['status'] );
		}
	}
);

dn_bfs_it(
	'valid filters and metrics are normalized',
	function () {
		dn_bfs_assert_same( array( 'campaign' => 'sale-10', 'device' => 'mobile' ), dn_bfs_parse_filters( array( 'filter' => array( 'device' => 'mobile', 'campaign' => ' sale-10 ' ) ) ) );
		dn_bfs_assert_same( array(), dn_bfs_parse_filters( array() ) );
		dn_bfs_assert_same( array( 'sessions', 'bounce_rate' ), dn_bfs_parse_metrics( array( 'sessions', 'bounce_rate', 'sessions' ) ) );
		dn_bfs_assert_same( array( 'sessions', 'orders', 'revenue' ), dn_bfs_parse_metrics( '' ) );
		dn_bfs_assert_same( array( 'sessions', 'orders' ), dn_bfs_parse_metrics( array( ' sessions ', 'orders' ) ) );
	}
);

dn_bfs_it(
	'query parameters from the dashboard URL are mapped',
	function () {
		$params = dn_bfs_params_from_query(
			array(
				'dn_period'  => 'custom',
				'dn_compare' => 'none',
				'dn_start'   => '2026-01-01',
				'dn_end'     => '2026-01-02',
				'dn_tab'     => 'sources',
				'dn_filter'  => array( 'campaign' => 'x' ),
			)
		);

		dn_bfs_assert_same( 'custom', $params['period'] );
		dn_bfs_assert_same( 'sources', $params['tab'] );
		dn_bfs_assert_same( array( 'campaign' => 'x' ), $params['filter'] );
		dn_bfs_assert_same( array(), dn_bfs_params_from_query( array() )['filter'] );
	}
);

dn_bfs_it(
	'admin permission follows the capability filter',
	function () {
		wp_set_current_user( 0 );
		dn_bfs_assert_same( false, dn_bfs_admin_permission() );

		dn_bfs_it_login_admin();
		dn_bfs_assert_same( true, dn_bfs_admin_permission() );

		$deny = function () { return 'do_not_exist_cap'; };
		add_filter( 'dn_bfs_capability', $deny );
		dn_bfs_assert_same( false, dn_bfs_admin_permission() );
		remove_filter( 'dn_bfs_capability', $deny );
	}
);

dn_bfs_it(
	'custom range accepts exactly 731 days inclusive and rejects 732',
	function () {
		$ok = dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '2024-01-01', 'end' => '2025-12-31' ) );
		dn_bfs_assert_true( ! is_wp_error( $ok ), '731 days accepted' );
		dn_bfs_assert_same( '2025-12-31', $ok['custom_end'] );

		$bad = dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '2024-01-01', 'end' => '2026-01-01' ) );
		dn_bfs_assert_true( is_wp_error( $bad ), '732 days rejected' );
		dn_bfs_assert_same( 'range_too_long', $bad->get_error_code() );
	}
);
