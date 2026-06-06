<?php
/**
 * Import/export tools.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get plugin option/transient names that should be portable.
 *
 * @return array
 */
function dn_burst_funnel_stats_get_exportable_option_names() {
	global $wpdb;

	$names = $wpdb->get_col(
		"
		SELECT option_name
		FROM {$wpdb->options}
		WHERE option_name LIKE 'dn_atc\_%'
			OR option_name IN (
				'dn_burst_funnel_stats_schema_version',
				'dn_burst_funnel_stats_tracking_settings',
				'dn_burst_funnel_stats_url_tracking_settings',
				'dn_burst_funnel_stats_last_refresh'
			)
			OR option_name LIKE '_transient_dn_atc\_%'
			OR option_name LIKE '_transient_timeout_dn_atc\_%'
		ORDER BY option_name ASC
		"
	);

	return array_values( array_map( 'sanitize_text_field', (array) $names ) );
}

/**
 * Build export payload.
 *
 * @return array
 */
function dn_burst_funnel_stats_build_export_payload() {
	$options = array();

	foreach ( dn_burst_funnel_stats_get_exportable_option_names() as $option_name ) {
		$options[ $option_name ] = get_option( $option_name );
	}

	return array(
		'meta'    => array(
			'plugin_name'         => 'DN Burst Funnel Stats',
			'plugin_version'      => DN_BURST_FUNNEL_STATS_VERSION,
			'export_date'         => current_time( 'mysql' ),
			'site_url'            => site_url(),
			'data_schema_version' => DN_BURST_FUNNEL_STATS_SCHEMA_VERSION,
		),
		'options' => $options,
	);
}

/**
 * Export JSON file.
 *
 * @return void
 */
function dn_burst_funnel_stats_handle_export() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to export this data.', 'dn-burst-funnel-stats' ) );
	}

	check_admin_referer( 'dn_burst_funnel_stats_export' );

	$payload  = dn_burst_funnel_stats_build_export_payload();
	$filename = 'dn-burst-funnel-stats-export-' . gmdate( 'Y-m-d-His' ) . '.json';

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );
	header( 'X-Content-Type-Options: nosniff' );

	echo wp_json_encode( $payload, JSON_PRETTY_PRINT );
	exit;
}
add_action( 'admin_post_dn_burst_funnel_stats_export', 'dn_burst_funnel_stats_handle_export' );

/**
 * Recursively sanitize imported values.
 *
 * @param mixed $value Raw value.
 * @return mixed
 */
function dn_burst_funnel_stats_sanitize_import_value( $value ) {
	if ( is_array( $value ) ) {
		$clean = array();

		foreach ( $value as $key => $item ) {
			$clean_key           = is_int( $key ) ? $key : sanitize_text_field( (string) $key );
			$clean[ $clean_key ] = dn_burst_funnel_stats_sanitize_import_value( $item );
		}

		return $clean;
	}

	if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
		return $value;
	}

	return sanitize_text_field( (string) $value );
}

/**
 * Check whether an imported option belongs to this plugin.
 *
 * @param string $option_name Option name.
 * @return bool
 */
function dn_burst_funnel_stats_is_importable_option( $option_name ) {
	$option_name = (string) $option_name;

	if ( preg_match( '/^dn_atc_(hits|qty|url_groups)_\d{4}_\d{2}_\d{2}$/', $option_name ) ) {
		return true;
	}

	if ( preg_match( '/^_transient(_timeout)?_dn_atc_/', $option_name ) ) {
		return true;
	}

	return in_array(
		$option_name,
		array(
			'dn_burst_funnel_stats_schema_version',
			'dn_burst_funnel_stats_tracking_settings',
			'dn_burst_funnel_stats_url_tracking_settings',
			'dn_burst_funnel_stats_last_refresh',
		),
		true
	);
}

/**
 * Validate and normalize import payloads, including older schema shapes.
 *
 * @param array $payload Raw payload.
 * @return array|WP_Error
 */
