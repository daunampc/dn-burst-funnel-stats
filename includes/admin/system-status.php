<?php
/**
 * System status checks for the Settings → System screen.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_status_check( $key, $label, $status, $detail ) {
	return array(
		'key'    => $key,
		'label'  => $label,
		'status' => $status,
		'detail' => $detail,
	);
}

function dn_bfs_status_tables() {
	global $wpdb;

	if ( ! dn_bfs_schema_tables_exist() ) {
		return dn_bfs_status_check( 'tables', __( 'Database tables', 'dn-burst-funnel-stats' ), 'error', __( 'Some tracking tables are missing. Deactivate and reactivate the plugin.', 'dn-burst-funnel-stats' ) );
	}

	$engine = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', dn_bfs_table( 'daily' ) ) );

	if ( 'innodb' !== strtolower( $engine ) ) {
		/* translators: %s: storage engine. */
		return dn_bfs_status_check( 'tables', __( 'Database tables', 'dn-burst-funnel-stats' ), 'warning', sprintf( __( 'Tables use %s; InnoDB is needed for safe daily rebuilds.', 'dn-burst-funnel-stats' ), $engine ) );
	}

	return dn_bfs_status_check( 'tables', __( 'Database tables', 'dn-burst-funnel-stats' ), 'ok', __( 'All tables exist (InnoDB).', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_status_schema() {
	$installed = (string) get_option( 'dn_burst_funnel_stats_schema_version', '' );
	$ok        = DN_BURST_FUNNEL_STATS_SCHEMA_VERSION === $installed;

	/* translators: 1: installed schema version, 2: expected schema version. */
	return dn_bfs_status_check( 'schema', __( 'Schema version', 'dn-burst-funnel-stats' ), $ok ? 'ok' : 'error', sprintf( __( 'Installed %1$s, expected %2$s.', 'dn-burst-funnel-stats' ), $installed, DN_BURST_FUNNEL_STATS_SCHEMA_VERSION ) );
}

function dn_bfs_status_aggregation( $now ) {
	$label = __( 'Daily aggregation', 'dn-burst-funnel-stats' );
	$error = get_option( 'dnbfs_aggregate_last_error', null );

	if ( is_array( $error ) && ! empty( $error['message'] ) ) {
		/* translators: 1: date, 2: database error message. */
		return dn_bfs_status_check( 'aggregation', $label, 'error', sprintf( __( 'Last run failed on %1$s: %2$s', 'dn-burst-funnel-stats' ), isset( $error['date'] ) ? $error['date'] : '', $error['message'] ) );
	}

	$last = (string) get_option( 'dnbfs_last_aggregated_date', '' );

	if ( '' === $last ) {
		return dn_bfs_status_check( 'aggregation', $label, 'warning', __( 'The aggregator has not run yet.', 'dn-burst-funnel-stats' ) );
	}

	$closable = dn_bfs_last_closable_date( $now );
	$lag      = $last >= $closable ? 0 : count( dn_bfs_dates_between( dn_bfs_date_shift( $last, 1 ), $closable ) );
	$status   = 0 === $lag ? 'ok' : ( $lag <= 2 ? 'warning' : 'error' );

	/* translators: 1: last aggregated date, 2: number of days behind. */
	return dn_bfs_status_check( 'aggregation', $label, $status, sprintf( __( 'Aggregated through %1$s (%2$d days behind).', 'dn-burst-funnel-stats' ), $last, $lag ) );
}

function dn_bfs_status_cron() {
	$label     = __( 'Scheduled tasks', 'dn-burst-funnel-stats' );
	$scheduled = wp_next_scheduled( 'dnbfs_aggregate' ) && wp_next_scheduled( 'dnbfs_cleanup' );

	if ( ! $scheduled ) {
		return dn_bfs_status_check( 'cron', $label, 'error', __( 'Aggregation or cleanup is not scheduled.', 'dn-burst-funnel-stats' ) );
	}

	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
		return dn_bfs_status_check( 'cron', $label, 'warning', __( 'WP-Cron is disabled. Make sure a system cron calls wp-cron.php every few minutes.', 'dn-burst-funnel-stats' ) );
	}

	return dn_bfs_status_check( 'cron', $label, 'ok', __( 'Aggregation runs hourly and cleanup daily.', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_status_collect() {
	$label    = __( 'Tracking endpoint', 'dn-burst-funnel-stats' );
	$response = wp_remote_post(
		rest_url( 'dnbfs/v1/collect' ),
		array(
			'timeout' => 3,
			'headers' => array( 'X-DNBFS-Check' => '1' ),
			'body'    => '',
		)
	);

	if ( is_wp_error( $response ) ) {
		return dn_bfs_status_check( 'collect', $label, 'error', $response->get_error_message() );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || empty( $body['ok'] ) ) {
		/* translators: %d: HTTP status code. */
		return dn_bfs_status_check( 'collect', $label, 'error', sprintf( __( 'The endpoint answered with HTTP %d. A security plugin or firewall may block the REST API.', 'dn-burst-funnel-stats' ), (int) wp_remote_retrieve_response_code( $response ) ) );
	}

	return dn_bfs_status_check( 'collect', $label, 'ok', __( 'The REST endpoint is reachable.', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_status_api() {
	$label  = __( 'Public REST API', 'dn-burst-funnel-stats' );
	$routes = rest_get_server()->get_routes( dn_bfs_api_namespace() );

	if ( ! isset( $routes[ '/' . dn_bfs_api_namespace() . '/meta' ] ) ) {
		return dn_bfs_status_check( 'api', $label, 'error', __( 'The public API routes are not registered.', 'dn-burst-funnel-stats' ) );
	}

	$response = wp_remote_get( rest_url( dn_bfs_api_namespace() . '/meta' ), array( 'timeout' => 3 ) );

	if ( is_wp_error( $response ) ) {
		return dn_bfs_status_check( 'api', $label, 'error', $response->get_error_message() );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( wp_remote_retrieve_body( $response ), true );
	$code   = is_array( $body ) && isset( $body['code'] ) ? (string) $body['code'] : '';

	if ( 401 === $status && 'missing_key' === $code ) {
		return dn_bfs_status_check( 'api', $label, 'ok', __( 'The API is reachable and asks for a key.', 'dn-burst-funnel-stats' ) );
	}

	if ( 403 === $status && 'https_required' === $code ) {
		return dn_bfs_status_check( 'api', $label, 'warning', __( 'The API only answers over HTTPS, but the site address uses HTTP.', 'dn-burst-funnel-stats' ) );
	}

	/* translators: %d: HTTP status code. */
	return dn_bfs_status_check( 'api', $label, 'error', sprintf( __( 'The API answered with HTTP %d. A security plugin or firewall may block the REST API.', 'dn-burst-funnel-stats' ), $status ) );
}

function dn_bfs_status_tracker() {
	$label    = __( 'Tracker script', 'dn-burst-funnel-stats' );
	$settings = dn_bfs_get_tracking_settings();

	if ( empty( $settings['tracking_enabled'] ) ) {
		return dn_bfs_status_check( 'tracker', $label, 'warning', __( 'Tracking is turned off in General settings.', 'dn-burst-funnel-stats' ) );
	}

	$response = wp_remote_get( home_url( '/' ), array( 'timeout' => 3 ) );

	if ( ! is_wp_error( $response ) && false !== strpos( wp_remote_retrieve_body( $response ), 'window.dnbfsPage' ) ) {
		return dn_bfs_status_check( 'tracker', $label, 'ok', __( 'The tracker is present on the home page.', 'dn-burst-funnel-stats' ) );
	}

	return dn_bfs_status_check( 'tracker', $label, 'warning', __( 'The tracker was not found on the home page. Clear your page cache and check that scripts are not blocked.', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_status_geoip() {
	$label  = __( 'GeoIP database', 'dn-burst-funnel-stats' );
	$status = dn_bfs_geoip_status();

	if ( '' !== $status['last_error'] ) {
		/* translators: %s: error code. */
		return dn_bfs_status_check( 'geoip', $label, 'warning', sprintf( __( 'Last update failed: %s.', 'dn-burst-funnel-stats' ), $status['last_error'] ) );
	}

	if ( $status['database'] ) {
		/* translators: %s: date. */
		return dn_bfs_status_check( 'geoip', $label, 'ok', sprintf( __( 'Updated %s.', 'dn-burst-funnel-stats' ), $status['updated_at'] ? wp_date( get_option( 'date_format' ), $status['updated_at'] ) : '—' ) );
	}

	if ( $status['license_set'] ) {
		return dn_bfs_status_check( 'geoip', $label, 'warning', __( 'A license key is set but the database has not been downloaded yet.', 'dn-burst-funnel-stats' ) );
	}

	return dn_bfs_status_check( 'geoip', $label, 'info', __( 'No MaxMind database. Countries come from Cloudflare headers only.', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_status_geoip_public() {
	$label = __( 'GeoIP file protection', 'dn-burst-funnel-stats' );

	if ( ! file_exists( dn_bfs_geo_db_path() ) ) {
		return dn_bfs_status_check( 'geoip_public', $label, 'info', __( 'No database file to protect.', 'dn-burst-funnel-stats' ) );
	}

	$uploads  = wp_upload_dir( null, false );
	$response = wp_remote_head( trailingslashit( $uploads['baseurl'] ) . 'dnbfs/GeoLite2-City.mmdb', array( 'timeout' => 3 ) );

	if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
		return dn_bfs_status_check( 'geoip_public', $label, 'warning', __( 'The GeoIP file can be downloaded publicly. Block uploads/dnbfs in your web server configuration.', 'dn-burst-funnel-stats' ) );
	}

	return dn_bfs_status_check( 'geoip_public', $label, 'ok', __( 'The GeoIP file is not publicly downloadable.', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_status_proxy() {
	$label    = __( 'Visitor IP detection', 'dn-burst-funnel-stats' );
	$settings = dn_bfs_get_tracking_settings();
	$remote   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	$private  = '' !== $remote && false === filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	$proxied  = ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) || ! empty( $_SERVER['HTTP_X_REAL_IP'] );
	$via_cf   = ! empty( $_SERVER['HTTP_CF_RAY'] ) && 'auto' === $settings['client_ip_source'];

	if ( $private && $proxied && ! $via_cf && in_array( $settings['client_ip_source'], array( 'auto', 'remote_addr' ), true ) ) {
		return dn_bfs_status_check( 'proxy', $label, 'warning', __( 'The site is behind a proxy, so every visitor appears to share one IP. Set the client IP source to X-Forwarded-For or X-Real-IP in Tracking settings.', 'dn-burst-funnel-stats' ) );
	}

	return dn_bfs_status_check( 'proxy', $label, 'ok', __( 'Visitor IPs are detected correctly.', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_status_versions() {
	global $wpdb;

	$detail = sprintf(
		'Plugin %1$s · WordPress %2$s · WooCommerce %3$s · PHP %4$s · MySQL %5$s',
		DN_BURST_FUNNEL_STATS_VERSION,
		get_bloginfo( 'version' ),
		defined( 'WC_VERSION' ) ? WC_VERSION : '—',
		PHP_VERSION,
		$wpdb->db_version()
	);

	return dn_bfs_status_check( 'versions', __( 'Versions', 'dn-burst-funnel-stats' ), 'info', $detail );
}

function dn_bfs_system_status( $now = null ) {
	$now = null === $now ? dn_bfs_now() : (int) $now;

	return array(
		dn_bfs_status_tables(),
		dn_bfs_status_schema(),
		dn_bfs_status_aggregation( $now ),
		dn_bfs_status_cron(),
		dn_bfs_status_collect(),
		dn_bfs_status_api(),
		dn_bfs_status_tracker(),
		dn_bfs_status_geoip(),
		dn_bfs_status_geoip_public(),
		dn_bfs_status_proxy(),
		dn_bfs_status_versions(),
	);
}

