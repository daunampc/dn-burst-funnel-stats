<?php

require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/admin-helpers.php';

dn_bfs_it_today(
	'dashboard widget data compares today with yesterday and counts online visitors',
	function () {
		$now = dn_bfs_it_now();
		$s   = dn_bfs_it_seed_session( array( 'started_at' => $now - 60, 'last_activity' => $now - 30 ) );
		dn_bfs_it_seed_pageview( $s, '/', $now - 60 );
		$y = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 1 ) ) );
		dn_bfs_it_seed_pageview( $y, '/', dn_bfs_it_day_noon( 1 ) );

		$data = dn_bfs_dashboard_widget_data( $now );

		dn_bfs_assert_same( 1, $data['online'] );
		dn_bfs_assert_same( 1, $data['visitors']['today'] );
		dn_bfs_assert_same( 1, $data['visitors']['yesterday'] );
		dn_bfs_assert_same( 0, $data['orders']['today'] );
	}
);

dn_bfs_it_today(
	'dashboard widget renders the table and the live online badge',
	function () {
		ob_start();
		dn_bfs_render_dashboard_widget();
		$html = ob_get_clean();

		dn_bfs_assert_true( false !== strpos( $html, 'data-dnbfs-online' ), 'online badge' );
		dn_bfs_assert_true( false !== strpos( $html, 'page=dn-burst-funnel-stats' ), 'dashboard link' );
	}
);

dn_bfs_it(
	'dashboard widget is registered only for users with the capability',
	function () {
		global $wp_meta_boxes;

		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		set_current_screen( 'dashboard' );
		$wp_meta_boxes = array();

		wp_set_current_user( 0 );
		dn_bfs_register_dashboard_widget();
		dn_bfs_assert_true( empty( $wp_meta_boxes['dashboard']['normal']['core']['dnbfs_overview'] ), 'hidden for guests' );

		dn_bfs_it_login_admin();
		dn_bfs_register_dashboard_widget();
		dn_bfs_assert_true( ! empty( $wp_meta_boxes['dashboard']['normal']['core']['dnbfs_overview'] ), 'shown for admins' );
	}
);
