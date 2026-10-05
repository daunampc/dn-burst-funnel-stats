<?php
/**
 * Dashboard cards and chart payloads built from the report API.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_dashboard_card_keys() {
	return array( 'visitors', 'pageviews', 'sessions', 'new_returning', 'bounce_rate', 'avg_duration', 'pages_per_session', 'product_views', 'atc', 'checkouts', 'orders_aov', 'items_aoi', 'conversion_rate', 'sales_tip', 'paid_balance' );
}

/**
 * Cards built from traffic metrics: shown as "—" without a trend when the
 * report has no raw tracking data for the range (orders-only results).
 */
function dn_bfs_dashboard_traffic_cards() {
	return array( 'visitors', 'pageviews', 'sessions', 'new_returning', 'bounce_rate', 'avg_duration', 'pages_per_session', 'product_views', 'atc', 'checkouts', 'conversion_rate' );
}

/**
 * Why traffic metrics are missing, with the raw-data retention in days.
 */
function dn_bfs_dashboard_traffic_message() {
	$settings = dn_bfs_get_tracking_settings();
	$days     = (int) $settings['raw_retention_days'];

	/* translators: %d: number of days raw tracking data is kept. */
	return sprintf( _n( 'Traffic metrics need raw tracking data, which is kept for %d day. Order and revenue figures cover the whole range.', 'Traffic metrics need raw tracking data, which is kept for %d days. Order and revenue figures cover the whole range.', $days, 'dn-burst-funnel-stats' ), $days );
}

function dn_bfs_dashboard_traffic_empty_message() {
	return __( 'Traffic metrics are not available for this range.', 'dn-burst-funnel-stats' );
}

/**
 * Cards visible by default, in display order (mirrors the classic dashboard).
 */
function dn_bfs_dashboard_default_cards() {
	return array( 'visitors', 'product_views', 'pageviews', 'atc', 'checkouts', 'orders_aov', 'items_aoi', 'conversion_rate', 'new_returning', 'sales_tip', 'sessions', 'paid_balance' );
}

function dn_bfs_get_user_cards( $user_id ) {
	$cards = get_user_meta( (int) $user_id, 'dnbfs_cards', true );

	return is_array( $cards ) ? array_values( array_intersect( $cards, dn_bfs_dashboard_card_keys() ) ) : dn_bfs_dashboard_default_cards();
}

function dn_bfs_save_user_cards( $user_id, $cards ) {
	if ( null === $cards ) {
		delete_user_meta( (int) $user_id, 'dnbfs_cards' );

		return dn_bfs_get_user_cards( $user_id );
	}

	if ( ! is_array( $cards ) ) {
		return dn_bfs_request_error( 'invalid_card', __( 'Cards must be a list.', 'dn-burst-funnel-stats' ) );
	}

	foreach ( $cards as $card ) {
		if ( ! is_string( $card ) || ! in_array( $card, dn_bfs_dashboard_card_keys(), true ) ) {
			return dn_bfs_request_error( 'invalid_card', __( 'Unknown dashboard card.', 'dn-burst-funnel-stats' ) );
		}
	}

	update_user_meta( (int) $user_id, 'dnbfs_cards', array_values( array_unique( $cards ) ) );

	return dn_bfs_get_user_cards( $user_id );
}

function dn_bfs_dash_money( $value ) {
	return function_exists( 'wc_price' ) ? wc_price( (float) $value ) : esc_html( number_format_i18n( (float) $value, 2 ) );
}

function dn_bfs_dash_number( $value, $decimals = 0 ) {
	return number_format_i18n( (float) $value, $decimals );
}

function dn_bfs_dash_percent( $value, $decimals = 1 ) {
	return number_format_i18n( (float) $value, $decimals ) . '%';
}

function dn_bfs_dash_duration( $seconds ) {
	$total   = max( 0, (int) round( (float) $seconds ) );
	$minutes = (int) floor( $total / 60 );
	$rest    = $total % 60;

	return $minutes > 0 ? sprintf( '%dm %02ds', $minutes, $rest ) : sprintf( '%ds', $rest );
}

function dn_bfs_dash_change( $summary, $metric ) {
	if ( null === $summary['previous'] || ! isset( $summary['change'][ $metric ] ) ) {
		return '';
	}

	$change = (float) $summary['change'][ $metric ];

	return ( $change > 0 ? '+' : '' ) . number_format_i18n( $change, 1 ) . '%';
}

function dn_bfs_dash_date_label( $date ) {
	return wp_date( 'M j', ( new DateTimeImmutable( $date . ' 12:00:00', wp_timezone() ) )->getTimestamp() );
}

/**
 * Per-card Dashicon (without the "dashicons-" prefix) and accent color.
 */
