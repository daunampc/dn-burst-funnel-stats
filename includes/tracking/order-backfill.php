<?php
/**
 * Order backfill: records `order` events for WooCommerce orders the checkout
 * hooks never saw (orders placed before the plugin was installed, admin/REST/app
 * orders, gateways that skip the checkout hooks), using WooCommerce order
 * attribution for their channel, source, campaign and device.
 *
 * A one-off background import walks every order by id (`dnbfs_backfill_orders`
 * cron, state in `dnbfs_backfill_state`, cursor in `dnbfs_backfill_cursor`).
 * Once it is done, the hourly aggregate cron continues the same id cursor, so
 * every newer order id is checked (backdated admin orders, imported orders and
 * orders created while WP-Cron was down included), and also re-checks orders of
 * the last 3 days (Store API orders keep the lower id of their checkout draft).
 * Both stop at orders younger than 15 minutes so the checkout hooks get the
 * first chance to attach the tracked session. Disabled tracking pauses all of it.
 *
 * Orders are skipped when they already have an order event (so re-running is
 * safe) or carry `_dnbfs_excluded` (placed by an excluded role or IP at
 * checkout). Nothing here ever writes to the order: on HPOS any order meta
 * write re-saves the order (date_modified, webhooks, integrations re-sync).
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order statuses the backfill imports: every registered status except
 * checkout drafts (not placed yet). Trashed orders are not listed by WooCommerce.
 */
function dn_bfs_backfill_statuses() {
	return array_values( array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft', 'wc-trash' ) ) );
}

function dn_bfs_backfill_hpos_enabled() {
	return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Order ids through wc_get_orders(), ascending by id. `dnbfs_after_id` limits
 * the query to ids greater than the cursor: HPOS gets a field_query, post
 * storage gets the WP_Query hook below.
 */
function dn_bfs_backfill_order_ids( $args ) {
	$after = isset( $args['dnbfs_after_id'] ) ? (int) $args['dnbfs_after_id'] : 0;
	$args  = array_merge(
		array(
			'type'    => 'shop_order',
			'status'  => dn_bfs_backfill_statuses(),
			'limit'   => 200,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'ids',
		),
		$args
	);

	if ( $after > 0 && dn_bfs_backfill_hpos_enabled() ) {
		$args['field_query'] = array(
			array(
				'field'   => 'id',
				'value'   => $after,
				// No 'type': NUMERIC becomes CAST(id AS SIGNED), which cannot use the primary key.
				'compare' => '>',
			),
		);
	}

	return array_map( 'intval', (array) wc_get_orders( $args ) );
}

function dn_bfs_backfill_cpt_query_args( $wp_query_args, $query_vars ) {
	if ( ! empty( $query_vars['dnbfs_after_id'] ) ) {
		$wp_query_args['dnbfs_after_id'] = (int) $query_vars['dnbfs_after_id'];
	}

	return $wp_query_args;
}
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'dn_bfs_backfill_cpt_query_args', 10, 2 );

function dn_bfs_backfill_posts_where( $where, $query ) {
	global $wpdb;

	$after = $query instanceof WP_Query ? (int) $query->get( 'dnbfs_after_id' ) : 0;

	return $after > 0 ? $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after ) : $where;
}
add_filter( 'posts_where', 'dn_bfs_backfill_posts_where', 10, 2 );

/**
 * Subset of `$order_ids` that already has an order event, as id => true.
 */
function dn_bfs_backfill_tracked_ids( $order_ids ) {
	global $wpdb;

	$tracked = array();

	foreach ( array_chunk( array_map( 'intval', (array) $order_ids ), 500 ) as $chunk ) {
		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT order_id FROM ' . dn_bfs_table( 'events' ) . ' WHERE order_id IN (' . implode( ', ', array_fill( 0, count( $chunk ), '%d' ) ) . ')',
				$chunk
			)
		);

		foreach ( (array) $found as $id ) {
			$tracked[ (int) $id ] = true;
		}
	}

	return $tracked;
}

/**
 * Records one untracked order with WooCommerce order attribution, timed at the
 * order's creation date.
 *
 * @param int|WC_Order $order Order or order id.
 * @return int Event time of the inserted event, 0 when nothing was inserted.
 */
