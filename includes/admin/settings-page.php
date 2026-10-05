<?php
/**
 * Settings screen: eight tabs of WordPress forms on top of the settings model.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_settings_tabs() {
	return array(
		'general'     => __( 'General', 'dn-burst-funnel-stats' ),
		'tracking'    => __( 'Tracking', 'dn-burst-funnel-stats' ),
		'antispam'    => __( 'Anti-spam', 'dn-burst-funnel-stats' ),
		'woocommerce' => __( 'WooCommerce', 'dn-burst-funnel-stats' ),
		'geoip'       => __( 'GeoIP', 'dn-burst-funnel-stats' ),
		'data'        => __( 'Data', 'dn-burst-funnel-stats' ),
		'system'      => __( 'System', 'dn-burst-funnel-stats' ),
		'api'         => __( 'API', 'dn-burst-funnel-stats' ),
	);
}

function dn_bfs_settings_current_tab() {
	$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification

	return array_key_exists( $tab, dn_bfs_settings_tabs() ) ? $tab : 'general';
}

function dn_bfs_settings_url( $tab, $args = array() ) {
	return add_query_arg(
		array_merge(
			array(
				'page' => 'dn-burst-funnel-stats-settings',
				'tab'  => $tab,
			),
			$args
		),
		admin_url( 'admin.php' )
	);
}

function dn_bfs_settings_fields() {
	$ranges     = dn_bfs_tracking_int_ranges();
	$rules_note = __( 'Changes apply to new days only. Days already aggregated keep the old rules until you re-aggregate them in Settings → Data → Re-aggregate.', 'dn-burst-funnel-stats' );
	$number = function ( $key, $label, $unit, $description = '' ) use ( $ranges ) {
		return array(
			'key'         => $key,
			'type'        => 'number',
			'label'       => $label,
			'unit'        => $unit,
			'min'         => $ranges[ $key ][0],
			'max'         => $ranges[ $key ][1],
			'description' => $description,
		);
	};

	return array(
		'general'     => array(
			array( 'key' => 'tracking_enabled', 'type' => 'checkbox', 'label' => __( 'Tracking', 'dn-burst-funnel-stats' ), 'text' => __( 'Record visits, add to cart and orders', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'default_date_range', 'type' => 'select', 'label' => __( 'Default date range', 'dn-burst-funnel-stats' ), 'options' => 'presets' ),
			array( 'key' => 'default_compare', 'type' => 'select', 'label' => __( 'Default comparison', 'dn-burst-funnel-stats' ), 'options' => array( 'previous_period' => __( 'Previous period', 'dn-burst-funnel-stats' ), 'previous_year' => __( 'Previous year', 'dn-burst-funnel-stats' ), 'none' => __( 'None', 'dn-burst-funnel-stats' ) ) ),
		),
		'tracking'    => array(
			array( 'key' => 'excluded_roles', 'type' => 'checklist', 'label' => __( 'Excluded roles', 'dn-burst-funnel-stats' ), 'options' => 'roles', 'description' => __( 'Logged-in users with these roles are never tracked.', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'excluded_ips', 'type' => 'lines', 'label' => __( 'Excluded IP addresses', 'dn-burst-funnel-stats' ), 'description' => __( 'One IP address or CIDR range per line, for example 192.168.1.0/24.', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'client_ip_source', 'type' => 'select', 'label' => __( 'Client IP source', 'dn-burst-funnel-stats' ), 'options' => array( 'auto' => __( 'Automatic (Cloudflare when present)', 'dn-burst-funnel-stats' ), 'remote_addr' => 'REMOTE_ADDR', 'x_forwarded_for' => 'X-Forwarded-For', 'x_real_ip' => 'X-Real-IP' ), 'description' => __( 'Change this only when the site is behind a proxy other than Cloudflare.', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'page_tracking_mode', 'type' => 'radio', 'label' => __( 'Pages', 'dn-burst-funnel-stats' ), 'options' => array( 'full' => __( 'Track the whole site', 'dn-burst-funnel-stats' ), 'selected' => __( 'Track only selected pages', 'dn-burst-funnel-stats' ) ) ),
			array( 'key' => 'selected_page_ids', 'type' => 'posts', 'post_type' => 'page', 'label' => __( 'Selected pages', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'product_tracking_mode', 'type' => 'radio', 'label' => __( 'Products', 'dn-burst-funnel-stats' ), 'options' => array( 'all' => __( 'Track all products', 'dn-burst-funnel-stats' ), 'selected' => __( 'Track only selected products', 'dn-burst-funnel-stats' ) ) ),
			array( 'key' => 'selected_product_ids', 'type' => 'posts', 'post_type' => 'product', 'label' => __( 'Selected products', 'dn-burst-funnel-stats' ) ),
			$number( 'session_timeout', __( 'Session timeout', 'dn-burst-funnel-stats' ), __( 'minutes', 'dn-burst-funnel-stats' ) ),
			$number( 'cookie_days', __( 'Visitor cookie lifetime', 'dn-burst-funnel-stats' ), __( 'days', 'dn-burst-funnel-stats' ) ),
		),
		'antispam'    => array(
			$number( 'dedupe_window', __( 'Product view and add to cart window', 'dn-burst-funnel-stats' ), __( 'seconds', 'dn-burst-funnel-stats' ), __( 'Each visitor counts once per product within this window (300 seconds = 5 minutes).', 'dn-burst-funnel-stats' ) ),
			$number( 'reload_window', __( 'Ignore reloads of the same page within', 'dn-burst-funnel-stats' ), __( 'seconds', 'dn-burst-funnel-stats' ) ),
			$number( 'limit_pv_per_min', __( 'Pageviews per minute per session', 'dn-burst-funnel-stats' ), '' ),
			$number( 'limit_sessions_per_hour', __( 'New sessions per hour per IP and browser', 'dn-burst-funnel-stats' ), '', __( 'Raise this for mobile networks where many shoppers share one IP.', 'dn-burst-funnel-stats' ) ),
			$number( 'limit_atc_per_min', __( 'Add to cart calls per minute per visitor', 'dn-burst-funnel-stats' ), '' ),
			$number( 'limit_pv_per_session', __( 'Pageviews per session', 'dn-burst-funnel-stats' ), '' ),
			array( 'key' => 'exclude_bots', 'type' => 'checkbox', 'label' => __( 'Bots', 'dn-burst-funnel-stats' ), 'text' => __( 'Ignore known bots and crawlers', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'block_empty_ua', 'type' => 'checkbox', 'label' => __( 'Empty user agent', 'dn-burst-funnel-stats' ), 'text' => __( 'Ignore requests without a user agent', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'custom_bot_user_agents', 'type' => 'lines', 'label' => __( 'Extra bot keywords', 'dn-burst-funnel-stats' ), 'description' => __( 'One keyword per line. User agents containing a keyword are ignored.', 'dn-burst-funnel-stats' ) ),
		),
		'woocommerce' => array(
			array( 'key' => 'force_cart_redirect', 'type' => 'checkbox', 'label' => __( 'Add to cart', 'dn-burst-funnel-stats' ), 'text' => __( 'Send shoppers to the Cart page after adding a product', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'sales_excluded_statuses', 'type' => 'statuses', 'label' => __( 'Not counted as sales', 'dn-burst-funnel-stats' ), 'description' => $rules_note ),
			array( 'key' => 'paid_statuses', 'type' => 'statuses', 'label' => __( 'Counted as paid', 'dn-burst-funnel-stats' ), 'description' => $rules_note ),
			array( 'key' => 'balance_statuses', 'type' => 'statuses', 'label' => __( 'Counted as balance', 'dn-burst-funnel-stats' ), 'description' => $rules_note ),
			array( 'key' => 'tip_keywords', 'type' => 'lines', 'label' => __( 'Tip fee keywords', 'dn-burst-funnel-stats' ), 'description' => __( 'Order fees whose name contains one of these words count as tips.', 'dn-burst-funnel-stats' ) . ' ' . $rules_note ),
		),
		'geoip'       => array(
			array( 'key' => 'prefer_cloudflare', 'type' => 'checkbox', 'label' => __( 'Cloudflare', 'dn-burst-funnel-stats' ), 'text' => __( 'Use Cloudflare country headers when present', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'maxmind_license_key', 'type' => 'password', 'label' => __( 'MaxMind license key', 'dn-burst-funnel-stats' ), 'description' => __( 'Free key from maxmind.com. The GeoLite2 City database is downloaded and refreshed every 30 days.', 'dn-burst-funnel-stats' ) ),
		),
		'data'        => array(
			$number( 'raw_retention_days', __( 'Keep raw tracking data for', 'dn-burst-funnel-stats' ), __( 'days', 'dn-burst-funnel-stats' ), __( 'Daily totals are kept forever. Beyond this window, combined filters show order and revenue figures only.', 'dn-burst-funnel-stats' ) ),
		),
	);
}

function dn_bfs_settings_input_from_post( $group, $post ) {
	$fields = dn_bfs_settings_fields();

	if ( ! isset( $fields[ $group ] ) ) {
		return array();
	}

	$raw   = isset( $post['dn_bfs'] ) && is_array( $post['dn_bfs'] ) ? $post['dn_bfs'] : array();
	$input = array();

	foreach ( $fields[ $group ] as $field ) {
		$key   = $field['key'];
		$value = isset( $raw[ $key ] ) ? $raw[ $key ] : null;

		switch ( $field['type'] ) {
			case 'checkbox':
				$input[ $key ] = empty( $value ) ? 0 : 1;
				break;
			case 'checklist':
			case 'statuses':
			case 'posts':
				$input[ $key ] = is_array( $value ) ? array_values( $value ) : array();
				break;
			case 'number':
				$input[ $key ] = (int) $value;
				break;
			default:
				$input[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}
	}

	return $input;
}

function dn_bfs_wc_revenue_rules() {
	$settings = dn_bfs_get_wc_report_settings();
	$rules    = array();

	// Sorted so that the same statuses or keywords in another order are not a rule change.
	foreach ( array( 'sales_excluded_statuses', 'paid_statuses', 'balance_statuses', 'tip_keywords' ) as $key ) {
		$list = array_values( (array) $settings[ $key ] );
		sort( $list, SORT_STRING );
		$rules[ $key ] = $list;
	}

	return $rules;
}

function dn_bfs_settings_save_from_post( $group, $post ) {
	$before = dn_bfs_wc_revenue_rules();
	$result = dn_bfs_save_settings_group( $group, dn_bfs_settings_input_from_post( $group, $post ) );

	if ( is_wp_error( $result ) ) {
		return $result->get_error_code();
	}

	return dn_bfs_wc_revenue_rules() !== $before ? 'saved_wc_rules' : 'saved';
}

function dn_bfs_reaggregate_outcome( $result ) {
	$run = $result['result'];

	if ( empty( $run['ok'] ) ) {
		return array( 'tab' => 'data', 'notice' => isset( $run['reason'] ) && 'locked' === $run['reason'] ? 'reagg_locked' : 'reagg_failed' );
	}

	if ( 0 === (int) $result['queued'] ) {
		return array( 'tab' => 'data', 'notice' => 'reagg_none' );
	}

	if ( (int) $result['remaining'] > 0 ) {
		return array(
			'tab'    => 'data',
			'notice' => 'reagg_partial',
			'args'   => array(
				'dn_done' => (int) $result['queued'] - (int) $result['remaining'],
				'dn_left' => (int) $result['remaining'],
			),
		);
	}

	return array( 'tab' => 'data', 'notice' => 'reaggregated' );
}

function dn_bfs_settings_import_notice( $payload ) {
	$before = dn_bfs_wc_revenue_rules();
	$result = dn_bfs_import_settings( $payload );

	if ( is_wp_error( $result ) ) {
		return $result->get_error_code();
	}

	return dn_bfs_wc_revenue_rules() !== $before ? 'imported_wc_rules' : 'imported';
}

function dn_bfs_settings_data_task( $task, $post, $files ) {
	switch ( $task ) {
		case 'reaggregate':
			$result = dn_bfs_reaggregate_range( isset( $post['start'] ) ? (string) $post['start'] : '', isset( $post['end'] ) ? (string) $post['end'] : '' );

			if ( is_wp_error( $result ) ) {
				return array( 'tab' => 'data', 'notice' => $result->get_error_code() );
			}

			return dn_bfs_reaggregate_outcome( $result );
		case 'import_orders':
			// A second click must not send a running import back to the first order.
			if ( 'running' === dn_bfs_backfill_state()['status'] ) {
				return array( 'tab' => 'data', 'notice' => 'orders_import_running' );
			}

			dn_bfs_backfill_start();

			return array( 'tab' => 'data', 'notice' => 'orders_import_started' );
		case 'purge':
			$result = dn_bfs_purge_all_data( isset( $post['confirm'] ) ? (string) $post['confirm'] : '' );

			return array( 'tab' => 'data', 'notice' => is_wp_error( $result ) ? $result->get_error_code() : 'purged' );
		case 'import':
			if ( empty( $files['import_file']['tmp_name'] ) || ! is_uploaded_file( $files['import_file']['tmp_name'] ) ) {
				return array( 'tab' => 'data', 'notice' => 'missing_file' );
			}

			return array( 'tab' => 'data', 'notice' => dn_bfs_settings_import_notice( json_decode( (string) file_get_contents( $files['import_file']['tmp_name'] ), true ) ) );
		case 'geoip_update':
			$result = dn_bfs_geoip_update_now();

			if ( is_wp_error( $result ) ) {
				return array( 'tab' => 'geoip', 'notice' => $result->get_error_code() );
			}

			return array( 'tab' => 'geoip', 'notice' => $result['ok'] ? 'geoip_updated' : 'geoip_' . $result['reason'] );
	}

	return array( 'tab' => 'data', 'notice' => 'invalid_task' );
}

function dn_bfs_settings_notice( $code, $args = array() ) {
	$messages = array(
		'saved'            => array( 'success', __( 'Settings saved.', 'dn-burst-funnel-stats' ) ),
		'saved_wc_rules'   => array( 'warning', __( 'Settings saved. Days that were already aggregated keep the old WooCommerce revenue rules. To apply the new rules to past days, go to Settings → Data → Re-aggregate.', 'dn-burst-funnel-stats' ) ),
		'imported_wc_rules' => array( 'warning', __( 'Settings imported. Days that were already aggregated keep the old WooCommerce revenue rules. To apply the new rules to past days, go to Settings → Data → Re-aggregate.', 'dn-burst-funnel-stats' ) ),
		'reaggregated'     => array( 'success', __( 'The selected days were re-aggregated.', 'dn-burst-funnel-stats' ) ),
		'purged'           => array( 'success', __( 'All tracking data was deleted.', 'dn-burst-funnel-stats' ) ),
		'orders_import_started' => array( 'success', __( 'Importing past WooCommerce orders in the background. Daily totals update within the next hours.', 'dn-burst-funnel-stats' ) ),
		'orders_import_running' => array( 'info', __( 'The order import is already running in the background.', 'dn-burst-funnel-stats' ) ),
		'imported'         => array( 'success', __( 'Settings imported.', 'dn-burst-funnel-stats' ) ),
		'geoip_updated'    => array( 'success', __( 'The GeoIP database was updated.', 'dn-burst-funnel-stats' ) ),
		'confirm_required' => array( 'error', __( 'Type DELETE to confirm.', 'dn-burst-funnel-stats' ) ),
		'invalid_import'   => array( 'error', __( 'This file is not a DN Burst Funnel Stats export.', 'dn-burst-funnel-stats' ) ),
		'missing_file'     => array( 'error', __( 'Choose a JSON file to import.', 'dn-burst-funnel-stats' ) ),
		'no_license'       => array( 'error', __( 'Add a MaxMind license key first.', 'dn-burst-funnel-stats' ) ),
		'invalid_date'     => array( 'error', __( 'Use valid start and end dates.', 'dn-burst-funnel-stats' ) ),
		'reagg_locked'     => array( 'warning', __( 'Aggregation is already running. Try again shortly.', 'dn-burst-funnel-stats' ) ),
		'reagg_none'       => array( 'info', __( 'Nothing to rebuild: the selected range is today or later.', 'dn-burst-funnel-stats' ) ),
		'range_too_long'   => array( 'error', __( 'Re-aggregate at most 92 days at a time.', 'dn-burst-funnel-stats' ) ),
		'key_created'        => array( 'success', __( 'API key created. Copy it from the box below — it is shown only once.', 'dn-burst-funnel-stats' ) ),
		'key_revoked'        => array( 'success', __( 'The API key was revoked.', 'dn-burst-funnel-stats' ) ),
		'invalid_name'       => array( 'error', __( 'Give the key a name.', 'dn-burst-funnel-stats' ) ),
		'invalid_scopes'     => array( 'error', __( 'Choose at least one scope.', 'dn-burst-funnel-stats' ) ),
		'invalid_ips'        => array( 'error', __( 'Allowed IPs must be IP addresses or CIDR ranges, one per line.', 'dn-burst-funnel-stats' ) ),
		'invalid_rate_limit' => array( 'error', __( 'The rate limit must be between 1 and 1000 requests per minute.', 'dn-burst-funnel-stats' ) ),
		'key_not_found'      => array( 'error', __( 'That key does not exist or is already revoked.', 'dn-burst-funnel-stats' ) ),
		'key_create_failed'  => array( 'error', __( 'The key could not be saved. Please try again.', 'dn-burst-funnel-stats' ) ),
	);

	if ( isset( $messages[ $code ] ) ) {
		return $messages[ $code ];
	}

	if ( 'reagg_partial' === $code ) {
		$done = isset( $args['done'] ) ? (int) $args['done'] : 0;
		$left = isset( $args['left'] ) ? (int) $args['left'] : 0;

		/* translators: 1: rebuilt days, 2: days still queued. */
		return array( 'warning', sprintf( __( '%1$d days rebuilt, %2$d remaining queued for the background job.', 'dn-burst-funnel-stats' ), $done, $left ) );
	}

	if ( 'reagg_failed' === $code ) {
		$error   = get_option( 'dnbfs_aggregate_last_error' );
		$message = is_array( $error ) && ! empty( $error['message'] ) ? (string) $error['message'] : __( 'unknown error', 'dn-burst-funnel-stats' );

		/* translators: %s: error message. */
		return array( 'error', sprintf( __( 'Re-aggregation failed: %s', 'dn-burst-funnel-stats' ), $message ) );
	}

	if ( 0 === strpos( $code, 'geoip_' ) ) {
		$reasons = array(
			'no_license'        => __( 'Add a MaxMind license key first.', 'dn-burst-funnel-stats' ),
			'download_failed'   => __( 'The database could not be downloaded.', 'dn-burst-funnel-stats' ),
			'checksum_mismatch' => __( 'The downloaded file failed its checksum check.', 'dn-burst-funnel-stats' ),
			'extract_failed'    => __( 'The downloaded archive could not be extracted.', 'dn-burst-funnel-stats' ),
			'mmdb_missing'      => __( 'The archive did not contain a database file.', 'dn-burst-funnel-stats' ),
			'invalid_database'  => __( 'The downloaded database is not valid.', 'dn-burst-funnel-stats' ),
			'write_failed'      => __( 'The database could not be saved to disk.', 'dn-burst-funnel-stats' ),
		);
		$reason  = substr( $code, 6 );

		/* translators: %s: reason. */
		return array( 'error', sprintf( __( 'The GeoIP update failed: %s', 'dn-burst-funnel-stats' ), isset( $reasons[ $reason ] ) ? $reasons[ $reason ] : __( 'unexpected error.', 'dn-burst-funnel-stats' ) ) );
	}

	return array( 'error', __( 'Something went wrong. Please try again.', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_handle_save_settings() {
	$group = isset( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked below.

	if ( ! dn_bfs_admin_permission() ) {
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'dn-burst-funnel-stats' ), 403 );
	}

	check_admin_referer( 'dn_bfs_save_settings_' . $group );

	wp_safe_redirect( dn_bfs_settings_url( $group, array( 'dn_notice' => dn_bfs_settings_save_from_post( $group, wp_unslash( $_POST ) ) ) ) );
	exit;
}
add_action( 'admin_post_dn_bfs_save_settings', 'dn_bfs_handle_save_settings' );

function dn_bfs_handle_data_task() {
	$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked below.

	if ( ! dn_bfs_admin_permission() ) {
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'dn-burst-funnel-stats' ), 403 );
	}

	check_admin_referer( 'dn_bfs_data_' . $task );

	$result = dn_bfs_settings_data_task( $task, wp_unslash( $_POST ), $_FILES );

	wp_safe_redirect( dn_bfs_settings_url( $result['tab'], array_merge( array( 'dn_notice' => $result['notice'] ), isset( $result['args'] ) ? $result['args'] : array() ) ) );
	exit;
}
add_action( 'admin_post_dn_bfs_data_task', 'dn_bfs_handle_data_task' );

