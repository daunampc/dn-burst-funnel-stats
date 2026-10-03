# Kế hoạch 1 — Thu thập dữ liệu (Native Tracking)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Plugin tự ghi visitors / sessions / pageviews / sự kiện thương mại vào bảng riêng, có chống trùng – bot – spam, không còn phụ thuộc Burst.

**Architecture:** Tracker JS gửi beacon `pv`/`ping` tới REST `dnbfs/v1/collect`; hook WooCommerce ghi Add To Cart (+ Cart) và Order trên server. Mọi request đi qua `guard.php` (hàm thuần, test được không cần WordPress) rồi tới `store.php` (ghi DB bằng `$wpdb`). Kế hoạch 2 sẽ tổng hợp dữ liệu này; dashboard cũ vẫn chạy (hiện cảnh báo thiếu bảng Burst) cho tới kế hoạch 3.

**Tech Stack:** PHP 7.4+ (WordPress 6.5+, WooCommerce), JS thuần (không build), MariaDB 11, Docker Compose, PHPUnit 9.6, WP-CLI `eval-file` cho test tích hợp, MaxMind DB Reader (vendored).

**Spec:** `docs/superpowers/specs/2026-10-03-native-tracking-design.md`

## Global Constraints

- PHP tối thiểu 7.4: không dùng `match`, union type, named args, `str_contains`, enum, readonly.
- Tiền tố hàm mới: `dn_bfs_`. Tên bảng: `{$wpdb->prefix}dnbfs_<name>` qua `dn_bfs_table( $name )`.
- Phong cách code: theo file hiện có — hàm thủ tục, tab để thụt lề, khoảng trắng trong ngoặc kiểu WordPress (`foo( $a )`), docblock ngắn.
- Mọi thời gian lưu dạng epoch UTC (int). Ngày (`Y-m-d`) luôn tính theo `wp_timezone()` (dùng `wp_date()`).
- Không bao giờ lưu IP gốc; chỉ lưu `ip_hash = hash_hmac( 'sha256', $ip . '|' . $user_agent, salt_ngày )`.
- Cookie: `dnbfs_vid` (32 hex, mặc định 365 ngày), `dnbfs_sid` (32 hex, hết hạn sau 30 phút không hoạt động), `dnbfs_sm` (`Y-m-d~campaign`). `path=/`, `SameSite=Lax`, `Secure` khi HTTPS, không `HttpOnly` (JS cần đọc).
- Cửa sổ chống trùng sản phẩm mặc định 300 giây; bỏ qua F5 10 giây; giới hạn: 60 pv/phút/phiên, 20 phiên mới/giờ/ip_hash, 20 ATC/phút/khách, 300 pv/phiên.
- Cart trên phễu luôn bằng Add To Cart: hai sự kiện `add_to_cart` và `cart` được ghi cùng lúc từ server.
- Mọi lệnh chạy qua Docker (máy dev không có PHP/Node). Thư mục làm việc khi chạy lệnh: gốc repo.
- Commit message kết thúc bằng dòng: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Điều chỉnh nhỏ so với spec (đã ghi vào spec ở Task 1)

- Thêm `includes/tracking/context.php` (dựng ngữ cảnh request) và `includes/tracking/store.php` (ghi DB) để `collector.php` và `wc-events.php` dùng chung.
- Thêm setting `client_ip_source` (`auto` | `remote_addr` | `x_forwarded_for` | `x_real_ip`). `auto` = `CF-Connecting-IP` khi có header `CF-Ray`, ngược lại `REMOTE_ADDR`. Lý do: sau Cloudflare, `REMOTE_ADDR` là IP edge của Cloudflare → mọi khách chung một IP → giới hạn "20 phiên/giờ/IP" sẽ đánh dấu spam nhầm.
- Bỏ setting "tham số URL bỏ qua": tracker chỉ gửi `location.pathname` làm `path`, query chỉ dùng để đọc UTM.

## Cấu trúc file

| File | Trách nhiệm |
|---|---|
| `docker/docker-compose.yml`, `docker/setup.sh`, `docker/.env.example` | Môi trường WordPress + WooCommerce + MariaDB + WP-CLI + PHPUnit |
| `composer.json`, `phpunit.xml.dist`, `tests/php/bootstrap.php` | Hạ tầng PHPUnit (stub tối thiểu các hàm WP) |
| `tests/integration/harness.php`, `tests/integration/run.php`, `tests/integration/test-*.php` | Test tích hợp chạy trong WordPress thật qua `wp eval-file` |
| `includes/tracking.php` (sửa) | Settings tracking + giá trị mặc định mới, IP/CIDR, chọn IP client, từ khóa bot |
| `includes/tracking/ua-parser.php` | `dn_bfs_parse_user_agent()` — thuần |
| `includes/tracking/channel.php` | UTM, referrer host, phân loại kênh — thuần |
| `includes/tracking/guard.php` | Kiểm tra payload, loại trừ khách, chọn trang — thuần |
| `includes/tracking/geo.php` | Cloudflare header + MaxMind `.mmdb` |
| `lib/maxmind-db/` | MaxMind DB Reader (Apache-2.0) |
| `includes/tracking/schema.php` | `dn_bfs_table()`, `dn_bfs_install_schema()` (dbDelta 6 bảng) |
| `includes/tracking/context.php` | `dn_bfs_now()`, `dn_bfs_site_host()`, `dn_bfs_ip_hash()`, `dn_bfs_request_context()` |
| `includes/tracking/store.php` | Ghi visitor/session/pageview/ping/event, chống trùng, giới hạn, đánh dấu spam, đếm bị chặn |
| `includes/tracking/collector.php` | REST `/collect`, nạp tracker + `window.dnbfsPage` |
| `assets/tracker.js` | Beacon `pv` / `ping`, cookie, thời gian xem thực |
| `includes/tracking/wc-events.php` | Hook ATC + Cart, Order, ép chuyển đến trang Cart |
| `dn-burst-funnel-stats.php` (sửa) | Nạp file mới, migration schema 4, bỏ phụ thuộc Burst |

---

### Task 1: Môi trường Docker + cập nhật spec

**Files:**
- Create: `docker/docker-compose.yml`, `docker/setup.sh`, `docker/.env.example`, `.gitignore`
- Modify: `docs/superpowers/specs/2026-10-03-native-tracking-design.md`

**Interfaces:**
- Produces: lệnh `docker compose -f docker/docker-compose.yml run --rm wpcli wp …` và `… run --rm phpunit` dùng ở mọi task sau; site `http://localhost:8080` có WooCommerce, trang shop/cart/checkout, 10 sản phẩm, COD, plugin đã kích hoạt.

- [ ] **Step 1: Kiểm tra Docker daemon đang chạy**

Run: `docker info --format '{{.ServerVersion}}'`
Expected: in ra số phiên bản. Nếu báo `Cannot connect to the Docker daemon` → dừng lại, yêu cầu người dùng mở Docker Desktop.

- [ ] **Step 2: Tạo `.gitignore`**

```gitignore
/vendor/
/node_modules/
/docker/.env
/.superpowers/
.phpunit.result.cache
.DS_Store
```

- [ ] **Step 3: Tạo `docker/.env.example`**

```dotenv
# Copy to docker/.env to override. Local test credentials only.
WP_URL=http://localhost:8080
WP_ADMIN_USER=admin
WP_ADMIN_PASSWORD=admin
WP_ADMIN_EMAIL=admin@example.test
```

- [ ] **Step 4: Tạo `docker/docker-compose.yml`**

```yaml
name: dnbfs

x-wp-env: &wp-env
  WORDPRESS_DB_HOST: db
  WORDPRESS_DB_USER: wordpress
  WORDPRESS_DB_PASSWORD: wordpress
  WORDPRESS_DB_NAME: wordpress

services:
  db:
    image: mariadb:11
    environment:
      MARIADB_DATABASE: wordpress
      MARIADB_USER: wordpress
      MARIADB_PASSWORD: wordpress
      MARIADB_ROOT_PASSWORD: root
    volumes:
      - db_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 20

  wordpress:
    image: wordpress:php8.2-apache
    depends_on:
      db:
        condition: service_healthy
    ports:
      - "8080:80"
    environment:
      <<: *wp-env
      WORDPRESS_DEBUG: "1"
    volumes:
      - wp_data:/var/www/html
      - ../:/var/www/html/wp-content/plugins/dn-burst-funnel-stats

  wpcli:
    image: wordpress:cli-php8.2
    profiles: ["tools"]
    user: "33:33"
    depends_on:
      db:
        condition: service_healthy
    environment:
      <<: *wp-env
    working_dir: /var/www/html
    volumes:
      - wp_data:/var/www/html
      - ../:/var/www/html/wp-content/plugins/dn-burst-funnel-stats

  phpunit:
    image: composer:2
    profiles: ["tools"]
    working_dir: /app
    volumes:
      - ../:/app
    entrypoint: ["sh", "-c", "composer install --no-interaction --no-progress --quiet && vendor/bin/phpunit \"$@\"", "--"]

volumes:
  db_data:
  wp_data:
```

- [ ] **Step 5: Tạo `docker/setup.sh` (idempotent)**

```bash
#!/usr/bin/env bash
# Provision a local WordPress + WooCommerce site for testing the plugin.
set -euo pipefail
cd "$(dirname "$0")"

if [ -f .env ]; then set -a; . ./.env; set +a; fi
WP_URL="${WP_URL:-http://localhost:8080}"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-admin}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.test}"

DC="docker compose -f docker-compose.yml"
WP="$DC run --rm wpcli wp"

$DC up -d db wordpress

echo "Waiting for WordPress files and database..."
until $WP db check >/dev/null 2>&1; do sleep 3; done

if ! $WP core is-installed >/dev/null 2>&1; then
  $WP core install --url="$WP_URL" --title="DN BFS Dev" \
    --admin_user="$WP_ADMIN_USER" --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" --skip-email
fi

$WP rewrite structure '/%postname%/' --hard
$WP plugin is-installed woocommerce || $WP plugin install woocommerce
$WP plugin activate woocommerce
$WP option update woocommerce_currency USD
$WP wc tool run install_pages --user="$WP_ADMIN_USER" >/dev/null
$WP option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery"}' --format=json

COUNT=$($WP post list --post_type=product --post_status=publish --format=count)
i=$((COUNT + 1))
while [ "$i" -le 10 ]; do
  $WP wc product create --name="Test product $i" --regular_price="$((i * 10))" --status=publish --user="$WP_ADMIN_USER" --porcelain >/dev/null
  i=$((i + 1))
done

$WP plugin activate dn-burst-funnel-stats
echo "Ready: $WP_URL (wp-admin user: $WP_ADMIN_USER)"
```

Run: `chmod +x docker/setup.sh`

- [ ] **Step 6: Chạy setup và kiểm tra**

Run: `./docker/setup.sh`
Expected: dòng cuối `Ready: http://localhost:8080 (wp-admin user: admin)`. Lưu ý: plugin hiện vẫn yêu cầu Burst Pro nên bước `plugin activate dn-burst-funnel-stats` sẽ **lỗi** ở lần chạy này — chấp nhận được; Task 9 bỏ phụ thuộc Burst và chạy lại setup.

Run: `curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/cart/`
Expected: `200`

Run: `docker compose -f docker/docker-compose.yml run --rm wpcli wp post list --post_type=product --format=count`
Expected: `10`

- [ ] **Step 7: Cập nhật spec theo mục "Điều chỉnh nhỏ so với spec"**

Trong `docs/superpowers/specs/2026-10-03-native-tracking-design.md`:
1. Mục 11, khối cấu trúc file: thêm hai dòng sau `tracking/schema.php`:
```
  tracking/context.php    (ngữ cảnh request: now, host, ip_hash, roles)
  tracking/store.php      (ghi DB, chống trùng, giới hạn, đánh dấu spam)
```
2. Mục 8.4, hàng **Tracking**: thay `tham số URL bỏ qua khi lưu path (mặc định \`fbclid\`, \`gclid\`, \`_ga\`…)` bằng `nguồn IP client (\`auto\` = CF-Connecting-IP khi có CF-Ray, \`remote_addr\`, \`x_forwarded_for\`, \`x_real_ip\`)`.
3. Mục 5, Lớp 2: thêm gạch đầu dòng `- IP client lấy theo setting \`client_ip_source\` (mặc định \`auto\`) để giới hạn theo IP không bị gộp sau Cloudflare/proxy.`

- [ ] **Step 8: Commit**

```bash
git add .gitignore docker docs/superpowers/specs/2026-10-03-native-tracking-design.md
git commit -m "chore: add Docker dev environment for WordPress + WooCommerce

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Hạ tầng test (PHPUnit + test tích hợp)

**Files:**
- Create: `composer.json`, `phpunit.xml.dist`, `tests/php/bootstrap.php`, `tests/php/TrackingCidrTest.php`, `tests/integration/harness.php`, `tests/integration/run.php`

**Interfaces:**
- Consumes: service `phpunit`, `wpcli` từ Task 1.
- Produces:
  - Lệnh unit: `docker compose -f docker/docker-compose.yml run --rm phpunit` (thêm `--filter X` khi cần).
  - Lệnh tích hợp: `docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php` — chạy mọi file `tests/integration/test-*.php`, thoát mã 1 nếu có test fail.
  - Helper tích hợp: `dn_bfs_it( string $name, callable $fn )`, `dn_bfs_assert_same( $expected, $actual, string $msg = '' )`, `dn_bfs_assert_true( $value, string $msg = '' )`, `dn_bfs_it_count( string $table, string $where = '1=1' ): int`, `dn_bfs_it_uid( string $seed ): string` (32 hex), `dn_bfs_it_set_now( int $ts )`, `dn_bfs_it_settings( array $overrides )`, `dn_bfs_it_collect( array $body, array $headers = array() ): WP_REST_Response`, hằng `DN_BFS_IT_UA`.
  - `tests/php/bootstrap.php` nạp `$GLOBALS['dn_bfs_test_options']` làm kho `get_option` giả.

- [ ] **Step 1: Tạo `composer.json`**

```json
{
  "name": "toshstack/dn-burst-funnel-stats",
  "description": "WooCommerce funnel analytics with native tracking.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require-dev": {
    "phpunit/phpunit": "^9.6"
  },
  "config": {
    "platform": {
      "php": "7.4"
    },
    "sort-packages": true
  }
}
```

- [ ] **Step 2: Tạo `phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/php/bootstrap.php" colors="true" failOnWarning="true">
  <testsuites>
    <testsuite name="unit">
      <directory suffix="Test.php">tests/php</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

- [ ] **Step 3: Tạo `tests/php/bootstrap.php`**

```php
<?php
/**
 * Minimal WordPress stubs so pure plugin functions can be unit tested.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['dn_bfs_test_options'] = array();

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['dn_bfs_test_options'] ) ? $GLOBALS['dn_bfs_test_options'][ $key ] : $default;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

function sanitize_text_field( $value ) {
	$value = strip_tags( (string) $value );
	$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );

	return trim( $value );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function trailingslashit( $value ) {
	return untrailingslashit( $value ) . '/';
}

$dn_bfs_root = dirname( __DIR__, 2 );

require_once $dn_bfs_root . '/includes/tracking.php';

foreach ( array( 'ua-parser', 'channel', 'guard', 'geo' ) as $dn_bfs_file ) {
	$dn_bfs_path = $dn_bfs_root . '/includes/tracking/' . $dn_bfs_file . '.php';

	if ( file_exists( $dn_bfs_path ) ) {
		require_once $dn_bfs_path;
	}
}
```

- [ ] **Step 4: Viết test đầu tiên cho hàm có sẵn `dn_bfs_ip_in_cidr` — `tests/php/TrackingCidrTest.php`**

```php
<?php

use PHPUnit\Framework\TestCase;

class TrackingCidrTest extends TestCase {
	public function test_exact_ipv4_match() {
		$this->assertTrue( dn_bfs_ip_in_cidr( '203.0.113.5', '203.0.113.5' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( '203.0.113.6', '203.0.113.5' ) );
	}

	public function test_ipv4_range() {
		$this->assertTrue( dn_bfs_ip_in_cidr( '10.1.2.3', '10.0.0.0/8' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( '11.1.2.3', '10.0.0.0/8' ) );
		$this->assertTrue( dn_bfs_ip_in_cidr( '192.168.1.130', '192.168.1.128/25' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( '192.168.1.127', '192.168.1.128/25' ) );
	}

	public function test_ipv6_range_and_family_mismatch() {
		$this->assertTrue( dn_bfs_ip_in_cidr( '2001:db8::1', '2001:db8::/32' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( '2001:db9::1', '2001:db8::/32' ) );
		$this->assertFalse( dn_bfs_ip_in_cidr( '10.0.0.1', '2001:db8::/32' ) );
	}
}
```

