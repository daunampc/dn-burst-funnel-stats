# Kế hoạch 4 — REST API công khai + quản lý API key

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Thêm REST API công khai chỉ đọc `/wp-json/dnbfs/v1/` (meta, stats/summary|timeseries|breakdown|funnel|realtime, openapi.json) xác thực bằng API key, cùng tab **API** trong Settings để tạo / thu hồi key, kiểm tra trạng thái hệ thống, README có ví dụ NestJS; kèm các chỉnh sửa nhỏ còn lại từ review Kế hoạch 3.

**Architecture:** `includes/api/keys.php` lưu key (chỉ prefix + HMAC) trong bảng `dnbfs_api_keys` đã có; `includes/api/auth.php` xác thực một request (header key, HTTPS, IP được phép, scope, giới hạn tần suất theo key, `last_used_at`); `includes/api/routes.php` đăng ký route GET với **một** callback chung: xác thực → kiểm tra tham số bằng các hàm có sẵn trong `includes/admin/request.php` (lỗi 400 đổi thành 422) → cache 60 giây → gọi `includes/reports.php` → bọc envelope `{data, meta}`; lỗi luôn có body `{code, message}`. Tab API là form `admin-post.php` theo mẫu Settings hiện có; key đầy đủ chỉ hiện một lần qua transient gắn với user.

**Tech Stack:** PHP 7.4+, WordPress 6.5+ REST API (`register_rest_route`, `WP_REST_Request`, `rest_do_request`), WooCommerce, MariaDB, WP-CLI integration harness, PHPUnit cho hàm thuần.

**Spec:** `docs/superpowers/specs/2026-10-03-native-tracking-design.md` (§3 `dnbfs_api_keys`, §8.3 bộ lọc, §9 REST API công khai — §9 được viết lại chi tiết ở Task 7). Ghi chú review Kế hoạch 3: `.superpowers/sdd/p3/final-review.md` mục "## Re-review" (R1, R2, R3, R5 — Task 8).

## Global Constraints

- PHP tối thiểu 7.4 (không `match`, union type, named args, `str_contains`, enum, readonly, nullsafe; không thêm return type). Hàm mới tiền tố `dn_bfs_` (API: `dn_bfs_api_*`). Mọi SQL có biến qua `$wpdb->prepare`. Mọi output escape (`esc_html`, `esc_attr`, `esc_url`, `esc_js`, `esc_textarea`).
- Phong cách: tab + khoảng trắng kiểu WordPress trong `includes/`; `dn-burst-funnel-stats.php` 2 space, không khoảng trắng trong ngoặc. JS: ES5 + jQuery, không bước build.
- Namespace `dnbfs/v1`, **chỉ GET**. Endpoint và scope: `/meta` (key bất kỳ), `/stats/summary`, `/stats/timeseries`, `/stats/breakdown`, `/stats/funnel` (`stats:read`), `/stats/realtime` (`realtime:read`), `/openapi.json` (key bất kỳ). Danh sách scope: `stats:read`, `realtime:read`.
- Xác thực: `Authorization: Bearer <key>` hoặc `X-DNBFS-Key: <key>`. Key `dnbfs_<prefix8>_<secret32>` (prefix `[a-z0-9]{8}`, secret `[A-Za-z0-9]{32}`); lưu `prefix` + `hash_hmac('sha256', key, wp_salt('auth'))`; so sánh `hash_equals`. Key thu hồi → 401.
- HTTPS bắt buộc trừ khi host của `home_url()` là `localhost`, `127.0.0.1`, `::1`, `*.test`, `*.localhost` (filter `dn_bfs_api_require_https`). Không bao giờ gửi header CORS cho các route công khai.
- Tham số công khai: `start`, `end` (`YYYY-MM-DD`, múi giờ site, bắt buộc với `/stats/*` trừ realtime, tối đa **366** ngày tính cả hai đầu), `compare=none|previous_period|previous_year` (chỉ summary, mặc định `none`), `metrics=a,b` (timeseries), `dimension`, `orderby`, `order`, `limit` (1–500, mặc định 25), `page` (≥ 1), `filter[dimension]=value` (mọi `/stats/*` trừ realtime). Admin giữ 731 ngày.
- Envelope: `{ "data": …, "meta": { "timezone", "currency", "range": {"start","end"} | null, "estimated": bool } }`. Lỗi: body đúng `{ "code", "message" }`; 401 `missing_key|invalid_key`, 403 `insufficient_scope|ip_not_allowed|https_required`, 422 tham số sai (`invalid_date`, `range_too_long`, `invalid_compare`, `invalid_filter`, `invalid_metric`, `invalid_dimension`, `invalid_orderby`, `invalid_order`, `invalid_limit`, `invalid_page`, `filter_out_of_retention`), 429 `rate_limited` + `Retry-After`.
- Giới hạn tần suất theo key (cột `rate_limit`, 1–1000, mặc định 60/phút, cửa sổ cố định theo phút của `dn_bfs_now()`); cache kết quả thành công 60 giây (filter `dn_bfs_api_cache_ttl`) theo (endpoint, tham số đã chuẩn hóa) cho summary/timeseries/breakdown/funnel; `last_used_at` cập nhật tối đa 1 lần/phút.
- Transient mới đều bắt đầu bằng `dnbfs_api_` (cache `dnbfs_api_c_<md5>`, bộ đếm `dnbfs_api_rl_<id>_<phút>`, hiện key một lần `dnbfs_api_reveal_<user_id>`) — đã nằm trong mẫu `\_transient\_dnbfs\_%` của `uninstall.php`.
- Admin: tab thứ 8 `api` trong Settings; admin-post action `dn_bfs_api_key`, trường `task` = `create|revoke`, nonce `dn_bfs_api_key_<task>`; quyền `dn_bfs_admin_permission()`. Key đầy đủ không bao giờ nằm trên URL.
- Commit message kết thúc bằng `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Không commit `.superpowers/`.
- Lệnh (từ gốc repo):
  - Unit: `docker compose -f docker/docker-compose.yml run --rm phpunit`
  - Tích hợp: `docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php`
  - Lint 7.4: `docker compose -f docker/docker-compose.yml run --rm php74`
  - JS: `docker run --rm -v "$PWD":/app -w /app node:20 node --check assets/admin.js`

## Cấu trúc file

| File | Trách nhiệm |
|---|---|
| `includes/api/keys.php` | Scope, phân tích/sinh/băm key, kiểm tra input, tạo / tra cứu / liệt kê / thu hồi key |
| `includes/api/auth.php` | Lấy key từ header, HTTPS, IP được phép, giới hạn tần suất, `last_used_at`, `dn_bfs_api_authenticate()` |
| `includes/api/routes.php` | Đăng ký route, ánh xạ tham số công khai, handler từng endpoint, envelope, cache, lỗi `{code,message}`, gỡ CORS |
| `includes/api/openapi.php` | Tài liệu OpenAPI 3.0.3 |
| `includes/admin/api-keys-page.php` | Tab API: danh sách key, form tạo, thu hồi, hiện key một lần, hướng dẫn dùng |
| `includes/admin/request.php` (sửa) | `dn_bfs_parse_range()` nhận giới hạn ngày tùy chọn |
| `includes/admin/settings-page.php` (sửa) | Tab thứ 8, notice key; R1/R2 |
| `includes/admin/system-status.php` (sửa) | Kiểm tra `api` |
| `includes/admin/data-tools.php` (sửa) | Xóa toàn bộ dữ liệu xóa luôn cache API |
| `includes/reports.php` (sửa) | `dn_bfs_report_funnel_steps()` dùng chung cho admin và API |
| `includes/admin/menu.php`, `assets/admin.js` (sửa) | R3: dấu phân cách tiền của WooCommerce trên biểu đồ |
| `dn-burst-funnel-stats.php` (sửa) | `dn_burst_funnel_stats_load_api()` |
| `README.MD`, spec (sửa) | Tài liệu API + ví dụ NestJS; §8.4, §9, §11 |
| `tests/integration/api-helpers.php` | Helper tạo key và request API trong test |
| `tests/integration/test-api-keys.php`, `test-api-auth.php`, `test-api-routes.php`, `test-api-openapi.php`, `test-api-admin.php` | Test tích hợp |
| `tests/php/ApiKeysTest.php`, `tests/php/ApiAuthTest.php` | Unit test hàm thuần |

---

### Task 1: Giới hạn ngày tùy chọn + lưu trữ API key

**Files:**
- Create: `includes/api/keys.php`, `tests/integration/api-helpers.php`, `tests/integration/test-api-keys.php`, `tests/php/ApiKeysTest.php`
- Modify: `includes/admin/request.php` (`dn_bfs_parse_range`), `tests/integration/test-admin-request.php`, `tests/integration/harness.php` (`dn_bfs_it_reset`), `tests/php/bootstrap.php`, `dn-burst-funnel-stats.php`

**Interfaces:**
- Consumes: `dn_bfs_request_error()`, `dn_bfs_truncate()`, `dn_bfs_normalize_lines()`, `dn_bfs_validate_ip_rule()`, `dn_bfs_table()`, `dn_bfs_now()`.
- Produces:
  - `dn_bfs_parse_range( array $params, int $max_days = 731 ): array|WP_Error` (thông báo `range_too_long` nêu số ngày tối đa).
  - `dn_bfs_api_scope_names(): string[]` → `array( 'stats:read', 'realtime:read' )`; `dn_bfs_api_scopes(): array` scope => nhãn.
  - `dn_bfs_api_parse_scopes( string|array $value ): string[]` (lọc + thứ tự chuẩn).
  - `dn_bfs_api_parse_key( $key ): array{prefix,secret}|false`.
  - `dn_bfs_api_key_hash( string $key ): string`; `dn_bfs_api_generate_key(): array{prefix,key}`.
  - `dn_bfs_api_sanitize_key_input( array $input ): array{name,scopes,allowed_ips,rate_limit}|WP_Error` (mã `invalid_name`, `invalid_scopes`, `invalid_ips`, `invalid_rate_limit`, status 400).
  - `dn_bfs_api_create_key( array $input, $now = null ): array{id,prefix,key}|WP_Error`.
  - `dn_bfs_api_normalize_key_row( array $row ): array` → `id, name, prefix, key_hash, scopes (array), allowed_ips (array), rate_limit, last_used_at, created_at, revoked_at` (int).
  - `dn_bfs_api_get_key( int $id ): array|null`; `dn_bfs_api_find_key( string $key ): array|null` (gồm cả key đã thu hồi); `dn_bfs_api_list_keys(): array` (key đang hoạt động trước, mới trước).
  - `dn_bfs_api_revoke_key( int $id, $now = null ): true|WP_Error` (`key_not_found`, 404).
  - `dn_burst_funnel_stats_load_api()` (file chính), gọi trong bootstrap ngay sau `dn_burst_funnel_stats_load_admin();`.
  - Helper test: `dn_bfs_it_api_key_defaults(): array`, `dn_bfs_it_api_key( array $overrides = array() ): array{id,prefix,key}`. `dn_bfs_it_reset()` còn TRUNCATE `api_keys`, xóa transient `dnbfs_api_*` và `$_SERVER['HTTPS']`.

- [ ] **Step 1: Viết test fail cho giới hạn ngày** — thêm vào cuối `tests/integration/test-admin-request.php`:

```php
dn_bfs_it(
	'range parsing takes an optional day limit for the public API',
	function () {
		$ok = dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '2025-01-01', 'end' => '2026-01-01' ), 366 );
		dn_bfs_assert_true( ! is_wp_error( $ok ), '366 days accepted' );

		$bad = dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '2025-01-01', 'end' => '2026-01-02' ), 366 );
		dn_bfs_assert_true( is_wp_error( $bad ), '367 days rejected' );
		dn_bfs_assert_same( 'range_too_long', $bad->get_error_code() );
		dn_bfs_assert_true( false !== strpos( $bad->get_error_message(), '366' ), 'message names the limit' );

		$admin = dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '2024-01-01', 'end' => '2026-01-01' ) );
		dn_bfs_assert_true( false !== strpos( $admin->get_error_message(), '731' ), 'admin limit unchanged' );
	}
);
```

- [ ] **Step 2: Viết helper và test fail cho key** — tạo `tests/integration/api-helpers.php`:

```php
<?php
/**
 * Helpers for public API integration tests.
 */

function dn_bfs_it_api_key_defaults() {
	return array(
		'name'        => 'Test key',
		'scopes'      => array( 'stats:read', 'realtime:read' ),
		'allowed_ips' => '',
		'rate_limit'  => '60',
	);
}

function dn_bfs_it_api_key( $overrides = array() ) {
	$created = dn_bfs_api_create_key( array_merge( dn_bfs_it_api_key_defaults(), $overrides ) );

	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( 'API key: ' . $created->get_error_code() );
	}

	return $created;
}
```

Tạo `tests/integration/test-api-keys.php`:

```php
<?php

require_once __DIR__ . '/api-helpers.php';

dn_bfs_it(
	'creating a key stores only its prefix and HMAC and returns the full key once',
	function () {
		global $wpdb;

		$created = dn_bfs_it_api_key( array( 'name' => 'NestJS', 'allowed_ips' => "198.51.100.0/24\n\n2001:db8::/32", 'rate_limit' => '120' ) );
		$parts   = dn_bfs_api_parse_key( $created['key'] );

		dn_bfs_assert_true( false !== $parts, 'key format' );
		dn_bfs_assert_same( $parts['prefix'], $created['prefix'] );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . dn_bfs_table( 'api_keys' ) . ' WHERE id = %d', $created['id'] ), ARRAY_A );
		dn_bfs_assert_same( $created['prefix'], $row['prefix'] );
		dn_bfs_assert_same( hash_hmac( 'sha256', $created['key'], wp_salt( 'auth' ) ), $row['key_hash'] );
		dn_bfs_assert_true( false === strpos( implode( '|', $row ), $parts['secret'] ), 'secret never stored' );

		$key = dn_bfs_api_get_key( $created['id'] );
		dn_bfs_assert_same( 'NestJS', $key['name'] );
		dn_bfs_assert_same( array( 'stats:read', 'realtime:read' ), $key['scopes'] );
		dn_bfs_assert_same( array( '198.51.100.0/24', '2001:db8::/32' ), $key['allowed_ips'] );
		dn_bfs_assert_same( 120, $key['rate_limit'] );
		dn_bfs_assert_same( 0, $key['last_used_at'] );
		dn_bfs_assert_same( 0, $key['revoked_at'] );
		dn_bfs_assert_true( $key['created_at'] > 0, 'created_at' );
	}
);

dn_bfs_it(
	'keys are found by the full key only, listed and revoked once',
	function () {
		$created = dn_bfs_it_api_key();

		dn_bfs_assert_same( $created['id'], dn_bfs_api_find_key( $created['key'] )['id'] );
		dn_bfs_assert_same( null, dn_bfs_api_find_key( 'dnbfs_' . $created['prefix'] . '_' . str_repeat( 'A', 32 ) ), 'wrong secret' );
		dn_bfs_assert_same( null, dn_bfs_api_find_key( 'not-a-key' ), 'garbage' );
		dn_bfs_assert_same( null, dn_bfs_api_find_key( strtoupper( $created['key'] ) ), 'case matters' );

		$second = dn_bfs_it_api_key( array( 'name' => 'Second' ) );
		dn_bfs_assert_same( 2, count( dn_bfs_api_list_keys() ) );

		dn_bfs_assert_same( true, dn_bfs_api_revoke_key( $created['id'], 1700000000 ) );
		dn_bfs_assert_same( 1700000000, dn_bfs_api_get_key( $created['id'] )['revoked_at'] );
		dn_bfs_assert_same( 1700000000, dn_bfs_api_find_key( $created['key'] )['revoked_at'], 'find returns revoked keys so auth can reject them' );
		dn_bfs_assert_same( 'key_not_found', dn_bfs_api_revoke_key( $created['id'] )->get_error_code(), 'twice' );
		dn_bfs_assert_same( 'key_not_found', dn_bfs_api_revoke_key( 999999 )->get_error_code(), 'unknown' );

		dn_bfs_assert_same( array( $second['id'], $created['id'] ), array_column( dn_bfs_api_list_keys(), 'id' ), 'active keys first' );
	}
);

dn_bfs_it(
	'key input is validated and defaults to 60 requests per minute from any IP',
	function () {
		$cases = array(
			'invalid_name'       => array( 'name' => '   ' ),
			'invalid_scopes'     => array( 'scopes' => array( 'admin:write' ) ),
			'invalid_ips'        => array( 'allowed_ips' => "198.51.100.0/24\nnot-an-ip" ),
			'invalid_rate_limit' => array( 'rate_limit' => '0' ),
		);

		foreach ( $cases as $code => $override ) {
			$result = dn_bfs_api_create_key( array_merge( dn_bfs_it_api_key_defaults(), $override ) );
			dn_bfs_assert_same( $code, is_wp_error( $result ) ? $result->get_error_code() : 'created', $code );
		}

		foreach ( array( '1001', 'abc', '-5', '1.5' ) as $rate ) {
			$result = dn_bfs_api_create_key( array_merge( dn_bfs_it_api_key_defaults(), array( 'rate_limit' => $rate ) ) );
			dn_bfs_assert_same( 'invalid_rate_limit', is_wp_error( $result ) ? $result->get_error_code() : 'created', $rate );
		}

		dn_bfs_assert_same( 0, dn_bfs_it_count( 'api_keys' ) );

		$default = dn_bfs_api_create_key( array( 'name' => 'Defaults', 'scopes' => 'stats:read' ) );
		$key     = dn_bfs_api_get_key( $default['id'] );
		dn_bfs_assert_same( 60, $key['rate_limit'] );
		dn_bfs_assert_same( array(), $key['allowed_ips'] );
		dn_bfs_assert_same( array( 'stats:read' ), $key['scopes'] );
	}
);
```

- [ ] **Step 3: Viết unit test fail — `tests/php/ApiKeysTest.php`**

```php
<?php

use PHPUnit\Framework\TestCase;

class ApiKeysTest extends TestCase {
	public function test_parse_key_accepts_only_the_documented_format() {
		$key = 'dnbfs_ab12cd34_' . str_repeat( 'Zz9', 10 ) . 'Q1';

		$this->assertSame( array( 'prefix' => 'ab12cd34', 'secret' => str_repeat( 'Zz9', 10 ) . 'Q1' ), dn_bfs_api_parse_key( $key ) );
		$this->assertFalse( dn_bfs_api_parse_key( 'dnbfs_AB12CD34_' . str_repeat( 'a', 32 ) ) );
		$this->assertFalse( dn_bfs_api_parse_key( 'dnbfs_ab12cd34_' . str_repeat( 'a', 31 ) ) );
		$this->assertFalse( dn_bfs_api_parse_key( 'dnbfs_ab12cd3_' . str_repeat( 'a', 32 ) ) );
		$this->assertFalse( dn_bfs_api_parse_key( 'key_ab12cd34_' . str_repeat( 'a', 32 ) ) );
		$this->assertFalse( dn_bfs_api_parse_key( ' dnbfs_ab12cd34_' . str_repeat( 'a', 32 ) ) );
		$this->assertFalse( dn_bfs_api_parse_key( null ) );
	}