function dn_burst_funnel_stats_normalize_import_payload( $payload ) {
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'invalid_payload', esc_html__( 'The import file is not a valid JSON object.', 'dn-burst-funnel-stats' ) );
	}

	if ( isset( $payload['options'] ) && is_array( $payload['options'] ) ) {
		$options = $payload['options'];
	} else {
		$options = array();

		foreach ( $payload as $key => $value ) {
			if ( dn_burst_funnel_stats_is_importable_option( $key ) ) {
				$options[ $key ] = $value;
			}
		}
	}

	if ( empty( $options ) ) {
		return new WP_Error( 'empty_payload', esc_html__( 'No importable plugin data was found in the file.', 'dn-burst-funnel-stats' ) );
	}

	$normalized = array();

	foreach ( $options as $option_name => $value ) {
		$option_name = sanitize_text_field( (string) $option_name );

		if ( ! dn_burst_funnel_stats_is_importable_option( $option_name ) ) {
			continue;
		}

		if ( 'dn_burst_funnel_stats_tracking_settings' === $option_name && function_exists( 'dn_bfs_sanitize_tracking_settings' ) ) {
			$normalized[ $option_name ] = dn_bfs_sanitize_tracking_settings( $value );
		} else {
			$normalized[ $option_name ] = dn_burst_funnel_stats_sanitize_import_value( $value );
		}
	}

	if ( empty( $normalized ) ) {
		return new WP_Error( 'empty_payload', esc_html__( 'No supported option names were found in the file.', 'dn-burst-funnel-stats' ) );
	}

	return $normalized;
}

/**
 * Import JSON file.
 *
 * @return void
 */
function dn_burst_funnel_stats_handle_import() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to import this data.', 'dn-burst-funnel-stats' ) );
	}

	check_admin_referer( 'dn_burst_funnel_stats_import' );

	$mode = isset( $_POST['dn_import_mode'] ) ? sanitize_key( wp_unslash( $_POST['dn_import_mode'] ) ) : 'merge';
	if ( ! in_array( $mode, array( 'merge', 'replace' ), true ) ) {
		$mode = 'merge';
	}

	if ( empty( $_FILES['dn_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['dn_import_file']['tmp_name'] ) ) {
		dn_burst_funnel_stats_redirect_import_export( 'missing_file' );
	}

	$raw = file_get_contents( sanitize_text_field( wp_unslash( $_FILES['dn_import_file']['tmp_name'] ) ) );
	if ( false === $raw ) {
		dn_burst_funnel_stats_redirect_import_export( 'read_error' );
	}

	$payload = json_decode( $raw, true );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		dn_burst_funnel_stats_redirect_import_export( 'invalid_json' );
	}

	$options = dn_burst_funnel_stats_normalize_import_payload( $payload );
	if ( is_wp_error( $options ) ) {
		dn_burst_funnel_stats_redirect_import_export( $options->get_error_code() );
	}

	if ( 'replace' === $mode ) {
		foreach ( dn_burst_funnel_stats_get_exportable_option_names() as $option_name ) {
			delete_option( $option_name );
		}
	}

	foreach ( $options as $option_name => $value ) {
		update_option( $option_name, $value, false );
	}

	dn_burst_funnel_stats_maybe_migrate();
	if ( function_exists( 'dn_burst_dash_clear_dashboard_cache' ) ) {
		dn_burst_dash_clear_dashboard_cache();
	}
	dn_burst_funnel_stats_redirect_import_export( 'imported', count( $options ) );
}
add_action( 'admin_post_dn_burst_funnel_stats_import', 'dn_burst_funnel_stats_handle_import' );

/**
 * Redirect back to import/export page.
 *
 * @param string $status Status key.
 * @param int    $count Imported count.
 * @return void
 */