function dn_bfs_handle_export() {
	if ( ! dn_bfs_admin_permission() ) {
		wp_die( esc_html__( 'You do not have permission to export these settings.', 'dn-burst-funnel-stats' ), 403 );
	}

	check_admin_referer( 'dn_bfs_data_export' );
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=dn-burst-funnel-stats-settings-' . gmdate( 'Y-m-d' ) . '.json' );
	echo wp_json_encode( dn_bfs_export_settings(), JSON_PRETTY_PRINT );
	exit;
}
add_action( 'admin_post_dn_bfs_export', 'dn_bfs_handle_export' );

function dn_bfs_render_post_multiselect( $name, $posts, $selected_ids ) {
	$selected_ids = array_map( 'absint', (array) $selected_ids );
	$id           = sanitize_key( $name );
	?>
	<div class="dn-burst-searchable-select" data-dn-searchable-select>
		<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>_search"><?php esc_html_e( 'Search items', 'dn-burst-funnel-stats' ); ?></label>
		<input type="search" id="<?php echo esc_attr( $id ); ?>_search" class="regular-text dn-burst-select-search" placeholder="<?php echo esc_attr__( 'Search by title…', 'dn-burst-funnel-stats' ); ?>" data-dn-select-search />
		<select name="<?php echo esc_attr( $name ); ?>[]" multiple size="10" class="dn-burst-multiselect" data-dn-select-list>
			<?php foreach ( $posts as $post ) : ?>
				<option value="<?php echo esc_attr( $post->ID ); ?>" <?php selected( in_array( (int) $post->ID, $selected_ids, true ) ); ?>><?php echo esc_html( get_the_title( $post->ID ) ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<?php
}

/**
 * Posts for the multiselects: a capped list plus every currently selected ID,
 * so a saved selection beyond the cap survives the next save.
 */
function dn_bfs_settings_selectable_posts( $post_type, $selected ) {
	$selected = array_values( array_filter( array_map( 'absint', (array) $selected ) ) );
	$statuses = array( 'publish', 'private', 'draft' );

	if ( 'page' === $post_type ) {
		$posts = get_pages( array( 'post_status' => $statuses, 'sort_column' => 'post_title' ) );
		$type  = 'page';
	} else {
		$limit = max( 1, (int) apply_filters( 'dn_bfs_settings_posts_limit', 300 ) );
		$posts = get_posts( array( 'post_type' => 'product', 'post_status' => $statuses, 'posts_per_page' => $limit, 'orderby' => 'title', 'order' => 'ASC' ) );
		$type  = 'product';
	}

	$have = wp_list_pluck( $posts, 'ID' );
	$more = array_diff( $selected, array_map( 'intval', $have ) );

	if ( $more ) {
		$extra = get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'post__in' => $more, 'posts_per_page' => count( $more ), 'orderby' => 'title', 'order' => 'ASC' ) );
		$posts = array_merge( $posts, $extra );
	}

	return $posts;
}

