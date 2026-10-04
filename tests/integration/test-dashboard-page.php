<?php

require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/admin-helpers.php';

function dn_bfs_it_seed_page_traffic() {
	$now = dn_bfs_it_now();

	foreach ( array( array( 'paid', 'sale-10', 'mobile' ), array( 'direct', '', 'desktop' ) ) as $i => $row ) {
		$s = dn_bfs_it_seed_session( array( 'started_at' => $now - 100 - $i, 'channel' => $row[0], 'utm_campaign' => $row[1], 'device' => $row[2] ) );
		dn_bfs_it_seed_pageview( $s, '/landing-' . $i . '/', $now - 100 - $i );
	}
}

dn_bfs_it_today(
	'overview payload renders cards, charts and range meta',
	function () {
		$user = dn_bfs_it_login_admin();
		delete_user_meta( $user, 'dnbfs_cards' );
		dn_bfs_it_seed_page_traffic();

		$payload = dn_bfs_ajax_tab_payload( array( 'tab' => 'overview', 'period' => 'today', 'compare' => 'previous_period' ) );

		dn_bfs_assert_same( 'overview', $payload['tab'] );
		dn_bfs_assert_same( 'Overview', $payload['title'] );
		dn_bfs_assert_same( 15, substr_count( $payload['html'], 'data-dn-card="' ) );
		dn_bfs_assert_same( 4, substr_count( $payload['html'], 'data-dn-chart="' ) );
		dn_bfs_assert_true( isset( $payload['range']['current_range_label'] ), 'range meta' );
	}
);

dn_bfs_it_today(
	'hidden cards keep their place after visible ones and carry the hidden class',
	function () {
		$user = dn_bfs_it_login_admin();
		dn_bfs_save_user_cards( $user, array( 'orders_aov' ) );

		$html = dn_bfs_ajax_tab_payload( array( 'tab' => 'overview', 'period' => 'today' ) )['html'];

		dn_bfs_assert_true( strpos( $html, 'data-dn-card="orders_aov"' ) < strpos( $html, 'data-dn-card="visitors"' ), 'visible card first' );
		dn_bfs_assert_true( (bool) preg_match( '/class="dn-burst-card is-hidden" data-dn-card="visitors"/', $html ), 'visitors hidden' );
		dn_bfs_save_user_cards( $user, null );
	}
);

dn_bfs_it_today(
	'breakdown tables render dimension switches, sorting and drillable rows',
	function () {
		dn_bfs_it_login_admin();
		dn_bfs_it_seed_page_traffic();

		$sources = dn_bfs_ajax_tab_payload( array( 'tab' => 'sources', 'period' => 'today' ) )['html'];
		dn_bfs_assert_same( 4, substr_count( $sources, 'name="dn_dimension"' ) );
		dn_bfs_assert_true( false !== strpos( $sources, 'data-dn-drill-dimension="channel" data-dn-drill-value="paid"' ), 'drillable paid row' );
		dn_bfs_assert_true( false !== strpos( $sources, 'Paid' ), 'channel label' );

		$campaigns = dn_bfs_ajax_table_payload( array( 'tab' => 'ad-urls', 'dimension' => 'campaign', 'orderby' => 'visitors', 'order' => 'asc', 'period' => 'today' ) )['html'];
		dn_bfs_assert_true( false !== strpos( $campaigns, '(none)' ), 'empty campaign shown' );
		dn_bfs_assert_same( 1, substr_count( $campaigns, 'data-dn-drill-dimension="campaign"' ) );
		dn_bfs_assert_true( false !== strpos( $campaigns, 'data-orderby="visitors" data-order="asc"' ), 'sort state' );

		$pages = dn_bfs_ajax_tab_payload( array( 'tab' => 'pages', 'period' => 'today' ) )['html'];
		dn_bfs_assert_true( false === strpos( $pages, 'data-dn-drill-dimension' ), 'pages not drillable' );

		dn_bfs_assert_same( 'invalid_tab', dn_bfs_ajax_table_payload( array( 'tab' => 'overview' ) )->get_error_code() );
	}
);