- [ ] **Step 5: Chạy unit test**

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit`
Expected: `OK (3 tests, 8 assertions)` (đây là hàm có sẵn nên pass ngay — mục đích là xác nhận hạ tầng chạy được).

- [ ] **Step 6: Tạo `tests/integration/harness.php`**

```php
<?php
/**
 * Tiny assertion harness for integration tests run through `wp eval-file`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DN_BFS_IT_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36' );

$GLOBALS['dn_bfs_it_results'] = array(
	'pass' => 0,
	'fail' => 0,
);
$GLOBALS['dn_bfs_it_now']     = null;

add_filter(
	'dn_bfs_now',
	function ( $now ) {
		return null === $GLOBALS['dn_bfs_it_now'] ? $now : $GLOBALS['dn_bfs_it_now'];
	}
);

function dn_bfs_it_set_now( $timestamp ) {
	$GLOBALS['dn_bfs_it_now'] = null === $timestamp ? null : (int) $timestamp;
}

function dn_bfs_it_uid( $seed ) {
	return md5( (string) $seed );
}

function dn_bfs_it_settings( $overrides = array() ) {
	delete_option( 'dn_burst_funnel_stats_tracking_settings' );
	update_option(
		'dn_burst_funnel_stats_tracking_settings',
		array_merge( dn_bfs_get_tracking_settings(), $overrides ),
		false
	);
}

function dn_bfs_it_reset() {
	global $wpdb;

	if ( function_exists( 'dn_bfs_table' ) ) {
		foreach ( array( 'visitors', 'sessions', 'pageviews', 'events', 'daily' ) as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . dn_bfs_table( $table ) );
		}
	}

	dn_bfs_it_settings( array() );
	dn_bfs_it_set_now( null );
	wp_set_current_user( 0 );

	$_COOKIE                    = array();
	$_SERVER['REMOTE_ADDR']     = '203.0.113.10';
	$_SERVER['HTTP_USER_AGENT'] = DN_BFS_IT_UA;
	$_SERVER['HTTP_REFERER']    = '';
	unset( $_SERVER['HTTP_CF_RAY'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_CF_IPCOUNTRY'] );
}

function dn_bfs_it( $name, $callback ) {
	dn_bfs_it_reset();

	try {
		$callback();
		$GLOBALS['dn_bfs_it_results']['pass']++;
		WP_CLI::log( 'PASS ' . $name );
	} catch ( Throwable $e ) {
		$GLOBALS['dn_bfs_it_results']['fail']++;
		WP_CLI::log( 'FAIL ' . $name . ': ' . $e->getMessage() );
	}
}

function dn_bfs_assert_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( trim( $message . ' expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ) );
	}
}

function dn_bfs_assert_true( $value, $message = '' ) {
	dn_bfs_assert_same( true, (bool) $value, $message );
}

function dn_bfs_it_count( $table, $where = '1=1' ) {
	global $wpdb;

	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . dn_bfs_table( $table ) . ' WHERE ' . $where );
}

function dn_bfs_it_collect( $body, $headers = array() ) {
	$request = new WP_REST_Request( 'POST', '/dnbfs/v1/collect' );
	$request->set_body( wp_json_encode( $body ) );
	$request->set_header( 'origin', home_url() );
	$request->set_header( 'user_agent', DN_BFS_IT_UA );

	foreach ( $headers as $key => $value ) {
		$request->set_header( $key, $value );
	}

	return rest_do_request( $request );
}

function dn_bfs_it_report() {
	$results = $GLOBALS['dn_bfs_it_results'];
	WP_CLI::log( sprintf( '%d passed, %d failed', $results['pass'], $results['fail'] ) );

	return $results['fail'] > 0 ? 1 : 0;
}
```

- [ ] **Step 7: Tạo `tests/integration/run.php`**

```php
<?php
/**
 * Run: docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php
 */

require __DIR__ . '/harness.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $dn_bfs_test_file ) {
	require $dn_bfs_test_file;
}

exit( dn_bfs_it_report() );
```

- [ ] **Step 8: Commit**

```bash
git add composer.json phpunit.xml.dist tests
git commit -m "test: add PHPUnit and WP-CLI integration test harness

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

(Chưa chạy test tích hợp được vì plugin chưa kích hoạt — Task 9 chạy lần đầu.)

---

### Task 3: Mở rộng settings tracking, nguồn IP client, từ khóa bot

**Files:**
- Modify: `includes/tracking.php` (các hàm `dn_bfs_default_bot_keywords`, `dn_bfs_get_tracking_settings`, `dn_bfs_sanitize_tracking_settings`, `dn_bfs_get_client_ip`, `dn_bfs_is_bot_request`)
- Test: `tests/php/TrackingSettingsTest.php`

**Interfaces:**
- Produces:
  - Khóa settings mới (trong `dn_bfs_get_tracking_settings()`): `excluded_roles` (array, mặc định `['administrator','shop_manager']`), `client_ip_source` (`auto`), `session_timeout` (30, phút), `cookie_days` (365), `dedupe_window` (300, giây), `reload_window` (10, giây), `limit_pv_per_min` (60), `limit_sessions_per_hour` (20), `limit_atc_per_min` (20), `limit_pv_per_session` (300), `block_empty_ua` (1), `force_cart_redirect` (0), `prefer_cloudflare` (1), `maxmind_license_key` (''), `raw_retention_days` (90).
  - `dn_bfs_tracking_int_ranges(): array` → `key => array( min, max, default )`.
  - `dn_bfs_resolve_client_ip( array $server, string $source ): string` → IP hợp lệ hoặc `'unknown'`.
  - `dn_bfs_is_bot_user_agent( string $ua, array $custom = array() ): bool` (UA rỗng → false; việc chặn UA rỗng do guard quyết định).
  - `dn_bfs_get_client_ip()` giờ dùng `dn_bfs_resolve_client_ip( $_SERVER, settings['client_ip_source'] )`.

- [ ] **Step 1: Viết test fail — `tests/php/TrackingSettingsTest.php`**

```php
<?php

use PHPUnit\Framework\TestCase;

class TrackingSettingsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['dn_bfs_test_options'] = array();
	}

	public function test_defaults_include_new_keys() {
		$settings = dn_bfs_get_tracking_settings();

		$this->assertSame( array( 'administrator', 'shop_manager' ), $settings['excluded_roles'] );
		$this->assertSame( 'auto', $settings['client_ip_source'] );
		$this->assertSame( 300, $settings['dedupe_window'] );
		$this->assertSame( 10, $settings['reload_window'] );
		$this->assertSame( 60, $settings['limit_pv_per_min'] );
		$this->assertSame( 20, $settings['limit_sessions_per_hour'] );
		$this->assertSame( 20, $settings['limit_atc_per_min'] );
		$this->assertSame( 300, $settings['limit_pv_per_session'] );
		$this->assertSame( 30, $settings['session_timeout'] );
		$this->assertSame( 365, $settings['cookie_days'] );
		$this->assertSame( 90, $settings['raw_retention_days'] );
		$this->assertSame( 1, $settings['block_empty_ua'] );
		$this->assertSame( 0, $settings['force_cart_redirect'] );
		$this->assertSame( 1, $settings['prefer_cloudflare'] );
		$this->assertSame( '', $settings['maxmind_license_key'] );
	}

	public function test_sanitize_clamps_integers_and_cleans_roles() {
		$clean = dn_bfs_sanitize_tracking_settings(
			array(
				'dedupe_window'       => '5',
				'limit_pv_per_min'    => '999999',
				'excluded_roles'      => array( 'Administrator', 'shop_manager', '<b>x</b>' ),
				'client_ip_source'    => 'evil',
				'maxmind_license_key' => 'abc DEF_12!',
				'tracking_enabled'    => 1,
				'exclude_bots'        => 1,
			)
		);

		$this->assertSame( 60, $clean['dedupe_window'] );
		$this->assertSame( 1000, $clean['limit_pv_per_min'] );
		$this->assertSame( array( 'administrator', 'shop_manager', 'bxb' ), $clean['excluded_roles'] );
		$this->assertSame( 'auto', $clean['client_ip_source'] );
		$this->assertSame( 'abcDEF_12', $clean['maxmind_license_key'] );
	}

	public function test_sanitize_keeps_saved_new_keys_when_missing_from_input() {
		$GLOBALS['dn_bfs_test_options']['dn_burst_funnel_stats_tracking_settings'] = array( 'dedupe_window' => 900 );

		$clean = dn_bfs_sanitize_tracking_settings( array( 'tracking_enabled' => 1 ) );

		$this->assertSame( 900, $clean['dedupe_window'] );
	}

	public function test_resolve_client_ip_sources() {
		$server = array(
			'REMOTE_ADDR'           => '172.68.1.1',
			'HTTP_CF_RAY'           => 'abc-SIN',
			'HTTP_CF_CONNECTING_IP' => '198.51.100.7',
			'HTTP_X_FORWARDED_FOR'  => 'bogus, 198.51.100.8, 10.0.0.1',
			'HTTP_X_REAL_IP'        => '198.51.100.9',
		);

		$this->assertSame( '198.51.100.7', dn_bfs_resolve_client_ip( $server, 'auto' ) );
		$this->assertSame( '172.68.1.1', dn_bfs_resolve_client_ip( $server, 'remote_addr' ) );
		$this->assertSame( '198.51.100.8', dn_bfs_resolve_client_ip( $server, 'x_forwarded_for' ) );
		$this->assertSame( '198.51.100.9', dn_bfs_resolve_client_ip( $server, 'x_real_ip' ) );

		unset( $server['HTTP_CF_RAY'] );
		$this->assertSame( '172.68.1.1', dn_bfs_resolve_client_ip( $server, 'auto' ) );
		$this->assertSame( 'unknown', dn_bfs_resolve_client_ip( array(), 'auto' ) );
	}

	public function test_bot_user_agents() {
		$this->assertTrue( dn_bfs_is_bot_user_agent( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) );
		$this->assertTrue( dn_bfs_is_bot_user_agent( 'Mozilla/5.0 HeadlessChrome/120.0' ) );
		$this->assertTrue( dn_bfs_is_bot_user_agent( 'curl/8.4.0' ) );
		$this->assertTrue( dn_bfs_is_bot_user_agent( 'python-requests/2.31' ) );
		$this->assertTrue( dn_bfs_is_bot_user_agent( 'MyCrawler', array( 'mycrawler' ) ) );
		$this->assertFalse( dn_bfs_is_bot_user_agent( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1' ) );
		$this->assertFalse( dn_bfs_is_bot_user_agent( '' ) );
	}
}
```

- [ ] **Step 2: Chạy test, xác nhận fail**

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit --filter TrackingSettingsTest`
Expected: FAIL (`Undefined index: excluded_roles` / `Call to undefined function dn_bfs_resolve_client_ip`).

- [ ] **Step 3: Sửa `dn_bfs_default_bot_keywords()` trong `includes/tracking.php`** — thêm vào cuối mảng trả về (sau `'petalbot',`):

```php
		'headlesschrome',
		'phantomjs',
		'puppeteer',
		'playwright',
		'selenium',
		'lighthouse',
		'ptst',
		'python-requests',
		'python-urllib',
		'curl',
		'wget',
		'go-http-client',
		'axios',
		'node-fetch',
		'okhttp',
		'scrapy',
		'httpclient',
```

- [ ] **Step 4: Thêm `dn_bfs_tracking_int_ranges()` ngay trước `dn_bfs_get_tracking_settings()` và thay toàn bộ `dn_bfs_get_tracking_settings()`**

```php
function dn_bfs_tracking_int_ranges() {
	return array(
		'session_timeout'         => array( 5, 240, 30 ),
		'cookie_days'             => array( 1, 730, 365 ),
		'dedupe_window'           => array( 60, 86400, 300 ),
		'reload_window'           => array( 0, 300, 10 ),
		'limit_pv_per_min'        => array( 10, 1000, 60 ),
		'limit_sessions_per_hour' => array( 1, 1000, 20 ),
		'limit_atc_per_min'       => array( 1, 500, 20 ),
		'limit_pv_per_session'    => array( 20, 5000, 300 ),
		'raw_retention_days'      => array( 7, 730, 90 ),
	);
}

function dn_bfs_get_tracking_settings() {
	$settings = get_option( 'dn_burst_funnel_stats_tracking_settings', array() );

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	$defaults = array(
		'page_tracking_mode'     => 'full',
		'selected_page_ids'      => array(),
		'product_tracking_mode'  => 'all',
		'selected_product_ids'   => array(),
		'excluded_ips'           => array(),
		'invalid_excluded_ips'   => array(),
		'exclude_bots'           => 1,
		'custom_bot_user_agents' => array(),
		'default_date_range'     => 'month_to_date',
		'default_compare'        => 'previous_year',
		'tracking_enabled'       => 1,
		'excluded_roles'         => array( 'administrator', 'shop_manager' ),
		'client_ip_source'       => 'auto',
		'block_empty_ua'         => 1,
		'force_cart_redirect'    => 0,
		'prefer_cloudflare'      => 1,
		'maxmind_license_key'    => '',
	);

	foreach ( dn_bfs_tracking_int_ranges() as $key => $range ) {
		$defaults[ $key ] = $range[2];
	}

	$settings = wp_parse_args( $settings, $defaults );

	foreach ( dn_bfs_tracking_int_ranges() as $key => $range ) {
		$settings[ $key ] = (int) $settings[ $key ];
	}

	return $settings;
}
```

- [ ] **Step 5: Sửa `dn_bfs_sanitize_tracking_settings()`** — giữ nguyên phần thân hiện có, chỉ đổi hai chỗ:

(a) Ngay sau dòng `$settings = is_array( $settings ) ? $settings : array();` thêm:

```php
	$current  = dn_bfs_get_tracking_settings();
	$new_keys = array_merge(
		array_keys( dn_bfs_tracking_int_ranges() ),
		array( 'excluded_roles', 'client_ip_source', 'block_empty_ua', 'force_cart_redirect', 'prefer_cloudflare', 'maxmind_license_key' )
	);

	// Keys introduced in schema 4 are kept from the saved value when a form does not post them.
	foreach ( $new_keys as $key ) {
		if ( ! array_key_exists( $key, $settings ) ) {
			$settings[ $key ] = $current[ $key ];
		}
	}
```

(b) Thay câu `return array( … );` cuối hàm bằng:

```php
	$clean = array(
		'page_tracking_mode'     => $page_tracking_mode,
		'selected_page_ids'      => array_values( array_filter( array_unique( $selected_page_ids ) ) ),
		'product_tracking_mode'  => $product_tracking_mode,
		'selected_product_ids'   => array_values( array_filter( array_unique( $selected_product_ids ) ) ),
		'excluded_ips'           => array_values( array_unique( $valid ) ),
		'invalid_excluded_ips'   => array_values( array_unique( $invalid ) ),
		'exclude_bots'           => empty( $settings['exclude_bots'] ) ? 0 : 1,
		'custom_bot_user_agents' => dn_bfs_normalize_lines( $custom_bots ),
		'default_date_range'     => 'month_to_date',
		'default_compare'        => $compare,
		'tracking_enabled'       => isset( $settings['tracking_enabled'] ) ? ( empty( $settings['tracking_enabled'] ) ? 0 : 1 ) : 1,
		'excluded_roles'         => array_values( array_filter( array_unique( array_map( 'sanitize_key', (array) $settings['excluded_roles'] ) ) ) ),
		'client_ip_source'       => in_array( $settings['client_ip_source'], array( 'auto', 'remote_addr', 'x_forwarded_for', 'x_real_ip' ), true ) ? $settings['client_ip_source'] : 'auto',
		'block_empty_ua'         => empty( $settings['block_empty_ua'] ) ? 0 : 1,
		'force_cart_redirect'    => empty( $settings['force_cart_redirect'] ) ? 0 : 1,
		'prefer_cloudflare'      => empty( $settings['prefer_cloudflare'] ) ? 0 : 1,
		'maxmind_license_key'    => substr( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $settings['maxmind_license_key'] ), 0, 64 ),
	);

	foreach ( dn_bfs_tracking_int_ranges() as $key => $range ) {
		$clean[ $key ] = max( $range[0], min( $range[1], (int) $settings[ $key ] ) );
	}

	return $clean;