function dn_bfs_render_setting_field( $field, $value, $meta ) {
	$name = 'dn_bfs[' . $field['key'] . ']';
	$id   = 'dn_bfs_' . $field['key'];

	switch ( $field['type'] ) {
		case 'checkbox':
			printf( '<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>', esc_attr( $id ), esc_attr( $name ), checked( ! empty( $value ), true, false ), esc_html( $field['text'] ) );
			break;
		case 'select':
			$options = 'presets' === $field['options'] ? $meta['presets'] : $field['options'];
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( $options as $option => $label ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option ), selected( (string) $value, (string) $option, false ), esc_html( $label ) );
			}
			echo '</select>';
			break;
		case 'radio':
			echo '<fieldset>';
			foreach ( $field['options'] as $option => $label ) {
				printf( '<label><input type="radio" name="%1$s" value="%2$s" %3$s /> %4$s</label><br />', esc_attr( $name ), esc_attr( $option ), checked( (string) $value, (string) $option, false ), esc_html( $label ) );
			}
			echo '</fieldset>';
			break;
		case 'number':
			printf( '<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$s" min="%4$d" max="%5$d" step="1" /> %6$s', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), (int) $field['min'], (int) $field['max'], esc_html( $field['unit'] ) );
			break;
		case 'lines':
			printf( '<textarea class="large-text code" rows="6" id="%1$s" name="%2$s">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( implode( "\n", (array) $value ) ) );
			break;
		case 'password':
			printf( '<input type="password" class="regular-text" autocomplete="off" id="%1$s" name="%2$s" value="%3$s" />', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
			break;
		case 'checklist':
		case 'statuses':
			$options = 'statuses' === $field['type'] ? $meta['order_statuses'] : $meta['roles'];
			echo '<fieldset class="dn-burst-checklist">';
			foreach ( $options as $option => $label ) {
				$label = 'statuses' === $field['type'] ? $label : translate_user_role( $label );
				printf( '<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>', esc_attr( $name ), esc_attr( $option ), checked( in_array( $option, (array) $value, true ), true, false ), esc_html( $label ) );
			}
			echo '</fieldset>';
			break;
		case 'posts':
			$posts = dn_bfs_settings_selectable_posts( $field['post_type'], $value );
			dn_bfs_render_post_multiselect( $name, $posts, $value );
			break;
	}

	if ( ! empty( $field['description'] ) ) {
		echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
	}

	if ( 'excluded_ips' === $field['key'] ) {
		/* translators: %s: visitor IP address. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Your current IP appears to be: %s', 'dn-burst-funnel-stats' ), dn_bfs_get_client_ip() ) ) . '</p>';
	}
}

function dn_bfs_render_settings_form( $group ) {
	$data   = dn_bfs_get_settings_group( $group );
	$fields = dn_bfs_settings_fields();

	if ( 'tracking' === $group && ! empty( $data['meta']['invalid_excluded_ips'] ) ) {
		/* translators: %s: comma-separated invalid IP rules. */
		echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'These IP entries were ignored because they are invalid: %s', 'dn-burst-funnel-stats' ), implode( ', ', $data['meta']['invalid_excluded_ips'] ) ) ) . '</p></div>';
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dn-burst-settings-form">
		<?php wp_nonce_field( 'dn_bfs_save_settings_' . $group ); ?>
		<input type="hidden" name="action" value="dn_bfs_save_settings" />
		<input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>" />
		<div class="dn-burst-panel">
			<table class="form-table" role="presentation">
				<tbody>
					<?php foreach ( $fields[ $group ] as $field ) : ?>
						<tr>
							<th scope="row">
								<?php if ( in_array( $field['type'], array( 'radio', 'checklist', 'statuses', 'posts' ), true ) ) : ?>
									<?php echo esc_html( $field['label'] ); ?>
								<?php else : ?>
									<label for="<?php echo esc_attr( 'dn_bfs_' . $field['key'] ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
								<?php endif; ?>
							</th>
							<td><?php dn_bfs_render_setting_field( $field, $data['values'][ $field['key'] ], $data['meta'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php submit_button( __( 'Save Settings', 'dn-burst-funnel-stats' ) ); ?>
	</form>
	<?php
}

function dn_bfs_render_data_task_form( $task, $button, $fields_html = '', $class = 'button', $multipart = false ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dn-burst-inline-form" <?php echo $multipart ? 'enctype="multipart/form-data"' : ''; ?>>
		<?php wp_nonce_field( 'dn_bfs_data_' . $task ); ?>
		<input type="hidden" name="action" value="dn_bfs_data_task" />
		<input type="hidden" name="task" value="<?php echo esc_attr( $task ); ?>" />
		<?php echo $fields_html; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts by callers. ?>
		<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $button ); ?></button>
	</form>
	<?php
}

