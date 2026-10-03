<?php

use PHPUnit\Framework\TestCase;

class ChannelTest extends TestCase {
	public function test_hosts() {
		$this->assertSame( 'shop.example.com', dn_bfs_normalize_host( 'WWW.Shop.Example.com' ) );
		$this->assertSame( 'google.com.vn', dn_bfs_referrer_host( 'https://www.google.com.vn/search?q=x' ) );
		$this->assertSame( '', dn_bfs_referrer_host( 'android-app://com.google.android.gm/' ) );
		$this->assertSame( '', dn_bfs_referrer_host( '' ) );
	}

	public function test_extract_utm() {
		$utm = dn_bfs_extract_utm( '?utm_source=Facebook&utm_medium=CPC&utm_campaign=Sale-10&utm_content=v1&utm_term=ao%20thun&fbclid=abc' );

		$this->assertSame( 'facebook', $utm['source'] );
		$this->assertSame( 'cpc', $utm['medium'] );
		$this->assertSame( 'Sale-10', $utm['campaign'] );
		$this->assertSame( 'v1', $utm['content'] );
		$this->assertSame( 'ao thun', $utm['term'] );
		$this->assertTrue( $utm['paid_click'] );

		$empty = dn_bfs_extract_utm( '' );
		$this->assertSame( '', $empty['campaign'] );
		$this->assertFalse( $empty['paid_click'] );
	}

	/**
	 * @dataProvider channels
	 */
	public function test_classify( $query, $ref_host, $expected ) {
		$this->assertSame( $expected, dn_bfs_classify_channel( dn_bfs_extract_utm( $query ), $ref_host, 'shop.example.com' ) );
	}

	public function channels() {
		return array(
			'direct'            => array( '', '', 'direct' ),
			'self referral'     => array( '', 'shop.example.com', 'direct' ),
			'google organic'    => array( '', 'google.com.vn', 'organic_search' ),
			'coccoc organic'    => array( '', 'coccoc.com', 'organic_search' ),
			'facebook organic'  => array( '', 'l.facebook.com', 'social' ),
			'tiktok organic'    => array( '', 'tiktok.com', 'social' ),
			'referral'          => array( '', 'blog.other.vn', 'referral' ),
			'utm cpc'           => array( '?utm_source=google&utm_medium=cpc', 'google.com', 'paid' ),
			'utm paid_social'   => array( '?utm_source=fb&utm_medium=paid_social', '', 'paid' ),
			'gclid only'        => array( '?gclid=xyz', 'google.com', 'paid' ),
			'utm email'         => array( '?utm_source=newsletter&utm_medium=email', '', 'email' ),
			'utm social'        => array( '?utm_source=zalo&utm_medium=social', '', 'social' ),
			'utm source social' => array( '?utm_source=instagram', '', 'social' ),
			'utm organic'       => array( '?utm_source=bing&utm_medium=organic', '', 'organic_search' ),
			'utm unknown'       => array( '?utm_source=partner&utm_medium=banner-x', '', 'referral' ),
		);
	}
}
