<?php

use PHPUnit\Framework\TestCase;

class TrackingCidrTest extends TestCase {
	public function test_exact_ipv4_match() {
		$this->assertTrue( dn_bfs_ip_in_cidr( '203.0.113.5', '203.0.113.5' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( '203.0.113.6', '203.0.113.5' ) );
	}

	public function test_ipv4_range() {
		$this->assertTrue( dn_bfs_ip_in_cidr( '10.1.2.3', '10.0.0.0/8' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( '11.1.2.3', '10.0.0.0/8' ) );
		$this->assertTrue( dn_bfs_ip_in_cidr( '192.168.1.130', '192.168.1.128/25' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( '192.168.1.127', '192.168.1.128/25' ) );
	}

	public function test_ipv6_range_and_family_mismatch() {
		$this->assertTrue( dn_bfs_ip_in_cidr( '2001:db8::1', '2001:db8::/32' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( '2001:db9::1', '2001:db8::/32' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( '10.0.0.1', '2001:db8::/32' ) );
	}

	public function test_ipv6_partial_prefixes_and_exact_match() {
		$this->assertTrue( dn_bfs_ip_in_cidr( '2a06:98c7::1', '2a06:98c0::/29' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( '2a06:98c8::1', '2a06:98c0::/29' ) );
		$this->assertTrue( dn_bfs_ip_in_cidr( '2001:db8::1', '2001:0db8:0:0::1' ) );
		$this->assertTrue( dn_bfs_ip_in_cidr( '2001:db8::1', '::/0' ) );
	}

	public function test_malformed_rules_never_match() {
		$this->assertFalse( dn_bfs_ip_in_cidr( '10.0.0.1', '10.0.0.0/' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( '10.0.0.1', '10.0.0.0/33' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( '10.0.0.1', '10.0.0.0/8x' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( '2001:db8::1', '2001:db8::/129' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( 'unknown', '10.0.0.0/8' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( 'unknown', 'unknown' ) );
	}
}
