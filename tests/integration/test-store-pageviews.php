<?php

function dn_bfs_it_hit( $overrides = array() ) {
	return array_merge(
		array(
			't'     => 'pv',
			'vid'   => dn_bfs_it_uid( 'visitor-1' ),
			'sid'   => dn_bfs_it_uid( 'session-1' ),
			'path'  => '/',
			'query' => 'utm_source=facebook&utm_medium=cpc&utm_campaign=sale-10',
			'ref'   => 'https://l.facebook.com/',
			'ptype' => 'home',
			'pid'   => 0,
			'sw'    => 390,
		),
		$overrides
	);
}

function dn_bfs_it_ctx( $now, $ua = DN_BFS_IT_UA ) {
	return dn_bfs_request_context( $now, $ua );
}

dn_bfs_it(
	'first pageview creates visitor, session with attribution, and pageview',
	function () {
		$now    = time();
		$result = dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now ) );

		dn_bfs_assert_true( $result['ok'], 'ok' );
		dn_bfs_assert_true( $result['pvid'] > 0, 'pvid' );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'visitors' ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'pageviews' ) );

		$session = dn_bfs_store_get_session( dn_bfs_it_uid( 'session-1' ) );
		dn_bfs_assert_same( 'paid', $session['channel'] );
		dn_bfs_assert_same( 'facebook', $session['utm_source'] );
		dn_bfs_assert_same( 'sale-10', $session['utm_campaign'] );
		dn_bfs_assert_same( 'l.facebook.com', $session['referrer_host'] );
		dn_bfs_assert_same( 'desktop', $session['device'] );
		dn_bfs_assert_same( 'Chrome', $session['browser'] );
		dn_bfs_assert_same( '1', $session['is_new_visitor'] );
		dn_bfs_assert_same( '1', $session['pageviews'] );
		dn_bfs_assert_same( '1', $session['is_bounce'] );
		dn_bfs_assert_same( 64, strlen( $session['ip_hash'] ) );
	}
);

dn_bfs_it(
	'reload of same path within 10 seconds returns same pageview',
	function () {
		$now    = time();
		$first  = dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now ) );
		$reload = dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now + 5 ) );
		$later  = dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now + 11 ) );

		dn_bfs_assert_same( 'reload', $reload['reason'] );
		dn_bfs_assert_same( $first['pvid'], $reload['pvid'] );
		dn_bfs_assert_true( $later['pvid'] !== $first['pvid'], 'new pageview after window' );
		dn_bfs_assert_same( 2, dn_bfs_it_count( 'pageviews' ) );
	}
);

dn_bfs_it(
	'second page clears bounce and updates exit path; returning visitor is not new',
	function () {
		$now = time();
		dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now ) );
		dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'path' => '/shop/' ) ), dn_bfs_it_ctx( $now + 20 ) );

		$session = dn_bfs_store_get_session( dn_bfs_it_uid( 'session-1' ) );
		dn_bfs_assert_same( '0', $session['is_bounce'] );
		dn_bfs_assert_same( '/shop/', $session['exit_path'] );
		dn_bfs_assert_same( '/', $session['entry_path'] );

		dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'sid' => dn_bfs_it_uid( 'session-2' ) ) ), dn_bfs_it_ctx( $now + 3600 ) );
		$second = dn_bfs_store_get_session( dn_bfs_it_uid( 'session-2' ) );
		dn_bfs_assert_same( '0', $second['is_new_visitor'] );
	}
);

dn_bfs_it(
	'session belonging to another visitor is rejected',
	function () {
		$now = time();
		dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now ) );
		$result = dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'vid' => dn_bfs_it_uid( 'other' ) ) ), dn_bfs_it_ctx( $now + 30 ) );

		dn_bfs_assert_same( 'session_mismatch', $result['reason'] );
	}
);

dn_bfs_it(
	'more than 60 pageviews per minute marks the session as spam',
	function () {
		$now = time();

		for ( $i = 0; $i < 60; $i++ ) {
			dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'path' => '/p' . $i ) ), dn_bfs_it_ctx( $now ) );
		}

		$result = dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'path' => '/p61' ) ), dn_bfs_it_ctx( $now + 1 ) );
		dn_bfs_assert_same( 'rate_pageviews', $result['reason'] );

		$session = dn_bfs_store_get_session( dn_bfs_it_uid( 'session-1' ) );
		dn_bfs_assert_same( '1', $session['is_spam'] );

		$after = dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'path' => '/later' ) ), dn_bfs_it_ctx( $now + 600 ) );
		dn_bfs_assert_same( 'spam', $after['reason'] );
	}
);

dn_bfs_it(
	'session cap of 300 pageviews marks spam',
	function () {
		global $wpdb;

		$now = time();
		dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now ) );
		$wpdb->update( dn_bfs_table( 'sessions' ), array( 'pageviews' => 300 ), array( 'session_uid' => dn_bfs_it_uid( 'session-1' ) ) );

		$result = dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'path' => '/x' ) ), dn_bfs_it_ctx( $now + 120 ) );
		dn_bfs_assert_same( 'session_cap', $result['reason'] );
	}
);

dn_bfs_it(
	'more than 20 new sessions per hour from one IP and browser blocks only the excess sessions',
	function () {
		$now = time();

		for ( $i = 0; $i < 20; $i++ ) {
			dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'vid' => dn_bfs_it_uid( 'v' . $i ), 'sid' => dn_bfs_it_uid( 's' . $i ) ) ), dn_bfs_it_ctx( $now + $i ) );
		}

		$result = dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'vid' => dn_bfs_it_uid( 'v21' ), 'sid' => dn_bfs_it_uid( 's21' ) ) ), dn_bfs_it_ctx( $now + 30 ) );

		dn_bfs_assert_same( 'rate_sessions', $result['reason'] );
		dn_bfs_assert_same( 0, dn_bfs_it_count( 'sessions', 'is_spam = 1' ) );
		dn_bfs_assert_same( 20, dn_bfs_it_count( 'sessions' ) );
	}
);

