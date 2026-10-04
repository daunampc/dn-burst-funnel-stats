<?php

use PHPUnit\Framework\TestCase;

class MetricsTest extends TestCase {
	public function test_empty_and_normalize() {
		$empty = dn_bfs_empty_metrics();

		$this->assertSame( dn_bfs_metric_columns(), array_keys( $empty ) );
		$this->assertSame( 0, $empty['sessions'] );
		$this->assertSame( 0.0, $empty['revenue'] );

		$row = dn_bfs_normalize_metrics( array( 'sessions' => '3', 'revenue' => '12.50', 'junk' => 1 ) );
		$this->assertSame( 3, $row['sessions'] );
		$this->assertSame( 12.5, $row['revenue'] );
		$this->assertArrayNotHasKey( 'junk', $row );
	}

	public function test_add_metrics() {
		$sum = dn_bfs_add_metrics(
			dn_bfs_normalize_metrics( array( 'sessions' => 2, 'revenue' => 1.1 ) ),
			dn_bfs_normalize_metrics( array( 'sessions' => 3, 'revenue' => 2.2 ) )
		);

		$this->assertSame( 5, $sum['sessions'] );
		$this->assertSame( 3.3, $sum['revenue'] );
	}

	public function test_dimension_lists() {
		$this->assertSame( array( 'channel', 'source', 'medium', 'campaign', 'device', 'country' ), dn_bfs_filter_dimensions() );
		$this->assertSame( 'total', dn_bfs_aggregate_dimensions()[0] );
		$this->assertContains( 'page', dn_bfs_report_dimensions() );
		$this->assertContains( 'product', dn_bfs_order_dimensions() );
		$this->assertNotContains( 'page', dn_bfs_order_dimensions() );
	}

	public function test_sanitize_filters() {
		$this->assertSame(
			array( 'campaign' => 'sale-10', 'device' => 'mobile' ),
			dn_bfs_sanitize_filters( array( 'device' => 'mobile', 'campaign' => ' sale-10 ', 'browser' => 'Chrome', 'country' => '', 'source' => array( 'x' ) ) )
		);
		$this->assertSame( array(), dn_bfs_sanitize_filters( 'nope' ) );
		$this->assertSame( 191, mb_strlen( dn_bfs_sanitize_filters( array( 'campaign' => str_repeat( 'ă', 300 ) ) )['campaign'], 'UTF-8' ) );
	}

	public function test_derive_metrics() {
		$m = dn_bfs_derive_metrics(
			dn_bfs_normalize_metrics(
				array(
					'visitors'     => 200,
					'new_visitors' => 150,
					'sessions'     => 250,
					'bounces'      => 100,
					'duration_sum' => 25000,
					'pageviews'    => 500,
					'orders'       => 5,
					'revenue'      => 250.0,
					'items'        => 8,
				)
			)
		);

		$this->assertSame( 50, $m['returning_visitors'] );
		$this->assertSame( 40.0, $m['bounce_rate'] );
		$this->assertSame( 100, $m['avg_duration'] );
		$this->assertSame( 2.0, $m['pages_per_session'] );
		$this->assertSame( 2.5, $m['conversion_rate'] );
		$this->assertSame( 50.0, $m['aov'] );
		$this->assertSame( 1.6, $m['aoi'] );

		$zero = dn_bfs_derive_metrics( dn_bfs_empty_metrics() );
		$this->assertSame( 0.0, $zero['bounce_rate'] );
		$this->assertSame( 0.0, $zero['aov'] );
		$this->assertSame( dn_bfs_derived_metric_names(), array_values( array_diff( array_keys( $zero ), dn_bfs_metric_columns() ) ) );
	}

	public function test_percent_change() {
		$this->assertSame( 50.0, dn_bfs_percent_change( 150, 100 ) );
		$this->assertSame( -25.0, dn_bfs_percent_change( 75, 100 ) );
		$this->assertSame( 100.0, dn_bfs_percent_change( 5, 0 ) );
		$this->assertSame( 0.0, dn_bfs_percent_change( 0, 0 ) );
	}

	public function test_dates() {
		$this->assertSame( '2026-03-01', dn_bfs_date_shift( '2026-02-28', 1 ) );
		$this->assertSame( '2025-12-31', dn_bfs_date_shift( '2026-01-01', -1 ) );
		$this->assertSame( array( '2026-02-27', '2026-02-28', '2026-03-01' ), dn_bfs_dates_between( '2026-02-27', '2026-03-01' ) );
		$this->assertSame( array(), dn_bfs_dates_between( '2026-03-02', '2026-03-01' ) );
	}

	public function test_sort_rows() {
		$rows = array(
			array( 'dim_value' => 'b', 'sessions' => 5 ),
			array( 'dim_value' => 'a', 'sessions' => 5 ),
			array( 'dim_value' => 'c', 'sessions' => 9 ),
		);

		$this->assertSame( array( 'c', 'a', 'b' ), array_column( dn_bfs_sort_report_rows( $rows, 'sessions', 'desc' ), 'dim_value' ) );
		$this->assertSame( array( 'a', 'b', 'c' ), array_column( dn_bfs_sort_report_rows( $rows, 'sessions', 'asc' ), 'dim_value' ) );
	}

	public function test_dim_hash_is_case_insensitive() {
		$this->assertSame( md5( 'facebook' ), dn_bfs_dim_hash( 'FaceBook' ) );
		$this->assertSame( dn_bfs_dim_hash( 'tiền boa' ), dn_bfs_dim_hash( 'TIỀN BOA' ) );
		$this->assertSame( md5( '' ), dn_bfs_dim_hash( '' ) );
	}
}
