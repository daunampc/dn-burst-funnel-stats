<?php
/**
 * Lightweight user-agent parser for device, browser and OS reports.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_parse_user_agent( $user_agent ) {
	$ua = (string) $user_agent;

	return array(
		'device'  => dn_bfs_ua_device( $ua ),
		'browser' => dn_bfs_ua_browser( $ua ),
		'os'      => dn_bfs_ua_os( $ua ),
	);
}

function dn_bfs_ua_device( $ua ) {
	if ( preg_match( '/iPad|Tablet|Kindle|Silk|PlayBook/i', $ua ) ) {
		return 'tablet';
	}

	if ( preg_match( '/Android/i', $ua ) && ! preg_match( '/Mobile/i', $ua ) ) {
		return 'tablet';
	}

	if ( preg_match( '/Mobi|iPhone|iPod|Android|Windows Phone|BlackBerry|Opera Mini/i', $ua ) ) {
		return 'mobile';
	}

	return 'desktop';
}

function dn_bfs_ua_browser( $ua ) {
	$rules = array(
		'Facebook'         => '/FBAN|FBAV/',
		'Instagram'        => '/Instagram/',
		'Zalo'             => '/Zalo/i',
		'Edge'             => '/Edg\/|Edge\/|EdgA\/|EdgiOS\//',
		'Opera'            => '/OPR\/|Opera/',
		'Samsung Internet' => '/SamsungBrowser/',
		'Coc Coc'          => '/coc_coc_browser/i',
		'UC Browser'       => '/UCBrowser/',
		'Firefox'          => '/Firefox\/|FxiOS\//',
		'Chrome'           => '/Chrome\/|CriOS\//',
		'Safari'           => '/Version\/[\d.]+.*Safari\//',
	);

	foreach ( $rules as $name => $pattern ) {
		if ( preg_match( $pattern, $ua ) ) {
			return $name;
		}
	}

	return 'Other';
}

function dn_bfs_ua_os( $ua ) {
	$rules = array(
		'Windows'  => '/Windows NT|Windows Phone/',
		'iOS'      => '/iPhone|iPad|iPod/',
		'macOS'    => '/Macintosh|Mac OS X/',
		'Android'  => '/Android/',
		'ChromeOS' => '/CrOS/',
		'Linux'    => '/Linux/',
	);

	foreach ( $rules as $name => $pattern ) {
		if ( preg_match( $pattern, $ua ) ) {
			return $name;
		}
	}

	return 'Other';
}
