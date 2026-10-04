<?php

use PHPUnit\Framework\TestCase;

class ApiAuthTest extends TestCase {
	public function test_extract_key_prefers_bearer_then_header() {
		$this->assertSame( 'abc', dn_bfs_api_extract_key( 'Bearer abc', 'other' ) );
		$this->assertSame( 'abc', dn_bfs_api_extract_key( '  bearer   abc ', '' ) );
		$this->assertSame( 'other', dn_bfs_api_extract_key( 'Basic dXNlcjpwYXNz', ' other ' ) );
		$this->assertSame( 'other', dn_bfs_api_extract_key( 'Bearer a b', 'other' ) );
		$this->assertSame( '', dn_bfs_api_extract_key( null, null ) );
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
}