```

- [ ] **Step 6: Thay `dn_bfs_get_client_ip()` và thêm `dn_bfs_resolve_client_ip()`**

```php
function dn_bfs_resolve_client_ip( $server, $source ) {
	$candidates = array();

	if ( 'x_forwarded_for' === $source && ! empty( $server['HTTP_X_FORWARDED_FOR'] ) ) {
		$candidates = explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] );
	} elseif ( 'x_real_ip' === $source && ! empty( $server['HTTP_X_REAL_IP'] ) ) {
		$candidates = array( $server['HTTP_X_REAL_IP'] );
	} elseif ( 'auto' === $source && ! empty( $server['HTTP_CF_RAY'] ) && ! empty( $server['HTTP_CF_CONNECTING_IP'] ) ) {
		$candidates = array( $server['HTTP_CF_CONNECTING_IP'] );
	}

	$candidates[] = isset( $server['REMOTE_ADDR'] ) ? $server['REMOTE_ADDR'] : '';

	foreach ( $candidates as $candidate ) {
		$candidate = trim( (string) $candidate );

		if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
			return $candidate;
		}
	}

	return 'unknown';
}

function dn_bfs_get_client_ip() {
	$settings = dn_bfs_get_tracking_settings();

	return dn_bfs_resolve_client_ip( wp_unslash( $_SERVER ), $settings['client_ip_source'] );
}
```

- [ ] **Step 7: Thêm `dn_bfs_is_bot_user_agent()` và cho `dn_bfs_is_bot_request()` dùng nó** — thay toàn bộ `dn_bfs_is_bot_request()`:

```php
function dn_bfs_is_bot_user_agent( $user_agent, $custom = array() ) {
	$user_agent = strtolower( trim( (string) $user_agent ) );

	if ( '' === $user_agent ) {
		return false;
	}

	foreach ( array_merge( dn_bfs_default_bot_keywords(), (array) $custom ) as $keyword ) {
		$keyword = strtolower( trim( (string) $keyword ) );

		if ( '' !== $keyword && false !== strpos( $user_agent, $keyword ) ) {
			return true;
		}
	}

	return false;
}

function dn_bfs_is_bot_request() {
	$settings = dn_bfs_get_tracking_settings();

	if ( empty( $settings['exclude_bots'] ) ) {
		return false;
	}

	$user_agent = dn_bfs_get_current_user_agent();

	if ( '' === $user_agent ) {
		return true;
	}

	return dn_bfs_is_bot_user_agent( $user_agent, $settings['custom_bot_user_agents'] );
}
```

- [ ] **Step 8: Chạy toàn bộ unit test**

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit`
Expected: `OK (8 tests, …)`.

- [ ] **Step 9: Commit**

```bash
git add includes/tracking.php tests/php/TrackingSettingsTest.php
git commit -m "feat(tracking): add schema-4 tracking settings, client IP source, bot keywords

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Phân tích user-agent

**Files:**
- Create: `includes/tracking/ua-parser.php`
- Test: `tests/php/UaParserTest.php`

**Interfaces:**
- Produces: `dn_bfs_parse_user_agent( string $ua ): array` → `array( 'device' => 'desktop'|'mobile'|'tablet', 'browser' => string, 'os' => string )`. Browser ∈ `Edge, Opera, Samsung Internet, Coc Coc, UC Browser, Facebook, Instagram, Zalo, Firefox, Chrome, Safari, Other`. OS ∈ `Windows, iOS, macOS, Android, ChromeOS, Linux, Other`.

- [ ] **Step 1: Viết test fail — `tests/php/UaParserTest.php`**

```php
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
```

- [ ] **Step 2: Chạy test, xác nhận fail**

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit --filter UaParserTest`
Expected: FAIL (`Call to undefined function dn_bfs_parse_user_agent`).

- [ ] **Step 3: Tạo `includes/tracking/ua-parser.php`**

```php
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
```

