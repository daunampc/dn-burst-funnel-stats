<?php
/**
 * Database writes for tracking: visitors, sessions, pageviews, events.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_store_result( $ok, $reason = '', $extra = array() ) {
	return array_merge(
		array(
			'ok'     => (bool) $ok,
			'reason' => (string) $reason,
		),
		$extra
	);
}

function dn_bfs_store_count_blocked( $reason, $now ) {
	global $wpdb;

	$reason = substr( sanitize_key( $reason ), 0, 64 );

	$wpdb->query(
		$wpdb->prepare(
			'INSERT INTO ' . dn_bfs_table( 'daily' ) . " (date, dimension, dim_hash, dim_value, pageviews)
			VALUES (%s, 'blocked', %s, %s, 1)
			ON DUPLICATE KEY UPDATE pageviews = pageviews + 1",
			wp_date( 'Y-m-d', $now ),
			md5( $reason ),
			$reason
		)
	);
}

function dn_bfs_store_get_session( $session_uid ) {
	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare( 'SELECT * FROM ' . dn_bfs_table( 'sessions' ) . ' WHERE session_uid = %s', $session_uid ),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

function dn_bfs_store_mark_spam( $session_id ) {
	global $wpdb;

	$session_id = (int) $session_id;
	$started_at = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT started_at FROM ' . dn_bfs_table( 'sessions' ) . ' WHERE id = %d', $session_id ) );

	$wpdb->update( dn_bfs_table( 'sessions' ), array( 'is_spam' => 1 ), array( 'id' => $session_id ), array( '%d' ), array( '%d' ) );

	do_action( 'dn_bfs_session_marked_spam', $session_id, $started_at );
}

function dn_bfs_store_ensure_session( $hit, $ctx ) {
	global $wpdb;

	$now      = (int) $ctx['now'];
	$settings = $ctx['settings'];
	$session  = dn_bfs_store_get_session( $hit['sid'] );

	if ( $session ) {
		if ( $session['visitor_uid'] !== $hit['vid'] ) {
			return dn_bfs_store_result( false, 'session_mismatch' );
		}

		if ( '1' === (string) $session['is_spam'] ) {
			return dn_bfs_store_result( false, 'spam' );
		}

		return dn_bfs_store_result( true, '', array( 'session' => $session ) );
	}

	$sessions_table = dn_bfs_table( 'sessions' );

	if ( '' !== $ctx['ip_hash'] ) {
		$since  = $now - HOUR_IN_SECONDS;
		$recent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sessions_table} WHERE ip_hash = %s AND started_at >= %d", $ctx['ip_hash'], $since ) );

		if ( $recent >= (int) $settings['limit_sessions_per_hour'] ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$sessions_table} WHERE ip_hash = %s AND started_at >= %d AND is_spam = 0", $ctx['ip_hash'], $since ) );

			foreach ( $ids as $id ) {
				dn_bfs_store_mark_spam( (int) $id );
			}

			return dn_bfs_store_result( false, 'rate_sessions' );
		}
	}

	$utm      = dn_bfs_extract_utm( $hit['query'] );
	$ref_host = dn_bfs_referrer_host( $hit['ref'] );
	$ref_host = $ref_host === $ctx['site_host'] ? '' : $ref_host;
	$geo      = dn_bfs_geo_lookup( $ctx['ip'], wp_unslash( $_SERVER ), $settings );

	$wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$sessions_table}
			(session_uid, visitor_uid, started_at, last_activity, is_new_visitor, entry_path, exit_path, referrer_host, channel,
			utm_source, utm_medium, utm_campaign, utm_content, utm_term, device, browser, os, country, city, ip_hash)
			VALUES (%s, %s, %d, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
			$hit['sid'],
			$hit['vid'],
			$now,
			$now,
			0,
			$hit['path'],
			$hit['path'],
			$ref_host,
			dn_bfs_classify_channel( $utm, $ref_host, $ctx['site_host'] ),
			$utm['source'],
			$utm['medium'],
			$utm['campaign'],
			$utm['content'],
			$utm['term'],
			$ctx['ua']['device'],
			$ctx['ua']['browser'],
			$ctx['ua']['os'],
			$geo['country'],
			$geo['city'],
			$ctx['ip_hash']
		)
	);

	if ( 1 === (int) $wpdb->rows_affected ) {
		// Only the request whose session INSERT won touches the visitor row.
		$visitors_table = dn_bfs_table( 'visitors' );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$visitors_table} (visitor_uid, first_seen, last_seen, sessions_count) VALUES (%s, %d, %d, 1)
				ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), sessions_count = sessions_count + 1",
				$hit['vid'],
				$now,
				$now
			)
		);

		// MySQL reports 1 affected row for a fresh insert and 2 for an update.
		if ( 1 === (int) $wpdb->rows_affected ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$sessions_table} SET is_new_visitor = 1 WHERE session_uid = %s", $hit['sid'] ) );
		}
	}

	$session = dn_bfs_store_get_session( $hit['sid'] );

	if ( ! $session ) {
		return dn_bfs_store_result( false, 'db_error' );
	}

	if ( $session['visitor_uid'] !== $hit['vid'] ) {
		return dn_bfs_store_result( false, 'session_mismatch' );
	}

	return dn_bfs_store_result( true, '', array( 'session' => $session ) );
}

function dn_bfs_store_track_pageview( $hit, $ctx ) {
	global $wpdb;

	$result = dn_bfs_store_ensure_session( $hit, $ctx );

	if ( ! $result['ok'] ) {
		return $result;
	}

	$session    = $result['session'];
	$session_id = (int) $session['id'];
	$now        = (int) $ctx['now'];
	$settings   = $ctx['settings'];
	$pv_table   = dn_bfs_table( 'pageviews' );

	$last_minute = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pv_table} WHERE session_id = %d AND time >= %d", $session_id, $now - MINUTE_IN_SECONDS ) );

	if ( $last_minute >= (int) $settings['limit_pv_per_min'] ) {
		dn_bfs_store_mark_spam( $session_id );
		return dn_bfs_store_result( false, 'rate_pageviews' );
	}

	if ( (int) $session['pageviews'] >= (int) $settings['limit_pv_per_session'] ) {
		dn_bfs_store_mark_spam( $session_id );
		return dn_bfs_store_result( false, 'session_cap' );
	}

	$last = $wpdb->get_row( $wpdb->prepare( "SELECT id, path, time FROM {$pv_table} WHERE session_id = %d ORDER BY id DESC LIMIT 1", $session_id ), ARRAY_A );

	if ( $last && $last['path'] === $hit['path'] && ( $now - (int) $last['time'] ) < (int) $settings['reload_window'] ) {
		return dn_bfs_store_result( true, 'reload', array( 'pvid' => (int) $last['id'] ) );
	}

	$wpdb->insert(
		$pv_table,
		array(
			'session_id'  => $session_id,
			'visitor_uid' => $hit['vid'],
			'time'        => $now,
			'path'        => $hit['path'],
			'page_type'   => isset( $hit['ptype'] ) ? $hit['ptype'] : 'other',
			'object_id'   => isset( $hit['pid'] ) ? (int) $hit['pid'] : 0,
		),
		array( '%d', '%s', '%d', '%s', '%s', '%d' )
	);

	$pvid = (int) $wpdb->insert_id;

	// is_bounce is computed from the pre-increment value and placed first, so it does not depend on SET evaluation order.
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE ' . dn_bfs_table( 'sessions' ) . ' SET is_bounce = IF(pageviews >= 1, 0, 1), pageviews = pageviews + 1, last_activity = %d, exit_path = %s WHERE id = %d',
			$now,
			$hit['path'],
			$session_id
		)
	);

	$wpdb->update( dn_bfs_table( 'visitors' ), array( 'last_seen' => $now ), array( 'visitor_uid' => $hit['vid'] ), array( '%d' ), array( '%s' ) );

	if ( function_exists( 'dn_bfs_store_after_pageview' ) ) {
		dn_bfs_store_after_pageview( $session, $hit, $ctx );
	}

	return dn_bfs_store_result( true, '', array( 'pvid' => $pvid ) );
}

function dn_bfs_store_track_ping( $hit, $ctx ) {
	global $wpdb;

	$session = dn_bfs_store_get_session( $hit['sid'] );

	if ( ! $session ) {
		return dn_bfs_store_result( false, 'unknown_session' );
	}

	if ( $session['visitor_uid'] !== $hit['vid'] ) {
		return dn_bfs_store_result( false, 'session_mismatch' );
	}

	if ( '1' === (string) $session['is_spam'] ) {
		return dn_bfs_store_result( false, 'spam' );
	}

	$session_id = (int) $session['id'];
	$pv_table   = dn_bfs_table( 'pageviews' );

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$pv_table} SET time_on_page = GREATEST(time_on_page, %d) WHERE id = %d AND session_id = %d",
			(int) $hit['engaged'],
			(int) $hit['pvid'],
			$session_id
		)
	);

	$wpdb->query(
		$wpdb->prepare(
			'UPDATE ' . dn_bfs_table( 'sessions' ) . " SET last_activity = %d, duration = (SELECT COALESCE(SUM(time_on_page), 0) FROM {$pv_table} WHERE session_id = %d) WHERE id = %d",
			(int) $ctx['now'],
			$session_id,
			$session_id
		)
	);

	return dn_bfs_store_result( true );
}
