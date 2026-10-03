<?php
/**
 * Downloads and installs the MaxMind GeoLite2-City database.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_geoip_download_url( $license_key, $suffix ) {
	$url = add_query_arg(
		array(
			'edition_id'  => 'GeoLite2-City',
			'license_key' => rawurlencode( $license_key ),
			'suffix'      => $suffix,
		),
		'https://download.maxmind.com/app/geoip_download'
	);

	return (string) apply_filters( 'dn_bfs_geoip_download_url', $url, $suffix );
}

function dn_bfs_geoip_fail( $reason, $files = array() ) {
	foreach ( (array) $files as $file ) {
		if ( $file && file_exists( $file ) ) {
			unlink( $file );
		}
	}

	update_option( 'dnbfs_geoip_last_error', $reason, false );

	return array(
		'ok'     => false,
		'reason' => $reason,
	);
}

function dn_bfs_geoip_update( $license_key ) {
	$license_key = (string) $license_key;

	if ( '' === $license_key ) {
		return dn_bfs_geoip_fail( 'no_license' );
	}

	$target = dn_bfs_geo_db_path();
	$dir    = dirname( $target );
	wp_mkdir_p( $dir );

	if ( ! wp_is_writable( $dir ) ) {
		return dn_bfs_geoip_fail( 'write_failed' );
	}

	update_option( 'dnbfs_geoip_attempted_at', time(), false );

	// Reclaim orphaned downloads and temp files left by interrupted runs.
	foreach ( array_merge( (array) glob( $dir . '/download-*' ), (array) glob( $dir . '/*.tmp' ) ) as $orphan ) {
		if ( is_file( $orphan ) && filemtime( $orphan ) < time() - HOUR_IN_SECONDS ) {
			unlink( $orphan );
		}
	}

	$archive  = $dir . '/download-' . wp_generate_password( 8, false ) . '.tar.gz';
	$response = wp_safe_remote_get(
		dn_bfs_geoip_download_url( $license_key, 'tar.gz' ),
		array(
			'timeout'  => 300,
			'stream'   => true,
			'filename' => $archive,
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! file_exists( $archive ) ) {
		return dn_bfs_geoip_fail( 'download_failed', array( $archive ) );
	}

	$checksum = wp_safe_remote_get( dn_bfs_geoip_download_url( $license_key, 'tar.gz.sha256' ), array( 'timeout' => 30 ) );

	if ( is_wp_error( $checksum ) || 200 !== (int) wp_remote_retrieve_response_code( $checksum ) ) {
		return dn_bfs_geoip_fail( 'download_failed', array( $archive ) );
	}

	$expected = strtolower( (string) strtok( trim( wp_remote_retrieve_body( $checksum ) ), " \t" ) );

	if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $expected, hash_file( 'sha256', $archive ) ) ) {
		return dn_bfs_geoip_fail( 'checksum_mismatch', array( $archive ) );
	}

	$found = '';

	try {
		$phar = new PharData( $archive );

		foreach ( new RecursiveIteratorIterator( $phar ) as $file ) {
			if ( 'GeoLite2-City.mmdb' === basename( $file->getPathname() ) ) {
				$found = $file->getPathname();
				break;
			}
		}
		unset( $phar );
	} catch ( \Throwable $e ) {
		unset( $phar );
		return dn_bfs_geoip_fail( 'extract_failed', array( $archive ) );
	}

	if ( '' === $found ) {
		return dn_bfs_geoip_fail( 'mmdb_missing', array( $archive ) );
	}

	$temp = $target . '.' . wp_generate_password( 8, false ) . '.tmp';

	if ( ! copy( $found, $temp ) ) {
		return dn_bfs_geoip_fail( 'write_failed', array( $archive, $temp ) );
	}

	try {
		require_once dirname( __DIR__, 2 ) . '/lib/maxmind-db/autoload.php';
		$reader = new \MaxMind\Db\Reader( $temp );
		$reader->close();
	} catch ( \Throwable $e ) {
		return dn_bfs_geoip_fail( 'invalid_database', array( $archive, $temp ) );
	}

	if ( ! rename( $temp, $target ) ) {
		return dn_bfs_geoip_fail( 'write_failed', array( $archive, $temp ) );
	}

	unlink( $archive );
	update_option( 'dnbfs_geoip_updated_at', time(), false );
	delete_option( 'dnbfs_geoip_last_error' );

	return array(
		'ok'     => true,
		'reason' => '',
	);
}

function dn_bfs_maybe_update_geoip( $now ) {
	$settings = dn_bfs_get_tracking_settings();

	if ( '' === $settings['maxmind_license_key'] ) {
		return;
	}

	if ( (int) $now - (int) get_option( 'dnbfs_geoip_updated_at', 0 ) < 30 * DAY_IN_SECONDS ) {
		return;
	}

	if ( (int) $now - (int) get_option( 'dnbfs_geoip_attempted_at', 0 ) < DAY_IN_SECONDS ) {
		return;
	}

	dn_bfs_geoip_update( $settings['maxmind_license_key'] );
}
