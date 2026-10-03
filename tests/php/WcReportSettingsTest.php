<?php

use PHPUnit\Framework\TestCase;

class WcReportSettingsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['dn_bfs_test_options'] = array();
	}

	public function test_defaults() {
		$settings = dn_bfs_get_wc_report_settings();

		$this->assertSame( array( 'wc-cancelled', 'wc-failed', 'wc-checkout-draft' ), $settings['sales_excluded_statuses'] );
		$this->assertSame( array( 'wc-processing', 'wc-completed' ), $settings['paid_statuses'] );
		$this->assertSame( array( 'wc-pending', 'wc-on-hold' ), $settings['balance_statuses'] );
		$this->assertSame( array( 'tip', 'tips', 'gratuity' ), $settings['tip_keywords'] );
	}

	public function test_sanitize() {
		$clean = dn_bfs_sanitize_wc_report_settings(
			array(
				'sales_excluded_statuses' => array( 'wc-cancelled', 'processing', 'WC-Failed', '' ),
				'paid_statuses'           => 'wc-completed',
				'tip_keywords'            => "Tip\nBo Duoc\n\n",
			)
		);

		$this->assertSame( array( 'wc-cancelled', 'wc-failed' ), $clean['sales_excluded_statuses'] );
		$this->assertSame( array( 'wc-completed' ), $clean['paid_statuses'] );
		$this->assertSame( array( 'wc-pending', 'wc-on-hold' ), $clean['balance_statuses'] );
		$this->assertSame( array( 'tip', 'bo duoc' ), $clean['tip_keywords'] );
	}

	public function test_tip_keywords_lowercase_unicode() {
		$clean = dn_bfs_sanitize_wc_report_settings( array( 'tip_keywords' => "Tiền Boa\nTIỀN BOA" ) );

		$this->assertSame( array( 'tiền boa' ), $clean['tip_keywords'] );
	}

	public function test_saved_values_are_used() {
		$GLOBALS['dn_bfs_test_options']['dn_burst_funnel_stats_wc_report_settings'] = array( 'paid_statuses' => array( 'wc-completed' ) );

		$this->assertSame( array( 'wc-completed' ), dn_bfs_get_wc_report_settings()['paid_statuses'] );
	}
}
