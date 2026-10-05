<?php
/**
 * Dashboard page rendering, upgraded from the original layout.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_dash_tabs() {
	return array(
		'overview'  => array( 'label' => __( 'Overview', 'dn-burst-funnel-stats' ), 'dimensions' => array() ),
		'pages'     => array( 'label' => __( 'Pages', 'dn-burst-funnel-stats' ), 'dimensions' => array( 'page', 'entry', 'exit' ) ),
		'sources'   => array( 'label' => __( 'Sources', 'dn-burst-funnel-stats' ), 'dimensions' => array( 'channel', 'referrer', 'source', 'medium' ) ),
		'ad-urls'   => array(
			'label'         => __( 'Ad URLs', 'dn-burst-funnel-stats' ),
			'dimensions'    => array( 'campaign', 'source', 'medium' ),
			// Only ad/campaign traffic: rows without a value are left out of this tab.
			'exclude_empty' => true,
			'empty'         => __( 'No campaign traffic in this period. Tag ad links with utm_campaign (Google Ads auto-tagging is detected automatically).', 'dn-burst-funnel-stats' ),
		),
		'products'  => array( 'label' => __( 'Products', 'dn-burst-funnel-stats' ), 'dimensions' => array( 'product' ) ),
		'brands'    => array( 'label' => __( 'Brands', 'dn-burst-funnel-stats' ), 'dimensions' => array( 'brand' ) ),
		'countries' => array( 'label' => __( 'Countries', 'dn-burst-funnel-stats' ), 'dimensions' => array( 'country', 'city' ) ),
		'devices'   => array( 'label' => __( 'Devices', 'dn-burst-funnel-stats' ), 'dimensions' => array( 'device', 'browser', 'os' ) ),
	);
}

function dn_bfs_dash_sanitize_tab( $tab ) {
	$tab = is_scalar( $tab ) ? sanitize_key( (string) $tab ) : '';

	return array_key_exists( $tab, dn_bfs_dash_tabs() ) ? $tab : 'overview';
}

function dn_bfs_dash_dimension_labels() {
	return array(
		'page'     => __( 'Page', 'dn-burst-funnel-stats' ),
		'entry'    => __( 'Entry page', 'dn-burst-funnel-stats' ),
		'exit'     => __( 'Exit page', 'dn-burst-funnel-stats' ),
		'channel'  => __( 'Channel', 'dn-burst-funnel-stats' ),
		'referrer' => __( 'Referrer', 'dn-burst-funnel-stats' ),
		'source'   => __( 'Source', 'dn-burst-funnel-stats' ),
		'medium'   => __( 'Medium', 'dn-burst-funnel-stats' ),
		'campaign' => __( 'Campaign', 'dn-burst-funnel-stats' ),
		'product'  => __( 'Product', 'dn-burst-funnel-stats' ),
		'brand'    => __( 'Brand', 'dn-burst-funnel-stats' ),
		'country'  => __( 'Country', 'dn-burst-funnel-stats' ),
		'city'     => __( 'City', 'dn-burst-funnel-stats' ),
		'device'   => __( 'Device', 'dn-burst-funnel-stats' ),
		'browser'  => __( 'Browser', 'dn-burst-funnel-stats' ),
		'os'       => __( 'Operating system', 'dn-burst-funnel-stats' ),
	);
}

function dn_bfs_dash_channel_labels() {
	return array(
		'direct'         => __( 'Direct', 'dn-burst-funnel-stats' ),
		'organic_search' => __( 'Organic search', 'dn-burst-funnel-stats' ),
		'social'         => __( 'Social', 'dn-burst-funnel-stats' ),
		'paid'           => __( 'Paid', 'dn-burst-funnel-stats' ),
		'email'          => __( 'Email', 'dn-burst-funnel-stats' ),
		'referral'       => __( 'Referral', 'dn-burst-funnel-stats' ),
		'admin'          => __( 'Admin', 'dn-burst-funnel-stats' ),
	);
}

function dn_bfs_dash_metric_labels() {
	return array(
		'pageviews'       => __( 'Pageviews', 'dn-burst-funnel-stats' ),
		'visitors'        => __( 'Visitors', 'dn-burst-funnel-stats' ),
		'sessions'        => __( 'Sessions', 'dn-burst-funnel-stats' ),
		'bounce_rate'     => __( 'Bounce rate', 'dn-burst-funnel-stats' ),
		'avg_duration'    => __( 'Avg. time', 'dn-burst-funnel-stats' ),
		'product_views'   => __( 'Product views', 'dn-burst-funnel-stats' ),
		'atc'             => __( 'Add to cart', 'dn-burst-funnel-stats' ),
		'checkouts'       => __( 'Checkout', 'dn-burst-funnel-stats' ),
		'orders'          => __( 'Orders', 'dn-burst-funnel-stats' ),
		'items'           => __( 'Items', 'dn-burst-funnel-stats' ),
		'revenue'         => __( 'Sales', 'dn-burst-funnel-stats' ),
		'conversion_rate' => __( 'Conversion', 'dn-burst-funnel-stats' ),
	);
}

function dn_bfs_dash_columns( $dimension ) {
	if ( 'page' === $dimension ) {
		return array( 'pageviews', 'visitors', 'avg_duration' );
	}

	if ( in_array( $dimension, array( 'entry', 'exit' ), true ) ) {
		return array( 'sessions', 'visitors', 'bounce_rate', 'avg_duration', 'orders', 'conversion_rate' );
	}

	if ( in_array( $dimension, array( 'product', 'brand' ), true ) ) {
		return array( 'product_views', 'atc', 'orders', 'items', 'revenue' );
	}

	return array( 'visitors', 'sessions', 'bounce_rate', 'product_views', 'atc', 'checkouts', 'orders', 'revenue', 'conversion_rate' );
}

function dn_bfs_dash_format_cell( $metric, $value ) {
	if ( in_array( $metric, array( 'revenue', 'tips', 'paid', 'balance', 'aov' ), true ) ) {
		return dn_bfs_dash_money( $value );
	}

	if ( 'bounce_rate' === $metric ) {
		return esc_html( dn_bfs_dash_percent( $value ) );
	}

	if ( 'conversion_rate' === $metric ) {
		return esc_html( dn_bfs_dash_percent( $value, 2 ) );
	}

	if ( 'avg_duration' === $metric ) {
		return esc_html( dn_bfs_dash_duration( $value ) );
	}

	if ( in_array( $metric, array( 'pages_per_session', 'aoi' ), true ) ) {
		return esc_html( dn_bfs_dash_number( $value, 2 ) );
	}

	return esc_html( dn_bfs_dash_number( $value ) );
}

function dn_bfs_dash_value_label( $dimension, $row ) {
	$value = (string) $row['dim_value'];

	if ( '' === $value ) {
		return __( '(none)', 'dn-burst-funnel-stats' );
	}

	if ( 'channel' === $dimension ) {
		$labels = dn_bfs_dash_channel_labels();

		return isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
	}

	return isset( $row['label'] ) && '' !== (string) $row['label'] ? (string) $row['label'] : $value;
}

function dn_bfs_dash_notice( $message, $type = 'warning' ) {
	return sprintf( '<div class="notice notice-%1$s inline dn-burst-notice"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $message ) );
}

function dn_bfs_dash_estimate_note() {
	return '<p class="dn-burst-estimate-note">' . esc_html__( 'Visitor counts are estimated for this range (summed per day).', 'dn-burst-funnel-stats' ) . '</p>';
}

/**
 * Compact note for orders-only results (no raw tracking data for the range).
 */