function dn_bfs_backfill_order( $order ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );

	if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
		return 0;
	}

	if ( '' !== (string) $order->get_meta( '_dnbfs_excluded' ) || dn_bfs_store_order_event_exists( $order->get_id() ) ) {
		return 0;
	}

	$created = $order->get_date_created();
	$time    = $created ? (int) $created->getTimestamp() : dn_bfs_now();

	$inserted = dn_bfs_store_insert_event(
		dn_bfs_wc_order_fallback_session( $order, '' ),
		'order',
		array(
			'order_id' => $order->get_id(),
			'qty'      => (int) $order->get_item_count(),
			'value'    => (float) $order->get_total(),
		),
		$time
	);

	return $inserted > 0 ? $time : 0;
}

/**
 * Records the untracked orders among `$order_ids` (ascending), in order.
 *
 * @param callable|null $include  Optional: receives the WC_Order and returns true to record it,
 *                                false to skip it, or null to stop here (this and later ids are left).
 * @param float         $deadline Optional microtime(true) after which no further order is loaded.
 * @return array{inserted: int, dates: array, handled: int, last: int, stopped: string} `handled` ids
 *               (recorded, already tracked or skipped) up to id `last`; `stopped` is 'wait' (callback),
 *               'deadline' or '' (all ids handled).
 */
function dn_bfs_backfill_order_ids_insert( $order_ids, $include = null, $deadline = 0 ) {
	$tracked  = dn_bfs_backfill_tracked_ids( $order_ids );
	$inserted = 0;
	$dates    = array();
	$handled  = 0;
	$last     = 0;
	$stopped  = '';

	foreach ( $order_ids as $order_id ) {
		if ( ! isset( $tracked[ $order_id ] ) ) {
			if ( $deadline > 0 && microtime( true ) >= $deadline ) {
				$stopped = 'deadline';
				break;
			}

			$order = wc_get_order( $order_id );
			$keep  = $order instanceof WC_Order && null !== $include ? call_user_func( $include, $order ) : $order instanceof WC_Order;

			if ( null === $keep ) {
				$stopped = 'wait';
				break;
			}

			$time = $keep ? dn_bfs_backfill_order( $order ) : 0;

			if ( $time > 0 ) {
				$inserted++;
				$dates[ wp_date( 'Y-m-d', $time ) ] = true;
			}
		}

		$last = $order_id;
		$handled++;
	}

	foreach ( array_keys( $dates ) as $date ) {
		dn_bfs_mark_dirty_date( $date );
	}

	return array(
		'inserted' => $inserted,
		'dates'    => array_keys( $dates ),
		'handled'  => $handled,
		'last'     => $last,
		'stopped'  => $stopped,
	);
}

/**
 * Cursor callback: an order created less than 15 minutes before `$now` stops
 * the walk, so the checkout hooks can record it with its tracked session first.
 */
function dn_bfs_backfill_settled_callback( $now ) {
	$settle = (int) $now - 15 * MINUTE_IN_SECONDS;

	return function ( $order ) use ( $settle, $now ) {
		$created = $order->get_date_created();
		$created = $created ? (int) $created->getTimestamp() : 0;

		// A future creation date would block the cursor for good; record it instead.
		return $created > $settle && $created <= (int) $now + HOUR_IN_SECONDS ? null : true;
	};
}

/**
 * One page of the import, from the stored cursor (last processed order id).
 * Stops at the first untracked order younger than 15 minutes (done: newer
 * orders are left to the hourly sync). A failed order query is not "done".
 *
 * @param int      $limit    Orders per page.
 * @param int|null $now      Current time, for the 15-minute guard.
 * @param float    $deadline Optional microtime(true) after which no further order is loaded.
 * @param bool     $bump     Reset the report cache when orders were recorded.
 * @return array{processed: int, inserted: int, done: bool}
 */
function dn_bfs_backfill_orders_batch( $limit = 200, $now = null, $deadline = 0, $bump = true ) {
	global $wpdb;

	$limit  = max( 1, (int) $limit );
	$cursor = (int) get_option( 'dnbfs_backfill_cursor', 0 );
	$ids    = dn_bfs_backfill_order_ids(
		array(
			'limit'          => $limit,
			'dnbfs_after_id' => $cursor,
		)
	);

	if ( empty( $ids ) ) {
		return array(
			'processed' => 0,
			'inserted'  => 0,
			'done'      => '' === (string) $wpdb->last_error,
		);
	}

	$result = dn_bfs_backfill_order_ids_insert( $ids, dn_bfs_backfill_settled_callback( null === $now ? dn_bfs_now() : $now ), $deadline );

	if ( $result['last'] > $cursor ) {
		update_option( 'dnbfs_backfill_cursor', $result['last'], false );
	}

	if ( $bump && $result['inserted'] > 0 ) {
		dn_bfs_bump_cache_generation();
	}

	return array(
		'processed' => $result['handled'],
		'inserted'  => $result['inserted'],
		'done'      => 'wait' === $result['stopped'] || ( '' === $result['stopped'] && count( $ids ) < $limit ),
	);
}