function dn_burst_funnel_stats_redirect_import_export( $status, $count = 0 ) {
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'             => 'dn-burst-funnel-stats-import-export',
				'dn_import_status' => sanitize_key( $status ),
				'dn_import_count'  => absint( $count ),
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Render admin notice for import status.
 *
 * @return void
 */
function dn_burst_funnel_stats_render_import_notice() {
	if ( empty( $_GET['dn_import_status'] ) ) {
		return;
	}

	$status = sanitize_key( wp_unslash( $_GET['dn_import_status'] ) );
	$count  = isset( $_GET['dn_import_count'] ) ? absint( $_GET['dn_import_count'] ) : 0;

	if ( 'imported' === $status ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: imported option count. */
					__( 'Import completed. %d records were saved.', 'dn-burst-funnel-stats' ),
					$count
				)
			)
		);
		return;
	}

	$messages = array(
		'missing_file'    => esc_html__( 'Please choose a JSON file to import.', 'dn-burst-funnel-stats' ),
		'read_error'      => esc_html__( 'The uploaded file could not be read.', 'dn-burst-funnel-stats' ),
		'invalid_json'    => esc_html__( 'The uploaded file is not valid JSON.', 'dn-burst-funnel-stats' ),
		'invalid_payload' => esc_html__( 'The import file is not a valid export from this plugin.', 'dn-burst-funnel-stats' ),
		'empty_payload'   => esc_html__( 'No supported plugin data was found in the import file.', 'dn-burst-funnel-stats' ),
	);

	$message = isset( $messages[ $status ] ) ? $messages[ $status ] : esc_html__( 'Import failed.', 'dn-burst-funnel-stats' );

	printf(
		'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
		esc_html( $message )
	);
}

/**
 * Render import/export page.
 *
 * @return void
 */
function dn_burst_funnel_stats_render_import_export_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap dn-burst-wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Import / Export', 'dn-burst-funnel-stats' ); ?></h1>
		<?php dn_burst_funnel_stats_render_import_notice(); ?>

		<div class="dn-burst-admin-grid">
			<div class="dn-burst-panel">
				<h2><?php esc_html_e( 'Export Data', 'dn-burst-funnel-stats' ); ?></h2>
				<p><?php esc_html_e( 'Download plugin settings, Add To Cart counters, page tracking configuration, URL tracking configuration, and restore metadata as a JSON file.', 'dn-burst-funnel-stats' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'dn_burst_funnel_stats_export' ); ?>
					<input type="hidden" name="action" value="dn_burst_funnel_stats_export" />
					<?php submit_button( esc_html__( 'Download Export File', 'dn-burst-funnel-stats' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="dn-burst-panel">
				<h2><?php esc_html_e( 'Import Data', 'dn-burst-funnel-stats' ); ?></h2>
				<p><?php esc_html_e( 'Import a JSON file previously exported by DN Burst Funnel Stats.', 'dn-burst-funnel-stats' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'dn_burst_funnel_stats_import' ); ?>
					<input type="hidden" name="action" value="dn_burst_funnel_stats_import" />

					<p>
						<label for="dn_import_file"><?php esc_html_e( 'JSON file', 'dn-burst-funnel-stats' ); ?></label><br />
						<input type="file" id="dn_import_file" name="dn_import_file" accept="application/json,.json" required />
					</p>

					<fieldset>
						<legend><?php esc_html_e( 'Import mode', 'dn-burst-funnel-stats' ); ?></legend>
						<label>
							<input type="radio" name="dn_import_mode" value="merge" checked />
							<?php esc_html_e( 'Merge with existing data', 'dn-burst-funnel-stats' ); ?>
						</label>
						<br />
						<label>
							<input type="radio" name="dn_import_mode" value="replace" />
							<?php esc_html_e( 'Replace existing data', 'dn-burst-funnel-stats' ); ?>
						</label>
					</fieldset>

					<?php submit_button( esc_html__( 'Import JSON', 'dn-burst-funnel-stats' ) ); ?>
				</form>
			</div>
		</div>
	</div>
	<?php
}