- [ ] **Step 4: Chạy test**

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit --filter UaParserTest`
Expected: `OK (16 tests, 16 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add includes/tracking/ua-parser.php tests/php/UaParserTest.php
git commit -m "feat(tracking): add user-agent parser for device, browser and OS

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: UTM, referrer và phân loại kênh

**Files:**
- Create: `includes/tracking/channel.php`
- Test: `tests/php/ChannelTest.php`

**Interfaces:**
- Produces:
  - `dn_bfs_normalize_host( string $host ): string` — chữ thường, bỏ `www.`.
  - `dn_bfs_referrer_host( string $referrer ): string` — host đã normalize, `''` nếu không phải URL http(s).
  - `dn_bfs_extract_utm( string $query ): array` → `array( 'source', 'medium', 'campaign', 'content', 'term', 'paid_click' => bool )`; `source`/`medium` chữ thường; mỗi giá trị ≤ 191 ký tự.
  - `dn_bfs_classify_channel( array $utm, string $referrer_host, string $site_host ): string` → `direct|organic_search|social|paid|email|referral`.

- [ ] **Step 1: Viết test fail — `tests/php/ChannelTest.php`**

```php
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
```

- [ ] **Step 2: Chạy test, xác nhận fail**

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit --filter ChannelTest`
Expected: FAIL (`Call to undefined function dn_bfs_normalize_host`).

- [ ] **Step 3: Tạo `includes/tracking/channel.php`**

```php
<?php
/**
 * UTM parsing, referrer hosts and traffic channel classification.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		$value = substr( sanitize_text_field( $value ), 0, 191 );

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
```

- [ ] **Step 4: Chạy test**

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit --filter ChannelTest`
Expected: `OK (17 tests, …)`.

- [ ] **Step 5: Commit**

```bash
git add includes/tracking/channel.php tests/php/ChannelTest.php
git commit -m "feat(tracking): add UTM extraction and traffic channel classification

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Guard — kiểm tra payload, loại trừ khách, chọn trang

**Files:**
- Create: `includes/tracking/guard.php`
- Test: `tests/php/GuardTest.php`

**Interfaces:**
- Consumes: `dn_bfs_normalize_host()` (Task 5), `dn_bfs_ip_in_cidr()`, `dn_bfs_is_bot_user_agent()` (Task 3).
- Produces:
  - `dn_bfs_guard_validate_payload( string $raw, array $server, string $site_host ): array` → `array( 'ok' => true, 'data' => array ) ` hoặc `array( 'ok' => false, 'reason' => string )`. `$server` chỉ cần `REQUEST_METHOD`, `HTTP_ORIGIN`, `HTTP_REFERER`. `data` cho `pv`: `t, vid, sid, path, query, ref, ptype, pid, sw`; cho `ping`: `t, vid, sid, pvid, engaged`. Lý do lỗi: `bad_method`, `too_large`, `invalid_json`, `invalid_type`, `invalid_id`, `bad_origin`, `invalid_path`, `invalid_pageview`.
  - `dn_bfs_guard_check_visitor( array $ctx ): string` — `''` nếu hợp lệ, hoặc `disabled|excluded_role|excluded_ip|empty_ua|bot`. `$ctx` cần: `settings`, `roles` (array), `ip` (string), `ua_raw` (string).
  - `dn_bfs_guard_is_page_selected( array $hit, array $settings ): bool`.
  - Hằng danh sách loại trang: `dn_bfs_page_types(): array` = `home, product, category, cart, checkout, thankyou, other`.

- [ ] **Step 1: Viết test fail — `tests/php/GuardTest.php`**

```php
<?php

use PHPUnit\Framework\TestCase;

class GuardTest extends TestCase {
	private $vid = '0123456789abcdef0123456789abcdef';
	private $sid = 'fedcba9876543210fedcba9876543210';

	private function server( $origin = 'https://shop.example.com' ) {
		return array(
			'REQUEST_METHOD' => 'POST',
			'HTTP_ORIGIN'    => $origin,
			'HTTP_REFERER'   => '',
		);
	}

	private function pv( $overrides = array() ) {
		return json_encode(
			array_merge(
				array(
					't'     => 'pv',
					'vid'   => $this->vid,
					'sid'   => $this->sid,
					'path'  => '/product/ao-thun/',
					'query' => '?utm_source=fb',
					'ref'   => 'https://l.facebook.com/',
					'ptype' => 'product',
					'pid'   => 42,
					'sw'    => 390,
				),
				$overrides
			)
		);
	}

	public function test_valid_pageview() {
		$result = dn_bfs_guard_validate_payload( $this->pv(), $this->server(), 'shop.example.com' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '/product/ao-thun/', $result['data']['path'] );
		$this->assertSame( 'product', $result['data']['ptype'] );
		$this->assertSame( 42, $result['data']['pid'] );
		$this->assertSame( 'utm_source=fb', $result['data']['query'] );
	}

	public function test_www_origin_is_same_site_and_referer_fallback() {
		$this->assertTrue( dn_bfs_guard_validate_payload( $this->pv(), $this->server( 'https://www.shop.example.com' ), 'shop.example.com' )['ok'] );

		$server                 = $this->server( '' );
		$server['HTTP_REFERER'] = 'https://shop.example.com/cart/';
		$this->assertTrue( dn_bfs_guard_validate_payload( $this->pv(), $server, 'shop.example.com' )['ok'] );
	}

	/**
	 * @dataProvider rejections
	 */
	public function test_rejections( $raw, $server, $reason ) {
		$result = dn_bfs_guard_validate_payload( $raw, $server, 'shop.example.com' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( $reason, $result['reason'] );
	}

	public function rejections() {
		$ok      = array( 'REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://shop.example.com' );
		$valid   = array( 't' => 'pv', 'vid' => str_repeat( 'a', 32 ), 'sid' => str_repeat( 'b', 32 ), 'path' => '/', 'ptype' => 'home' );
		$get     = array_merge( $ok, array( 'REQUEST_METHOD' => 'GET' ) );
		$foreign = array( 'REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://evil.test' );
		$none    = array( 'REQUEST_METHOD' => 'POST' );

		return array(
			'method'      => array( json_encode( $valid ), $get, 'bad_method' ),
			'too large'   => array( str_repeat( 'x', 2049 ), $ok, 'too_large' ),
			'not json'    => array( 'hello', $ok, 'invalid_json' ),
			'bad type'    => array( json_encode( array_merge( $valid, array( 't' => 'click' ) ) ), $ok, 'invalid_type' ),
			'bad vid'     => array( json_encode( array_merge( $valid, array( 'vid' => 'XYZ' ) ) ), $ok, 'invalid_id' ),
			'foreign'     => array( json_encode( $valid ), $foreign, 'bad_origin' ),
			'no origin'   => array( json_encode( $valid ), $none, 'bad_origin' ),
			'bad path'    => array( json_encode( array_merge( $valid, array( 'path' => 'http://x' ) ) ), $ok, 'invalid_path' ),
			'ping no pv'  => array( json_encode( array( 't' => 'ping', 'vid' => str_repeat( 'a', 32 ), 'sid' => str_repeat( 'b', 32 ), 'pvid' => 0 ) ), $ok, 'invalid_pageview' ),
		);
	}

	public function test_normalizes_fields() {
		$result = dn_bfs_guard_validate_payload(
			$this->pv(
				array(
					'path'  => '/a?x=1#y' . str_repeat( 'z', 300 ),
					'ptype' => 'weird',
					'ref'   => 'javascript:alert(1)',
					'pid'   => -5,
				)
			),
			$this->server(),
			'shop.example.com'
		);

		$this->assertSame( '/a', $result['data']['path'] );
		$this->assertSame( 'other', $result['data']['ptype'] );
		$this->assertSame( '', $result['data']['ref'] );
		$this->assertSame( 5, $result['data']['pid'] );
	}

	public function test_ping_clamps_engaged() {
		$raw    = json_encode( array( 't' => 'ping', 'vid' => $this->vid, 'sid' => $this->sid, 'pvid' => 9, 'engaged' => 99999 ) );
		$result = dn_bfs_guard_validate_payload( $raw, $this->server(), 'shop.example.com' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 9, $result['data']['pvid'] );
		$this->assertSame( 1800, $result['data']['engaged'] );
	}

	public function test_check_visitor() {
		$GLOBALS['dn_bfs_test_options'] = array();
		$settings                       = dn_bfs_get_tracking_settings();
		$settings['excluded_ips']       = array( '10.0.0.0/8' );
		$base                           = array(
			'settings' => $settings,
			'roles'    => array( 'customer' ),
			'ip'       => '203.0.113.1',
			'ua_raw'   => 'Mozilla/5.0 (Windows NT 10.0) Chrome/128.0',
		);

		$this->assertSame( '', dn_bfs_guard_check_visitor( $base ) );
		$this->assertSame( 'excluded_role', dn_bfs_guard_check_visitor( array_merge( $base, array( 'roles' => array( 'shop_manager' ) ) ) ) );
		$this->assertSame( 'excluded_ip', dn_bfs_guard_check_visitor( array_merge( $base, array( 'ip' => '10.2.3.4' ) ) ) );
		$this->assertSame( 'empty_ua', dn_bfs_guard_check_visitor( array_merge( $base, array( 'ua_raw' => '' ) ) ) );
		$this->assertSame( 'bot', dn_bfs_guard_check_visitor( array_merge( $base, array( 'ua_raw' => 'curl/8.0' ) ) ) );

		$off                     = $base;
		$off['settings']['tracking_enabled'] = 0;
		$this->assertSame( 'disabled', dn_bfs_guard_check_visitor( $off ) );
	}

	public function test_page_selection() {
		$settings = array( 'page_tracking_mode' => 'selected', 'selected_page_ids' => array( 7 ) );

		$this->assertTrue( dn_bfs_guard_is_page_selected( array( 'ptype' => 'other', 'pid' => 7 ), $settings ) );
		$this->assertFalse( dn_bfs_guard_is_page_selected( array( 'ptype' => 'other', 'pid' => 8 ), $settings ) );
		$this->assertTrue( dn_bfs_guard_is_page_selected( array( 'ptype' => 'cart', 'pid' => 8 ), $settings ) );
		$this->assertTrue( dn_bfs_guard_is_page_selected( array( 'ptype' => 'other', 'pid' => 8 ), array( 'page_tracking_mode' => 'full' ) ) );
	}
}
```

- [ ] **Step 2: Chạy test, xác nhận fail**

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit --filter GuardTest`
Expected: FAIL (`Call to undefined function dn_bfs_guard_validate_payload`).

- [ ] **Step 3: Tạo `includes/tracking/guard.php`**

```php
<?php
/**
 * Request validation and visitor exclusion rules for tracking hits.
 *
 * Pure functions only: no database access, no globals.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_page_types() {
	return array( 'home', 'product', 'category', 'cart', 'checkout', 'thankyou', 'other' );
}

function dn_bfs_guard_fail( $reason ) {
	return array(
		'ok'     => false,
		'reason' => $reason,
	);
}

function dn_bfs_guard_same_site( $server, $site_host ) {
	foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $header ) {
		if ( empty( $server[ $header ] ) ) {
			continue;
		}

		$host = wp_parse_url( (string) $server[ $header ], PHP_URL_HOST );

		return is_string( $host ) && dn_bfs_normalize_host( $host ) === dn_bfs_normalize_host( $site_host );
	}

	return false;
}

function dn_bfs_guard_validate_payload( $raw, $server, $site_host ) {
	$raw = (string) $raw;

	if ( ! isset( $server['REQUEST_METHOD'] ) || 'POST' !== strtoupper( (string) $server['REQUEST_METHOD'] ) ) {
		return dn_bfs_guard_fail( 'bad_method' );
	}

	if ( strlen( $raw ) > 2048 ) {
		return dn_bfs_guard_fail( 'too_large' );
	}

	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) ) {
		return dn_bfs_guard_fail( 'invalid_json' );
	}

	$type = isset( $data['t'] ) ? (string) $data['t'] : '';

	if ( ! in_array( $type, array( 'pv', 'ping' ), true ) ) {
		return dn_bfs_guard_fail( 'invalid_type' );
	}

	foreach ( array( 'vid', 'sid' ) as $key ) {
		if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) || ! preg_match( '/^[a-f0-9]{32}$/', $data[ $key ] ) ) {
			return dn_bfs_guard_fail( 'invalid_id' );
		}
	}

	if ( ! dn_bfs_guard_same_site( $server, $site_host ) ) {
		return dn_bfs_guard_fail( 'bad_origin' );
	}

	if ( 'ping' === $type ) {
		$pvid = isset( $data['pvid'] ) ? absint( $data['pvid'] ) : 0;

		if ( $pvid < 1 ) {
			return dn_bfs_guard_fail( 'invalid_pageview' );
		}

		return array(
			'ok'   => true,
			'data' => array(
				't'       => 'ping',
				'vid'     => $data['vid'],
				'sid'     => $data['sid'],
				'pvid'    => $pvid,
				'engaged' => max( 0, min( 1800, isset( $data['engaged'] ) ? (int) $data['engaged'] : 0 ) ),
			),
		);
	}

	$path = isset( $data['path'] ) && is_string( $data['path'] ) ? $data['path'] : '';
	$path = preg_replace( '/[\x00-\x1F\x7F]/', '', strtok( strtok( $path, '#' ), '?' ) );

	if ( '' === $path || '/' !== $path[0] || 0 === strpos( $path, '//' ) ) {
		return dn_bfs_guard_fail( 'invalid_path' );
	}

	$ref = isset( $data['ref'] ) && is_string( $data['ref'] ) ? substr( $data['ref'], 0, 1024 ) : '';
	if ( ! preg_match( '#^https?://#i', $ref ) ) {
		$ref = '';
	}

	$ptype = isset( $data['ptype'] ) && is_string( $data['ptype'] ) ? $data['ptype'] : 'other';

	return array(
		'ok'   => true,
		'data' => array(
			't'     => 'pv',
			'vid'   => $data['vid'],
			'sid'   => $data['sid'],
			'path'  => substr( $path, 0, 255 ),
			'query' => isset( $data['query'] ) && is_string( $data['query'] ) ? substr( ltrim( $data['query'], '?' ), 0, 1024 ) : '',
			'ref'   => $ref,
			'ptype' => in_array( $ptype, dn_bfs_page_types(), true ) ? $ptype : 'other',
			'pid'   => isset( $data['pid'] ) ? absint( $data['pid'] ) : 0,
			'sw'    => isset( $data['sw'] ) ? min( 10000, absint( $data['sw'] ) ) : 0,
		),
	);
}

function dn_bfs_guard_check_visitor( $ctx ) {
	$settings = $ctx['settings'];

	if ( empty( $settings['tracking_enabled'] ) ) {
		return 'disabled';
	}

	if ( array_intersect( (array) $ctx['roles'], (array) $settings['excluded_roles'] ) ) {
		return 'excluded_role';
	}

	$ip = (string) $ctx['ip'];

	if ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		foreach ( (array) $settings['excluded_ips'] as $rule ) {
			if ( dn_bfs_ip_in_cidr( $ip, $rule ) ) {
				return 'excluded_ip';
			}
		}
	}

	$ua = trim( (string) $ctx['ua_raw'] );

	if ( '' === $ua ) {
		return empty( $settings['block_empty_ua'] ) ? '' : 'empty_ua';
	}

	if ( ! empty( $settings['exclude_bots'] ) && dn_bfs_is_bot_user_agent( $ua, $settings['custom_bot_user_agents'] ) ) {
		return 'bot';
	}

	return '';
}

function dn_bfs_guard_is_page_selected( $hit, $settings ) {
	if ( empty( $settings['page_tracking_mode'] ) || 'selected' !== $settings['page_tracking_mode'] ) {
		return true;
	}

	if ( in_array( $hit['ptype'], array( 'product', 'cart', 'checkout', 'thankyou' ), true ) ) {
		return true;
	}

	return in_array( (int) $hit['pid'], array_map( 'absint', (array) $settings['selected_page_ids'] ), true );
}
```

- [ ] **Step 4: Chạy test**

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit --filter GuardTest`
Expected: `OK` (tất cả test trong `GuardTest` pass).

- [ ] **Step 5: Chạy toàn bộ unit test**

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit`
Expected: `OK`.

- [ ] **Step 6: Commit**

```bash
git add includes/tracking/guard.php tests/php/GuardTest.php
git commit -m "feat(tracking): add payload validation and visitor exclusion guard

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: GeoIP (Cloudflare + MaxMind)

**Files:**
- Create: `lib/maxmind-db/` (vendored), `lib/maxmind-db/autoload.php`, `includes/tracking/geo.php`, `tests/php/fixtures/GeoIP2-City-Test.mmdb`
- Test: `tests/php/GeoTest.php`

**Interfaces:**
- Produces:
  - `dn_bfs_geo_from_headers( array $server ): array` → `array( 'country' => 'VN'|'' , 'city' => string )`.
  - `dn_bfs_geo_from_mmdb( string $ip, string $path ): array` (cùng dạng; rỗng nếu thiếu file/IP không có).
  - `dn_bfs_geo_db_path(): string` → `{uploads basedir}/dnbfs/GeoLite2-City.mmdb` (Kế hoạch 2 tải file vào đây).
  - `dn_bfs_geo_lookup( string $ip, array $server, array $settings ): array`.

- [ ] **Step 1: Vendor thư viện MaxMind DB Reader**

```bash
mkdir -p /tmp/dnbfs-maxmind && cd /tmp/dnbfs-maxmind
curl -fsSL -o reader.tar.gz https://github.com/maxmind/MaxMind-DB-Reader-php/archive/refs/tags/v1.11.1.tar.gz
tar xzf reader.tar.gz
cd - >/dev/null
mkdir -p lib/maxmind-db
cp -R /tmp/dnbfs-maxmind/MaxMind-DB-Reader-php-1.11.1/src lib/maxmind-db/
cp /tmp/dnbfs-maxmind/MaxMind-DB-Reader-php-1.11.1/LICENSE lib/maxmind-db/LICENSE
ls lib/maxmind-db/src/MaxMind/Db lib/maxmind-db/src/MaxMind/Db/Reader
```

Expected: `Reader.php` và thư mục `Reader/` chứa `Decoder.php`, `InvalidDatabaseException.php`, `Metadata.php`, `Util.php`. Nếu `curl` báo 404 → chạy `gh release list -R maxmind/MaxMind-DB-Reader-php --limit 5`, chọn tag `v1.x` mới nhất và thay `1.11.1` ở các lệnh trên.

- [ ] **Step 2: Tạo `lib/maxmind-db/autoload.php`**

```php
<?php
/**
 * Loads the vendored MaxMind DB Reader (Apache-2.0, see LICENSE in this folder).
 */

if ( ! class_exists( 'MaxMind\\Db\\Reader' ) ) {
	$dn_bfs_mmdb_dir = __DIR__ . '/src/MaxMind/Db/';

	require_once $dn_bfs_mmdb_dir . 'Reader/InvalidDatabaseException.php';
	require_once $dn_bfs_mmdb_dir . 'Reader/Util.php';
	require_once $dn_bfs_mmdb_dir . 'Reader/Decoder.php';
	require_once $dn_bfs_mmdb_dir . 'Reader/Metadata.php';
	require_once $dn_bfs_mmdb_dir . 'Reader.php';
}
```

- [ ] **Step 3: Tải database test của MaxMind (CC BY-SA 4.0)**

```bash
mkdir -p tests/php/fixtures
curl -fsSL -o tests/php/fixtures/GeoIP2-City-Test.mmdb https://github.com/maxmind/MaxMind-DB/raw/main/test-data/GeoIP2-City-Test.mmdb
ls -la tests/php/fixtures/GeoIP2-City-Test.mmdb
```

Expected: file vài chục KB.

- [ ] **Step 4: Viết test fail — `tests/php/GeoTest.php`**

```php
<?php

use PHPUnit\Framework\TestCase;

class GeoTest extends TestCase {
	private $db;

	protected function setUp(): void {
		$this->db = __DIR__ . '/fixtures/GeoIP2-City-Test.mmdb';
	}

	public function test_headers() {
		$this->assertSame( array( 'country' => 'VN', 'city' => 'Hanoi' ), dn_bfs_geo_from_headers( array( 'HTTP_CF_IPCOUNTRY' => 'vn', 'HTTP_CF_IPCITY' => 'Hanoi' ) ) );
		$this->assertSame( array( 'country' => '', 'city' => '' ), dn_bfs_geo_from_headers( array( 'HTTP_CF_IPCOUNTRY' => 'XX' ) ) );
		$this->assertSame( array( 'country' => '', 'city' => '' ), dn_bfs_geo_from_headers( array( 'HTTP_CF_IPCOUNTRY' => 'T1' ) ) );
		$this->assertSame( array( 'country' => '', 'city' => '' ), dn_bfs_geo_from_headers( array() ) );
	}

	public function test_mmdb_lookup() {
		$this->assertSame( array( 'country' => 'GB', 'city' => 'London' ), dn_bfs_geo_from_mmdb( '81.2.69.142', $this->db ) );
		$this->assertSame( array( 'country' => '', 'city' => '' ), dn_bfs_geo_from_mmdb( '127.0.0.1', $this->db ) );
		$this->assertSame( array( 'country' => '', 'city' => '' ), dn_bfs_geo_from_mmdb( '81.2.69.142', '/nope.mmdb' ) );
	}

	public function test_lookup_prefers_cloudflare_and_fills_city_from_mmdb() {
		$settings = array( 'prefer_cloudflare' => 1 );

		$this->assertSame(
			array( 'country' => 'GB', 'city' => 'London' ),
			dn_bfs_geo_lookup( '81.2.69.142', array( 'HTTP_CF_IPCOUNTRY' => 'GB' ), $settings, $this->db )
		);
		$this->assertSame(
			array( 'country' => 'VN', 'city' => '' ),
			dn_bfs_geo_lookup( '81.2.69.142', array( 'HTTP_CF_IPCOUNTRY' => 'VN' ), $settings, $this->db )
		);
		$this->assertSame(
			array( 'country' => 'GB', 'city' => 'London' ),
			dn_bfs_geo_lookup( '81.2.69.142', array( 'HTTP_CF_IPCOUNTRY' => 'VN' ), array( 'prefer_cloudflare' => 0 ), $this->db )
		);
	}
}
```

- [ ] **Step 5: Chạy test, xác nhận fail**

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit --filter GeoTest`
Expected: FAIL (`Call to undefined function dn_bfs_geo_from_headers`).

- [ ] **Step 6: Tạo `includes/tracking/geo.php`**

```php
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
```

- [ ] **Step 7: Chạy test**

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit --filter GeoTest`
Expected: `OK (3 tests, …)`.

- [ ] **Step 8: Commit**

```bash
git add lib/maxmind-db includes/tracking/geo.php tests/php/GeoTest.php tests/php/fixtures/GeoIP2-City-Test.mmdb
git commit -m "feat(tracking): add GeoIP lookup via Cloudflare headers and MaxMind DB

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Schema database + ngữ cảnh request

**Files:**
- Create: `includes/tracking/schema.php`, `includes/tracking/context.php`, `tests/integration/test-schema.php`

**Interfaces:**
- Consumes: `dn_bfs_get_tracking_settings()`, `dn_bfs_get_client_ip()`, `dn_bfs_parse_user_agent()`, `dn_bfs_normalize_host()`.
- Produces:
  - `dn_bfs_table( string $name ): string`.
  - `dn_bfs_install_schema(): void` — tạo 6 bảng `visitors, sessions, pageviews, events, daily, api_keys` bằng `dbDelta`.
  - `dn_bfs_now(): int` (filter `dn_bfs_now`).
  - `dn_bfs_site_host(): string`.
  - `dn_bfs_ip_hash( string $ip, int $now, string $user_agent = '' ): string` — `''` khi IP không hợp lệ.
  - `dn_bfs_request_roles(): array`.
  - `dn_bfs_request_context( int $now, string $ua_raw ): array` → khóa `now, settings, ip, ip_hash, ua_raw, ua, roles, site_host`.

- [ ] **Step 1: Tạo `includes/tracking/schema.php`**

```php
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
```

- [ ] **Step 2: Tạo `includes/tracking/context.php`**

```php
<?php
/**
 * Per-request tracking context shared by the collector and WooCommerce hooks.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DN_BFS_COOKIE_VISITOR', 'dnbfs_vid' );
define( 'DN_BFS_COOKIE_SESSION', 'dnbfs_sid' );

function dn_bfs_now() {
	return (int) apply_filters( 'dn_bfs_now', time() );
}

function dn_bfs_site_host() {
	return dn_bfs_normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
}

function dn_bfs_ip_hash( $ip, $now ) {
	if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return '';
	}

	$option = 'dnbfs_salt_' . wp_date( 'Y-m-d', $now );
	$salt   = get_option( $option );

	if ( ! $salt ) {
		add_option( $option, wp_generate_password( 64, true, true ), '', 'no' );
		$salt = get_option( $option );
	}

	return hash_hmac( 'sha256', $ip, (string) $salt );
}

/**
 * Roles of the logged-in visitor. Reads the auth cookie directly because REST
 * requests without a nonce run as user 0.
 */
function dn_bfs_request_roles() {
	$user_id = wp_validate_auth_cookie( '', 'logged_in' );

	if ( ! $user_id ) {
		return array();
	}

	$user = get_userdata( $user_id );

	return $user ? array_values( (array) $user->roles ) : array();
}

function dn_bfs_request_context( $now, $ua_raw ) {
	$ip = dn_bfs_get_client_ip();

	return array(
		'now'       => (int) $now,
		'settings'  => dn_bfs_get_tracking_settings(),
		'ip'        => $ip,
		'ip_hash'   => dn_bfs_ip_hash( $ip, $now ),
		'ua_raw'    => (string) $ua_raw,
		'ua'        => dn_bfs_parse_user_agent( $ua_raw ),
		'roles'     => dn_bfs_request_roles(),
		'site_host' => dn_bfs_site_host(),
	);
}
```

- [ ] **Step 3: Viết test tích hợp — `tests/integration/test-schema.php`**

```php
<?php

dn_bfs_it(
	'schema creates all tracking tables',
	function () {
		global $wpdb;

		foreach ( array( 'visitors', 'sessions', 'pageviews', 'events', 'daily', 'api_keys' ) as $name ) {
			$table = dn_bfs_table( $name );
			dn_bfs_assert_same( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), $name );
		}

		dn_bfs_assert_same( '4', (string) get_option( 'dn_burst_funnel_stats_schema_version' ), 'schema version' );
	}
);

dn_bfs_it(
	'ip hash is stable per day and changes across days',
	function () {
		$day1 = strtotime( '2026-10-01 12:00:00 UTC' );

		dn_bfs_assert_same( dn_bfs_ip_hash( '203.0.113.9', $day1 ), dn_bfs_ip_hash( '203.0.113.9', $day1 + 60 ) );
		dn_bfs_assert_true( dn_bfs_ip_hash( '203.0.113.9', $day1 ) !== dn_bfs_ip_hash( '203.0.113.9', $day1 + 2 * DAY_IN_SECONDS ), 'differs across days' );
		dn_bfs_assert_same( '', dn_bfs_ip_hash( 'unknown', $day1 ) );
	}
);
```

(Test chạy ở Task 9 sau khi plugin nạp các file này.)

- [ ] **Step 4: Commit**

```bash
git add includes/tracking/schema.php includes/tracking/context.php tests/integration/test-schema.php
git commit -m "feat(tracking): add schema-4 tables and request context helpers

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Nạp file mới, migration schema 4, bỏ phụ thuộc Burst

**Files:**
- Modify: `dn-burst-funnel-stats.php`

**Interfaces:**
- Consumes: mọi file của Task 3–8.
- Produces: `DN_BURST_FUNNEL_STATS_SCHEMA_VERSION = '4'`; `dn_burst_funnel_stats_load_tracking()` nạp các file tracking (dùng ở activate và bootstrap); plugin chỉ yêu cầu WooCommerce.

- [ ] **Step 1: Sửa header và hằng số**

Trong `dn-burst-funnel-stats.php`:
- Dòng ` * Description:` → ` * Description: Funnel dashboard for WooCommerce with built-in visitor tracking and WooCommerce order metrics.`
- `define('DN_BURST_FUNNEL_STATS_SCHEMA_VERSION', '3');` → `define('DN_BURST_FUNNEL_STATS_SCHEMA_VERSION', '4');`

- [ ] **Step 2: Xóa ba hàm phát hiện Burst và rút gọn kiểm tra phụ thuộc**

Xóa hoàn toàn `dn_burst_funnel_stats_is_any_plugin_in_folder_active()` và `dn_burst_funnel_stats_is_burst_pro_active()` cùng docblock của chúng. Thay `dn_burst_funnel_stats_missing_dependencies()` bằng:

```php
function dn_burst_funnel_stats_missing_dependencies()
{
  $missing = array();

  if (! dn_burst_funnel_stats_is_plugin_active_safe('woocommerce/woocommerce.php')) {
    $missing[] = 'WooCommerce';
  }

  return $missing;
}
```

- [ ] **Step 3: Thêm hàm nạp file tracking** — ngay trên `dn_burst_funnel_stats_activate()`:

```php
/**
 * Load native tracking modules.
 *
 * @return void
 */
function dn_burst_funnel_stats_load_tracking()
{
  require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/tracking.php';

  foreach (array('ua-parser', 'channel', 'guard', 'geo', 'schema', 'context', 'store', 'collector', 'wc-events') as $module) {
    $file = DN_BURST_FUNNEL_STATS_PATH . 'includes/tracking/' . $module . '.php';

    if (file_exists($file)) {
      require_once $file;
    }
  }
}
```

- [ ] **Step 4: Trong `dn_burst_funnel_stats_activate()`** thay khối

```php
  if (! function_exists('dn_bfs_get_tracking_settings')) {
    require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/tracking.php';
  }
```

bằng `  dn_burst_funnel_stats_load_tracking();`

- [ ] **Step 5: Thay toàn bộ `dn_burst_funnel_stats_maybe_migrate()`**

```php
function dn_burst_funnel_stats_maybe_migrate()
{
  $current_schema = (string) get_option('dn_burst_funnel_stats_schema_version', '1');

  if (version_compare($current_schema, DN_BURST_FUNNEL_STATS_SCHEMA_VERSION, '>=')) {
    return;
  }

  update_option('dn_burst_funnel_stats_tracking_settings', dn_bfs_get_tracking_settings(), false);

  if (false === get_option('dn_burst_funnel_stats_url_tracking_settings', false)) {
    update_option(
      'dn_burst_funnel_stats_url_tracking_settings',
      array(
        'default_group' => 'campaign',
      ),
      false
    );
  }

  dn_bfs_install_schema();

  update_option('dn_burst_funnel_stats_schema_version', DN_BURST_FUNNEL_STATS_SCHEMA_VERSION, false);
}
```

- [ ] **Step 6: Trong `dn_burst_funnel_stats_bootstrap()`** thay dòng `require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/tracking.php';` bằng `dn_burst_funnel_stats_load_tracking();`

- [ ] **Step 7: Kích hoạt plugin và chạy test tích hợp**

Run: `./docker/setup.sh`
Expected: `Ready: …` (lần này `plugin activate dn-burst-funnel-stats` thành công).

Run: `docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php`
Expected:
```
PASS schema creates all tracking tables
PASS ip hash is stable per day and changes across days
2 passed, 0 failed
```

Run: `curl -s http://localhost:8080/wp-admin/ -o /dev/null -w '%{http_code}\n'`
Expected: `302` (chuyển tới trang đăng nhập — không có fatal error).

- [ ] **Step 8: Commit**

```bash
git add dn-burst-funnel-stats.php
git commit -m "feat: load native tracking, migrate to schema 4, drop Burst dependency

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Store — visitor, session, pageview, ping, đếm bị chặn

**Files:**
- Create: `includes/tracking/store.php`, `tests/integration/test-store-pageviews.php`

**Interfaces:**
- Consumes: `dn_bfs_table()`, context array từ `dn_bfs_request_context()`, `dn_bfs_extract_utm()`, `dn_bfs_referrer_host()`, `dn_bfs_classify_channel()`, `dn_bfs_geo_lookup()`.
- Produces (tất cả trả `array( 'ok' => bool, 'reason' => string, … )`):
  - `dn_bfs_store_count_blocked( string $reason, int $now ): void` — tăng `dnbfs_daily` (`dimension='blocked'`, `dim_value=$reason`, cột `pageviews`).
  - `dn_bfs_store_get_session( string $session_uid ): ?array`.
  - `dn_bfs_store_ensure_session( array $hit, array $ctx ): array` → thêm khóa `session` (array row) khi ok. `$hit` cần `vid, sid, path, query, ref`. Lý do lỗi: `spam`, `session_mismatch`, `rate_sessions`, `db_error`.
  - `dn_bfs_store_mark_spam( int $session_id ): void` — bắn action `dn_bfs_session_marked_spam( int $session_id, int $started_at )`.
  - `dn_bfs_store_track_pageview( array $hit, array $ctx ): array` → thêm `pvid` (int) khi ok; `reason='reload'` khi trùng F5 (vẫn ok). Lý do lỗi thêm: `rate_pageviews`, `session_cap`.
  - `dn_bfs_store_track_ping( array $hit, array $ctx ): array` — lý do lỗi: `unknown_session`, `spam`, `session_mismatch`.
  - Hook nội bộ cho Task 11: sau khi chèn pageview, gọi `dn_bfs_store_after_pageview( array $session, array $hit, array $ctx )` nếu hàm tồn tại.

- [ ] **Step 1: Viết test tích hợp fail — `tests/integration/test-store-pageviews.php`**

```php
<?php

function dn_bfs_it_hit( $overrides = array() ) {
	return array_merge(
		array(
			't'     => 'pv',
			'vid'   => dn_bfs_it_uid( 'visitor-1' ),
			'sid'   => dn_bfs_it_uid( 'session-1' ),
			'path'  => '/',
			'query' => 'utm_source=facebook&utm_medium=cpc&utm_campaign=sale-10',
			'ref'   => 'https://l.facebook.com/',
			'ptype' => 'home',
			'pid'   => 0,
			'sw'    => 390,
		),
		$overrides
	);
}

function dn_bfs_it_ctx( $now, $ua = DN_BFS_IT_UA ) {
	return dn_bfs_request_context( $now, $ua );
}

dn_bfs_it(
	'first pageview creates visitor, session with attribution, and pageview',
	function () {
		$now    = time();
		$result = dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now ) );

		dn_bfs_assert_true( $result['ok'], 'ok' );
		dn_bfs_assert_true( $result['pvid'] > 0, 'pvid' );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'visitors' ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'pageviews' ) );

		$session = dn_bfs_store_get_session( dn_bfs_it_uid( 'session-1' ) );
		dn_bfs_assert_same( 'paid', $session['channel'] );
		dn_bfs_assert_same( 'facebook', $session['utm_source'] );
		dn_bfs_assert_same( 'sale-10', $session['utm_campaign'] );
		dn_bfs_assert_same( 'facebook.com', $session['referrer_host'] );
		dn_bfs_assert_same( 'desktop', $session['device'] );
		dn_bfs_assert_same( 'Chrome', $session['browser'] );
		dn_bfs_assert_same( '1', $session['is_new_visitor'] );
		dn_bfs_assert_same( '1', $session['pageviews'] );
		dn_bfs_assert_same( '1', $session['is_bounce'] );
		dn_bfs_assert_same( 64, strlen( $session['ip_hash'] ) );
	}
);

