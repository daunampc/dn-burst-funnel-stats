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

function dn_bfs_store_insert_event( $session, $type, $fields, $now ) {
	global $wpdb;

	$order_id = isset( $fields['order_id'] ) && $fields['order_id'] ? (int) $fields['order_id'] : null;

	$wpdb->query(
		$wpdb->prepare(
			'INSERT IGNORE INTO ' . dn_bfs_table( 'events' ) . '
			(session_id, visitor_uid, time, type, product_id, qty, value, order_id, channel, utm_source, utm_medium, utm_campaign, country, device)
			VALUES (%d, %s, %d, %s, %d, %d, %f, ' . ( null === $order_id ? 'NULL' : '%d' ) . ', %s, %s, %s, %s, %s, %s)',
			array_merge(
				array(
					(int) $session['id'],
					(string) $session['visitor_uid'],
					(int) $now,
					$type,
					isset( $fields['product_id'] ) ? (int) $fields['product_id'] : 0,
					isset( $fields['qty'] ) ? (int) $fields['qty'] : 0,
					isset( $fields['value'] ) ? (float) $fields['value'] : 0,
				),
				null === $order_id ? array() : array( $order_id ),
				array(
					(string) $session['channel'],
					(string) $session['utm_source'],
					(string) $session['utm_medium'],
					(string) $session['utm_campaign'],
					(string) $session['country'],
					(string) $session['device'],
				)
			)
		)
	);

	return (int) $wpdb->insert_id;
}

function dn_bfs_store_order_event_exists( $order_id ) {
	global $wpdb;

	return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . dn_bfs_table( 'events' ) . ' WHERE order_id = %d', (int) $order_id ) );
}

function dn_bfs_store_find_recent_event( $type, $visitor_uid, $ip_hash, $product_id, $since ) {
	global $wpdb;

	$events   = dn_bfs_table( 'events' );
	$sessions = dn_bfs_table( 'sessions' );

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT e.* FROM {$events} e
			LEFT JOIN {$sessions} s ON s.id = e.session_id
			WHERE e.type = %s AND e.product_id = %d AND e.time >= %d
				AND (e.visitor_uid = %s OR (%s <> '' AND s.ip_hash = %s))
			ORDER BY e.time DESC LIMIT 1",
			$type,
			(int) $product_id,
			(int) $since,
			$visitor_uid,
			$ip_hash,
			$ip_hash
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

function dn_bfs_store_lock( $name ) {
	global $wpdb;

	return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', 'dnbfs_' . md5( $name ) ) );
}

function dn_bfs_store_unlock( $name ) {
	global $wpdb;

	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'dnbfs_' . md5( $name ) ) );
}

function dn_bfs_store_record_product_view( $session, $product_id, $ctx ) {
	$now  = (int) $ctx['now'];
	$lock = 'pv|' . $session['visitor_uid'] . '|' . (int) $product_id;

	if ( ! dn_bfs_store_lock( $lock ) ) {
		return dn_bfs_store_result( false, 'busy' );
	}

	$recent = dn_bfs_store_find_recent_event( 'product_view', $session['visitor_uid'], $ctx['ip_hash'], $product_id, $now - (int) $ctx['settings']['dedupe_window'] );

	if ( $recent ) {
		dn_bfs_store_unlock( $lock );
		return dn_bfs_store_result( false, 'duplicate' );
	}

	$id = dn_bfs_store_insert_event( $session, 'product_view', array( 'product_id' => $product_id ), $now );
	dn_bfs_store_unlock( $lock );

	return $id > 0 ? dn_bfs_store_result( true ) : dn_bfs_store_result( false, 'db_error' );
}

function dn_bfs_store_record_checkout( $session, $ctx ) {
	global $wpdb;

	$exists = $wpdb->get_var(
		$wpdb->prepare( 'SELECT id FROM ' . dn_bfs_table( 'events' ) . " WHERE session_id = %d AND type = 'checkout_start' LIMIT 1", (int) $session['id'] )
	);

	if ( $exists ) {
		return dn_bfs_store_result( false, 'duplicate' );
	}

	$id = dn_bfs_store_insert_event( $session, 'checkout_start', array(), (int) $ctx['now'] );

	return $id > 0 ? dn_bfs_store_result( true ) : dn_bfs_store_result( false, 'db_error' );
}

function dn_bfs_store_track_add_to_cart( $session, $product_id, $qty, $value, $ctx ) {
	global $wpdb;

	$now        = (int) $ctx['now'];
	$events     = dn_bfs_table( 'events' );
	$qty        = max( 1, (int) $qty );
	$product_id = (int) $product_id;
	$lock       = 'atc|' . $session['visitor_uid'] . '|' . $product_id;

	if ( ! dn_bfs_store_lock( $lock ) ) {
		return dn_bfs_store_result( false, 'busy' );
	}

	$since = $now - MINUTE_IN_SECONDS;
	$calls = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT (SELECT COUNT(*) FROM {$events} WHERE visitor_uid = %s AND type = 'add_to_cart' AND time >= %d)
			+ (SELECT COALESCE(SUM(attempts), 0) FROM {$events} WHERE visitor_uid = %s AND type = 'add_to_cart' AND last_attempt_at >= %d)",
			$session['visitor_uid'],
			$since,
			$session['visitor_uid'],
			$since
		)
	);

	if ( $calls >= (int) $ctx['settings']['limit_atc_per_min'] ) {
		dn_bfs_store_unlock( $lock );
		dn_bfs_store_mark_spam( (int) $session['id'] );
		return dn_bfs_store_result( false, 'rate_atc' );
	}

	$recent = dn_bfs_store_find_recent_event( 'add_to_cart', $session['visitor_uid'], $ctx['ip_hash'], $product_id, $now - (int) $ctx['settings']['dedupe_window'] );

	if ( $recent ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$events} SET qty = qty + %d, value = value + %f, attempts = LEAST(attempts + 1, 65535), last_attempt_at = %d WHERE id = %d", $qty, (float) $value, $now, (int) $recent['id'] ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$events} SET qty = qty + %d, value = value + %f WHERE type = 'cart' AND session_id = %d AND product_id = %d AND time = %d",
				$qty,
				(float) $value,
				(int) $recent['session_id'],
				(int) $recent['product_id'],
				(int) $recent['time']
			)
		);
		dn_bfs_store_unlock( $lock );
		return dn_bfs_store_result( false, 'duplicate' );
	}

	$fields = array(
		'product_id' => $product_id,
		'qty'        => $qty,
		'value'      => (float) $value,
	);

	$atc_id = dn_bfs_store_insert_event( $session, 'add_to_cart', $fields, $now );

	if ( $atc_id <= 0 ) {
		dn_bfs_store_unlock( $lock );
		return dn_bfs_store_result( false, 'db_error' );
	}

	if ( dn_bfs_store_insert_event( $session, 'cart', $fields, $now ) <= 0 ) {
		$wpdb->delete( $events, array( 'id' => $atc_id ), array( '%d' ) );
		dn_bfs_store_unlock( $lock );
		return dn_bfs_store_result( false, 'db_error' );
	}

	dn_bfs_store_unlock( $lock );

	return dn_bfs_store_result( true );
}

function dn_bfs_store_after_pageview( $session, $hit, $ctx ) {
	if ( 'product' === $hit['ptype'] && (int) $hit['pid'] > 0 && dn_bfs_should_track_product( (int) $hit['pid'] ) ) {
		dn_bfs_store_record_product_view( $session, (int) $hit['pid'], $ctx );
	}

	if ( 'checkout' === $hit['ptype'] ) {
		dn_bfs_store_record_checkout( $session, $ctx );
	}
}