	public function test_parse_scopes_filters_and_orders() {
		$this->assertSame( array( 'stats:read', 'realtime:read' ), dn_bfs_api_parse_scopes( 'realtime:read, stats:read,bogus' ) );
		$this->assertSame( array( 'realtime:read' ), dn_bfs_api_parse_scopes( array( 'realtime:read', array( 'x' ) ) ) );
		$this->assertSame( array(), dn_bfs_api_parse_scopes( '' ) );
	}
}
```

Thêm vào cuối `tests/php/bootstrap.php`:

```php
foreach ( array( 'keys', 'auth' ) as $dn_bfs_file ) {
	$dn_bfs_path = $dn_bfs_root . '/includes/api/' . $dn_bfs_file . '.php';

	if ( file_exists( $dn_bfs_path ) ) {
		require_once $dn_bfs_path;
	}
}
```

- [ ] **Step 4: Dọn bảng key và transient API giữa các test** — trong `tests/integration/harness.php`, hàm `dn_bfs_it_reset()`:

Thay `foreach ( array( 'visitors', 'sessions', 'pageviews', 'events', 'daily' ) as $table ) {` bằng:

```php
		foreach ( array( 'visitors', 'sessions', 'pageviews', 'events', 'daily', 'api_keys' ) as $table ) {
```

Thay dòng `$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_dnbfs_r_' ) . '%', $wpdb->esc_like( '_transient_timeout_dnbfs_r_' ) . '%' ) );` bằng:

```php
	foreach ( array( 'dnbfs_r_', 'dnbfs_api_' ) as $transient_prefix ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_' . $transient_prefix ) . '%', $wpdb->esc_like( '_transient_timeout_' . $transient_prefix ) . '%' ) );
	}
```

Thay `unset( $_SERVER['HTTP_CF_RAY'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_CF_IPCOUNTRY'] );` bằng:

```php
	unset( $_SERVER['HTTP_CF_RAY'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_CF_IPCOUNTRY'], $_SERVER['HTTPS'] );
```

- [ ] **Step 5: Chạy test để thấy fail** — Unit `--filter ApiKeysTest` → FAIL "Call to undefined function dn_bfs_api_parse_key()". Tích hợp → 3 test `test-api-keys.php` FAIL (hàm chưa có), test giới hạn ngày FAIL ở `367 days rejected` (tham số thứ hai chưa được dùng).

- [ ] **Step 6: Thêm giới hạn ngày vào `dn_bfs_parse_range()`** — trong `includes/admin/request.php`:

Thay `function dn_bfs_parse_range( $params ) {` bằng `function dn_bfs_parse_range( $params, $max_days = 731 ) {`, và thay khối:

```php
		if ( $span > 730 ) {
			return dn_bfs_request_error( 'range_too_long', __( 'Custom ranges can cover at most 731 days.', 'dn-burst-funnel-stats' ) );
		}
```

bằng:

```php
		if ( $span > (int) $max_days - 1 ) {
			/* translators: %d: maximum number of days in a custom range. */
			return dn_bfs_request_error( 'range_too_long', sprintf( __( 'Custom ranges can cover at most %d days.', 'dn-burst-funnel-stats' ), (int) $max_days ) );
		}
```

- [ ] **Step 7: Tạo `includes/api/keys.php`**

```php
<?php
/**
 * API key storage: scopes, generation, hashing, lookup, creation and revocation.
 *
 * Keys look like dnbfs_<prefix8>_<secret32>. Only the prefix and an HMAC of the
 * full key are stored; the full key is shown once, right after creation.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_api_scope_names() {
	return array( 'stats:read', 'realtime:read' );
}

function dn_bfs_api_scopes() {
	return array(
		'stats:read'    => __( 'Statistics (summary, timeseries, breakdown, funnel)', 'dn-burst-funnel-stats' ),
		'realtime:read' => __( 'Visitors online right now', 'dn-burst-funnel-stats' ),
	);
}

function dn_bfs_api_parse_scopes( $value ) {
	$list = is_array( $value ) ? array_filter( $value, 'is_scalar' ) : explode( ',', (string) $value );
	$list = array_map( 'trim', array_map( 'strval', $list ) );

	return array_values( array_intersect( dn_bfs_api_scope_names(), $list ) );
}

function dn_bfs_api_parse_key( $key ) {
	if ( ! is_string( $key ) || ! preg_match( '/^dnbfs_([a-z0-9]{8})_([A-Za-z0-9]{32})$/', $key, $matches ) ) {
		return false;
	}

	return array(
		'prefix' => $matches[1],
		'secret' => $matches[2],
	);
}

function dn_bfs_api_key_hash( $key ) {
	return hash_hmac( 'sha256', (string) $key, wp_salt( 'auth' ) );
}

function dn_bfs_api_generate_key() {
	$prefix = strtolower( wp_generate_password( 8, false, false ) );

	return array(
		'prefix' => $prefix,
		'key'    => 'dnbfs_' . $prefix . '_' . wp_generate_password( 32, false, false ),
	);
}

function dn_bfs_api_sanitize_key_input( $input ) {
	$input = is_array( $input ) ? $input : array();
	$name  = isset( $input['name'] ) && is_scalar( $input['name'] ) ? dn_bfs_truncate( trim( sanitize_text_field( (string) $input['name'] ) ), 100 ) : '';

	if ( '' === $name ) {
		return dn_bfs_request_error( 'invalid_name', __( 'Give the key a name.', 'dn-burst-funnel-stats' ) );
	}

	$scopes = dn_bfs_api_parse_scopes( isset( $input['scopes'] ) ? $input['scopes'] : array() );

	if ( empty( $scopes ) ) {
		return dn_bfs_request_error( 'invalid_scopes', __( 'Choose at least one scope.', 'dn-burst-funnel-stats' ) );
	}

	$rules   = dn_bfs_normalize_lines( isset( $input['allowed_ips'] ) && is_scalar( $input['allowed_ips'] ) ? (string) $input['allowed_ips'] : '' );
	$invalid = array();

	foreach ( $rules as $rule ) {
		if ( ! dn_bfs_validate_ip_rule( $rule ) ) {
			$invalid[] = $rule;
		}
	}

	if ( ! empty( $invalid ) ) {
		/* translators: %s: comma-separated invalid IP rules. */
		return dn_bfs_request_error( 'invalid_ips', sprintf( __( 'These allowed IP entries are invalid: %s', 'dn-burst-funnel-stats' ), implode( ', ', $invalid ) ), 400, array( 'invalid' => $invalid ) );
	}

	$rate = isset( $input['rate_limit'] ) && is_scalar( $input['rate_limit'] ) ? trim( (string) $input['rate_limit'] ) : '';
	$rate = '' === $rate ? '60' : $rate;

	if ( ! ctype_digit( $rate ) || (int) $rate < 1 || (int) $rate > 1000 ) {
		return dn_bfs_request_error( 'invalid_rate_limit', __( 'The rate limit must be between 1 and 1000 requests per minute.', 'dn-burst-funnel-stats' ) );
	}

	return array(
		'name'        => $name,
		'scopes'      => $scopes,
		'allowed_ips' => $rules,
		'rate_limit'  => (int) $rate,
	);
}

function dn_bfs_api_create_key( $input, $now = null ) {
	global $wpdb;

	$clean = dn_bfs_api_sanitize_key_input( $input );

	if ( is_wp_error( $clean ) ) {
		return $clean;
	}

	$now = null === $now ? dn_bfs_now() : (int) $now;

	// The prefix is UNIQUE; retry on the (unlikely) collision.
	for ( $attempt = 0; $attempt < 5; $attempt++ ) {
		$generated = dn_bfs_api_generate_key();
		$inserted  = $wpdb->insert(
			dn_bfs_table( 'api_keys' ),
			array(
				'name'         => $clean['name'],
				'prefix'       => $generated['prefix'],
				'key_hash'     => dn_bfs_api_key_hash( $generated['key'] ),
				'scopes'       => implode( ',', $clean['scopes'] ),
				'allowed_ips'  => implode( "\n", $clean['allowed_ips'] ),
				'rate_limit'   => $clean['rate_limit'],
				'last_used_at' => 0,
				'created_at'   => $now,
				'revoked_at'   => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
		);

		if ( $inserted ) {
			return array(
				'id'     => (int) $wpdb->insert_id,
				'prefix' => $generated['prefix'],
				'key'    => $generated['key'],
			);
		}
	}

	return dn_bfs_request_error( 'key_create_failed', __( 'The key could not be saved. Please try again.', 'dn-burst-funnel-stats' ), 500 );
}

function dn_bfs_api_normalize_key_row( $row ) {
	return array(
		'id'           => (int) $row['id'],
		'name'         => (string) $row['name'],
		'prefix'       => (string) $row['prefix'],
		'key_hash'     => (string) $row['key_hash'],
		'scopes'       => dn_bfs_api_parse_scopes( (string) $row['scopes'] ),
		'allowed_ips'  => dn_bfs_normalize_lines( (string) $row['allowed_ips'] ),
		'rate_limit'   => max( 1, (int) $row['rate_limit'] ),
		'last_used_at' => (int) $row['last_used_at'],
		'created_at'   => (int) $row['created_at'],
		'revoked_at'   => (int) $row['revoked_at'],
	);
}

function dn_bfs_api_get_key( $id ) {
	global $wpdb;

	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . dn_bfs_table( 'api_keys' ) . ' WHERE id = %d', (int) $id ), ARRAY_A );

	return $row ? dn_bfs_api_normalize_key_row( $row ) : null;
}

/**
 * Key row for a full key string, revoked keys included (callers check revoked_at).
 */
function dn_bfs_api_find_key( $key ) {
	global $wpdb;

	$parts = dn_bfs_api_parse_key( $key );

	if ( false === $parts ) {
		return null;
	}

	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . dn_bfs_table( 'api_keys' ) . ' WHERE prefix = %s', $parts['prefix'] ), ARRAY_A );

	if ( ! $row || ! hash_equals( (string) $row['key_hash'], dn_bfs_api_key_hash( $key ) ) ) {
		return null;
	}

	return dn_bfs_api_normalize_key_row( $row );
}

function dn_bfs_api_list_keys() {
	global $wpdb;

	$rows = $wpdb->get_results( 'SELECT * FROM ' . dn_bfs_table( 'api_keys' ) . ' ORDER BY (revoked_at = 0) DESC, created_at DESC, id DESC', ARRAY_A );

	return array_map( 'dn_bfs_api_normalize_key_row', (array) $rows );
}

function dn_bfs_api_revoke_key( $id, $now = null ) {
	global $wpdb;

	$now     = null === $now ? dn_bfs_now() : (int) $now;
	$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . dn_bfs_table( 'api_keys' ) . ' SET revoked_at = %d WHERE id = %d AND revoked_at = 0', max( 1, $now ), (int) $id ) );

	if ( ! $updated ) {
		return dn_bfs_request_error( 'key_not_found', __( 'That key does not exist or is already revoked.', 'dn-burst-funnel-stats' ), 404 );
	}

	return true;
}
```

- [ ] **Step 8: Nạp module API** — trong `dn-burst-funnel-stats.php`, thêm ngay dưới hàm `dn_burst_funnel_stats_load_admin()`:

```php
/**
 * Load the public REST API (keys, authentication, routes, OpenAPI document).
 *
 * @return void
 */
function dn_burst_funnel_stats_load_api()
{
  foreach (array('keys') as $module) {
    require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/api/' . $module . '.php';
  }
}
```

Trong `dn_burst_funnel_stats_bootstrap()`, ngay sau `dn_burst_funnel_stats_load_admin();` thêm `dn_burst_funnel_stats_load_api();`.

- [ ] **Step 9: Chạy test** — Unit `--filter ApiKeysTest` → OK (2 tests). Tích hợp → PASS (thêm 4 test, test cũ vẫn PASS, gồm `custom range accepts exactly 731 days inclusive and rejects 732`). `php74` → `PHP 7.4 lint OK`.

- [ ] **Step 10: Commit**

```bash
git add includes/api/keys.php includes/admin/request.php dn-burst-funnel-stats.php tests/integration/api-helpers.php tests/integration/test-api-keys.php tests/integration/test-admin-request.php tests/integration/harness.php tests/php/ApiKeysTest.php tests/php/bootstrap.php
git commit -m "feat(api): store API keys as prefix + HMAC and allow a per-caller range limit

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Xác thực request API

**Files:**
- Create: `includes/api/auth.php`, `tests/integration/test-api-auth.php`, `tests/php/ApiAuthTest.php`
- Modify: `tests/integration/api-helpers.php`, `dn-burst-funnel-stats.php` (thêm `'auth'`)

**Interfaces:**
- Consumes: `dn_bfs_api_find_key()`, `dn_bfs_request_error()`, `dn_bfs_get_client_ip()`, `dn_bfs_ip_in_cidr()`, `dn_bfs_table()`.
- Produces:
  - `dn_bfs_api_extract_key( $authorization, $header_key ): string` (Bearer trước, rồi `X-DNBFS-Key`; không có → `''`).
  - `dn_bfs_api_is_local_host( string $host ): bool`; `dn_bfs_api_https_required(): bool` (host của `home_url()`, filter `dn_bfs_api_require_https( bool $required, string $host )`).
  - `dn_bfs_api_ip_allowed( string $ip, array $rules ): bool` (rỗng = mọi IP).
  - `dn_bfs_api_rate_check( array $key, int $now ): true|WP_Error` (`rate_limited`, 429, data `retry_after` giây).
  - `dn_bfs_api_touch_key( array $key, int $now ): bool`.
  - `dn_bfs_api_authenticate( WP_REST_Request $request, string $scope, int $now ): array|WP_Error` — thứ tự: HTTPS (`https_required` 403) → key (`missing_key` / `invalid_key` 401) → IP (`ip_not_allowed` 403) → scope (`insufficient_scope` 403; `''` = không cần scope) → tần suất (429) → `last_used_at`.
  - Helper test: `dn_bfs_it_api_request( string $route, array $query = array(), string $key = '', string $via = 'bearer' ): WP_REST_Request`, `dn_bfs_it_error_pair( $result ): array{0: string, 1: int}`.

- [ ] **Step 1: Viết unit test fail — `tests/php/ApiAuthTest.php`**

```php
<?php

use PHPUnit\Framework\TestCase;

class ApiAuthTest extends TestCase {
	public function test_extract_key_prefers_bearer_then_header() {
		$this->assertSame( 'abc', dn_bfs_api_extract_key( 'Bearer abc', 'other' ) );
		$this->assertSame( 'abc', dn_bfs_api_extract_key( '  bearer   abc ', '' ) );
		$this->assertSame( 'other', dn_bfs_api_extract_key( 'Basic dXNlcjpwYXNz', ' other ' ) );
		$this->assertSame( 'other', dn_bfs_api_extract_key( 'Bearer a b', 'other' ) );
		$this->assertSame( '', dn_bfs_api_extract_key( null, null ) );
	}

	public function test_local_hosts_are_exempt_from_https() {
		foreach ( array( 'localhost', '127.0.0.1', '::1', '[::1]', 'shop.test', 'app.localhost', 'LOCALHOST' ) as $host ) {
			$this->assertTrue( dn_bfs_api_is_local_host( $host ), $host );
		}

		foreach ( array( 'example.com', 'test.example.com', 'localhost.example.com', 'mytest', '' ) as $host ) {
			$this->assertFalse( dn_bfs_api_is_local_host( $host ), $host );
		}
	}

	public function test_ip_allow_list() {
		$this->assertTrue( dn_bfs_api_ip_allowed( '203.0.113.10', array() ) );
		$this->assertTrue( dn_bfs_api_ip_allowed( '198.51.100.7', array( '198.51.100.0/24' ) ) );
		$this->assertFalse( dn_bfs_api_ip_allowed( '203.0.113.10', array( '198.51.100.0/24' ) ) );
		$this->assertTrue( dn_bfs_api_ip_allowed( '2001:db8::5', array( '198.51.100.0/24', '2001:db8::/32' ) ) );
		$this->assertTrue( dn_bfs_api_ip_allowed( '203.0.113.10', array( '203.0.113.10' ) ) );
		$this->assertFalse( dn_bfs_api_ip_allowed( 'unknown', array( '198.51.100.0/24' ) ) );
	}
}
```

- [ ] **Step 2: Thêm helper và viết test tích hợp fail** — thêm vào cuối `tests/integration/api-helpers.php`:

```php
function dn_bfs_it_api_request( $route, $query = array(), $key = '', $via = 'bearer' ) {
	$request = new WP_REST_Request( 'GET', '/dnbfs/v1/' . ltrim( $route, '/' ) );
	$request->set_query_params( $query );

	if ( '' !== $key ) {
		if ( 'bearer' === $via ) {
			$request->set_header( 'authorization', 'Bearer ' . $key );
		} else {
			$request->set_header( 'x_dnbfs_key', $key );
		}
	}

	return $request;
}

function dn_bfs_it_error_pair( $result ) {
	if ( ! is_wp_error( $result ) ) {
		return array( 'ok', 200 );
	}

	$data = $result->get_error_data();

	return array( $result->get_error_code(), is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0 );
}
```

Tạo `tests/integration/test-api-auth.php`:

```php
<?php

require_once __DIR__ . '/api-helpers.php';

dn_bfs_it(
	'authentication accepts the Bearer and X-DNBFS-Key headers and rejects bad keys',
	function () {
		$created = dn_bfs_it_api_key();
		$now     = 1800000000;

		$key = dn_bfs_api_authenticate( dn_bfs_it_api_request( 'meta', array(), $created['key'] ), '', $now );
		dn_bfs_assert_same( $created['id'], is_wp_error( $key ) ? $key->get_error_code() : $key['id'], 'bearer' );

		$key = dn_bfs_api_authenticate( dn_bfs_it_api_request( 'meta', array(), $created['key'], 'header' ), '', $now );
		dn_bfs_assert_same( $created['id'], is_wp_error( $key ) ? $key->get_error_code() : $key['id'], 'x-dnbfs-key' );

		dn_bfs_assert_same( array( 'missing_key', 401 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( dn_bfs_it_api_request( 'meta' ), '', $now ) ) );
		dn_bfs_assert_same( array( 'invalid_key', 401 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( dn_bfs_it_api_request( 'meta', array(), 'dnbfs_' . $created['prefix'] . '_' . str_repeat( 'x', 32 ) ), '', $now ) ) );

		dn_bfs_api_revoke_key( $created['id'] );
		dn_bfs_assert_same( array( 'invalid_key', 401 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( dn_bfs_it_api_request( 'meta', array(), $created['key'] ), '', $now ) ), 'revoked' );
	}
);

dn_bfs_it(
	'HTTPS is required outside local development hosts',
	function () {
		$created = dn_bfs_it_api_key();
		$request = dn_bfs_it_api_request( 'meta', array(), $created['key'] );

		dn_bfs_assert_true( ! dn_bfs_api_https_required(), 'the Docker site (localhost) is exempt' );

		add_filter( 'dn_bfs_api_require_https', '__return_true' );
		dn_bfs_assert_same( array( 'https_required', 403 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, '', 1800000000 ) ) );

		$_SERVER['HTTPS'] = 'on';
		dn_bfs_assert_same( array( 'ok', 200 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, '', 1800000000 ) ) );
		remove_filter( 'dn_bfs_api_require_https', '__return_true' );
	}
);

