<?php

use PHPUnit\Framework\TestCase;

class ApiKeysTest extends TestCase {
	public function test_parse_key_accepts_only_the_documented_format() {
		$key = 'dnbfs_ab12cd34_' . str_repeat( 'Zz9', 10 ) . 'Q1';

		$this->assertSame( array( 'prefix' => 'ab12cd34', 'secret' => str_repeat( 'Zz9', 10 ) . 'Q1' ), dn_bfs_api_parse_key( $key ) );
		$this->assertFalse( dn_bfs_api_parse_key( 'dnbfs_AB12CD34_' . str_repeat( 'a', 32 ) ) );
		$this->assertFalse( dn_bfs_api_parse_key( 'dnbfs_ab12cd34_' . str_repeat( 'a', 31 ) ) );
		$this->assertFalse( dn_bfs_api_parse_key( 'dnbfs_ab12cd3_' . str_repeat( 'a', 32 ) ) );
		$this->assertFalse( dn_bfs_api_parse_key( 'key_ab12cd34_' . str_repeat( 'a', 32 ) ) );
		$this->assertFalse( dn_bfs_api_parse_key( ' dnbfs_ab12cd34_' . str_repeat( 'a', 32 ) ) );
		$this->assertFalse( dn_bfs_api_parse_key( null ) );
	}

	public function test_parse_scopes_filters_and_orders() {
		$this->assertSame( array( 'stats:read', 'realtime:read' ), dn_bfs_api_parse_scopes( 'realtime:read, stats:read,bogus' ) );
		$this->assertSame( array( 'realtime:read' ), dn_bfs_api_parse_scopes( array( 'realtime:read', array( 'x' ) ) ) );
		$this->assertSame( array(), dn_bfs_api_parse_scopes( '' ) );
	}
}
