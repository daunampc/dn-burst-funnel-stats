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

/**
 * Query parameters that carry a campaign, in priority order. tracker.js and
 * the WooCommerce cookie mirror use the same order for the session campaign key.
 */
function dn_bfs_campaign_params() {
	return array( 'utm_campaign', 'utm_id', 'gad_campaignid', 'campaign_id', 'hsa_cam' );
}

/**
 * Ad-network click ids that mark a paid click, mapped to the source used when
 * utm_source is missing (auto-tagged ads carry no utm_* parameters).
 */
function dn_bfs_paid_click_params() {
	// gad_source is added by Google Ads to ad clicks only, including iOS clicks without gclid.
	return array(
		'gclid'      => 'google',
		'gbraid'     => 'google',
		'wbraid'     => 'google',
		'gad_source' => 'google',
		'msclkid'    => 'bing',
		'ttclid'     => 'tiktok',
		'twclid'     => 'twitter',
		'li_fat_id'  => 'linkedin',
	);
}

function dn_bfs_query_param( $params, $key ) {
	return isset( $params[ $key ] ) && is_scalar( $params[ $key ] ) ? (string) $params[ $key ] : '';
}

/**
 * Raw (unsanitized) campaign key of a parsed query: the first non-empty
 * campaign parameter. Must match campaignKey() in tracker.js.
 */
function dn_bfs_campaign_key( $params ) {
	foreach ( dn_bfs_campaign_params() as $key ) {
		$value = dn_bfs_query_param( $params, $key );

		if ( '' !== $value ) {
			return $value;
		}
	}

	return '';
}

/**
 * Raw value of the first paid click id in a parsed query, truncated like
 * clickId() in tracker.js.
 */
function dn_bfs_paid_click_value( $params ) {
	foreach ( array_keys( dn_bfs_paid_click_params() ) as $key ) {
		$value = dn_bfs_query_param( $params, $key );

		if ( '' !== $value ) {
			return substr( $value, 0, 100 );
		}
	}

	return '';
}

function dn_bfs_extract_utm( $query ) {
	$params = array();
	parse_str( ltrim( (string) $query, '?' ), $params );

	$utm = array();

	foreach ( array( 'source', 'medium', 'campaign', 'content', 'term' ) as $key ) {
		$value = 'campaign' === $key ? dn_bfs_campaign_key( $params ) : dn_bfs_query_param( $params, 'utm_' . $key );
		$value = dn_bfs_truncate( sanitize_text_field( $value ), 191 );

		$utm[ $key ] = in_array( $key, array( 'source', 'medium' ), true ) ? strtolower( $value ) : $value;
	}

	$utm['paid_click'] = false;
	$click_source      = '';

	foreach ( dn_bfs_paid_click_params() as $click_id => $source ) {
		if ( '' !== dn_bfs_query_param( $params, $click_id ) ) {
			$utm['paid_click'] = true;
			$click_source      = $source;
			break;
		}
	}

	if ( '' === $utm['source'] ) {
		if ( '' !== $click_source ) {
			$utm['source'] = $click_source;
		} elseif ( '' !== dn_bfs_query_param( $params, 'fbclid' ) ) {
			// fbclid is also added to organic Facebook links, so it is not a paid click.
			$utm['source'] = 'facebook';
		}
	}

	if ( '' === $utm['medium'] && $utm['paid_click'] ) {
		$utm['medium'] = 'cpc';
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
