<?php
/**
 * Admin menu and assets.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return plugin admin page slugs.
 *
 * @return array
 */
function dn_burst_funnel_stats_admin_pages() {
	return array(
		'dn-burst-funnel-stats',
		'dn-burst-funnel-stats-url-tracking',
		'dn-burst-funnel-stats-settings',
		'dn-burst-funnel-stats-import-export',
	);
}

function dn_burst_funnel_stats_get_query_value( $key, $default = '' ) {
	if ( ! isset( $_GET[ $key ] ) || is_array( $_GET[ $key ] ) ) {
		return $default;
	}

	return wp_unslash( $_GET[ $key ] );
}

/**
 * Check if the current admin request belongs to this plugin.
 *
 * @return bool
 */
function dn_burst_funnel_stats_is_admin_page() {
	if ( ! is_admin() ) {
		return false;
	}

	$page = sanitize_key( dn_burst_funnel_stats_get_query_value( 'page' ) );

	return in_array( $page, dn_burst_funnel_stats_admin_pages(), true );
}

function dn_burst_funnel_stats_admin_nocache_headers() {
	if ( ! dn_burst_funnel_stats_is_admin_page() ) {
		return;
	}

	if ( function_exists( 'dn_burst_dash_send_nocache_headers' ) ) {
		dn_burst_dash_send_nocache_headers();
		return;
	}

	if ( ! headers_sent() ) {
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
	}
}
add_action( 'admin_init', 'dn_burst_funnel_stats_admin_nocache_headers' );

/**
 * Register standalone admin menu.
 *
 * @return void
 */
function dn_burst_funnel_stats_register_admin_menu() {
	add_menu_page(
		esc_html__( 'Funnel Stats', 'dn-burst-funnel-stats' ),
		esc_html__( 'Funnel Stats', 'dn-burst-funnel-stats' ),
		'manage_options',
		'dn-burst-funnel-stats',
		'dn_burst_dash_render_page',
		'dashicons-chart-area',
		56
	);

	add_submenu_page(
		'dn-burst-funnel-stats',
		esc_html__( 'Dashboard', 'dn-burst-funnel-stats' ),
		esc_html__( 'Dashboard', 'dn-burst-funnel-stats' ),
		'manage_options',
		'dn-burst-funnel-stats',
		'dn_burst_dash_render_page'
	);

	add_submenu_page(
		'dn-burst-funnel-stats',
		esc_html__( 'URL Tracking', 'dn-burst-funnel-stats' ),
		esc_html__( 'URL Tracking', 'dn-burst-funnel-stats' ),
		'manage_options',
		'dn-burst-funnel-stats-url-tracking',
		'dn_burst_dash_render_url_tracking_page'
	);

	add_submenu_page(
		'dn-burst-funnel-stats',
		esc_html__( 'Settings', 'dn-burst-funnel-stats' ),
		esc_html__( 'Settings', 'dn-burst-funnel-stats' ),
		'manage_options',
		'dn-burst-funnel-stats-settings',
		'dn_burst_funnel_stats_render_settings_page'
	);

	add_submenu_page(
		'dn-burst-funnel-stats',
		esc_html__( 'Import / Export', 'dn-burst-funnel-stats' ),
		esc_html__( 'Import / Export', 'dn-burst-funnel-stats' ),
		'manage_options',
		'dn-burst-funnel-stats-import-export',
		'dn_burst_funnel_stats_render_import_export_page'
	);

	/*
	 * Keep the legacy Burst submenu as a convenience when the parent menu exists.
	 * The plugin now has its own top-level menu, so this is no longer the only entry point.
	 */
	add_submenu_page(
		'burst',
		esc_html__( 'Funnel Stats', 'dn-burst-funnel-stats' ),
		esc_html__( 'Funnel Stats', 'dn-burst-funnel-stats' ),
		'manage_options',
		'dn-burst-funnel-stats',
		'dn_burst_dash_render_page'
	);
}
add_action( 'admin_menu', 'dn_burst_funnel_stats_register_admin_menu', 99 );

/**
 * Enqueue admin assets only on plugin pages.
 *
 * @param string $hook_suffix Admin hook suffix.
 * @return void
 */
function dn_burst_funnel_stats_enqueue_admin_assets( $hook_suffix ) {
	unset( $hook_suffix );

	if ( ! dn_burst_funnel_stats_is_admin_page() ) {
		return;
	}

	$css_path = DN_BURST_FUNNEL_STATS_PATH . 'assets/admin.css';
	$js_path  = DN_BURST_FUNNEL_STATS_PATH . 'assets/admin.js';
	$page     = sanitize_key( dn_burst_funnel_stats_get_query_value( 'page', 'dn-burst-funnel-stats' ) );
	$tab      = sanitize_key( dn_burst_funnel_stats_get_query_value( 'dn_tab', 'overview' ) );
	$range    = function_exists( 'dn_bfs_get_range_data_from_request' ) ? dn_bfs_get_range_data_from_request() : array();

	if ( 'dn-burst-funnel-stats-url-tracking' === $page ) {
		$tab = 'ad-urls';
	}

	wp_enqueue_style(
		'dn-burst-funnel-stats-admin',
		DN_BURST_FUNNEL_STATS_URL . 'assets/admin.css',
		array(),
		file_exists( $css_path ) ? (string) filemtime( $css_path ) : DN_BURST_FUNNEL_STATS_VERSION
	);

	wp_enqueue_script(
		'dn-burst-funnel-stats-admin',
		DN_BURST_FUNNEL_STATS_URL . 'assets/admin.js',
		array( 'jquery' ),
		file_exists( $js_path ) ? (string) filemtime( $js_path ) : DN_BURST_FUNNEL_STATS_VERSION,
		true
	);

	wp_localize_script(
		'dn-burst-funnel-stats-admin',
		'dnBurstFunnelStats',
		array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'dn_burst_funnel_stats_ajax' ),
			'page'       => $page,
			'currentTab' => $tab,
			'range'      => $range,
			'period'     => isset( $range['period'] ) ? $range['period'] : 'month_to_date',
			'compare'    => isset( $range['compare'] ) ? $range['compare'] : 'previous_year',
			'start'      => isset( $range['custom_start'] ) ? $range['custom_start'] : '',
			'end'        => isset( $range['custom_end'] ) ? $range['custom_end'] : '',
			'strings'    => array(
				'loading' => esc_html__( 'Loading data...', 'dn-burst-funnel-stats' ),
				'error'   => esc_html__( 'Unable to load data. Please try again.', 'dn-burst-funnel-stats' ),
				'empty'   => esc_html__( 'No data is available for this period.', 'dn-burst-funnel-stats' ),
				'updated' => esc_html__( 'Data refreshed successfully.', 'dn-burst-funnel-stats' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'dn_burst_funnel_stats_enqueue_admin_assets' );
