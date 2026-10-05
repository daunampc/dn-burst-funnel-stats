<?php
/**
 * Order backfill: records `order` events for WooCommerce orders the checkout
 * hooks never saw (orders placed before the plugin was installed, admin/REST/app
 * orders, gateways that skip the checkout hooks), using WooCommerce order
 * attribution for their channel, source, campaign and device.
 *
 * A one-off background import walks every order by id (`dnbfs_backfill_orders`
 * cron, state in `dnbfs_backfill_state`, cursor in `dnbfs_backfill_cursor`); an
 * hourly reconcile pass picks up orders of the last 3 days that are still
 * untracked 15 minutes after they were created.
 *
 * Orders are skipped when they already have an order event (so re-running is
 * safe and `_dnbfs_backfilled` is informational only) or carry
 * `_dnbfs_excluded` (placed by an excluded role or IP at checkout).
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
				'compare' => '>',
				'type'    => 'NUMERIC',
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

	if ( $inserted <= 0 ) {
		return 0;
	}

	$order->update_meta_data( '_dnbfs_backfilled', 1 );
	$order->save_meta_data();

	return $time;
}

/**
 * Records the untracked orders among `$order_ids`.
 *
 * @param callable|null $include Optional filter: receives the WC_Order, returns false to skip it.
 * @return array{inserted: int, dates: array} Dates (site timezone) that got new events.
 */
function dn_bfs_backfill_order_ids_insert( $order_ids, $include = null ) {
	$tracked  = dn_bfs_backfill_tracked_ids( $order_ids );
	$inserted = 0;
	$dates    = array();

	foreach ( $order_ids as $order_id ) {
		if ( isset( $tracked[ $order_id ] ) ) {
			continue;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || ( null !== $include && ! call_user_func( $include, $order ) ) ) {
			continue;
		}

		$time = dn_bfs_backfill_order( $order );

		if ( $time > 0 ) {
			$inserted++;
			$dates[ wp_date( 'Y-m-d', $time ) ] = true;
		}
	}

	foreach ( array_keys( $dates ) as $date ) {
		dn_bfs_mark_dirty_date( $date );
	}

	if ( $inserted > 0 ) {
		dn_bfs_bump_cache_generation();
	}

	return array(
		'inserted' => $inserted,
		'dates'    => array_keys( $dates ),
	);
}

/**
 * One page of the import, from the stored cursor (last processed order id).
 *
 * @return array{processed: int, inserted: int, done: bool}
 */
function dn_bfs_backfill_orders_batch( $limit = 200 ) {
	$limit  = max( 1, (int) $limit );
	$cursor = (int) get_option( 'dnbfs_backfill_cursor', 0 );
	$ids    = dn_bfs_backfill_order_ids(
		array(
			'limit'          => $limit,
			'dnbfs_after_id' => $cursor,
		)
	);
	$result = empty( $ids ) ? array( 'inserted' => 0 ) : dn_bfs_backfill_order_ids_insert( $ids );

	if ( ! empty( $ids ) ) {
		update_option( 'dnbfs_backfill_cursor', max( $ids ), false );
	}

	return array(
		'processed' => count( $ids ),
		'inserted'  => (int) $result['inserted'],
		'done'      => count( $ids ) < $limit,
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
	wp_clear_scheduled_hook( 'dnbfs_backfill_orders' );
	wp_schedule_single_event( time(), 'dnbfs_backfill_orders' );

	return $state;
}

function dn_bfs_backfill_reset() {
	wp_clear_scheduled_hook( 'dnbfs_backfill_orders' );
	delete_option( 'dnbfs_backfill_cursor' );
	delete_option( 'dnbfs_backfill_state' );
}

/**
 * Runs import batches until done or the `dn_bfs_backfill_time_budget`
 * (seconds, default 20) is spent, then reschedules itself a minute later.
 *
 * @return array Backfill state after the run.
 */
function dn_bfs_backfill_run() {
	$state = dn_bfs_backfill_state();

	if ( 'running' !== $state['status'] || ! dn_bfs_store_lock( 'order_backfill' ) ) {
		return $state;
	}

	$started = microtime( true );
	$budget  = (int) apply_filters( 'dn_bfs_backfill_time_budget', 20 );
	$size    = (int) apply_filters( 'dn_bfs_backfill_batch_size', 200 );

	do {
		$batch               = dn_bfs_backfill_orders_batch( $size );
		$state['processed'] += $batch['processed'];
		$state['inserted']  += $batch['inserted'];

		// Loaded orders pile up in the in-memory object cache; drop them between batches.
		if ( function_exists( 'wp_cache_flush_runtime' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	} while ( ! $batch['done'] && microtime( true ) - $started < $budget );

	$state['last_run'] = dn_bfs_now();

	if ( $batch['done'] ) {
		$state['status']      = 'done';
		$state['finished_at'] = dn_bfs_now();

		// Rebuild the dates the import marked dirty without waiting for the hourly run.
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'dnbfs_aggregate' );
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
 * deactivated mid-import).
 */
function dn_bfs_backfill_maybe_resume() {
	$state = dn_bfs_backfill_state();

	if ( 'running' === $state['status'] && ! wp_next_scheduled( 'dnbfs_backfill_orders' ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'dnbfs_backfill_orders' );
	}
}

/**
 * Hourly safety net: records orders created in the last 3 days that are still
 * untracked once they and their last change are 15 minutes old, so the checkout
 * hooks always get the first chance to attach the tracked session.
 *
 * @return int Orders recorded.
 */
function dn_bfs_reconcile_recent_orders( $now = null ) {
	$settings = dn_bfs_get_tracking_settings();

	if ( empty( $settings['tracking_enabled'] ) ) {
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
		}
	);

	return (int) $result['inserted'];
}
