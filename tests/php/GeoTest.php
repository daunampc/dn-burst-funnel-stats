<?php

use PHPUnit\Framework\TestCase;

class GeoTest extends TestCase {
	private $db;

	protected function setUp(): void {
		$this->db = __DIR__ . '/fixtures/GeoIP2-City-Test.mmdb';
	}

	public function test_headers() {
		$this->assertSame( array( 'country' => 'VN', 'city' => 'Hanoi' ), dn_bfs_geo_from_headers( array( 'HTTP_CF_IPCOUNTRY' => 'vn', 'HTTP_CF_IPCITY' => 'Hanoi' ) ) );
		$this->assertSame( array( 'country' => '', 'city' => '' ), dn_bfs_geo_from_headers( array( 'HTTP_CF_IPCOUNTRY' => 'XX' ) ) );
		$this->assertSame( array( 'country' => '', 'city' => '' ), dn_bfs_geo_from_headers( array( 'HTTP_CF_IPCOUNTRY' => 'T1' ) ) );
		$this->assertSame( array( 'country' => '', 'city' => '' ), dn_bfs_geo_from_headers( array() ) );
	}

	public function test_mmdb_lookup() {
		$this->assertSame( array( 'country' => 'GB', 'city' => 'London' ), dn_bfs_geo_from_mmdb( '81.2.69.142', $this->db ) );
		$this->assertSame( array( 'country' => '', 'city' => '' ), dn_bfs_geo_from_mmdb( '127.0.0.1', $this->db ) );
		$this->assertSame( array( 'country' => '', 'city' => '' ), dn_bfs_geo_from_mmdb( '81.2.69.142', '/nope.mmdb' ) );
	}

	public function test_lookup_prefers_cloudflare_and_fills_city_from_mmdb() {
		$settings = array( 'prefer_cloudflare' => 1 );

		$this->assertSame(
			array( 'country' => 'GB', 'city' => 'London' ),
			dn_bfs_geo_lookup( '81.2.69.142', array( 'HTTP_CF_IPCOUNTRY' => 'GB' ), $settings, $this->db )
		);
		$this->assertSame(
			array( 'country' => 'VN', 'city' => '' ),
			dn_bfs_geo_lookup( '81.2.69.142', array( 'HTTP_CF_IPCOUNTRY' => 'VN' ), $settings, $this->db )
		);
		$this->assertSame(
			array( 'country' => 'GB', 'city' => 'London' ),
			dn_bfs_geo_lookup( '81.2.69.142', array( 'HTTP_CF_IPCOUNTRY' => 'VN' ), array( 'prefer_cloudflare' => 0 ), $this->db )
		);
	}

	public function test_header_city_is_truncated_on_character_boundaries() {
		$geo = dn_bfs_geo_from_headers( array( 'HTTP_CF_IPCOUNTRY' => 'VN', 'HTTP_CF_IPCITY' => str_repeat( 'Hà', 60 ) ) );

		$this->assertSame( 100, mb_strlen( $geo['city'], 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $geo['city'], 'UTF-8' ) );
	}
}
