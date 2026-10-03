<?php

use PHPUnit\Framework\TestCase;

class UaParserTest extends TestCase {
	/**
	 * @dataProvider agents
	 */
	public function test_parse( $ua, $device, $browser, $os ) {
		$this->assertSame(
			array(
				'device'  => $device,
				'browser' => $browser,
				'os'      => $os,
			),
			dn_bfs_parse_user_agent( $ua )
		);
	}

	public function agents() {
		return array(
			'chrome windows'  => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', 'desktop', 'Chrome', 'Windows' ),
			'edge windows'    => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 Edg/128.0.2739.42', 'desktop', 'Edge', 'Windows' ),
			'safari mac'      => array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15', 'desktop', 'Safari', 'macOS' ),
			'firefox linux'   => array( 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'desktop', 'Firefox', 'Linux' ),
			'iphone safari'   => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', 'mobile', 'Safari', 'iOS' ),
			'iphone chrome'   => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/128.0.6613.98 Mobile/15E148 Safari/604.1', 'mobile', 'Chrome', 'iOS' ),
			'ipad'            => array( 'Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', 'tablet', 'Safari', 'iOS' ),
			'android chrome'  => array( 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36', 'mobile', 'Chrome', 'Android' ),
			'android tablet'  => array( 'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', 'tablet', 'Chrome', 'Android' ),
			'samsung browser' => array( 'Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36', 'mobile', 'Samsung Internet', 'Android' ),
			'coc coc'         => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) coc_coc_browser/124.0.198 Chrome/118.0.0.0 Safari/537.36', 'desktop', 'Coc Coc', 'Windows' ),
			'facebook iab'    => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/478.0.0.0]', 'mobile', 'Facebook', 'iOS' ),
			'zalo iab'        => array( 'Mozilla/5.0 (Linux; Android 13; SM-A536E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36 Zalo android/12100', 'mobile', 'Zalo', 'Android' ),
			'opera'           => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 OPR/113.0.0.0', 'desktop', 'Opera', 'Windows' ),
			'chromeos'        => array( 'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', 'desktop', 'Chrome', 'ChromeOS' ),
			'empty'           => array( '', 'desktop', 'Other', 'Other' ),
		);
	}
}
