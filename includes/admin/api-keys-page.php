<?php
/**
 * Settings → API: create, list and revoke public API keys.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The full key lives only in this per-user transient until the next page view.
 */
function dn_bfs_api_reveal_key_store( $user_id, $key ) {
	set_transient( 'dnbfs_api_reveal_' . (int) $user_id, (string) $key, 5 * MINUTE_IN_SECONDS );
}

function dn_bfs_api_reveal_key_take( $user_id ) {
	$name = 'dnbfs_api_reveal_' . (int) $user_id;
	$key  = get_transient( $name );

	delete_transient( $name );

	return is_string( $key ) ? $key : '';
}

function dn_bfs_api_key_task( $task, $post, $user_id ) {
	if ( 'create' === $task ) {
		$pick   = function ( $name ) use ( $post ) {
			return isset( $post[ $name ] ) && is_scalar( $post[ $name ] ) ? (string) $post[ $name ] : '';
		};
		$result = dn_bfs_api_create_key(
			array(
				'name'        => $pick( 'name' ),
				'scopes'      => isset( $post['scopes'] ) && is_array( $post['scopes'] ) ? $post['scopes'] : array(),
				'allowed_ips' => $pick( 'allowed_ips' ),
				'rate_limit'  => $pick( 'rate_limit' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return array( 'tab' => 'api', 'notice' => $result->get_error_code() );
		}

		dn_bfs_api_reveal_key_store( $user_id, $result['key'] );

		return array( 'tab' => 'api', 'notice' => 'key_created' );
	}

	if ( 'revoke' === $task ) {
		$result = dn_bfs_api_revoke_key( isset( $post['key_id'] ) && is_scalar( $post['key_id'] ) ? absint( $post['key_id'] ) : 0 );

		return array( 'tab' => 'api', 'notice' => is_wp_error( $result ) ? $result->get_error_code() : 'key_revoked' );
	}

	return array( 'tab' => 'api', 'notice' => 'invalid_task' );
}

function dn_bfs_handle_api_key_task() {
	$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked below.

	if ( ! dn_bfs_admin_permission() ) {
		wp_die( esc_html__( 'You do not have permission to manage API keys.', 'dn-burst-funnel-stats' ), 403 );
	}

	check_admin_referer( 'dn_bfs_api_key_' . $task );

	$result = dn_bfs_api_key_task( $task, wp_unslash( $_POST ), get_current_user_id() );

	wp_safe_redirect( dn_bfs_settings_url( $result['tab'], array( 'dn_notice' => $result['notice'] ) ) );
	exit;
}
add_action( 'admin_post_dn_bfs_api_key', 'dn_bfs_handle_api_key_task' );

function dn_bfs_render_settings_api_keys_table( $keys ) {
	$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	$confirm = __( 'Revoke this key? Apps using it stop working immediately.', 'dn-burst-funnel-stats' );
	?>
	<table class="widefat striped dn-burst-api-keys">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'dn-burst-funnel-stats' ); ?></th>
				<th><?php esc_html_e( 'Key', 'dn-burst-funnel-stats' ); ?></th>
				<th><?php esc_html_e( 'Scopes', 'dn-burst-funnel-stats' ); ?></th>
				<th><?php esc_html_e( 'Allowed IPs', 'dn-burst-funnel-stats' ); ?></th>
				<th><?php esc_html_e( 'Rate limit', 'dn-burst-funnel-stats' ); ?></th>
				<th><?php esc_html_e( 'Last used', 'dn-burst-funnel-stats' ); ?></th>
				<th><?php esc_html_e( 'Created', 'dn-burst-funnel-stats' ); ?></th>
				<th><?php esc_html_e( 'Status', 'dn-burst-funnel-stats' ); ?></th>
				<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'dn-burst-funnel-stats' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $keys as $key ) : ?>
				<tr>
					<td><?php echo esc_html( $key['name'] ); ?></td>
					<td><code><?php echo esc_html( 'dnbfs_' . $key['prefix'] . '_…' ); ?></code></td>
					<td><?php echo esc_html( implode( ', ', $key['scopes'] ) ); ?></td>
					<td><?php echo esc_html( empty( $key['allowed_ips'] ) ? __( 'Any', 'dn-burst-funnel-stats' ) : implode( ', ', $key['allowed_ips'] ) ); ?></td>
					<?php /* translators: %d: requests per minute. */ ?>
					<td><?php echo esc_html( sprintf( __( '%d / min', 'dn-burst-funnel-stats' ), $key['rate_limit'] ) ); ?></td>
					<td><?php echo esc_html( $key['last_used_at'] ? wp_date( $format, $key['last_used_at'] ) : __( 'Never', 'dn-burst-funnel-stats' ) ); ?></td>
					<td><?php echo esc_html( wp_date( $format, $key['created_at'] ) ); ?></td>
					<td>
						<?php if ( $key['revoked_at'] ) : ?>
							<span class="dn-burst-status-badge is-error"><?php esc_html_e( 'Revoked', 'dn-burst-funnel-stats' ); ?></span>
						<?php else : ?>
							<span class="dn-burst-status-badge is-ok"><?php esc_html_e( 'Active', 'dn-burst-funnel-stats' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( ! $key['revoked_at'] ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dn-burst-inline-form" onsubmit="return window.confirm('<?php echo esc_js( $confirm ); ?>');">
								<?php wp_nonce_field( 'dn_bfs_api_key_revoke' ); ?>
								<input type="hidden" name="action" value="dn_bfs_api_key" />
								<input type="hidden" name="task" value="revoke" />
								<input type="hidden" name="key_id" value="<?php echo esc_attr( $key['id'] ); ?>" />
								<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Revoke', 'dn-burst-funnel-stats' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function dn_bfs_render_settings_api() {
	$revealed = dn_bfs_api_reveal_key_take( get_current_user_id() );
	$keys     = dn_bfs_api_list_keys();
	$base     = untrailingslashit( rest_url( dn_bfs_api_namespace() ) );
	$end      = wp_date( 'Y-m-d' );
	$start    = dn_bfs_date_shift( $end, -6 );
	?>
	<?php if ( '' !== $revealed ) : ?>
		<div class="notice notice-warning inline dn-burst-api-reveal">
			<p><strong><?php esc_html_e( 'Copy the new API key now. It will not be shown again.', 'dn-burst-funnel-stats' ); ?></strong></p>
			<p><input type="text" class="large-text code" readonly="readonly" value="<?php echo esc_attr( $revealed ); ?>" onfocus="this.select();" aria-label="<?php esc_attr_e( 'New API key', 'dn-burst-funnel-stats' ); ?>" /></p>
		</div>
	<?php endif; ?>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'API keys', 'dn-burst-funnel-stats' ); ?></h2>
		<?php if ( empty( $keys ) ) : ?>
			<p><?php esc_html_e( 'No API keys yet.', 'dn-burst-funnel-stats' ); ?></p>
		<?php else : ?>
			<?php dn_bfs_render_settings_api_keys_table( $keys ); ?>
		<?php endif; ?>
	</div>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'Create an API key', 'dn-burst-funnel-stats' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'dn_bfs_api_key_create' ); ?>
			<input type="hidden" name="action" value="dn_bfs_api_key" />
			<input type="hidden" name="task" value="create" />
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="dn_bfs_api_name"><?php esc_html_e( 'Name', 'dn-burst-funnel-stats' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="dn_bfs_api_name" name="name" maxlength="100" required />
							<p class="description"><?php esc_html_e( 'Which app uses this key, for example "Reporting backend".', 'dn-burst-funnel-stats' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Scopes', 'dn-burst-funnel-stats' ); ?></th>
						<td>
							<fieldset class="dn-burst-checklist">
								<?php foreach ( dn_bfs_api_scopes() as $scope => $label ) : ?>
									<label><input type="checkbox" name="scopes[]" value="<?php echo esc_attr( $scope ); ?>" <?php checked( 'stats:read', $scope ); ?> /> <code><?php echo esc_html( $scope ); ?></code> — <?php echo esc_html( $label ); ?></label><br />
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dn_bfs_api_ips"><?php esc_html_e( 'Allowed IPs', 'dn-burst-funnel-stats' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="4" id="dn_bfs_api_ips" name="allowed_ips"></textarea>
							<p class="description"><?php esc_html_e( 'One IP address or CIDR range per line. Leave empty to allow any IP. The client IP is the connection address; Cloudflare\'s CF-Connecting-IP is trusted only from Cloudflare ranges and X-Forwarded-For only from proxies listed by the dn_bfs_api_trusted_proxies filter.', 'dn-burst-funnel-stats' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dn_bfs_api_rate"><?php esc_html_e( 'Rate limit', 'dn-burst-funnel-stats' ); ?></label></th>
						<td><input type="number" class="small-text" id="dn_bfs_api_rate" name="rate_limit" value="60" min="1" max="1000" step="1" /> <?php esc_html_e( 'requests per minute', 'dn-burst-funnel-stats' ); ?></td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( __( 'Create API key', 'dn-burst-funnel-stats' ) ); ?>
		</form>
	</div>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'Using the API', 'dn-burst-funnel-stats' ); ?></h2>
		<p><?php esc_html_e( 'Base URL:', 'dn-burst-funnel-stats' ); ?> <code><?php echo esc_html( $base ); ?></code></p>
		<pre><code><?php echo esc_html( 'curl -H "Authorization: Bearer dnbfs_xxxxxxxx_…" "' . $base . '/stats/summary?start=' . $start . '&end=' . $end . '"' ); ?></code></pre>
		<p class="description">
			<?php esc_html_e( 'Read-only, for your own servers: HTTPS is required (local development hosts excepted) and browsers cannot call it because no CORS headers are sent. Statistics are cached for 60 seconds.', 'dn-burst-funnel-stats' ); ?>
			<?php /* translators: %s: OpenAPI document URL. */ ?>
			<?php echo esc_html( sprintf( __( 'Full description: %s', 'dn-burst-funnel-stats' ), $base . '/openapi.json' ) ); ?>
		</p>
	</div>
	<?php
}
