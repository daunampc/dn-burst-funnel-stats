<?php
/**
 * Tracking endpoint and front-end tracker loader.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_register_collect_route() {
	register_rest_route(
		'dnbfs/v1',
		'/collect',
		array(
			'methods'             => 'POST',
			'callback'            => 'dn_bfs_rest_collect',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'dn_bfs_register_collect_route' );

function dn_bfs_collect_blocked( $reason, $now ) {
	dn_bfs_store_count_blocked( $reason, $now );

	return new WP_REST_Response( null, 204 );
}

function dn_bfs_rest_collect( WP_REST_Request $request ) {
	$now    = dn_bfs_now();
	$server = array(
		'REQUEST_METHOD' => $request->get_method(),
		'HTTP_ORIGIN'    => (string) $request->get_header( 'origin' ),
		'HTTP_REFERER'   => (string) $request->get_header( 'referer' ),
	);

	$validated = dn_bfs_guard_validate_payload( $request->get_body(), $server, dn_bfs_site_host() );

	if ( ! $validated['ok'] ) {
		return dn_bfs_collect_blocked( $validated['reason'], $now );
	}

	$ctx    = dn_bfs_request_context( $now, (string) $request->get_header( 'user_agent' ) );
	$reason = dn_bfs_guard_check_visitor( $ctx );

	if ( '' !== $reason ) {
		return dn_bfs_collect_blocked( $reason, $now );
	}

	$hit = $validated['data'];

	if ( 'ping' === $hit['t'] ) {
		$result = dn_bfs_store_track_ping( $hit, $ctx );

		return $result['ok'] ? new WP_REST_Response( null, 204 ) : dn_bfs_collect_blocked( $result['reason'], $now );
	}

	if ( ! dn_bfs_guard_is_page_selected( $hit, $ctx['settings'] ) ) {
		return dn_bfs_collect_blocked( 'not_selected', $now );
	}

	$result = dn_bfs_store_track_pageview( $hit, $ctx );

	if ( ! $result['ok'] ) {
		return dn_bfs_collect_blocked( $result['reason'], $now );
	}

	return new WP_REST_Response( array( 'pvid' => (int) $result['pvid'] ), 200 );
}

function dn_bfs_current_page_type() {
	if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		return 'thankyou';
	}

	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		return 'checkout';
	}

	if ( function_exists( 'is_cart' ) && is_cart() ) {
		return 'cart';
	}

	if ( function_exists( 'is_product' ) && is_product() ) {
		return 'product';
	}

	if ( ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) ) {
		return 'category';
	}

	if ( is_front_page() ) {
		return 'home';
	}

	return 'other';
}

function dn_bfs_page_context() {
	$settings = dn_bfs_get_tracking_settings();
	$offset   = wp_timezone()->getOffset( new DateTime( 'now', new DateTimeZone( 'UTC' ) ) );

	return array(
		'type'       => dn_bfs_current_page_type(),
		'id'         => (int) get_queried_object_id(),
		'endpoint'   => rest_url( 'dnbfs/v1/collect' ),
		'tz'         => (int) round( $offset / MINUTE_IN_SECONDS ),
		'timeout'    => (int) $settings['session_timeout'],
		'cookieDays' => (int) $settings['cookie_days'],
	);
}

function dn_bfs_enqueue_tracker() {
	$settings = dn_bfs_get_tracking_settings();

	if ( empty( $settings['tracking_enabled'] ) || is_admin() || is_preview() || is_customize_preview() ) {
		return;
	}

	if ( is_user_logged_in() && array_intersect( (array) wp_get_current_user()->roles, (array) $settings['excluded_roles'] ) ) {
		return;
	}

	$path = DN_BURST_FUNNEL_STATS_PATH . 'assets/tracker.js';

	wp_enqueue_script(
		'dnbfs-tracker',
		DN_BURST_FUNNEL_STATS_URL . 'assets/tracker.js',
		array(),
		file_exists( $path ) ? (string) filemtime( $path ) : DN_BURST_FUNNEL_STATS_VERSION,
		array(
			'strategy'  => 'defer',
			'in_footer' => false,
		)
	);

	wp_add_inline_script( 'dnbfs-tracker', 'window.dnbfsPage=' . wp_json_encode( dn_bfs_page_context() ) . ';', 'before' );
}
add_action( 'wp_enqueue_scripts', 'dn_bfs_enqueue_tracker' );
