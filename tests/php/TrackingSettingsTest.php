<?php

use PHPUnit\Framework\TestCase;

class TrackingSettingsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['dn_bfs_test_options'] = array();
	}

	public function test_defaults_include_new_keys() {
		$settings = dn_bfs_get_tracking_settings();

		$this->assertSame( array( 'administrator', 'shop_manager' ), $settings['excluded_roles'] );
		$this->assertSame( 'auto', $settings['client_ip_source'] );
		$this->assertSame( 300, $settings['dedupe_window'] );
		$this->assertSame( 10, $settings['reload_window'] );
		$this->assertSame( 60, $settings['limit_pv_per_min'] );
		$this->assertSame( 20, $settings['limit_sessions_per_hour'] );
		$this->assertSame( 20, $settings['limit_atc_per_min'] );
		$this->assertSame( 300, $settings['limit_pv_per_session'] );
		$this->assertSame( 30, $settings['session_timeout'] );
		$this->assertSame( 365, $settings['cookie_days'] );
		$this->assertSame( 90, $settings['raw_retention_days'] );
		$this->assertSame( 1, $settings['block_empty_ua'] );
		$this->assertSame( 0, $settings['force_cart_redirect'] );
		$this->assertSame( 1, $settings['prefer_cloudflare'] );
		$this->assertSame( '', $settings['maxmind_license_key'] );
	}

	public function test_sanitize_clamps_integers_and_cleans_roles() {
		$clean = dn_bfs_sanitize_tracking_settings(
			array(
				'dedupe_window'       => '5',
				'limit_pv_per_min'    => '999999',
				'excluded_roles'      => array( 'Administrator', 'shop_manager', '<b>x</b>' ),
				'client_ip_source'    => 'evil',
				'maxmind_license_key' => 'abc DEF_12!',
				'tracking_enabled'    => 1,
				'exclude_bots'        => 1,
			)
		);

		$this->assertSame( 60, $clean['dedupe_window'] );
		$this->assertSame( 1000, $clean['limit_pv_per_min'] );
		$this->assertSame( array( 'administrator', 'shop_manager', 'bxb' ), $clean['excluded_roles'] );
		$this->assertSame( 'auto', $clean['client_ip_source'] );
		$this->assertSame( 'abcDEF_12', $clean['maxmind_license_key'] );
	}

	public function test_sanitize_keeps_saved_new_keys_when_missing_from_input() {
		$GLOBALS['dn_bfs_test_options']['dn_burst_funnel_stats_tracking_settings'] = array( 'dedupe_window' => 900 );

		$clean = dn_bfs_sanitize_tracking_settings( array( 'tracking_enabled' => 1 ) );

		$this->assertSame( 900, $clean['dedupe_window'] );
	}

	public function test_resolve_client_ip_sources() {
		$server = array(
			'REMOTE_ADDR'           => '172.68.1.1',
			'HTTP_CF_RAY'           => 'abc-SIN',
			'HTTP_CF_CONNECTING_IP' => '198.51.100.7',
			'HTTP_X_FORWARDED_FOR'  => 'bogus, 198.51.100.8, 10.0.0.1',
			'HTTP_X_REAL_IP'        => '198.51.100.9',
		);

		$this->assertSame( '198.51.100.7', dn_bfs_resolve_client_ip( $server, 'auto' ) );
		$this->assertSame( '172.68.1.1', dn_bfs_resolve_client_ip( $server, 'remote_addr' ) );
		$this->assertSame( '198.51.100.8', dn_bfs_resolve_client_ip( $server, 'x_forwarded_for' ) );
		$this->assertSame( '198.51.100.9', dn_bfs_resolve_client_ip( $server, 'x_real_ip' ) );

		unset( $server['HTTP_CF_RAY'] );
		$this->assertSame( '172.68.1.1', dn_bfs_resolve_client_ip( $server, 'auto' ) );
		$this->assertSame( 'unknown', dn_bfs_resolve_client_ip( array(), 'auto' ) );
	}

	public function test_bot_user_agents() {
		$this->assertTrue( dn_bfs_is_bot_user_agent( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) );
		$this->assertTrue( dn_bfs_is_bot_user_agent( 'Mozilla/5.0 HeadlessChrome/120.0' ) );
		$this->assertTrue( dn_bfs_is_bot_user_agent( 'curl/8.4.0' ) );
		$this->assertTrue( dn_bfs_is_bot_user_agent( 'python-requests/2.31' ) );
		$this->assertTrue( dn_bfs_is_bot_user_agent( 'MyCrawler', array( 'mycrawler' ) ) );
		$this->assertFalse( dn_bfs_is_bot_user_agent( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1' ) );
		$this->assertFalse( dn_bfs_is_bot_user_agent( '' ) );
	}

	public function test_default_date_range_accepts_presets() {
		$this->assertSame( 'last_week', dn_bfs_sanitize_tracking_settings( array( 'default_date_range' => 'last_week' ) )['default_date_range'] );
		$this->assertSame( 'month_to_date', dn_bfs_sanitize_tracking_settings( array( 'default_date_range' => 'custom' ) )['default_date_range'] );
		$this->assertSame( 'month_to_date', dn_bfs_sanitize_tracking_settings( array( 'default_date_range' => 'nope' ) )['default_date_range'] );
		$this->assertContains( 'year_to_date', dn_bfs_default_range_presets() );
	}
}