dn_bfs_it(
	'reload of same path within 10 seconds returns same pageview',
	function () {
		$now    = time();
		$first  = dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now ) );
		$reload = dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now + 5 ) );
		$later  = dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now + 11 ) );

		dn_bfs_assert_same( 'reload', $reload['reason'] );
		dn_bfs_assert_same( $first['pvid'], $reload['pvid'] );
		dn_bfs_assert_true( $later['pvid'] !== $first['pvid'], 'new pageview after window' );
		dn_bfs_assert_same( 2, dn_bfs_it_count( 'pageviews' ) );
	}
);

dn_bfs_it(
	'second page clears bounce and updates exit path; returning visitor is not new',
	function () {
		$now = time();
		dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now ) );
		dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'path' => '/shop/' ) ), dn_bfs_it_ctx( $now + 20 ) );

		$session = dn_bfs_store_get_session( dn_bfs_it_uid( 'session-1' ) );
		dn_bfs_assert_same( '0', $session['is_bounce'] );
		dn_bfs_assert_same( '/shop/', $session['exit_path'] );
		dn_bfs_assert_same( '/', $session['entry_path'] );

		dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'sid' => dn_bfs_it_uid( 'session-2' ) ) ), dn_bfs_it_ctx( $now + 3600 ) );
		$second = dn_bfs_store_get_session( dn_bfs_it_uid( 'session-2' ) );
		dn_bfs_assert_same( '0', $second['is_new_visitor'] );
	}
);

dn_bfs_it(
	'session belonging to another visitor is rejected',
	function () {
		$now = time();
		dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now ) );
		$result = dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'vid' => dn_bfs_it_uid( 'other' ) ) ), dn_bfs_it_ctx( $now + 30 ) );

		dn_bfs_assert_same( 'session_mismatch', $result['reason'] );
	}
);

dn_bfs_it(
	'more than 60 pageviews per minute marks the session as spam',
	function () {
		$now = time();

		for ( $i = 0; $i < 60; $i++ ) {
			dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'path' => '/p' . $i ) ), dn_bfs_it_ctx( $now ) );
		}

		$result = dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'path' => '/p61' ) ), dn_bfs_it_ctx( $now + 1 ) );
		dn_bfs_assert_same( 'rate_pageviews', $result['reason'] );

		$session = dn_bfs_store_get_session( dn_bfs_it_uid( 'session-1' ) );
		dn_bfs_assert_same( '1', $session['is_spam'] );

		$after = dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'path' => '/later' ) ), dn_bfs_it_ctx( $now + 600 ) );
		dn_bfs_assert_same( 'spam', $after['reason'] );
	}
);

dn_bfs_it(
	'session cap of 300 pageviews marks spam',
	function () {
		global $wpdb;

		$now = time();
		dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now ) );
		$wpdb->update( dn_bfs_table( 'sessions' ), array( 'pageviews' => 300 ), array( 'session_uid' => dn_bfs_it_uid( 'session-1' ) ) );

		$result = dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'path' => '/x' ) ), dn_bfs_it_ctx( $now + 120 ) );
		dn_bfs_assert_same( 'session_cap', $result['reason'] );
	}
);

dn_bfs_it(
	'more than 20 new sessions per hour from one IP is blocked and marked spam',
	function () {
		$now = time();

		for ( $i = 0; $i < 20; $i++ ) {
			dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'vid' => dn_bfs_it_uid( 'v' . $i ), 'sid' => dn_bfs_it_uid( 's' . $i ) ) ), dn_bfs_it_ctx( $now + $i ) );
		}

		$result = dn_bfs_store_track_pageview( dn_bfs_it_hit( array( 'vid' => dn_bfs_it_uid( 'v21' ), 'sid' => dn_bfs_it_uid( 's21' ) ) ), dn_bfs_it_ctx( $now + 30 ) );

		dn_bfs_assert_same( 'rate_sessions', $result['reason'] );
		dn_bfs_assert_same( 20, dn_bfs_it_count( 'sessions', 'is_spam = 1' ) );
	}
);

dn_bfs_it(
	'ping updates time on page and session duration',
	function () {
		$now = time();
		$pv  = dn_bfs_store_track_pageview( dn_bfs_it_hit(), dn_bfs_it_ctx( $now ) );
		$hit = array( 't' => 'ping', 'vid' => dn_bfs_it_uid( 'visitor-1' ), 'sid' => dn_bfs_it_uid( 'session-1' ), 'pvid' => $pv['pvid'], 'engaged' => 45 );

		dn_bfs_assert_true( dn_bfs_store_track_ping( $hit, dn_bfs_it_ctx( $now + 50 ) )['ok'], 'ping ok' );
		dn_bfs_store_track_ping( array_merge( $hit, array( 'engaged' => 30 ) ), dn_bfs_it_ctx( $now + 55 ) );

		$session = dn_bfs_store_get_session( dn_bfs_it_uid( 'session-1' ) );
		dn_bfs_assert_same( '45', $session['duration'] );
		dn_bfs_assert_same( (string) ( $now + 55 ), $session['last_activity'] );
	}
);

