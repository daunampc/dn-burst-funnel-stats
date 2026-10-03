<?php

function dn_bfs_it_session( $now, $seed = '1' ) {
	$hit = array(
		'vid'   => dn_bfs_it_uid( 'visitor-' . $seed ),
		'sid'   => dn_bfs_it_uid( 'session-' . $seed ),
		'path'  => '/',
		'query' => 'utm_campaign=sale-10&utm_source=facebook&utm_medium=cpc',
		'ref'   => '',
	);

	return dn_bfs_store_ensure_session( $hit, dn_bfs_request_context( $now, DN_BFS_IT_UA ) )['session'];
}

dn_bfs_it(
	'product view counts once per visitor and product within 5 minutes',
	function () {
		$now     = time();
		$session = dn_bfs_it_session( $now );
		$ctx     = dn_bfs_request_context( $now, DN_BFS_IT_UA );

		dn_bfs_assert_true( dn_bfs_store_record_product_view( $session, 101, $ctx )['ok'], 'first A' );
		dn_bfs_assert_same( 'duplicate', dn_bfs_store_record_product_view( $session, 101, dn_bfs_request_context( $now + 120, DN_BFS_IT_UA ) )['reason'] );
		dn_bfs_assert_true( dn_bfs_store_record_product_view( $session, 102, dn_bfs_request_context( $now + 130, DN_BFS_IT_UA ) )['ok'], 'B counts' );
		dn_bfs_assert_true( dn_bfs_store_record_product_view( $session, 101, dn_bfs_request_context( $now + 301, DN_BFS_IT_UA ) )['ok'], 'A after window' );

		dn_bfs_assert_same( 3, dn_bfs_it_count( 'events', "type = 'product_view'" ) );
	}
);

dn_bfs_it(
	'product view dedupe also applies across cookies from the same IP',
	function () {
		$now = time();
		dn_bfs_store_record_product_view( dn_bfs_it_session( $now, 'a' ), 101, dn_bfs_request_context( $now, DN_BFS_IT_UA ) );
		$result = dn_bfs_store_record_product_view( dn_bfs_it_session( $now + 10, 'b' ), 101, dn_bfs_request_context( $now + 10, DN_BFS_IT_UA ) );

		dn_bfs_assert_same( 'duplicate', $result['reason'] );
	}
);

dn_bfs_it(
	'add to cart writes add_to_cart and cart together and dedupes within 5 minutes',
	function () {
		$now     = time();
		$session = dn_bfs_it_session( $now );

		$first = dn_bfs_store_track_add_to_cart( $session, 101, 1, 20.0, dn_bfs_request_context( $now, DN_BFS_IT_UA ) );
		dn_bfs_assert_true( $first['ok'], 'first ok' );

		for ( $i = 1; $i <= 4; $i++ ) {
			$dup = dn_bfs_store_track_add_to_cart( $session, 101, 2, 40.0, dn_bfs_request_context( $now + $i * 10, DN_BFS_IT_UA ) );
			dn_bfs_assert_same( 'duplicate', $dup['reason'] );
		}

		dn_bfs_store_track_add_to_cart( $session, 102, 1, 30.0, dn_bfs_request_context( $now + 60, DN_BFS_IT_UA ) );
		dn_bfs_store_track_add_to_cart( $session, 101, 1, 20.0, dn_bfs_request_context( $now + 301, DN_BFS_IT_UA ) );

		dn_bfs_assert_same( 3, dn_bfs_it_count( 'events', "type = 'add_to_cart'" ) );
		dn_bfs_assert_same( 3, dn_bfs_it_count( 'events', "type = 'cart'" ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'events', "type = 'add_to_cart' AND product_id = 101 AND qty = 9 AND attempts = 4" ) );
		dn_bfs_assert_same( 3, dn_bfs_it_count( 'events', "type = 'cart' AND utm_campaign = 'sale-10' AND channel = 'paid'" ) );
	}
);

dn_bfs_it(
	'more than 20 add to cart calls per minute marks the session as spam',
	function () {
		$now     = time();
		$session = dn_bfs_it_session( $now );

		for ( $i = 0; $i < 20; $i++ ) {
			dn_bfs_store_track_add_to_cart( $session, 101, 1, 20.0, dn_bfs_request_context( $now + 1, DN_BFS_IT_UA ) );
		}

		$result = dn_bfs_store_track_add_to_cart( $session, 105, 1, 20.0, dn_bfs_request_context( $now + 2, DN_BFS_IT_UA ) );
		dn_bfs_assert_same( 'rate_atc', $result['reason'] );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'sessions', 'is_spam = 1' ) );
	}
);

dn_bfs_it(
	'checkout is recorded once per session',
	function () {
		$now     = time();
		$session = dn_bfs_it_session( $now );

		dn_bfs_assert_true( dn_bfs_store_record_checkout( $session, dn_bfs_request_context( $now, DN_BFS_IT_UA ) )['ok'], 'first' );
		dn_bfs_assert_same( 'duplicate', dn_bfs_store_record_checkout( $session, dn_bfs_request_context( $now + 900, DN_BFS_IT_UA ) )['reason'] );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'events', "type = 'checkout_start'" ) );
	}
);

dn_bfs_it(
	'product and checkout pageviews record events through after_pageview',
	function () {
		$now = time();
		$hit = array(
			't'     => 'pv',
			'vid'   => dn_bfs_it_uid( 'visitor-1' ),
			'sid'   => dn_bfs_it_uid( 'session-1' ),
			'path'  => '/product/a/',
			'query' => '',
			'ref'   => '',
			'ptype' => 'product',
			'pid'   => 101,
			'sw'    => 0,
		);

		dn_bfs_store_track_pageview( $hit, dn_bfs_request_context( $now, DN_BFS_IT_UA ) );
		dn_bfs_store_track_pageview( array_merge( $hit, array( 'path' => '/checkout/', 'ptype' => 'checkout', 'pid' => 0 ) ), dn_bfs_request_context( $now + 30, DN_BFS_IT_UA ) );

		dn_bfs_assert_same( 1, dn_bfs_it_count( 'events', "type = 'product_view' AND product_id = 101" ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'events', "type = 'checkout_start'" ) );
	}
);

dn_bfs_it(
	'selected product mode skips untracked products',
	function () {
		dn_bfs_it_settings( array( 'product_tracking_mode' => 'selected', 'selected_product_ids' => array( 101 ) ) );

		$now = time();
		$hit = array(
			't'     => 'pv',
			'vid'   => dn_bfs_it_uid( 'visitor-1' ),
			'sid'   => dn_bfs_it_uid( 'session-1' ),
			'path'  => '/product/b/',
			'query' => '',
			'ref'   => '',
			'ptype' => 'product',
			'pid'   => 102,
			'sw'    => 0,
		);

		dn_bfs_store_track_pageview( $hit, dn_bfs_request_context( $now, DN_BFS_IT_UA ) );
		dn_bfs_assert_same( 0, dn_bfs_it_count( 'events', "type = 'product_view'" ) );
	}
);