function dn_bfs_dashboard_card_styles() {
	return array(
		'visitors'          => array( 'groups', '#2563eb' ),
		'product_views'     => array( 'visibility', '#7c3aed' ),
		'pageviews'         => array( 'media-document', '#0891b2' ),
		'atc'               => array( 'cart', '#ea580c' ),
		'checkouts'         => array( 'yes-alt', '#d97706' ),
		'orders_aov'        => array( 'archive', '#4f46e5' ),
		'items_aoi'         => array( 'screenoptions', '#0d9488' ),
		'conversion_rate'   => array( 'chart-line', '#16a34a' ),
		'new_returning'     => array( 'admin-users', '#db2777' ),
		'sales_tip'         => array( 'money-alt', '#059669' ),
		'sessions'          => array( 'clock', '#475569' ),
		'paid_balance'      => array( 'bank', '#0e7490' ),
		'bounce_rate'       => array( 'undo', '#e11d48' ),
		'avg_duration'      => array( 'backup', '#9333ea' ),
		'pages_per_session' => array( 'admin-page', '#64748b' ),
	);
}

function dn_bfs_dashboard_cards( $summary ) {
	$cur      = $summary['current'];
	$prev     = $summary['previous'];
	$has_prev = null !== $prev;
	$styles   = dn_bfs_dashboard_card_styles();
	$was      = function ( $metric ) use ( $prev ) {
		return null === $prev ? 0 : $prev[ $metric ];
	};
	$share    = function ( $value ) use ( $cur ) {
		return $cur['visitors'] > 0 ? dn_bfs_dash_percent( $value / $cur['visitors'] * 100 ) : '0%';
	};
	$trend    = function ( $metric ) use ( $summary ) {
		if ( null === $summary['previous'] || ! isset( $summary['change'][ $metric ] ) ) {
			return '';
		}

		$change = round( (float) $summary['change'][ $metric ], 1 );

		return $change > 0 ? 'up' : ( $change < 0 ? 'down' : 'flat' );
	};
	$card     = function ( $key, $metric, $title, $main, $secondary, $compare, $help ) use ( $styles, $summary, $trend ) {
		return array(
			'title'           => $title,
			'icon'            => $styles[ $key ][0],
			'accent'          => $styles[ $key ][1],
			'main'            => $main,
			'secondary'       => $secondary,
			'compare'         => $compare,
			'change'          => dn_bfs_dash_change( $summary, $metric ),
			'trend'           => $trend( $metric ),
			'lower_is_better' => 'bounce_rate' === $metric,
			'help'            => $help,
		);
	};

	$cards = array(
		'visitors'          => $card( 'visitors', 'visitors', __( 'Visitors', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['visitors'] ), '', $has_prev ? dn_bfs_dash_number( $was( 'visitors' ) ) : '', __( 'Unique visitors, identified by a first-party browser cookie.', 'dn-burst-funnel-stats' ) ),
		'pageviews'         => $card( 'pageviews', 'pageviews', __( 'Pageviews', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['pageviews'] ), '', $has_prev ? dn_bfs_dash_number( $was( 'pageviews' ) ) : '', __( 'Pages viewed. Reloads of the same page within a few seconds count once.', 'dn-burst-funnel-stats' ) ),
		'sessions'          => $card( 'sessions', 'sessions', __( 'Sessions', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['sessions'] ), '', $has_prev ? dn_bfs_dash_number( $was( 'sessions' ) ) : '', __( 'Visits. A session ends after 30 minutes without activity or at midnight.', 'dn-burst-funnel-stats' ) ),
		/* translators: %s: number of returning visitors. */
		'new_returning'     => $card( 'new_returning', 'new_visitors', __( 'New / returning', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['new_visitors'] ), sprintf( __( 'Returning: %s', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['returning_visitors'] ) ), $has_prev ? dn_bfs_dash_number( $was( 'new_visitors' ) ) : '', __( 'New visitors had no earlier visit; returning visitors came back.', 'dn-burst-funnel-stats' ) ),
		'bounce_rate'       => $card( 'bounce_rate', 'bounce_rate', __( 'Bounce rate', 'dn-burst-funnel-stats' ), dn_bfs_dash_percent( $cur['bounce_rate'] ), '', $has_prev ? dn_bfs_dash_percent( $was( 'bounce_rate' ) ) : '', __( 'Sessions with a single pageview.', 'dn-burst-funnel-stats' ) ),
		'avg_duration'      => $card( 'avg_duration', 'avg_duration', __( 'Avg. session time', 'dn-burst-funnel-stats' ), dn_bfs_dash_duration( $cur['avg_duration'] ), '', $has_prev ? dn_bfs_dash_duration( $was( 'avg_duration' ) ) : '', __( 'Average time the tab was visible per session.', 'dn-burst-funnel-stats' ) ),
		'pages_per_session' => $card( 'pages_per_session', 'pages_per_session', __( 'Pages / session', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['pages_per_session'], 2 ), '', $has_prev ? dn_bfs_dash_number( $was( 'pages_per_session' ), 2 ) : '', __( 'Average pageviews per session.', 'dn-burst-funnel-stats' ) ),
		'product_views'     => $card( 'product_views', 'product_views', __( 'Product views', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['product_views'] ), $share( $cur['product_views'] ), $has_prev ? dn_bfs_dash_number( $was( 'product_views' ) ) : '', __( 'Each visitor counts once per product within the anti-spam window.', 'dn-burst-funnel-stats' ) ),
		/* translators: %s: number of cart events. */
		'atc'               => $card( 'atc', 'atc', __( 'Add to cart', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['atc'] ), sprintf( __( 'Cart: %s', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['carts'] ) ), $has_prev ? dn_bfs_dash_number( $was( 'atc' ) ) : '', __( 'Add to cart and Cart are recorded together, so they are always equal.', 'dn-burst-funnel-stats' ) ),
		'checkouts'         => $card( 'checkouts', 'checkouts', __( 'Checkout', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['checkouts'] ), $share( $cur['checkouts'] ), $has_prev ? dn_bfs_dash_number( $was( 'checkouts' ) ) : '', __( 'Sessions that reached the checkout page.', 'dn-burst-funnel-stats' ) ),
		/* translators: %s: average order value. */
		'orders_aov'        => $card( 'orders_aov', 'orders', __( 'Orders / AOV', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['orders'] ), sprintf( __( 'AOV: %s', 'dn-burst-funnel-stats' ), dn_bfs_dash_money( $cur['aov'] ) ), $has_prev ? dn_bfs_dash_number( $was( 'orders' ) ) : '', __( 'By default, orders exclude cancelled, failed, draft and fully refunded orders. AOV is net of refunds.', 'dn-burst-funnel-stats' ) ),
		/* translators: %s: average items per order. */
		'items_aoi'         => $card( 'items_aoi', 'items', __( 'Items / AOI', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['items'] ), sprintf( __( 'AOI: %s', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['aoi'], 2 ) ), $has_prev ? dn_bfs_dash_number( $was( 'items' ) ) : '', __( 'Items sold, net of refunded quantities.', 'dn-burst-funnel-stats' ) ),
		'conversion_rate'   => $card( 'conversion_rate', 'conversion_rate', __( 'Conversion rate', 'dn-burst-funnel-stats' ), dn_bfs_dash_percent( $cur['conversion_rate'], 2 ), '', $has_prev ? dn_bfs_dash_percent( $was( 'conversion_rate' ), 2 ) : '', __( 'Orders divided by visitors.', 'dn-burst-funnel-stats' ) ),
		/* translators: %s: tip total. */
		'sales_tip'         => $card( 'sales_tip', 'revenue', __( 'Sales / tip', 'dn-burst-funnel-stats' ), dn_bfs_dash_money( $cur['revenue'] ), sprintf( __( 'Tip: %s', 'dn-burst-funnel-stats' ), dn_bfs_dash_money( $cur['tips'] ) ), $has_prev ? dn_bfs_dash_money( $was( 'revenue' ) ) : '', __( 'Sales are net of refunds; tips are gross fee totals.', 'dn-burst-funnel-stats' ) ),
		'paid_balance'      => $card( 'paid_balance', 'paid', __( 'Paid / balance', 'dn-burst-funnel-stats' ), dn_bfs_dash_money( $cur['paid'] ) . ' / ' . dn_bfs_dash_money( $cur['balance'] ), '', $has_prev ? dn_bfs_dash_money( $was( 'paid' ) ) : '', __( 'By default, paid = processing and completed orders, net of refunds, and balance = pending and on-hold orders.', 'dn-burst-funnel-stats' ) ),
	);

	return dn_bfs_dashboard_withhold_traffic_cards( $cards, $summary );
}