dn_bfs_it(
	'blocked counter accumulates per reason per day',
	function () {
		global $wpdb;

		$now = time();
		dn_bfs_store_count_blocked( 'bot', $now );
		dn_bfs_store_count_blocked( 'bot', $now );
		dn_bfs_store_count_blocked( 'bad_origin', $now );

		$bot = $wpdb->get_var( $wpdb->prepare( 'SELECT pageviews FROM ' . dn_bfs_table( 'daily' ) . " WHERE dimension = 'blocked' AND dim_value = %s", 'bot' ) );
		dn_bfs_assert_same( '2', $bot );
	}
);
```

- [ ] **Step 2: Chạy test, xác nhận fail**

Run: `docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php`
Expected: các test mới `FAIL … Call to undefined function dn_bfs_store_track_pageview()`; dòng cuối `2 passed, 9 failed`.

- [ ] **Step 3: Tạo `includes/tracking/store.php`**

```php
<?php
/**
 * Database writes for tracking: visitors, sessions, pageviews, events.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_store_result( $ok, $reason = '', $extra = array() ) {
	return array_merge(
		array(
			'ok'     => (bool) $ok,
			'reason' => (string) $reason,
		),
		$extra
	);
}

function dn_bfs_store_count_blocked( $reason, $now ) {
	global $wpdb;

	$reason = substr( sanitize_key( $reason ), 0, 64 );

	$wpdb->query(
		$wpdb->prepare(
			'INSERT INTO ' . dn_bfs_table( 'daily' ) . " (date, dimension, dim_hash, dim_value, pageviews)
			VALUES (%s, 'blocked', %s, %s, 1)
			ON DUPLICATE KEY UPDATE pageviews = pageviews + 1",
			wp_date( 'Y-m-d', $now ),
			md5( $reason ),
			$reason
		)
	);
}

function dn_bfs_store_get_session( $session_uid ) {
	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare( 'SELECT * FROM ' . dn_bfs_table( 'sessions' ) . ' WHERE session_uid = %s', $session_uid ),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

function dn_bfs_store_mark_spam( $session_id ) {
	global $wpdb;

	$session_id = (int) $session_id;
	$started_at = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT started_at FROM ' . dn_bfs_table( 'sessions' ) . ' WHERE id = %d', $session_id ) );

	$wpdb->update( dn_bfs_table( 'sessions' ), array( 'is_spam' => 1 ), array( 'id' => $session_id ), array( '%d' ), array( '%d' ) );

	do_action( 'dn_bfs_session_marked_spam', $session_id, $started_at );
}

function dn_bfs_store_ensure_session( $hit, $ctx ) {
	global $wpdb;

	$now      = (int) $ctx['now'];
	$settings = $ctx['settings'];
	$session  = dn_bfs_store_get_session( $hit['sid'] );

	if ( $session ) {
		if ( $session['visitor_uid'] !== $hit['vid'] ) {
			return dn_bfs_store_result( false, 'session_mismatch' );
		}

		if ( '1' === (string) $session['is_spam'] ) {
			return dn_bfs_store_result( false, 'spam' );
		}

		return dn_bfs_store_result( true, '', array( 'session' => $session ) );
	}

	$sessions_table = dn_bfs_table( 'sessions' );

	if ( '' !== $ctx['ip_hash'] ) {
		$since  = $now - HOUR_IN_SECONDS;
		$recent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sessions_table} WHERE ip_hash = %s AND started_at >= %d", $ctx['ip_hash'], $since ) );

		if ( $recent >= (int) $settings['limit_sessions_per_hour'] ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$sessions_table} WHERE ip_hash = %s AND started_at >= %d AND is_spam = 0", $ctx['ip_hash'], $since ) );

			foreach ( $ids as $id ) {
				dn_bfs_store_mark_spam( (int) $id );
			}

			return dn_bfs_store_result( false, 'rate_sessions' );
		}
	}

	$visitors_table = dn_bfs_table( 'visitors' );
	$is_new         = null === $wpdb->get_var( $wpdb->prepare( "SELECT visitor_uid FROM {$visitors_table} WHERE visitor_uid = %s", $hit['vid'] ) );

	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$visitors_table} (visitor_uid, first_seen, last_seen, sessions_count) VALUES (%s, %d, %d, 1)
			ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), sessions_count = sessions_count + 1",
			$hit['vid'],
			$now,
			$now
		)
	);

	$utm      = dn_bfs_extract_utm( $hit['query'] );
	$ref_host = dn_bfs_referrer_host( $hit['ref'] );
	$ref_host = $ref_host === $ctx['site_host'] ? '' : $ref_host;
	$geo      = dn_bfs_geo_lookup( $ctx['ip'], wp_unslash( $_SERVER ), $settings );

	$wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$sessions_table}
			(session_uid, visitor_uid, started_at, last_activity, is_new_visitor, entry_path, exit_path, referrer_host, channel,
			utm_source, utm_medium, utm_campaign, utm_content, utm_term, device, browser, os, country, city, ip_hash)
			VALUES (%s, %s, %d, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
			$hit['sid'],
			$hit['vid'],
			$now,
			$now,
			$is_new ? 1 : 0,
			$hit['path'],
			$hit['path'],
			$ref_host,
			dn_bfs_classify_channel( $utm, $ref_host, $ctx['site_host'] ),
			$utm['source'],
			$utm['medium'],
			$utm['campaign'],
			$utm['content'],
			$utm['term'],
			$ctx['ua']['device'],
			$ctx['ua']['browser'],
			$ctx['ua']['os'],
			$geo['country'],
			$geo['city'],
			$ctx['ip_hash']
		)
	);

	$session = dn_bfs_store_get_session( $hit['sid'] );

	if ( ! $session ) {
		return dn_bfs_store_result( false, 'db_error' );
	}

	return dn_bfs_store_result( true, '', array( 'session' => $session ) );
}

function dn_bfs_store_track_pageview( $hit, $ctx ) {
	global $wpdb;

	$result = dn_bfs_store_ensure_session( $hit, $ctx );

	if ( ! $result['ok'] ) {
		return $result;
	}

	$session    = $result['session'];
	$session_id = (int) $session['id'];
	$now        = (int) $ctx['now'];
	$settings   = $ctx['settings'];
	$pv_table   = dn_bfs_table( 'pageviews' );

	$last_minute = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pv_table} WHERE session_id = %d AND time >= %d", $session_id, $now - MINUTE_IN_SECONDS ) );

	if ( $last_minute >= (int) $settings['limit_pv_per_min'] ) {
		dn_bfs_store_mark_spam( $session_id );
		return dn_bfs_store_result( false, 'rate_pageviews' );
	}

	if ( (int) $session['pageviews'] >= (int) $settings['limit_pv_per_session'] ) {
		dn_bfs_store_mark_spam( $session_id );
		return dn_bfs_store_result( false, 'session_cap' );
	}

	$last = $wpdb->get_row( $wpdb->prepare( "SELECT id, path, time FROM {$pv_table} WHERE session_id = %d ORDER BY id DESC LIMIT 1", $session_id ), ARRAY_A );

	if ( $last && $last['path'] === $hit['path'] && ( $now - (int) $last['time'] ) < (int) $settings['reload_window'] ) {
		return dn_bfs_store_result( true, 'reload', array( 'pvid' => (int) $last['id'] ) );
	}

	$wpdb->insert(
		$pv_table,
		array(
			'session_id'  => $session_id,
			'visitor_uid' => $hit['vid'],
			'time'        => $now,
			'path'        => $hit['path'],
			'page_type'   => isset( $hit['ptype'] ) ? $hit['ptype'] : 'other',
			'object_id'   => isset( $hit['pid'] ) ? (int) $hit['pid'] : 0,
		),
		array( '%d', '%s', '%d', '%s', '%s', '%d' )
	);

	$pvid = (int) $wpdb->insert_id;

	// MySQL evaluates SET assignments left to right, so is_bounce sees the incremented pageviews.
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE ' . dn_bfs_table( 'sessions' ) . ' SET pageviews = pageviews + 1, is_bounce = IF(pageviews > 1, 0, 1), last_activity = %d, exit_path = %s WHERE id = %d',
			$now,
			$hit['path'],
			$session_id
		)
	);

	$wpdb->update( dn_bfs_table( 'visitors' ), array( 'last_seen' => $now ), array( 'visitor_uid' => $hit['vid'] ), array( '%d' ), array( '%s' ) );

	if ( function_exists( 'dn_bfs_store_after_pageview' ) ) {
		dn_bfs_store_after_pageview( $session, $hit, $ctx );
	}

	return dn_bfs_store_result( true, '', array( 'pvid' => $pvid ) );
}

function dn_bfs_store_track_ping( $hit, $ctx ) {
	global $wpdb;

	$session = dn_bfs_store_get_session( $hit['sid'] );

	if ( ! $session ) {
		return dn_bfs_store_result( false, 'unknown_session' );
	}

	if ( $session['visitor_uid'] !== $hit['vid'] ) {
		return dn_bfs_store_result( false, 'session_mismatch' );
	}

	if ( '1' === (string) $session['is_spam'] ) {
		return dn_bfs_store_result( false, 'spam' );
	}

	$session_id = (int) $session['id'];
	$pv_table   = dn_bfs_table( 'pageviews' );

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$pv_table} SET time_on_page = GREATEST(time_on_page, %d) WHERE id = %d AND session_id = %d",
			(int) $hit['engaged'],
			(int) $hit['pvid'],
			$session_id
		)
	);

	$wpdb->query(
		$wpdb->prepare(
			'UPDATE ' . dn_bfs_table( 'sessions' ) . " SET last_activity = %d, duration = (SELECT COALESCE(SUM(time_on_page), 0) FROM {$pv_table} WHERE session_id = %d) WHERE id = %d",
			(int) $ctx['now'],
			$session_id,
			$session_id
		)
	);

	return dn_bfs_store_result( true );
}
```

- [ ] **Step 4: Chạy test tích hợp**

Run: `docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php`
Expected: `11 passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add includes/tracking/store.php tests/integration/test-store-pageviews.php
git commit -m "feat(tracking): store sessions, pageviews and pings with reload and rate limits

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Store — sự kiện sản phẩm (quy tắc 5 phút), checkout, Add To Cart + Cart

**Files:**
- Modify: `includes/tracking/store.php` (thêm hàm vào cuối file)
- Create: `tests/integration/test-store-events.php`

**Interfaces:**
- Consumes: Task 10.
- Produces:
  - `dn_bfs_store_insert_event( array $session, string $type, array $fields, int $now ): int` — `$fields` có thể chứa `product_id`, `qty`, `value`, `order_id`; attribution (`channel, utm_source, utm_medium, utm_campaign, country, device`) lấy từ `$session`; dùng `INSERT IGNORE`; trả id (0 nếu bị bỏ qua).
  - `dn_bfs_store_find_recent_event( string $type, string $visitor_uid, string $ip_hash, int $product_id, int $since ): ?array`.
  - `dn_bfs_store_record_product_view( array $session, int $product_id, array $ctx ): array` — `reason='duplicate'` khi trong cửa sổ.
  - `dn_bfs_store_record_checkout( array $session, array $ctx ): array` — `reason='duplicate'` khi đã có trong phiên.
  - `dn_bfs_store_track_add_to_cart( array $session, int $product_id, int $qty, float $value, array $ctx ): array` — ghi `add_to_cart` và `cart`; `reason='duplicate'` (ok=false) cộng qty/attempts; `reason='rate_atc'` đánh dấu spam.
  - `dn_bfs_store_after_pageview( array $session, array $hit, array $ctx ): void` — `product` → product view (nếu `dn_bfs_should_track_product`), `checkout` → checkout.

- [ ] **Step 1: Viết test tích hợp fail — `tests/integration/test-store-events.php`**

```php
<?php

function dn_bfs_it_session( $now, $seed = '1' ) {
	$hit = array(
		'vid'   => dn_bfs_it_uid( 'visitor-' . $seed ),
		'sid'   => dn_bfs_it_uid( 'session-' . $seed ),
		'path'  => '/',
		'query' => 'utm_campaign=sale-10&utm_source=facebook&utm_medium=cpc',
		'ref'   => '',
	);

	return dn_bfs_store_ensure_session( $hit, dn_bfs_request_context( $now, DN_BFS_IT_UA ) )['session'];
}

dn_bfs_it(
	'product view counts once per visitor and product within 5 minutes',
	function () {
		$now     = time();
		$session = dn_bfs_it_session( $now );
		$ctx     = dn_bfs_request_context( $now, DN_BFS_IT_UA );

		dn_bfs_assert_true( dn_bfs_store_record_product_view( $session, 101, $ctx )['ok'], 'first A' );
		dn_bfs_assert_same( 'duplicate', dn_bfs_store_record_product_view( $session, 101, dn_bfs_request_context( $now + 120, DN_BFS_IT_UA ) )['reason'] );
		dn_bfs_assert_true( dn_bfs_store_record_product_view( $session, 102, dn_bfs_request_context( $now + 130, DN_BFS_IT_UA ) )['ok'], 'B counts' );
		dn_bfs_assert_true( dn_bfs_store_record_product_view( $session, 101, dn_bfs_request_context( $now + 301, DN_BFS_IT_UA ) )['ok'], 'A after window' );

		dn_bfs_assert_same( 3, dn_bfs_it_count( 'events', "type = 'product_view'" ) );
	}
);

dn_bfs_it(
	'product view dedupe also applies across cookies from the same IP',
	function () {
		$now = time();
		dn_bfs_store_record_product_view( dn_bfs_it_session( $now, 'a' ), 101, dn_bfs_request_context( $now, DN_BFS_IT_UA ) );
		$result = dn_bfs_store_record_product_view( dn_bfs_it_session( $now + 10, 'b' ), 101, dn_bfs_request_context( $now + 10, DN_BFS_IT_UA ) );

		dn_bfs_assert_same( 'duplicate', $result['reason'] );
	}
);

dn_bfs_it(
	'add to cart writes add_to_cart and cart together and dedupes within 5 minutes',
	function () {
		$now     = time();
		$session = dn_bfs_it_session( $now );

		$first = dn_bfs_store_track_add_to_cart( $session, 101, 1, 20.0, dn_bfs_request_context( $now, DN_BFS_IT_UA ) );
		dn_bfs_assert_true( $first['ok'], 'first ok' );

		for ( $i = 1; $i <= 4; $i++ ) {
			$dup = dn_bfs_store_track_add_to_cart( $session, 101, 2, 40.0, dn_bfs_request_context( $now + $i * 10, DN_BFS_IT_UA ) );
			dn_bfs_assert_same( 'duplicate', $dup['reason'] );
		}

		dn_bfs_store_track_add_to_cart( $session, 102, 1, 30.0, dn_bfs_request_context( $now + 60, DN_BFS_IT_UA ) );
		dn_bfs_store_track_add_to_cart( $session, 101, 1, 20.0, dn_bfs_request_context( $now + 301, DN_BFS_IT_UA ) );

		dn_bfs_assert_same( 3, dn_bfs_it_count( 'events', "type = 'add_to_cart'" ) );
		dn_bfs_assert_same( 3, dn_bfs_it_count( 'events', "type = 'cart'" ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'events', "type = 'add_to_cart' AND product_id = 101 AND qty = 9 AND attempts = 4" ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'events', "type = 'cart' AND utm_campaign = 'sale-10' AND channel = 'paid'" ) );
	}
);

dn_bfs_it(
	'more than 20 add to cart calls per minute marks the session as spam',
	function () {
		$now     = time();
		$session = dn_bfs_it_session( $now );

		for ( $i = 0; $i < 20; $i++ ) {
			dn_bfs_store_track_add_to_cart( $session, 101, 1, 20.0, dn_bfs_request_context( $now + 1, DN_BFS_IT_UA ) );
		}

		$result = dn_bfs_store_track_add_to_cart( $session, 105, 1, 20.0, dn_bfs_request_context( $now + 2, DN_BFS_IT_UA ) );
		dn_bfs_assert_same( 'rate_atc', $result['reason'] );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'sessions', 'is_spam = 1' ) );
	}
);

dn_bfs_it(
	'checkout is recorded once per session',
	function () {
		$now     = time();
		$session = dn_bfs_it_session( $now );

		dn_bfs_assert_true( dn_bfs_store_record_checkout( $session, dn_bfs_request_context( $now, DN_BFS_IT_UA ) )['ok'], 'first' );
		dn_bfs_assert_same( 'duplicate', dn_bfs_store_record_checkout( $session, dn_bfs_request_context( $now + 900, DN_BFS_IT_UA ) )['reason'] );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'events', "type = 'checkout_start'" ) );
	}
);

dn_bfs_it(
	'product and checkout pageviews record events through after_pageview',
	function () {
		$now = time();
		$hit = array(
			't'     => 'pv',
			'vid'   => dn_bfs_it_uid( 'visitor-1' ),
			'sid'   => dn_bfs_it_uid( 'session-1' ),
			'path'  => '/product/a/',
			'query' => '',
			'ref'   => '',
			'ptype' => 'product',
			'pid'   => 101,
			'sw'    => 0,
		);

		dn_bfs_store_track_pageview( $hit, dn_bfs_request_context( $now, DN_BFS_IT_UA ) );
		dn_bfs_store_track_pageview( array_merge( $hit, array( 'path' => '/checkout/', 'ptype' => 'checkout', 'pid' => 0 ) ), dn_bfs_request_context( $now + 30, DN_BFS_IT_UA ) );

		dn_bfs_assert_same( 1, dn_bfs_it_count( 'events', "type = 'product_view' AND product_id = 101" ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'events', "type = 'checkout_start'" ) );
	}
);

dn_bfs_it(
	'selected product mode skips untracked products',
	function () {
		dn_bfs_it_settings( array( 'product_tracking_mode' => 'selected', 'selected_product_ids' => array( 101 ) ) );

		$now = time();
		$hit = array(
			't'     => 'pv',
			'vid'   => dn_bfs_it_uid( 'visitor-1' ),
			'sid'   => dn_bfs_it_uid( 'session-1' ),
			'path'  => '/product/b/',
			'query' => '',
			'ref'   => '',
			'ptype' => 'product',
			'pid'   => 102,
			'sw'    => 0,
		);

		dn_bfs_store_track_pageview( $hit, dn_bfs_request_context( $now, DN_BFS_IT_UA ) );
		dn_bfs_assert_same( 0, dn_bfs_it_count( 'events', "type = 'product_view'" ) );
	}
);
```

- [ ] **Step 2: Chạy test, xác nhận fail**

Run: `docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php`
Expected: 7 test mới FAIL (`Call to undefined function dn_bfs_store_record_product_view()`).

- [ ] **Step 3: Thêm vào cuối `includes/tracking/store.php`**

```php
function dn_bfs_store_insert_event( $session, $type, $fields, $now ) {
	global $wpdb;

	$order_id = isset( $fields['order_id'] ) && $fields['order_id'] ? (int) $fields['order_id'] : null;

	$wpdb->query(
		$wpdb->prepare(
			'INSERT IGNORE INTO ' . dn_bfs_table( 'events' ) . '
			(session_id, visitor_uid, time, type, product_id, qty, value, order_id, channel, utm_source, utm_medium, utm_campaign, country, device)
			VALUES (%d, %s, %d, %s, %d, %d, %f, ' . ( null === $order_id ? 'NULL' : '%d' ) . ', %s, %s, %s, %s, %s, %s)',
			array_merge(
				array(
					(int) $session['id'],
					(string) $session['visitor_uid'],
					(int) $now,
					$type,
					isset( $fields['product_id'] ) ? (int) $fields['product_id'] : 0,
					isset( $fields['qty'] ) ? (int) $fields['qty'] : 0,
					isset( $fields['value'] ) ? (float) $fields['value'] : 0,
				),
				null === $order_id ? array() : array( $order_id ),
				array(
					(string) $session['channel'],
					(string) $session['utm_source'],
					(string) $session['utm_medium'],
					(string) $session['utm_campaign'],
					(string) $session['country'],
					(string) $session['device'],
				)
			)
		)
	);

	return (int) $wpdb->insert_id;
}

function dn_bfs_store_find_recent_event( $type, $visitor_uid, $ip_hash, $product_id, $since ) {
	global $wpdb;

	$events   = dn_bfs_table( 'events' );
	$sessions = dn_bfs_table( 'sessions' );

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT e.* FROM {$events} e
			LEFT JOIN {$sessions} s ON s.id = e.session_id
			WHERE e.type = %s AND e.product_id = %d AND e.time >= %d
				AND (e.visitor_uid = %s OR (%s <> '' AND s.ip_hash = %s))
			ORDER BY e.time DESC LIMIT 1",
			$type,
			(int) $product_id,
			(int) $since,
			$visitor_uid,
			$ip_hash,
			$ip_hash
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

function dn_bfs_store_record_product_view( $session, $product_id, $ctx ) {
	$now    = (int) $ctx['now'];
	$recent = dn_bfs_store_find_recent_event( 'product_view', $session['visitor_uid'], $ctx['ip_hash'], $product_id, $now - (int) $ctx['settings']['dedupe_window'] );

	if ( $recent ) {
		return dn_bfs_store_result( false, 'duplicate' );
	}

	dn_bfs_store_insert_event( $session, 'product_view', array( 'product_id' => $product_id ), $now );

	return dn_bfs_store_result( true );
}

function dn_bfs_store_record_checkout( $session, $ctx ) {
	global $wpdb;

	$exists = $wpdb->get_var(
		$wpdb->prepare( 'SELECT id FROM ' . dn_bfs_table( 'events' ) . " WHERE session_id = %d AND type = 'checkout_start' LIMIT 1", (int) $session['id'] )
	);

	if ( $exists ) {
		return dn_bfs_store_result( false, 'duplicate' );
	}

	dn_bfs_store_insert_event( $session, 'checkout_start', array(), (int) $ctx['now'] );

	return dn_bfs_store_result( true );
}

function dn_bfs_store_track_add_to_cart( $session, $product_id, $qty, $value, $ctx ) {
	global $wpdb;

	$now    = (int) $ctx['now'];
	$events = dn_bfs_table( 'events' );
	$qty    = max( 1, (int) $qty );

	$calls = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) + COALESCE(SUM(attempts), 0) FROM {$events} WHERE visitor_uid = %s AND type = 'add_to_cart' AND time >= %d",
			$session['visitor_uid'],
			$now - MINUTE_IN_SECONDS
		)
	);

	if ( $calls >= (int) $ctx['settings']['limit_atc_per_min'] ) {
		dn_bfs_store_mark_spam( (int) $session['id'] );
		return dn_bfs_store_result( false, 'rate_atc' );
	}

	$recent = dn_bfs_store_find_recent_event( 'add_to_cart', $session['visitor_uid'], $ctx['ip_hash'], $product_id, $now - (int) $ctx['settings']['dedupe_window'] );

	if ( $recent ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$events} SET qty = qty + %d, attempts = attempts + 1 WHERE id = %d", $qty, (int) $recent['id'] ) );
		return dn_bfs_store_result( false, 'duplicate' );
	}

	$fields = array(
		'product_id' => (int) $product_id,
		'qty'        => $qty,
		'value'      => (float) $value,
	);

	dn_bfs_store_insert_event( $session, 'add_to_cart', $fields, $now );
	dn_bfs_store_insert_event( $session, 'cart', $fields, $now );

	return dn_bfs_store_result( true );
}

function dn_bfs_store_after_pageview( $session, $hit, $ctx ) {
	if ( 'product' === $hit['ptype'] && (int) $hit['pid'] > 0 && dn_bfs_should_track_product( (int) $hit['pid'] ) ) {
		dn_bfs_store_record_product_view( $session, (int) $hit['pid'], $ctx );
	}

	if ( 'checkout' === $hit['ptype'] ) {
		dn_bfs_store_record_checkout( $session, $ctx );
	}
}
```

- [ ] **Step 4: Chạy test tích hợp**

Run: `docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php`
Expected: `18 passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add includes/tracking/store.php tests/integration/test-store-events.php
git commit -m "feat(tracking): record product views, checkout and add-to-cart with 5-minute dedupe

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Endpoint `/collect`, nạp tracker, `assets/tracker.js`

**Files:**
- Create: `includes/tracking/collector.php`, `assets/tracker.js`, `tests/integration/test-collector.php`

**Interfaces:**
- Consumes: `dn_bfs_guard_validate_payload()`, `dn_bfs_guard_check_visitor()`, `dn_bfs_guard_is_page_selected()`, `dn_bfs_request_context()`, `dn_bfs_store_track_pageview()`, `dn_bfs_store_track_ping()`, `dn_bfs_store_count_blocked()`.
- Produces:
  - `POST /wp-json/dnbfs/v1/collect` → `200 {"pvid": int}` cho `pv` thành công; `204` cho ping và mọi trường hợp bị chặn.
  - `dn_bfs_rest_collect( WP_REST_Request $request ): WP_REST_Response`.
  - `dn_bfs_page_context(): array` → `type, id, endpoint, tz, timeout, cookieDays`.
  - Script handle `dnbfs-tracker` (defer) + `window.dnbfsPage`.

- [ ] **Step 1: Viết test tích hợp fail — `tests/integration/test-collector.php`**

```php
<?php

function dn_bfs_it_pv_body( $overrides = array() ) {
	return array_merge(
		array(
			't'     => 'pv',
			'vid'   => dn_bfs_it_uid( 'visitor-1' ),
			'sid'   => dn_bfs_it_uid( 'session-1' ),
			'path'  => '/',
			'query' => '',
			'ref'   => '',
			'ptype' => 'home',
			'pid'   => 0,
			'sw'    => 1440,
		),
		$overrides
	);
}

dn_bfs_it(
	'collect stores a pageview and returns pvid',
	function () {
		$response = dn_bfs_it_collect( dn_bfs_it_pv_body() );

		dn_bfs_assert_same( 200, $response->get_status() );
		dn_bfs_assert_true( $response->get_data()['pvid'] > 0, 'pvid' );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'pageviews' ) );
	}
);

dn_bfs_it(
	'collect accepts a ping for the returned pageview',
	function () {
		$pvid     = dn_bfs_it_collect( dn_bfs_it_pv_body() )->get_data()['pvid'];
		$response = dn_bfs_it_collect( array( 't' => 'ping', 'vid' => dn_bfs_it_uid( 'visitor-1' ), 'sid' => dn_bfs_it_uid( 'session-1' ), 'pvid' => $pvid, 'engaged' => 12 ) );

		dn_bfs_assert_same( 204, $response->get_status() );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'pageviews', 'time_on_page = 12' ) );
	}
);

dn_bfs_it(
	'collect blocks foreign origin, bots and excluded roles and counts reasons',
	function () {
		dn_bfs_assert_same( 204, dn_bfs_it_collect( dn_bfs_it_pv_body(), array( 'origin' => 'https://evil.test' ) )->get_status() );
		dn_bfs_it_collect( dn_bfs_it_pv_body(), array( 'user_agent' => 'Mozilla/5.0 (compatible; bingbot/2.0)' ) );

		$admin_id                   = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $admin_id, time() + HOUR_IN_SECONDS, 'logged_in' );
		dn_bfs_it_collect( dn_bfs_it_pv_body() );

		dn_bfs_assert_same( 0, dn_bfs_it_count( 'pageviews' ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'daily', "dimension = 'blocked' AND dim_value = 'bad_origin'" ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'daily', "dimension = 'blocked' AND dim_value = 'bot'" ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'daily', "dimension = 'blocked' AND dim_value = 'excluded_role'" ) );
	}
);

dn_bfs_it(
	'collect respects selected page mode',
	function () {
		dn_bfs_it_settings( array( 'page_tracking_mode' => 'selected', 'selected_page_ids' => array( 5 ) ) );

		dn_bfs_it_collect( dn_bfs_it_pv_body( array( 'ptype' => 'other', 'pid' => 6, 'path' => '/about/' ) ) );
		dn_bfs_it_collect( dn_bfs_it_pv_body( array( 'ptype' => 'other', 'pid' => 5, 'path' => '/sale/' ) ) );

		dn_bfs_assert_same( 1, dn_bfs_it_count( 'pageviews' ) );
	}
);

dn_bfs_it(
	'cloudflare connecting IP is used for the session IP hash',
	function () {
		$_SERVER['REMOTE_ADDR']           = '172.68.10.10';
		$_SERVER['HTTP_CF_RAY']           = 'x-SIN';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.44';
		$_SERVER['HTTP_CF_IPCOUNTRY']     = 'VN';

		dn_bfs_it_collect( dn_bfs_it_pv_body() );
		$session = dn_bfs_store_get_session( dn_bfs_it_uid( 'session-1' ) );

		dn_bfs_assert_same( dn_bfs_ip_hash( '198.51.100.44', time(), DN_BFS_IT_UA ), $session['ip_hash'] );
		dn_bfs_assert_same( 'VN', $session['country'] );
	}
);

dn_bfs_it(
	'tracker script and page context are printed on the front end',
	function () {
		$context = dn_bfs_page_context();

		dn_bfs_assert_same( rest_url( 'dnbfs/v1/collect' ), $context['endpoint'] );
		dn_bfs_assert_same( 30, $context['timeout'] );
		dn_bfs_assert_same( 365, $context['cookieDays'] );
	}
);
```

- [ ] **Step 2: Chạy test, xác nhận fail**

Run: `docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php`
Expected: 6 test mới FAIL (route chưa có → status 404 / `Call to undefined function dn_bfs_page_context()`).

- [ ] **Step 3: Tạo `includes/tracking/collector.php`**

```php
<?php
/**
 * Tracking endpoint and front-end tracker loader.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_register_collect_route() {
	register_rest_route(
		'dnbfs/v1',
		'/collect',
		array(
			'methods'             => 'POST',
			'callback'            => 'dn_bfs_rest_collect',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'dn_bfs_register_collect_route' );

function dn_bfs_collect_blocked( $reason, $now ) {
	dn_bfs_store_count_blocked( $reason, $now );

	return new WP_REST_Response( null, 204 );
}

function dn_bfs_rest_collect( WP_REST_Request $request ) {
	$now    = dn_bfs_now();
	$server = array(
		'REQUEST_METHOD' => $request->get_method(),
		'HTTP_ORIGIN'    => (string) $request->get_header( 'origin' ),
		'HTTP_REFERER'   => (string) $request->get_header( 'referer' ),
	);

	$validated = dn_bfs_guard_validate_payload( $request->get_body(), $server, dn_bfs_site_host() );

	if ( ! $validated['ok'] ) {
		return dn_bfs_collect_blocked( $validated['reason'], $now );
	}

	$ctx    = dn_bfs_request_context( $now, (string) $request->get_header( 'user_agent' ) );
	$reason = dn_bfs_guard_check_visitor( $ctx );

	if ( '' !== $reason ) {
		return dn_bfs_collect_blocked( $reason, $now );
	}

	$hit = $validated['data'];

	if ( 'ping' === $hit['t'] ) {
		$result = dn_bfs_store_track_ping( $hit, $ctx );

		return $result['ok'] ? new WP_REST_Response( null, 204 ) : dn_bfs_collect_blocked( $result['reason'], $now );
	}

	if ( ! dn_bfs_guard_is_page_selected( $hit, $ctx['settings'] ) ) {
		return dn_bfs_collect_blocked( 'not_selected', $now );
	}

	$result = dn_bfs_store_track_pageview( $hit, $ctx );

	if ( ! $result['ok'] ) {
		return dn_bfs_collect_blocked( $result['reason'], $now );
	}

	return new WP_REST_Response( array( 'pvid' => (int) $result['pvid'] ), 200 );
}

function dn_bfs_current_page_type() {
	if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		return 'thankyou';
	}

	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		return 'checkout';
	}

	if ( function_exists( 'is_cart' ) && is_cart() ) {
		return 'cart';
	}

	if ( function_exists( 'is_product' ) && is_product() ) {
		return 'product';
	}

	if ( ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) ) {
		return 'category';
	}

	if ( is_front_page() ) {
		return 'home';
	}

	return 'other';
}

function dn_bfs_page_context() {
	$settings = dn_bfs_get_tracking_settings();
	$offset   = wp_timezone()->getOffset( new DateTime( 'now', new DateTimeZone( 'UTC' ) ) );

	return array(
		'type'       => dn_bfs_current_page_type(),
		'id'         => (int) get_queried_object_id(),
		'endpoint'   => rest_url( 'dnbfs/v1/collect' ),
		'tz'         => (int) round( $offset / MINUTE_IN_SECONDS ),
		'timeout'    => (int) $settings['session_timeout'],
		'cookieDays' => (int) $settings['cookie_days'],
	);
}

function dn_bfs_enqueue_tracker() {
	$settings = dn_bfs_get_tracking_settings();

	if ( empty( $settings['tracking_enabled'] ) || is_admin() || is_preview() || is_customize_preview() ) {
		return;
	}

	$path = DN_BURST_FUNNEL_STATS_PATH . 'assets/tracker.js';

	wp_enqueue_script(
		'dnbfs-tracker',
		DN_BURST_FUNNEL_STATS_URL . 'assets/tracker.js',
		array(),
		file_exists( $path ) ? (string) filemtime( $path ) : DN_BURST_FUNNEL_STATS_VERSION,
		array(
			'strategy'  => 'defer',
			'in_footer' => false,
		)
	);

	wp_add_inline_script( 'dnbfs-tracker', 'window.dnbfsPage=' . wp_json_encode( dn_bfs_page_context() ) . ';', 'before' );
}
add_action( 'wp_enqueue_scripts', 'dn_bfs_enqueue_tracker' );
```

- [ ] **Step 4: Tạo `assets/tracker.js`**

```js
/* DN Burst Funnel Stats tracker: sends pageview and engagement beacons. */
(function () {
	'use strict';

	var cfg = window.dnbfsPage;

	if (!cfg || !cfg.endpoint || navigator.webdriver) {
		return;
	}

	var VISITOR = 'dnbfs_vid';
	var SESSION = 'dnbfs_sid';
	var META = 'dnbfs_sm';
	var HEX = /^[a-f0-9]{32}$/;

	function getCookie(name) {
		var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
		return match ? decodeURIComponent(match[1]) : '';
	}

	function setCookie(name, value, seconds) {
		var cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + seconds + '; SameSite=Lax';

		if (location.protocol === 'https:') {
			cookie += '; Secure';
		}

		document.cookie = cookie;
	}

	function uid() {
		var bytes = new Uint8Array(16);
		var out = '';

		(window.crypto || window.msCrypto).getRandomValues(bytes);

		for (var i = 0; i < bytes.length; i++) {
			out += ('0' + bytes[i].toString(16)).slice(-2);
		}

		return out;
	}

	function siteDay() {
		return new Date(Date.now() + (cfg.tz || 0) * 60000).toISOString().slice(0, 10);
	}

	function param(name) {
		var match = location.search.match(new RegExp('[?&]' + name + '=([^&]*)'));
		return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
	}

	var vid = getCookie(VISITOR);
	if (!HEX.test(vid)) {
		vid = uid();
	}
	setCookie(VISITOR, vid, (cfg.cookieDays || 365) * 86400);

	var timeout = (cfg.timeout || 30) * 60;
	var sid = getCookie(SESSION);
	var meta = getCookie(META).split('~');
	var day = siteDay();
	var campaign = param('utm_campaign');

	if (!HEX.test(sid) || meta[0] !== day || (campaign && campaign !== (meta[1] || ''))) {
		sid = uid();
		meta = [day, campaign || (meta[0] === day ? meta[1] || '' : '')];
	}

	function touch() {
		setCookie(SESSION, sid, timeout);
		setCookie(META, meta[0] + '~' + (meta[1] || ''), timeout);
	}

	touch();

	var pvid = 0;
	var engaged = 0;
	var visibleSince = null;
	var started = false;

	function send(body, wantResponse) {
		var json = JSON.stringify(body);

		if (wantResponse && window.fetch) {
			return fetch(cfg.endpoint, {
				method: 'POST',
				body: json,
				keepalive: true,
				credentials: 'same-origin',
				headers: { 'Content-Type': 'text/plain' }
			}).then(function (response) {
				return response.status === 200 ? response.json() : null;
			}).catch(function () {
				return null;
			});
		}

		if (navigator.sendBeacon) {
			navigator.sendBeacon(cfg.endpoint, new Blob([json], { type: 'text/plain' }));
		} else if (window.fetch) {
			fetch(cfg.endpoint, { method: 'POST', body: json, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'text/plain' } });
		}

		return null;
	}

	function engagedSeconds() {
		var total = engaged;

		if (visibleSince !== null) {
			total += (Date.now() - visibleSince) / 1000;
		}

		return Math.min(1800, Math.round(total));
	}

	function ping() {
		if (!pvid) {
			return;
		}

		touch();
		send({ t: 'ping', vid: vid, sid: sid, pvid: pvid, engaged: engagedSeconds() }, false);
	}

	function pauseClock() {
		if (visibleSince !== null) {
			engaged += (Date.now() - visibleSince) / 1000;
			visibleSince = null;
		}
	}

	function start() {
		visibleSince = Date.now();

		var request = send({
			t: 'pv',
			vid: vid,
			sid: sid,
			path: location.pathname,
			query: location.search,
			ref: document.referrer,
			ptype: cfg.type || 'other',
			pid: cfg.id || 0,
			sw: (window.screen && screen.width) || 0
		}, true);

		if (request) {
			request.then(function (data) {
				if (data && data.pvid) {
					pvid = data.pvid;
				}
			});
		}

		setInterval(function () {
			if (document.visibilityState === 'visible') {
				ping();
			}
		}, 30000);
	}

	function onVisibilityChange() {
		if (document.visibilityState === 'visible') {
			if (!started) {
				started = true;
				start();
			} else if (visibleSince === null) {
				visibleSince = Date.now();
			}
		} else if (visibleSince !== null) {
			pauseClock();
			ping();
		}
	}

	document.addEventListener('visibilitychange', onVisibilityChange);
	window.addEventListener('pagehide', function () {
		pauseClock();
		ping();
	});

	onVisibilityChange();
})();
```

- [ ] **Step 5: Chạy test tích hợp**

Run: `docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php`
Expected: `24 passed, 0 failed`.

- [ ] **Step 6: Kiểm tra thủ công bằng curl**

Run:
```bash
curl -s http://localhost:8080/ | grep -o 'window.dnbfsPage=[^;]*'
```
Expected: `window.dnbfsPage={"type":"home","id":…,"endpoint":"http:\/\/localhost:8080\/wp-json\/dnbfs\/v1\/collect",…}`

Run:
```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost:8080/wp-json/dnbfs/v1/collect -H 'Content-Type: text/plain' -d '{"t":"pv","vid":"0123456789abcdef0123456789abcdef","sid":"0123456789abcdef0123456789abcdee","path":"/"}'
```
Expected: `204` (không có Origin → bị chặn `bad_origin`).

- [ ] **Step 7: Kiểm tra trong trình duyệt**

Mở `http://localhost:8080/` trong browser pane, đợi 2 giây, rồi:
Run: `docker compose -f docker/docker-compose.yml run --rm wpcli wp db query "SELECT path, page_type FROM wp_dnbfs_pageviews ORDER BY id DESC LIMIT 1"`
Expected: một dòng `/	home`. Mở một trang sản phẩm → có thêm dòng `page_type=product` và một sự kiện `product_view`. Chuyển tab khác rồi quay lại → `time_on_page` > 0.