function dn_bfs_dash_traffic_note() {
	return '<p class="dn-burst-estimate-note dn-burst-traffic-note">' . esc_html( dn_bfs_dashboard_traffic_message() ) . '</p>';
}

function dn_bfs_dash_request( $params ) {
	$range = dn_bfs_parse_range( $params );

	if ( is_wp_error( $range ) ) {
		return $range;
	}

	$filters = dn_bfs_parse_filters( $params );

	if ( is_wp_error( $filters ) ) {
		return $filters;
	}

	return array( $range, $filters );
}

function dn_bfs_dash_url( $tab, $range, $filters ) {
	$args = array(
		'page'       => 'dn-burst-funnel-stats',
		'dn_tab'     => $tab,
		'dn_period'  => $range['period'],
		'dn_compare' => $range['compare'],
	);

	if ( 'custom' === $range['period'] ) {
		$args['dn_start'] = $range['custom_start'];
		$args['dn_end']   = $range['custom_end'];
	}

	if ( $filters ) {
		$args['dn_filter'] = array_map( 'rawurlencode', $filters );
	}

	return add_query_arg( array_map( function ( $value ) {
		return is_array( $value ) ? $value : rawurlencode( (string) $value );
	}, $args ), admin_url( 'admin.php' ) );
}

/**
 * Compact data-status labels for the toolbar: "Oct 3" and "9:00" (the next run
 * also shows its date when it is not today).
 */
function dn_bfs_dash_status_labels() {
	$last = (string) get_option( 'dnbfs_last_aggregated_date', '' );
	$next = wp_next_scheduled( 'dnbfs_aggregate' );
	$time = (string) get_option( 'time_format' );
	$same = $next && wp_date( 'Y-m-d', $next ) === wp_date( 'Y-m-d', dn_bfs_now() );

	return array(
		'last' => '' !== $last && dn_bfs_valid_date_string( $last ) ? dn_bfs_dash_date_label( $last ) : __( 'Not yet', 'dn-burst-funnel-stats' ),
		'next' => $next ? wp_date( $same ? $time : 'M j ' . $time, $next ) : __( 'Not scheduled', 'dn-burst-funnel-stats' ),
	);
}

function dn_bfs_dash_chart_series_total( $series ) {
	$total = 0;

	foreach ( (array) $series as $value ) {
		$total += (float) $value;
	}

	return $total;
}

function dn_bfs_dash_format_chart_value( $value, $format = 'integer' ) {
	if ( 'money' === $format ) {
		return dn_bfs_dash_money( $value );
	}

	if ( 'percent' === $format ) {
		return esc_html( number_format_i18n( (float) $value, 1 ) . '%' );
	}

	return esc_html( number_format_i18n( (float) $value ) );
}

function dn_bfs_dash_chart_subtitle( $type ) {
	$subtitles = array(
		'sales'      => __( 'Net sales and order volume for the selected range.', 'dn-burst-funnel-stats' ),
		'funnel'     => __( 'Step-by-step movement from visitors through completed orders.', 'dn-burst-funnel-stats' ),
		'conversion' => __( 'Daily orders divided by visitors.', 'dn-burst-funnel-stats' ),
		'top-sales'  => __( 'Campaigns ranked by WooCommerce sales.', 'dn-burst-funnel-stats' ),
		'trend'      => __( 'Daily visitors and orders.', 'dn-burst-funnel-stats' ),
	);

	return isset( $subtitles[ $type ] ) ? $subtitles[ $type ] : '';
}

function dn_bfs_dash_chart_total( $type, $data ) {
	$first = isset( $data['series'][0]['values'] ) ? $data['series'][0]['values'] : ( isset( $data['values'] ) ? $data['values'] : array() );

	if ( in_array( $type, array( 'sales', 'top-sales' ), true ) ) {
		return dn_bfs_dash_money( dn_bfs_dash_chart_series_total( $first ) );
	}

	if ( 'conversion' === $type && ! empty( $first ) ) {
		return dn_bfs_dash_format_chart_value( array_sum( array_map( 'floatval', $first ) ) / count( $first ), 'percent' );
	}

	if ( 'funnel' === $type && ! empty( $data['values'] ) ) {
		$values = array_values( (array) $data['values'] );

		return dn_bfs_dash_format_chart_value( end( $values ), 'integer' );
	}

	return '';
}

function dn_bfs_dash_chart_total_label( $type ) {
	$labels = array(
		'sales'      => __( 'Total sales', 'dn-burst-funnel-stats' ),
		'funnel'     => __( 'Orders', 'dn-burst-funnel-stats' ),
		'conversion' => __( 'Avg. rate', 'dn-burst-funnel-stats' ),
		'top-sales'  => __( 'Campaign sales', 'dn-burst-funnel-stats' ),
	);

	return isset( $labels[ $type ] ) ? $labels[ $type ] : '';
}

function dn_bfs_dash_chart_has_values( $data ) {
	$values = array();

	if ( ! empty( $data['series'] ) ) {
		foreach ( (array) $data['series'] as $series ) {
			$values = array_merge( $values, isset( $series['values'] ) ? (array) $series['values'] : array() );
		}
	} elseif ( isset( $data['values'] ) ) {
		$values = (array) $data['values'];
	}

	foreach ( $values as $value ) {
		if ( (float) $value > 0 ) {
			return true;
		}
	}

	return false;
}

