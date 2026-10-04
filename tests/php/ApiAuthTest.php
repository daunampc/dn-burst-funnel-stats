<?php

use PHPUnit\Framework\TestCase;

class ApiAuthTest extends TestCase {
	const KEY = 'dnbfs_abcd1234_ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef';

	public function test_extract_key_uses_bearer_only_for_plugin_keys() {
		$this->assertSame( self::KEY, dn_bfs_api_extract_key( 'Bearer ' . self::KEY, 'other' ) );
		$this->assertSame( self::KEY, dn_bfs_api_extract_key( '  bearer   ' . self::KEY . ' ', '' ) );
		$this->assertSame( 'other', dn_bfs_api_extract_key( 'Basic dXNlcjpwYXNz', ' other ' ) );
		$this->assertSame( 'other', dn_bfs_api_extract_key( 'Bearer a b', 'other' ) );
		$this->assertSame( '', dn_bfs_api_extract_key( null, null ) );
	}

	public function test_foreign_bearer_tokens_fall_back_to_the_key_header() {
		$this->assertSame( self::KEY, dn_bfs_api_extract_key( 'Bearer eyJhbGciOiJIUzI1NiJ9.e30.x', self::KEY ) );
		$this->assertSame( '', dn_bfs_api_extract_key( 'Bearer abc', '' ) );
		$this->assertSame( self::KEY, dn_bfs_api_extract_key( 'Bearer DNBFS_x', ' ' . self::KEY ) );
	}

	public function test_local_hosts_are_exempt_from_https() {
		foreach ( array( 'localhost', '127.0.0.1', '::1', '[::1]', 'shop.test', 'app.localhost', 'LOCALHOST' ) as $host ) {
			$this->assertTrue( dn_bfs_api_is_local_host( $host ), $host );
		}

		foreach ( array( 'example.com', 'test.example.com', 'localhost.example.com', 'mytest', '' ) as $host ) {
			$this->assertFalse( dn_bfs_api_is_local_host( $host ), $host );
		}
	}

	public function test_ip_allow_list() {
		$this->assertTrue( dn_bfs_api_ip_allowed( '203.0.113.10', array() ) );
		$this->assertTrue( dn_bfs_api_ip_allowed( '198.51.100.7', array( '198.51.100.0/24' ) ) );
		$this->assertFalse( dn_bfs_api_ip_allowed( '203.0.113.10', array( '198.51.100.0/24' ) ) );
		$this->assertTrue( dn_bfs_api_ip_allowed( '2001:db8::5', array( '198.51.100.0/24', '2001:db8::/32' ) ) );
		$this->assertTrue( dn_bfs_api_ip_allowed( '203.0.113.10', array( '203.0.113.10' ) ) );
		$this->assertFalse( dn_bfs_api_ip_allowed( 'unknown', array( '198.51.100.0/24' ) ) );
	}

	private function resolve( $server, $trusted = array() ) {
		return dn_bfs_api_resolve_client_ip( $server, $trusted, dn_bfs_api_default_cloudflare_ranges() );
	}

	public function test_client_ip_defaults_to_remote_addr() {
		$this->assertSame( '203.0.113.10', $this->resolve( array( 'REMOTE_ADDR' => ' 203.0.113.10 ' ) ) );
		$this->assertSame( '2001:db8::1', $this->resolve( array( 'REMOTE_ADDR' => '2001:db8::1' ) ) );
		$this->assertSame( 'unknown', $this->resolve( array( 'REMOTE_ADDR' => 'garbage' ) ) );
		$this->assertSame( 'unknown', $this->resolve( array() ) );
	}

	public function test_spoofed_cloudflare_headers_are_ignored_outside_cloudflare_ranges() {
		$server = array(
			'REMOTE_ADDR'           => '203.0.113.10',
			'HTTP_CF_RAY'           => 'abc-LAX',
			'HTTP_CF_CONNECTING_IP' => '198.51.100.7',
		);

		$this->assertSame( '203.0.113.10', $this->resolve( $server ) );
	}