function dn_bfs_backfill_state() {
	$state = get_option( 'dnbfs_backfill_state', array() );

	return wp_parse_args(
		is_array( $state ) ? $state : array(),
		array(
			'status'      => 'idle',
			'inserted'    => 0,
			'processed'   => 0,
			'started_at'  => 0,
			'finished_at' => 0,
			'last_run'    => 0,
		)
	);
}

function dn_bfs_backfill_tracking_enabled() {
	$settings = dn_bfs_get_tracking_settings();

	return ! empty( $settings['tracking_enabled'] );
}

/**
 * False once the request uses 70% of the PHP memory limit (loaded orders stay
 * in memory when the object cache cannot flush its runtime cache).
 */
function dn_bfs_backfill_memory_ok() {
	$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

	return $limit <= 0 || memory_get_usage() < 0.7 * $limit;
}

/**
 * Resets the cursor and queues the background import.
 */
function dn_bfs_backfill_start() {
	$state = array(
		'status'      => 'running',
		'inserted'    => 0,
		'processed'   => 0,
		'started_at'  => dn_bfs_now(),
		'finished_at' => 0,
		'last_run'    => 0,
	);

	update_option( 'dnbfs_backfill_cursor', 0, false );
	update_option( 'dnbfs_backfill_state', $state, false );
	delete_transient( 'dnbfs_order_counts' );
	wp_clear_scheduled_hook( 'dnbfs_backfill_orders' );
	wp_schedule_single_event( time(), 'dnbfs_backfill_orders' );

	return $state;
}

function dn_bfs_backfill_reset() {
	wp_clear_scheduled_hook( 'dnbfs_backfill_orders' );
	delete_option( 'dnbfs_backfill_cursor' );
	delete_option( 'dnbfs_backfill_state' );
	delete_transient( 'dnbfs_order_counts' );
}

/**
 * Runs import batches until done or the `dn_bfs_backfill_time_budget`
 * (seconds, default 20) is spent, then reschedules itself a minute later.
 * Paused (not rescheduled) while tracking is disabled; dn_bfs_backfill_maybe_resume()
 * queues it again once tracking is back on.
 *
 * @return array Backfill state after the run.
 */