- [ ] **Step 8: Commit**

```bash
git add includes/tracking/collector.php assets/tracker.js tests/integration/test-collector.php
git commit -m "feat(tracking): add collect endpoint and front-end tracker beacon

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Hook WooCommerce — Add To Cart + Cart, Order, ép chuyển đến trang Cart

**Files:**
- Create: `includes/tracking/wc-events.php`, `tests/integration/test-wc-events.php`

**Interfaces:**
- Consumes: `dn_bfs_request_context()`, `dn_bfs_guard_check_visitor()`, `dn_bfs_store_ensure_session()`, `dn_bfs_store_track_add_to_cart()`, `dn_bfs_store_insert_event()`, `dn_bfs_store_count_blocked()`, `dn_bfs_extract_utm()`, `dn_bfs_classify_channel()`.
- Produces:
  - `dn_bfs_wc_session_hit(): array` — `vid, sid, path, query, ref` từ cookie (tạo + set cookie khi thiếu) và `HTTP_REFERER`.
  - `dn_bfs_track_add_to_cart( int $product_id, int $qty, float $value, int $check_product_id = 0 ): array`.
  - `dn_bfs_track_order( WC_Order $order ): array` — ghi meta `_dnbfs_session_uid`, `_dnbfs_visitor_uid`; sự kiện `order` duy nhất theo `order_id`; attribution lấy từ phiên, nếu không có phiên thì từ meta `_wc_order_attribution_utm_*`.
  - Filter `pre_option_woocommerce_cart_redirect_after_add` → `'yes'` và `pre_option_woocommerce_enable_ajax_add_to_cart` → `'no'` khi `force_cart_redirect = 1`.

- [ ] **Step 1: Viết test tích hợp fail — `tests/integration/test-wc-events.php`**

```php
<?php

