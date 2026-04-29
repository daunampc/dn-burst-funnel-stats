<?php
/**
 * Plugin Name: DN Burst Funnel Stats
 * Plugin URI: https://github.com/daunampc/dn-burst-funnel-stats.git
 * Description: Funnel dashboard for WooCommerce using Burst Statistics page visit data and WooCommerce order metrics.
 * Version: 1.0.2
 * Author: toshstack.dev
 * Author URI: https://toshstack.dev
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: burst-statistics, woocommerce
 * Text Domain: dn-burst-funnel-stats
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/daunampc/dn-burst-funnel-stats 
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DN_BURST_FUNNEL_STATS_VERSION', '1.0.2' );
define( 'DN_BURST_FUNNEL_STATS_FILE', __FILE__ );
define( 'DN_BURST_FUNNEL_STATS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DN_BURST_FUNNEL_STATS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Change this to your real GitHub repository in owner/repo format.
 */
define( 'DN_BURST_FUNNEL_STATS_GITHUB_REPO', 'daunampc/dn-burst-funnel-stats' );

define( 'DN_BURST_FUNNEL_STATS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

function dn_burst_funnel_stats_dependency_plugins() {
	return array(
		'burst-statistics/burst.php' => 'Burst Statistics',
		'woocommerce/woocommerce.php' => 'WooCommerce',
	);
}

function dn_burst_funnel_stats_missing_dependencies() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$missing = array();
	foreach ( dn_burst_funnel_stats_dependency_plugins() as $plugin_file => $plugin_name ) {
		if ( ! is_plugin_active( $plugin_file ) ) {
			$missing[] = $plugin_name;
		}
	}

	return $missing;
}

function dn_burst_funnel_stats_activate() {
	$missing = dn_burst_funnel_stats_missing_dependencies();
	if ( ! empty( $missing ) ) {
		deactivate_plugins( DN_BURST_FUNNEL_STATS_PLUGIN_BASENAME );
		wp_die(
			esc_html( sprintf( 'DN Burst Funnel Stats requires these plugins to be installed and active first: %s.', implode( ', ', $missing ) ) ),
			esc_html__( 'Plugin dependency missing', 'dn-burst-funnel-stats' ),
			array( 'back_link' => true )
		);
	}
}
register_activation_hook( __FILE__, 'dn_burst_funnel_stats_activate' );

function dn_burst_funnel_stats_admin_dependency_notice() {
	$missing = dn_burst_funnel_stats_missing_dependencies();
	if ( empty( $missing ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p><strong>DN Burst Funnel Stats</strong> requires these plugins to be active first: %s.</p></div>',
		esc_html( implode( ', ', $missing ) )
	);
}
add_action( 'admin_notices', 'dn_burst_funnel_stats_admin_dependency_notice' );

function dn_burst_funnel_stats_bootstrap() {
	if ( ! empty( dn_burst_funnel_stats_missing_dependencies() ) ) {
		return;
	}

	require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/dashboard.php';
}
add_action( 'plugins_loaded', 'dn_burst_funnel_stats_bootstrap' );

if ( is_admin() ) {
	require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/class-github-updater.php';
	new DN_Burst_Funnel_Stats_GitHub_Updater(
		DN_BURST_FUNNEL_STATS_PLUGIN_BASENAME,
		DN_BURST_FUNNEL_STATS_VERSION,
		DN_BURST_FUNNEL_STATS_GITHUB_REPO,
		'DN Burst Funnel Stats'
	);
}
