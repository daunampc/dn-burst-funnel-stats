<?php
/**
 * Database tables for native tracking.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_table( $name ) {
	global $wpdb;

	return $wpdb->prefix . 'dnbfs_' . $name;
}

function dn_bfs_install_schema() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$tables  = array();

	$tables[] = 'CREATE TABLE ' . dn_bfs_table( 'visitors' ) . " (
  visitor_uid char(32) NOT NULL,
  first_seen int(10) unsigned NOT NULL DEFAULT 0,
  last_seen int(10) unsigned NOT NULL DEFAULT 0,
  sessions_count int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (visitor_uid),
  KEY last_seen (last_seen)
) {$charset};";

	$tables[] = 'CREATE TABLE ' . dn_bfs_table( 'sessions' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_uid char(32) NOT NULL,
  visitor_uid char(32) NOT NULL,
  started_at int(10) unsigned NOT NULL DEFAULT 0,
  last_activity int(10) unsigned NOT NULL DEFAULT 0,
  is_new_visitor tinyint(1) unsigned NOT NULL DEFAULT 0,
  entry_path varchar(255) NOT NULL DEFAULT '',
  exit_path varchar(255) NOT NULL DEFAULT '',
  pageviews smallint(5) unsigned NOT NULL DEFAULT 0,
  duration int(10) unsigned NOT NULL DEFAULT 0,
  is_bounce tinyint(1) unsigned NOT NULL DEFAULT 1,
  referrer_host varchar(191) NOT NULL DEFAULT '',
  channel varchar(20) NOT NULL DEFAULT '',
  utm_source varchar(191) NOT NULL DEFAULT '',
  utm_medium varchar(191) NOT NULL DEFAULT '',
  utm_campaign varchar(191) NOT NULL DEFAULT '',
  utm_content varchar(191) NOT NULL DEFAULT '',
  utm_term varchar(191) NOT NULL DEFAULT '',
  device varchar(10) NOT NULL DEFAULT '',
  browser varchar(40) NOT NULL DEFAULT '',
  os varchar(40) NOT NULL DEFAULT '',
  country char(2) NOT NULL DEFAULT '',
  city varchar(100) NOT NULL DEFAULT '',
  ip_hash char(64) NOT NULL DEFAULT '',
  is_spam tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY session_uid (session_uid),
  KEY visitor_uid (visitor_uid),
  KEY started_at (started_at),
  KEY last_activity (last_activity),
  KEY ip_started (ip_hash,started_at),
  KEY is_spam (is_spam)
) {$charset};";

	$tables[] = 'CREATE TABLE ' . dn_bfs_table( 'pageviews' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint(20) unsigned NOT NULL DEFAULT 0,
  visitor_uid char(32) NOT NULL DEFAULT '',
  time int(10) unsigned NOT NULL DEFAULT 0,
  path varchar(255) NOT NULL DEFAULT '',
  page_type varchar(12) NOT NULL DEFAULT 'other',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  time_on_page int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY session_time (session_id,time),
  KEY time (time)
) {$charset};";

	$tables[] = 'CREATE TABLE ' . dn_bfs_table( 'events' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint(20) unsigned NOT NULL DEFAULT 0,
  visitor_uid char(32) NOT NULL DEFAULT '',
  time int(10) unsigned NOT NULL DEFAULT 0,
  type varchar(16) NOT NULL DEFAULT '',
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  qty int(10) unsigned NOT NULL DEFAULT 0,
  value decimal(19,4) NOT NULL DEFAULT 0,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned DEFAULT NULL,
  channel varchar(20) NOT NULL DEFAULT '',
  utm_source varchar(191) NOT NULL DEFAULT '',
  utm_medium varchar(191) NOT NULL DEFAULT '',
  utm_campaign varchar(191) NOT NULL DEFAULT '',
  country char(2) NOT NULL DEFAULT '',
  device varchar(10) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY order_id (order_id),
  KEY visitor_product (visitor_uid,product_id,type,time),
  KEY product_type_time (product_id,type,time),
  KEY session_type (session_id,type),
  KEY type_time (type,time)
) {$charset};";

	$tables[] = 'CREATE TABLE ' . dn_bfs_table( 'daily' ) . " (
  date date NOT NULL,
  dimension varchar(16) NOT NULL,
  dim_hash char(32) NOT NULL,
  dim_value varchar(255) NOT NULL DEFAULT '',
  pageviews int(10) unsigned NOT NULL DEFAULT 0,
  visitors int(10) unsigned NOT NULL DEFAULT 0,
  sessions int(10) unsigned NOT NULL DEFAULT 0,
  new_visitors int(10) unsigned NOT NULL DEFAULT 0,
  bounces int(10) unsigned NOT NULL DEFAULT 0,
  duration_sum bigint(20) unsigned NOT NULL DEFAULT 0,
  product_views int(10) unsigned NOT NULL DEFAULT 0,
  atc int(10) unsigned NOT NULL DEFAULT 0,
  carts int(10) unsigned NOT NULL DEFAULT 0,
  checkouts int(10) unsigned NOT NULL DEFAULT 0,
  orders int(10) unsigned NOT NULL DEFAULT 0,
  revenue decimal(19,4) NOT NULL DEFAULT 0,
  PRIMARY KEY  (date,dimension,dim_hash),
  KEY dimension_date (dimension,date)
) {$charset};";

	$tables[] = 'CREATE TABLE ' . dn_bfs_table( 'api_keys' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL DEFAULT '',
  prefix char(8) NOT NULL,
  key_hash char(64) NOT NULL,
  scopes varchar(191) NOT NULL DEFAULT '',
  allowed_ips text NOT NULL,
  rate_limit smallint(5) unsigned NOT NULL DEFAULT 60,
  last_used_at int(10) unsigned NOT NULL DEFAULT 0,
  created_at int(10) unsigned NOT NULL DEFAULT 0,
  revoked_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY prefix (prefix)
) {$charset};";

	foreach ( $tables as $sql ) {
		dbDelta( $sql );
	}
}