function dn_bfs_it_products( $count = 2 ) {
	return wc_get_products(
		array(
			'limit'   => $count,
			'status'  => 'publish',
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'ids',
		)
	);
}

dn_bfs_it(
	'woocommerce add to cart records add_to_cart and cart once within 5 minutes',
	function () {
		list( $a, $b ) = dn_bfs_it_products();

		$_COOKIE['dnbfs_vid']    = dn_bfs_it_uid( 'visitor-1' );
		$_COOKIE['dnbfs_sid']    = dn_bfs_it_uid( 'session-1' );
		$_SERVER['HTTP_REFERER'] = home_url( '/product/x/?utm_campaign=sale-10&utm_source=facebook&utm_medium=cpc' );

		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $a, 1 );
		WC()->cart->add_to_cart( $a, 1 );
		WC()->cart->add_to_cart( $b, 2 );

		dn_bfs_assert_same( 2, dn_bfs_it_count( 'events', "type = 'add_to_cart'" ) );
		dn_bfs_assert_same( 2, dn_bfs_it_count( 'events', "type = 'cart'" ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'events', "type = 'add_to_cart' AND product_id = {$a} AND qty = 2" ) );

		$session = dn_bfs_store_get_session( dn_bfs_it_uid( 'session-1' ) );
		dn_bfs_assert_same( 'sale-10', $session['utm_campaign'] );
	}
);

dn_bfs_it(
	'add to cart without tracker cookies creates a visitor and session',
	function () {
		list( $a ) = dn_bfs_it_products( 1 );

		$result = dn_bfs_track_add_to_cart( $a, 1, 10.0, $a );

		dn_bfs_assert_true( $result['ok'], 'ok' );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'sessions' ) );
		dn_bfs_assert_true( (bool) preg_match( '/^[a-f0-9]{32}$/', $_COOKIE['dnbfs_vid'] ), 'cookie set' );
	}
);

dn_bfs_it(
	'bot add to cart is ignored and counted as blocked',
	function () {
		list( $a ) = dn_bfs_it_products( 1 );
		$_SERVER['HTTP_USER_AGENT'] = 'python-requests/2.31';

		dn_bfs_track_add_to_cart( $a, 1, 10.0, $a );

		dn_bfs_assert_same( 0, dn_bfs_it_count( 'events' ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'daily', "dimension = 'blocked' AND dim_value = 'bot'" ) );
	}
);

dn_bfs_it(
	'order is recorded once with session attribution and order meta',
	function () {
		list( $a ) = dn_bfs_it_products( 1 );

		$_COOKIE['dnbfs_vid']    = dn_bfs_it_uid( 'visitor-1' );
		$_COOKIE['dnbfs_sid']    = dn_bfs_it_uid( 'session-1' );
		$_SERVER['HTTP_REFERER'] = home_url( '/?utm_campaign=sale-10&utm_source=facebook&utm_medium=cpc' );

		$order = wc_create_order();
		$order->add_product( wc_get_product( $a ), 2 );
		$order->calculate_totals();
		$order->save();

		dn_bfs_track_order( $order );
		dn_bfs_track_order( $order );

		dn_bfs_assert_same( 1, dn_bfs_it_count( 'events', "type = 'order' AND order_id = " . $order->get_id() . " AND utm_campaign = 'sale-10'" ) );
		dn_bfs_assert_same( dn_bfs_it_uid( 'session-1' ), wc_get_order( $order->get_id() )->get_meta( '_dnbfs_session_uid' ) );
	}
);

dn_bfs_it(
	'order from an excluded role is not recorded',
	function () {
		list( $a ) = dn_bfs_it_products( 1 );

		$admin_id                    = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $admin_id, time() + HOUR_IN_SECONDS, 'logged_in' );

		$order = wc_create_order();
		$order->add_product( wc_get_product( $a ), 1 );
		$order->calculate_totals();
		$order->save();

		dn_bfs_track_order( $order );
		dn_bfs_assert_same( 0, dn_bfs_it_count( 'events', "type = 'order'" ) );
	}
);

dn_bfs_it(
	'force cart redirect overrides WooCommerce options',
	function () {
		dn_bfs_it_settings( array( 'force_cart_redirect' => 1 ) );
		dn_bfs_assert_same( 'yes', get_option( 'woocommerce_cart_redirect_after_add' ) );
		dn_bfs_assert_same( 'no', get_option( 'woocommerce_enable_ajax_add_to_cart' ) );

		dn_bfs_it_settings( array( 'force_cart_redirect' => 0 ) );
		update_option( 'woocommerce_cart_redirect_after_add', 'no' );
		dn_bfs_assert_same( 'no', get_option( 'woocommerce_cart_redirect_after_add' ) );
	}
);
```

- [ ] **Step 2: Chạy test, xác nhận fail**

Run: `docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php`
Expected: 6 test mới FAIL (`Call to undefined function dn_bfs_track_add_to_cart()` …). Lưu ý: test đầu có thể đếm thêm sự kiện từ hook cũ trong `dashboard.php` — hook cũ ghi vào option `dn_atc_*`, không vào bảng `dnbfs_events`, nên không ảnh hưởng.

- [ ] **Step 3: Tạo `includes/tracking/wc-events.php`**

```php
<?php
/**
 * WooCommerce server-side events: add to cart (+ cart) and orders.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_wc_cookie_id( $name, $lifetime ) {
	$value = isset( $_COOKIE[ $name ] ) && is_string( $_COOKIE[ $name ] ) ? sanitize_key( wp_unslash( $_COOKIE[ $name ] ) ) : '';

	if ( preg_match( '/^[a-f0-9]{32}$/', $value ) ) {
		return $value;
	}

	$value = bin2hex( random_bytes( 16 ) );

	if ( ! headers_sent() ) {
		setcookie( $name, $value, time() + $lifetime, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
	}

	$_COOKIE[ $name ] = $value;

	return $value;
}

function dn_bfs_wc_session_hit() {
	$settings = dn_bfs_get_tracking_settings();
	$referer  = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
	$path     = '/';
	$query    = '';

	if ( '' !== $referer && dn_bfs_referrer_host( $referer ) === dn_bfs_site_host() ) {
		$path  = (string) wp_parse_url( $referer, PHP_URL_PATH );
		$path  = '' === $path ? '/' : substr( $path, 0, 255 );
		$query = (string) wp_parse_url( $referer, PHP_URL_QUERY );
	}

	return array(
		'vid'   => dn_bfs_wc_cookie_id( DN_BFS_COOKIE_VISITOR, (int) $settings['cookie_days'] * DAY_IN_SECONDS ),
		'sid'   => dn_bfs_wc_cookie_id( DN_BFS_COOKIE_SESSION, (int) $settings['session_timeout'] * MINUTE_IN_SECONDS ),
		'path'  => $path,
		'query' => $query,
		'ref'   => '',
	);
}

function dn_bfs_wc_context( $now ) {
	return dn_bfs_request_context( $now, dn_bfs_get_current_user_agent() );
}

function dn_bfs_track_add_to_cart( $product_id, $qty, $value, $check_product_id = 0 ) {
	$now    = dn_bfs_now();
	$ctx    = dn_bfs_wc_context( $now );
	$reason = dn_bfs_guard_check_visitor( $ctx );

	if ( '' !== $reason ) {
		dn_bfs_store_count_blocked( $reason, $now );
		return dn_bfs_store_result( false, $reason );
	}

	if ( ! dn_bfs_should_track_product( $check_product_id ? $check_product_id : $product_id ) ) {
		return dn_bfs_store_result( false, 'not_selected' );
	}

	$session = dn_bfs_store_ensure_session( dn_bfs_wc_session_hit(), $ctx );

	if ( ! $session['ok'] ) {
		dn_bfs_store_count_blocked( $session['reason'], $now );
		return $session;
	}

	$result = dn_bfs_store_track_add_to_cart( $session['session'], (int) $product_id, (int) $qty, (float) $value, $ctx );

	if ( ! $result['ok'] && 'duplicate' !== $result['reason'] ) {
		dn_bfs_store_count_blocked( $result['reason'], $now );
	}

	return $result;
}

function dn_bfs_wc_on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id ) {
	unset( $cart_item_key );

	$target  = $variation_id ? (int) $variation_id : (int) $product_id;
	$product = wc_get_product( $target );
	$price   = $product ? (float) wc_get_price_to_display( $product ) : 0.0;
	$qty     = max( 1, (int) $quantity );

	dn_bfs_track_add_to_cart( $target, $qty, $price * $qty, (int) $product_id );
}
add_action( 'woocommerce_add_to_cart', 'dn_bfs_wc_on_add_to_cart', 10, 4 );

function dn_bfs_wc_order_fallback_session( $order, $visitor_uid ) {
	$utm = array(
		'source'     => strtolower( (string) $order->get_meta( '_wc_order_attribution_utm_source' ) ),
		'medium'     => strtolower( (string) $order->get_meta( '_wc_order_attribution_utm_medium' ) ),
		'campaign'   => (string) $order->get_meta( '_wc_order_attribution_utm_campaign' ),
		'paid_click' => false,
	);

	return array(
		'id'           => 0,
		'visitor_uid'  => $visitor_uid,
		'channel'      => dn_bfs_classify_channel( $utm, '', dn_bfs_site_host() ),
		'utm_source'   => substr( $utm['source'], 0, 191 ),
		'utm_medium'   => substr( $utm['medium'], 0, 191 ),
		'utm_campaign' => substr( $utm['campaign'], 0, 191 ),
		'country'      => substr( (string) $order->get_billing_country(), 0, 2 ),
		'device'       => '',
	);
}

function dn_bfs_track_order( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return dn_bfs_store_result( false, 'invalid_order' );
	}

	$now    = dn_bfs_now();
	$ctx    = dn_bfs_wc_context( $now );
	$reason = dn_bfs_guard_check_visitor( $ctx );

	if ( '' !== $reason ) {
		return dn_bfs_store_result( false, $reason );
	}

	$hit    = dn_bfs_wc_session_hit();
	$result = dn_bfs_store_ensure_session( $hit, $ctx );

	$session = $result['ok'] ? $result['session'] : dn_bfs_wc_order_fallback_session( $order, $hit['vid'] );

	$order->update_meta_data( '_dnbfs_session_uid', $result['ok'] ? $hit['sid'] : '' );
	$order->update_meta_data( '_dnbfs_visitor_uid', $hit['vid'] );
	$order->save();

	$inserted = dn_bfs_store_insert_event(
		$session,
		'order',
		array(
			'order_id' => $order->get_id(),
			'qty'      => (int) $order->get_item_count(),
			'value'    => (float) $order->get_total(),
		),
		$now
	);

	return dn_bfs_store_result( $inserted > 0, $inserted > 0 ? '' : 'duplicate' );
}

function dn_bfs_wc_on_checkout_order_processed( $order_id, $posted_data = array(), $order = null ) {
	unset( $posted_data );
	dn_bfs_track_order( $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );
}
add_action( 'woocommerce_checkout_order_processed', 'dn_bfs_wc_on_checkout_order_processed', 10, 3 );

function dn_bfs_wc_on_store_api_order_processed( $order ) {
	dn_bfs_track_order( $order );
}
add_action( 'woocommerce_store_api_checkout_order_processed', 'dn_bfs_wc_on_store_api_order_processed', 10, 1 );

function dn_bfs_wc_force_cart_redirect( $value ) {
	$settings = dn_bfs_get_tracking_settings();

	return empty( $settings['force_cart_redirect'] ) ? $value : 'yes';
}
add_filter( 'pre_option_woocommerce_cart_redirect_after_add', 'dn_bfs_wc_force_cart_redirect' );

function dn_bfs_wc_force_no_ajax_add_to_cart( $value ) {
	$settings = dn_bfs_get_tracking_settings();

	return empty( $settings['force_cart_redirect'] ) ? $value : 'no';
}
add_filter( 'pre_option_woocommerce_enable_ajax_add_to_cart', 'dn_bfs_wc_force_no_ajax_add_to_cart' );
```

- [ ] **Step 4: Chạy test tích hợp**

Run: `docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php`
Expected: `30 passed, 0 failed`.

- [ ] **Step 5: Chạy toàn bộ unit test**

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit`
Expected: `OK`.

- [ ] **Step 6: Kiểm tra end-to-end trong trình duyệt**

Trong browser pane (không đăng nhập): mở `http://localhost:8080/?utm_source=facebook&utm_medium=cpc&utm_campaign=sale-10`, vào một sản phẩm, bấm "Add to cart" 3 lần liền, sang sản phẩm khác bấm 1 lần, vào checkout và đặt đơn COD.

Run:
```bash
docker compose -f docker/docker-compose.yml run --rm wpcli wp db query "SELECT type, product_id, qty, attempts, utm_campaign FROM wp_dnbfs_events ORDER BY id"
```
Expected: 2 dòng `product_view`, 2 dòng `add_to_cart` (dòng đầu `qty=3 attempts=2`), 2 dòng `cart`, 1 `checkout_start`, 1 `order` — tất cả `utm_campaign=sale-10`.

- [ ] **Step 7: Commit**

```bash
git add includes/tracking/wc-events.php tests/integration/test-wc-events.php
git commit -m "feat(tracking): record WooCommerce add-to-cart, cart and orders server-side

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Tự kiểm tra kế hoạch so với spec (phạm vi Kế hoạch 1)

| Yêu cầu spec | Task |
|---|---|
| §3 Database 6 bảng | 8 |
| §4.1 Tracker JS (cookie, phiên mới theo ngày/campaign, webdriver, prerender, pv/ping, engaged ≤ 1800) | 12 |
| §4.2 Endpoint `/collect` (guard, UA, geo, kênh, upsert, product_view, checkout_start) | 10, 11, 12 |
| §4.3 Hook WC (ATC + Cart cùng lúc, order + meta, block checkout, ép chuyển trang Cart) | 13 |
| §5 Lớp 1–4 (payload, Origin, loại trừ, bot, F5, 5 phút theo visitor/ip_hash, giới hạn, spam) | 3, 6, 10, 11 |
| §5 IP chỉ lưu hash, salt theo ngày | 8 |
| §10 Bỏ Burst, migration schema 4 | 9 |
| §12 Docker | 1 |
| §13 PHPUnit + checklist tích hợp mục 1–5, 10 | 2–13 |

Ngoài phạm vi kế hoạch này (đã phân cho kế hoạch sau): aggregator, dirty dates, cleanup, đổi salt/xóa salt cũ, tải GeoLite2 (Kế hoạch 2); trạng thái đơn tính doanh thu, dashboard/settings React, widget, xóa code Burst cũ và option `dn_atc_*`, version 3.0.0 (Kế hoạch 3); API key và REST công khai (Kế hoạch 4).
