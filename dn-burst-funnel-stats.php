<?php

/**
 * Plugin Name: DN Burst Funnel Stats
 * Plugin URI: https://github.com/daunampc/dn-burst-funnel-stats.git
 * Description: Funnel dashboard for WooCommerce with built-in visitor tracking and WooCommerce order metrics.
 * Version: 2.2.0
 * Author: toshstack.dev
 * Author URI: https://toshstack.dev
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: dn-burst-funnel-stats
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/daunampc/dn-burst-funnel-stats
 */

if (! defined('ABSPATH')) {
  exit;
}

define('DN_BURST_FUNNEL_STATS_VERSION', '2.2.0');
define('DN_BURST_FUNNEL_STATS_FILE', __FILE__);
define('DN_BURST_FUNNEL_STATS_PATH', plugin_dir_path(__FILE__));
define('DN_BURST_FUNNEL_STATS_URL', plugin_dir_url(__FILE__));

/**
 * GitHub repository in owner/repo format.
 */
define('DN_BURST_FUNNEL_STATS_GITHUB_REPO', 'daunampc/dn-burst-funnel-stats');

define('DN_BURST_FUNNEL_STATS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('DN_BURST_FUNNEL_STATS_SCHEMA_VERSION', '5');

/**
 * Load translations.
 *
 * @return void
 */
function dn_burst_funnel_stats_load_textdomain()
{
  load_plugin_textdomain(
    'dn-burst-funnel-stats',
    false,
    dirname(DN_BURST_FUNNEL_STATS_PLUGIN_BASENAME) . '/languages'
  );
}
add_action('plugins_loaded', 'dn_burst_funnel_stats_load_textdomain');

/**
 * Safely check if a plugin is active.
 *
 * Supports normal active plugins and multisite network-active plugins.
 *
 * @param string $plugin_file Plugin basename, for example woocommerce/woocommerce.php.
 * @return bool
 */
function dn_burst_funnel_stats_is_plugin_active_safe($plugin_file)
{
  if (! function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
  }

  if (is_plugin_active($plugin_file)) {
    return true;
  }

  if (
    is_multisite()
    && function_exists('is_plugin_active_for_network')
    && is_plugin_active_for_network($plugin_file)
  ) {
    return true;
  }

  return false;
}

/**
 * Get missing required dependencies.
 *
 * @return array
 */
function dn_burst_funnel_stats_missing_dependencies()
{
  $missing = array();

  if (! dn_burst_funnel_stats_is_plugin_active_safe('woocommerce/woocommerce.php')) {
    $missing[] = 'WooCommerce';
  }

  return $missing;
}

/**
 * Load native tracking modules.
 *
 * @return void
 */
function dn_burst_funnel_stats_load_tracking()
{
  require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/tracking.php';

  foreach (array('ua-parser', 'channel', 'guard', 'geo', 'schema', 'context', 'store', 'collector', 'wc-events', 'aggregator', 'cleanup', 'geoip-update') as $module) {
    require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/tracking/' . $module . '.php';
  }
}

/**
 * Load report modules (aggregation inputs and the report API).
 *
 * @return void
 */
function dn_burst_funnel_stats_load_reports()
{
  foreach (array('reports/metrics', 'reports/wc-settings', 'reports/raw', 'reports') as $module) {
    require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/' . $module . '.php';
  }
}

/**
 * Load admin modules (AJAX handlers run outside wp-admin screens, so load on every request).
 *
 * @return void
 */
function dn_burst_funnel_stats_load_admin()
{
  foreach (array('request', 'settings-model', 'system-status', 'dashboard-data', 'dashboard-page', 'ajax') as $module) {
    require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/admin/' . $module . '.php';
  }
}

/**
 * Validate dependencies on plugin activation.
 *
 * @return void
 */
function dn_burst_funnel_stats_activate()
{
  $missing = dn_burst_funnel_stats_missing_dependencies();

  if (! empty($missing)) {
    deactivate_plugins(DN_BURST_FUNNEL_STATS_PLUGIN_BASENAME);

    wp_die(
      esc_html(
        sprintf(
          /* translators: %s: comma-separated plugin names. */
          __('DN Burst Funnel Stats requires these plugins to be installed and active first: %s.', 'dn-burst-funnel-stats'),
          implode(', ', $missing)
        )
      ),
      esc_html__('Plugin dependency missing', 'dn-burst-funnel-stats'),
      array('back_link' => true)
    );
  }

  dn_burst_funnel_stats_load_tracking();
  dn_burst_funnel_stats_load_reports();

  dn_burst_funnel_stats_maybe_migrate();
}
register_activation_hook(__FILE__, 'dn_burst_funnel_stats_activate');

/**
 * Remove plugin cron events on deactivation.
 *
 * @return void
 */
function dn_burst_funnel_stats_deactivate()
{
  if (function_exists('dn_bfs_unschedule_crons')) {
    dn_bfs_unschedule_crons();
  }
}
register_deactivation_hook(__FILE__, 'dn_burst_funnel_stats_deactivate');

/**
 * Show admin notice when required dependencies are missing.
 *
 * @return void
 */
function dn_burst_funnel_stats_admin_dependency_notice()
{
  $missing = dn_burst_funnel_stats_missing_dependencies();

  if (empty($missing)) {
    return;
  }

  printf(
    '<div class="notice notice-error"><p><strong>DN Burst Funnel Stats</strong> %s %s.</p></div>',
    esc_html__('requires these plugins to be installed and active first:', 'dn-burst-funnel-stats'),
    esc_html(implode(', ', $missing))
  );
}
add_action('admin_notices', 'dn_burst_funnel_stats_admin_dependency_notice');

/**
 * Add plugin action links on the Plugins screen.
 *
 * @param array $links Existing action links.
 * @return array
 */
function dn_burst_funnel_stats_plugin_action_links($links)
{
  $dashboard_link = sprintf(
    '<a href="%1$s">%2$s</a>',
    esc_url(admin_url('admin.php?page=dn-burst-funnel-stats')),
    esc_html__('Dashboard', 'dn-burst-funnel-stats')
  );
  $settings_link  = sprintf(
    '<a href="%1$s">%2$s</a>',
    esc_url(admin_url('admin.php?page=dn-burst-funnel-stats-settings')),
    esc_html__('Settings', 'dn-burst-funnel-stats')
  );

  array_unshift($links, $settings_link);
  array_unshift($links, $dashboard_link);

  return $links;
}
add_filter('plugin_action_links_' . DN_BURST_FUNNEL_STATS_PLUGIN_BASENAME, 'dn_burst_funnel_stats_plugin_action_links');

/**
 * Store lightweight migration/schema metadata.
 *
 * @return void
 */
function dn_burst_funnel_stats_maybe_migrate()
{
  $current_schema = (string) get_option('dn_burst_funnel_stats_schema_version', '1');

  if (version_compare($current_schema, DN_BURST_FUNNEL_STATS_SCHEMA_VERSION, '>=')) {
    return;
  }

  update_option('dn_burst_funnel_stats_tracking_settings', dn_bfs_get_tracking_settings(), false);

  if (false === get_option('dn_burst_funnel_stats_url_tracking_settings', false)) {
    update_option(
      'dn_burst_funnel_stats_url_tracking_settings',
      array(
        'default_group' => 'campaign',
      ),
      false
    );
  }

  dn_bfs_install_schema();

  // Retry on the next load if dbDelta could not create every table.
  if (! dn_bfs_schema_tables_exist()) {
    return;
  }

  update_option('dn_burst_funnel_stats_schema_version', DN_BURST_FUNNEL_STATS_SCHEMA_VERSION, false);
}

/**
 * Bootstrap plugin after dependencies are available.
 *
 * @return void
 */
function dn_burst_funnel_stats_bootstrap()
{
  if (! empty(dn_burst_funnel_stats_missing_dependencies())) {
    return;
  }

  dn_burst_funnel_stats_load_tracking();
  dn_burst_funnel_stats_load_reports();
  require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/date-ranges.php';
  dn_burst_funnel_stats_load_admin();
  require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/dashboard.php';
  require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/admin-menu.php';
  require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/settings.php';
  require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/import-export.php';
  require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/ajax.php';

  dn_burst_funnel_stats_maybe_migrate();
  dn_bfs_schedule_crons();
  dn_burst_dash_schedule_refresh_event();
}
add_action('plugins_loaded', 'dn_burst_funnel_stats_bootstrap');

if (is_admin()) {
  require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/class-github-updater.php';

  new DN_Burst_Funnel_Stats_GitHub_Updater(
    DN_BURST_FUNNEL_STATS_PLUGIN_BASENAME,
    DN_BURST_FUNNEL_STATS_VERSION,
    DN_BURST_FUNNEL_STATS_GITHUB_REPO,
    'DN Burst Funnel Stats'
  );
}