/**
 * Orders-only summaries: traffic cards show "—" (current period without raw
 * data) or lose their comparison (previous period without raw data). Both
 * drop the trend pill; order cards keep values and trends.
 */
function dn_bfs_dashboard_withhold_traffic_cards( $cards, $summary ) {
	$current  = ! isset( $summary['current_traffic_available'] ) || false !== $summary['current_traffic_available'];
	$previous = ! isset( $summary['previous_traffic_available'] ) || false !== $summary['previous_traffic_available'];

	foreach ( $cards as $key => $card ) {
		$cards[ $key ]['unavailable']         = false;
		$cards[ $key ]['compare_unavailable'] = false;
	}

	foreach ( dn_bfs_dashboard_traffic_cards() as $key ) {
		if ( ! $current ) {
			$cards[ $key ]['main']        = '—';
			$cards[ $key ]['secondary']   = '';
			$cards[ $key ]['unavailable'] = true;
		}

		if ( ! $current || ! $previous ) {
			$cards[ $key ]['compare']             = $previous ? $cards[ $key ]['compare'] : '';
			$cards[ $key ]['change']              = '';
			$cards[ $key ]['trend']               = '';
			$cards[ $key ]['compare_unavailable'] = true;
		}
	}

	return $cards;
}

/**
 * @param array $funnel dn_bfs_report_funnel() result. Without traffic data the
 *                      chart is empty and says why.
 */
