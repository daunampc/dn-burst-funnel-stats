<?php

use PHPUnit\Framework\TestCase;

class GuardTest extends TestCase {
	private $vid = '0123456789abcdef0123456789abcdef';
	private $sid = 'fedcba9876543210fedcba9876543210';

	private function server( $origin = 'https://shop.example.com' ) {
		return array(
			'REQUEST_METHOD' => 'POST',
			'HTTP_ORIGIN'    => $origin,
			'HTTP_REFERER'   => '',
		);
	}

	private function pv( $overrides = array() ) {
		return json_encode(
			array_merge(
				array(
					't'     => 'pv',
					'vid'   => $this->vid,
					'sid'   => $this->sid,
					'path'  => '/product/ao-thun/',
					'query' => '?utm_source=fb',
					'ref'   => 'https://l.facebook.com/',
					'ptype' => 'product',
					'pid'   => 42,
					'sw'    => 390,
				),
				$overrides
			)
		);
	}

	public function test_valid_pageview() {
		$result = dn_bfs_guard_validate_payload( $this->pv(), $this->server(), 'shop.example.com' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '/product/ao-thun/', $result['data']['path'] );
		$this->assertSame( 'product', $result['data']['ptype'] );
		$this->assertSame( 42, $result['data']['pid'] );
		$this->assertSame( 'utm_source=fb', $result['data']['query'] );
	}

	public function test_www_origin_is_same_site_and_referer_fallback() {
		$this->assertTrue( dn_bfs_guard_validate_payload( $this->pv(), $this->server( 'https://www.shop.example.com' ), 'shop.example.com' )['ok'] );

		$server                 = $this->server( '' );
		$server['HTTP_REFERER'] = 'https://shop.example.com/cart/';
		$this->assertTrue( dn_bfs_guard_validate_payload( $this->pv(), $server, 'shop.example.com' )['ok'] );
	}