dn_bfs_it(
	'allowed IPs, scopes and the per-key rate limit are enforced in order',
	function () {
		$created = dn_bfs_it_api_key( array( 'allowed_ips' => '198.51.100.0/24', 'scopes' => array( 'realtime:read' ), 'rate_limit' => '2' ) );
		$request = dn_bfs_it_api_request( 'stats/realtime', array(), $created['key'] );
		$now     = 1800000010; // 50 seconds before the next one-minute window.

		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		dn_bfs_assert_same( array( 'ip_not_allowed', 403 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, 'realtime:read', $now ) ) );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		dn_bfs_assert_same( array( 'insufficient_scope', 403 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, 'stats:read', $now ) ) );

		dn_bfs_assert_same( array( 'ok', 200 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, 'realtime:read', $now ) ), 'first' );
		dn_bfs_assert_same( array( 'ok', 200 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, 'realtime:read', $now ) ), 'second' );

		$limited = dn_bfs_api_authenticate( $request, 'realtime:read', $now );
		dn_bfs_assert_same( array( 'rate_limited', 429 ), dn_bfs_it_error_pair( $limited ) );
		dn_bfs_assert_same( 50, $limited->get_error_data()['retry_after'] );

		dn_bfs_assert_same( array( 'ok', 200 ), dn_bfs_it_error_pair( dn_bfs_api_authenticate( $request, 'realtime:read', $now + 60 ) ), 'next window' );
	}
);

dn_bfs_it(
	'last_used_at is written at most once per minute',
	function () {
		$created = dn_bfs_it_api_key();
		$request = dn_bfs_it_api_request( 'meta', array(), $created['key'] );
		$now     = 1800000000;

		dn_bfs_api_authenticate( $request, '', $now );
		dn_bfs_assert_same( $now, dn_bfs_api_get_key( $created['id'] )['last_used_at'] );

		dn_bfs_api_authenticate( $request, '', $now + 30 );
		dn_bfs_assert_same( $now, dn_bfs_api_get_key( $created['id'] )['last_used_at'], 'within a minute' );

		dn_bfs_api_authenticate( $request, '', $now + 61 );
		dn_bfs_assert_same( $now + 61, dn_bfs_api_get_key( $created['id'] )['last_used_at'], 'after a minute' );
	}
);
```

- [ ] **Step 3: Chạy test để thấy fail** — Unit `--filter ApiAuthTest` → FAIL (hàm chưa có). Tích hợp → 4 test `test-api-auth.php` FAIL.

- [ ] **Step 4: Tạo `includes/api/auth.php`**

```php
<?php
/**
 * Public API request authentication: key headers, HTTPS, IP allow-list,
 * scopes, per-key rate limit and last-used tracking.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_api_extract_key( $authorization, $header_key ) {
	if ( preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', (string) $authorization, $matches ) ) {
		return $matches[1];
	}

	return trim( (string) $header_key );
}

function dn_bfs_api_is_local_host( $host ) {
	$host = strtolower( trim( (string) $host, "[] \t" ) );

	if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
		return true;
	}

	return (bool) preg_match( '/\.(test|localhost)$/', $host );
}

/**
 * Based on the configured site address, never on the request's Host header.
 */
function dn_bfs_api_https_required() {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

	return (bool) apply_filters( 'dn_bfs_api_require_https', ! dn_bfs_api_is_local_host( $host ), $host );
}

