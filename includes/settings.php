<?php
/**
 * Settings page.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get tracking settings with defaults.
 *
 * @return array
 */
function dn_burst_funnel_stats_get_tracking_settings() {
	return function_exists( 'dn_bfs_get_tracking_settings' ) ? dn_bfs_get_tracking_settings() : array();
}

/**
 * Sanitize tracking settings.
 *
 * @param array $settings Raw settings.
 * @return array
 */
function dn_burst_funnel_stats_sanitize_tracking_settings( $settings ) {
	return function_exists( 'dn_bfs_sanitize_tracking_settings' )
		? dn_bfs_sanitize_tracking_settings( $settings )
		: array();
}

/**
 * Check whether an Add To Cart event should be tracked for a product.
 *
 * @param int $product_id Product or variation ID.
 * @return bool
 */
function dn_burst_funnel_stats_should_track_product( $product_id ) {
	return function_exists( 'dn_bfs_should_track_product' ) ? dn_bfs_should_track_product( $product_id ) : true;
}

/**
 * Save settings form.
 *
 * @return void
 */
function dn_burst_funnel_stats_handle_save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to save these settings.', 'dn-burst-funnel-stats' ) );
	}

	check_admin_referer( 'dn_burst_funnel_stats_save_settings' );

	$raw_settings = isset( $_POST['dn_burst_funnel_stats_tracking_settings'] )
		? wp_unslash( $_POST['dn_burst_funnel_stats_tracking_settings'] )
		: array();

	update_option(
		'dn_burst_funnel_stats_tracking_settings',
		dn_burst_funnel_stats_sanitize_tracking_settings( $raw_settings ),
		false
	);

	if ( function_exists( 'dn_burst_dash_clear_dashboard_cache' ) ) {
		dn_burst_dash_clear_dashboard_cache();
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'    => 'dn-burst-funnel-stats-settings',
				'updated' => '1',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_post_dn_burst_funnel_stats_save_settings', 'dn_burst_funnel_stats_handle_save_settings' );

/**
 * Return WooCommerce important page IDs.
 *
 * @return array
 */
function dn_burst_funnel_stats_get_woocommerce_page_suggestions() {
	$suggestions = array();
	$page_keys   = array(
		'shop'      => esc_html__( 'Shop', 'dn-burst-funnel-stats' ),
		'cart'      => esc_html__( 'Cart', 'dn-burst-funnel-stats' ),
		'checkout'  => esc_html__( 'Checkout', 'dn-burst-funnel-stats' ),
		'myaccount' => esc_html__( 'My Account', 'dn-burst-funnel-stats' ),
	);

	foreach ( $page_keys as $key => $label ) {
		$page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( $key ) : 0;

		if ( $page_id > 0 ) {
			$suggestions[ $page_id ] = $label;
		}
	}

	return $suggestions;
}

/**
 * Render a searchable multi-select field.
 *
 * @param string $name Select field name.
 * @param array  $posts Posts.
 * @param array  $selected_ids Selected IDs.
 * @param array  $badges Optional badges keyed by post ID.
 * @return void
 */
function dn_burst_funnel_stats_render_post_multiselect( $name, $posts, $selected_ids, $badges = array() ) {
	?>
	<div class="dn-burst-searchable-select" data-dn-searchable-select>
		<label class="screen-reader-text" for="<?php echo esc_attr( sanitize_key( $name ) ); ?>_search">
			<?php esc_html_e( 'Search items', 'dn-burst-funnel-stats' ); ?>
		</label>
		<input
			type="search"
			id="<?php echo esc_attr( sanitize_key( $name ) ); ?>_search"
			class="regular-text dn-burst-select-search"
			placeholder="<?php echo esc_attr__( 'Search by title...', 'dn-burst-funnel-stats' ); ?>"
			data-dn-select-search
		/>
		<select name="<?php echo esc_attr( $name ); ?>[]" multiple size="12" class="dn-burst-multiselect" data-dn-select-list>
			<?php foreach ( $posts as $post ) : ?>
				<?php
				$post_id = (int) $post->ID;
				$label   = get_the_title( $post_id );
				if ( isset( $badges[ $post_id ] ) ) {
					$label .= ' - ' . $badges[ $post_id ];
				}
				?>
				<option value="<?php echo esc_attr( $post_id ); ?>" <?php selected( in_array( $post_id, $selected_ids, true ) ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>
	<?php
}

/**
 * Render settings page.
 *
 * @return void
 */
function dn_burst_funnel_stats_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings    = dn_burst_funnel_stats_get_tracking_settings();
	$suggestions = dn_burst_funnel_stats_get_woocommerce_page_suggestions();
	$current_ip  = function_exists( 'dn_bfs_get_client_ip' ) ? dn_bfs_get_client_ip() : 'unknown';
	$pages       = get_pages(
		array(
			'post_status' => array( 'publish', 'private', 'draft' ),
			'sort_column' => 'post_title',
		)
	);
	$products    = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => 300,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
	?>
	<div class="wrap dn-burst-wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Funnel Stats Settings', 'dn-burst-funnel-stats' ); ?></h1>

		<?php if ( isset( $_GET['updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'dn-burst-funnel-stats' ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! empty( $settings['invalid_excluded_ips'] ) ) : ?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: comma-separated invalid IP rules. */
							__( 'These IP exclusion entries were ignored because they are invalid: %s', 'dn-burst-funnel-stats' ),
							implode( ', ', array_map( 'sanitize_text_field', $settings['invalid_excluded_ips'] ) )
						)
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dn-burst-settings-form">
			<?php wp_nonce_field( 'dn_burst_funnel_stats_save_settings' ); ?>
			<input type="hidden" name="action" value="dn_burst_funnel_stats_save_settings" />

			<div class="dn-burst-panel">
				<h2><?php esc_html_e( 'Page Tracking', 'dn-burst-funnel-stats' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Choose which site pages should be included in saved tracking configuration.', 'dn-burst-funnel-stats' ); ?></p>

				<fieldset>
					<label>
						<input type="radio" name="dn_burst_funnel_stats_tracking_settings[page_tracking_mode]" value="full" <?php checked( 'full', $settings['page_tracking_mode'] ); ?> />
						<?php esc_html_e( 'Track full site', 'dn-burst-funnel-stats' ); ?>
					</label>
					<br />
					<label>
						<input type="radio" name="dn_burst_funnel_stats_tracking_settings[page_tracking_mode]" value="selected" <?php checked( 'selected', $settings['page_tracking_mode'] ); ?> />
						<?php esc_html_e( 'Track only selected pages', 'dn-burst-funnel-stats' ); ?>
					</label>
				</fieldset>

				<h3><?php esc_html_e( 'Selected Pages', 'dn-burst-funnel-stats' ); ?></h3>
				<?php dn_burst_funnel_stats_render_post_multiselect( 'dn_burst_funnel_stats_tracking_settings[selected_page_ids]', $pages, $settings['selected_page_ids'], $suggestions ); ?>
				<p class="description"><?php esc_html_e( 'WooCommerce pages are labeled when available: Shop, Cart, Checkout, and My Account.', 'dn-burst-funnel-stats' ); ?></p>
			</div>

			<div class="dn-burst-panel">
				<h2><?php esc_html_e( 'Product Tracking', 'dn-burst-funnel-stats' ); ?></h2>
				<fieldset>
					<label>
						<input type="radio" name="dn_burst_funnel_stats_tracking_settings[product_tracking_mode]" value="all" <?php checked( 'all', $settings['product_tracking_mode'] ); ?> />
						<?php esc_html_e( 'Track all product pages', 'dn-burst-funnel-stats' ); ?>
					</label>
					<br />
					<label>
						<input type="radio" name="dn_burst_funnel_stats_tracking_settings[product_tracking_mode]" value="selected" <?php checked( 'selected', $settings['product_tracking_mode'] ); ?> />
						<?php esc_html_e( 'Track only selected products', 'dn-burst-funnel-stats' ); ?>
					</label>
				</fieldset>

				<h3><?php esc_html_e( 'Selected Products', 'dn-burst-funnel-stats' ); ?></h3>
				<?php dn_burst_funnel_stats_render_post_multiselect( 'dn_burst_funnel_stats_tracking_settings[selected_product_ids]', $products, $settings['selected_product_ids'] ); ?>
				<p class="description"><?php esc_html_e( 'Product suggestions are limited to the first 300 products by title.', 'dn-burst-funnel-stats' ); ?></p>
			</div>

			<div class="dn-burst-panel">
				<h2><?php esc_html_e( 'IP Exclusions', 'dn-burst-funnel-stats' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Prevent plugin tracking writes from specific visitor IP addresses.', 'dn-burst-funnel-stats' ); ?></p>
				<p>
					<label for="dn_excluded_ips"><strong><?php esc_html_e( 'Excluded IP Addresses', 'dn-burst-funnel-stats' ); ?></strong></label><br />
					<textarea
						id="dn_excluded_ips"
						name="dn_burst_funnel_stats_tracking_settings[excluded_ips]"
						rows="8"
						class="large-text code"
						placeholder="<?php echo esc_attr__( 'One IP address or CIDR range per line', 'dn-burst-funnel-stats' ); ?>"
					><?php echo esc_textarea( implode( "\n", (array) $settings['excluded_ips'] ) ); ?></textarea>
				</p>
				<p class="description"><?php esc_html_e( 'One IP address per line. Visits from these IP addresses will not be tracked. IPv4, IPv6, and CIDR ranges such as 192.168.1.0/24 are supported.', 'dn-burst-funnel-stats' ); ?></p>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: detected visitor IP. */
							__( 'Your current IP appears to be: %s', 'dn-burst-funnel-stats' ),
							$current_ip
						)
					);
					?>
				</p>
			</div>

			<div class="dn-burst-panel">
				<h2><?php esc_html_e( 'Bot & Crawler Exclusions', 'dn-burst-funnel-stats' ); ?></h2>
				<p>
					<label>
						<input type="checkbox" name="dn_burst_funnel_stats_tracking_settings[exclude_bots]" value="1" <?php checked( ! empty( $settings['exclude_bots'] ) ); ?> />
						<?php esc_html_e( 'Exclude known bots and crawlers from tracking', 'dn-burst-funnel-stats' ); ?>
					</label>
				</p>
				<p>
					<label for="dn_custom_bot_user_agents"><strong><?php esc_html_e( 'Custom Bot User Agents', 'dn-burst-funnel-stats' ); ?></strong></label><br />
					<textarea
						id="dn_custom_bot_user_agents"
						name="dn_burst_funnel_stats_tracking_settings[custom_bot_user_agents]"
						rows="8"
						class="large-text code"
						placeholder="<?php echo esc_attr__( 'One keyword per line', 'dn-burst-funnel-stats' ); ?>"
					><?php echo esc_textarea( implode( "\n", (array) $settings['custom_bot_user_agents'] ) ); ?></textarea>
				</p>
				<p class="description"><?php esc_html_e( 'One keyword per line. If the visitor user agent contains one of these keywords, the visit will not be tracked.', 'dn-burst-funnel-stats' ); ?></p>
			</div>

			<?php submit_button( esc_html__( 'Save Settings', 'dn-burst-funnel-stats' ) ); ?>
		</form>
	</div>
	<?php
}
