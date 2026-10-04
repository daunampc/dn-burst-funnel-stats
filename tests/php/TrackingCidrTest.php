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

	public function test_parse_ip_rule_normalises_and_rejects_garbage() {
		$this->assertSame( array( '203.0.113.5', 32 ), dn_bfs_parse_ip_rule( ' 203.0.113.5 / 32 ' ) );
		$this->assertSame( array( '10.0.0.0', 8 ), dn_bfs_parse_ip_rule( '10.0.0.0/ 8' ) );
		$this->assertSame( array( '10.0.0.1', 32 ), dn_bfs_parse_ip_rule( '10.0.0.1' ) );
		$this->assertSame( array( '2001:db8::', 128 ), dn_bfs_parse_ip_rule( '2001:db8::' ) );
		$this->assertSame( array( '::', 0 ), dn_bfs_parse_ip_rule( '::/0' ) );

		foreach ( array( '10.0.0.0/', '10.0.0.0/8x', '10.0.0.0/33', '2001:db8::/129', '10.0.0.0/-1', '10.0.0.0/+8', '/8', 'junk/8', '', '10.0.0.0/ ' ) as $rule ) {
			$this->assertFalse( dn_bfs_parse_ip_rule( $rule ), $rule );
		}
	}

	public function test_validator_and_matcher_agree() {
		$rules = array( '10.0.0.0/', '10.0.0.0/8x', '10.0.0.0/ 8', '10.0.0.0/33', '10.0.0.0/8', '203.0.113.5/ 32', ' 203.0.113.5 ', '2001:db8::/129', '2001:db8::/32', 'junk', '' );

		foreach ( $rules as $rule ) {
			$matches = dn_bfs_ip_in_cidr( '10.0.0.1', $rule ) || dn_bfs_ip_in_cidr( '203.0.113.5', $rule ) || dn_bfs_ip_in_cidr( '2001:db8::1', $rule );

			$this->assertSame( dn_bfs_validate_ip_rule( $rule ), $matches, 'agreement for "' . $rule . '"' );
		}

		$this->assertTrue( dn_bfs_ip_in_cidr( '203.0.113.5', '203.0.113.5/ 32' ) );
		$this->assertTrue( dn_bfs_ip_in_cidr( '10.9.9.9', '10.0.0.0/ 8' ) );
	}
}