function dn_bfs_dash_render_chart_legend( $type, $data ) {
	if ( 'funnel' === $type ) {
		$labels = isset( $data['labels'] ) ? array_values( (array) $data['labels'] ) : array();
		$values = isset( $data['values'] ) ? array_values( (array) $data['values'] ) : array();
		$base   = isset( $values[0] ) ? max( 1, (float) $values[0] ) : 1;
		$colors = array( '#2271b1', '#00a32a', '#dba617', '#7f54b3', '#d63638' );
		?>
		<div class="dn-burst-funnel-legend" aria-label="<?php echo esc_attr__( 'Funnel step details', 'dn-burst-funnel-stats' ); ?>">
			<?php foreach ( $labels as $index => $label ) : ?>
				<?php
				$value      = isset( $values[ $index ] ) ? (float) $values[ $index ] : 0;
				$previous   = $index > 0 && isset( $values[ $index - 1 ] ) ? (float) $values[ $index - 1 ] : $base;
				$step_rate  = $previous > 0 ? ( $value / $previous ) * 100 : 0;
				$visit_rate = $base > 0 ? ( $value / $base ) * 100 : 0;
				?>
				<div class="dn-burst-funnel-legend-row">
					<span class="dn-burst-chart-legend-label">
						<span class="dn-burst-chart-legend-dot" style="background-color: <?php echo esc_attr( $colors[ $index % count( $colors ) ] ); ?>"></span>
						<span><?php echo esc_html( $label ); ?></span>
					</span>
					<strong><?php echo esc_html( number_format_i18n( $value ) ); ?></strong>
					<?php /* translators: 1: step conversion rate, 2: share of visitors. */ ?>
					<span><?php echo esc_html( sprintf( __( '%1$s step, %2$s of visitors', 'dn-burst-funnel-stats' ), number_format_i18n( $step_rate, 1 ) . '%', number_format_i18n( $visit_rate, 1 ) . '%' ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return;
	}
	?>
	<div class="dn-burst-chart-legend" aria-label="<?php echo esc_attr__( 'Chart legend', 'dn-burst-funnel-stats' ); ?>">
		<?php foreach ( (array) ( isset( $data['series'] ) ? $data['series'] : array() ) as $row ) : ?>
			<div class="dn-burst-chart-legend-row">
				<span class="dn-burst-chart-legend-label">
					<span class="dn-burst-chart-legend-dot" style="background-color: <?php echo esc_attr( isset( $row['color'] ) ? $row['color'] : '#2271b1' ); ?>"></span>
					<span><?php echo esc_html( isset( $row['label'] ) ? $row['label'] : '' ); ?></span>
				</span>
				<strong><?php echo dn_bfs_dash_kses_money( dn_bfs_dash_format_chart_value( dn_bfs_dash_chart_series_total( isset( $row['values'] ) ? $row['values'] : array() ), isset( $row['format'] ) ? $row['format'] : 'integer' ) ); ?></strong>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

function dn_bfs_dash_render_chart_panel( $title, $type, $data ) {
	$data         = is_array( $data ) ? $data : array();
	$data['type'] = $type;
	$total        = dn_bfs_dash_chart_total( $type, $data );
	$total_label  = dn_bfs_dash_chart_total_label( $type );
	$subtitle     = dn_bfs_dash_chart_subtitle( $type );
	$has_values   = dn_bfs_dash_chart_has_values( $data );
	?>
	<div class="dn-burst-panel dn-burst-chart-panel <?php echo $has_values ? '' : 'is-empty'; ?>" data-dn-chart-panel>
		<div class="dn-burst-chart-header">
			<div>
				<h2 class="dn-burst-chart-title"><?php echo esc_html( $title ); ?></h2>
				<?php if ( '' !== $subtitle ) : ?>
					<p class="dn-burst-chart-subtitle"><?php echo esc_html( $subtitle ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( '' !== $total && '' !== $total_label ) : ?>
				<div class="dn-burst-chart-total">
					<span><?php echo esc_html( $total_label ); ?></span>
					<strong><?php echo dn_bfs_dash_kses_money( $total ); ?></strong>
				</div>
			<?php endif; ?>
		</div>
		<div class="dn-burst-chart-body">
			<canvas class="dn-burst-chart" height="250" data-dn-chart="<?php echo esc_attr( $type ); ?>" data-chart="<?php echo esc_attr( wp_json_encode( $data ) ); ?>"></canvas>
			<p class="dn-burst-chart-empty" <?php echo $has_values ? 'hidden' : ''; ?>><?php echo esc_html( ! empty( $data['empty'] ) ? $data['empty'] : __( 'No chart data is available for this period yet.', 'dn-burst-funnel-stats' ) ); ?></p>
			<div class="dn-burst-chart-tooltip" role="status" aria-live="polite" hidden></div>
		</div>
		<?php dn_bfs_dash_render_chart_legend( $type, $data ); ?>
	</div>
	<?php
}

/**
 * Pill classes: direction (is-up / is-down) plus color (is-good / is-bad),
 * inverted for lower-is-better metrics; is-flat when unchanged or not compared.
 */
function dn_bfs_dash_trend_class( $card ) {
	$trend = isset( $card['trend'] ) ? (string) $card['trend'] : '';

	if ( ! in_array( $trend, array( 'up', 'down' ), true ) ) {
		return 'is-flat';
	}

	$good = ( 'up' === $trend ) !== ! empty( $card['lower_is_better'] );

	return 'is-' . $trend . ' ' . ( $good ? 'is-good' : 'is-bad' );
}

function dn_bfs_dash_hex_rgb( $hex ) {
	$hex = ltrim( (string) $hex, '#' );

	if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
		return '34, 113, 177';
	}

	return implode( ', ', array_map( 'hexdec', str_split( $hex, 2 ) ) );
}

function dn_bfs_dash_render_card( $key, $card, $compare_label, $hidden ) {
	$accent = isset( $card['accent'] ) ? (string) $card['accent'] : '#2271b1';
	$style  = sprintf( '--dn-card-accent: %1$s; --dn-card-rgb: %2$s;', $accent, dn_bfs_dash_hex_rgb( $accent ) );
	$trend  = isset( $card['trend'] ) ? (string) $card['trend'] : '';
	$arrows = array(
		'up'   => '&#9650;',
		'down' => '&#9660;',
	);
	?>
	<div class="dn-burst-card<?php echo ! empty( $card['unavailable'] ) ? ' is-unavailable' : ''; ?><?php echo $hidden ? ' is-hidden' : ''; ?>" data-dn-card="<?php echo esc_attr( $key ); ?>" style="<?php echo esc_attr( $style ); ?>" title="<?php echo esc_attr( $card['help'] ); ?>">
		<label class="dn-burst-card-toggle">
			<input type="checkbox" data-dn-card-visible <?php checked( ! $hidden ); ?> />
			<span class="screen-reader-text"><?php esc_html_e( 'Show this card', 'dn-burst-funnel-stats' ); ?></span>
		</label>
		<span class="dn-burst-card-handle dashicons dashicons-move" aria-hidden="true"></span>
		<div class="dn-burst-card-head">
			<h3 class="dn-burst-card-title"><?php echo esc_html( $card['title'] ); ?></h3>
			<span class="dn-burst-card-icon" aria-hidden="true"><span class="dashicons dashicons-<?php echo esc_attr( $card['icon'] ); ?>"></span></span>
		</div>
		<div class="dn-burst-main-line">
			<div class="dn-burst-main"><?php echo dn_bfs_dash_kses_money( $card['main'] ); ?></div>
			<?php if ( '' !== $card['secondary'] ) : ?>
				<div class="dn-burst-secondary"><?php echo dn_bfs_dash_kses_money( $card['secondary'] ); ?></div>
			<?php endif; ?>
		</div>
		<?php if ( '' !== $compare_label ) : ?>
			<div class="dn-burst-card-foot">
				<?php if ( empty( $card['compare_unavailable'] ) ) : ?>
					<span class="dn-burst-change <?php echo esc_attr( dn_bfs_dash_trend_class( $card ) ); ?>">
						<?php if ( isset( $arrows[ $trend ] ) ) : ?>
							<span class="dn-burst-change-arrow" aria-hidden="true"><?php echo $arrows[ $trend ]; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed entity. ?></span>
						<?php endif; ?>
						<?php echo esc_html( '' !== $card['change'] ? ltrim( $card['change'], '+-' ) : '0%' ); ?>
					</span>
				<?php endif; ?>
				<?php /* translators: 1: comparison label such as "vs. Previous year", 2: previous value. */ ?>
				<span class="dn-burst-compare"><?php echo dn_bfs_dash_kses_money( sprintf( __( '%1$s: %2$s', 'dn-burst-funnel-stats' ), esc_html( $compare_label ), '' !== $card['compare'] ? $card['compare'] : '&ndash;' ) ); ?></span>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Full date-range text for the compact date button tooltip.
 */
function dn_bfs_dash_date_tooltip( $range ) {
	$text = $range['current_label'] . ' (' . $range['current_range_label'] . ')';

	if ( 'none' !== $range['compare'] ) {
		$text .= ' · ' . $range['compare_label'] . ' (' . $range['previous_range_label'] . ')';
	}

	return $text;
}

function dn_bfs_dash_render_date_picker( $range, $tab ) {
	$presets = dn_bfs_get_date_presets();
	?>
	<div class="dn-burst-date-control" data-dn-date-control>
		<button type="button" class="button dn-burst-date-toggle" data-dn-date-toggle title="<?php echo esc_attr( dn_bfs_dash_date_tooltip( $range ) ); ?>" aria-label="<?php echo esc_attr( dn_bfs_dash_date_tooltip( $range ) ); ?>">
			<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
			<span class="dn-burst-date-title"><?php echo esc_html( $range['current_label'] ); ?></span>
			<span class="dn-burst-date-compare" <?php echo 'none' === $range['compare'] ? 'hidden' : ''; ?>><?php echo esc_html( 'none' === $range['compare'] ? '' : $range['compare_label'] ); ?></span>
		</button>
		<form method="get" class="dn-burst-date-popover" data-dn-date-popover hidden>
			<input type="hidden" name="page" value="dn-burst-funnel-stats" />
			<input type="hidden" name="dn_tab" value="<?php echo esc_attr( $tab ); ?>" />
			<h2><?php esc_html_e( 'Select a date range', 'dn-burst-funnel-stats' ); ?></h2>
			<div class="dn-burst-date-tabs">
				<button type="button" class="button <?php echo 'custom' !== $range['period'] ? 'is-active' : ''; ?>" data-dn-date-mode="presets"><?php esc_html_e( 'Presets', 'dn-burst-funnel-stats' ); ?></button>
				<button type="button" class="button <?php echo 'custom' === $range['period'] ? 'is-active' : ''; ?>" data-dn-date-mode="custom"><?php esc_html_e( 'Custom', 'dn-burst-funnel-stats' ); ?></button>
			</div>
			<div class="dn-burst-date-pane <?php echo 'custom' !== $range['period'] ? 'is-active' : ''; ?>" data-dn-date-pane="presets">
				<?php foreach ( $presets as $key => $label ) : ?>
					<?php if ( 'custom' === $key ) { continue; } ?>
					<label><input type="radio" name="dn_period" value="<?php echo esc_attr( $key ); ?>" <?php checked( $range['period'], $key ); ?> /> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</div>
			<div class="dn-burst-date-pane <?php echo 'custom' === $range['period'] ? 'is-active' : ''; ?>" data-dn-date-pane="custom">
				<label class="dn-burst-custom-radio"><input type="radio" name="dn_period" value="custom" <?php checked( $range['period'], 'custom' ); ?> /> <?php esc_html_e( 'Custom range', 'dn-burst-funnel-stats' ); ?></label>
				<label><?php esc_html_e( 'Start date', 'dn-burst-funnel-stats' ); ?> <input type="date" name="dn_start" value="<?php echo esc_attr( $range['custom_start'] ); ?>" /></label>
				<label><?php esc_html_e( 'End date', 'dn-burst-funnel-stats' ); ?> <input type="date" name="dn_end" value="<?php echo esc_attr( $range['custom_end'] ); ?>" /></label>
			</div>
			<fieldset class="dn-burst-compare-options">
				<legend><?php esc_html_e( 'Compare', 'dn-burst-funnel-stats' ); ?></legend>
				<label><input type="radio" name="dn_compare" value="previous_period" <?php checked( $range['compare'], 'previous_period' ); ?> /> <?php esc_html_e( 'Previous period', 'dn-burst-funnel-stats' ); ?></label>
				<label><input type="radio" name="dn_compare" value="previous_year" <?php checked( $range['compare'], 'previous_year' ); ?> /> <?php esc_html_e( 'Previous year', 'dn-burst-funnel-stats' ); ?></label>
				<label><input type="radio" name="dn_compare" value="none" <?php checked( $range['compare'], 'none' ); ?> /> <?php esc_html_e( 'None', 'dn-burst-funnel-stats' ); ?></label>
			</fieldset>
			<button type="submit" class="button button-primary" data-dn-date-update><?php esc_html_e( 'Update', 'dn-burst-funnel-stats' ); ?></button>
		</form>
	</div>
	<?php
}

function dn_bfs_dash_render_data_status() {
	$labels = dn_bfs_dash_status_labels();
	?>
	<div class="dn-burst-data-status" data-dn-status-panel>
		<span class="dn-burst-data-status-text">
			<span class="dn-burst-data-status-item"><?php esc_html_e( 'Updated', 'dn-burst-funnel-stats' ); ?> <strong data-dn-last-update><?php echo esc_html( $labels['last'] ); ?></strong></span>
			<span class="dn-burst-data-status-sep" aria-hidden="true">&middot;</span>
			<span class="dn-burst-data-status-item"><?php esc_html_e( 'Next', 'dn-burst-funnel-stats' ); ?> <strong data-dn-next-update><?php echo esc_html( $labels['next'] ); ?></strong></span>
		</span>
		<button type="button" class="button button-small dn-burst-data-status-button" data-dn-update-now><?php esc_html_e( 'Update now', 'dn-burst-funnel-stats' ); ?></button>
		<span class="dn-burst-status-message" data-dn-status-message aria-live="polite"></span>
	</div>
	<?php
}

function dn_bfs_dash_render_online_badge() {
	$realtime = dn_bfs_report_realtime();
	?>
	<div class="dn-burst-online-wrap">
		<button type="button" class="dn-burst-online" data-dn-online aria-expanded="false">
			<span class="dn-burst-online-dot" aria-hidden="true"></span>
			<strong data-dn-online-count><?php echo esc_html( number_format_i18n( $realtime['online'] ) ); ?></strong>
			<span><?php esc_html_e( 'online', 'dn-burst-funnel-stats' ); ?></span>
		</button>
		<div class="dn-burst-online-popover" data-dn-online-popover hidden></div>
	</div>
	<?php
}

/**
 * Customize-cards controls. They live in the page toolbar and are only shown
 * on the Overview tab (admin.js toggles them on tab switches).
 */
function dn_bfs_dash_render_cards_toolbar( $tab ) {
	?>
	<div class="dn-burst-cards-toolbar" data-dn-cards-toolbar<?php echo 'overview' === $tab ? '' : ' hidden'; ?>>
		<button type="button" class="button button-small" data-dn-cards-edit><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> <?php esc_html_e( 'Customize cards', 'dn-burst-funnel-stats' ); ?></button>
		<button type="button" class="button button-small button-primary" data-dn-cards-save hidden><?php esc_html_e( 'Save cards', 'dn-burst-funnel-stats' ); ?></button>
		<button type="button" class="button button-small" data-dn-cards-cancel hidden><?php esc_html_e( 'Cancel', 'dn-burst-funnel-stats' ); ?></button>
		<button type="button" class="button-link" data-dn-cards-reset hidden><?php esc_html_e( 'Reset to default', 'dn-burst-funnel-stats' ); ?></button>
	</div>
	<?php
}

function dn_bfs_dash_render_filter_bar( $filters ) {
	$labels = dn_bfs_dash_dimension_labels();
	?>
	<div class="dn-burst-filter-bar" data-dn-filter-bar>
		<span class="screen-reader-text"><?php esc_html_e( 'Filters:', 'dn-burst-funnel-stats' ); ?></span>
		<span class="dn-burst-filter-chips" data-dn-filter-chips>
			<?php foreach ( $filters as $dimension => $value ) : ?>
				<span class="dn-burst-chip" data-dn-filter-dim="<?php echo esc_attr( $dimension ); ?>">
					<span><?php echo esc_html( $labels[ $dimension ] . ': ' . $value ); ?></span>
					<button type="button" class="dn-burst-chip-remove" data-dn-filter-remove aria-label="<?php esc_attr_e( 'Remove filter', 'dn-burst-funnel-stats' ); ?>">&times;</button>
				</span>
			<?php endforeach; ?>
		</span>
		<button type="button" class="button button-small dn-burst-filter-add" data-dn-filter-toggle><span class="dashicons dashicons-filter" aria-hidden="true"></span> <?php esc_html_e( 'Add filter', 'dn-burst-funnel-stats' ); ?></button>
		<form class="dn-burst-filter-popover" data-dn-filter-form hidden>
			<label>
				<span><?php esc_html_e( 'Dimension', 'dn-burst-funnel-stats' ); ?></span>
				<select name="dimension" data-dn-filter-dimension>
					<?php foreach ( dn_bfs_filter_dimensions() as $dimension ) : ?>
						<option value="<?php echo esc_attr( $dimension ); ?>"><?php echo esc_html( $labels[ $dimension ] ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Value', 'dn-burst-funnel-stats' ); ?></span>
				<input type="text" name="value" list="dn-filter-values" autocomplete="off" data-dn-filter-value />
			</label>
			<datalist id="dn-filter-values"></datalist>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'dn-burst-funnel-stats' ); ?></button>
		</form>
	</div>
	<?php
}

function dn_bfs_dash_overview_html( $range, $filters ) {
	$summary = dn_bfs_report_summary( $range, $filters );

	if ( is_wp_error( $summary ) ) {
		return dn_bfs_dash_notice( $summary->get_error_message() );
	}

	$cards         = dn_bfs_dashboard_cards( $summary );
	$visible       = dn_bfs_get_user_cards( get_current_user_id() );
	$order         = array_merge( $visible, array_values( array_diff( dn_bfs_dashboard_card_keys(), $visible ) ) );
	$compare_label = 'none' === $range['compare'] ? '' : $range['compare_label'];
	$charts        = dn_bfs_dashboard_charts( $range, $filters );

	ob_start();

	if ( empty( $summary['traffic_available'] ) ) {
		echo wp_kses_post( dn_bfs_dash_traffic_note() );
	}
	?>
	<div class="dn-burst-grid" data-dn-cards>
		<?php
		foreach ( $order as $key ) {
			dn_bfs_dash_render_card( $key, $cards[ $key ], $compare_label, ! in_array( $key, $visible, true ) );
		}
		?>
	</div>
	<?php
	if ( $summary['estimated'] ) {
		echo wp_kses_post( dn_bfs_dash_estimate_note() );
	}
	?>
	<?php if ( is_wp_error( $charts ) ) : ?>
		<?php echo wp_kses_post( dn_bfs_dash_notice( $charts->get_error_message() ) ); ?>
	<?php else : ?>
		<div class="dn-burst-chart-grid">
			<?php dn_bfs_dash_render_chart_panel( __( 'Sales / Orders', 'dn-burst-funnel-stats' ), 'sales', $charts['sales'] ); ?>
			<?php dn_bfs_dash_render_chart_panel( __( 'Funnel', 'dn-burst-funnel-stats' ), 'funnel', $charts['funnel'] ); ?>
			<?php dn_bfs_dash_render_chart_panel( __( 'Conversion Rate', 'dn-burst-funnel-stats' ), 'conversion', $charts['conversion'] ); ?>
			<?php dn_bfs_dash_render_chart_panel( __( 'Top Campaigns by Sales', 'dn-burst-funnel-stats' ), 'top-sales', $charts['top'] ); ?>
		</div>
	<?php endif; ?>
	<?php
	return ob_get_clean();
}

function dn_bfs_dash_kses_money( $html ) {
	$allowed        = wp_kses_allowed_html( 'post' );
	$allowed['bdi'] = array();

	return wp_kses( (string) $html, $allowed );
}

function dn_bfs_dash_brand_breakdown( $range, $filters, $orderby, $order, $limit, $offset ) {
	$page_size = max( 1, min( 500, (int) apply_filters( 'dn_bfs_brand_page_size', 500 ) ) );
	$product_rows = array();
	$estimated    = false;
	$traffic      = true;
	$page_offset  = 0;

	do {
		$products = dn_bfs_report_breakdown( $range, 'product', $filters, 'revenue', 'desc', $page_size, $page_offset );

		if ( is_wp_error( $products ) ) {
			return $products;
		}

		$product_rows = array_merge( $product_rows, $products['rows'] );
		$estimated    = $products['estimated'];
		$traffic      = $products['traffic_available'];
		$page_offset += $page_size;
	} while ( $page_offset < (int) $products['total'] );

	$taxonomies = array_values( array_filter( array( 'product_brand', 'pa_brand' ), 'taxonomy_exists' ) );
	$ids        = array_values( array_unique( array_filter( array_map( function ( $row ) {
		return (int) $row['dim_value'];
	}, $product_rows ) ) ) );
	$names      = array();

	if ( $taxonomies && $ids ) {
		$terms = wp_get_object_terms( $ids, $taxonomies, array( 'fields' => 'all_with_object_id' ) );

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( ! isset( $names[ (int) $term->object_id ] ) ) {
					$names[ (int) $term->object_id ] = (string) $term->name;
				}
			}
		}
	}

	$brands = array();

	foreach ( $product_rows as $row ) {
		$name = isset( $names[ (int) $row['dim_value'] ] ) ? $names[ (int) $row['dim_value'] ] : __( 'Unassigned', 'dn-burst-funnel-stats' );

		$brands[ $name ] = isset( $brands[ $name ] ) ? dn_bfs_add_metrics( $brands[ $name ], dn_bfs_normalize_metrics( $row ) ) : dn_bfs_normalize_metrics( $row );
	}

	$rows = array();

	foreach ( $brands as $name => $metrics ) {
		$rows[] = array_merge(
			array(
				'dim_value' => (string) $name,
				'label'     => (string) $name,
			),
			dn_bfs_derive_metrics( $metrics )
		);
	}

	$rows = dn_bfs_sort_report_rows( $rows, dn_bfs_report_breakdown_orderby( '' !== $orderby ? $orderby : 'revenue', 'product', $traffic ), $order );

	return array(
		'rows'              => array_slice( $rows, $offset, $limit ),
		'total'             => count( $rows ),
		'estimated'         => $estimated,
		'traffic_available' => $traffic,
	);
}

/**
 * Breakdown rows for a dashboard table. With $exclude_empty the row without a
 * dimension value is dropped before paginating, so rows, total and pages only
 * count rows that have a value. The report API itself is unchanged.
 */
function dn_bfs_dash_breakdown_rows( $range, $dimension, $filters, $orderby, $order, $limit, $offset, $exclude_empty = false ) {
	if ( ! $exclude_empty ) {
		return dn_bfs_report_breakdown( $range, $dimension, $filters, $orderby, $order, $limit, $offset );
	}

	$page_size   = 500;
	$rows        = array();
	$estimated   = false;
	$traffic     = true;
	$page_offset = 0;

	do {
		$result = dn_bfs_report_breakdown( $range, $dimension, $filters, $orderby, $order, $page_size, $page_offset );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $result['rows'] as $row ) {
			if ( '' !== trim( (string) $row['dim_value'] ) ) {
				$rows[] = $row;
			}
		}

		$estimated    = $result['estimated'];
		$traffic      = $result['traffic_available'];
		$page_offset += $page_size;
	} while ( $page_offset < (int) $result['total'] );

	return array(
		'rows'              => array_slice( $rows, max( 0, (int) $offset ), max( 1, (int) $limit ) ),
		'total'             => count( $rows ),
		'estimated'         => $estimated,
		'traffic_available' => $traffic,
	);
}

function dn_bfs_dash_table_html( $tab, $dimension, $range, $filters, $args = array() ) {
	$tabs     = dn_bfs_dash_tabs();
	$tab_def  = isset( $tabs[ $tab ] ) ? $tabs[ $tab ] : array();
	$columns  = dn_bfs_dash_columns( $dimension );
	$orderby  = isset( $args['orderby'] ) && in_array( $args['orderby'], $columns, true ) ? $args['orderby'] : $columns[0];
	$order    = isset( $args['order'] ) && 'asc' === $args['order'] ? 'asc' : 'desc';
	$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
	$per_page = 25;
	$offset   = ( $page - 1 ) * $per_page;
	$empty    = ! empty( $tab_def['empty'] ) ? $tab_def['empty'] : __( 'No data is available for this period.', 'dn-burst-funnel-stats' );
	$result   = 'brand' === $dimension
		? dn_bfs_dash_brand_breakdown( $range, $filters, $orderby, $order, $per_page, $offset )
		: dn_bfs_dash_breakdown_rows( $range, $dimension, $filters, $orderby, $order, $per_page, $offset, ! empty( $tab_def['exclude_empty'] ) );

	if ( is_wp_error( $result ) ) {
		return dn_bfs_dash_notice( $result->get_error_message() );
	}

	$traffic = ! empty( $result['traffic_available'] );
	$muted   = $traffic ? array() : dn_bfs_traffic_metric_names();

	if ( ! $traffic ) {
		// Same fallback as the report: traffic columns are all 0, rows are sorted by orders.
		$orderby = dn_bfs_report_breakdown_orderby( $orderby, $dimension, false );
	}

	$pages      = max( 1, (int) ceil( $result['total'] / $per_page ) );
	$drillable  = in_array( $dimension, dn_bfs_filter_dimensions(), true );
	$labels     = dn_bfs_dash_metric_labels();
	$dim_labels = dn_bfs_dash_dimension_labels();

	ob_start();
	?>
	<div class="dn-burst-table-wrap" data-dn-table data-tab="<?php echo esc_attr( $tab ); ?>" data-dimension="<?php echo esc_attr( $dimension ); ?>" data-orderby="<?php echo esc_attr( $orderby ); ?>" data-order="<?php echo esc_attr( $order ); ?>" data-page="<?php echo esc_attr( $page ); ?>">
		<?php
		if ( ! $traffic ) {
			echo wp_kses_post( dn_bfs_dash_traffic_note() );
		}

		if ( ! empty( $result['estimated'] ) ) {
			echo wp_kses_post( dn_bfs_dash_estimate_note() );
		}
		?>
		<table class="widefat striped dn-burst-data-table">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html( $dim_labels[ $dimension ] ); ?></th>
					<?php foreach ( $columns as $column ) : ?>
						<?php $next = ( $column === $orderby && 'desc' === $order ) ? 'asc' : 'desc'; ?>
						<th scope="col" class="<?php echo $column === $orderby ? esc_attr( 'is-sorted is-' . $order ) : ''; ?>">
							<a href="#" data-dn-sort="<?php echo esc_attr( $column ); ?>" data-dn-order="<?php echo esc_attr( $next ); ?>"><?php echo esc_html( $labels[ $column ] ); ?></a>
						</th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $result['rows'] ) ) : ?>
					<tr class="dn-burst-empty-row"><td colspan="<?php echo esc_attr( count( $columns ) + 1 ); ?>"><?php echo esc_html( $empty ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $result['rows'] as $row ) : ?>
						<?php
						$value     = (string) $row['dim_value'];
						$can_drill = $drillable && '' !== $value;
						?>
						<tr<?php if ( $can_drill ) : ?> class="is-drillable" data-dn-drill-dimension="<?php echo esc_attr( $dimension ); ?>" data-dn-drill-value="<?php echo esc_attr( $value ); ?>" tabindex="0"<?php endif; ?>>
							<td><?php echo esc_html( dn_bfs_dash_value_label( $dimension, $row ) ); ?></td>
							<?php foreach ( $columns as $column ) : ?>
								<?php if ( in_array( $column, $muted, true ) ) : ?>
									<td class="is-unavailable">—</td>
								<?php else : ?>
									<td><?php echo dn_bfs_dash_kses_money( dn_bfs_dash_format_cell( $column, isset( $row[ $column ] ) ? $row[ $column ] : 0 ) ); ?></td>
								<?php endif; ?>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php /* translators: %d: number of rows. */ ?>
					<span class="displaying-num"><?php echo esc_html( sprintf( _n( '%d item', '%d items', $result['total'], 'dn-burst-funnel-stats' ), $result['total'] ) ); ?></span>
					<?php if ( $page > 1 ) : ?>
						<a class="button" href="#" data-dn-page="<?php echo esc_attr( $page - 1 ); ?>"><?php esc_html_e( 'Previous', 'dn-burst-funnel-stats' ); ?></a>
					<?php endif; ?>
					<span class="paging-input"><?php echo esc_html( $page . ' / ' . $pages ); ?></span>
					<?php if ( $page < $pages ) : ?>
						<a class="button" href="#" data-dn-page="<?php echo esc_attr( $page + 1 ); ?>"><?php esc_html_e( 'Next', 'dn-burst-funnel-stats' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

function dn_bfs_dash_breakdown_html( $tab, $range, $filters, $args ) {
	$tabs       = dn_bfs_dash_tabs();
	$dimensions = $tabs[ $tab ]['dimensions'];
	$dimension  = isset( $args['dimension'] ) && in_array( $args['dimension'], $dimensions, true ) ? $args['dimension'] : $dimensions[0];
	$labels     = dn_bfs_dash_dimension_labels();

	ob_start();
	?>
	<div class="dn-burst-breakdown">
		<div class="dn-burst-panel dn-burst-panel-toolbar">
			<div><h2><?php echo esc_html( $tabs[ $tab ]['label'] ); ?></h2></div>
			<?php if ( count( $dimensions ) > 1 ) : ?>
				<fieldset class="dn-burst-radio-group" data-dn-dimension>
					<legend class="screen-reader-text"><?php esc_html_e( 'Group by', 'dn-burst-funnel-stats' ); ?></legend>
					<?php foreach ( $dimensions as $option ) : ?>
						<label><input type="radio" name="dn_dimension" value="<?php echo esc_attr( $option ); ?>" <?php checked( $option, $dimension ); ?> /> <?php echo esc_html( $labels[ $option ] ); ?></label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
		</div>
		<div data-dn-table-region>
			<?php echo dn_bfs_dash_table_html( $tab, $dimension, $range, $filters, $args ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

function dn_bfs_dash_drilldown_html( $range, $filters, $dimension, $value ) {
	$clean = in_array( $dimension, dn_bfs_filter_dimensions(), true ) ? dn_bfs_sanitize_filters( array( $dimension => $value ) ) : array();

	if ( ! isset( $clean[ $dimension ] ) ) {
		return dn_bfs_dash_notice( __( 'This row cannot be explored.', 'dn-burst-funnel-stats' ) );
	}

	$value                 = $clean[ $dimension ];
	$filters[ $dimension ] = $value;
	$filters               = dn_bfs_sanitize_filters( $filters );
	$summary               = dn_bfs_report_summary( $range, $filters );
	$series                = is_wp_error( $summary ) ? $summary : dn_bfs_report_timeseries( $range, array( 'visitors', 'orders' ), $filters );
	$funnel                = is_wp_error( $series ) ? $series : dn_bfs_report_funnel( $range, $filters );

	if ( is_wp_error( $funnel ) ) {
		return dn_bfs_dash_notice( $funnel->get_error_message() );
	}

	$labels   = dn_bfs_dash_dimension_labels();
	$channels = dn_bfs_dash_channel_labels();
	$display  = 'channel' === $dimension && isset( $channels[ $value ] ) ? $channels[ $value ] : $value;
	$cur      = $summary['current'];
	$traffic  = ! empty( $summary['current_traffic_available'] );
	$stats    = array(
		'visitors'        => array( __( 'Visitors', 'dn-burst-funnel-stats' ), esc_html( dn_bfs_dash_number( $cur['visitors'] ) ) ),
		'sessions'        => array( __( 'Sessions', 'dn-burst-funnel-stats' ), esc_html( dn_bfs_dash_number( $cur['sessions'] ) ) ),
		'orders'          => array( __( 'Orders', 'dn-burst-funnel-stats' ), esc_html( dn_bfs_dash_number( $cur['orders'] ) ) ),
		'revenue'         => array( __( 'Sales', 'dn-burst-funnel-stats' ), dn_bfs_dash_money( $cur['revenue'] ) ),
		'conversion_rate' => array( __( 'Conversion', 'dn-burst-funnel-stats' ), esc_html( dn_bfs_dash_percent( $cur['conversion_rate'], 2 ) ) ),
	);
	$visitors = array(
		'label'  => __( 'Visitors', 'dn-burst-funnel-stats' ),
		'values' => $series['series']['visitors'],
		'color'  => '#2271b1',
		'format' => 'integer',
		'axis'   => 'left',
	);
	$orders   = array(
		'label'  => __( 'Orders', 'dn-burst-funnel-stats' ),
		'values' => $series['series']['orders'],
		'color'  => '#7f54b3',
		'format' => 'integer',
		'axis'   => 'right',
	);
	$trend    = array(
		'labels' => array_map( 'dn_bfs_dash_date_label', $series['labels'] ),
		'format' => 'integer',
		// Without traffic data the visitors line would be a flat 0.
		'series' => ! empty( $series['traffic_available'] ) ? array( $visitors, $orders ) : array( $orders ),
	);
	// Traffic stats lose their change when either period lacks raw data, and show "—" when the current one does.
	$withheld = empty( $summary['traffic_available'] ) ? array_intersect( array_keys( $stats ), dn_bfs_traffic_metric_names() ) : array();

	foreach ( $traffic ? array() : $withheld as $metric ) {
		$stats[ $metric ][1] = '—';
	}

	ob_start();
	?>
	<div class="dn-burst-drawer-header">
		<h2><?php echo esc_html( $labels[ $dimension ] . ': ' . $display ); ?></h2>
		<button type="button" class="button-link dn-burst-drawer-close" data-dn-drawer-close aria-label="<?php esc_attr_e( 'Close', 'dn-burst-funnel-stats' ); ?>">&times;</button>
	</div>
	<p class="dn-burst-drawer-range"><?php echo esc_html( $range['current_label'] . ' (' . $range['current_range_label'] . ')' ); ?></p>
	<?php
	if ( empty( $summary['traffic_available'] ) ) {
		echo wp_kses_post( dn_bfs_dash_traffic_note() );
	}

	if ( $summary['estimated'] ) {
		echo wp_kses_post( dn_bfs_dash_estimate_note() );
	}
	?>
	<div class="dn-burst-drawer-stats">
		<?php foreach ( $stats as $metric => $stat ) : ?>
			<div class="dn-burst-drawer-stat<?php echo ! $traffic && in_array( $metric, $withheld, true ) ? ' is-unavailable' : ''; ?>">
				<span><?php echo esc_html( $stat[0] ); ?></span>
				<strong><?php echo dn_bfs_dash_kses_money( $stat[1] ); ?></strong>
				<?php $change = in_array( $metric, $withheld, true ) ? '' : dn_bfs_dash_change( $summary, $metric ); ?>
				<?php if ( '' !== $change ) : ?>
					<em class="<?php echo 0 === strpos( $change, '-' ) ? 'is-down' : 'is-up'; ?>"><?php echo esc_html( $change ); ?></em>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php dn_bfs_dash_render_chart_panel( __( 'Visitors / Orders', 'dn-burst-funnel-stats' ), 'trend', $trend ); ?>
	<?php dn_bfs_dash_render_chart_panel( __( 'Funnel', 'dn-burst-funnel-stats' ), 'funnel', dn_bfs_dashboard_funnel_payload( $funnel ) ); ?>
	<p>
		<button type="button" class="button button-primary" data-dn-apply-filter data-dimension="<?php echo esc_attr( $dimension ); ?>" data-value="<?php echo esc_attr( $value ); ?>"><?php esc_html_e( 'Apply as filter', 'dn-burst-funnel-stats' ); ?></button>
	</p>
	<?php
	return ob_get_clean();
}

function dn_bfs_dash_tab_html( $tab, $range, $filters, $args = array() ) {
	$tab = dn_bfs_dash_sanitize_tab( $tab );

	return 'overview' === $tab ? dn_bfs_dash_overview_html( $range, $filters ) : dn_bfs_dash_breakdown_html( $tab, $range, $filters, $args );
}

function dn_bfs_dash_render_page() {
	if ( ! dn_bfs_admin_permission() ) {
		return;
	}

	$params  = dn_bfs_params_from_query( wp_unslash( $_GET ) );
	$tab     = dn_bfs_dash_sanitize_tab( $params['tab'] );
	$request = dn_bfs_dash_request( $params );
	$notice  = '';

	if ( is_wp_error( $request ) ) {
		$notice  = $request->get_error_message();
		$request = dn_bfs_dash_request( array() );
	}

	list( $range, $filters ) = $request;
	$tabs                    = dn_bfs_dash_tabs();
	?>
	<div class="wrap dn-burst-wrap dn-burst-funnel-stats" data-dn-dashboard>
		<header class="dn-burst-topbar">
			<h1 class="dn-burst-topbar-title"><?php echo esc_html( $tabs[ $tab ]['label'] ); ?></h1>
			<div class="dn-burst-topbar-actions"><?php dn_bfs_dash_render_online_badge(); ?></div>
		</header>
		<?php
		if ( '' !== $notice ) {
			echo wp_kses_post( dn_bfs_dash_notice( $notice ) );
		}
		?>
		<div class="dn-burst-dashboard-toolbar">
			<div class="dn-burst-dashboard-toolbar-section dn-burst-toolbar-start">
				<span class="screen-reader-text"><?php esc_html_e( 'Date range:', 'dn-burst-funnel-stats' ); ?></span>
				<?php dn_bfs_dash_render_date_picker( $range, $tab ); ?>
				<?php dn_bfs_dash_render_filter_bar( $filters ); ?>
			</div>
			<div class="dn-burst-dashboard-toolbar-section dn-burst-toolbar-end">
				<span class="screen-reader-text"><?php esc_html_e( 'Data status:', 'dn-burst-funnel-stats' ); ?></span>
				<?php dn_bfs_dash_render_data_status(); ?>
				<?php dn_bfs_dash_render_cards_toolbar( $tab ); ?>
			</div>
		</div>
		<div class="dn-burst-dashboard-content">
			<nav class="nav-tab-wrapper dn-burst-tabs" aria-label="<?php echo esc_attr__( 'Dashboard tabs', 'dn-burst-funnel-stats' ); ?>">
				<?php foreach ( $tabs as $key => $definition ) : ?>
					<a href="<?php echo esc_url( dn_bfs_dash_url( $key, $range, $filters ) ); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>" data-dn-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $definition['label'] ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="dn-burst-tab-content" data-dn-tab-content aria-live="polite">
				<?php echo dn_bfs_dash_tab_html( $tab, $range, $filters ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
			</div>
		</div>
	</div>
	<?php
}
