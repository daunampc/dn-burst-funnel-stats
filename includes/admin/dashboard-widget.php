<?php
/**
 * Compact "today" widget on the main WordPress dashboard.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_dashboard_widget_data( $now = null ) {
	$range   = dn_bfs_calculate_date_range( 'today', 'previous_period' );
	$summary = dn_bfs_report_summary( $range, array(), $now );

	if ( is_wp_error( $summary ) ) {
		return null;
	}

	$realtime = dn_bfs_report_realtime( $now );
	$data     = array( 'online' => (int) $realtime['online'] );

	foreach ( array( 'visitors', 'orders', 'revenue' ) as $metric ) {
		$data[ $metric ] = array(
			'today'     => $summary['current'][ $metric ],
			'yesterday' => null === $summary['previous'] ? 0 : $summary['previous'][ $metric ],
		);
	}

	return $data;
}

function dn_bfs_register_dashboard_widget() {
	if ( ! dn_bfs_admin_permission() ) {
		return;
	}

	wp_add_dashboard_widget( 'dnbfs_overview', __( 'Funnel Stats', 'dn-burst-funnel-stats' ), 'dn_bfs_render_dashboard_widget' );
}
add_action( 'wp_dashboard_setup', 'dn_bfs_register_dashboard_widget' );

function dn_bfs_render_dashboard_widget() {
	$data = dn_bfs_dashboard_widget_data();

	if ( null === $data ) {
		echo '<p>' . esc_html__( 'Stats are not available right now.', 'dn-burst-funnel-stats' ) . '</p>';
		return;
	}

	$money = function ( $value ) {
		return function_exists( 'wc_price' ) ? wc_price( $value ) : esc_html( number_format_i18n( $value, 2 ) );
	};
	$rows  = array(
		array( __( 'Visitors', 'dn-burst-funnel-stats' ), esc_html( number_format_i18n( $data['visitors']['today'] ) ), esc_html( number_format_i18n( $data['visitors']['yesterday'] ) ) ),
		array( __( 'Orders', 'dn-burst-funnel-stats' ), esc_html( number_format_i18n( $data['orders']['today'] ) ), esc_html( number_format_i18n( $data['orders']['yesterday'] ) ) ),
		array( __( 'Sales', 'dn-burst-funnel-stats' ), $money( $data['revenue']['today'] ), $money( $data['revenue']['yesterday'] ) ),
	);
	?>
	<p>
		<strong data-dnbfs-online><?php echo esc_html( number_format_i18n( $data['online'] ) ); ?></strong>
		<?php esc_html_e( 'visitors online now', 'dn-burst-funnel-stats' ); ?>
	</p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"></th>
				<th scope="col"><?php esc_html_e( 'Today', 'dn-burst-funnel-stats' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Yesterday', 'dn-burst-funnel-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
					<td><?php echo wp_kses_post( $row[1] ); ?></td>
					<td><?php echo wp_kses_post( $row[2] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=dn-burst-funnel-stats' ) ); ?>"><?php esc_html_e( 'Open the dashboard', 'dn-burst-funnel-stats' ); ?></a></p>
	<?php
}

function dn_bfs_enqueue_dashboard_widget( $hook_suffix ) {
	if ( 'index.php' !== $hook_suffix || ! dn_bfs_admin_permission() ) {
		return;
	}

	$path = DN_BURST_FUNNEL_STATS_PATH . 'assets/dashboard-widget.js';

	wp_enqueue_script(
		'dnbfs-dashboard-widget',
		DN_BURST_FUNNEL_STATS_URL . 'assets/dashboard-widget.js',
		array( 'jquery' ),
		file_exists( $path ) ? (string) filemtime( $path ) : DN_BURST_FUNNEL_STATS_VERSION,
		true
	);
	wp_localize_script(
		'dnbfs-dashboard-widget',
		'dnBfsWidget',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'dn_bfs_admin' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'dn_bfs_enqueue_dashboard_widget' );