function dn_bfs_dashboard_funnel_payload( $funnel ) {
	if ( isset( $funnel['traffic_available'] ) && ! $funnel['traffic_available'] ) {
		return array(
			'format' => 'integer',
			'labels' => array(),
			'values' => array(),
			'empty'  => dn_bfs_dashboard_traffic_empty_message(),
		);
	}

	$funnel = isset( $funnel['steps'] ) ? $funnel['steps'] : $funnel;
	$labels = array(
		'visitors'      => __( 'Visitors', 'dn-burst-funnel-stats' ),
		'product_views' => __( 'Product views', 'dn-burst-funnel-stats' ),
		'atc'           => __( 'Add to cart (= Cart)', 'dn-burst-funnel-stats' ),
		'checkouts'     => __( 'Checkout', 'dn-burst-funnel-stats' ),
		'orders'        => __( 'Orders', 'dn-burst-funnel-stats' ),
	);
	$steps  = array();

	foreach ( $funnel as $step ) {
		if ( isset( $labels[ $step['key'] ] ) ) {
			$steps[ $step['key'] ] = (int) $step['value'];
		}
	}

	return array(
		'format' => 'integer',
		'labels' => array_values( array_intersect_key( $labels, $steps ) ),
		'values' => array_values( $steps ),
	);
}

function dn_bfs_dashboard_charts( $range, $filters ) {
	$series = dn_bfs_report_timeseries( $range, array( 'revenue', 'orders', 'conversion_rate' ), $filters );

	if ( is_wp_error( $series ) ) {
		return $series;
	}

	$funnel = dn_bfs_report_funnel( $range, $filters );

	if ( is_wp_error( $funnel ) ) {
		return $funnel;
	}

	$top = dn_bfs_report_breakdown( $range, 'campaign', $filters, 'revenue', 'desc', 8, 0 );

	if ( is_wp_error( $top ) ) {
		return $top;
	}

	$labels     = array_map( 'dn_bfs_dash_date_label', $series['labels'] );
	$traffic    = ! empty( $series['traffic_available'] );
	$top_labels = array();
	$top_values = array();

	foreach ( $top['rows'] as $row ) {
		if ( (float) $row['revenue'] <= 0 ) {
			continue;
		}

		$top_labels[] = '' === (string) $row['dim_value'] ? __( '(none)', 'dn-burst-funnel-stats' ) : (string) $row['dim_value'];
		$top_values[] = round( (float) $row['revenue'], 2 );
	}

	return array(
		'estimated'  => ! empty( $series['estimated'] ) || ! empty( $top['estimated'] ),
		'sales'      => array(
			'labels' => $labels,
			'format' => 'money',
			'series' => array(
				array(
					'label'  => __( 'Net sales', 'dn-burst-funnel-stats' ),
					'values' => $series['series']['revenue'],
					'color'  => '#2271b1',
					'format' => 'money',
					'axis'   => 'left',
				),
				array(
					'label'  => __( 'Orders', 'dn-burst-funnel-stats' ),
					'values' => $series['series']['orders'],
					'color'  => '#7f54b3',
					'format' => 'integer',
					'axis'   => 'right',
				),
			),
		),
		'funnel'     => dn_bfs_dashboard_funnel_payload( $funnel ),
		'conversion' => $traffic ? array(
			'labels' => $labels,
			'format' => 'percent',
			'values' => $series['series']['conversion_rate'],
			'series' => array(
				array(
					'label'  => __( 'Conversion rate', 'dn-burst-funnel-stats' ),
					'values' => $series['series']['conversion_rate'],
					'color'  => '#d63638',
					'format' => 'percent',
					'axis'   => 'left',
				),
			),
		) : array(
			'labels' => $labels,
			'format' => 'percent',
			'values' => array(),
			'series' => array(),
			'empty'  => dn_bfs_dashboard_traffic_empty_message(),
		),
		'top'        => array(
			'labels' => $top_labels,
			'format' => 'money',
			'values' => $top_values,
			'series' => array(
				array(
					'label'  => __( 'Sales', 'dn-burst-funnel-stats' ),
					'values' => $top_values,
					'color'  => '#2271b1',
					'format' => 'money',
					'axis'   => 'left',
				),
			),
		),
	);
}
