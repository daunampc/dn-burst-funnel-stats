<?php
/**
 * UTM parsing, referrer hosts and traffic channel classification.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_truncate( $value, $length ) {
	return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, 0, $length, 'UTF-8' ) : substr( (string) $value, 0, $length );
}

function dn_bfs_normalize_host( $host ) {
	$host = strtolower( trim( (string) $host ) );

	return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
}

function dn_bfs_referrer_host( $referrer ) {
	$referrer = trim( (string) $referrer );

	if ( ! preg_match( '#^https?://#i', $referrer ) ) {
		return '';
	}

	$host = wp_parse_url( $referrer, PHP_URL_HOST );

	return is_string( $host ) ? dn_bfs_normalize_host( $host ) : '';
}

function dn_bfs_extract_utm( $query ) {
	$params = array();
	parse_str( ltrim( (string) $query, '?' ), $params );

	$utm = array();

	foreach ( array( 'source', 'medium', 'campaign', 'content', 'term' ) as $key ) {
		$value = isset( $params[ 'utm_' . $key ] ) && is_scalar( $params[ 'utm_' . $key ] ) ? (string) $params[ 'utm_' . $key ] : '';
		$value = dn_bfs_truncate( sanitize_text_field( $value ), 191 );

		$utm[ $key ] = in_array( $key, array( 'source', 'medium' ), true ) ? strtolower( $value ) : $value;
	}

	$utm['paid_click'] = false;

	foreach ( array( 'gclid', 'gbraid', 'wbraid', 'msclkid', 'ttclid' ) as $click_id ) {
		if ( ! empty( $params[ $click_id ] ) ) {
			$utm['paid_click'] = true;
		}
	}

	return $utm;
}

function dn_bfs_channel_host_matches( $host, $patterns ) {
	foreach ( $patterns as $pattern ) {
		if ( preg_match( $pattern, $host ) ) {
			return true;
		}
	}

	return false;
}

function dn_bfs_channel_search_patterns() {
	return array( '/(^|\.)google\./', '/(^|\.)bing\.com$/', '/(^|\.)yahoo\./', '/(^|\.)duckduckgo\.com$/', '/(^|\.)baidu\.com$/', '/(^|\.)yandex\./', '/(^|\.)coccoc\.com$/', '/(^|\.)ecosia\.org$/', '/(^|\.)naver\.com$/' );
}

function dn_bfs_channel_social_patterns() {
	return array( '/(^|\.)facebook\.com$/', '/(^|\.)fb\.com$/', '/(^|\.)instagram\.com$/', '/^t\.co$/', '/(^|\.)twitter\.com$/', '/^x\.com$/', '/(^|\.)linkedin\.com$/', '/^lnkd\.in$/', '/(^|\.)pinterest\./', '/(^|\.)tiktok\.com$/', '/(^|\.)youtube\.com$/', '/(^|\.)reddit\.com$/', '/(^|\.)zalo\.me$/', '/(^|\.)threads\.net$/' );
}

function dn_bfs_classify_channel( $utm, $referrer_host, $site_host ) {
	$medium = isset( $utm['medium'] ) ? (string) $utm['medium'] : '';
	$source = isset( $utm['source'] ) ? (string) $utm['source'] : '';

	if ( in_array( $medium, array( 'cpc', 'ppc', 'cpm', 'cpv', 'paid', 'display', 'ads', 'banner' ), true ) || 0 === strpos( $medium, 'paid' ) || ! empty( $utm['paid_click'] ) ) {
		return 'paid';
	}

	if ( in_array( $medium, array( 'email', 'e-mail', 'e_mail', 'newsletter' ), true ) ) {
		return 'email';
	}

	if ( in_array( $medium, array( 'social', 'social-network', 'social-media', 'sm', 'social_network', 'social_media' ), true ) ) {
		return 'social';
	}

	if ( 'organic' === $medium ) {
		return 'organic_search';
	}

	if ( '' !== $source ) {
		if ( preg_match( '/facebook|^fb$|instagram|tiktok|zalo|youtube|twitter|^x$|linkedin|pinterest|threads/', $source ) ) {
			return 'social';
		}

		if ( preg_match( '/google|bing|yahoo|duckduckgo|coccoc|baidu|yandex/', $source ) && '' === $medium ) {
			return 'organic_search';
		}

		return 'referral';
	}

	$referrer_host = dn_bfs_normalize_host( $referrer_host );

	if ( '' === $referrer_host || dn_bfs_normalize_host( $site_host ) === $referrer_host ) {
		return 'direct';
	}

	if ( dn_bfs_channel_host_matches( $referrer_host, dn_bfs_channel_search_patterns() ) ) {
		return 'organic_search';
	}

	if ( dn_bfs_channel_host_matches( $referrer_host, dn_bfs_channel_social_patterns() ) ) {
		return 'social';
	}

	return 'referral';
}
