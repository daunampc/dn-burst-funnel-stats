<?php

require_once __DIR__ . '/admin-helpers.php';

function dn_bfs_it_status_by_key( $checks ) {
	$by_key = array();

	foreach ( $checks as $check ) {
		$by_key[ $check['key'] ] = $check;
	}

	return $by_key;
}

function dn_bfs_it_mock_loopback( $collect_ok = true, $tracker_ok = true ) {
	$GLOBALS['dn_bfs_it_loopback'] = function ( $pre, $args, $url ) use ( $collect_ok, $tracker_ok ) {
		$ok = array( 'headers' => array(), 'cookies' => array(), 'filename' => null, 'response' => array( 'code' => 200, 'message' => 'OK' ) );

		if ( false !== strpos( $url, '/dnbfs/v1/collect' ) ) {
			return $collect_ok ? array_merge( $ok, array( 'body' => '{"ok":true}' ) ) : new WP_Error( 'http_request_failed', 'Connection refused' );
		}

		if ( false !== strpos( $url, 'GeoLite2-City.mmdb' ) ) {
			return array_merge( $ok, array( 'body' => '', 'response' => array( 'code' => 403, 'message' => 'Forbidden' ) ) );
		}

		if ( 0 === strpos( $url, home_url() ) ) {
			return array_merge( $ok, array( 'body' => $tracker_ok ? '<script>window.dnbfsPage={};</script>' : '<html></html>' ) );
		}

		return $pre;
	};

	add_filter( 'pre_http_request', $GLOBALS['dn_bfs_it_loopback'], 10, 3 );
}

function dn_bfs_it_unmock_loopback() {
	remove_filter( 'pre_http_request', $GLOBALS['dn_bfs_it_loopback'], 10 );
}

dn_bfs_it(
	'collector answers the status check header without recording anything',
	function () {
		$response = dn_bfs_it_collect( array(), array( 'x_dnbfs_check' => '1' ) );

		dn_bfs_assert_same( 200, $response->get_status() );
		dn_bfs_assert_same( array( 'ok' => true ), $response->get_data() );
		dn_bfs_assert_same( 0, dn_bfs_it_count( 'daily' ) );
	}
);

dn_bfs_it_today(
	'system status reports healthy checks when everything works',
	function () {
		dn_bfs_it_mock_loopback();
		dn_bfs_schedule_crons();
		update_option( 'dnbfs_last_aggregated_date', dn_bfs_last_closable_date( dn_bfs_now() ), false );
		delete_option( 'dnbfs_aggregate_last_error' );

		$checks = dn_bfs_it_status_by_key( dn_bfs_system_status() );

		dn_bfs_assert_same( array( 'tables', 'schema', 'aggregation', 'cron', 'collect', 'tracker', 'geoip', 'geoip_public', 'proxy', 'versions' ), array_keys( $checks ) );
		dn_bfs_assert_same( 'ok', $checks['tables']['status'] );
		dn_bfs_assert_same( 'ok', $checks['schema']['status'] );
		dn_bfs_assert_same( 'ok', $checks['aggregation']['status'] );
		dn_bfs_assert_same( 'ok', $checks['collect']['status'] );
		dn_bfs_assert_same( 'ok', $checks['tracker']['status'] );
		dn_bfs_assert_same( 'info', $checks['versions']['status'] );

		dn_bfs_it_unmock_loopback();
	}
);

dn_bfs_it_today(
	'system status flags aggregation lag, aggregation errors and a blocked endpoint',
	function () {
		dn_bfs_it_mock_loopback( false, false );

		update_option( 'dnbfs_last_aggregated_date', dn_bfs_date_shift( dn_bfs_last_closable_date( dn_bfs_now() ), -5 ), false );
		$checks = dn_bfs_it_status_by_key( dn_bfs_system_status() );
		dn_bfs_assert_same( 'error', $checks['aggregation']['status'] );
		dn_bfs_assert_same( 'error', $checks['collect']['status'] );
		dn_bfs_assert_same( 'warning', $checks['tracker']['status'] );

		update_option( 'dnbfs_last_aggregated_date', dn_bfs_last_closable_date( dn_bfs_now() ), false );
		update_option( 'dnbfs_aggregate_last_error', array( 'date' => '2026-01-01', 'message' => 'Deadlock', 'time' => time() ), false );
		$checks = dn_bfs_it_status_by_key( dn_bfs_system_status() );
		dn_bfs_assert_same( 'error', $checks['aggregation']['status'] );
		dn_bfs_assert_true( false !== strpos( $checks['aggregation']['detail'], 'Deadlock' ), 'error detail' );

		delete_option( 'dnbfs_aggregate_last_error' );
		dn_bfs_it_unmock_loopback();
	}
);

dn_bfs_it(
	'system status flags proxy setups that hide the client IP',
	function () {
		dn_bfs_it_mock_loopback();
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';

		$checks = dn_bfs_it_status_by_key( dn_bfs_system_status() );
		dn_bfs_assert_same( 'warning', $checks['proxy']['status'] );

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		dn_bfs_it_unmock_loopback();
	}
);

