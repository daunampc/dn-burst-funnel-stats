<?php

function dn_bfs_it_geoip_fixture() {
	static $archive = null;

	if ( null === $archive ) {
		$dir = get_temp_dir() . 'dnbfs-geo-' . wp_generate_password( 6, false );
		wp_mkdir_p( $dir );
		$tar = new PharData( $dir . '/GeoLite2-City.tar' );
		$tar->addFile( dirname( __DIR__ ) . '/php/fixtures/GeoIP2-City-Test.mmdb', 'GeoLite2-City_20261001/GeoLite2-City.mmdb' );
		$tar->compress( Phar::GZ );
		$archive = $dir . '/GeoLite2-City.tar.gz';
	}

	return $archive;
}

function dn_bfs_it_mock_geoip_http( $checksum_override = null ) {
	$archive  = dn_bfs_it_geoip_fixture();
	$checksum = null === $checksum_override ? hash_file( 'sha256', $archive ) : $checksum_override;

	$GLOBALS['dn_bfs_it_geo_url'] = function ( $url, $suffix ) {
		unset( $url );
		return 'https://geoip.example.test/' . $suffix;
	};

	$GLOBALS['dn_bfs_it_geo_http'] = function ( $pre, $args, $url ) use ( $archive, $checksum ) {
		if ( 0 !== strpos( $url, 'https://geoip.example.test/' ) ) {
			return $pre;
		}

		$response = array(
			'headers'  => array(),
			'body'     => '',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => isset( $args['filename'] ) ? $args['filename'] : null,
		);

		if ( '.sha256' === substr( $url, -7 ) ) {
			$response['body'] = $checksum . '  GeoLite2-City_20261001.tar.gz';
		} elseif ( ! empty( $args['filename'] ) ) {
			copy( $archive, $args['filename'] );
		}

		return $response;
	};

	add_filter( 'dn_bfs_geoip_download_url', $GLOBALS['dn_bfs_it_geo_url'], 10, 2 );
	add_filter( 'pre_http_request', $GLOBALS['dn_bfs_it_geo_http'], 10, 3 );
}

function dn_bfs_it_unmock_geoip_http() {
	remove_filter( 'dn_bfs_geoip_download_url', $GLOBALS['dn_bfs_it_geo_url'], 10 );
	remove_filter( 'pre_http_request', $GLOBALS['dn_bfs_it_geo_http'], 10 );

	if ( file_exists( dn_bfs_geo_db_path() ) ) {
		unlink( dn_bfs_geo_db_path() );
	}

	delete_option( 'dnbfs_geoip_updated_at' );
	delete_option( 'dnbfs_geoip_last_error' );
}

dn_bfs_it(
	'geoip update downloads, verifies and installs the database',
	function () {
		dn_bfs_it_mock_geoip_http();

		$result = dn_bfs_geoip_update( 'TESTKEY123' );

		dn_bfs_assert_true( $result['ok'], 'ok: ' . $result['reason'] );
		dn_bfs_assert_same( array( 'country' => 'GB', 'city' => 'London' ), dn_bfs_geo_from_mmdb( '81.2.69.142', dn_bfs_geo_db_path() ) );
		dn_bfs_assert_true( (int) get_option( 'dnbfs_geoip_updated_at' ) > 0, 'timestamp stored' );
		dn_bfs_assert_same( array(), glob( dirname( dn_bfs_geo_db_path() ) . '/download-*' ) );

		dn_bfs_it_unmock_geoip_http();
	}
);

dn_bfs_it(
	'geoip update rejects a checksum mismatch and keeps the old database',
	function () {
		dn_bfs_it_mock_geoip_http( str_repeat( 'a', 64 ) );

		$result = dn_bfs_geoip_update( 'TESTKEY123' );

		dn_bfs_assert_same( 'checksum_mismatch', $result['reason'] );
		dn_bfs_assert_true( ! file_exists( dn_bfs_geo_db_path() ), 'not installed' );
		dn_bfs_assert_same( 'checksum_mismatch', get_option( 'dnbfs_geoip_last_error' ) );

		dn_bfs_it_unmock_geoip_http();
	}
);

dn_bfs_it(
	'geoip auto update needs a license key and runs at most every 30 days',
	function () {
		dn_bfs_it_mock_geoip_http();

		dn_bfs_assert_same( 'no_license', dn_bfs_geoip_update( '' )['reason'] );

		dn_bfs_maybe_update_geoip( time() );
		dn_bfs_assert_true( ! file_exists( dn_bfs_geo_db_path() ), 'no key, no download' );

		dn_bfs_it_settings( array( 'maxmind_license_key' => 'TESTKEY123' ) );
		update_option( 'dnbfs_geoip_updated_at', time() - DAY_IN_SECONDS, false );
		dn_bfs_maybe_update_geoip( time() );
		dn_bfs_assert_true( ! file_exists( dn_bfs_geo_db_path() ), 'too recent' );

		update_option( 'dnbfs_geoip_updated_at', time() - 31 * DAY_IN_SECONDS, false );
		dn_bfs_maybe_update_geoip( time() );
		dn_bfs_assert_true( file_exists( dn_bfs_geo_db_path() ), 'updated after 30 days' );

		dn_bfs_it_unmock_geoip_http();
	}
);