dn_bfs_it_today(
	'brands tab groups products by brand',
	function () {
		dn_bfs_it_login_admin();

		$product  = (int) wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' ) )[0];
		$taxonomy = taxonomy_exists( 'product_brand' ) ? 'product_brand' : '';

		if ( '' === $taxonomy ) {
			register_taxonomy( 'product_brand', 'product' );
			$taxonomy = 'product_brand';
		}

		wp_set_object_terms( $product, 'Acme', $taxonomy );
		$s = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_now() - 60 ) );
		dn_bfs_it_seed_event( $s, 'product_view', dn_bfs_it_now() - 60, array( 'product_id' => $product ) );

		$html = dn_bfs_ajax_tab_payload( array( 'tab' => 'brands', 'period' => 'today' ) )['html'];
		dn_bfs_assert_true( false !== strpos( $html, 'Acme' ), 'brand row' );

		wp_set_object_terms( $product, array(), $taxonomy );
	}
);

dn_bfs_it_today(
	'drill-down shows a filtered summary with an apply button',
	function () {
		dn_bfs_it_login_admin();
		dn_bfs_it_seed_page_traffic();

		$html = dn_bfs_ajax_drilldown_payload( array( 'period' => 'today', 'dimension' => 'campaign', 'value' => 'sale-10' ) )['html'];
		dn_bfs_assert_true( false !== strpos( $html, 'Campaign: sale-10' ), 'title' );
		dn_bfs_assert_true( false !== strpos( $html, 'data-dn-apply-filter' ), 'apply button' );
		dn_bfs_assert_same( 2, substr_count( $html, 'data-dn-chart="' ) );

		$refused = dn_bfs_ajax_drilldown_payload( array( 'period' => 'today', 'dimension' => 'browser', 'value' => 'Chrome' ) )['html'];
		dn_bfs_assert_true( false !== strpos( $refused, 'notice' ), 'refused' );
	}
);

dn_bfs_it_today(
	'out-of-retention filters show a notice instead of failing',
	function () {
		dn_bfs_it_login_admin();

		$payload = dn_bfs_ajax_tab_payload(
			array(
				'tab'    => 'overview',
				'period' => 'custom',
				'start'  => dn_bfs_date_shift( wp_date( 'Y-m-d', dn_bfs_it_now() ), -400 ),
				'end'    => wp_date( 'Y-m-d', dn_bfs_it_now() ),
				'filter' => array( 'channel' => 'paid', 'device' => 'mobile' ),
			)
		);

		dn_bfs_assert_true( false !== strpos( $payload['html'], 'notice-warning' ), 'notice' );
		dn_bfs_assert_true( false !== strpos( $payload['html'], 'Combined filters are only available for dates that still have raw tracking data.' ), 'retention message' );
		dn_bfs_assert_same( 'filter_out_of_retention', dn_bfs_report_error_out_of_retention()->get_error_code() );
		dn_bfs_assert_same( 'invalid_filter', dn_bfs_ajax_tab_payload( array( 'filter' => array( 'browser' => 'x' ) ) )->get_error_code() );
	}
);

dn_bfs_it_today(
	'cards, filter values, realtime and update-now payloads',
	function () {
		$user = dn_bfs_it_login_admin();
		dn_bfs_it_seed_page_traffic();

		dn_bfs_assert_same( array( 'visitors' ), dn_bfs_ajax_save_cards_payload( $user, array( 'cards' => array( 'visitors' ) ) )['cards'] );
		dn_bfs_assert_same( array(), dn_bfs_ajax_save_cards_payload( $user, array( 'cards_sent' => 1 ) )['cards'] );
		dn_bfs_assert_same( dn_bfs_dashboard_card_keys(), dn_bfs_ajax_save_cards_payload( $user, array( 'reset' => 1 ) )['cards'] );
		dn_bfs_assert_same( 'invalid_card', dn_bfs_ajax_save_cards_payload( $user, array( 'cards' => array( 'x' ) ) )->get_error_code() );

		dn_bfs_assert_same( array( 'sale-10' ), dn_bfs_ajax_filter_values_payload( array( 'dimension' => 'campaign', 'search' => 'sale' ) )['values'] );
		dn_bfs_assert_same( 'invalid_dimension', dn_bfs_ajax_filter_values_payload( array( 'dimension' => 'page' ) )->get_error_code() );

		dn_bfs_assert_true( isset( dn_bfs_ajax_realtime_payload()['online'] ), 'realtime' );

		$update = dn_bfs_ajax_update_now_payload();
		dn_bfs_assert_true( isset( $update['message'], $update['lastUpdate'], $update['nextUpdate'] ), 'update labels' );
	}
);

