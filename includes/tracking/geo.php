<?php
/**
 * Visitor location from Cloudflare headers or a local GeoLite2 database.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_geo_empty() {
	return array(
		'country' => '',
		'city'    => '',
	);
}

function dn_bfs_geo_from_headers( $server ) {
	$geo     = dn_bfs_geo_empty();
	$country = isset( $server['HTTP_CF_IPCOUNTRY'] ) ? strtoupper( trim( (string) $server['HTTP_CF_IPCOUNTRY'] ) ) : '';

	if ( preg_match( '/^[A-Z]{2}$/', $country ) && ! in_array( $country, array( 'XX', 'T1' ), true ) ) {
		$geo['country'] = $country;
		$geo['city']    = isset( $server['HTTP_CF_IPCITY'] ) ? substr( sanitize_text_field( (string) $server['HTTP_CF_IPCITY'] ), 0, 100 ) : '';
	}

	return $geo;
}

function dn_bfs_geo_db_path() {
	$uploads = wp_upload_dir( null, false );

	return trailingslashit( $uploads['basedir'] ) . 'dnbfs/GeoLite2-City.mmdb';
}

function dn_bfs_geo_from_mmdb( $ip, $path ) {
	static $readers = array();

	$geo = dn_bfs_geo_empty();

	if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) || ! is_readable( $path ) ) {
		return $geo;
	}

	try {
		if ( ! isset( $readers[ $path ] ) ) {
			require_once dirname( __DIR__, 2 ) . '/lib/maxmind-db/autoload.php';
			$readers[ $path ] = new \MaxMind\Db\Reader( $path );
		}

		$record = $readers[ $path ]->get( $ip );
	} catch ( \Throwable $e ) {
		return $geo;
	}

	if ( is_array( $record ) ) {
		$geo['country'] = isset( $record['country']['iso_code'] ) ? (string) $record['country']['iso_code'] : '';
		$geo['city']    = isset( $record['city']['names']['en'] ) ? substr( (string) $record['city']['names']['en'], 0, 100 ) : '';
	}

	return $geo;
}

function dn_bfs_geo_lookup( $ip, $server, $settings, $db_path = null ) {
	$db_path = null === $db_path ? dn_bfs_geo_db_path() : $db_path;

	if ( ! empty( $settings['prefer_cloudflare'] ) ) {
		$geo = dn_bfs_geo_from_headers( $server );

		if ( '' !== $geo['country'] ) {
			if ( '' === $geo['city'] ) {
				$mmdb = dn_bfs_geo_from_mmdb( $ip, $db_path );

				if ( $mmdb['country'] === $geo['country'] ) {
					$geo['city'] = $mmdb['city'];
				}
			}

			return $geo;
		}
	}

	$geo = dn_bfs_geo_from_mmdb( $ip, $db_path );

	return '' !== $geo['country'] ? $geo : dn_bfs_geo_from_headers( $server );
}
