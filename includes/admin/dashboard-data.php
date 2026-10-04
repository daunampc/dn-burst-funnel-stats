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

function dn_bfs_get_user_cards( $user_id ) {
	$cards = get_user_meta( (int) $user_id, 'dnbfs_cards', true );

	return is_array( $cards ) ? array_values( array_intersect( $cards, dn_bfs_dashboard_card_keys() ) ) : dn_bfs_dashboard_card_keys();
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

function dn_bfs_dashboard_cards( $summary ) {
	$cur      = $summary['current'];
	$prev     = $summary['previous'];
	$has_prev = null !== $prev;
	$was      = function ( $metric ) use ( $prev ) {
		return null === $prev ? 0 : $prev[ $metric ];
	};
	$share    = function ( $value ) use ( $cur ) {
		return $cur['visitors'] > 0 ? dn_bfs_dash_percent( $value / $cur['visitors'] * 100 ) : '0%';
	};
	$card     = function ( $title, $icon, $main, $secondary, $compare, $change, $help ) {
		return array(
			'title'     => $title,
			'icon'      => $icon,
			'main'      => $main,
			'secondary' => $secondary,
			'compare'   => $compare,
			'change'    => $change,
			'help'      => $help,
		);
	};

	return array(
		'visitors'          => $card( __( 'Visitors', 'dn-burst-funnel-stats' ), 'eye', dn_bfs_dash_number( $cur['visitors'] ), '', $has_prev ? dn_bfs_dash_number( $was( 'visitors' ) ) : '', dn_bfs_dash_change( $summary, 'visitors' ), __( 'Unique visitors, identified by a first-party browser cookie.', 'dn-burst-funnel-stats' ) ),
		'pageviews'         => $card( __( 'Pageviews', 'dn-burst-funnel-stats' ), 'layers', dn_bfs_dash_number( $cur['pageviews'] ), '', $has_prev ? dn_bfs_dash_number( $was( 'pageviews' ) ) : '', dn_bfs_dash_change( $summary, 'pageviews' ), __( 'Pages viewed. Reloads of the same page within a few seconds count once.', 'dn-burst-funnel-stats' ) ),
		'sessions'          => $card( __( 'Sessions', 'dn-burst-funnel-stats' ), 'user', dn_bfs_dash_number( $cur['sessions'] ), '', $has_prev ? dn_bfs_dash_number( $was( 'sessions' ) ) : '', dn_bfs_dash_change( $summary, 'sessions' ), __( 'Visits. A session ends after 30 minutes without activity or at midnight.', 'dn-burst-funnel-stats' ) ),
		/* translators: %s: number of returning visitors. */
		'new_returning'     => $card( __( 'New / Returning', 'dn-burst-funnel-stats' ), 'user', dn_bfs_dash_number( $cur['new_visitors'] ), sprintf( __( 'Returning: %s', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['returning_visitors'] ) ), $has_prev ? dn_bfs_dash_number( $was( 'new_visitors' ) ) : '', dn_bfs_dash_change( $summary, 'new_visitors' ), __( 'New visitors had no earlier visit; returning visitors came back.', 'dn-burst-funnel-stats' ) ),
		'bounce_rate'       => $card( __( 'Bounce Rate', 'dn-burst-funnel-stats' ), 'check', dn_bfs_dash_percent( $cur['bounce_rate'] ), '', $has_prev ? dn_bfs_dash_percent( $was( 'bounce_rate' ) ) : '', dn_bfs_dash_change( $summary, 'bounce_rate' ), __( 'Sessions with a single pageview.', 'dn-burst-funnel-stats' ) ),
		'avg_duration'      => $card( __( 'Avg. Session Time', 'dn-burst-funnel-stats' ), 'clock', dn_bfs_dash_duration( $cur['avg_duration'] ), '', $has_prev ? dn_bfs_dash_duration( $was( 'avg_duration' ) ) : '', dn_bfs_dash_change( $summary, 'avg_duration' ), __( 'Average time the tab was visible per session.', 'dn-burst-funnel-stats' ) ),
		'pages_per_session' => $card( __( 'Pages / Session', 'dn-burst-funnel-stats' ), 'layers', dn_bfs_dash_number( $cur['pages_per_session'], 2 ), '', $has_prev ? dn_bfs_dash_number( $was( 'pages_per_session' ), 2 ) : '', dn_bfs_dash_change( $summary, 'pages_per_session' ), __( 'Average pageviews per session.', 'dn-burst-funnel-stats' ) ),
		'product_views'     => $card( __( 'Product Views', 'dn-burst-funnel-stats' ), 'product', dn_bfs_dash_number( $cur['product_views'] ), $share( $cur['product_views'] ), $has_prev ? dn_bfs_dash_number( $was( 'product_views' ) ) : '', dn_bfs_dash_change( $summary, 'product_views' ), __( 'Each visitor counts once per product within the anti-spam window.', 'dn-burst-funnel-stats' ) ),
		/* translators: %s: number of cart events. */
		'atc'               => $card( __( 'Add To Cart', 'dn-burst-funnel-stats' ), 'cart', dn_bfs_dash_number( $cur['atc'] ), sprintf( __( 'Cart: %s', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['carts'] ) ), $has_prev ? dn_bfs_dash_number( $was( 'atc' ) ) : '', dn_bfs_dash_change( $summary, 'atc' ), __( 'Add to cart and Cart are recorded together, so they are always equal.', 'dn-burst-funnel-stats' ) ),
		'checkouts'         => $card( __( 'Checkout', 'dn-burst-funnel-stats' ), 'checkout', dn_bfs_dash_number( $cur['checkouts'] ), $share( $cur['checkouts'] ), $has_prev ? dn_bfs_dash_number( $was( 'checkouts' ) ) : '', dn_bfs_dash_change( $summary, 'checkouts' ), __( 'Sessions that reached the checkout page.', 'dn-burst-funnel-stats' ) ),
		'orders_aov'        => $card( __( 'Orders / AOV', 'dn-burst-funnel-stats' ), 'orders', dn_bfs_dash_number( $cur['orders'] ), dn_bfs_dash_money( $cur['aov'] ), $has_prev ? dn_bfs_dash_number( $was( 'orders' ) ) : '', dn_bfs_dash_change( $summary, 'orders' ), __( 'Orders exclude cancelled, failed, draft and fully refunded orders. AOV is net of refunds.', 'dn-burst-funnel-stats' ) ),
		'items_aoi'         => $card( __( 'Items / AOI', 'dn-burst-funnel-stats' ), 'box', dn_bfs_dash_number( $cur['items'] ), dn_bfs_dash_number( $cur['aoi'], 2 ), $has_prev ? dn_bfs_dash_number( $was( 'items' ) ) : '', dn_bfs_dash_change( $summary, 'items' ), __( 'Items sold, net of refunded quantities.', 'dn-burst-funnel-stats' ) ),
		'conversion_rate'   => $card( __( 'Conversion Rate', 'dn-burst-funnel-stats' ), 'check', dn_bfs_dash_percent( $cur['conversion_rate'], 2 ), '', $has_prev ? dn_bfs_dash_percent( $was( 'conversion_rate' ), 2 ) : '', dn_bfs_dash_change( $summary, 'conversion_rate' ), __( 'Orders divided by visitors.', 'dn-burst-funnel-stats' ) ),
		/* translators: %s: tip total. */
		'sales_tip'         => $card( __( 'Sales / Tip', 'dn-burst-funnel-stats' ), 'dollar', dn_bfs_dash_money( $cur['revenue'] ), sprintf( __( 'Tip: %s', 'dn-burst-funnel-stats' ), dn_bfs_dash_money( $cur['tips'] ) ), $has_prev ? dn_bfs_dash_money( $was( 'revenue' ) ) : '', dn_bfs_dash_change( $summary, 'revenue' ), __( 'Sales are net of refunds; tips are gross fee totals.', 'dn-burst-funnel-stats' ) ),
		'paid_balance'      => $card( __( 'Paid / Balance', 'dn-burst-funnel-stats' ), 'money', dn_bfs_dash_money( $cur['paid'] ) . ' / ' . dn_bfs_dash_money( $cur['balance'] ), '', $has_prev ? dn_bfs_dash_money( $was( 'paid' ) ) : '', dn_bfs_dash_change( $summary, 'paid' ), __( 'Paid = processing and completed orders, net of refunds. Balance = pending and on-hold orders.', 'dn-burst-funnel-stats' ) ),
	);
}

function dn_bfs_dashboard_funnel_payload( $funnel ) {
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
		'conversion' => array(
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
