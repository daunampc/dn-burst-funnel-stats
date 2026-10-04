<?php
/**
 * Admin menu and assets for the dashboard and settings screens.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_admin_pages() {
	return array(
		'dn-burst-funnel-stats'          => 'dashboard',
		'dn-burst-funnel-stats-settings' => 'settings',
	);
}

function dn_bfs_current_admin_page() {
	$slug  = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$pages = dn_bfs_admin_pages();

	return isset( $pages[ $slug ] ) ? $pages[ $slug ] : '';
}

function dn_bfs_register_admin_menu() {
	$capability = dn_bfs_admin_capability();

	add_menu_page( __( 'Funnel Stats', 'dn-burst-funnel-stats' ), __( 'Funnel Stats', 'dn-burst-funnel-stats' ), $capability, 'dn-burst-funnel-stats', 'dn_bfs_dash_render_page', 'dashicons-chart-area', 56 );
	add_submenu_page( 'dn-burst-funnel-stats', __( 'Dashboard', 'dn-burst-funnel-stats' ), __( 'Dashboard', 'dn-burst-funnel-stats' ), $capability, 'dn-burst-funnel-stats', 'dn_bfs_dash_render_page' );
	add_submenu_page( 'dn-burst-funnel-stats', __( 'Settings', 'dn-burst-funnel-stats' ), __( 'Settings', 'dn-burst-funnel-stats' ), $capability, 'dn-burst-funnel-stats-settings', 'dn_bfs_render_settings_page' );
}
add_action( 'admin_menu', 'dn_bfs_register_admin_menu' );

function dn_bfs_admin_nocache() {
	if ( '' !== dn_bfs_current_admin_page() && ! headers_sent() ) {
		nocache_headers();
	}
}
add_action( 'admin_init', 'dn_bfs_admin_nocache' );

function dn_bfs_admin_script_data() {
	$params  = dn_bfs_params_from_query( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$request = dn_bfs_dash_request( $params );

	if ( is_wp_error( $request ) ) {
		$request = dn_bfs_dash_request( array() );
	}

	list( $range, $filters ) = $request;
	$labels                  = dn_bfs_dash_dimension_labels();

	return array(
		'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
		'nonce'        => wp_create_nonce( 'dn_bfs_admin' ),
		'page'         => dn_bfs_current_admin_page(),
		'tab'          => dn_bfs_dash_sanitize_tab( $params['tab'] ),
		'period'       => $range['period'],
		'compare'      => $range['compare'],
		'start'        => 'custom' === $range['period'] ? $range['custom_start'] : '',
		'end'          => 'custom' === $range['period'] ? $range['custom_end'] : '',
		'filters'      => (object) $filters,
		'filterLabels' => array_intersect_key( $labels, array_flip( dn_bfs_filter_dimensions() ) ),
		'currency'     => array(
			'symbol'   => html_entity_decode( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$', ENT_QUOTES, 'UTF-8' ),
			'position' => function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_currency_pos', 'left' ) : 'left',
			'decimals' => function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2,
			'thousand' => function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',',
			'decimal'  => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
		),
		'strings'      => array(
			'sales'          => __( 'Sales', 'dn-burst-funnel-stats' ),
			'value'          => __( 'Value', 'dn-burst-funnel-stats' ),
			'stepConversion' => __( 'Step conversion', 'dn-burst-funnel-stats' ),
			'ofVisits'       => __( 'Of visits', 'dn-burst-funnel-stats' ),
			'loading'      => __( 'Loading data…', 'dn-burst-funnel-stats' ),
			'error'        => __( 'Unable to load data. Please try again.', 'dn-burst-funnel-stats' ),
			'updated'      => __( 'Data refreshed.', 'dn-burst-funnel-stats' ),
			'removeFilter' => __( 'Remove filter', 'dn-burst-funnel-stats' ),
			'activePages'  => __( 'Pages being viewed', 'dn-burst-funnel-stats' ),
			'nobodyOnline' => __( 'Nobody is online right now.', 'dn-burst-funnel-stats' ),
		),
	);
}

function dn_bfs_enqueue_admin_assets( $hook_suffix ) {
	unset( $hook_suffix );

	if ( '' === dn_bfs_current_admin_page() ) {
		return;
	}

	$css = DN_BURST_FUNNEL_STATS_PATH . 'assets/admin.css';
	$js  = DN_BURST_FUNNEL_STATS_PATH . 'assets/admin.js';

	wp_enqueue_style( 'dn-burst-funnel-stats-admin', DN_BURST_FUNNEL_STATS_URL . 'assets/admin.css', array(), file_exists( $css ) ? (string) filemtime( $css ) : DN_BURST_FUNNEL_STATS_VERSION );
	wp_enqueue_script( 'dn-burst-funnel-stats-admin', DN_BURST_FUNNEL_STATS_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), file_exists( $js ) ? (string) filemtime( $js ) : DN_BURST_FUNNEL_STATS_VERSION, true );
	wp_localize_script( 'dn-burst-funnel-stats-admin', 'dnBfsAdmin', dn_bfs_admin_script_data() );
}
add_action( 'admin_enqueue_scripts', 'dn_bfs_enqueue_admin_assets' );