	/**
	 * @dataProvider rejections
	 */
	public function test_rejections( $raw, $server, $reason ) {
		$result = dn_bfs_guard_validate_payload( $raw, $server, 'shop.example.com' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( $reason, $result['reason'] );
	}

	public function rejections() {
		$ok      = array( 'REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://shop.example.com' );
		$valid   = array( 't' => 'pv', 'vid' => str_repeat( 'a', 32 ), 'sid' => str_repeat( 'b', 32 ), 'path' => '/', 'ptype' => 'home' );
		$get     = array_merge( $ok, array( 'REQUEST_METHOD' => 'GET' ) );
		$foreign = array( 'REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://evil.test' );
		$none    = array( 'REQUEST_METHOD' => 'POST' );

		return array(
			'method'        => array( json_encode( $valid ), $get, 'bad_method' ),
			'too large'     => array( str_repeat( 'x', 4097 ), $ok, 'too_large' ),
			'not json'      => array( 'hello', $ok, 'invalid_json' ),
			'bad type'      => array( json_encode( array_merge( $valid, array( 't' => 'click' ) ) ), $ok, 'invalid_type' ),
			'array type'    => array( json_encode( array_merge( $valid, array( 't' => array( 'pv' ) ) ) ), $ok, 'invalid_type' ),
			'bad vid'       => array( json_encode( array_merge( $valid, array( 'vid' => 'XYZ' ) ) ), $ok, 'invalid_id' ),
			'foreign'       => array( json_encode( $valid ), $foreign, 'bad_origin' ),
			'no origin'     => array( json_encode( $valid ), $none, 'bad_origin' ),
			'bad path'      => array( json_encode( array_merge( $valid, array( 'path' => 'http://x' ) ) ), $ok, 'invalid_path' ),
			'leading query' => array( json_encode( array_merge( $valid, array( 'path' => '?/evil' ) ) ), $ok, 'invalid_path' ),
			'ping no pv'    => array( json_encode( array( 't' => 'ping', 'vid' => str_repeat( 'a', 32 ), 'sid' => str_repeat( 'b', 32 ), 'pvid' => 0 ) ), $ok, 'invalid_pageview' ),
		);
	}

	public function test_normalizes_fields() {
		$result = dn_bfs_guard_validate_payload(
			$this->pv(
				array(
					'path'  => '/a?x=1#y' . str_repeat( 'z', 300 ),
					'ptype' => 'weird',
					'ref'   => 'javascript:alert(1)',
					'pid'   => -5,
				)
			),
			$this->server(),
			'shop.example.com'
		);

		$this->assertSame( '/a', $result['data']['path'] );
		$this->assertSame( 'other', $result['data']['ptype'] );
		$this->assertSame( '', $result['data']['ref'] );
		$this->assertSame( 5, $result['data']['pid'] );
	}

	public function test_long_but_legitimate_hit_is_accepted() {
		$raw    = $this->pv( array( 'query' => '?' . str_repeat( 'q', 1023 ), 'ref' => 'https://l.facebook.com/' . str_repeat( 'r', 990 ) ) );
		$result = dn_bfs_guard_validate_payload( $raw, $this->server(), 'shop.example.com' );

		$this->assertTrue( $result['ok'] );
	}

	public function test_ping_clamps_engaged() {
		$raw    = json_encode( array( 't' => 'ping', 'vid' => $this->vid, 'sid' => $this->sid, 'pvid' => 9, 'engaged' => 99999 ) );
		$result = dn_bfs_guard_validate_payload( $raw, $this->server(), 'shop.example.com' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 9, $result['data']['pvid'] );
		$this->assertSame( 1800, $result['data']['engaged'] );
	}

	public function test_check_visitor() {
		$GLOBALS['dn_bfs_test_options'] = array();
		$settings                       = dn_bfs_get_tracking_settings();
		$settings['excluded_ips']       = array( '10.0.0.0/8' );
		$base                           = array(
			'settings' => $settings,
			'roles'    => array( 'customer' ),
			'ip'       => '203.0.113.1',
			'ua_raw'   => 'Mozilla/5.0 (Windows NT 10.0) Chrome/128.0',
		);

		$this->assertSame( '', dn_bfs_guard_check_visitor( $base ) );
		$this->assertSame( 'excluded_role', dn_bfs_guard_check_visitor( array_merge( $base, array( 'roles' => array( 'shop_manager' ) ) ) ) );
		$this->assertSame( 'excluded_ip', dn_bfs_guard_check_visitor( array_merge( $base, array( 'ip' => '10.2.3.4' ) ) ) );
		$this->assertSame( 'empty_ua', dn_bfs_guard_check_visitor( array_merge( $base, array( 'ua_raw' => '' ) ) ) );
		$this->assertSame( 'bot', dn_bfs_guard_check_visitor( array_merge( $base, array( 'ua_raw' => 'curl/8.0' ) ) ) );

		$off                     = $base;
		$off['settings']['tracking_enabled'] = 0;
		$this->assertSame( 'disabled', dn_bfs_guard_check_visitor( $off ) );
	}

	public function test_page_selection() {
		$settings = array( 'page_tracking_mode' => 'selected', 'selected_page_ids' => array( 7 ) );

		$this->assertTrue( dn_bfs_guard_is_page_selected( array( 'ptype' => 'other', 'pid' => 7 ), $settings ) );
		$this->assertFalse( dn_bfs_guard_is_page_selected( array( 'ptype' => 'other', 'pid' => 8 ), $settings ) );
		$this->assertTrue( dn_bfs_guard_is_page_selected( array( 'ptype' => 'cart', 'pid' => 8 ), $settings ) );
		$this->assertTrue( dn_bfs_guard_is_page_selected( array( 'ptype' => 'other', 'pid' => 8 ), array( 'page_tracking_mode' => 'full' ) ) );
	}
}