dn_bfs_it_today(
	'dashboard page renders toolbar, filter chips and tabs from the URL',
	function () {
		dn_bfs_it_login_admin();
		$_GET = array( 'page' => 'dn-burst-funnel-stats', 'dn_tab' => 'sources', 'dn_period' => 'today', 'dn_filter' => array( 'campaign' => 'sale-10' ) );

		ob_start();
		dn_bfs_dash_render_page();
		$html = ob_get_clean();

		dn_bfs_assert_true( false !== strpos( $html, 'data-dn-filter-dim="campaign"' ), 'chip' );
		dn_bfs_assert_true( false !== strpos( $html, 'Campaign: sale-10' ), 'chip label' );
		dn_bfs_assert_true( (bool) preg_match( '/nav-tab nav-tab-active" data-dn-tab="sources"/', $html ), 'active tab' );
		dn_bfs_assert_true( false !== strpos( $html, 'data-dn-online' ), 'online badge' );
		dn_bfs_assert_true( false !== strpos( $html, 'data-dn-update-now' ), 'update now' );

		$_GET = array( 'page' => 'dn-burst-funnel-stats', 'dn_period' => 'nope' );
		ob_start();
		dn_bfs_dash_render_page();
		$fallback = ob_get_clean();
		dn_bfs_assert_true( false !== strpos( $fallback, 'Unknown date range.' ), 'invalid URL notice' );
		$_GET = array();
	}
);

dn_bfs_it_today(
	'estimate note renders for multi-day unfiltered ranges',
	function () {
		dn_bfs_it_login_admin();
		dn_bfs_it_seed_page_traffic();

		$today = wp_date( 'Y-m-d', dn_bfs_it_now() );
		$html  = dn_bfs_ajax_tab_payload( array( 'tab' => 'overview', 'period' => 'custom', 'start' => dn_bfs_date_shift( $today, -3 ), 'end' => $today ) )['html'];

		dn_bfs_assert_true( false !== strpos( $html, 'dn-burst-estimate-note' ), 'estimate note' );
	}
);

dn_bfs_it_today(
	'brand totals aggregate across breakdown pages',
	function () {
		dn_bfs_it_login_admin();

		if ( ! taxonomy_exists( 'product_brand' ) ) {
			register_taxonomy( 'product_brand', 'product' );
		}

		$products = wc_get_products( array( 'limit' => 2, 'status' => 'publish', 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' ) );
		dn_bfs_assert_true( count( $products ) >= 2, 'two products available' );

		foreach ( $products as $product ) {
			wp_set_object_terms( (int) $product, 'Acme', 'product_brand' );
			$s = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_now() - 60 ) );
			dn_bfs_it_seed_event( $s, 'product_view', dn_bfs_it_now() - 60, array( 'product_id' => (int) $product ) );
		}

		$size = function () {
			return 1;
		};
		add_filter( 'dn_bfs_brand_page_size', $size );

		list( $range, $filters ) = dn_bfs_dash_request( array( 'period' => 'today' ) );
		$result                  = dn_bfs_dash_brand_breakdown( $range, $filters, '', 'desc', 25, 0 );

		remove_filter( 'dn_bfs_brand_page_size', $size );

		$acme = array_values( array_filter( $result['rows'], function ( $row ) {
			return 'Acme' === $row['label'];
		} ) );

		dn_bfs_assert_same( 1, count( $acme ), 'single brand row' );
		dn_bfs_assert_same( 2, (int) $acme[0]['product_views'], 'views summed across pages' );

		foreach ( $products as $product ) {
			wp_set_object_terms( (int) $product, array(), 'product_brand' );
		}
	}
);

dn_bfs_it_today(
	'money output keeps bdi, malformed aggregate date and unsafe drill values are tolerated',
	function () {
		dn_bfs_assert_true( false !== strpos( dn_bfs_dash_kses_money( '<span class="x"><bdi>1</bdi></span><script>x</script>' ), '<bdi>1</bdi>' ), 'bdi kept' );
		dn_bfs_assert_true( false === strpos( dn_bfs_dash_kses_money( '<script>x</script>' ), '<script' ), 'script stripped' );

		update_option( 'dnbfs_last_aggregated_date', 'garbage' );
		dn_bfs_assert_same( 'Not yet', dn_bfs_dash_status_labels()['last'] );
		delete_option( 'dnbfs_last_aggregated_date' );

		dn_bfs_it_login_admin();
		list( $range, $filters ) = dn_bfs_dash_request( array( 'period' => 'today' ) );
		dn_bfs_assert_true( false !== strpos( dn_bfs_dash_drilldown_html( $range, $filters, 'campaign', '<b></b>' ), 'cannot be explored' ), 'empty after sanitize' );
	}
);