dn_bfs_it(
	'same IP with a different user-agent is not blocked by the session limit',
	function () {
		$now = time();
		$ua2 = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

		for ( $i = 0; $i < 20; $i++ ) {
			dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'vid' => dn_bfs_it_uid( 'v' . $i ), 'sid' => dn_bfs_it_uid( 's' . $i ) ) ), dn_bfs_it_ctx( $now + $i ) );
		}

		$result = dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'vid' => dn_bfs_it_uid( 'v-ios' ), 'sid' => dn_bfs_it_uid( 's-ios' ) ) ), dn_bfs_it_ctx( $now + 30, $ua2 ) );

		dn_bfs_assert_true( $result['ok'], 'different browser is ok' );
		dn_bfs_assert_same( 21, dn_bfs_it_count( 'sessions' ) );
	}
);

dn_bfs_it(
	'ping updates time on page and session duration',
	function () {
		$now = time();
		$pv  = dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now ) );
		$hit = array( 't' => 'ping', 'vid' => dn_bfs_it_uid( 'visitor-1' ), 'sid' => dn_bfs_it_uid( 'session-1' ), 'pvid' => $pv['pvid'], 'engaged' => 45 );

		dn_bfs_assert_true( dn_bfs_store_track_ping( $hit, dn_bfs_it_ctx( $now + 50 ) )['ok'], 'ping ok' );
		dn_bfs_store_track_ping( array_merge( $hit, array( 'engaged' => 30 ) ), dn_bfs_it_ctx( $now + 55 ) );

		$session = dn_bfs_store_get_session( dn_bfs_it_uid( 'session-1' ) );
		dn_bfs_assert_same( '45', $session['duration'] );
		dn_bfs_assert_same( (string) ( $now + 55 ), $session['last_activity'] );
	}
);

dn_bfs_it(
	'blocked counter accumulates per reason per day',
	function () {
		global $wpdb;

		$now = time();
		dn_bfs_store_count_blocked( 'bot', $now );
		dn_bfs_store_count_blocked( 'bot', $now );
		dn_bfs_store_count_blocked( 'bad_origin', $now );

		$bot = $wpdb->get_var( $wpdb->prepare( 'SELECT pageviews FROM ' . dn_bfs_table( 'daily' ) . " WHERE dimension = 'blocked' AND dim_value = %s", 'bot' ) );
		dn_bfs_assert_same( '2', $bot );
	}
);

dn_bfs_it(
	'visitor sessions_count increments once per new session',
	function () {
		global $wpdb;

		$now      = time();
		$vid      = dn_bfs_it_uid( 'visitor-1' );
		$visitors = dn_bfs_table( 'visitors' );

		dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now ) );
		dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'sid' => dn_bfs_it_uid( 'session-2' ) ) ), dn_bfs_it_ctx( $now + 5 ) );

		dn_bfs_assert_same( '2', $wpdb->get_var( $wpdb->prepare( "SELECT sessions_count FROM {$visitors} WHERE visitor_uid = %s", $vid ) ) );
		dn_bfs_assert_same( '1', dn_bfs_store_get_session( dn_bfs_it_uid( 'session-1' ) )['is_new_visitor'] );
		dn_bfs_assert_same( '0', dn_bfs_store_get_session( dn_bfs_it_uid( 'session-2' ) )['is_new_visitor'] );

		$other = dn_bfs_it_hit( array( 'vid' => dn_bfs_it_uid( 'visitor-2' ), 'sid' => dn_bfs_it_uid( 'session-3' ) ) );
		dn_bfs_store_ensure_session( $other, dn_bfs_it_ctx( $now + 10 ) );
		dn_bfs_store_ensure_session( $other, dn_bfs_it_ctx( $now + 11 ) );

		dn_bfs_assert_same( '1', $wpdb->get_var( $wpdb->prepare( "SELECT sessions_count FROM {$visitors} WHERE visitor_uid = %s", dn_bfs_it_uid( 'visitor-2' ) ) ) );
	}
);

dn_bfs_it(
	'ping is rejected for unknown, foreign or spam sessions',
	function () {
		$now = time();
		dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now ) );
		$session = dn_bfs_store_get_session( dn_bfs_it_uid( 'session-1' ) );
		$ping    = array( 't' => 'ping', 'vid' => dn_bfs_it_uid( 'visitor-1' ), 'sid' => dn_bfs_it_uid( 'session-1' ), 'pvid' => 1, 'engaged' => 10 );

		$unknown = dn_bfs_store_track_ping( array_merge( $ping, array( 'sid' => dn_bfs_it_uid( 'nope' ) ) ), dn_bfs_it_ctx( $now + 1 ) );
		dn_bfs_assert_same( 'unknown_session', $unknown['reason'] );

		$foreign = dn_bfs_store_track_ping( array_merge( $ping, array( 'vid' => dn_bfs_it_uid( 'visitor-9' ) ) ), dn_bfs_it_ctx( $now + 1 ) );
		dn_bfs_assert_same( 'session_mismatch', $foreign['reason'] );

		dn_bfs_store_mark_spam( (int) $session['id'] );
		$spam = dn_bfs_store_track_ping( $ping, dn_bfs_it_ctx( $now + 2 ) );
		dn_bfs_assert_same( 'spam', $spam['reason'] );
	}
);