function dn_bfs_backfill_run() {
	$state = dn_bfs_backfill_state();

	if ( 'running' !== $state['status'] || ! dn_bfs_backfill_tracking_enabled() || ! dn_bfs_store_lock( 'order_backfill' ) ) {
		return $state;
	}

	$started  = microtime( true );
	$budget   = (int) apply_filters( 'dn_bfs_backfill_time_budget', 20 );
	$size     = (int) apply_filters( 'dn_bfs_backfill_batch_size', 200 );
	$inserted = 0;

	do {
		$batch               = dn_bfs_backfill_orders_batch( $size, null, 0, false );
		$state['processed'] += $batch['processed'];
		$state['inserted']  += $batch['inserted'];
		$inserted           += $batch['inserted'];

		// Loaded orders pile up in the in-memory object cache; drop them between batches.
		if ( function_exists( 'wp_cache_flush_runtime' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	} while ( ! $batch['done'] && microtime( true ) - $started < $budget && dn_bfs_backfill_memory_ok() );

	$state['last_run'] = dn_bfs_now();

	if ( $batch['done'] ) {
		$state['status']      = 'done';
		$state['finished_at'] = dn_bfs_now();

		// Rebuild the dates the import marked dirty without waiting for the hourly run.
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'dnbfs_aggregate' );
	}

	if ( $inserted > 0 ) {
		dn_bfs_bump_cache_generation();
	}

	update_option( 'dnbfs_backfill_state', $state, false );
	dn_bfs_store_unlock( 'order_backfill' );

	if ( ! $batch['done'] && ! wp_next_scheduled( 'dnbfs_backfill_orders' ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'dnbfs_backfill_orders' );
	}

	return $state;
}
add_action( 'dnbfs_backfill_orders', 'dn_bfs_backfill_run' );

/**
 * Re-queues a running import whose cron event was lost (e.g. the plugin was
 * deactivated mid-import, or tracking was disabled for a while).
 */
function dn_bfs_backfill_maybe_resume() {
	$state = dn_bfs_backfill_state();

	if ( 'running' === $state['status'] && dn_bfs_backfill_tracking_enabled() && ! wp_next_scheduled( 'dnbfs_backfill_orders' ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'dnbfs_backfill_orders' );
	}
}

/**
 * Hourly, once the import is done: continues the import's id cursor so every
 * newer order id is checked (backdated or imported orders, orders created while
 * WP-Cron was down), stopping at the first untracked order younger than 15
 * minutes; the next run resumes there. Ids come from the primary key, only
 * untracked orders are loaded, and the run ends at `$deadline` (default: the
 * `dn_bfs_order_sync_time_budget` filter, 5 seconds from now).
 *
 * @param int|null $now      Current time.
 * @param float    $deadline Optional microtime(true) end of the run.
 * @param bool     $more     Set to true when the run ended with orders left to check.
 * @return int Orders recorded.
 */
function dn_bfs_order_sync_run( $now = null, $deadline = 0, &$more = false ) {
	$more = false;

	if ( ! dn_bfs_backfill_tracking_enabled() || 'done' !== dn_bfs_backfill_state()['status'] || ! dn_bfs_store_lock( 'order_backfill' ) ) {
		return 0;
	}

	$now      = null === $now ? dn_bfs_now() : (int) $now;
	$deadline = $deadline > 0 ? (float) $deadline : microtime( true ) + (int) apply_filters( 'dn_bfs_order_sync_time_budget', 5 );
	$size     = (int) apply_filters( 'dn_bfs_backfill_batch_size', 200 );
	$inserted = 0;

	// Re-read under the lock (bypassing the request's option cache): "Import past
	// orders now" or "Delete all data" may have restarted the import.
	dn_bfs_option_cache_forget( 'dnbfs_backfill_state' );
	dn_bfs_option_cache_forget( 'dnbfs_backfill_cursor' );

	if ( 'done' === dn_bfs_backfill_state()['status'] ) {
		do {
			$batch     = dn_bfs_backfill_orders_batch( $size, $now, $deadline, false );
			$inserted += $batch['inserted'];
		} while ( ! $batch['done'] && microtime( true ) < $deadline && dn_bfs_backfill_memory_ok() );

		$more = ! $batch['done'];

		dn_bfs_option_cache_forget( 'dnbfs_backfill_state' );
		$state = dn_bfs_backfill_state();

		// Only record this run if nobody restarted or reset the import meanwhile.
		if ( 'done' === $state['status'] ) {
			$state['inserted'] += $inserted;
			$state['last_run']  = dn_bfs_now();
			update_option( 'dnbfs_backfill_state', $state, false );
		}
	}

	if ( $inserted > 0 ) {
		dn_bfs_bump_cache_generation();
	}

	dn_bfs_store_unlock( 'order_backfill' );

	return $inserted;
}

/**
 * Hourly safety net for orders the id cursor has already passed: records
 * orders created in the last 3 days that are still untracked once they and
 * their last change are 15 minutes old (Store API orders keep the id of their
 * checkout draft, which the import does not list), so the checkout hooks always
 * get the first chance to attach the tracked session.
 *
 * @param int|null $now      Current time.
 * @param float    $deadline Optional microtime(true) after which no further order is loaded.
 * @return int Orders recorded.
 */
function dn_bfs_reconcile_recent_orders( $now = null, $deadline = 0 ) {
	if ( ! dn_bfs_backfill_tracking_enabled() ) {
		return 0;
	}

	$now    = null === $now ? dn_bfs_now() : (int) $now;
	$settle = $now - 15 * MINUTE_IN_SECONDS;
	$ids    = dn_bfs_backfill_order_ids(
		array(
			'limit'        => -1,
			'date_created' => ( $now - 3 * DAY_IN_SECONDS ) . '...' . $settle,
		)
	);

	if ( empty( $ids ) ) {
		return 0;
	}

	$result = dn_bfs_backfill_order_ids_insert(
		$ids,
		function ( $order ) use ( $settle ) {
			$modified = $order->get_date_modified();

			return ! $modified || $modified->getTimestamp() <= $settle;
		},
		$deadline
	);

	if ( $result['inserted'] > 0 ) {
		dn_bfs_bump_cache_generation();
	}

	return (int) $result['inserted'];
}
