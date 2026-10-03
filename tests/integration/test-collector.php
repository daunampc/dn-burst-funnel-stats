<?php

function dn_bfs_it_pv_body( $overrides = array() ) {
	return array_merge(
		array(
			't'     => 'pv',
			'vid'   => dn_bfs_it_uid( 'visitor-1' ),
			'sid'   => dn_bfs_it_uid( 'session-1' ),
			'path'  => '/',
			'query' => '',
			'ref'   => '',
			'ptype' => 'home',
			'pid'   => 0,
			'sw'    => 1440,
		),
		$overrides
	);
}

dn_bfs_it(
	'collect stores a pageview and returns pvid',
	function () {
		$response = dn_bfs_it_collect( dn_bfs_it_pv_body() );

		dn_bfs_assert_same( 200, $response->get_status() );
		dn_bfs_assert_true( $response->get_data()['pvid'] > 0, 'pvid' );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'pageviews' ) );
	}
);

dn_bfs_it(
	'collect accepts a ping for the returned pageview',
	function () {
		$pvid     = dn_bfs_it_collect( dn_bfs_it_pv_body() )->get_data()['pvid'];
		$response = dn_bfs_it_collect( array( 't' => 'ping', 'vid' => dn_bfs_it_uid( 'visitor-1' ), 'sid' => dn_bfs_it_uid( 'session-1' ), 'pvid' => $pvid, 'engaged' => 12 ) );

		dn_bfs_assert_same( 204, $response->get_status() );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'pageviews', 'time_on_page = 12' ) );
	}
);

dn_bfs_it(
	'collect blocks foreign origin, bots and excluded roles and counts reasons',
	function () {
		dn_bfs_assert_same( 204, dn_bfs_it_collect( dn_bfs_it_pv_body(), array( 'origin' => 'https://evil.test' ) )->get_status() );
		dn_bfs_it_collect( dn_bfs_it_pv_body(), array( 'user_agent' => 'Mozilla/5.0 (compatible; bingbot/2.0)' ) );

		$admin_id                   = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $admin_id, time() + HOUR_IN_SECONDS, 'logged_in' );
		dn_bfs_it_collect( dn_bfs_it_pv_body() );

		dn_bfs_assert_same( 0, dn_bfs_it_count( 'pageviews' ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'daily', "dimension = 'blocked' AND dim_value = 'bad_origin'" ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'daily', "dimension = 'blocked' AND dim_value = 'bot'" ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'daily', "dimension = 'blocked' AND dim_value = 'excluded_role'" ) );
	}
);

dn_bfs_it(
	'collect respects selected page mode',
	function () {
		dn_bfs_it_settings( array( 'page_tracking_mode' => 'selected', 'selected_page_ids' => array( 5 ) ) );

		dn_bfs_it_collect( dn_bfs_it_pv_body( array( 'ptype' => 'other', 'pid' => 6, 'path' => '/about/' ) ) );
		dn_bfs_it_collect( dn_bfs_it_pv_body( array( 'ptype' => 'other', 'pid' => 5, 'path' => '/sale/' ) ) );

		dn_bfs_assert_same( 1, dn_bfs_it_count( 'pageviews' ) );
	}
);

dn_bfs_it(
	'cloudflare connecting IP is used for the session IP hash',
	function () {
		$_SERVER['REMOTE_ADDR']           = '172.68.10.10';
		$_SERVER['HTTP_CF_RAY']           = 'x-SIN';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.44';
		$_SERVER['HTTP_CF_IPCOUNTRY']     = 'VN';

		dn_bfs_it_collect( dn_bfs_it_pv_body() );
		$session = dn_bfs_store_get_session( dn_bfs_it_uid( 'session-1' ) );

		dn_bfs_assert_same( dn_bfs_ip_hash( '198.51.100.44', time(), DN_BFS_IT_UA ), $session['ip_hash'] );
		dn_bfs_assert_same( 'VN', $session['country'] );
	}
);

dn_bfs_it(
	'tracker script and page context are printed on the front end',
	function () {
		$context = dn_bfs_page_context();

		dn_bfs_assert_same( rest_url( 'dnbfs/v1/collect' ), $context['endpoint'] );
		dn_bfs_assert_same( 30, $context['timeout'] );
		dn_bfs_assert_same( 365, $context['cookieDays'] );
	}
);

dn_bfs_it(
	'tracker script is not enqueued for excluded roles',
	function () {
		$admin_id = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];

		try {
			wp_dequeue_script( 'dnbfs-tracker' );
			dn_bfs_enqueue_tracker();
			dn_bfs_assert_true( wp_script_is( 'dnbfs-tracker', 'enqueued' ), 'enqueued for guests' );

			wp_dequeue_script( 'dnbfs-tracker' );
			wp_set_current_user( $admin_id );
			dn_bfs_enqueue_tracker();
			dn_bfs_assert_true( ! wp_script_is( 'dnbfs-tracker', 'enqueued' ), 'not enqueued for administrators' );
		} finally {
			wp_dequeue_script( 'dnbfs-tracker' );
		}
	}
);