function dn_bfs_api_ip_allowed( $ip, $rules ) {
	$rules = array_filter( array_map( 'trim', array_map( 'strval', (array) $rules ) ), 'strlen' );

	if ( empty( $rules ) ) {
		return true;
	}

	if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return false;
	}

	foreach ( $rules as $rule ) {
		if ( dn_bfs_ip_in_cidr( $ip, $rule ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Fixed one-minute window per key, counted in a transient.
 */
function dn_bfs_api_rate_check( $key, $now ) {
	$limit  = max( 1, (int) $key['rate_limit'] );
	$window = (int) floor( $now / MINUTE_IN_SECONDS );
	$name   = 'dnbfs_api_rl_' . (int) $key['id'] . '_' . $window;
	$count  = (int) get_transient( $name );

	if ( $count >= $limit ) {
		return dn_bfs_request_error(
			'rate_limited',
			__( 'Too many requests for this API key. Try again later.', 'dn-burst-funnel-stats' ),
			429,
			array( 'retry_after' => max( 1, ( $window + 1 ) * MINUTE_IN_SECONDS - (int) $now ) )
		);
	}

	set_transient( $name, $count + 1, 2 * MINUTE_IN_SECONDS );

	return true;
}

function dn_bfs_api_touch_key( $key, $now ) {
	global $wpdb;

	if ( (int) $now - (int) $key['last_used_at'] < MINUTE_IN_SECONDS ) {
		return false;
	}

	return (bool) $wpdb->query(
		$wpdb->prepare(
			'UPDATE ' . dn_bfs_table( 'api_keys' ) . ' SET last_used_at = %d WHERE id = %d AND last_used_at <= %d',
			(int) $now,
			(int) $key['id'],
			(int) $now - MINUTE_IN_SECONDS
		)
	);
}

function dn_bfs_api_authenticate( WP_REST_Request $request, $scope, $now ) {
	if ( dn_bfs_api_https_required() && ! is_ssl() ) {
		return dn_bfs_request_error( 'https_required', __( 'The API only accepts HTTPS requests.', 'dn-burst-funnel-stats' ), 403 );
	}

	$raw = dn_bfs_api_extract_key( $request->get_header( 'authorization' ), $request->get_header( 'x_dnbfs_key' ) );

	if ( '' === $raw ) {
		return dn_bfs_request_error( 'missing_key', __( 'Send the API key in the Authorization: Bearer header or the X-DNBFS-Key header.', 'dn-burst-funnel-stats' ), 401 );
	}

	$key = dn_bfs_api_find_key( $raw );

	if ( null === $key || $key['revoked_at'] > 0 ) {
		return dn_bfs_request_error( 'invalid_key', __( 'The API key is invalid or has been revoked.', 'dn-burst-funnel-stats' ), 401 );
	}

	if ( ! dn_bfs_api_ip_allowed( dn_bfs_get_client_ip(), $key['allowed_ips'] ) ) {
		return dn_bfs_request_error( 'ip_not_allowed', __( 'This API key cannot be used from your IP address.', 'dn-burst-funnel-stats' ), 403 );
	}

	if ( '' !== $scope && ! in_array( $scope, $key['scopes'], true ) ) {
		/* translators: %s: scope name. */
		return dn_bfs_request_error( 'insufficient_scope', sprintf( __( 'This API key needs the %s scope.', 'dn-burst-funnel-stats' ), $scope ), 403 );
	}

	$allowed = dn_bfs_api_rate_check( $key, $now );

	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}

	dn_bfs_api_touch_key( $key, $now );

	return $key;
}
```

- [ ] **Step 5: Nạp module** — trong `dn_burst_funnel_stats_load_api()` đổi `array('keys')` thành `array('keys', 'auth')`.

- [ ] **Step 6: Chạy test** — Unit `--filter ApiAuthTest` → OK (3 tests); unit đầy đủ OK. Tích hợp → PASS (thêm 4 test). `php74` → OK.

- [ ] **Step 7: Commit**

```bash
git add includes/api/auth.php dn-burst-funnel-stats.php tests/integration/api-helpers.php tests/integration/test-api-auth.php tests/php/ApiAuthTest.php
git commit -m "feat(api): authenticate API requests (key headers, HTTPS, IP allow-list, scopes, rate limit)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Route REST công khai, envelope, cache, không CORS

**Files:**
- Create: `includes/api/routes.php`, `tests/integration/test-api-routes.php`
- Modify: `includes/reports.php` (`dn_bfs_report_funnel`), `includes/admin/data-tools.php` (`dn_bfs_purge_all_data`), `tests/integration/api-helpers.php`, `dn-burst-funnel-stats.php` (thêm `'routes'`)

**Interfaces:**
- Consumes: `dn_bfs_api_authenticate()`, `dn_bfs_parse_range( $params, $max_days )`, `dn_bfs_parse_filters()`, `dn_bfs_parse_metrics()`, `dn_bfs_request_error()`, `dn_bfs_calculate_date_range()`, `dn_bfs_report_summary()`, `dn_bfs_report_timeseries()`, `dn_bfs_report_breakdown()`, `dn_bfs_report_realtime()`, `dn_bfs_report_dimensions()`, `dn_bfs_filter_dimensions()`, `dn_bfs_metric_columns()`, `dn_bfs_derived_metric_names()`, `dn_bfs_raw_available_from()`, `dn_bfs_now()`.
- Produces:
  - `dn_bfs_report_funnel_steps( array $metrics ): array` (6 bước `{key, value}`; `dn_bfs_report_funnel()` dùng lại).
  - `dn_bfs_api_namespace(): string` (`'dnbfs/v1'`), `dn_bfs_api_max_range_days(): int` (366), `dn_bfs_api_cache_ttl(): int` (filter `dn_bfs_api_cache_ttl`, mặc định 60).
  - `dn_bfs_api_endpoints(): array` endpoint => `array( 'scope', 'handler', 'cache' )` (Task 4 thêm `openapi.json`).
  - `dn_bfs_api_endpoint_from_route( string $route ): string` (`''` nếu không phải route công khai).
  - `dn_bfs_api_request_params( string $endpoint, array $query ): array|WP_Error` → `start, end, compare, filters` (+ `metrics` cho timeseries; + `dimension, orderby, order, limit, page` cho breakdown); `array()` cho `meta`, `stats/realtime`, `openapi.json`.
  - `dn_bfs_api_envelope( $data, array $params, bool $estimated ): array`.
  - `dn_bfs_api_response( $body, int $status = 200 ): WP_REST_Response`; `dn_bfs_api_error_response( WP_Error $error ): WP_REST_Response` (400 → 422, body `{code,message}`, `Retry-After` cho 429, `WWW-Authenticate` cho 401).
  - Handler `dn_bfs_api_endpoint_{meta,summary,timeseries,breakdown,funnel,realtime}( array $params, array $key, int $now ): array|WP_Error`.
  - `dn_bfs_api_rest_callback( WP_REST_Request $request ): WP_REST_Response`; `dn_bfs_api_strip_cors( $served, $result, $request )` trên `rest_pre_serve_request` priority 0.
  - Helper test `dn_bfs_it_api_get( string $route, array $query = array(), string $key = '', string $via = 'bearer' ): WP_REST_Response`.

- [ ] **Step 1: Thêm helper** — cuối `tests/integration/api-helpers.php`:

```php
function dn_bfs_it_api_get( $route, $query = array(), $key = '', $via = 'bearer' ) {
	return rest_do_request( dn_bfs_it_api_request( $route, $query, $key, $via ) );
}
```

- [ ] **Step 2: Viết test fail — `tests/integration/test-api-routes.php`**

```php
<?php

require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/api-helpers.php';

/**
 * Two sessions (VN direct, US referral) with one pageview each, today.
 */
function dn_bfs_it_api_seed_today() {
	$now   = dn_bfs_now();
	$today = wp_date( 'Y-m-d', $now );

	update_option( 'dnbfs_last_aggregated_date', dn_bfs_date_shift( $today, -1 ), false );

	foreach ( array( array( 'VN', 'direct', 120 ), array( 'US', 'referral', 90 ) ) as $row ) {
		$session = dn_bfs_it_seed_session( array( 'started_at' => $now - $row[2], 'country' => $row[0], 'channel' => $row[1] ) );
		dn_bfs_it_seed_pageview( $session, '/', $now - $row[2] );
	}

	return $today;
}

dn_bfs_it(
	'public routes are registered for GET only',
	function () {
		$routes = rest_get_server()->get_routes( 'dnbfs/v1' );

		foreach ( array( 'meta', 'stats/summary', 'stats/timeseries', 'stats/breakdown', 'stats/funnel', 'stats/realtime' ) as $endpoint ) {
			dn_bfs_assert_true( isset( $routes[ '/dnbfs/v1/' . $endpoint ] ), $endpoint );
			dn_bfs_assert_same( array( 'GET' => true ), $routes[ '/dnbfs/v1/' . $endpoint ][0]['methods'], $endpoint );
		}

		dn_bfs_assert_same( 404, rest_do_request( new WP_REST_Request( 'POST', '/dnbfs/v1/meta' ) )->get_status(), 'POST' );
	}
);

dn_bfs_it(
	'meta describes the plugin, the key and the API limits',
	function () {
		$created  = dn_bfs_it_api_key( array( 'scopes' => array( 'stats:read' ) ) );
		$response = dn_bfs_it_api_get( 'meta', array(), $created['key'], 'header' );
		$body     = $response->get_data();

		dn_bfs_assert_same( 200, $response->get_status() );
		dn_bfs_assert_same( array( 'data', 'meta' ), array_keys( $body ) );
		dn_bfs_assert_same( 1, $body['data']['api_version'] );
		dn_bfs_assert_same( $created['prefix'], $body['data']['key']['prefix'] );
		dn_bfs_assert_same( array( 'stats:read' ), $body['data']['key']['scopes'] );
		dn_bfs_assert_same( 366, $body['data']['max_range_days'] );
		dn_bfs_assert_same( dn_bfs_report_dimensions(), $body['data']['dimensions'] );
		dn_bfs_assert_same( dn_bfs_filter_dimensions(), $body['data']['filters'] );
		dn_bfs_assert_same( wp_timezone_string(), $body['meta']['timezone'] );
		dn_bfs_assert_same( get_woocommerce_currency(), $body['meta']['currency'] );
		dn_bfs_assert_same( null, $body['meta']['range'] );
		dn_bfs_assert_same( false, $body['meta']['estimated'] );
		dn_bfs_assert_same( 'no-store', $response->get_headers()['Cache-Control'] );
	}
);

dn_bfs_it_today(
	'summary returns the envelope with totals and an optional comparison',
	function () {
		$created = dn_bfs_it_api_key();
		$today   = dn_bfs_it_api_seed_today();

		$response = dn_bfs_it_api_get( 'stats/summary', array( 'start' => $today, 'end' => $today ), $created['key'] );
		$body     = $response->get_data();

		dn_bfs_assert_same( 200, $response->get_status() );
		dn_bfs_assert_same( 2, $body['data']['current']['sessions'] );
		dn_bfs_assert_same( 2, $body['data']['current']['visitors'] );
		dn_bfs_assert_same( null, $body['data']['previous'] );
		dn_bfs_assert_same( null, $body['data']['previous_range'] );
		dn_bfs_assert_same( array(), (array) $body['data']['change'] );
		dn_bfs_assert_same( array( 'start' => $today, 'end' => $today ), $body['meta']['range'] );
		dn_bfs_assert_same( false, $body['meta']['estimated'] );

		$compared = dn_bfs_it_api_get( 'stats/summary', array( 'start' => $today, 'end' => $today, 'compare' => 'previous_period' ), $created['key'] )->get_data();
		$yesterday = dn_bfs_date_shift( $today, -1 );
		dn_bfs_assert_same( 0, $compared['data']['previous']['sessions'] );
		dn_bfs_assert_same( array( 'start' => $yesterday, 'end' => $yesterday ), $compared['data']['previous_range'] );
		dn_bfs_assert_same( 100.0, $compared['data']['change']->sessions );

		$filtered = dn_bfs_it_api_get( 'stats/summary', array( 'start' => $today, 'end' => $today, 'filter' => array( 'country' => 'VN' ) ), $created['key'] )->get_data();
		dn_bfs_assert_same( 1, $filtered['data']['current']['sessions'], 'filter[country]' );
	}
);

dn_bfs_it_today(
	'timeseries, breakdown, funnel and realtime return their data',
	function () {
		$created = dn_bfs_it_api_key();
		$today   = dn_bfs_it_api_seed_today();
		$range   = array( 'start' => $today, 'end' => $today );

		$series = dn_bfs_it_api_get( 'stats/timeseries', $range + array( 'metrics' => 'sessions,orders' ), $created['key'] )->get_data();
		dn_bfs_assert_same( array( $today ), $series['data']['labels'] );
		dn_bfs_assert_same( array( 'sessions', 'orders' ), array_keys( $series['data']['series'] ) );
		dn_bfs_assert_same( array( 2 ), $series['data']['series']['sessions'] );

		$table = dn_bfs_it_api_get( 'stats/breakdown', $range + array( 'dimension' => 'country', 'orderby' => 'sessions', 'limit' => '1', 'page' => '2' ), $created['key'] )->get_data();
		dn_bfs_assert_same( 'country', $table['data']['dimension'] );
		dn_bfs_assert_same( 2, $table['data']['total'] );
		dn_bfs_assert_same( 2, $table['data']['page'] );
		dn_bfs_assert_same( 1, $table['data']['limit'] );
		dn_bfs_assert_same( 2, $table['data']['pages'] );
		dn_bfs_assert_same( 1, count( $table['data']['rows'] ) );
		dn_bfs_assert_same( 'VN', $table['data']['rows'][0]['dim_value'], 'ties sort by value, page 2 holds VN' );

		$funnel = dn_bfs_it_api_get( 'stats/funnel', $range, $created['key'] )->get_data();
		dn_bfs_assert_same( array( 'visitors', 'product_views', 'atc', 'carts', 'checkouts', 'orders' ), array_column( $funnel['data']['steps'], 'key' ) );
		dn_bfs_assert_same( 2, $funnel['data']['steps'][0]['value'] );
		dn_bfs_assert_same( array( 'start' => $today, 'end' => $today ), $funnel['meta']['range'] );

		$live = dn_bfs_it_api_get( 'stats/realtime', array(), $created['key'] )->get_data();
		dn_bfs_assert_same( 2, $live['data']['online'] );
		dn_bfs_assert_same( null, $live['meta']['range'] );
	}
);

dn_bfs_it(
	'invalid parameters answer 422 with a code and message body',
	function () {
		$created = dn_bfs_it_api_key();
		$day     = wp_date( 'Y-m-d', dn_bfs_now() );
		$range   = array( 'start' => $day, 'end' => $day );

		update_option( 'dnbfs_last_aggregated_date', dn_bfs_date_shift( $day, -1 ), false );

		$cases = array(
			array( 'stats/summary', array(), 'invalid_date' ),
			array( 'stats/summary', array( 'start' => '2026-02-30', 'end' => '2026-03-01' ), 'invalid_date' ),
			array( 'stats/summary', array( 'start' => '2025-01-01', 'end' => '2026-01-02' ), 'range_too_long' ),
			array( 'stats/summary', $range + array( 'compare' => 'last_week' ), 'invalid_compare' ),
			array( 'stats/summary', $range + array( 'filter' => array( 'bogus' => 'x' ) ), 'invalid_filter' ),
			array( 'stats/timeseries', $range + array( 'metrics' => 'sessions,nope' ), 'invalid_metric' ),
			array( 'stats/breakdown', $range, 'invalid_dimension' ),
			array( 'stats/breakdown', $range + array( 'dimension' => 'country', 'orderby' => 'nope' ), 'invalid_orderby' ),
			array( 'stats/breakdown', $range + array( 'dimension' => 'country', 'order' => 'up' ), 'invalid_order' ),
			array( 'stats/breakdown', $range + array( 'dimension' => 'country', 'limit' => '501' ), 'invalid_limit' ),
			array( 'stats/breakdown', $range + array( 'dimension' => 'country', 'limit' => '0' ), 'invalid_limit' ),
			array( 'stats/breakdown', $range + array( 'dimension' => 'country', 'page' => '0' ), 'invalid_page' ),
			array( 'stats/summary', array( 'start' => dn_bfs_date_shift( $day, -365 ), 'end' => $day, 'filter' => array( 'channel' => 'direct', 'country' => 'VN' ) ), 'filter_out_of_retention' ),
		);

		foreach ( $cases as $case ) {
			$response = dn_bfs_it_api_get( $case[0], $case[1], $created['key'] );

			dn_bfs_assert_same( 422, $response->get_status(), $case[2] . ' status' );
			dn_bfs_assert_same( $case[2], $response->get_data()['code'], $case[0] );
			dn_bfs_assert_same( array( 'code', 'message' ), array_keys( $response->get_data() ), $case[2] . ' body' );
		}

		dn_bfs_assert_same( 200, dn_bfs_it_api_get( 'stats/summary', array( 'start' => '2025-01-01', 'end' => '2026-01-01' ), $created['key'] )->get_status(), '366 days' );
	}
);

dn_bfs_it(
	'authentication failures answer 401, 403 and 429 with the spec body',
	function () {
		dn_bfs_it_set_now( 1800000010 );

		$missing = dn_bfs_it_api_get( 'meta' );
		dn_bfs_assert_same( 401, $missing->get_status() );
		dn_bfs_assert_same( array( 'code' => $missing->get_data()['code'], 'message' => $missing->get_data()['message'] ), $missing->get_data() );
		dn_bfs_assert_same( 'missing_key', $missing->get_data()['code'] );
		dn_bfs_assert_true( isset( $missing->get_headers()['WWW-Authenticate'] ), 'WWW-Authenticate' );

		$scoped = dn_bfs_it_api_key( array( 'scopes' => array( 'realtime:read' ), 'rate_limit' => '1' ) );
		$denied = dn_bfs_it_api_get( 'stats/summary', array(), $scoped['key'] );
		dn_bfs_assert_same( 403, $denied->get_status() );
		dn_bfs_assert_same( 'insufficient_scope', $denied->get_data()['code'] );

		dn_bfs_assert_same( 200, dn_bfs_it_api_get( 'meta', array(), $scoped['key'] )->get_status(), 'within the limit' );

		$limited = dn_bfs_it_api_get( 'meta', array(), $scoped['key'] );
		dn_bfs_assert_same( 429, $limited->get_status() );
		dn_bfs_assert_same( 'rate_limited', $limited->get_data()['code'] );
		dn_bfs_assert_same( '50', $limited->get_headers()['Retry-After'] );
	}
);

dn_bfs_it_today(
	'stats responses are cached per endpoint and parameters; realtime is not; purge clears the cache',
	function () {
		$created = dn_bfs_it_api_key();
		$today   = dn_bfs_it_api_seed_today();
		$query   = array( 'start' => $today, 'end' => $today );

		dn_bfs_assert_same( 2, dn_bfs_it_api_get( 'stats/summary', $query, $created['key'] )->get_data()['data']['current']['sessions'] );
		dn_bfs_assert_same( 2, dn_bfs_it_api_get( 'stats/realtime', array(), $created['key'] )->get_data()['data']['online'] );

		$extra = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_now() - 30 ) );
		dn_bfs_it_seed_pageview( $extra, '/', dn_bfs_now() - 30 );

		dn_bfs_assert_same( 2, dn_bfs_it_api_get( 'stats/summary', $query, $created['key'] )->get_data()['data']['current']['sessions'], 'cached' );
		dn_bfs_assert_same( 3, dn_bfs_it_api_get( 'stats/summary', $query + array( 'compare' => 'previous_period' ), $created['key'] )->get_data()['data']['current']['sessions'], 'other parameters' );
		dn_bfs_assert_same( 3, dn_bfs_it_api_get( 'stats/funnel', $query, $created['key'] )->get_data()['data']['steps'][0]['value'], 'other endpoint' );
		dn_bfs_assert_same( 3, dn_bfs_it_api_get( 'stats/realtime', array(), $created['key'] )->get_data()['data']['online'], 'realtime not cached' );

		dn_bfs_assert_same( true, dn_bfs_purge_all_data( 'DELETE' ) );
		dn_bfs_assert_same( 0, dn_bfs_it_api_get( 'stats/summary', $query, $created['key'] )->get_data()['data']['current']['sessions'], 'purge clears the API cache' );
	}
);

dn_bfs_it(
	'public routes never send CORS headers; the collector keeps WordPress defaults',
	function () {
		rest_get_server();
		dn_bfs_assert_true( false !== has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ), 'core CORS filter present' );

		dn_bfs_api_strip_cors( false, null, new WP_REST_Request( 'POST', '/dnbfs/v1/collect' ) );
		dn_bfs_assert_true( false !== has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ), 'collector untouched' );

		dn_bfs_api_strip_cors( false, null, new WP_REST_Request( 'GET', '/dnbfs/v1/stats/summary' ) );
		dn_bfs_assert_true( false === has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ), 'removed for the public API' );

		add_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
	}
);
```

Run tích hợp → Expected: 8 test mới FAIL (`dn_bfs_it_api_get` trả 404 `rest_no_route` hoặc hàm chưa có).

- [ ] **Step 3: Tách bước phễu trong `includes/reports.php`** — thay toàn bộ `dn_bfs_report_funnel()` bằng:

```php
function dn_bfs_report_funnel_steps( $metrics ) {
	$steps = array();

	foreach ( array( 'visitors', 'product_views', 'atc', 'carts', 'checkouts', 'orders' ) as $key ) {
		$steps[] = array(
			'key'   => $key,
			'value' => (int) $metrics[ $key ],
		);
	}

	return $steps;
}

function dn_bfs_report_funnel( $range, $filters = array(), $now = null ) {
	$range['compare'] = 'none';
	$summary          = dn_bfs_report_summary( $range, $filters, $now );

	if ( is_wp_error( $summary ) ) {
		return $summary;
	}

	return dn_bfs_report_funnel_steps( $summary['current'] );
}
```

- [ ] **Step 4: Xóa toàn bộ dữ liệu xóa luôn cache API** — trong `dn_bfs_purge_all_data()` (`includes/admin/data-tools.php`), thay khối:

```php
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_dnbfs_r_' ) . '%' ) );

	foreach ( (array) $names as $name ) {
		delete_transient( substr( $name, strlen( '_transient_' ) ) );
	}
```

bằng:

```php
	foreach ( array( 'dnbfs_r_', 'dnbfs_api_c_' ) as $prefix ) {
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . $prefix ) . '%' ) );

		foreach ( (array) $names as $name ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}
	}
```

- [ ] **Step 5: Tạo `includes/api/routes.php`**

```php
<?php
/**
 * Public read-only REST API (dnbfs/v1): routes, parameters, envelope and cache.
 *
 * Every route shares one callback that authenticates the key itself, so that
 * errors keep the documented {code, message} body instead of WordPress's
 * {code, message, data} shape.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_api_namespace() {
	return 'dnbfs/v1';
}

function dn_bfs_api_max_range_days() {
	return 366;
}

function dn_bfs_api_cache_ttl() {
	return (int) apply_filters( 'dn_bfs_api_cache_ttl', 60 );
}

/**
 * Endpoint => scope ('' = any valid key), handler and whether results are cached.
 */
function dn_bfs_api_endpoints() {
	return array(
		'meta'             => array( 'scope' => '', 'handler' => 'dn_bfs_api_endpoint_meta', 'cache' => false ),
		'stats/summary'    => array( 'scope' => 'stats:read', 'handler' => 'dn_bfs_api_endpoint_summary', 'cache' => true ),
		'stats/timeseries' => array( 'scope' => 'stats:read', 'handler' => 'dn_bfs_api_endpoint_timeseries', 'cache' => true ),
		'stats/breakdown'  => array( 'scope' => 'stats:read', 'handler' => 'dn_bfs_api_endpoint_breakdown', 'cache' => true ),
		'stats/funnel'     => array( 'scope' => 'stats:read', 'handler' => 'dn_bfs_api_endpoint_funnel', 'cache' => true ),
		'stats/realtime'   => array( 'scope' => 'realtime:read', 'handler' => 'dn_bfs_api_endpoint_realtime', 'cache' => false ),
	);
}

function dn_bfs_api_register_routes() {
	foreach ( array_keys( dn_bfs_api_endpoints() ) as $endpoint ) {
		register_rest_route(
			dn_bfs_api_namespace(),
			'/' . preg_quote( $endpoint, '@' ),
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'dn_bfs_api_rest_callback',
				// API keys are checked inside the callback (see the file header).
				'permission_callback' => '__return_true',
			)
		);
	}
}
add_action( 'rest_api_init', 'dn_bfs_api_register_routes' );

function dn_bfs_api_endpoint_from_route( $route ) {
	$prefix = '/' . dn_bfs_api_namespace() . '/';
	$route  = untrailingslashit( (string) $route );

	if ( 0 !== strpos( $route, $prefix ) ) {
		return '';
	}

	$endpoint = substr( $route, strlen( $prefix ) );

	return array_key_exists( $endpoint, dn_bfs_api_endpoints() ) ? $endpoint : '';
}

function dn_bfs_api_query_text( $query, $name ) {
	return isset( $query[ $name ] ) && is_scalar( $query[ $name ] ) ? trim( (string) $query[ $name ] ) : '';
}

/**
 * Whole number in [1, $max]; $default when empty; null when invalid.
 */
function dn_bfs_api_positive_int( $value, $default, $max ) {
	if ( '' === $value ) {
		return $default;
	}

	if ( ! ctype_digit( $value ) || strlen( $value ) > 6 || (int) $value < 1 || (int) $value > $max ) {
		return null;
	}

	return (int) $value;
}

function dn_bfs_api_breakdown_params( $query ) {
	$dimension = dn_bfs_api_query_text( $query, 'dimension' );

	if ( ! in_array( $dimension, dn_bfs_report_dimensions(), true ) ) {
		/* translators: %s: comma-separated dimension names. */
		return dn_bfs_request_error( 'invalid_dimension', sprintf( __( 'Use one of these dimensions: %s.', 'dn-burst-funnel-stats' ), implode( ', ', dn_bfs_report_dimensions() ) ) );
	}

	$orderby = dn_bfs_api_query_text( $query, 'orderby' );

	if ( '' !== $orderby && ! in_array( $orderby, array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() ), true ) ) {
		/* translators: %s: metric name. */
		return dn_bfs_request_error( 'invalid_orderby', sprintf( __( 'Cannot sort by %s.', 'dn-burst-funnel-stats' ), $orderby ) );
	}

	$order = strtolower( dn_bfs_api_query_text( $query, 'order' ) );
	$order = '' === $order ? 'desc' : $order;

	if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
		return dn_bfs_request_error( 'invalid_order', __( 'Order must be asc or desc.', 'dn-burst-funnel-stats' ) );
	}

	$limit = dn_bfs_api_positive_int( dn_bfs_api_query_text( $query, 'limit' ), 25, 500 );

	if ( null === $limit ) {
		return dn_bfs_request_error( 'invalid_limit', __( 'Limit must be a whole number from 1 to 500.', 'dn-burst-funnel-stats' ) );
	}

	$page = dn_bfs_api_positive_int( dn_bfs_api_query_text( $query, 'page' ), 1, 100000 );

	if ( null === $page ) {
		return dn_bfs_request_error( 'invalid_page', __( 'Page must be a whole number starting at 1.', 'dn-burst-funnel-stats' ) );
	}

	return array(
		'dimension' => $dimension,
		'orderby'   => $orderby,
		'order'     => $order,
		'limit'     => $limit,
		'page'      => $page,
	);
}

/**
 * Validated, normalized parameters (also the cache key) for an endpoint.
 */
function dn_bfs_api_request_params( $endpoint, $query ) {
	if ( 0 !== strpos( $endpoint, 'stats/' ) || 'stats/realtime' === $endpoint ) {
		return array();
	}

	$query   = is_array( $query ) ? $query : array();
	$compare = 'stats/summary' === $endpoint ? dn_bfs_api_query_text( $query, 'compare' ) : '';
	$range   = dn_bfs_parse_range(
		array(
			'period'  => 'custom',
			'compare' => '' === $compare ? 'none' : $compare,
			'start'   => dn_bfs_api_query_text( $query, 'start' ),
			'end'     => dn_bfs_api_query_text( $query, 'end' ),
		),
		dn_bfs_api_max_range_days()
	);

	if ( is_wp_error( $range ) ) {
		return $range;
	}

	$filters = dn_bfs_parse_filters( array( 'filter' => isset( $query['filter'] ) ? $query['filter'] : array() ) );

	if ( is_wp_error( $filters ) ) {
		return $filters;
	}

	$params = array(
		'start'   => $range['custom_start'],
		'end'     => $range['custom_end'],
		'compare' => $range['compare'],
		'filters' => $filters,
	);

	if ( 'stats/timeseries' === $endpoint ) {
		$metrics = dn_bfs_parse_metrics( isset( $query['metrics'] ) ? $query['metrics'] : '' );

		if ( is_wp_error( $metrics ) ) {
			return $metrics;
		}

		$params['metrics'] = $metrics;
	}

	if ( 'stats/breakdown' === $endpoint ) {
		$table = dn_bfs_api_breakdown_params( $query );

		if ( is_wp_error( $table ) ) {
			return $table;
		}

		$params = array_merge( $params, $table );
	}

	return $params;
}

function dn_bfs_api_range( $params ) {
	return dn_bfs_calculate_date_range( 'custom', $params['compare'], $params['start'], $params['end'] );
}

function dn_bfs_api_envelope( $data, $params, $estimated ) {
	return array(
		'data' => $data,
		'meta' => array(
			'timezone'  => wp_timezone_string(),
			'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'range'     => isset( $params['start'] ) ? array( 'start' => $params['start'], 'end' => $params['end'] ) : null,
			'estimated' => (bool) $estimated,
		),
	);
}

function dn_bfs_api_endpoint_meta( $params, $key, $now ) {
	unset( $params );

	return dn_bfs_api_envelope(
		array(
			'api_version'          => 1,
			'plugin_version'       => DN_BURST_FUNNEL_STATS_VERSION,
			'site_url'             => home_url( '/' ),
			'key'                  => array(
				'name'       => $key['name'],
				'prefix'     => $key['prefix'],
				'scopes'     => $key['scopes'],
				'rate_limit' => $key['rate_limit'],
			),
			'metrics'              => array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() ),
			'dimensions'           => dn_bfs_report_dimensions(),
			'filters'              => dn_bfs_filter_dimensions(),
			'max_range_days'       => dn_bfs_api_max_range_days(),
			'last_aggregated_date' => (string) get_option( 'dnbfs_last_aggregated_date', '' ),
			'raw_available_from'   => dn_bfs_raw_available_from( $now ),
		),
		array(),
		false
	);
}

function dn_bfs_api_endpoint_summary( $params, $key, $now ) {
	unset( $key );

	$range  = dn_bfs_api_range( $params );
	$report = dn_bfs_report_summary( $range, $params['filters'], $now );

	if ( is_wp_error( $report ) ) {
		return $report;
	}

	$previous_range = null;

	if ( 'none' !== $params['compare'] ) {
		$previous_range = array(
			'start' => wp_date( 'Y-m-d', $range['previous_start'] ),
			'end'   => wp_date( 'Y-m-d', $range['previous_end'] ),
		);
	}

	return dn_bfs_api_envelope(
		array(
			'current'        => $report['current'],
			'previous'       => $report['previous'],
			'change'         => (object) $report['change'],
			'previous_range' => $previous_range,
		),
		$params,
		$report['estimated']
	);
}

function dn_bfs_api_endpoint_timeseries( $params, $key, $now ) {
	unset( $key );

	$report = dn_bfs_report_timeseries( dn_bfs_api_range( $params ), $params['metrics'], $params['filters'], $now );

	if ( is_wp_error( $report ) ) {
		return $report;
	}

	return dn_bfs_api_envelope(
		array(
			'labels' => $report['labels'],
			'series' => $report['series'],
		),
		$params,
		$report['estimated']
	);
}

function dn_bfs_api_endpoint_breakdown( $params, $key, $now ) {
	unset( $key );

	$report = dn_bfs_report_breakdown(
		dn_bfs_api_range( $params ),
		$params['dimension'],
		$params['filters'],
		$params['orderby'],
		$params['order'],
		$params['limit'],
		( $params['page'] - 1 ) * $params['limit'],
		$now
	);

	if ( is_wp_error( $report ) ) {
		return $report;
	}

	return dn_bfs_api_envelope(
		array(
			'dimension' => $params['dimension'],
			'rows'      => $report['rows'],
			'total'     => $report['total'],
			'page'      => $params['page'],
			'limit'     => $params['limit'],
			'pages'     => (int) ceil( $report['total'] / $params['limit'] ),
		),
		$params,
		$report['estimated']
	);
}

function dn_bfs_api_endpoint_funnel( $params, $key, $now ) {
	unset( $key );

	$summary = dn_bfs_report_summary( dn_bfs_api_range( $params ), $params['filters'], $now );

	if ( is_wp_error( $summary ) ) {
		return $summary;
	}

	return dn_bfs_api_envelope( array( 'steps' => dn_bfs_report_funnel_steps( $summary['current'] ) ), $params, $summary['estimated'] );
}

function dn_bfs_api_endpoint_realtime( $params, $key, $now ) {
	unset( $params, $key );

	return dn_bfs_api_envelope( dn_bfs_report_realtime( $now ), array(), false );
}

function dn_bfs_api_response( $body, $status = 200 ) {
	$response = new WP_REST_Response( $body, $status );
	$response->header( 'Cache-Control', 'no-store' );

	return $response;
}

function dn_bfs_api_error_response( $error ) {
	$data   = $error->get_error_data();
	$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

	// The shared request.php validators answer 400; the public API reports invalid parameters as 422.
	if ( 400 === $status ) {
		$status = 422;
	}

	$response = dn_bfs_api_response(
		array(
			'code'    => (string) $error->get_error_code(),
			'message' => $error->get_error_message(),
		),
		$status
	);

	if ( 401 === $status ) {
		$response->header( 'WWW-Authenticate', 'Bearer realm="dnbfs"' );
	}

	if ( 429 === $status && is_array( $data ) && isset( $data['retry_after'] ) ) {
		$response->header( 'Retry-After', (string) (int) $data['retry_after'] );
	}

	return $response;
}

function dn_bfs_api_rest_callback( WP_REST_Request $request ) {
	$endpoint = dn_bfs_api_endpoint_from_route( $request->get_route() );

	if ( '' === $endpoint ) {
		return dn_bfs_api_error_response( dn_bfs_request_error( 'rest_no_route', __( 'No API endpoint matches this URL.', 'dn-burst-funnel-stats' ), 404 ) );
	}

	$endpoints = dn_bfs_api_endpoints();
	$config    = $endpoints[ $endpoint ];
	$now       = dn_bfs_now();
	$key       = dn_bfs_api_authenticate( $request, $config['scope'], $now );

	if ( is_wp_error( $key ) ) {
		return dn_bfs_api_error_response( $key );
	}

	$params = dn_bfs_api_request_params( $endpoint, $request->get_query_params() );

	if ( is_wp_error( $params ) ) {
		return dn_bfs_api_error_response( $params );
	}

	$ttl       = $config['cache'] ? dn_bfs_api_cache_ttl() : 0;
	$cache_key = 'dnbfs_api_c_' . md5( (string) wp_json_encode( array( $endpoint, $params ) ) );

	if ( $ttl > 0 ) {
		$cached = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return dn_bfs_api_response( $cached );
		}
	}

	$body = call_user_func( $config['handler'], $params, $key, $now );

	if ( is_wp_error( $body ) ) {
		return dn_bfs_api_error_response( $body );
	}

	if ( $ttl > 0 ) {
		set_transient( $cache_key, $body, $ttl );
	}

	return dn_bfs_api_response( $body );
}

/**
 * Server-to-server API: never answer with CORS headers (core adds them in
 * rest_send_cors_headers at priority 10 and in WP_REST_Server::serve_request).
 */
function dn_bfs_api_strip_cors( $served, $result, $request ) {
	unset( $result );

	if ( ! $request instanceof WP_REST_Request || '' === dn_bfs_api_endpoint_from_route( $request->get_route() ) ) {
		return $served;
	}

	remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

	if ( ! headers_sent() ) {
		foreach ( array( 'Access-Control-Allow-Origin', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Headers', 'Access-Control-Expose-Headers' ) as $header ) {
			header_remove( $header );
		}
	}

	return $served;
}
add_filter( 'rest_pre_serve_request', 'dn_bfs_api_strip_cors', 0, 3 );
```

- [ ] **Step 6: Nạp module** — trong `dn_burst_funnel_stats_load_api()` đổi thành `array('keys', 'auth', 'routes')`.

- [ ] **Step 7: Chạy test** — tích hợp PASS (thêm 8 test; `funnel lists the six steps in order` và test purge cũ vẫn PASS); unit + `php74` → OK.

- [ ] **Step 8: Kiểm tra thủ công bằng curl** (container `wordpress` đang chạy; chạy trong `bash`):

```bash
KEY=$(docker compose -f docker/docker-compose.yml run --rm -T wpcli wp eval 'echo dn_bfs_api_create_key( array( "name" => "curl", "scopes" => "stats:read,realtime:read" ) )["key"];')
curl -si -H "Origin: https://evil.example" -H "Authorization: Bearer $KEY" "http://localhost:8080/wp-json/dnbfs/v1/stats/summary?start=$(date +%F)&end=$(date +%F)" | sed -n '1,20p'
curl -si -H "Origin: https://evil.example" -H "Authorization: Bearer $KEY" "http://localhost:8080/wp-json/dnbfs/v1/meta" | grep -i '^access-control' || echo "no CORS headers"
curl -s "http://localhost:8080/wp-json/dnbfs/v1/meta"
```

Expected: lệnh 1 trả `HTTP/1.1 200` và JSON `{"data":{"current":…},"meta":{…}}`; lệnh 2 in `no CORS headers`; lệnh 3 in `{"code":"missing_key","message":"…"}`. Thu hồi key thử: `docker compose -f docker/docker-compose.yml run --rm -T wpcli wp eval 'foreach ( dn_bfs_api_list_keys() as $k ) { if ( "curl" === $k["name"] ) { dn_bfs_api_revoke_key( $k["id"] ); } }'`.

- [ ] **Step 9: Commit**

```bash
git add includes/api/routes.php includes/reports.php includes/admin/data-tools.php dn-burst-funnel-stats.php tests/integration/api-helpers.php tests/integration/test-api-routes.php
git commit -m "feat(api): add public read-only stats routes with envelope, 60s cache and no CORS

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Tài liệu OpenAPI `/openapi.json`

**Files:**
- Create: `includes/api/openapi.php`, `tests/integration/test-api-openapi.php`
- Modify: `includes/api/routes.php` (`dn_bfs_api_endpoints`), `dn-burst-funnel-stats.php` (thêm `'openapi'`)

**Interfaces:**
- Consumes: `dn_bfs_api_namespace()`, `dn_bfs_api_max_range_days()`, `dn_bfs_report_dimensions()`, `dn_bfs_filter_dimensions()`, `dn_bfs_metric_columns()`, `dn_bfs_derived_metric_names()`, `dn_bfs_api_endpoints()`.
- Produces: `dn_bfs_api_openapi_param( $name, $description, $schema, $required = false ): array`, `dn_bfs_api_openapi_operation( $summary, $scope, $parameters, $data_schema ): array`, `dn_bfs_api_openapi_document(): array` (OpenAPI 3.0.3), handler `dn_bfs_api_endpoint_openapi( $params, $key, $now ): array` (tài liệu thô, không envelope); endpoint `openapi.json` (key bất kỳ, không cache).

- [ ] **Step 1: Viết test fail — `tests/integration/test-api-openapi.php`**

```php
<?php

require_once __DIR__ . '/api-helpers.php';

dn_bfs_it(
	'openapi.json describes every endpoint and needs a key',
	function () {
		$created  = dn_bfs_it_api_key( array( 'scopes' => array( 'realtime:read' ) ) );
		$response = dn_bfs_it_api_get( 'openapi.json', array(), $created['key'] );
		$doc      = $response->get_data();

		dn_bfs_assert_same( 200, $response->get_status() );
		dn_bfs_assert_same( '3.0.3', $doc['openapi'] );
		dn_bfs_assert_same( untrailingslashit( rest_url( 'dnbfs/v1' ) ), $doc['servers'][0]['url'] );
		dn_bfs_assert_same( array( '/meta', '/stats/summary', '/stats/timeseries', '/stats/breakdown', '/stats/funnel', '/stats/realtime', '/openapi.json' ), array_keys( $doc['paths'] ) );
		dn_bfs_assert_same( array( 'bearerAuth', 'keyHeader' ), array_keys( $doc['components']['securitySchemes'] ) );
		dn_bfs_assert_same( 'X-DNBFS-Key', $doc['components']['securitySchemes']['keyHeader']['name'] );
		dn_bfs_assert_same( array( 'start', 'end', 'filter', 'dimension', 'orderby', 'order', 'limit', 'page' ), array_column( $doc['paths']['/stats/breakdown']['get']['parameters'], 'name' ) );
		dn_bfs_assert_same( 500, $doc['paths']['/stats/breakdown']['get']['parameters'][6]['schema']['maximum'] );
		dn_bfs_assert_true( isset( $doc['paths']['/stats/summary']['get']['responses']['422'] ), 'summary documents 422' );
		dn_bfs_assert_true( isset( $doc['paths']['/stats/realtime']['get']['responses']['429'] ), 'realtime documents 429' );
		dn_bfs_assert_true( false !== wp_json_encode( $doc ), 'JSON encodable' );

		dn_bfs_assert_same( 401, dn_bfs_it_api_get( 'openapi.json' )->get_status(), 'needs a key' );
	}
);
```

Run tích hợp → Expected: FAIL (`rest_no_route`, status 404).

- [ ] **Step 2: Tạo `includes/api/openapi.php`**

```php
<?php
/**
 * OpenAPI 3.0 description of the public REST API, served at /openapi.json.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_api_openapi_param( $name, $description, $schema, $required = false ) {
	return array(
		'name'        => $name,
		'in'          => 'query',
		'required'    => (bool) $required,
		'description' => $description,
		'schema'      => $schema,
	);
}

function dn_bfs_api_openapi_operation( $summary, $scope, $parameters, $data_schema ) {
	$error     = array( '$ref' => '#/components/responses/Error' );
	$responses = array(
		'200' => array(
			'description' => 'OK',
			'content'     => array(
				'application/json' => array(
					'schema' => array(
						'type'       => 'object',
						'required'   => array( 'data', 'meta' ),
						'properties' => array(
							'data' => $data_schema,
							'meta' => array( '$ref' => '#/components/schemas/Meta' ),
						),
					),
				),
			),
		),
		'401' => $error,
		'403' => $error,
	);

	if ( ! empty( $parameters ) ) {
		$responses['422'] = $error;
	}

	$responses['429'] = $error;

	return array(
		'summary'     => $summary,
		'description' => '' === $scope ? 'Any valid API key.' : 'Requires the ' . $scope . ' scope.',
		'parameters'  => $parameters,
		'responses'   => $responses,
	);
}

function dn_bfs_api_openapi_document() {
	$date    = array( 'type' => 'string', 'format' => 'date' );
	$string  = array( 'type' => 'string' );
	$integer = array( 'type' => 'integer' );
	$numbers = array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'number' ) );
	$metrics = array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() );
	$period  = array( 'type' => 'object', 'nullable' => true, 'properties' => array( 'start' => $date, 'end' => $date ) );
	$error   = array( '$ref' => '#/components/responses/Error' );
	$range   = array(
		dn_bfs_api_openapi_param( 'start', 'First day, YYYY-MM-DD in the store timezone.', $date, true ),
		dn_bfs_api_openapi_param( 'end', 'Last day, YYYY-MM-DD in the store timezone. At most ' . dn_bfs_api_max_range_days() . ' days including both ends.', $date, true ),
		array(
			'name'        => 'filter',
			'in'          => 'query',
			'style'       => 'deepObject',
			'explode'     => true,
			'description' => 'filter[dimension]=value. Dimensions: ' . implode( ', ', dn_bfs_filter_dimensions() ) . '. Two or more filters only work while raw data is kept (422 filter_out_of_retention otherwise).',
			'schema'      => array( 'type' => 'object', 'additionalProperties' => $string ),
		),
	);
	$rows    = array(
		'type'  => 'array',
		'items' => array(
			'type'                 => 'object',
			'properties'           => array( 'dim_value' => $string, 'label' => $string ),
			'additionalProperties' => array( 'type' => 'number' ),
		),
	);
	$visits  = function ( $name ) use ( $string, $integer ) {
		return array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( $name => $string, 'visitors' => $integer ) ) );
	};

	return array(
		'openapi'    => '3.0.3',
		'info'       => array(
			'title'       => 'DN Burst Funnel Stats API',
			'version'     => DN_BURST_FUNNEL_STATS_VERSION,
			'description' => 'Read-only WooCommerce funnel statistics for server-to-server use: HTTPS, API key, no CORS. Successful statistics are cached for 60 seconds.',
		),
		'servers'    => array( array( 'url' => untrailingslashit( rest_url( dn_bfs_api_namespace() ) ) ) ),
		'security'   => array( array( 'bearerAuth' => array() ), array( 'keyHeader' => array() ) ),
		'paths'      => array(
			'/meta'             => array(
				'get' => dn_bfs_api_openapi_operation( 'Plugin, key and API limits', '', array(), array( 'type' => 'object' ) ),
			),
			'/stats/summary'    => array(
				'get' => dn_bfs_api_openapi_operation(
					'Totals for a date range, with an optional comparison',
					'stats:read',
					array_merge( $range, array( dn_bfs_api_openapi_param( 'compare', 'Comparison period.', array( 'type' => 'string', 'enum' => array( 'none', 'previous_period', 'previous_year' ), 'default' => 'none' ) ) ) ),
					array(
						'type'       => 'object',
						'properties' => array(
							'current'        => $numbers,
							'previous'       => array_merge( $numbers, array( 'nullable' => true ) ),
							'change'         => $numbers,
							'previous_range' => $period,
						),
					)
				),
			),
			'/stats/timeseries' => array(
				'get' => dn_bfs_api_openapi_operation(
					'Daily values per metric',
					'stats:read',
					array_merge( $range, array( dn_bfs_api_openapi_param( 'metrics', 'Comma-separated metrics: ' . implode( ', ', $metrics ) . '.', array( 'type' => 'string', 'default' => 'sessions,orders,revenue' ) ) ) ),
					array(
						'type'       => 'object',
						'properties' => array(
							'labels' => array( 'type' => 'array', 'items' => $date ),
							'series' => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'array', 'items' => array( 'type' => 'number' ) ) ),
						),
					)
				),
			),
			'/stats/breakdown'  => array(
				'get' => dn_bfs_api_openapi_operation(
					'Rows for one dimension, sorted and paginated',
					'stats:read',
					array_merge(
						$range,
						array(
							dn_bfs_api_openapi_param( 'dimension', 'Dimension to group by.', array( 'type' => 'string', 'enum' => dn_bfs_report_dimensions() ), true ),
							dn_bfs_api_openapi_param( 'orderby', 'Metric to sort by.', array( 'type' => 'string', 'enum' => $metrics ) ),
							dn_bfs_api_openapi_param( 'order', 'Sort direction.', array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ) ),
							dn_bfs_api_openapi_param( 'limit', 'Rows per page.', array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 25 ) ),
							dn_bfs_api_openapi_param( 'page', 'Page number, starting at 1.', array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ) ),
						)
					),
					array(
						'type'       => 'object',
						'properties' => array(
							'dimension' => $string,
							'rows'      => $rows,
							'total'     => $integer,
							'page'      => $integer,
							'limit'     => $integer,
							'pages'     => $integer,
						),
					)
				),
			),
			'/stats/funnel'     => array(
				'get' => dn_bfs_api_openapi_operation(
					'Funnel steps: visitors, product views, add to cart, cart, checkout, orders',
					'stats:read',
					$range,
					array(
						'type'       => 'object',
						'properties' => array(
							'steps' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'key' => $string, 'value' => $integer ) ) ),
						),
					)
				),
			),
			'/stats/realtime'   => array(
				'get' => dn_bfs_api_openapi_operation(
					'Visitors online in the last 5 minutes (not cached)',
					'realtime:read',
					array(),
					array(
						'type'       => 'object',
						'properties' => array(
							'online'   => $integer,
							'pages'    => $visits( 'path' ),
							'channels' => $visits( 'channel' ),
						),
					)
				),
			),
			'/openapi.json'     => array(
				'get' => array(
					'summary'     => 'This document',
					'description' => 'Any valid API key.',
					'responses'   => array(
						'200' => array( 'description' => 'OpenAPI 3.0 document', 'content' => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ) ),
						'401' => $error,
						'403' => $error,
						'429' => $error,
					),
				),
			),
		),
		'components' => array(
			'securitySchemes' => array(
				'bearerAuth' => array( 'type' => 'http', 'scheme' => 'bearer', 'description' => 'Authorization: Bearer dnbfs_<prefix>_<secret>' ),
				'keyHeader'  => array( 'type' => 'apiKey', 'in' => 'header', 'name' => 'X-DNBFS-Key' ),
			),
			'schemas'         => array(
				'Error' => array(
					'type'       => 'object',
					'required'   => array( 'code', 'message' ),
					'properties' => array( 'code' => $string, 'message' => $string ),
				),
				'Meta'  => array(
					'type'       => 'object',
					'required'   => array( 'timezone', 'currency', 'range', 'estimated' ),
					'properties' => array(
						'timezone'  => $string,
						'currency'  => $string,
						'range'     => $period,
						'estimated' => array( 'type' => 'boolean', 'description' => 'True when visitor counts were summed per day.' ),
					),
				),
			),
			'responses'       => array(
				'Error' => array(
					'description' => '401 missing/invalid/revoked key; 403 missing scope, IP not allowed or HTTPS required; 422 invalid parameters; 429 rate limited (see Retry-After).',
					'content'     => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/Error' ) ) ),
				),
			),
		),
	);
}

function dn_bfs_api_endpoint_openapi( $params, $key, $now ) {
	unset( $params, $key, $now );

	return dn_bfs_api_openapi_document();
}
```

- [ ] **Step 3: Thêm endpoint** — trong `dn_bfs_api_endpoints()` (`includes/api/routes.php`), thêm dòng cuối mảng (sau `stats/realtime`):

```php
		'openapi.json'     => array( 'scope' => '', 'handler' => 'dn_bfs_api_endpoint_openapi', 'cache' => false ),
```

Trong `dn_burst_funnel_stats_load_api()` đổi thành `array('keys', 'auth', 'routes', 'openapi')`.

- [ ] **Step 4: Chạy test** — tích hợp PASS (thêm 1 test, `test-api-routes.php` vẫn PASS); unit + `php74` → OK. Kiểm tra thủ công: `curl -s -H "X-DNBFS-Key: $KEY" http://localhost:8080/wp-json/dnbfs/v1/openapi.json | head -c 200` → bắt đầu bằng `{"openapi":"3.0.3"`.

- [ ] **Step 5: Commit**

```bash
git add includes/api/openapi.php includes/api/routes.php dn-burst-funnel-stats.php tests/integration/test-api-openapi.php
git commit -m "feat(api): serve an OpenAPI 3.0 description at /openapi.json

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Tab API trong Settings (tạo, hiện một lần, thu hồi)

**Files:**
- Create: `includes/admin/api-keys-page.php`, `tests/integration/test-api-admin.php`
- Modify: `includes/admin/settings-page.php` (`dn_bfs_settings_tabs`, `dn_bfs_settings_notice`, `dn_bfs_render_settings_page`), `tests/integration/test-settings-page.php`, `dn-burst-funnel-stats.php` (thêm `'api-keys-page'` vào `dn_burst_funnel_stats_load_admin()`)

**Interfaces:**
- Consumes: `dn_bfs_api_create_key()`, `dn_bfs_api_revoke_key()`, `dn_bfs_api_list_keys()`, `dn_bfs_api_scopes()`, `dn_bfs_api_namespace()`, `dn_bfs_admin_permission()`, `dn_bfs_settings_url()`, `dn_bfs_date_shift()`.
- Produces:
  - `dn_bfs_api_reveal_key_store( int $user_id, string $key ): void` (transient `dnbfs_api_reveal_<user_id>`, 5 phút); `dn_bfs_api_reveal_key_take( int $user_id ): string` (đọc rồi xóa; `''` nếu không có).
  - `dn_bfs_api_key_task( string $task, array $post, int $user_id ): array{tab: 'api', notice: string}` — notice `key_created`, `key_revoked`, `invalid_name`, `invalid_scopes`, `invalid_ips`, `invalid_rate_limit`, `key_create_failed`, `key_not_found`, `invalid_task`.
  - `dn_bfs_handle_api_key_task()` trên `admin_post_dn_bfs_api_key`; `dn_bfs_render_settings_api()`.
  - `dn_bfs_settings_tabs()` thêm `'api' => 'API'` ở vị trí thứ 8.

- [ ] **Step 1: Viết test fail — `tests/integration/test-api-admin.php`**

```php
<?php

require_once __DIR__ . '/admin-helpers.php';
require_once __DIR__ . '/api-helpers.php';

function dn_bfs_it_render_api_tab() {
	$_GET = array( 'page' => 'dn-burst-funnel-stats-settings', 'tab' => 'api' );
	ob_start();
	dn_bfs_render_settings_page();
	$html = ob_get_clean();
	$_GET = array();

	return $html;
}

dn_bfs_it(
	'the API tab creates a key, shows it exactly once and revokes it',
	function () {
		$user = dn_bfs_it_login_admin();

		dn_bfs_assert_same( 'api', array_keys( dn_bfs_settings_tabs() )[7] );

		$result = dn_bfs_api_key_task( 'create', array( 'name' => 'NestJS', 'scopes' => array( 'stats:read' ), 'allowed_ips' => "198.51.100.0/24\n", 'rate_limit' => '120' ), $user );
		dn_bfs_assert_same( array( 'tab' => 'api', 'notice' => 'key_created' ), $result );

		$keys = dn_bfs_api_list_keys();
		dn_bfs_assert_same( 1, count( $keys ) );
		dn_bfs_assert_same( 'NestJS', $keys[0]['name'] );
		dn_bfs_assert_same( array( 'stats:read' ), $keys[0]['scopes'] );
		dn_bfs_assert_same( array( '198.51.100.0/24' ), $keys[0]['allowed_ips'] );
		dn_bfs_assert_same( 120, $keys[0]['rate_limit'] );

		$html = dn_bfs_it_render_api_tab();
		dn_bfs_assert_same( 1, preg_match_all( '/dnbfs_[a-z0-9]{8}_[A-Za-z0-9]{32}/', $html, $matches ), 'full key shown once' );
		dn_bfs_assert_same( $keys[0]['id'], dn_bfs_api_find_key( $matches[0][0] )['id'], 'shown key works' );
		dn_bfs_assert_true( false === strpos( $html, $keys[0]['key_hash'] ), 'hash never rendered' );
		dn_bfs_assert_true( false !== strpos( $html, 'NestJS' ), 'listed' );
		dn_bfs_assert_true( false !== strpos( $html, untrailingslashit( rest_url( 'dnbfs/v1' ) ) ), 'base URL hint' );
		dn_bfs_assert_true( false !== strpos( $html, 'name="task" value="create"' ), 'create form' );

		$again = dn_bfs_it_render_api_tab();
		dn_bfs_assert_same( 0, preg_match( '/dnbfs_[a-z0-9]{8}_[A-Za-z0-9]{32}/', $again ), 'not shown again' );
		dn_bfs_assert_true( false !== strpos( $again, 'dnbfs_' . $keys[0]['prefix'] . '_' ), 'prefix still listed' );
		dn_bfs_assert_true( false !== strpos( $again, 'name="task" value="revoke"' ), 'revoke form for active key' );

		dn_bfs_assert_same( array( 'tab' => 'api', 'notice' => 'key_revoked' ), dn_bfs_api_key_task( 'revoke', array( 'key_id' => (string) $keys[0]['id'] ), $user ) );
		dn_bfs_assert_same( 'key_not_found', dn_bfs_api_key_task( 'revoke', array( 'key_id' => (string) $keys[0]['id'] ), $user )['notice'] );

		$revoked = dn_bfs_it_render_api_tab();
		dn_bfs_assert_true( false !== strpos( $revoked, 'Revoked' ), 'revoked status' );
		dn_bfs_assert_true( false === strpos( $revoked, 'name="task" value="revoke"' ), 'no revoke button for revoked keys' );
	}
);

dn_bfs_it(
	'API key task errors map to notices and reveals are per user',
	function () {
		$user  = dn_bfs_it_login_admin();
		$cases = array(
			'invalid_name'       => array( 'scopes' => array( 'stats:read' ) ),
			'invalid_scopes'     => array( 'name' => 'X' ),
			'invalid_ips'        => array( 'name' => 'X', 'scopes' => array( 'stats:read' ), 'allowed_ips' => 'nope' ),
			'invalid_rate_limit' => array( 'name' => 'X', 'scopes' => array( 'stats:read' ), 'rate_limit' => '5000' ),
		);

		foreach ( $cases as $code => $post ) {
			dn_bfs_assert_same( $code, dn_bfs_api_key_task( 'create', $post, $user )['notice'], $code );
			dn_bfs_assert_same( 'error', dn_bfs_settings_notice( $code )[0], $code . ' notice' );
		}

		dn_bfs_assert_same( 0, dn_bfs_it_count( 'api_keys' ) );
		dn_bfs_assert_same( 'invalid_task', dn_bfs_api_key_task( 'delete', array(), $user )['notice'] );
		dn_bfs_assert_same( 'success', dn_bfs_settings_notice( 'key_created' )[0] );
		dn_bfs_assert_same( 'success', dn_bfs_settings_notice( 'key_revoked' )[0] );
		dn_bfs_assert_same( 'error', dn_bfs_settings_notice( 'key_not_found' )[0] );

		dn_bfs_api_reveal_key_store( $user, 'dnbfs_example' );
		dn_bfs_assert_same( '', dn_bfs_api_reveal_key_take( $user + 1000 ), 'other user sees nothing' );
		dn_bfs_assert_same( 'dnbfs_example', dn_bfs_api_reveal_key_take( $user ) );
		dn_bfs_assert_same( '', dn_bfs_api_reveal_key_take( $user ), 'taken once' );
	}
);
```

Sửa `tests/integration/test-settings-page.php`:
- Trong test `every settings group has field definitions matching the model keys`, đổi kỳ vọng thành `array( 'general', 'tracking', 'antispam', 'woocommerce', 'geoip', 'data', 'system', 'api' )`.
- Trong test `settings page renders each tab`, đổi mảng lặp thành `array( 'general', 'tracking', 'antispam', 'woocommerce', 'geoip', 'data', 'api', 'system' )` (giữ `system` cuối vì assertion sau vòng lặp tìm `dn-burst-status-badge`).

Run tích hợp → Expected: 2 test mới + 2 test settings-page FAIL.

- [ ] **Step 2: Tạo `includes/admin/api-keys-page.php`**

```php
<?php
/**
 * Settings → API: create, list and revoke public API keys.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The full key lives only in this per-user transient until the next page view.
 */
function dn_bfs_api_reveal_key_store( $user_id, $key ) {
	set_transient( 'dnbfs_api_reveal_' . (int) $user_id, (string) $key, 5 * MINUTE_IN_SECONDS );
}

function dn_bfs_api_reveal_key_take( $user_id ) {
	$name = 'dnbfs_api_reveal_' . (int) $user_id;
	$key  = get_transient( $name );

	delete_transient( $name );

	return is_string( $key ) ? $key : '';
}

function dn_bfs_api_key_task( $task, $post, $user_id ) {
	if ( 'create' === $task ) {
		$pick   = function ( $name ) use ( $post ) {
			return isset( $post[ $name ] ) && is_scalar( $post[ $name ] ) ? (string) $post[ $name ] : '';
		};
		$result = dn_bfs_api_create_key(
			array(
				'name'        => $pick( 'name' ),
				'scopes'      => isset( $post['scopes'] ) && is_array( $post['scopes'] ) ? $post['scopes'] : array(),
				'allowed_ips' => $pick( 'allowed_ips' ),
				'rate_limit'  => $pick( 'rate_limit' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return array( 'tab' => 'api', 'notice' => $result->get_error_code() );
		}

		dn_bfs_api_reveal_key_store( $user_id, $result['key'] );

		return array( 'tab' => 'api', 'notice' => 'key_created' );
	}

	if ( 'revoke' === $task ) {
		$result = dn_bfs_api_revoke_key( isset( $post['key_id'] ) && is_scalar( $post['key_id'] ) ? absint( $post['key_id'] ) : 0 );

		return array( 'tab' => 'api', 'notice' => is_wp_error( $result ) ? $result->get_error_code() : 'key_revoked' );
	}

	return array( 'tab' => 'api', 'notice' => 'invalid_task' );
}

function dn_bfs_handle_api_key_task() {
	$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked below.

	if ( ! dn_bfs_admin_permission() ) {
		wp_die( esc_html__( 'You do not have permission to manage API keys.', 'dn-burst-funnel-stats' ), 403 );
	}

	check_admin_referer( 'dn_bfs_api_key_' . $task );

	$result = dn_bfs_api_key_task( $task, wp_unslash( $_POST ), get_current_user_id() );

	wp_safe_redirect( dn_bfs_settings_url( $result['tab'], array( 'dn_notice' => $result['notice'] ) ) );
	exit;
}
add_action( 'admin_post_dn_bfs_api_key', 'dn_bfs_handle_api_key_task' );

function dn_bfs_render_settings_api_keys_table( $keys ) {
	$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	$confirm = __( 'Revoke this key? Apps using it stop working immediately.', 'dn-burst-funnel-stats' );
	?>
	<table class="widefat striped dn-burst-api-keys">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'dn-burst-funnel-stats' ); ?></th>
				<th><?php esc_html_e( 'Key', 'dn-burst-funnel-stats' ); ?></th>
				<th><?php esc_html_e( 'Scopes', 'dn-burst-funnel-stats' ); ?></th>
				<th><?php esc_html_e( 'Allowed IPs', 'dn-burst-funnel-stats' ); ?></th>
				<th><?php esc_html_e( 'Rate limit', 'dn-burst-funnel-stats' ); ?></th>
				<th><?php esc_html_e( 'Last used', 'dn-burst-funnel-stats' ); ?></th>
				<th><?php esc_html_e( 'Created', 'dn-burst-funnel-stats' ); ?></th>
				<th><?php esc_html_e( 'Status', 'dn-burst-funnel-stats' ); ?></th>
				<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'dn-burst-funnel-stats' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $keys as $key ) : ?>
				<tr>
					<td><?php echo esc_html( $key['name'] ); ?></td>
					<td><code><?php echo esc_html( 'dnbfs_' . $key['prefix'] . '_…' ); ?></code></td>
					<td><?php echo esc_html( implode( ', ', $key['scopes'] ) ); ?></td>
					<td><?php echo esc_html( empty( $key['allowed_ips'] ) ? __( 'Any', 'dn-burst-funnel-stats' ) : implode( ', ', $key['allowed_ips'] ) ); ?></td>
					<?php /* translators: %d: requests per minute. */ ?>
					<td><?php echo esc_html( sprintf( __( '%d / min', 'dn-burst-funnel-stats' ), $key['rate_limit'] ) ); ?></td>
					<td><?php echo esc_html( $key['last_used_at'] ? wp_date( $format, $key['last_used_at'] ) : __( 'Never', 'dn-burst-funnel-stats' ) ); ?></td>
					<td><?php echo esc_html( wp_date( $format, $key['created_at'] ) ); ?></td>
					<td>
						<?php if ( $key['revoked_at'] ) : ?>
							<span class="dn-burst-status-badge is-error"><?php esc_html_e( 'Revoked', 'dn-burst-funnel-stats' ); ?></span>
						<?php else : ?>
							<span class="dn-burst-status-badge is-ok"><?php esc_html_e( 'Active', 'dn-burst-funnel-stats' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( ! $key['revoked_at'] ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dn-burst-inline-form" onsubmit="return window.confirm('<?php echo esc_js( $confirm ); ?>');">
								<?php wp_nonce_field( 'dn_bfs_api_key_revoke' ); ?>
								<input type="hidden" name="action" value="dn_bfs_api_key" />
								<input type="hidden" name="task" value="revoke" />
								<input type="hidden" name="key_id" value="<?php echo esc_attr( $key['id'] ); ?>" />
								<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Revoke', 'dn-burst-funnel-stats' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function dn_bfs_render_settings_api() {
	$revealed = dn_bfs_api_reveal_key_take( get_current_user_id() );
	$keys     = dn_bfs_api_list_keys();
	$base     = untrailingslashit( rest_url( dn_bfs_api_namespace() ) );
	$end      = wp_date( 'Y-m-d' );
	$start    = dn_bfs_date_shift( $end, -6 );
	?>
	<?php if ( '' !== $revealed ) : ?>
		<div class="notice notice-warning inline dn-burst-api-reveal">
			<p><strong><?php esc_html_e( 'Copy the new API key now. It will not be shown again.', 'dn-burst-funnel-stats' ); ?></strong></p>
			<p><input type="text" class="large-text code" readonly="readonly" value="<?php echo esc_attr( $revealed ); ?>" onfocus="this.select();" aria-label="<?php esc_attr_e( 'New API key', 'dn-burst-funnel-stats' ); ?>" /></p>
		</div>
	<?php endif; ?>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'API keys', 'dn-burst-funnel-stats' ); ?></h2>
		<?php if ( empty( $keys ) ) : ?>
			<p><?php esc_html_e( 'No API keys yet.', 'dn-burst-funnel-stats' ); ?></p>
		<?php else : ?>
			<?php dn_bfs_render_settings_api_keys_table( $keys ); ?>
		<?php endif; ?>
	</div>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'Create an API key', 'dn-burst-funnel-stats' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'dn_bfs_api_key_create' ); ?>
			<input type="hidden" name="action" value="dn_bfs_api_key" />
			<input type="hidden" name="task" value="create" />
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="dn_bfs_api_name"><?php esc_html_e( 'Name', 'dn-burst-funnel-stats' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="dn_bfs_api_name" name="name" maxlength="100" required />
							<p class="description"><?php esc_html_e( 'Which app uses this key, for example "Reporting backend".', 'dn-burst-funnel-stats' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Scopes', 'dn-burst-funnel-stats' ); ?></th>
						<td>
							<fieldset class="dn-burst-checklist">
								<?php foreach ( dn_bfs_api_scopes() as $scope => $label ) : ?>
									<label><input type="checkbox" name="scopes[]" value="<?php echo esc_attr( $scope ); ?>" <?php checked( 'stats:read', $scope ); ?> /> <code><?php echo esc_html( $scope ); ?></code> — <?php echo esc_html( $label ); ?></label><br />
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dn_bfs_api_ips"><?php esc_html_e( 'Allowed IPs', 'dn-burst-funnel-stats' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="4" id="dn_bfs_api_ips" name="allowed_ips"></textarea>
							<p class="description"><?php esc_html_e( 'One IP address or CIDR range per line. Leave empty to allow any IP. The client IP follows the "Client IP source" setting in the Tracking tab.', 'dn-burst-funnel-stats' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dn_bfs_api_rate"><?php esc_html_e( 'Rate limit', 'dn-burst-funnel-stats' ); ?></label></th>
						<td><input type="number" class="small-text" id="dn_bfs_api_rate" name="rate_limit" value="60" min="1" max="1000" step="1" /> <?php esc_html_e( 'requests per minute', 'dn-burst-funnel-stats' ); ?></td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( __( 'Create API key', 'dn-burst-funnel-stats' ) ); ?>
		</form>
	</div>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'Using the API', 'dn-burst-funnel-stats' ); ?></h2>
		<p><?php esc_html_e( 'Base URL:', 'dn-burst-funnel-stats' ); ?> <code><?php echo esc_html( $base ); ?></code></p>
		<pre><code><?php echo esc_html( 'curl -H "Authorization: Bearer dnbfs_xxxxxxxx_…" "' . $base . '/stats/summary?start=' . $start . '&end=' . $end . '"' ); ?></code></pre>
		<p class="description">
			<?php esc_html_e( 'Read-only, for your own servers: HTTPS is required (local development hosts excepted) and browsers cannot call it because no CORS headers are sent. Statistics are cached for 60 seconds.', 'dn-burst-funnel-stats' ); ?>
			<?php /* translators: %s: OpenAPI document URL. */ ?>
			<?php echo esc_html( sprintf( __( 'Full description: %s', 'dn-burst-funnel-stats' ), $base . '/openapi.json' ) ); ?>
		</p>
	</div>
	<?php
}
```

- [ ] **Step 3: Nối vào trang Settings** — trong `includes/admin/settings-page.php`:

1. `dn_bfs_settings_tabs()`: thêm sau dòng `'system'      => __( 'System', 'dn-burst-funnel-stats' ),`:

```php
		'api'         => __( 'API', 'dn-burst-funnel-stats' ),
```

2. `dn_bfs_settings_notice()`: thêm vào cuối mảng `$messages` (sau `'range_too_long' => …`):

```php
		'key_created'        => array( 'success', __( 'API key created. Copy it from the box below — it is shown only once.', 'dn-burst-funnel-stats' ) ),
		'key_revoked'        => array( 'success', __( 'The API key was revoked.', 'dn-burst-funnel-stats' ) ),
		'invalid_name'       => array( 'error', __( 'Give the key a name.', 'dn-burst-funnel-stats' ) ),
		'invalid_scopes'     => array( 'error', __( 'Choose at least one scope.', 'dn-burst-funnel-stats' ) ),
		'invalid_ips'        => array( 'error', __( 'Allowed IPs must be IP addresses or CIDR ranges, one per line.', 'dn-burst-funnel-stats' ) ),
		'invalid_rate_limit' => array( 'error', __( 'The rate limit must be between 1 and 1000 requests per minute.', 'dn-burst-funnel-stats' ) ),
		'key_not_found'      => array( 'error', __( 'That key does not exist or is already revoked.', 'dn-burst-funnel-stats' ) ),
		'key_create_failed'  => array( 'error', __( 'The key could not be saved. Please try again.', 'dn-burst-funnel-stats' ) ),
```

3. `dn_bfs_render_settings_page()`: thay

```php
		if ( 'system' === $tab ) {
			dn_bfs_render_settings_system();
		} else {
```

bằng

```php
		if ( 'system' === $tab ) {
			dn_bfs_render_settings_system();
		} elseif ( 'api' === $tab ) {
			dn_bfs_render_settings_api();
		} else {
```

4. `dn-burst-funnel-stats.php`: trong `dn_burst_funnel_stats_load_admin()` thêm `'api-keys-page'` ngay sau `'settings-page'`.

- [ ] **Step 4: Chạy test** — tích hợp PASS (thêm 2 test, settings-page PASS); unit + `php74` → OK. Kiểm tra thủ công: đăng nhập `http://localhost:8080/wp-admin/admin.php?page=dn-burst-funnel-stats-settings&tab=api`, tạo key → URL sau redirect chỉ có `dn_notice=key_created`, ô key hiện một lần; tải lại trang → ô biến mất; Revoke hỏi xác nhận rồi hiện "Revoked".

- [ ] **Step 5: Commit**

```bash
git add includes/admin/api-keys-page.php includes/admin/settings-page.php dn-burst-funnel-stats.php tests/integration/test-api-admin.php tests/integration/test-settings-page.php
git commit -m "feat(admin): add Settings → API tab to create, reveal once and revoke API keys

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Kiểm tra hệ thống cho API + gỡ cài đặt

**Files:**
- Modify: `includes/admin/system-status.php`, `tests/integration/test-system-status.php`, `tests/integration/test-uninstall.php`

**Interfaces:**
- Consumes: `dn_bfs_status_check()`, `dn_bfs_api_namespace()`, `dn_bfs_api_create_key()`, `dn_bfs_api_reveal_key_store()`.
- Produces: `dn_bfs_status_api(): array` (khóa `api`: `ok` khi loopback `GET /meta` trả `401 missing_key`; `warning` khi `403 https_required`; `error` khi route chưa đăng ký / lỗi kết nối / trả khác); thứ tự khóa của `dn_bfs_system_status()` thành `tables, schema, aggregation, cron, collect, api, tracker, geoip, geoip_public, proxy, versions`.

- [ ] **Step 1: Viết test fail** — trong `tests/integration/test-system-status.php`:

1. Thay toàn bộ `dn_bfs_it_mock_loopback()` bằng:

```php
function dn_bfs_it_mock_loopback( $collect_ok = true, $tracker_ok = true, $api = 'missing_key' ) {
	$GLOBALS['dn_bfs_it_loopback'] = function ( $pre, $args, $url ) use ( $collect_ok, $tracker_ok, $api ) {
		$ok = array( 'headers' => array(), 'cookies' => array(), 'filename' => null, 'response' => array( 'code' => 200, 'message' => 'OK' ) );

		if ( false !== strpos( $url, '/dnbfs/v1/collect' ) ) {
			return $collect_ok ? array_merge( $ok, array( 'body' => '{"ok":true}' ) ) : new WP_Error( 'http_request_failed', 'Connection refused' );
		}

		if ( false !== strpos( $url, '/dnbfs/v1/meta' ) ) {
			if ( 'blocked' === $api ) {
				return array_merge( $ok, array( 'body' => '<html>Forbidden</html>', 'response' => array( 'code' => 403, 'message' => 'Forbidden' ) ) );
			}

			return array_merge( $ok, array( 'body' => wp_json_encode( array( 'code' => $api, 'message' => 'x' ) ), 'response' => array( 'code' => 'https_required' === $api ? 403 : 401, 'message' => 'x' ) ) );
		}

		if ( false !== strpos( $url, 'GeoLite2-City.mmdb' ) ) {
			return array_merge( $ok, array( 'body' => '', 'response' => array( 'code' => 403, 'message' => 'Forbidden' ) ) );
		}

		if ( 0 === strpos( $url, home_url() ) ) {
			return array_merge( $ok, array( 'body' => $tracker_ok ? '<script>window.dnbfsPage={};</script>' : '<html></html>' ) );
		}

		return $pre;
	};

	add_filter( 'pre_http_request', $GLOBALS['dn_bfs_it_loopback'], 10, 3 );
}
```

2. Trong test `system status reports healthy checks when everything works`, đổi kỳ vọng khóa thành `array( 'tables', 'schema', 'aggregation', 'cron', 'collect', 'api', 'tracker', 'geoip', 'geoip_public', 'proxy', 'versions' )` và thêm `dn_bfs_assert_same( 'ok', $checks['api']['status'] );` sau assertion của `collect`.

3. Thêm vào cuối file:

```php
dn_bfs_it(
	'system status checks that the public API answers and asks for a key',
	function () {
		$real = rest_do_request( new WP_REST_Request( 'GET', '/dnbfs/v1/meta' ) );
		dn_bfs_assert_same( 401, $real->get_status(), 'the real route answers what the check expects' );
		dn_bfs_assert_same( 'missing_key', $real->get_data()['code'] );

		foreach ( array( 'missing_key' => 'ok', 'https_required' => 'warning', 'blocked' => 'error' ) as $api => $status ) {
			dn_bfs_it_mock_loopback( true, true, $api );
			$checks = dn_bfs_it_status_by_key( dn_bfs_system_status() );
			dn_bfs_it_unmock_loopback();

			dn_bfs_assert_same( $status, $checks['api']['status'], $api );
		}
	}
);
```

Trong `tests/integration/test-uninstall.php`, ngay trước `if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {` thêm:

```php
		$api_key = dn_bfs_api_create_key( array( 'name' => 'Uninstall', 'scopes' => array( 'stats:read' ) ) );
		dn_bfs_api_reveal_key_store( 1, $api_key['key'] );
		set_transient( 'dnbfs_api_c_test', array( 1 ), 60 );
		set_transient( 'dnbfs_api_rl_1_1', 1, 60 );
```

và ngay sau dòng `dn_bfs_assert_true( false === get_transient( 'dnbfs_r_test' ), 'transient' );` thêm:

```php
			dn_bfs_assert_true( false === get_transient( 'dnbfs_api_reveal_1' ), 'one-time key reveal' );
			dn_bfs_assert_true( false === get_transient( 'dnbfs_api_c_test' ), 'API response cache' );
			dn_bfs_assert_true( false === get_transient( 'dnbfs_api_rl_1_1' ), 'API rate counter' );
```

(Bảng `api_keys` đã được kiểm tra bị DROP trong vòng lặp `dn_bfs_schema_tables()` có sẵn.)

Run tích hợp → Expected: test healthy và test API mới FAIL (chưa có khóa `api`); test uninstall PASS ngay (mẫu `\_transient\_dnbfs\_%` và DROP `api_keys` đã có — test này khóa hành vi lại).

- [ ] **Step 2: Thêm kiểm tra** — trong `includes/admin/system-status.php`, thêm ngay sau `dn_bfs_status_collect()`:

```php
function dn_bfs_status_api() {
	$label  = __( 'Public REST API', 'dn-burst-funnel-stats' );
	$routes = rest_get_server()->get_routes( dn_bfs_api_namespace() );

	if ( ! isset( $routes[ '/' . dn_bfs_api_namespace() . '/meta' ] ) ) {
		return dn_bfs_status_check( 'api', $label, 'error', __( 'The public API routes are not registered.', 'dn-burst-funnel-stats' ) );
	}

	$response = wp_remote_get( rest_url( dn_bfs_api_namespace() . '/meta' ), array( 'timeout' => 3 ) );

	if ( is_wp_error( $response ) ) {
		return dn_bfs_status_check( 'api', $label, 'error', $response->get_error_message() );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( wp_remote_retrieve_body( $response ), true );
	$code   = is_array( $body ) && isset( $body['code'] ) ? (string) $body['code'] : '';

	if ( 401 === $status && 'missing_key' === $code ) {
		return dn_bfs_status_check( 'api', $label, 'ok', __( 'The API is reachable and asks for a key.', 'dn-burst-funnel-stats' ) );
	}

	if ( 403 === $status && 'https_required' === $code ) {
		return dn_bfs_status_check( 'api', $label, 'warning', __( 'The API only answers over HTTPS, but the site address uses HTTP.', 'dn-burst-funnel-stats' ) );
	}

	/* translators: %d: HTTP status code. */
	return dn_bfs_status_check( 'api', $label, 'error', sprintf( __( 'The API answered with HTTP %d. A security plugin or firewall may block the REST API.', 'dn-burst-funnel-stats' ), $status ) );
}
```

Trong `dn_bfs_system_status()`, thêm `dn_bfs_status_api(),` ngay sau `dn_bfs_status_collect(),`.

- [ ] **Step 3: Chạy test** — tích hợp PASS (thêm 1 test; các test system-status/settings-page cũ PASS); unit + `php74` → OK. Settings → System hiển thị dòng "Public REST API" `ok`.

- [ ] **Step 4: Commit**

```bash
git add includes/admin/system-status.php tests/integration/test-system-status.php tests/integration/test-uninstall.php
git commit -m "feat(admin): check that the public REST API answers; cover API data in uninstall

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: README (tài liệu API + ví dụ NestJS) và spec

**Files:**
- Modify: `README.MD`, `docs/superpowers/specs/2026-10-03-native-tracking-design.md` (§8.4, §9, §11)

**Interfaces:**
- Consumes: hợp đồng đã cài ở Task 1–6 (tên endpoint, tham số, mã lỗi, header).
- Produces: tài liệu khớp code; không đổi hành vi.

- [ ] **Step 1: README** — trong `README.MD`:

1. Dưới `= Settings =`, đổi câu thành: `Funnel Stats → Settings has General, Tracking, Anti-spam, WooCommerce, GeoIP, Data, System and API tabs.`
2. Thêm khối sau ngay trước `== Requirements ==`:

````text
= Public REST API =

Read-only statistics for your own servers, for example a NestJS backend. Create a key in Funnel Stats → Settings → API. The full key is shown once; only a hash is stored.

* Base URL: `https://your-store.example/wp-json/dnbfs/v1`
* Authentication: `Authorization: Bearer dnbfs_xxxxxxxx_…` or `X-DNBFS-Key: dnbfs_xxxxxxxx_…` (use the second header if your web server strips `Authorization`).
* HTTPS only. Sites on `localhost`, `127.0.0.1`, `*.test` or `*.localhost` are exempt for development.
* No CORS headers are sent: call the API from a server, never from a browser.
* Each key has scopes (`stats:read`, `realtime:read`), optional allowed IPs (one IP or CIDR range per line) and a rate limit (default 60 requests per minute). Over the limit the API answers 429 with a `Retry-After` header.
* Successful statistics are cached for 60 seconds. Realtime is never cached.

Endpoints (all GET):

* `/meta` – plugin, key and API limits (any key)
* `/stats/summary?start=2026-09-01&end=2026-09-30&compare=previous_period` – totals; `compare` is `none` (default), `previous_period` or `previous_year` (`stats:read`)
* `/stats/timeseries?start=…&end=…&metrics=sessions,orders,revenue` – one value per day (`stats:read`)
* `/stats/breakdown?start=…&end=…&dimension=country&orderby=revenue&order=desc&limit=25&page=1` – table rows; `limit` is at most 500 (`stats:read`)
* `/stats/funnel?start=…&end=…` – visitors → product views → add to cart → cart → checkout → orders (`stats:read`)
* `/stats/realtime` – visitors online in the last 5 minutes (`realtime:read`)
* `/openapi.json` – OpenAPI 3.0 description of all of the above (any key)

Dates are `YYYY-MM-DD` in the store timezone, and a range covers at most 366 days. Add `filter[channel]=paid` (also `source`, `medium`, `campaign`, `device`, `country`) to any statistics endpoint except realtime. Two or more filters only work for dates that still have raw data.

Responses look like this:

    {
      "data": { "current": { "visitors": 120, "orders": 4, "revenue": 1530000 }, "previous": null, "change": {}, "previous_range": null },
      "meta": { "timezone": "Asia/Ho_Chi_Minh", "currency": "VND", "range": { "start": "2026-09-01", "end": "2026-09-30" }, "estimated": false }
    }

`estimated: true` means visitor counts were summed day by day because raw data for the whole range is no longer kept. Errors look like `{"code": "invalid_date", "message": "…"}` with status 401 (missing, invalid or revoked key), 403 (missing scope, IP not allowed or HTTPS required), 422 (invalid parameters) or 429 (rate limited).

NestJS example (`@nestjs/axios`, `@nestjs/config`):

    import { Injectable } from '@nestjs/common';
    import { HttpService } from '@nestjs/axios';
    import { ConfigService } from '@nestjs/config';
    import { firstValueFrom } from 'rxjs';

    @Injectable()
    export class FunnelStatsService {
      constructor(
        private readonly http: HttpService,
        private readonly config: ConfigService,
      ) {}

      async summary(start: string, end: string) {
        const { data } = await firstValueFrom(
          this.http.get(`${this.config.get('DNBFS_URL')}/wp-json/dnbfs/v1/stats/summary`, {
            params: { start, end, compare: 'previous_period', 'filter[channel]': 'paid' },
            headers: { Authorization: `Bearer ${this.config.get('DNBFS_API_KEY')}` },
            timeout: 10000,
          }),
        );

        // data.data.current.revenue, data.meta.currency, data.meta.estimated …
        return data;
      }
    }

On a 429 response, wait for the number of seconds in the `Retry-After` header before retrying.
````

3. Trong `== Data and performance ==`, đổi câu cuối thành: `Settings → System shows the health of tracking, aggregation, GeoIP and the public REST API.`

- [ ] **Step 2: Spec §8.4** — đổi tiêu đề `### 8.4 Settings (7 tab)` thành `### 8.4 Settings (8 tab)`; trong đoạn dưới: `Chung, Tracking, Chống spam, WooCommerce, GeoIP, Dữ liệu, Hệ thống —` → `Chung, Tracking, Chống spam, WooCommerce, GeoIP, Dữ liệu, Hệ thống, API —`; `endpoint \`/collect\`, tracker,` → `endpoint \`/collect\`, REST API công khai, tracker,`; câu cuối `Quản lý API key thuộc Kế hoạch 4.` → `Tab API: danh sách key, tạo key (tên, scope, IP được phép, giới hạn tần suất; key đầy đủ hiện một lần), thu hồi (mục 9).`

- [ ] **Step 3: Spec §9** — thay toàn bộ mục `## 9. REST API công khai` (tới trước `## 10.`) bằng:

```markdown
## 9. REST API công khai

Namespace `/wp-json/dnbfs/v1/`, chỉ GET, dành cho gọi server-to-server (ví dụ NestJS). Code: `includes/api/keys.php` (lưu key), `includes/api/auth.php` (xác thực), `includes/api/routes.php` (route, tham số, envelope, cache), `includes/api/openapi.php`; quản lý key ở Settings → API (`includes/admin/api-keys-page.php`).

- Xác thực: `Authorization: Bearer <key>` hoặc `X-DNBFS-Key: <key>`. Key dạng `dnbfs_<prefix8>_<secret32>` (prefix `[a-z0-9]`, secret `[A-Za-z0-9]`); chỉ hiện một lần sau khi tạo (transient 5 phút theo user, không bao giờ nằm trên URL); lưu `prefix` + `hash_hmac('sha256', key, wp_salt('auth'))`; so sánh bằng `hash_equals`. Key bị thu hồi → `401`.
- Mỗi key: tên, scope (`stats:read`, `realtime:read`), IP/CIDR được phép (mỗi dòng một rule; rỗng = mọi IP; IP client theo cài đặt "Client IP source"), giới hạn 1–1000 request/phút (mặc định 60).
- Bắt buộc HTTPS trừ khi host của `home_url()` là `localhost`, `127.0.0.1`, `::1`, `*.test`, `*.localhost` (filter `dn_bfs_api_require_https`); vi phạm → `403 https_required`. Không gửi header CORS (gỡ `rest_send_cors_headers` và mọi header `Access-Control-*` cho các route này).
- Giới hạn tần suất theo key, cửa sổ cố định 1 phút → `429 rate_limited` + `Retry-After` (số giây tới phút kế tiếp). Request bị 401/403 không bị tính.
- Kết quả thành công của `/stats/summary|timeseries|breakdown|funnel` cache 60 giây (filter `dn_bfs_api_cache_ttl`) theo (endpoint, tham số đã chuẩn hóa); `/stats/realtime`, `/meta`, `/openapi.json` không cache. Xóa toàn bộ dữ liệu (Settings → Dữ liệu) xóa luôn cache này.
- Mỗi request đã xác thực cập nhật `last_used_at` (tối đa 1 lần/phút).

| Endpoint | Quyền |
|---|---|
| `GET /meta` | key hợp lệ bất kỳ |
| `GET /stats/summary?start&end&compare=none\|previous_period\|previous_year` | `stats:read` |
| `GET /stats/timeseries?start&end&metrics=a,b` | `stats:read` |
| `GET /stats/breakdown?dimension&start&end&orderby&order&limit(≤500)&page` | `stats:read` |
| `GET /stats/funnel?start&end` | `stats:read` |
| `GET /stats/realtime` | `realtime:read` |
| `GET /openapi.json` | key hợp lệ bất kỳ |

- `start`, `end` bắt buộc với `/stats/*` trừ realtime: `YYYY-MM-DD` theo múi giờ site; khoảng tối đa 366 ngày (tính cả hai đầu; dùng chung `dn_bfs_parse_range( $params, 366 )` với admin, admin 731).
- `compare` chỉ áp dụng cho summary, mặc định `none`. `filter[dimension]=value` trên mọi `/stats/*` trừ realtime (mục 8.3). Timeseries: `metrics` mặc định `sessions,orders,revenue`. Breakdown: `dimension` bắt buộc, `orderby` là tên chỉ số, `order=asc|desc` (mặc định `desc`), `limit` 1–500 (mặc định 25), `page` ≥ 1.
- Phản hồi: `{ "data": …, "meta": { "timezone", "currency", "range": {start,end} | null, "estimated": bool } }` (`range = null` cho `/meta` và `/stats/realtime`). `/openapi.json` trả thẳng tài liệu OpenAPI 3.0.3, không bọc envelope.
- `data`: summary `{current, previous, change, previous_range}`; timeseries `{labels, series}`; breakdown `{dimension, rows, total, page, limit, pages}`; funnel `{steps: [{key, value}]}`; realtime `{online, pages, channels}`; meta `{api_version, plugin_version, site_url, key {name, prefix, scopes, rate_limit}, metrics, dimensions, filters, max_range_days, last_aggregated_date, raw_available_from}`.
- Lỗi: `401 missing_key|invalid_key`, `403 insufficient_scope|ip_not_allowed|https_required`, `422` tham số sai (`invalid_date`, `range_too_long`, `invalid_compare`, `invalid_filter`, `invalid_metric`, `invalid_dimension`, `invalid_orderby`, `invalid_order`, `invalid_limit`, `invalid_page`, `filter_out_of_retention`), `429 rate_limited`. Body lỗi: `{ "code", "message" }`.
- Tab Hệ thống kiểm tra `GET /meta` qua loopback trả `401 missing_key`.
- README có ví dụ NestJS (`HttpService`, `Authorization: Bearer`).
```

- [ ] **Step 4: Spec §11** — trong cây file: `admin/settings-page.php  (màn Settings: 7 tab, form admin-post, notice)` → `(màn Settings: 8 tab, form admin-post, notice)`; thay 3 dòng `api/auth.php …`, `api/routes.php …`, `api/openapi.php …` bằng:

```text
  admin/api-keys-page.php  (tab API: tạo / thu hồi key, hiện key một lần)
  api/keys.php             (tạo, băm, tra cứu, thu hồi API key)
  api/auth.php             (header key, HTTPS, IP được phép, scope, giới hạn tần suất, last_used)
  api/routes.php           (REST công khai: route, tham số, envelope, cache, không CORS)
  api/openapi.php          (tài liệu OpenAPI 3.0)
```

- [ ] **Step 5: Kiểm tra** — `grep -n "Kế hoạch 4" docs/superpowers/specs/2026-10-03-native-tracking-design.md` → không còn dòng nào nói API "thuộc Kế hoạch 4" như việc chưa làm; `ls includes/api includes/admin` khớp cây §11; `grep -c "dnbfs/v1" README.MD` ≥ 2.

- [ ] **Step 6: Commit**

```bash
git add README.MD docs/superpowers/specs/2026-10-03-native-tracking-design.md
git commit -m "docs: document the public REST API with a NestJS example and align the spec

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Hoàn thiện còn lại từ review Kế hoạch 3 (R1, R2, R3, R5)

**Files:**
- Modify: `includes/admin/settings-page.php` (`dn_bfs_wc_revenue_rules`, nhánh `import` của `dn_bfs_settings_data_task`), `includes/admin/menu.php` (`dn_bfs_admin_script_data`), `assets/admin.js` (`formatValue`), `tests/integration/test-settings-page.php`, `tests/integration/test-admin-menu.php`, `tests/integration/test-dashboard-data.php`

**Interfaces:**
- Consumes: `dn_bfs_get_wc_report_settings()`, `dn_bfs_import_settings()`, `dn_bfs_export_settings()`, `dn_bfs_settings_save_from_post()`, `dn_bfs_it_group_values()` (định nghĩa trong `test-settings-model.php`, nạp trước `test-settings-page.php`), `dn_bfs_report_summary()`, `dn_bfs_raw_get_order()`.
- Produces:
  - R1: `dn_bfs_wc_revenue_rules()` trả mỗi danh sách đã `sort()` → đổi thứ tự không bị coi là đổi quy tắc.
  - R2: `dn_bfs_settings_import_notice( $payload ): string` (`imported` | `imported_wc_rules` | mã lỗi), nhánh import gọi hàm này.
  - R3: `dnBfsAdmin.currency.thousand`, `dnBfsAdmin.currency.decimal`; JS `formatNumber( value, minPlaces, maxPlaces, thousand, decimal )`.
  - R5: test tip gộp khi hoàn tiền chính dòng phí tip (không đổi code).

- [ ] **Step 1: Viết test fail** — thêm vào cuối `tests/integration/test-settings-page.php`:

```php
dn_bfs_it(
	'reordering the same WooCommerce statuses or tip keywords is not a rule change',
	function () {
		delete_option( 'dn_burst_funnel_stats_wc_report_settings' );
		$post = array( 'dn_bfs' => dn_bfs_it_group_values( 'woocommerce' ) );

		$post['dn_bfs']['tip_keywords'] = implode( "\n", $post['dn_bfs']['tip_keywords'] );
		dn_bfs_settings_save_from_post( 'woocommerce', $post );

		$post['dn_bfs']['sales_excluded_statuses'] = array_reverse( $post['dn_bfs']['sales_excluded_statuses'] );
		$post['dn_bfs']['tip_keywords']            = implode( "\n", array_reverse( explode( "\n", $post['dn_bfs']['tip_keywords'] ) ) );
		dn_bfs_assert_same( 'saved', dn_bfs_settings_save_from_post( 'woocommerce', $post ), 'same rules, other order' );

		delete_option( 'dn_burst_funnel_stats_wc_report_settings' );
	}
);

dn_bfs_it(
	'importing changed WooCommerce revenue rules yields the re-aggregate notice',
	function () {
		delete_option( 'dn_burst_funnel_stats_wc_report_settings' );
		$export = dn_bfs_export_settings();

		dn_bfs_assert_same( 'imported', dn_bfs_settings_import_notice( $export ), 'unchanged' );

		$reordered = $export;
		$reordered['settings']['woocommerce_report']['paid_statuses'] = array_reverse( $export['settings']['woocommerce_report']['paid_statuses'] );
		dn_bfs_assert_same( 'imported', dn_bfs_settings_import_notice( $reordered ), 'same rules, other order' );

		$changed = $export;
		$changed['settings']['woocommerce_report']['paid_statuses'] = array( 'wc-completed' );
		dn_bfs_assert_same( 'imported_wc_rules', dn_bfs_settings_import_notice( $changed ), 'changed' );

		dn_bfs_assert_same( 'invalid_import', dn_bfs_settings_import_notice( array( 'meta' => array( 'plugin' => 'x' ) ) ) );
		dn_bfs_assert_same( 'warning', dn_bfs_settings_notice( 'imported_wc_rules' )[0] );

		delete_option( 'dn_burst_funnel_stats_wc_report_settings' );
	}
);
```

Thêm vào cuối `tests/integration/test-admin-menu.php`:

```php
dn_bfs_it(
	'chart money uses the WooCommerce thousand and decimal separators',
	function () {
		$thousand = get_option( 'woocommerce_price_thousand_sep' );
		$decimal  = get_option( 'woocommerce_price_decimal_sep' );

		update_option( 'woocommerce_price_thousand_sep', '.' );
		update_option( 'woocommerce_price_decimal_sep', ',' );
		$_GET     = array( 'page' => 'dn-burst-funnel-stats' );
		$currency = dn_bfs_admin_script_data()['currency'];

		$_GET = array();
		update_option( 'woocommerce_price_thousand_sep', $thousand );
		update_option( 'woocommerce_price_decimal_sep', $decimal );

		dn_bfs_assert_same( '.', isset( $currency['thousand'] ) ? $currency['thousand'] : null );
		dn_bfs_assert_same( ',', isset( $currency['decimal'] ) ? $currency['decimal'] : null );
	}
);
```

Thêm vào cuối `tests/integration/test-dashboard-data.php`:

```php
dn_bfs_it_today(
	'refunding the tip fee line keeps tips gross and lowers sales',
	function () {
		dn_bfs_raw_get_order( 0, true );

		$now     = dn_bfs_it_now();
		$product = (int) wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' ) )[0];
		$price   = (float) wc_get_product( $product )->get_price();
		$session = dn_bfs_it_seed_session( array( 'started_at' => $now - 120 ) );

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product ), 1 );
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Tip' );
		$fee->set_total( 3.0 );
		$order->add_item( $fee );
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->save();

		$fee_id = (int) array_keys( $order->get_items( 'fee' ) )[0];
		dn_bfs_it_seed_event( $session, 'order', $now - 60, array( 'order_id' => $order->get_id() ) );
		wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => 3.0,
				'line_items' => array(
					$fee_id => array(
						'qty'          => 0,
						'refund_total' => 3.0,
						'refund_tax'   => array(),
					),
				),
			)
		);
		dn_bfs_raw_get_order( 0, true );

		$summary = dn_bfs_report_summary( dn_bfs_calculate_date_range( 'today', 'none' ) );

		dn_bfs_assert_same( 3.0, (float) wc_get_order( $order->get_id() )->get_total_refunded(), 'the refund hit the order' );
		dn_bfs_assert_same( 3.0, (float) $summary['current']['tips'], 'tip stays gross after its own fee line is refunded' );
		dn_bfs_assert_same( round( $price, 2 ), round( (float) $summary['current']['revenue'], 2 ), 'sales are net of the refunded tip' );
	}
);
```

Run tích hợp → Expected: 2 test settings-page FAIL (R1 trả `saved_wc_rules`; R2 `dn_bfs_settings_import_notice()` chưa có), test admin-menu FAIL (`expected '.', got NULL`); test tip (R5) PASS ngay — chỉ khóa hợp đồng "tip gross" (review R5), không cần đổi code.

- [ ] **Step 2: R1 — so sánh không phụ thuộc thứ tự** — trong `includes/admin/settings-page.php`, thay toàn bộ `dn_bfs_wc_revenue_rules()` bằng:

```php
function dn_bfs_wc_revenue_rules() {
	$settings = dn_bfs_get_wc_report_settings();
	$rules    = array();

	// Sorted so that the same statuses or keywords in another order are not a rule change.
	foreach ( array( 'sales_excluded_statuses', 'paid_statuses', 'balance_statuses', 'tip_keywords' ) as $key ) {
		$list = array_values( (array) $settings[ $key ] );
		sort( $list );
		$rules[ $key ] = $list;
	}

	return $rules;
}
```

- [ ] **Step 3: R2 — tách thông báo import thành hàm test được** — thêm ngay trên `dn_bfs_settings_data_task()`:

```php
function dn_bfs_settings_import_notice( $payload ) {
	$before = dn_bfs_wc_revenue_rules();
	$result = dn_bfs_import_settings( $payload );

	if ( is_wp_error( $result ) ) {
		return $result->get_error_code();
	}

	return dn_bfs_wc_revenue_rules() !== $before ? 'imported_wc_rules' : 'imported';
}
```

và trong nhánh `case 'import':` của `dn_bfs_settings_data_task()`, thay khối:

```php
			$before = dn_bfs_wc_revenue_rules();
			$result = dn_bfs_import_settings( json_decode( (string) file_get_contents( $files['import_file']['tmp_name'] ), true ) );

			if ( is_wp_error( $result ) ) {
				return array( 'tab' => 'data', 'notice' => $result->get_error_code() );
			}

			return array( 'tab' => 'data', 'notice' => dn_bfs_wc_revenue_rules() !== $before ? 'imported_wc_rules' : 'imported' );
```

bằng:

```php
			return array( 'tab' => 'data', 'notice' => dn_bfs_settings_import_notice( json_decode( (string) file_get_contents( $files['import_file']['tmp_name'] ), true ) ) );
```

- [ ] **Step 4: R3 — truyền dấu phân cách** — trong `dn_bfs_admin_script_data()` (`includes/admin/menu.php`), thêm vào mảng `'currency'` sau `'decimals' => …,`:

```php
			'thousand' => function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',',
			'decimal'  => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
```

Trong `assets/admin.js`, thêm hàm ngay trước `function formatValue(value, format) {`:

```js
	function formatNumber(value, minPlaces, maxPlaces, thousand, decimal) {
		var parts = Number(value).toFixed(maxPlaces).split('.');
		var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousand);
		var fraction = parts.length > 1 ? parts[1] : '';

		while (fraction.length > minPlaces && fraction.charAt(fraction.length - 1) === '0') {
			fraction = fraction.slice(0, -1);
		}

		return fraction ? whole + decimal + fraction : whole;
	}
```

và trong nhánh `if (format === 'money') {` của `formatValue`, thay:

```js
			var text = Math.abs(number).toLocaleString(undefined, {
				minimumFractionDigits: abs > 0 && abs < 10 ? places : 0,
				maximumFractionDigits: places
			});
```

bằng:

```js
			var text = formatNumber(
				abs,
				abs > 0 && abs < 10 ? places : 0,
				places,
				currency.thousand == null ? ',' : String(currency.thousand),
				currency.decimal == null ? '.' : String(currency.decimal)
			);
```

(Giữ nguyên hành vi cũ: số < 10 hiện đủ `places` chữ số thập phân, số ≥ 10 bỏ số 0 thừa; chỉ đổi dấu phân cách theo WooCommerce.)

- [ ] **Step 5: Chạy test** — tích hợp PASS (thêm 4 test); unit + `php74` → OK; `docker run --rm -v "$PWD":/app -w /app node:20 node --check assets/admin.js` → không lỗi; `grep -n "toLocaleString" assets/admin.js` chỉ còn ở nhánh `percent` và số nguyên. Kiểm tra thủ công: WooCommerce → Settings → General đặt thousand `.` decimal `,`, mở Funnel Stats → Dashboard, rê chuột biểu đồ Sales → tooltip dạng `1.234,5 ₫` khớp thẻ.

- [ ] **Step 6: Commit**

```bash
git add includes/admin/settings-page.php includes/admin/menu.php assets/admin.js tests/integration/test-settings-page.php tests/integration/test-admin-menu.php tests/integration/test-dashboard-data.php
git commit -m "fix(admin): order-insensitive WooCommerce rule check, testable import notice, WooCommerce separators in chart money, tip-refund test

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Tự kiểm tra kế hoạch so với spec (mục 9, §3 `dnbfs_api_keys`)

| Yêu cầu | Task |
|---|---|
| §9 Namespace `/wp-json/dnbfs/v1/`, chỉ GET, 7 endpoint + scope | 3, 4 |
| §9 `Authorization: Bearer` / `X-DNBFS-Key` | 2, 3 |
| §9 Key `dnbfs_<prefix8>_<secret32>`, hiện một lần, lưu prefix + `hash_hmac(…, wp_salt('auth'))`, `hash_equals` | 1, 5 |
| §3 Cột `name, prefix, key_hash, scopes (CSV), allowed_ips (mỗi dòng), rate_limit, last_used_at, created_at, revoked_at` | 1 |
| Key thu hồi → 401 | 2, 3 |
| IP được phép (CIDR mỗi dòng) → 403 | 2, 3 |
| HTTPS trừ localhost / 127.0.0.1 / ::1 / `*.test` / `*.localhost` (có filter) | 2 |
| Không gửi CORS | 3 |
| Giới hạn tần suất theo key (mặc định 60/phút) → 429 + `Retry-After` | 2, 3 |
| Cache 60 giây theo (endpoint, tham số) | 3 |
| `last_used_at` tối đa 1 lần/phút | 2 |
| Ngày `YYYY-MM-DD` theo múi giờ site; tối đa 366 ngày (mở rộng `dn_bfs_parse_range`) | 1, 3 |
| `compare`, `metrics`, `dimension/orderby/order/limit ≤ 500/page`, `filter[dimension]` (§8.3) | 3 |
| Envelope `{data, meta: {timezone, currency, range, estimated}}` | 3 |
| Lỗi 401 / 403 / 422 (400 → 422) / 429, body `{code, message}`; `filter_out_of_retention` 422 | 3 |
| `/openapi.json` | 4 |
| Admin: tab API (danh sách, tạo, hiện một lần qua transient theo user, thu hồi có xác nhận, hướng dẫn base URL + curl), admin-post + quyền + nonce | 5 |
| Trạng thái hệ thống: REST công khai đăng ký và trả lời | 6 |
| Gỡ cài đặt: bảng `api_keys`, transient `dnbfs_api_*` | 6 |
| README: tài liệu API + ví dụ NestJS (`HttpService`, `Authorization: Bearer`) | 7 |
| Export settings không gồm API key (§11) | đã đúng — `dn_bfs_export_settings()` không đọc bảng key |
| Review Kế hoạch 3: R1, R2, R3, R5 | 8 |

Ngoài phạm vi: R4 (cache báo cáo còn sống tới 60 giây sau khi xóa dữ liệu nếu dùng object cache bền — review chấp nhận; cache API mới có cùng giới hạn này); tăng phiên bản plugin (để chủ sản phẩm quyết định khi phát hành).

**Quyết định cho các điểm spec chưa nói rõ:**
- Ngoại lệ HTTPS dựa trên host của `home_url()`, không dựa trên header `Host` của request (header này giả mạo được). Vi phạm HTTPS trả `403 https_required`.
- `start`/`end` bắt buộc (không có mặc định) cho `/stats/*` trừ realtime; `compare` chỉ dùng cho summary, các endpoint khác luôn `none`.
- `/stats/realtime`, `/meta`, `/openapi.json` không cache (realtime theo §7 "realtime không cache"); chỉ 4 endpoint stats cache 60 giây. Request bị 401/403 không tính vào giới hạn tần suất; request lỗi 422 và request trả từ cache vẫn tính.
- `/openapi.json` trả thẳng tài liệu OpenAPI (không bọc envelope) để công cụ sinh client đọc được; vẫn cần key hợp lệ.
- `meta.range = null` cho `/meta` và `/stats/realtime`; summary thêm `data.previous_range` khi có so sánh; breakdown thêm `pages`.
- Key không hợp lệ và key đã thu hồi cùng trả `invalid_key` (không tiết lộ key nào từng tồn tại); thêm header `WWW-Authenticate: Bearer` cho 401 và `Cache-Control: no-store` cho mọi phản hồi API.
- Key đầy đủ nằm trong transient `dnbfs_api_reveal_<user_id>` tối đa 5 phút và bị xóa ngay khi trang tab API hiển thị nó.
- Giới hạn tần suất 1–1000/phút (cột `SMALLINT`); `orderby`/`order`/`limit`/`page` sai trả 422 (`invalid_orderby`, `invalid_order`, `invalid_limit`, `invalid_page`) thay vì lặng lẽ dùng mặc định như admin.
