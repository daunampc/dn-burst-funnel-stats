<?php

require_once __DIR__ . '/admin-helpers.php';

dn_bfs_it(
	'admin menu registers dashboard and settings pages only',
	function () {
		global $submenu;

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		dn_bfs_it_login_admin();
		$submenu = array();
		dn_bfs_register_admin_menu();

		dn_bfs_assert_same( array( 'dn-burst-funnel-stats', 'dn-burst-funnel-stats-settings' ), array_column( $submenu['dn-burst-funnel-stats'], 2 ) );
	}
);

dn_bfs_it(
	'assets load on plugin pages with the dashboard state',
	function () {
		dn_bfs_it_login_admin();
		$_GET = array( 'page' => 'dn-burst-funnel-stats', 'dn_tab' => 'sources', 'dn_period' => 'yesterday', 'dn_filter' => array( 'campaign' => 'x' ) );
		wp_dequeue_script( 'dn-burst-funnel-stats-admin' );

		dn_bfs_enqueue_admin_assets( 'toplevel_page_dn-burst-funnel-stats' );
		dn_bfs_assert_true( wp_script_is( 'dn-burst-funnel-stats-admin', 'enqueued' ), 'script' );
		dn_bfs_assert_true( in_array( 'jquery-ui-sortable', wp_scripts()->registered['dn-burst-funnel-stats-admin']->deps, true ), 'sortable dependency' );

		$data = dn_bfs_admin_script_data();
		dn_bfs_assert_same( 'dashboard', $data['page'] );
		dn_bfs_assert_same( 'sources', $data['tab'] );
		dn_bfs_assert_same( 'yesterday', $data['period'] );
		dn_bfs_assert_same( array( 'campaign' => 'x' ), (array) $data['filters'] );
		dn_bfs_assert_true( '' !== $data['nonce'], 'nonce' );

		$_GET = array( 'page' => 'woocommerce' );
		wp_dequeue_script( 'dn-burst-funnel-stats-admin' );
		dn_bfs_enqueue_admin_assets( 'woocommerce_page_wc-admin' );
		dn_bfs_assert_true( ! wp_script_is( 'dn-burst-funnel-stats-admin', 'enqueued' ), 'not on other pages' );
		$_GET = array();
	}
);

dn_bfs_it(
	'empty filters are sent as an object',
	function () {
		$_GET = array( 'page' => 'dn-burst-funnel-stats' );
		dn_bfs_assert_same( '{}', wp_json_encode( dn_bfs_admin_script_data()['filters'] ) );
		$_GET = array();
	}
);

dn_bfs_it(
	'the Burst-era dashboard code and AJAX actions are gone',
	function () {
		foreach ( array( 'dn_burst_dash_render_page', 'dn_burst_dash_build_data', 'dn_burst_funnel_stats_ajax_load_tab', 'dn_burst_funnel_stats_render_settings_page', 'dn_burst_funnel_stats_render_import_export_page' ) as $function ) {
			dn_bfs_assert_true( ! function_exists( $function ), $function );
		}

		dn_bfs_assert_true( false === has_action( 'wp_ajax_dn_burst_funnel_stats_load_tab' ), 'old ajax action' );
		dn_bfs_assert_true( false !== has_action( 'woocommerce_add_to_cart', 'dn_bfs_wc_on_add_to_cart' ), 'native ATC hook kept' );
		dn_bfs_assert_true( ! function_exists( 'dn_burst_dash_record_atc_url_groups' ), 'legacy ATC recorder removed' );
	}
);