	public function test_cf_connecting_ip_is_honoured_from_cloudflare_ranges() {
		$this->assertSame( '198.51.100.7', $this->resolve( array( 'REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7' ) ) );
		$this->assertSame( '2001:db8::7', $this->resolve( array( 'REMOTE_ADDR' => '2a06:98c1::1', 'HTTP_CF_CONNECTING_IP' => '2001:db8::7' ) ) );
		$this->assertSame( '172.70.1.2', $this->resolve( array( 'REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => 'nope' ) ), 'invalid header falls back' );
		$this->assertSame( '172.70.1.2', $this->resolve( array( 'REMOTE_ADDR' => '172.70.1.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7' ) ), 'XFF from Cloudflare alone is not trusted' );
	}

	public function test_x_forwarded_for_needs_a_trusted_proxy() {
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
		);

		$this->assertSame( '10.0.0.5', $this->resolve( $server ) );
		$this->assertSame( '198.51.100.7', $this->resolve( $server, array( '10.0.0.0/8' ) ) );
	}

	public function test_x_forwarded_for_takes_the_right_most_untrusted_hop() {
		$trusted = array( '10.0.0.0/8', '192.0.2.1' );

		$this->assertSame( '203.0.113.9', $this->resolve( array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 203.0.113.9, 10.1.1.1' ), $trusted ), 'spoofed left-most entry ignored' );
		$this->assertSame( '203.0.113.9', $this->resolve( array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 162.158.1.1, 192.0.2.1' ), $trusted ), 'Cloudflare hops skipped' );
		$this->assertSame( '10.2.2.2', $this->resolve( array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '10.2.2.2, 10.1.1.1' ), $trusted ), 'all trusted: left-most hop' );
		$this->assertSame( '10.1.1.1', $this->resolve( array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, unknown, 10.1.1.1' ), $trusted ), 'stops at an invalid hop' );
		$this->assertSame( '10.0.0.5', $this->resolve( array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '' ), $trusted ) );
		$this->assertSame( '2001:db8::9', $this->resolve( array( 'REMOTE_ADDR' => 'fd00::1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::9, fd00::2' ), array( 'fd00::/8' ) ) );
	}

	public function test_ports_and_ipv4_mapped_addresses_are_normalised() {
		$this->assertSame( '203.0.113.10', $this->resolve( array( 'REMOTE_ADDR' => '::ffff:203.0.113.10' ) ) );
		$this->assertSame( '203.0.113.10', $this->resolve( array( 'REMOTE_ADDR' => '::ffff:cb00:710a' ) ) );
		$this->assertSame( '203.0.113.10', $this->resolve( array( 'REMOTE_ADDR' => '203.0.113.10:5555' ) ) );
		$this->assertSame( '2001:db8::1', $this->resolve( array( 'REMOTE_ADDR' => '[2001:db8::1]:443' ) ) );
		$this->assertSame( '198.51.100.7', $this->resolve( array( 'REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7:1234' ) ) );
		$this->assertSame( '172.70.1.2', $this->resolve( array( 'REMOTE_ADDR' => '::ffff:172.70.1.2', 'HTTP_CF_CONNECTING_IP' => 'x' ) ), 'mapped Cloudflare address matches the v4 range' );

		$trusted = array( '10.0.0.0/8' );
		$server  = array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7:4444, [2001:db8::9]:443, ::ffff:10.1.1.1, 10.1.1.2:80' );

		$this->assertSame( '2001:db8::9', $this->resolve( $server, $trusted ) );
		$this->assertSame( '198.51.100.7', $this->resolve( array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7:4444, ::ffff:10.1.1.1' ), $trusted ) );
		$this->assertTrue( dn_bfs_api_ip_allowed( $this->resolve( array( 'REMOTE_ADDR' => '::ffff:203.0.113.10' ) ), array( '203.0.113.10' ) ) );
		$this->assertSame( '', dn_bfs_api_normalize_ip( 'a:b' ) );
		$this->assertSame( '', dn_bfs_api_normalize_ip( '1.2.3.4:' ) );
	}

	public function test_default_cloudflare_ranges_are_valid() {
		$ranges = dn_bfs_api_default_cloudflare_ranges();

		$this->assertCount( 22, $ranges );

		foreach ( $ranges as $range ) {
			$this->assertTrue( dn_bfs_validate_ip_rule( $range ), $range );
		}
	}
}
