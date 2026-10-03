<?php
/**
 * Direct-insert fixtures for report tests (bypasses the tracking guard and limits).
 */

function dn_bfs_it_day_noon( $days_ago ) {
	$date = dn_bfs_date_shift( wp_date( 'Y-m-d', time() ), -1 * (int) $days_ago );

	return ( new DateTimeImmutable( $date . ' 12:00:00', wp_timezone() ) )->getTimestamp();
}

function dn_bfs_it_seed_session( $overrides = array() ) {
	global $wpdb;
	static $counter = 0;

	$counter++;
	$time = isset( $overrides['started_at'] ) ? (int) $overrides['started_at'] : time();
	$row  = array_merge(
		array(
			'session_uid'    => md5( 'seed-session-' . $counter . '-' . wp_rand() ),
			'visitor_uid'    => md5( 'seed-visitor-' . $counter ),
			'started_at'     => $time,
			'last_activity'  => $time,
			'is_new_visitor' => 1,
			'entry_path'     => '/',
			'exit_path'      => '/',
			'pageviews'      => 1,
			'duration'       => 0,
			'is_bounce'      => 1,
			'referrer_host'  => '',
			'channel'        => 'direct',
			'utm_source'     => '',
			'utm_medium'     => '',
			'utm_campaign'   => '',
			'utm_content'    => '',
			'utm_term'       => '',
			'device'         => 'desktop',
			'browser'        => 'Chrome',
			'os'             => 'Windows',
			'country'        => 'VN',
			'city'           => '',
			'ip_hash'        => '',
			'is_spam'        => 0,
		),
		$overrides
	);

	$wpdb->insert( dn_bfs_table( 'sessions' ), $row );
	$row['id'] = (int) $wpdb->insert_id;

	return $row;
}

function dn_bfs_it_seed_pageview( $session, $path, $time, $overrides = array() ) {
	global $wpdb;

	$wpdb->insert(
		dn_bfs_table( 'pageviews' ),
		array_merge(
			array(
				'session_id'   => (int) $session['id'],
				'visitor_uid'  => $session['visitor_uid'],
				'time'         => (int) $time,
				'path'         => $path,
				'page_type'    => 'other',
				'object_id'    => 0,
				'time_on_page' => 0,
			),
			$overrides
		)
	);

	return (int) $wpdb->insert_id;
}

function dn_bfs_it_seed_event( $session, $type, $time, $fields = array() ) {
	global $wpdb;

	$wpdb->insert(
		dn_bfs_table( 'events' ),
		array_merge(
			array(
				'session_id'   => (int) $session['id'],
				'visitor_uid'  => (string) $session['visitor_uid'],
				'time'         => (int) $time,
				'type'         => $type,
				'product_id'   => 0,
				'qty'          => 0,
				'value'        => 0,
				'channel'      => (string) $session['channel'],
				'utm_source'   => (string) $session['utm_source'],
				'utm_medium'   => (string) $session['utm_medium'],
				'utm_campaign' => (string) $session['utm_campaign'],
				'country'      => (string) $session['country'],
				'device'       => (string) $session['device'],
			),
			$fields
		)
	);

	return (int) $wpdb->insert_id;
}

function dn_bfs_it_day_range( $days_ago ) {
	return dn_bfs_day_bounds_for_test( dn_bfs_date_shift( wp_date( 'Y-m-d', time() ), -1 * $days_ago ) );
}

function dn_bfs_day_bounds_for_test( $date ) {
	$start = new DateTimeImmutable( $date . ' 00:00:00', wp_timezone() );

	return array( $start->getTimestamp(), $start->modify( '+1 day' )->getTimestamp() );
}