function dn_bfs_render_settings_antispam_panel() {
	$stats = dn_bfs_blocked_stats( 7 );
	?>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'Blocked requests (last 7 days)', 'dn-burst-funnel-stats' ); ?></h2>
		<?php if ( empty( $stats ) ) : ?>
			<p><?php esc_html_e( 'Nothing was blocked.', 'dn-burst-funnel-stats' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<tbody>
					<?php foreach ( $stats as $reason => $count ) : ?>
						<tr><td><code><?php echo esc_html( $reason ); ?></code></td><td><?php echo esc_html( number_format_i18n( $count ) ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

function dn_bfs_render_settings_geoip_panel() {
	$status = dn_bfs_geoip_status();
	$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	?>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'GeoIP database', 'dn-burst-funnel-stats' ); ?></h2>
		<table class="widefat striped">
			<tbody>
				<tr><td><?php esc_html_e( 'Database file', 'dn-burst-funnel-stats' ); ?></td><td><?php echo esc_html( $status['database'] ? size_format( $status['size'] ) : __( 'Not downloaded', 'dn-burst-funnel-stats' ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Last update', 'dn-burst-funnel-stats' ); ?></td><td><?php echo esc_html( $status['updated_at'] ? wp_date( $format, $status['updated_at'] ) : '—' ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Last error', 'dn-burst-funnel-stats' ); ?></td><td><?php echo esc_html( '' !== $status['last_error'] ? $status['last_error'] : '—' ); ?></td></tr>
			</tbody>
		</table>
		<?php dn_bfs_render_data_task_form( 'geoip_update', __( 'Update database now', 'dn-burst-funnel-stats' ) ); ?>
	</div>
	<?php
}

function dn_bfs_render_settings_orders_panel() {
	$stats  = dn_bfs_order_import_stats();
	$state  = $stats['state'];
	$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	$labels = array(
		'idle'    => __( 'Not run yet', 'dn-burst-funnel-stats' ),
		'running' => __( 'Running in the background', 'dn-burst-funnel-stats' ),
		'done'    => __( 'Done', 'dn-burst-funnel-stats' ),
	);
	$last   = max( (int) $state['last_run'], (int) $state['finished_at'] );
	?>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'WooCommerce orders', 'dn-burst-funnel-stats' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Orders placed before the plugin was installed, or created outside the checkout (admin, REST API, mobile app, some payment gateways), are imported with their WooCommerce order attribution. New untracked orders are picked up every hour, 15 minutes after they were created.', 'dn-burst-funnel-stats' ); ?></p>
		<table class="widefat striped">
			<tbody>
				<tr><td><?php esc_html_e( 'Import status', 'dn-burst-funnel-stats' ); ?></td><td><?php echo esc_html( isset( $labels[ $state['status'] ] ) ? $labels[ $state['status'] ] : $state['status'] ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Orders imported', 'dn-burst-funnel-stats' ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $state['inserted'] ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Last run', 'dn-burst-funnel-stats' ); ?></td><td><?php echo esc_html( $last ? wp_date( $format, $last ) : '—' ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Orders in WooCommerce', 'dn-burst-funnel-stats' ); ?></td><td><?php echo esc_html( number_format_i18n( $stats['orders_total'] ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Orders recorded in stats', 'dn-burst-funnel-stats' ); ?></td><td><?php echo esc_html( number_format_i18n( $stats['orders_tracked'] ) ); ?></td></tr>
			</tbody>
		</table>
		<?php dn_bfs_render_data_task_form( 'import_orders', __( 'Import past orders now', 'dn-burst-funnel-stats' ) ); ?>
	</div>
	<?php
}

function dn_bfs_render_settings_data_panels() {
	$stats = dn_bfs_data_stats();
	?>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'Storage', 'dn-burst-funnel-stats' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Table', 'dn-burst-funnel-stats' ); ?></th><th><?php esc_html_e( 'Rows (approx.)', 'dn-burst-funnel-stats' ); ?></th><th><?php esc_html_e( 'Size', 'dn-burst-funnel-stats' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $stats['tables'] as $name => $table ) : ?>
					<tr><td><code><?php echo esc_html( dn_bfs_table( $name ) ); ?></code></td><td><?php echo esc_html( number_format_i18n( $table['rows'] ) ); ?></td><td><?php echo esc_html( size_format( $table['bytes'] ) ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php /* translators: 1: last aggregated date, 2: first date with raw data. */ ?>
		<p class="description"><?php echo esc_html( sprintf( __( 'Aggregated through %1$s. Raw data is available from %2$s.', 'dn-burst-funnel-stats' ), '' !== $stats['last_aggregated'] ? $stats['last_aggregated'] : '—', $stats['raw_available_from'] ) ); ?></p>
	</div>
	<?php dn_bfs_render_settings_orders_panel(); ?>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'Re-aggregate', 'dn-burst-funnel-stats' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Rebuild daily totals for a date range (at most 92 days).', 'dn-burst-funnel-stats' ); ?></p>
		<?php
		dn_bfs_render_data_task_form(
			'reaggregate',
			__( 'Re-aggregate', 'dn-burst-funnel-stats' ),
			'<input type="date" name="start" required /> <input type="date" name="end" required /> '
		);
		?>
	</div>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'Export / Import settings', 'dn-burst-funnel-stats' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dn-burst-inline-form">
			<?php wp_nonce_field( 'dn_bfs_data_export' ); ?>
			<input type="hidden" name="action" value="dn_bfs_export" />
			<button type="submit" class="button"><?php esc_html_e( 'Download settings (JSON)', 'dn-burst-funnel-stats' ); ?></button>
		</form>
		<?php dn_bfs_render_data_task_form( 'import', __( 'Import settings', 'dn-burst-funnel-stats' ), '<input type="file" name="import_file" accept=".json,application/json" required /> ', 'button', true ); ?>
		<p class="description"><?php esc_html_e( 'Exports never include the MaxMind license key or tracking data.', 'dn-burst-funnel-stats' ); ?></p>
	</div>
	<div class="dn-burst-panel dn-burst-danger-zone">
		<h2><?php esc_html_e( 'Delete all tracking data', 'dn-burst-funnel-stats' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Removes every visitor, session, pageview, event and daily total. Settings are kept. This cannot be undone.', 'dn-burst-funnel-stats' ); ?></p>
		<?php
		dn_bfs_render_data_task_form(
			'purge',
			__( 'Delete all data', 'dn-burst-funnel-stats' ),
			'<input type="text" name="confirm" placeholder="DELETE" autocomplete="off" required /> ',
			'button button-link-delete'
		);
		?>
	</div>
	<?php
}

function dn_bfs_render_settings_system() {
	?>
	<div class="dn-burst-panel">
		<table class="widefat striped dn-burst-status-table">
			<tbody>
				<?php foreach ( dn_bfs_system_status() as $check ) : ?>
					<tr>
						<td><span class="dn-burst-status-badge is-<?php echo esc_attr( $check['status'] ); ?>"><?php echo esc_html( $check['status'] ); ?></span></td>
						<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
						<td><?php echo esc_html( $check['detail'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

function dn_bfs_render_settings_page() {
	if ( ! dn_bfs_admin_permission() ) {
		return;
	}

	$tab    = dn_bfs_settings_current_tab();
	$notice = isset( $_GET['dn_notice'] ) && is_string( $_GET['dn_notice'] ) ? sanitize_key( wp_unslash( $_GET['dn_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	?>
	<div class="wrap dn-burst-wrap dn-burst-settings">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Funnel Stats Settings', 'dn-burst-funnel-stats' ); ?></h1>
		<?php if ( '' !== $notice ) : ?>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification
			list( $type, $message ) = dn_bfs_settings_notice( $notice, array( 'done' => isset( $_GET['dn_done'] ) ? absint( $_GET['dn_done'] ) : 0, 'left' => isset( $_GET['dn_left'] ) ? absint( $_GET['dn_left'] ) : 0 ) );
			?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
		<?php endif; ?>
		<nav class="nav-tab-wrapper dn-burst-tabs">
			<?php foreach ( dn_bfs_settings_tabs() as $key => $label ) : ?>
				<a href="<?php echo esc_url( dn_bfs_settings_url( $key ) ); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		if ( 'system' === $tab ) {
			dn_bfs_render_settings_system();
		} elseif ( 'api' === $tab ) {
			dn_bfs_render_settings_api();
		} else {
			dn_bfs_render_settings_form( $tab );

			if ( 'antispam' === $tab ) {
				dn_bfs_render_settings_antispam_panel();
			} elseif ( 'geoip' === $tab ) {
				dn_bfs_render_settings_geoip_panel();
			} elseif ( 'data' === $tab ) {
				dn_bfs_render_settings_data_panels();
			}
		}
		?>
	</div>
	<?php
}
