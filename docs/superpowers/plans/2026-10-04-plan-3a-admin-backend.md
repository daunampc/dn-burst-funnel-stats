# Kế hoạch 3A — Backend cho trang admin (REST nội bộ, Settings, trạng thái hệ thống, widget, gỡ dashboard cũ)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cung cấp toàn bộ phần server mà giao diện React (Kế hoạch 3B, 3C) cần: REST nội bộ `dnbfs/v1/admin/*`, mô hình Settings 6 nhóm, kiểm tra trạng thái hệ thống, widget Dashboard WordPress, khung build React; đồng thời gỡ hẳn dashboard cũ dựa trên Burst.

**Architecture:** Các route REST là lớp mỏng: kiểm tra quyền, kiểm tra tham số (trả `400` cho tham số sai), gọi `includes/reports.php` / mô hình settings / aggregator, và bọc kết quả trong envelope `{ data, meta }` dùng chung cho Kế hoạch 4. Trang admin chỉ in `<div id="dnbfs-app">` và nạp bundle `build/index.js` (build bằng `@wordpress/scripts` trong Docker, commit vào repo).

**Tech Stack:** PHP 7.4+, WordPress 6.5+ REST API, WooCommerce, `@wordpress/scripts` (Node 20 trong Docker), React 18 qua `@wordpress/element`, Jest.

**Spec:** `docs/superpowers/specs/2026-10-03-native-tracking-design.md` (mục 8.1, 8.4, 8.5, 8.6, 10, 12, 13).

**Phân chia Kế hoạch 3:** 3A (kế hoạch này) — backend; 3B — Dashboard React; 3C — Settings React + quản lý API key + phát hành 3.0.0. Kế hoạch 4 — REST công khai bằng API key.

## Global Constraints

- PHP tối thiểu 7.4 (không `match`, union type, named args, `str_contains`, enum, readonly, nullsafe; không thêm return type). Hàm mới tiền tố `dn_bfs_`. Mọi SQL có biến qua `$wpdb->prepare`.
- Phong cách: tab + khoảng trắng kiểu WordPress trong `includes/`; `dn-burst-funnel-stats.php` 2 space, không khoảng trắng trong ngoặc. JS theo chuẩn `@wordpress/scripts` (tab, khoảng trắng trong ngoặc).
- Namespace REST: `dnbfs/v1`, route nội bộ dưới `/admin/...`. Quyền: `current_user_can( dn_bfs_admin_capability() )` (filter `dn_bfs_capability`, mặc định `manage_options`).
- Envelope mọi phản hồi thành công: `{ "data": …, "meta": { "range": {…} | null, "currency": "USD", "timezone": "Asia/Ho_Chi_Minh", "estimated": bool } }`.
- Lỗi tham số → `WP_Error` status `400` với code: `invalid_period`, `invalid_compare`, `invalid_date`, `range_too_long` (khoảng custom > 731 ngày), `invalid_filter`, `invalid_metric`, `invalid_dimension`, `invalid_orderby`, `invalid_card`, `invalid_group` (404), `missing_keys`, `unknown_keys`, `invalid_settings`, `confirm_required`, `invalid_import`, `no_license`. Lỗi từ `reports.php` (`filter_out_of_retention` 422) được chuyển nguyên.
- Settings: POST một nhóm phải gửi **đủ mọi khóa** của nhóm (kể cả `0`/`[]`); thiếu → `missing_keys`, thừa → `unknown_keys`. License MaxMind không bao giờ trả về nguyên văn: GET trả `********` khi đã có; POST giá trị `********` = giữ nguyên; đổi key → xóa `dnbfs_geoip_attempted_at`.
- Mặc định loại khỏi doanh thu: `wc-cancelled`, `wc-failed`, `wc-checkout-draft`, `wc-refunded`.
- "Còn dữ liệu thô" luôn theo `dn_bfs_raw_available_from( $now )`.
- Commit message kết thúc bằng `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Không commit `.superpowers/`, `node_modules/`.
- Lệnh (từ gốc repo):
  - Unit PHP: `docker compose -f docker/docker-compose.yml run --rm phpunit`
  - Tích hợp: `docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php`
  - Lint 7.4: `docker compose -f docker/docker-compose.yml run --rm php74`
  - JS (từ Task 1): `docker compose -f docker/docker-compose.yml run --rm node build` / `… run --rm node test`

## Cấu trúc file

| File | Trách nhiệm |
|---|---|
| `docker/docker-compose.yml` (sửa), `package.json`, `package-lock.json` | Service `node`, build + test JS |
| `src/admin/index.js`, `src/admin/App.js`, `src/admin/utils/format.js`, `src/admin/utils/__tests__/format.test.js`, `build/*` | Khung React tối thiểu (3B/3C lấp đầy) |
| `includes/tracking.php` (sửa) | `default_date_range` nhận các preset |
| `includes/admin/settings-model.php` | 6 nhóm settings: đọc, ghi, che license, meta |
| `includes/admin/rest-params.php` | Quyền, envelope, phân tích + kiểm tra tham số REST |
| `includes/admin/rest-reports.php` | Route báo cáo: summary, timeseries, funnel, breakdown, realtime, filter values |
| `includes/admin/rest-settings.php` | Route preferences, settings, data (stats/reaggregate/purge/export/import), geoip |
| `includes/admin/system-status.php` | Các kiểm tra trạng thái + route |
| `includes/tracking/collector.php` (sửa) | Header `X-DNBFS-Check` cho kiểm tra loopback |
| `includes/admin/pages.php` | Menu, trang mount, nạp bundle + cấu hình |
| `includes/admin/dashboard-widget.php`, `assets/dashboard-widget.js` | Widget Dashboard WordPress |
| `dn-burst-funnel-stats.php` (sửa) | `dn_burst_funnel_stats_load_admin()`, migration schema 6 |
| Xóa | `includes/dashboard.php`, `includes/ajax.php`, `includes/admin-menu.php`, `includes/import-export.php`, `includes/settings.php`, `assets/admin.js`, `assets/admin.css` |
| `tests/integration/rest.php` | Helper gọi REST trong test |

---

### Task 1: Khung build React trong Docker

**Files:**
- Modify: `docker/docker-compose.yml`, `.gitignore`
- Create: `package.json`, `package-lock.json` (sinh tự động), `src/admin/index.js`, `src/admin/App.js`, `src/admin/utils/format.js`, `src/admin/utils/__tests__/format.test.js`, `build/index.js`, `build/index.asset.php` (sinh tự động)

**Interfaces:**
- Produces: service `node` (`docker compose … run --rm node build|test`); bundle `build/index.js` + `build/index.asset.php` (dependencies + version) mount vào `#dnbfs-app` với `data-page` (`dashboard`|`settings`) và đọc `window.dnbfsAdmin`; `src/admin/utils/format.js` export `formatNumber( value, locale )`, `formatPercent( value, digits = 1 )`, `formatDuration( seconds )`, `formatMoney( value, currency, locale )`.

- [ ] **Step 1: Thêm service `node` vào `docker/docker-compose.yml`** (sau service `php74`):

```yaml
  node:
    image: node:20
    profiles: ["tools"]
    working_dir: /app
    volumes:
      - ../:/app
    entrypoint: ["sh", "-c", "npm ci --no-audit --no-fund --loglevel=error && npm run \"$@\"", "--"]
```

- [ ] **Step 2: Tạo `package.json`**

```json
{
  "name": "dn-burst-funnel-stats",
  "private": true,
  "description": "Admin UI for DN Burst Funnel Stats.",
  "license": "GPL-2.0-or-later",
  "scripts": {
    "build": "wp-scripts build src/admin/index.js --output-path=build",
    "start": "wp-scripts start src/admin/index.js --output-path=build",
    "test": "wp-scripts test-unit-js --passWithNoTests"
  }
}
```

- [ ] **Step 3: Cài phụ thuộc (sinh `package-lock.json`)**

```bash
docker compose -f docker/docker-compose.yml run --rm --entrypoint sh node -c "npm install --save-dev --no-audit --no-fund @wordpress/scripts && npm install --no-audit --no-fund @wordpress/element @wordpress/components @wordpress/api-fetch @wordpress/i18n @wordpress/url"
```

Expected: `package.json` có `devDependencies.@wordpress/scripts` và `dependencies` 5 gói; có `package-lock.json`.

- [ ] **Step 4: `.gitignore`** — đảm bảo có dòng `/node_modules/` (đã có từ Kế hoạch 1; giữ nguyên) và **không** ignore `build/`.

- [ ] **Step 5: Viết test fail — `src/admin/utils/__tests__/format.test.js`**

```js
import { formatNumber, formatPercent, formatDuration, formatMoney } from '../format';

describe( 'format helpers', () => {
	it( 'formats integers with grouping', () => {
		expect( formatNumber( 1234567, 'en-US' ) ).toBe( '1,234,567' );
		expect( formatNumber( 'x', 'en-US' ) ).toBe( '0' );
	} );

	it( 'formats percentages with fixed digits', () => {
		expect( formatPercent( 12.345 ) ).toBe( '12.3%' );
		expect( formatPercent( 5, 0 ) ).toBe( '5%' );
	} );

	it( 'formats durations as minutes and seconds', () => {
		expect( formatDuration( 125 ) ).toBe( '2m 5s' );
		expect( formatDuration( 5 ) ).toBe( '5s' );
		expect( formatDuration( -3 ) ).toBe( '0s' );
	} );

	it( 'formats money and falls back for unknown currencies', () => {
		expect( formatMoney( 12.5, 'USD', 'en-US' ) ).toBe( '$12.50' );
		expect( formatMoney( 1, 'NOT-A-CODE', 'en-US' ) ).toBe( '1.00 NOT-A-CODE' );
	} );
} );
```

Run: `docker compose -f docker/docker-compose.yml run --rm node test`
Expected: FAIL (`Cannot find module '../format'`).

- [ ] **Step 6: Tạo `src/admin/utils/format.js`**

```js
export function formatNumber( value, locale ) {
	return new Intl.NumberFormat( locale || undefined ).format( Number( value ) || 0 );
}

export function formatPercent( value, digits = 1 ) {
	return `${ ( Number( value ) || 0 ).toFixed( digits ) }%`;
}

export function formatDuration( seconds ) {
	const total = Math.max( 0, Math.round( Number( seconds ) || 0 ) );
	const minutes = Math.floor( total / 60 );
	const rest = total % 60;

	return minutes > 0 ? `${ minutes }m ${ rest }s` : `${ rest }s`;
}

export function formatMoney( value, currency, locale ) {
	const amount = Number( value ) || 0;

	try {
		return new Intl.NumberFormat( locale || undefined, { style: 'currency', currency } ).format( amount );
	} catch ( error ) {
		return `${ amount.toFixed( 2 ) } ${ currency }`;
	}
}
```

- [ ] **Step 7: Tạo `src/admin/App.js`**

```js
import { __ } from '@wordpress/i18n';

export default function App( { page } ) {
	return (
		<div className="dnbfs-app" data-page={ page }>
			<p>
				{ 'settings' === page
					? __( 'Settings are loading…', 'dn-burst-funnel-stats' )
					: __( 'Dashboard is loading…', 'dn-burst-funnel-stats' ) }
			</p>
		</div>
	);
}
```

- [ ] **Step 8: Tạo `src/admin/index.js`**

```js
import { createRoot } from '@wordpress/element';
import App from './App';

const mount = document.getElementById( 'dnbfs-app' );

if ( mount ) {
	createRoot( mount ).render(
		<App page={ mount.dataset.page || 'dashboard' } config={ window.dnbfsAdmin || {} } />
	);
}
```

- [ ] **Step 9: Test + build**

Run: `docker compose -f docker/docker-compose.yml run --rm node test` → Expected: `Tests: 4 passed`.
Run: `docker compose -f docker/docker-compose.yml run --rm node build` → Expected: tạo `build/index.js` và `build/index.asset.php`; `build/index.asset.php` chứa `wp-element` và `wp-i18n` trong `dependencies`.

- [ ] **Step 10: Commit**

```bash
git add docker/docker-compose.yml package.json package-lock.json src build .gitignore
git commit -m "build: add @wordpress/scripts toolchain and admin app skeleton

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Mô hình Settings 6 nhóm

**Files:**
- Create: `includes/admin/settings-model.php`, `tests/integration/test-settings-model.php`
- Modify: `includes/tracking.php` (`default_date_range`), `includes/reports/wc-settings.php` (mặc định thêm `wc-refunded` — chỉ nếu chưa có), `tests/php/TrackingSettingsTest.php`, `dn-burst-funnel-stats.php` (thêm `dn_burst_funnel_stats_load_admin()`)

**Interfaces:**
- Consumes: `dn_bfs_get_tracking_settings()`, `dn_bfs_sanitize_tracking_settings()`, `dn_bfs_get_wc_report_settings()`, `dn_bfs_sanitize_wc_report_settings()`, `dn_bfs_get_date_presets()`, `dn_bfs_raw_available_from()`, `dn_bfs_geo_db_path()`.
- Produces:
  - `dn_bfs_default_range_presets(): array` (tracking.php) — `today, yesterday, week_to_date, last_week, month_to_date, last_month, quarter_to_date, last_quarter, year_to_date, last_year`.
  - `DN_BFS_SECRET_MASK` = `'********'`.
  - `dn_bfs_settings_groups(): array` — `group => keys[]` cho `general, tracking, antispam, woocommerce, geoip, data`.
  - `dn_bfs_wc_report_setting_keys(): array`.
  - `dn_bfs_get_settings_group( string $group ): array|WP_Error` → `array( 'values' => array, 'meta' => array )`.
  - `dn_bfs_save_settings_group( string $group, $input ): array|WP_Error` → như GET sau khi lưu.
  - `dn_bfs_geoip_status(): array` → `database` (bool), `size` (int), `updated_at`, `attempted_at` (int), `last_error` (string), `license_set` (bool).
  - `dn_burst_funnel_stats_load_admin()` trong file chính, gọi trong `dn_burst_funnel_stats_bootstrap()` ngay sau `dn_burst_funnel_stats_load_reports();` (route REST cần ở mọi request, không chỉ `is_admin()`).

- [ ] **Step 1: Viết test unit fail** — thêm vào `tests/php/TrackingSettingsTest.php`:

```php
	public function test_default_date_range_accepts_presets() {
		$this->assertSame( 'last_week', dn_bfs_sanitize_tracking_settings( array( 'default_date_range' => 'last_week' ) )['default_date_range'] );
		$this->assertSame( 'month_to_date', dn_bfs_sanitize_tracking_settings( array( 'default_date_range' => 'custom' ) )['default_date_range'] );
		$this->assertSame( 'month_to_date', dn_bfs_sanitize_tracking_settings( array( 'default_date_range' => 'nope' ) )['default_date_range'] );
		$this->assertContains( 'year_to_date', dn_bfs_default_range_presets() );
	}
```

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit --filter TrackingSettingsTest` → Expected: FAIL (`'last_week'` khác `'month_to_date'`).

- [ ] **Step 2: Sửa `includes/tracking.php`** — thêm hàm ngay trước `dn_bfs_tracking_int_ranges()`:

```php
function dn_bfs_default_range_presets() {
	return array( 'today', 'yesterday', 'week_to_date', 'last_week', 'month_to_date', 'last_month', 'quarter_to_date', 'last_quarter', 'year_to_date', 'last_year' );
}
```

và trong mảng `$clean` của `dn_bfs_sanitize_tracking_settings()` thay dòng `'default_date_range'     => 'month_to_date',` bằng:

```php
		'default_date_range'     => isset( $settings['default_date_range'] ) && in_array( $settings['default_date_range'], dn_bfs_default_range_presets(), true ) ? $settings['default_date_range'] : 'month_to_date',
```

Run lại → Expected: PASS.

- [ ] **Step 3: Mặc định `wc-refunded`** — kiểm tra `dn_bfs_wc_report_defaults()` trong `includes/reports/wc-settings.php`; nếu `sales_excluded_statuses` chưa có `wc-refunded` thì thêm vào cuối mảng (Kế hoạch 2 đã thêm theo quyết định "trừ hoàn tiền mọi nơi" — khi đó bỏ qua bước này).

- [ ] **Step 4: Viết test tích hợp fail — `tests/integration/test-settings-model.php`**

```php
<?php

function dn_bfs_it_group_values( $group ) {
	return dn_bfs_get_settings_group( $group )['values'];
}

dn_bfs_it(
	'settings groups expose every key and reject unknown groups',
	function () {
		dn_bfs_assert_same( array( 'general', 'tracking', 'antispam', 'woocommerce', 'geoip', 'data' ), array_keys( dn_bfs_settings_groups() ) );

		foreach ( dn_bfs_settings_groups() as $group => $keys ) {
			dn_bfs_assert_same( $keys, array_keys( dn_bfs_it_group_values( $group ) ), $group );
		}

		$error = dn_bfs_get_settings_group( 'nope' );
		dn_bfs_assert_same( 'invalid_group', $error->get_error_code() );
		dn_bfs_assert_same( 404, $error->get_error_data()['status'] );
	}
);

dn_bfs_it(
	'saving a group requires every key and rejects unknown keys',
	function () {
		$missing = dn_bfs_save_settings_group( 'general', array( 'tracking_enabled' => 1 ) );
		dn_bfs_assert_same( 'missing_keys', $missing->get_error_code() );
		dn_bfs_assert_same( array( 'default_date_range', 'default_compare' ), $missing->get_error_data()['missing'] );

		$unknown = dn_bfs_save_settings_group( 'general', array( 'tracking_enabled' => 1, 'default_date_range' => 'today', 'default_compare' => 'none', 'hack' => 1 ) );
		dn_bfs_assert_same( 'unknown_keys', $unknown->get_error_code() );

		$saved = dn_bfs_save_settings_group( 'general', array( 'tracking_enabled' => 0, 'default_date_range' => 'last_week', 'default_compare' => 'previous_period' ) );
		dn_bfs_assert_same( 0, $saved['values']['tracking_enabled'] );
		dn_bfs_assert_same( 'last_week', dn_bfs_get_tracking_settings()['default_date_range'] );
	}
);

dn_bfs_it(
	'tracking group keeps empty lists and reports invalid IP rules',
	function () {
		$values                   = dn_bfs_it_group_values( 'tracking' );
		$values['excluded_roles'] = array();
		$values['excluded_ips']   = array( '10.0.0.0/8', 'not-an-ip' );

		$saved = dn_bfs_save_settings_group( 'tracking', $values );

		dn_bfs_assert_same( array(), $saved['values']['excluded_roles'] );
		dn_bfs_assert_same( array( '10.0.0.0/8' ), $saved['values']['excluded_ips'] );
		dn_bfs_assert_same( array( 'not-an-ip' ), $saved['meta']['invalid_excluded_ips'] );
	}
);

dn_bfs_it(
	'license key is masked, kept by the mask and clears the backoff when changed',
	function () {
		$values                        = dn_bfs_it_group_values( 'geoip' );
		$values['maxmind_license_key'] = 'ABC_123';
		dn_bfs_save_settings_group( 'geoip', $values );

		dn_bfs_assert_same( DN_BFS_SECRET_MASK, dn_bfs_it_group_values( 'geoip' )['maxmind_license_key'] );

		update_option( 'dnbfs_geoip_attempted_at', time(), false );
		$values                        = dn_bfs_it_group_values( 'geoip' );
		$values['maxmind_license_key'] = DN_BFS_SECRET_MASK;
		dn_bfs_save_settings_group( 'geoip', $values );

		dn_bfs_assert_same( 'ABC_123', dn_bfs_get_tracking_settings()['maxmind_license_key'] );
		dn_bfs_assert_true( false !== get_option( 'dnbfs_geoip_attempted_at' ), 'kept backoff when unchanged' );

		$values['maxmind_license_key'] = 'NEW_KEY';
		dn_bfs_save_settings_group( 'geoip', $values );
		dn_bfs_assert_true( false === get_option( 'dnbfs_geoip_attempted_at' ), 'backoff cleared' );

		$values['maxmind_license_key'] = '';
		dn_bfs_save_settings_group( 'geoip', $values );
		dn_bfs_assert_same( '', dn_bfs_it_group_values( 'geoip' )['maxmind_license_key'] );
	}
);

dn_bfs_it(
	'woocommerce group writes report settings and tracking flags to their own options',
	function () {
		$values                            = dn_bfs_it_group_values( 'woocommerce' );
		$values['force_cart_redirect']     = 1;
		$values['paid_statuses']           = array( 'wc-completed' );
		$values['tip_keywords']            = array( 'Tiền Boa' );

		$saved = dn_bfs_save_settings_group( 'woocommerce', $values );

		dn_bfs_assert_same( 1, dn_bfs_get_tracking_settings()['force_cart_redirect'] );
		dn_bfs_assert_same( array( 'wc-completed' ), dn_bfs_get_wc_report_settings()['paid_statuses'] );
		dn_bfs_assert_same( array( 'tiền boa' ), $saved['values']['tip_keywords'] );
		dn_bfs_assert_true( isset( $saved['meta']['order_statuses']['wc-refunded'] ), 'statuses listed' );
		delete_option( 'dn_burst_funnel_stats_wc_report_settings' );
	}
);

dn_bfs_it(
	'data and geoip groups expose status meta',
	function () {
		$data = dn_bfs_get_settings_group( 'data' );
		dn_bfs_assert_same( 90, $data['values']['raw_retention_days'] );
		dn_bfs_assert_same( dn_bfs_raw_available_from( dn_bfs_now() ), $data['meta']['raw_available_from'] );

		$geo = dn_bfs_get_settings_group( 'geoip' );
		dn_bfs_assert_same( false, $geo['meta']['status']['license_set'] );
	}
);
```

Run tích hợp → Expected: các test mới FAIL (`Call to undefined function dn_bfs_settings_groups()`).

- [ ] **Step 5: Tạo `includes/admin/settings-model.php`**

```php
<?php
/**
 * Settings groups behind the admin Settings screen.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DN_BFS_SECRET_MASK', '********' );

function dn_bfs_settings_groups() {
	return array(
		'general'     => array( 'tracking_enabled', 'default_date_range', 'default_compare' ),
		'tracking'    => array( 'excluded_roles', 'excluded_ips', 'client_ip_source', 'page_tracking_mode', 'selected_page_ids', 'product_tracking_mode', 'selected_product_ids', 'session_timeout', 'cookie_days' ),
		'antispam'    => array( 'dedupe_window', 'reload_window', 'limit_pv_per_min', 'limit_sessions_per_hour', 'limit_atc_per_min', 'limit_pv_per_session', 'exclude_bots', 'block_empty_ua', 'custom_bot_user_agents' ),
		'woocommerce' => array( 'force_cart_redirect', 'sales_excluded_statuses', 'paid_statuses', 'balance_statuses', 'tip_keywords' ),
		'geoip'       => array( 'prefer_cloudflare', 'maxmind_license_key' ),
		'data'        => array( 'raw_retention_days' ),
	);
}

function dn_bfs_wc_report_setting_keys() {
	return array( 'sales_excluded_statuses', 'paid_statuses', 'balance_statuses', 'tip_keywords' );
}

function dn_bfs_geoip_status() {
	$path     = dn_bfs_geo_db_path();
	$settings = dn_bfs_get_tracking_settings();

	return array(
		'database'     => file_exists( $path ),
		'size'         => file_exists( $path ) ? (int) filesize( $path ) : 0,
		'updated_at'   => (int) get_option( 'dnbfs_geoip_updated_at', 0 ),
		'attempted_at' => (int) get_option( 'dnbfs_geoip_attempted_at', 0 ),
		'last_error'   => (string) get_option( 'dnbfs_geoip_last_error', '' ),
		'license_set'  => '' !== $settings['maxmind_license_key'],
	);
}

function dn_bfs_settings_group_meta( $group, $tracking ) {
	switch ( $group ) {
		case 'general':
			$presets = dn_bfs_get_date_presets();
			unset( $presets['custom'] );

			return array( 'presets' => $presets );
		case 'tracking':
			return array(
				'invalid_excluded_ips' => array_values( (array) $tracking['invalid_excluded_ips'] ),
				'roles'                => wp_roles()->get_names(),
			);
		case 'woocommerce':
			return array( 'order_statuses' => function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array() );
		case 'geoip':
			return array( 'status' => dn_bfs_geoip_status() );
		case 'data':
			return array(
				'raw_available_from' => dn_bfs_raw_available_from( dn_bfs_now() ),
				'last_aggregated'    => (string) get_option( 'dnbfs_last_aggregated_date', '' ),
			);
	}

	return array();
}

function dn_bfs_get_settings_group( $group ) {
	$groups = dn_bfs_settings_groups();

	if ( ! isset( $groups[ $group ] ) ) {
		return new WP_Error( 'invalid_group', __( 'Unknown settings group.', 'dn-burst-funnel-stats' ), array( 'status' => 404 ) );
	}

	$tracking = dn_bfs_get_tracking_settings();
	$wc       = dn_bfs_get_wc_report_settings();
	$values   = array();

	foreach ( $groups[ $group ] as $key ) {
		$values[ $key ] = in_array( $key, dn_bfs_wc_report_setting_keys(), true ) ? $wc[ $key ] : $tracking[ $key ];
	}

	if ( array_key_exists( 'maxmind_license_key', $values ) ) {
		$values['maxmind_license_key'] = '' !== $values['maxmind_license_key'] ? DN_BFS_SECRET_MASK : '';
	}

	return array(
		'values' => $values,
		'meta'   => dn_bfs_settings_group_meta( $group, $tracking ),
	);
}

function dn_bfs_save_settings_group( $group, $input ) {
	$groups = dn_bfs_settings_groups();

	if ( ! isset( $groups[ $group ] ) ) {
		return new WP_Error( 'invalid_group', __( 'Unknown settings group.', 'dn-burst-funnel-stats' ), array( 'status' => 404 ) );
	}

	if ( ! is_array( $input ) ) {
		return new WP_Error( 'invalid_settings', __( 'Settings must be an object.', 'dn-burst-funnel-stats' ), array( 'status' => 400 ) );
	}

	$keys    = $groups[ $group ];
	$missing = array_values( array_diff( $keys, array_keys( $input ) ) );
	$unknown = array_values( array_diff( array_keys( $input ), $keys ) );

	if ( $missing ) {
		return new WP_Error( 'missing_keys', __( 'Every setting in the group must be sent.', 'dn-burst-funnel-stats' ), array( 'status' => 400, 'missing' => $missing ) );
	}

	if ( $unknown ) {
		return new WP_Error( 'unknown_keys', __( 'Unknown settings were sent.', 'dn-burst-funnel-stats' ), array( 'status' => 400, 'unknown' => $unknown ) );
	}

	$tracking_input = array();
	$wc_input       = array();

	foreach ( $keys as $key ) {
		if ( in_array( $key, dn_bfs_wc_report_setting_keys(), true ) ) {
			$wc_input[ $key ] = $input[ $key ];
		} else {
			$tracking_input[ $key ] = $input[ $key ];
		}
	}

	if ( $tracking_input ) {
		$current = dn_bfs_get_tracking_settings();

		if ( array_key_exists( 'maxmind_license_key', $tracking_input ) && DN_BFS_SECRET_MASK === $tracking_input['maxmind_license_key'] ) {
			$tracking_input['maxmind_license_key'] = $current['maxmind_license_key'];
		}

		$clean = dn_bfs_sanitize_tracking_settings( array_merge( $current, $tracking_input ) );
		update_option( 'dn_burst_funnel_stats_tracking_settings', $clean, false );

		if ( $clean['maxmind_license_key'] !== $current['maxmind_license_key'] ) {
			delete_option( 'dnbfs_geoip_attempted_at' );
		}
	}

	if ( $wc_input ) {
		update_option(
			'dn_burst_funnel_stats_wc_report_settings',
			dn_bfs_sanitize_wc_report_settings( array_merge( dn_bfs_get_wc_report_settings(), $wc_input ) ),
			false
		);
	}

	return dn_bfs_get_settings_group( $group );
}
```

- [ ] **Step 6: Nạp module admin** — trong `dn-burst-funnel-stats.php` thêm ngay sau `dn_burst_funnel_stats_load_reports()`:

```php
/**
 * Load admin modules (REST routes are needed on every request, not only in wp-admin).
 *
 * @return void
 */
function dn_burst_funnel_stats_load_admin()
{
  foreach (array('settings-model') as $module) {
    require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/admin/' . $module . '.php';
  }
}
```

và trong `dn_burst_funnel_stats_bootstrap()` gọi `dn_burst_funnel_stats_load_admin();` ngay sau `dn_burst_funnel_stats_load_reports();` **và sau** dòng `require_once … 'includes/date-ranges.php';` (model dùng `dn_bfs_get_date_presets()`). Các task sau thêm module vào mảng này.

- [ ] **Step 7: Chạy test**

Run tích hợp → Expected: tất cả PASS (thêm 6 test). Run unit → OK. Run `php74` → OK.

- [ ] **Step 8: Commit**

```bash
git add includes/admin/settings-model.php includes/tracking.php includes/reports/wc-settings.php tests dn-burst-funnel-stats.php
git commit -m "feat(admin): add settings groups model with masked license and full-group saves

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Tham số REST + route báo cáo

**Files:**
- Create: `includes/admin/rest-params.php`, `includes/admin/rest-reports.php`, `tests/integration/rest.php`, `tests/integration/test-admin-rest-reports.php`
- Modify: `dn-burst-funnel-stats.php` (thêm `'rest-params', 'rest-reports'` vào `dn_burst_funnel_stats_load_admin()`)

**Interfaces:**
- Consumes: `dn_bfs_calculate_date_range()`, `dn_bfs_get_date_presets()`, `includes/reports.php` (summary, timeseries, funnel, breakdown, realtime, `dn_bfs_report_raw_rows`, `dn_bfs_day_bounds`), metrics lists.
- Produces (Kế hoạch 4 dùng lại):
  - `dn_bfs_admin_capability(): string`, `dn_bfs_admin_permission(): bool`.
  - `dn_bfs_rest_error( string $code, string $message, int $status = 400, array $extra = array() ): WP_Error`.
  - `dn_bfs_rest_range( array $params ): array|WP_Error` — `period` (preset hoặc `custom`; rỗng → mặc định settings), `compare`, `start`, `end` (`Y-m-d`, khoảng ≤ 731 ngày).
  - `dn_bfs_rest_filters( array $params ): array|WP_Error` — `filter[dimension]=value`.
  - `dn_bfs_rest_metrics( $value ): array|WP_Error` — chuỗi phân cách dấu phẩy hoặc mảng; rỗng → `array( 'sessions', 'orders', 'revenue' )`.
  - `dn_bfs_rest_range_meta( array $range ): array` → `period, compare, start, end, label, range_label, previous_range_label`.
  - `dn_bfs_rest_envelope( $data, $range = null, bool $estimated = false ): WP_REST_Response`.
  - Route GET `dnbfs/v1/admin/summary`, `/admin/timeseries` (`metrics`; khi compare ≠ none trả thêm `previous`), `/admin/funnel`, `/admin/breakdown` (`dimension`, `orderby`, `order`, `per_page` 1..500 mặc định 25, `page` ≥ 1), `/admin/realtime`, `/admin/filters/values` (`dimension` ∈ bộ lọc, `search`).
  - Helper test: `dn_bfs_it_login_admin(): int`, `dn_bfs_it_rest( string $method, string $route, array $params = array(), $body = null ): WP_REST_Response`.

- [ ] **Step 1: Tạo `tests/integration/rest.php`**

```php
<?php
/**
 * Helpers for calling plugin REST routes inside integration tests.
 */

function dn_bfs_it_login_admin() {
	$admin_id = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
	wp_set_current_user( $admin_id );

	return $admin_id;
}

function dn_bfs_it_rest( $method, $route, $params = array(), $body = null ) {
	$request = new WP_REST_Request( $method, $route );

	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( null === $body ? $params : $body ) );
	}

	return rest_do_request( $request );
}
```

- [ ] **Step 2: Viết test fail — `tests/integration/test-admin-rest-reports.php`**

```php
<?php

require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/rest.php';

function dn_bfs_it_seed_admin_reports() {
	$s = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_now() - 60, 'utm_campaign' => 'sale-10', 'channel' => 'paid' ) );
	dn_bfs_it_seed_pageview( $s, '/', dn_bfs_it_now() - 60 );
}

dn_bfs_it(
	'admin report routes require the capability',
	function () {
		dn_bfs_assert_same( 401, dn_bfs_it_rest( 'GET', '/dnbfs/v1/admin/summary' )->get_status() );

		$subscriber = wp_insert_user( array( 'user_login' => 'dnbfs_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		dn_bfs_assert_same( 403, dn_bfs_it_rest( 'GET', '/dnbfs/v1/admin/summary' )->get_status() );
		wp_delete_user( $subscriber );
	}
);

dn_bfs_it_today(
	'summary returns the envelope with range meta and currency',
	function () {
		dn_bfs_it_login_admin();
		dn_bfs_it_seed_admin_reports();

		$response = dn_bfs_it_rest( 'GET', '/dnbfs/v1/admin/summary', array( 'period' => 'today', 'compare' => 'none' ) );
		$body     = $response->get_data();

		dn_bfs_assert_same( 200, $response->get_status() );
		dn_bfs_assert_same( 1, $body['data']['current']['sessions'] );
		dn_bfs_assert_same( 'today', $body['meta']['range']['period'] );
		dn_bfs_assert_same( wp_date( 'Y-m-d', dn_bfs_it_now() ), $body['meta']['range']['start'] );
		dn_bfs_assert_same( get_woocommerce_currency(), $body['meta']['currency'] );
		dn_bfs_assert_same( false, $body['meta']['estimated'] );
	}
);

dn_bfs_it(
	'invalid parameters return 400 with specific codes',
	function () {
		dn_bfs_it_login_admin();

		$cases = array(
			array( '/dnbfs/v1/admin/summary', array( 'period' => 'nope' ), 'invalid_period' ),
			array( '/dnbfs/v1/admin/summary', array( 'compare' => 'nope' ), 'invalid_compare' ),
			array( '/dnbfs/v1/admin/summary', array( 'period' => 'custom', 'start' => '2026-13-01', 'end' => '2026-01-02' ), 'invalid_date' ),
			array( '/dnbfs/v1/admin/summary', array( 'period' => 'custom', 'start' => '2020-01-01', 'end' => '2026-01-01' ), 'range_too_long' ),
			array( '/dnbfs/v1/admin/summary', array( 'filter' => array( 'browser' => 'Chrome' ) ), 'invalid_filter' ),
			array( '/dnbfs/v1/admin/summary', array( 'filter' => array( 'campaign' => '' ) ), 'invalid_filter' ),
			array( '/dnbfs/v1/admin/timeseries', array( 'metrics' => 'sessions,nope' ), 'invalid_metric' ),
			array( '/dnbfs/v1/admin/breakdown', array( 'dimension' => 'nope' ), 'invalid_dimension' ),
			array( '/dnbfs/v1/admin/breakdown', array( 'dimension' => 'page', 'orderby' => 'nope' ), 'invalid_orderby' ),
			array( '/dnbfs/v1/admin/filters/values', array( 'dimension' => 'page' ), 'invalid_dimension' ),
		);

		foreach ( $cases as $case ) {
			$response = dn_bfs_it_rest( 'GET', $case[0], $case[1] );
			dn_bfs_assert_same( 400, $response->get_status(), $case[2] );
			dn_bfs_assert_same( $case[2], $response->get_data()['code'], $case[2] );
		}
	}
);

dn_bfs_it_today(
	'report errors pass through with their status',
	function () {
		dn_bfs_it_login_admin();

		$response = dn_bfs_it_rest(
			'GET',
			'/dnbfs/v1/admin/summary',
			array(
				'period' => 'custom',
				'start'  => dn_bfs_date_shift( wp_date( 'Y-m-d', dn_bfs_it_now() ), -400 ),
				'end'    => wp_date( 'Y-m-d', dn_bfs_it_now() ),
				'filter' => array( 'channel' => 'paid', 'device' => 'mobile' ),
			)
		);

		dn_bfs_assert_same( 422, $response->get_status() );
		dn_bfs_assert_same( 'filter_out_of_retention', $response->get_data()['code'] );
	}
);

dn_bfs_it_today(
	'timeseries returns previous series when comparing, funnel and realtime work',
	function () {
		dn_bfs_it_login_admin();
		dn_bfs_it_seed_admin_reports();

		$series = dn_bfs_it_rest( 'GET', '/dnbfs/v1/admin/timeseries', array( 'period' => 'today', 'compare' => 'previous_period', 'metrics' => array( 'sessions', 'bounce_rate' ) ) )->get_data();
		dn_bfs_assert_same( array( 1 ), $series['data']['series']['sessions'] );
		dn_bfs_assert_same( array( 0 ), $series['data']['previous']['series']['sessions'] );

		$funnel = dn_bfs_it_rest( 'GET', '/dnbfs/v1/admin/funnel', array( 'period' => 'today' ) )->get_data();
		dn_bfs_assert_same( 'visitors', $funnel['data'][0]['key'] );

		$realtime = dn_bfs_it_rest( 'GET', '/dnbfs/v1/admin/realtime' )->get_data();
		dn_bfs_assert_true( isset( $realtime['data']['online'] ), 'online' );
		dn_bfs_assert_same( null, $realtime['meta']['range'] );
	}
);

dn_bfs_it_today(
	'breakdown paginates and filter values search today and daily rows',
	function () {
		dn_bfs_it_login_admin();

		foreach ( array( 'alpha', 'beta', 'gamma' ) as $i => $campaign ) {
			$s = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_now() - 60 - $i, 'utm_campaign' => $campaign ) );
			dn_bfs_it_seed_pageview( $s, '/' . $campaign, dn_bfs_it_now() - 60 - $i );
		}

		$page = dn_bfs_it_rest( 'GET', '/dnbfs/v1/admin/breakdown', array( 'period' => 'today', 'dimension' => 'campaign', 'orderby' => 'sessions', 'order' => 'asc', 'per_page' => 2, 'page' => 2 ) )->get_data();
		dn_bfs_assert_same( 3, $page['data']['total'] );
		dn_bfs_assert_same( array( 'gamma' ), array_column( $page['data']['rows'], 'dim_value' ) );

		$values = dn_bfs_it_rest( 'GET', '/dnbfs/v1/admin/filters/values', array( 'dimension' => 'campaign', 'search' => 'ph' ) )->get_data();
		dn_bfs_assert_same( array( 'alpha' ), $values['data'] );
	}
);
```

(Test `dn_bfs_it_today` và `dn_bfs_it_now` là helper có sẵn từ Kế hoạch 2 — ghim "now" sau nửa đêm + thời gian hết phiên.)

Run tích hợp → Expected: các test mới FAIL (route chưa có → 404).

- [ ] **Step 3: Tạo `includes/admin/rest-params.php`**

```php
<?php
/**
 * Shared REST helpers: permissions, parameter validation and the response envelope.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_admin_capability() {
	return (string) apply_filters( 'dn_bfs_capability', 'manage_options' );
}

function dn_bfs_admin_permission() {
	return current_user_can( dn_bfs_admin_capability() );
}

function dn_bfs_rest_error( $code, $message, $status = 400, $extra = array() ) {
	return new WP_Error( $code, $message, array_merge( array( 'status' => (int) $status ), $extra ) );
}

function dn_bfs_rest_valid_date( $value ) {
	if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
		return false;
	}

	$date = DateTime::createFromFormat( '!Y-m-d', $value );

	return $date && $date->format( 'Y-m-d' ) === $value;
}

function dn_bfs_rest_range( $params ) {
	$settings = dn_bfs_get_tracking_settings();
	$period   = isset( $params['period'] ) && '' !== $params['period'] ? (string) $params['period'] : $settings['default_date_range'];
	$compare  = isset( $params['compare'] ) && '' !== $params['compare'] ? (string) $params['compare'] : $settings['default_compare'];

	if ( ! array_key_exists( $period, dn_bfs_get_date_presets() ) ) {
		return dn_bfs_rest_error( 'invalid_period', __( 'Unknown date range.', 'dn-burst-funnel-stats' ) );
	}

	if ( ! in_array( $compare, array( 'none', 'previous_period', 'previous_year' ), true ) ) {
		return dn_bfs_rest_error( 'invalid_compare', __( 'Unknown comparison mode.', 'dn-burst-funnel-stats' ) );
	}

	$start = '';
	$end   = '';

	if ( 'custom' === $period ) {
		$start = isset( $params['start'] ) ? $params['start'] : '';
		$end   = isset( $params['end'] ) ? $params['end'] : '';

		if ( ! dn_bfs_rest_valid_date( $start ) || ! dn_bfs_rest_valid_date( $end ) || $start > $end ) {
			return dn_bfs_rest_error( 'invalid_date', __( 'Use valid start and end dates in YYYY-MM-DD format.', 'dn-burst-funnel-stats' ) );
		}

		if ( count( dn_bfs_dates_between( $start, $end ) ) > 731 ) {
			return dn_bfs_rest_error( 'range_too_long', __( 'Custom ranges can cover at most 731 days.', 'dn-burst-funnel-stats' ) );
		}
	}

	return dn_bfs_calculate_date_range( $period, $compare, $start, $end );
}

function dn_bfs_rest_filters( $params ) {
	$raw = isset( $params['filter'] ) ? $params['filter'] : array();

	if ( ! is_array( $raw ) ) {
		return dn_bfs_rest_error( 'invalid_filter', __( 'Filters must be sent as filter[dimension]=value.', 'dn-burst-funnel-stats' ) );
	}

	foreach ( $raw as $dimension => $value ) {
		if ( ! in_array( $dimension, dn_bfs_filter_dimensions(), true ) ) {
			/* translators: %s: filter dimension. */
			return dn_bfs_rest_error( 'invalid_filter', sprintf( __( 'Unknown filter: %s.', 'dn-burst-funnel-stats' ), $dimension ) );
		}

		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			/* translators: %s: filter dimension. */
			return dn_bfs_rest_error( 'invalid_filter', sprintf( __( 'Filter %s needs a value.', 'dn-burst-funnel-stats' ), $dimension ) );
		}
	}

	return dn_bfs_sanitize_filters( $raw );
}

function dn_bfs_rest_metrics( $value ) {
	$metrics = is_array( $value ) ? $value : array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );

	if ( empty( $metrics ) ) {
		return array( 'sessions', 'orders', 'revenue' );
	}

	$allowed = array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() );

	foreach ( $metrics as $metric ) {
		if ( ! in_array( $metric, $allowed, true ) ) {
			/* translators: %s: metric name. */
			return dn_bfs_rest_error( 'invalid_metric', sprintf( __( 'Unknown metric: %s.', 'dn-burst-funnel-stats' ), (string) $metric ) );
		}
	}

	return array_values( array_unique( $metrics ) );
}

function dn_bfs_rest_range_meta( $range ) {
	return array(
		'period'               => $range['period'],
		'compare'              => $range['compare'],
		'start'                => $range['custom_start'],
		'end'                  => $range['custom_end'],
		'label'                => $range['current_label'],
		'range_label'          => $range['current_range_label'],
		'previous_range_label' => $range['previous_range_label'],
	);
}

function dn_bfs_rest_envelope( $data, $range = null, $estimated = false ) {
	return rest_ensure_response(
		array(
			'data' => $data,
			'meta' => array(
				'range'     => is_array( $range ) ? dn_bfs_rest_range_meta( $range ) : null,
				'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'timezone'  => wp_timezone_string(),
				'estimated' => (bool) $estimated,
			),
		)
	);
}
```

- [ ] **Step 4: Tạo `includes/admin/rest-reports.php`**

```php
<?php
/**
 * Internal REST routes for the admin dashboard reports.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_register_admin_report_routes() {
	$routes = array(
		'/admin/summary'        => 'dn_bfs_rest_admin_summary',
		'/admin/timeseries'     => 'dn_bfs_rest_admin_timeseries',
		'/admin/funnel'         => 'dn_bfs_rest_admin_funnel',
		'/admin/breakdown'      => 'dn_bfs_rest_admin_breakdown',
		'/admin/realtime'       => 'dn_bfs_rest_admin_realtime',
		'/admin/filters/values' => 'dn_bfs_rest_admin_filter_values',
	);

	foreach ( $routes as $route => $callback ) {
		register_rest_route(
			'dnbfs/v1',
			$route,
			array(
				'methods'             => 'GET',
				'callback'            => $callback,
				'permission_callback' => 'dn_bfs_admin_permission',
			)
		);
	}
}
add_action( 'rest_api_init', 'dn_bfs_register_admin_report_routes' );

/**
 * Parse the shared range + filter parameters; returns array( $range, $filters ) or WP_Error.
 */
function dn_bfs_rest_report_input( WP_REST_Request $request ) {
	$params = $request->get_params();
	$range  = dn_bfs_rest_range( $params );

	if ( is_wp_error( $range ) ) {
		return $range;
	}

	$filters = dn_bfs_rest_filters( $params );

	if ( is_wp_error( $filters ) ) {
		return $filters;
	}

	return array( $range, $filters );
}

function dn_bfs_rest_admin_summary( WP_REST_Request $request ) {
	$input = dn_bfs_rest_report_input( $request );

	if ( is_wp_error( $input ) ) {
		return $input;
	}

	list( $range, $filters ) = $input;
	$summary                 = dn_bfs_report_summary( $range, $filters );

	return is_wp_error( $summary ) ? $summary : dn_bfs_rest_envelope( $summary, $range, $summary['estimated'] );
}

function dn_bfs_rest_admin_timeseries( WP_REST_Request $request ) {
	$input = dn_bfs_rest_report_input( $request );

	if ( is_wp_error( $input ) ) {
		return $input;
	}

	$metrics = dn_bfs_rest_metrics( $request->get_param( 'metrics' ) );

	if ( is_wp_error( $metrics ) ) {
		return $metrics;
	}

	list( $range, $filters ) = $input;
	$current                 = dn_bfs_report_timeseries( $range, $metrics, $filters );

	if ( is_wp_error( $current ) ) {
		return $current;
	}

	$estimated = ! empty( $current['estimated'] );

	if ( 'none' !== $range['compare'] ) {
		$previous_range = array_merge(
			$range,
			array(
				'current_start' => $range['previous_start'],
				'current_end'   => $range['previous_end'],
				'compare'       => 'none',
			)
		);
		$previous       = dn_bfs_report_timeseries( $previous_range, $metrics, $filters );

		if ( is_wp_error( $previous ) ) {
			return $previous;
		}

		$current['previous'] = $previous;
		$estimated           = $estimated || ! empty( $previous['estimated'] );
	}

	return dn_bfs_rest_envelope( $current, $range, $estimated );
}

function dn_bfs_rest_admin_funnel( WP_REST_Request $request ) {
	$input = dn_bfs_rest_report_input( $request );

	if ( is_wp_error( $input ) ) {
		return $input;
	}

	list( $range, $filters ) = $input;
	$funnel                  = dn_bfs_report_funnel( $range, $filters );

	return is_wp_error( $funnel ) ? $funnel : dn_bfs_rest_envelope( $funnel, $range );
}

function dn_bfs_rest_admin_breakdown( WP_REST_Request $request ) {
	$input = dn_bfs_rest_report_input( $request );

	if ( is_wp_error( $input ) ) {
		return $input;
	}

	$dimension = (string) $request->get_param( 'dimension' );

	if ( ! in_array( $dimension, dn_bfs_report_dimensions(), true ) ) {
		return dn_bfs_rest_error( 'invalid_dimension', __( 'Unknown report dimension.', 'dn-burst-funnel-stats' ) );
	}

	$orderby = (string) $request->get_param( 'orderby' );

	if ( '' !== $orderby && ! in_array( $orderby, array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() ), true ) ) {
		return dn_bfs_rest_error( 'invalid_orderby', __( 'Unknown sort column.', 'dn-burst-funnel-stats' ) );
	}

	$order    = 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'asc' : 'desc';
	$per_page = max( 1, min( 500, (int) ( $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 25 ) ) );
	$page     = max( 1, (int) $request->get_param( 'page' ) );

	list( $range, $filters ) = $input;
	$result                  = dn_bfs_report_breakdown( $range, $dimension, $filters, $orderby, $order, $per_page, ( $page - 1 ) * $per_page );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$result['page']     = $page;
	$result['per_page'] = $per_page;

	return dn_bfs_rest_envelope( $result, $range, $result['estimated'] );
}

function dn_bfs_rest_admin_realtime() {
	return dn_bfs_rest_envelope( dn_bfs_report_realtime() );
}

function dn_bfs_rest_admin_filter_values( WP_REST_Request $request ) {
	global $wpdb;

	$dimension = (string) $request->get_param( 'dimension' );

	if ( ! in_array( $dimension, dn_bfs_filter_dimensions(), true ) ) {
		return dn_bfs_rest_error( 'invalid_dimension', __( 'Filter values are only available for filter dimensions.', 'dn-burst-funnel-stats' ) );
	}

	$search = dn_bfs_truncate( sanitize_text_field( (string) $request->get_param( 'search' ) ), 100 );
	$now    = dn_bfs_now();
	$like   = '%' . $wpdb->esc_like( $search ) . '%';
	$totals = array();

	$daily = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT dim_value, SUM(sessions) AS sessions FROM ' . dn_bfs_table( 'daily' ) . '
			WHERE dimension = %s AND date >= %s AND dim_value <> %s AND dim_value LIKE %s
			GROUP BY dim_hash, dim_value',
			$dimension,
			dn_bfs_date_shift( wp_date( 'Y-m-d', $now ), -90 ),
			'',
			$like
		),
		ARRAY_A
	);

	foreach ( (array) $daily as $row ) {
		$totals[ (string) $row['dim_value'] ] = (int) $row['sessions'];
	}

	list( $today_start ) = dn_bfs_day_bounds( wp_date( 'Y-m-d', $now ) );

	foreach ( dn_bfs_report_raw_rows( $today_start, $now + 1, $dimension, array() ) as $value => $metrics ) {
		$value = (string) $value;

		if ( '' === $value || ( '' !== $search && false === stripos( $value, $search ) ) ) {
			continue;
		}

		$totals[ $value ] = ( isset( $totals[ $value ] ) ? $totals[ $value ] : 0 ) + (int) $metrics['sessions'];
	}

	arsort( $totals );

	return dn_bfs_rest_envelope( array_slice( array_map( 'strval', array_keys( $totals ) ), 0, 20 ) );
}
```

- [ ] **Step 5: Nạp module** — mảng của `dn_burst_funnel_stats_load_admin()` thành `array('settings-model', 'rest-params', 'rest-reports')`.

- [ ] **Step 6: Chạy test**

Run tích hợp → Expected: tất cả PASS (thêm 6 test). Run unit + `php74` → OK.

- [ ] **Step 7: Commit**

```bash
git add includes/admin/rest-params.php includes/admin/rest-reports.php tests/integration/rest.php tests/integration/test-admin-rest-reports.php dn-burst-funnel-stats.php
git commit -m "feat(admin): add validated internal REST routes for reports

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Route preferences, settings, dữ liệu, GeoIP

**Files:**
- Create: `includes/admin/rest-settings.php`, `tests/integration/test-admin-rest-settings.php`
- Modify: `dn-burst-funnel-stats.php` (thêm `'rest-settings'`)

**Interfaces:**
- Consumes: Task 2 model, Task 3 helpers, `dn_bfs_mark_dirty_date()`, `dn_bfs_aggregate_run()`, `dn_bfs_last_closable_date()`, `dn_bfs_geoip_update()`, `dn_bfs_schema_tables()`.
- Produces:
  - `dn_bfs_dashboard_card_keys(): array` — `visitors, pageviews, sessions, new_returning, bounce_rate, avg_duration, pages_per_session, product_views, atc, checkouts, orders_aov, items_aoi, conversion_rate, sales_tip, paid_balance`.
  - `dn_bfs_get_user_cards( int $user_id ): array` (user meta `dnbfs_cards`, mặc định = mọi thẻ theo thứ tự trên).
  - `dn_bfs_data_stats(): array` — `tables` (`name => array( 'rows', 'bytes' )`, ước tính từ information_schema), `last_aggregated`, `raw_available_from`, `retention_days`, `dirty_dates` (int), `last_error` (array|null).
  - `dn_bfs_export_settings(): array`, `dn_bfs_import_settings( $payload ): array|WP_Error`.
  - Route: `GET|POST /admin/preferences` (body `{ "cards": [..] | null }`), `GET|POST /admin/settings/(?P<group>[a-z]+)` (body `{ "values": {...} }`), `GET /admin/data/stats`, `POST /admin/data/reaggregate` (`{ start, end }`, ≤ 92 ngày, ≤ ngày đóng sổ gần nhất), `POST /admin/data/purge` (`{ "confirm": "DELETE" }`), `GET /admin/data/export`, `POST /admin/data/import` (`{ "payload": {...} }`), `POST /admin/geoip/update`.

- [ ] **Step 1: Viết test fail — `tests/integration/test-admin-rest-settings.php`**

```php
<?php

require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/rest.php';

dn_bfs_it(
	'preferences store card order per user and reset to defaults',
	function () {
		$user = dn_bfs_it_login_admin();
		delete_user_meta( $user, 'dnbfs_cards' );

		$initial = dn_bfs_it_rest( 'GET', '/dnbfs/v1/admin/preferences' )->get_data();
		dn_bfs_assert_same( dn_bfs_dashboard_card_keys(), $initial['data']['cards'] );

		$saved = dn_bfs_it_rest( 'POST', '/dnbfs/v1/admin/preferences', array( 'cards' => array( 'orders_aov', 'visitors' ) ) )->get_data();
		dn_bfs_assert_same( array( 'orders_aov', 'visitors' ), $saved['data']['cards'] );

		$bad = dn_bfs_it_rest( 'POST', '/dnbfs/v1/admin/preferences', array( 'cards' => array( 'nope' ) ) );
		dn_bfs_assert_same( 'invalid_card', $bad->get_data()['code'] );

		$reset = dn_bfs_it_rest( 'POST', '/dnbfs/v1/admin/preferences', array( 'cards' => null ) )->get_data();
		dn_bfs_assert_same( dn_bfs_dashboard_card_keys(), $reset['data']['cards'] );
	}
);

dn_bfs_it(
	'settings routes read and save groups through the model',
	function () {
		dn_bfs_it_login_admin();

		$general = dn_bfs_it_rest( 'GET', '/dnbfs/v1/admin/settings/general' )->get_data();
		dn_bfs_assert_same( array( 'tracking_enabled', 'default_date_range', 'default_compare' ), array_keys( $general['data']['values'] ) );

		$values                       = $general['data']['values'];
		$values['default_date_range'] = 'yesterday';
		$saved                        = dn_bfs_it_rest( 'POST', '/dnbfs/v1/admin/settings/general', array( 'values' => $values ) );
		dn_bfs_assert_same( 200, $saved->get_status() );
		dn_bfs_assert_same( 'yesterday', $saved->get_data()['data']['values']['default_date_range'] );

		$missing = dn_bfs_it_rest( 'POST', '/dnbfs/v1/admin/settings/general', array( 'values' => array( 'tracking_enabled' => 1 ) ) );
		dn_bfs_assert_same( 400, $missing->get_status() );
		dn_bfs_assert_same( 'missing_keys', $missing->get_data()['code'] );

		dn_bfs_assert_same( 404, dn_bfs_it_rest( 'GET', '/dnbfs/v1/admin/settings/nope' )->get_status() );
	}
);

dn_bfs_it(
	'data stats, export and import round-trip settings without the license key',
	function () {
		dn_bfs_it_login_admin();
		dn_bfs_it_settings( array( 'maxmind_license_key' => 'SECRET_KEY', 'session_timeout' => 45 ) );

		$stats = dn_bfs_it_rest( 'GET', '/dnbfs/v1/admin/data/stats' )->get_data();
		dn_bfs_assert_true( isset( $stats['data']['tables']['sessions']['rows'] ), 'table stats' );

		$export = dn_bfs_it_rest( 'GET', '/dnbfs/v1/admin/data/export' )->get_data()['data'];
		dn_bfs_assert_same( 'dn-burst-funnel-stats', $export['meta']['plugin'] );
		dn_bfs_assert_true( ! isset( $export['settings']['tracking']['maxmind_license_key'] ), 'no license in export' );
		dn_bfs_assert_same( 45, $export['settings']['tracking']['session_timeout'] );

		dn_bfs_it_settings( array( 'maxmind_license_key' => 'SECRET_KEY', 'session_timeout' => 30 ) );
		$import = dn_bfs_it_rest( 'POST', '/dnbfs/v1/admin/data/import', array( 'payload' => $export ) );
		dn_bfs_assert_same( 200, $import->get_status() );
		dn_bfs_assert_same( 45, dn_bfs_get_tracking_settings()['session_timeout'] );
		dn_bfs_assert_same( 'SECRET_KEY', dn_bfs_get_tracking_settings()['maxmind_license_key'] );

		$bad = dn_bfs_it_rest( 'POST', '/dnbfs/v1/admin/data/import', array( 'payload' => array( 'meta' => array( 'plugin' => 'other' ) ) ) );
		dn_bfs_assert_same( 'invalid_import', $bad->get_data()['code'] );
	}
);

dn_bfs_it_today(
	'reaggregate marks dates dirty and runs the aggregator; purge needs confirmation',
	function () {
		dn_bfs_it_login_admin();
		dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 2 ) ) );

		$day      = wp_date( 'Y-m-d', dn_bfs_it_day_noon( 2 ) );
		$response = dn_bfs_it_rest( 'POST', '/dnbfs/v1/admin/data/reaggregate', array( 'start' => $day, 'end' => $day ) )->get_data();
		dn_bfs_assert_same( 1, $response['data']['queued'] );
		dn_bfs_assert_true( in_array( $day, $response['data']['result']['processed'], true ), 'processed' );

		$too_long = dn_bfs_it_rest( 'POST', '/dnbfs/v1/admin/data/reaggregate', array( 'start' => '2025-01-01', 'end' => '2025-12-31' ) );
		dn_bfs_assert_same( 'range_too_long', $too_long->get_data()['code'] );

		$refused = dn_bfs_it_rest( 'POST', '/dnbfs/v1/admin/data/purge', array( 'confirm' => 'yes' ) );
		dn_bfs_assert_same( 'confirm_required', $refused->get_data()['code'] );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'sessions' ) );

		$purged = dn_bfs_it_rest( 'POST', '/dnbfs/v1/admin/data/purge', array( 'confirm' => 'DELETE' ) );
		dn_bfs_assert_same( 200, $purged->get_status() );
		dn_bfs_assert_same( 0, dn_bfs_it_count( 'sessions' ) );
		dn_bfs_assert_same( 0, dn_bfs_it_count( 'daily' ) );
		dn_bfs_assert_true( false === get_option( 'dnbfs_last_aggregated_date' ), 'watermark reset' );
	}
);

dn_bfs_it(
	'geoip update requires a license key',
	function () {
		dn_bfs_it_login_admin();

		$response = dn_bfs_it_rest( 'POST', '/dnbfs/v1/admin/geoip/update' );
		dn_bfs_assert_same( 400, $response->get_status() );
		dn_bfs_assert_same( 'no_license', $response->get_data()['code'] );
	}
);
```

Run tích hợp → Expected: test mới FAIL (route 404).

- [ ] **Step 2: Tạo `includes/admin/rest-settings.php`**

```php
<?php
/**
 * Internal REST routes for preferences, settings, data tools and GeoIP.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_dashboard_card_keys() {
	return array( 'visitors', 'pageviews', 'sessions', 'new_returning', 'bounce_rate', 'avg_duration', 'pages_per_session', 'product_views', 'atc', 'checkouts', 'orders_aov', 'items_aoi', 'conversion_rate', 'sales_tip', 'paid_balance' );
}

function dn_bfs_get_user_cards( $user_id ) {
	$cards = get_user_meta( (int) $user_id, 'dnbfs_cards', true );

	return is_array( $cards ) ? array_values( array_intersect( $cards, dn_bfs_dashboard_card_keys() ) ) : dn_bfs_dashboard_card_keys();
}

function dn_bfs_register_admin_settings_routes() {
	$routes = array(
		array( '/admin/preferences', 'GET', 'dn_bfs_rest_admin_get_preferences' ),
		array( '/admin/preferences', 'POST', 'dn_bfs_rest_admin_save_preferences' ),
		array( '/admin/settings/(?P<group>[a-z]+)', 'GET', 'dn_bfs_rest_admin_get_settings' ),
		array( '/admin/settings/(?P<group>[a-z]+)', 'POST', 'dn_bfs_rest_admin_save_settings' ),
		array( '/admin/data/stats', 'GET', 'dn_bfs_rest_admin_data_stats' ),
		array( '/admin/data/reaggregate', 'POST', 'dn_bfs_rest_admin_reaggregate' ),
		array( '/admin/data/purge', 'POST', 'dn_bfs_rest_admin_purge' ),
		array( '/admin/data/export', 'GET', 'dn_bfs_rest_admin_export' ),
		array( '/admin/data/import', 'POST', 'dn_bfs_rest_admin_import' ),
		array( '/admin/geoip/update', 'POST', 'dn_bfs_rest_admin_geoip_update' ),
	);

	foreach ( $routes as $route ) {
		register_rest_route(
			'dnbfs/v1',
			$route[0],
			array(
				'methods'             => $route[1],
				'callback'            => $route[2],
				'permission_callback' => 'dn_bfs_admin_permission',
			)
		);
	}
}
add_action( 'rest_api_init', 'dn_bfs_register_admin_settings_routes' );

function dn_bfs_rest_body( WP_REST_Request $request ) {
	$body = $request->get_json_params();

	return is_array( $body ) ? $body : array();
}

function dn_bfs_rest_preferences_payload() {
	return array(
		'cards'     => dn_bfs_get_user_cards( get_current_user_id() ),
		'available' => dn_bfs_dashboard_card_keys(),
	);
}

function dn_bfs_rest_admin_get_preferences() {
	return dn_bfs_rest_envelope( dn_bfs_rest_preferences_payload() );
}

function dn_bfs_rest_admin_save_preferences( WP_REST_Request $request ) {
	$body = dn_bfs_rest_body( $request );

	if ( ! array_key_exists( 'cards', $body ) || null === $body['cards'] ) {
		delete_user_meta( get_current_user_id(), 'dnbfs_cards' );

		return dn_bfs_rest_envelope( dn_bfs_rest_preferences_payload() );
	}

	if ( ! is_array( $body['cards'] ) ) {
		return dn_bfs_rest_error( 'invalid_card', __( 'Cards must be a list.', 'dn-burst-funnel-stats' ) );
	}

	foreach ( $body['cards'] as $card ) {
		if ( ! is_string( $card ) || ! in_array( $card, dn_bfs_dashboard_card_keys(), true ) ) {
			return dn_bfs_rest_error( 'invalid_card', __( 'Unknown dashboard card.', 'dn-burst-funnel-stats' ) );
		}
	}

	update_user_meta( get_current_user_id(), 'dnbfs_cards', array_values( array_unique( $body['cards'] ) ) );

	return dn_bfs_rest_envelope( dn_bfs_rest_preferences_payload() );
}

function dn_bfs_rest_admin_get_settings( WP_REST_Request $request ) {
	$group = dn_bfs_get_settings_group( (string) $request['group'] );

	return is_wp_error( $group ) ? $group : dn_bfs_rest_envelope( $group );
}

function dn_bfs_rest_admin_save_settings( WP_REST_Request $request ) {
	$body  = dn_bfs_rest_body( $request );
	$saved = dn_bfs_save_settings_group( (string) $request['group'], isset( $body['values'] ) ? $body['values'] : null );

	return is_wp_error( $saved ) ? $saved : dn_bfs_rest_envelope( $saved );
}

function dn_bfs_data_stats() {
	global $wpdb;

	$tables = array();

	foreach ( dn_bfs_schema_tables() as $name ) {
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT TABLE_ROWS AS rows_estimate, DATA_LENGTH + INDEX_LENGTH AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				dn_bfs_table( $name )
			),
			ARRAY_A
		);

		$tables[ $name ] = array(
			'rows'  => $row ? (int) $row['rows_estimate'] : 0,
			'bytes' => $row ? (int) $row['bytes'] : 0,
		);
	}

	$settings = dn_bfs_get_tracking_settings();
	$error    = get_option( 'dnbfs_aggregate_last_error', null );

	return array(
		'tables'             => $tables,
		'last_aggregated'    => (string) get_option( 'dnbfs_last_aggregated_date', '' ),
		'raw_available_from' => dn_bfs_raw_available_from( dn_bfs_now() ),
		'retention_days'     => (int) $settings['raw_retention_days'],
		'dirty_dates'        => count( dn_bfs_get_dirty_dates() ),
		'last_error'         => is_array( $error ) ? $error : null,
	);
}

function dn_bfs_rest_admin_data_stats() {
	return dn_bfs_rest_envelope( dn_bfs_data_stats() );
}

function dn_bfs_rest_admin_reaggregate( WP_REST_Request $request ) {
	$body  = dn_bfs_rest_body( $request );
	$start = isset( $body['start'] ) ? $body['start'] : '';
	$end   = isset( $body['end'] ) ? $body['end'] : '';

	if ( ! dn_bfs_rest_valid_date( $start ) || ! dn_bfs_rest_valid_date( $end ) || $start > $end ) {
		return dn_bfs_rest_error( 'invalid_date', __( 'Use valid start and end dates in YYYY-MM-DD format.', 'dn-burst-funnel-stats' ) );
	}

	$dates = dn_bfs_dates_between( $start, $end );

	if ( count( $dates ) > 92 ) {
		return dn_bfs_rest_error( 'range_too_long', __( 'Re-aggregate at most 92 days at a time.', 'dn-burst-funnel-stats' ) );
	}

	$closable = dn_bfs_last_closable_date( dn_bfs_now() );
	$queued   = 0;

	foreach ( $dates as $date ) {
		if ( $date <= $closable ) {
			dn_bfs_mark_dirty_date( $date );
			$queued++;
		}
	}

	return dn_bfs_rest_envelope(
		array(
			'queued' => $queued,
			'result' => dn_bfs_aggregate_run(),
		)
	);
}

function dn_bfs_rest_admin_purge( WP_REST_Request $request ) {
	global $wpdb;

	$body = dn_bfs_rest_body( $request );

	if ( ! isset( $body['confirm'] ) || 'DELETE' !== $body['confirm'] ) {
		return dn_bfs_rest_error( 'confirm_required', __( 'Type DELETE to confirm.', 'dn-burst-funnel-stats' ) );
	}

	foreach ( array( 'visitors', 'sessions', 'pageviews', 'events', 'daily' ) as $name ) {
		$wpdb->query( 'TRUNCATE TABLE ' . dn_bfs_table( $name ) );
	}

	foreach ( array( 'dnbfs_last_aggregated_date', 'dnbfs_aggregate_last_error', 'dnbfs_aggregate_lock' ) as $option ) {
		delete_option( $option );
	}

	foreach ( dn_bfs_get_dirty_dates() as $date ) {
		delete_option( 'dnbfs_dirty_' . $date );
	}

	return dn_bfs_rest_envelope( array( 'purged' => true ) );
}

function dn_bfs_export_settings() {
	$tracking = dn_bfs_get_tracking_settings();
	unset( $tracking['maxmind_license_key'], $tracking['invalid_excluded_ips'] );

	return array(
		'meta'     => array(
			'plugin'         => 'dn-burst-funnel-stats',
			'plugin_version' => DN_BURST_FUNNEL_STATS_VERSION,
			'schema_version' => DN_BURST_FUNNEL_STATS_SCHEMA_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'site_url'       => home_url(),
		),
		'settings' => array(
			'tracking'           => $tracking,
			'woocommerce_report' => dn_bfs_get_wc_report_settings(),
		),
	);
}

function dn_bfs_import_settings( $payload ) {
	if ( ! is_array( $payload ) || ! isset( $payload['meta']['plugin'] ) || 'dn-burst-funnel-stats' !== $payload['meta']['plugin'] || ! isset( $payload['settings'] ) || ! is_array( $payload['settings'] ) ) {
		return dn_bfs_rest_error( 'invalid_import', __( 'This file is not a DN Burst Funnel Stats export.', 'dn-burst-funnel-stats' ) );
	}

	$imported = array();

	if ( isset( $payload['settings']['tracking'] ) && is_array( $payload['settings']['tracking'] ) ) {
		$current  = dn_bfs_get_tracking_settings();
		$incoming = $payload['settings']['tracking'];
		unset( $incoming['maxmind_license_key'] );

		update_option( 'dn_burst_funnel_stats_tracking_settings', dn_bfs_sanitize_tracking_settings( array_merge( $current, $incoming ) ), false );
		$imported[] = 'tracking';
	}

	if ( isset( $payload['settings']['woocommerce_report'] ) && is_array( $payload['settings']['woocommerce_report'] ) ) {
		update_option( 'dn_burst_funnel_stats_wc_report_settings', dn_bfs_sanitize_wc_report_settings( $payload['settings']['woocommerce_report'] ), false );
		$imported[] = 'woocommerce_report';
	}

	return array( 'imported' => $imported );
}

function dn_bfs_rest_admin_export() {
	return dn_bfs_rest_envelope( dn_bfs_export_settings() );
}

function dn_bfs_rest_admin_import( WP_REST_Request $request ) {
	$body   = dn_bfs_rest_body( $request );
	$result = dn_bfs_import_settings( isset( $body['payload'] ) ? $body['payload'] : null );

	return is_wp_error( $result ) ? $result : dn_bfs_rest_envelope( $result );
}

function dn_bfs_rest_admin_geoip_update() {
	$settings = dn_bfs_get_tracking_settings();

	if ( '' === $settings['maxmind_license_key'] ) {
		return dn_bfs_rest_error( 'no_license', __( 'Add a MaxMind license key first.', 'dn-burst-funnel-stats' ) );
	}

	$result           = dn_bfs_geoip_update( $settings['maxmind_license_key'] );
	$result['status'] = dn_bfs_geoip_status();

	return dn_bfs_rest_envelope( $result );
}
```

- [ ] **Step 3: Nạp module** — thêm `'rest-settings'` vào mảng `dn_burst_funnel_stats_load_admin()`.

- [ ] **Step 4: Chạy test**

Run tích hợp → Expected: tất cả PASS (thêm 5 test). Run unit + `php74` → OK.

- [ ] **Step 5: Commit**

```bash
git add includes/admin/rest-settings.php tests/integration/test-admin-rest-settings.php dn-burst-funnel-stats.php
git commit -m "feat(admin): add REST routes for preferences, settings, data tools and GeoIP

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Trạng thái hệ thống

**Files:**
- Create: `includes/admin/system-status.php`, `tests/integration/test-system-status.php`
- Modify: `includes/tracking/collector.php` (header kiểm tra), `dn-burst-funnel-stats.php` (thêm `'system-status'`)

**Interfaces:**
- Consumes: `dn_bfs_schema_tables_exist()`, `dn_bfs_last_closable_date()`, `dn_bfs_geoip_status()`, `dn_bfs_geo_db_path()`, `dn_bfs_get_tracking_settings()`.
- Produces:
  - Collector: request có header `X-DNBFS-Check: 1` → `200 {"ok":true}`, không ghi gì, không đếm bị chặn.
  - `dn_bfs_status_check( string $key, string $label, string $status, string $detail ): array` (`status` ∈ `ok|warning|error|info`).
  - `dn_bfs_system_status( $now = null ): array` — danh sách check theo thứ tự khóa: `tables`, `schema`, `aggregation`, `cron`, `collect`, `tracker`, `geoip`, `geoip_public`, `proxy`, `versions`.
  - Route `GET /admin/system/status` → envelope `data` = danh sách check.

- [ ] **Step 1: Viết test fail — `tests/integration/test-system-status.php`**

```php
<?php

require_once __DIR__ . '/rest.php';

function dn_bfs_it_status_by_key( $checks ) {
	$by_key = array();

	foreach ( $checks as $check ) {
		$by_key[ $check['key'] ] = $check;
	}

	return $by_key;
}

function dn_bfs_it_mock_loopback( $collect_ok = true, $tracker_ok = true ) {
	$GLOBALS['dn_bfs_it_loopback'] = function ( $pre, $args, $url ) use ( $collect_ok, $tracker_ok ) {
		$ok = array( 'headers' => array(), 'cookies' => array(), 'filename' => null, 'response' => array( 'code' => 200, 'message' => 'OK' ) );

		if ( false !== strpos( $url, '/dnbfs/v1/collect' ) ) {
			return $collect_ok ? array_merge( $ok, array( 'body' => '{"ok":true}' ) ) : new WP_Error( 'http_request_failed', 'Connection refused' );
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

function dn_bfs_it_unmock_loopback() {
	remove_filter( 'pre_http_request', $GLOBALS['dn_bfs_it_loopback'], 10 );
}

dn_bfs_it(
	'collector answers the status check header without recording anything',
	function () {
		$response = dn_bfs_it_collect( array(), array( 'x_dnbfs_check' => '1' ) );

		dn_bfs_assert_same( 200, $response->get_status() );
		dn_bfs_assert_same( array( 'ok' => true ), $response->get_data() );
		dn_bfs_assert_same( 0, dn_bfs_it_count( 'daily' ) );
	}
);

dn_bfs_it_today(
	'system status reports healthy checks when everything works',
	function () {
		dn_bfs_it_mock_loopback();
		dn_bfs_schedule_crons();
		update_option( 'dnbfs_last_aggregated_date', dn_bfs_last_closable_date( dn_bfs_now() ), false );
		delete_option( 'dnbfs_aggregate_last_error' );

		$checks = dn_bfs_it_status_by_key( dn_bfs_system_status() );

		dn_bfs_assert_same( array( 'tables', 'schema', 'aggregation', 'cron', 'collect', 'tracker', 'geoip', 'geoip_public', 'proxy', 'versions' ), array_keys( $checks ) );
		dn_bfs_assert_same( 'ok', $checks['tables']['status'] );
		dn_bfs_assert_same( 'ok', $checks['schema']['status'] );
		dn_bfs_assert_same( 'ok', $checks['aggregation']['status'] );
		dn_bfs_assert_same( 'ok', $checks['collect']['status'] );
		dn_bfs_assert_same( 'ok', $checks['tracker']['status'] );
		dn_bfs_assert_same( 'info', $checks['versions']['status'] );

		dn_bfs_it_unmock_loopback();
	}
);

dn_bfs_it_today(
	'system status flags aggregation lag, aggregation errors and a blocked endpoint',
	function () {
		dn_bfs_it_mock_loopback( false, false );

		update_option( 'dnbfs_last_aggregated_date', dn_bfs_date_shift( dn_bfs_last_closable_date( dn_bfs_now() ), -5 ), false );
		$checks = dn_bfs_it_status_by_key( dn_bfs_system_status() );
		dn_bfs_assert_same( 'error', $checks['aggregation']['status'] );
		dn_bfs_assert_same( 'error', $checks['collect']['status'] );
		dn_bfs_assert_same( 'warning', $checks['tracker']['status'] );

		update_option( 'dnbfs_last_aggregated_date', dn_bfs_last_closable_date( dn_bfs_now() ), false );
		update_option( 'dnbfs_aggregate_last_error', array( 'date' => '2026-01-01', 'message' => 'Deadlock', 'time' => time() ), false );
		$checks = dn_bfs_it_status_by_key( dn_bfs_system_status() );
		dn_bfs_assert_same( 'error', $checks['aggregation']['status'] );
		dn_bfs_assert_true( false !== strpos( $checks['aggregation']['detail'], 'Deadlock' ), 'error detail' );

		delete_option( 'dnbfs_aggregate_last_error' );
		dn_bfs_it_unmock_loopback();
	}
);

dn_bfs_it(
	'system status flags proxy setups that hide the client IP',
	function () {
		dn_bfs_it_mock_loopback();
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';

		$checks = dn_bfs_it_status_by_key( dn_bfs_system_status() );
		dn_bfs_assert_same( 'warning', $checks['proxy']['status'] );

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		dn_bfs_it_unmock_loopback();
	}
);

dn_bfs_it(
	'system status route is admin-only',
	function () {
		dn_bfs_it_mock_loopback();
		dn_bfs_assert_same( 401, dn_bfs_it_rest( 'GET', '/dnbfs/v1/admin/system/status' )->get_status() );

		dn_bfs_it_login_admin();
		$response = dn_bfs_it_rest( 'GET', '/dnbfs/v1/admin/system/status' );
		dn_bfs_assert_same( 200, $response->get_status() );
		dn_bfs_assert_same( 10, count( $response->get_data()['data'] ) );
		dn_bfs_it_unmock_loopback();
	}
);
```

Run tích hợp → Expected: test mới FAIL.

- [ ] **Step 2: Header kiểm tra trong collector** — trong `includes/tracking/collector.php`, ở đầu thân `dn_bfs_rest_collect()` (trước `$now = dn_bfs_now();`) thêm:

```php
	// Loopback health check from the System status screen: answer without recording anything.
	if ( '1' === (string) $request->get_header( 'x_dnbfs_check' ) ) {
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
```

- [ ] **Step 3: Tạo `includes/admin/system-status.php`**

```php
<?php
/**
 * System status checks for the Settings → System screen.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_status_check( $key, $label, $status, $detail ) {
	return array(
		'key'    => $key,
		'label'  => $label,
		'status' => $status,
		'detail' => $detail,
	);
}

function dn_bfs_status_tables() {
	global $wpdb;

	if ( ! dn_bfs_schema_tables_exist() ) {
		return dn_bfs_status_check( 'tables', __( 'Database tables', 'dn-burst-funnel-stats' ), 'error', __( 'Some tracking tables are missing. Deactivate and reactivate the plugin.', 'dn-burst-funnel-stats' ) );
	}

	$engine = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', dn_bfs_table( 'daily' ) ) );

	if ( 'innodb' !== strtolower( $engine ) ) {
		/* translators: %s: storage engine. */
		return dn_bfs_status_check( 'tables', __( 'Database tables', 'dn-burst-funnel-stats' ), 'warning', sprintf( __( 'Tables use %s; InnoDB is needed for safe daily rebuilds.', 'dn-burst-funnel-stats' ), $engine ) );
	}

	return dn_bfs_status_check( 'tables', __( 'Database tables', 'dn-burst-funnel-stats' ), 'ok', __( 'All tables exist (InnoDB).', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_status_schema() {
	$installed = (string) get_option( 'dn_burst_funnel_stats_schema_version', '' );
	$ok        = DN_BURST_FUNNEL_STATS_SCHEMA_VERSION === $installed;

	/* translators: 1: installed schema version, 2: expected schema version. */
	return dn_bfs_status_check( 'schema', __( 'Schema version', 'dn-burst-funnel-stats' ), $ok ? 'ok' : 'error', sprintf( __( 'Installed %1$s, expected %2$s.', 'dn-burst-funnel-stats' ), $installed, DN_BURST_FUNNEL_STATS_SCHEMA_VERSION ) );
}

function dn_bfs_status_aggregation( $now ) {
	$label = __( 'Daily aggregation', 'dn-burst-funnel-stats' );
	$error = get_option( 'dnbfs_aggregate_last_error', null );

	if ( is_array( $error ) && ! empty( $error['message'] ) ) {
		/* translators: 1: date, 2: database error message. */
		return dn_bfs_status_check( 'aggregation', $label, 'error', sprintf( __( 'Last run failed on %1$s: %2$s', 'dn-burst-funnel-stats' ), isset( $error['date'] ) ? $error['date'] : '', $error['message'] ) );
	}

	$last = (string) get_option( 'dnbfs_last_aggregated_date', '' );

	if ( '' === $last ) {
		return dn_bfs_status_check( 'aggregation', $label, 'warning', __( 'The aggregator has not run yet.', 'dn-burst-funnel-stats' ) );
	}

	$closable = dn_bfs_last_closable_date( $now );
	$lag      = $last >= $closable ? 0 : count( dn_bfs_dates_between( dn_bfs_date_shift( $last, 1 ), $closable ) );
	$status   = 0 === $lag ? 'ok' : ( $lag <= 2 ? 'warning' : 'error' );

	/* translators: 1: last aggregated date, 2: number of days behind. */
	return dn_bfs_status_check( 'aggregation', $label, $status, sprintf( __( 'Aggregated through %1$s (%2$d days behind).', 'dn-burst-funnel-stats' ), $last, $lag ) );
}

function dn_bfs_status_cron() {
	$label     = __( 'Scheduled tasks', 'dn-burst-funnel-stats' );
	$scheduled = wp_next_scheduled( 'dnbfs_aggregate' ) && wp_next_scheduled( 'dnbfs_cleanup' );

	if ( ! $scheduled ) {
		return dn_bfs_status_check( 'cron', $label, 'error', __( 'Aggregation or cleanup is not scheduled.', 'dn-burst-funnel-stats' ) );
	}

	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
		return dn_bfs_status_check( 'cron', $label, 'warning', __( 'WP-Cron is disabled. Make sure a system cron calls wp-cron.php every few minutes.', 'dn-burst-funnel-stats' ) );
	}

	return dn_bfs_status_check( 'cron', $label, 'ok', __( 'Aggregation runs hourly and cleanup daily.', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_status_collect() {
	$label    = __( 'Tracking endpoint', 'dn-burst-funnel-stats' );
	$response = wp_remote_post(
		rest_url( 'dnbfs/v1/collect' ),
		array(
			'timeout' => 10,
			'headers' => array( 'X-DNBFS-Check' => '1' ),
			'body'    => '',
		)
	);

	if ( is_wp_error( $response ) ) {
		return dn_bfs_status_check( 'collect', $label, 'error', $response->get_error_message() );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || empty( $body['ok'] ) ) {
		/* translators: %d: HTTP status code. */
		return dn_bfs_status_check( 'collect', $label, 'error', sprintf( __( 'The endpoint answered with HTTP %d. A security plugin or firewall may block the REST API.', 'dn-burst-funnel-stats' ), (int) wp_remote_retrieve_response_code( $response ) ) );
	}

	return dn_bfs_status_check( 'collect', $label, 'ok', __( 'The REST endpoint is reachable.', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_status_tracker() {
	$label    = __( 'Tracker script', 'dn-burst-funnel-stats' );
	$settings = dn_bfs_get_tracking_settings();

	if ( empty( $settings['tracking_enabled'] ) ) {
		return dn_bfs_status_check( 'tracker', $label, 'warning', __( 'Tracking is turned off in General settings.', 'dn-burst-funnel-stats' ) );
	}

	$response = wp_remote_get( home_url( '/' ), array( 'timeout' => 10 ) );

	if ( ! is_wp_error( $response ) && false !== strpos( wp_remote_retrieve_body( $response ), 'window.dnbfsPage' ) ) {
		return dn_bfs_status_check( 'tracker', $label, 'ok', __( 'The tracker is present on the home page.', 'dn-burst-funnel-stats' ) );
	}

	return dn_bfs_status_check( 'tracker', $label, 'warning', __( 'The tracker was not found on the home page. Clear your page cache and check that scripts are not blocked.', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_status_geoip() {
	$label  = __( 'GeoIP database', 'dn-burst-funnel-stats' );
	$status = dn_bfs_geoip_status();

	if ( '' !== $status['last_error'] ) {
		/* translators: %s: error code. */
		return dn_bfs_status_check( 'geoip', $label, 'warning', sprintf( __( 'Last update failed: %s.', 'dn-burst-funnel-stats' ), $status['last_error'] ) );
	}

	if ( $status['database'] ) {
		/* translators: %s: date. */
		return dn_bfs_status_check( 'geoip', $label, 'ok', sprintf( __( 'Updated %s.', 'dn-burst-funnel-stats' ), $status['updated_at'] ? wp_date( get_option( 'date_format' ), $status['updated_at'] ) : '—' ) );
	}

	if ( $status['license_set'] ) {
		return dn_bfs_status_check( 'geoip', $label, 'warning', __( 'A license key is set but the database has not been downloaded yet.', 'dn-burst-funnel-stats' ) );
	}

	return dn_bfs_status_check( 'geoip', $label, 'info', __( 'No MaxMind database. Countries come from Cloudflare headers only.', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_status_geoip_public() {
	$label = __( 'GeoIP file protection', 'dn-burst-funnel-stats' );

	if ( ! file_exists( dn_bfs_geo_db_path() ) ) {
		return dn_bfs_status_check( 'geoip_public', $label, 'info', __( 'No database file to protect.', 'dn-burst-funnel-stats' ) );
	}

	$uploads  = wp_upload_dir( null, false );
	$response = wp_remote_head( trailingslashit( $uploads['baseurl'] ) . 'dnbfs/GeoLite2-City.mmdb', array( 'timeout' => 10 ) );

	if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
		return dn_bfs_status_check( 'geoip_public', $label, 'warning', __( 'The GeoIP file can be downloaded publicly. Block uploads/dnbfs in your web server configuration.', 'dn-burst-funnel-stats' ) );
	}

	return dn_bfs_status_check( 'geoip_public', $label, 'ok', __( 'The GeoIP file is not publicly downloadable.', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_status_proxy() {
	$label    = __( 'Visitor IP detection', 'dn-burst-funnel-stats' );
	$settings = dn_bfs_get_tracking_settings();
	$remote   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	$private  = '' !== $remote && false === filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	$proxied  = ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) || ! empty( $_SERVER['HTTP_X_REAL_IP'] );
	$via_cf   = ! empty( $_SERVER['HTTP_CF_RAY'] ) && 'auto' === $settings['client_ip_source'];

	if ( $private && $proxied && ! $via_cf && in_array( $settings['client_ip_source'], array( 'auto', 'remote_addr' ), true ) ) {
		return dn_bfs_status_check( 'proxy', $label, 'warning', __( 'The site is behind a proxy, so every visitor appears to share one IP. Set the client IP source to X-Forwarded-For or X-Real-IP in Tracking settings.', 'dn-burst-funnel-stats' ) );
	}

	return dn_bfs_status_check( 'proxy', $label, 'ok', __( 'Visitor IPs are detected correctly.', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_status_versions() {
	global $wpdb;

	$detail = sprintf(
		'Plugin %1$s · WordPress %2$s · WooCommerce %3$s · PHP %4$s · MySQL %5$s',
		DN_BURST_FUNNEL_STATS_VERSION,
		get_bloginfo( 'version' ),
		defined( 'WC_VERSION' ) ? WC_VERSION : '—',
		PHP_VERSION,
		$wpdb->db_version()
	);

	return dn_bfs_status_check( 'versions', __( 'Versions', 'dn-burst-funnel-stats' ), 'info', $detail );
}

function dn_bfs_system_status( $now = null ) {
	$now = null === $now ? dn_bfs_now() : (int) $now;

	return array(
		dn_bfs_status_tables(),
		dn_bfs_status_schema(),
		dn_bfs_status_aggregation( $now ),
		dn_bfs_status_cron(),
		dn_bfs_status_collect(),
		dn_bfs_status_tracker(),
		dn_bfs_status_geoip(),
		dn_bfs_status_geoip_public(),
		dn_bfs_status_proxy(),
		dn_bfs_status_versions(),
	);
}

function dn_bfs_register_system_status_route() {
	register_rest_route(
		'dnbfs/v1',
		'/admin/system/status',
		array(
			'methods'             => 'GET',
			'callback'            => 'dn_bfs_rest_admin_system_status',
			'permission_callback' => 'dn_bfs_admin_permission',
		)
	);
}
add_action( 'rest_api_init', 'dn_bfs_register_system_status_route' );

function dn_bfs_rest_admin_system_status() {
	return dn_bfs_rest_envelope( dn_bfs_system_status() );
}
```

- [ ] **Step 4: Nạp module** — thêm `'system-status'` vào mảng `dn_burst_funnel_stats_load_admin()`.

- [ ] **Step 5: Chạy test**

Run tích hợp → Expected: tất cả PASS (thêm 5 test). Run unit + `php74` → OK.

- [ ] **Step 6: Commit**

```bash
git add includes/admin/system-status.php includes/tracking/collector.php tests/integration/test-system-status.php dn-burst-funnel-stats.php
git commit -m "feat(admin): add system status checks and loopback health header

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Trang admin mới, gỡ dashboard cũ, migration schema 6

**Files:**
- Create: `includes/admin/pages.php`, `tests/integration/test-admin-pages.php`
- Delete: `includes/dashboard.php`, `includes/ajax.php`, `includes/admin-menu.php`, `includes/import-export.php`, `includes/settings.php`, `assets/admin.js`, `assets/admin.css`
- Modify: `dn-burst-funnel-stats.php`, `includes/tracking.php` (gỡ hàm cũ không còn ai gọi), `tests/integration/test-schema.php` (schema `6`)

**Interfaces:**
- Consumes: Task 1 bundle, Task 2–5.
- Produces:
  - `dn_bfs_admin_pages(): array` (`slug => page`), `dn_bfs_current_admin_page(): string` (`''` nếu không phải trang plugin).
  - `dn_bfs_register_admin_menu()`, `dn_bfs_render_admin_page()` (in `<div class="wrap dnbfs-wrap"><div id="dnbfs-app" data-page="dashboard|settings"></div></div>`).
  - `dn_bfs_admin_config(): array` → `window.dnbfsAdmin` gồm `page, restNamespace ('dnbfs/v1'), currency, currencySymbol, locale, timezone, defaults {period, compare}, presets, dimensions, filterDimensions, metrics, cards, settingsGroups, version, adminUrl`.
  - `dn_bfs_enqueue_admin_app( $hook )` (handle `dnbfs-admin`, style `wp-components`).
  - `DN_BURST_FUNNEL_STATS_SCHEMA_VERSION = '6'`; `dn_bfs_migrate_legacy_cleanup()` xóa option `dn_atc_*`, transient `dn_atc_*` và `dn_bfs_*`, `dn_bfs_data_last_changed`, `dn_burst_funnel_stats_last_refresh`, `dn_burst_funnel_stats_url_tracking_settings`, hủy cron `dn_burst_funnel_stats_refresh_cache`.

- [ ] **Step 1: Viết test fail — `tests/integration/test-admin-pages.php`**

```php
<?php

require_once __DIR__ . '/rest.php';

dn_bfs_it(
	'admin menu registers dashboard and settings pages',
	function () {
		global $submenu;

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		dn_bfs_it_login_admin();
		dn_bfs_register_admin_menu();

		$slugs = array_column( $submenu['dn-burst-funnel-stats'], 2 );
		dn_bfs_assert_same( array( 'dn-burst-funnel-stats', 'dn-burst-funnel-stats-settings' ), $slugs );
	}
);

dn_bfs_it(
	'admin page renders the mount point and enqueues the app with config',
	function () {
		dn_bfs_it_login_admin();
		$_GET['page'] = 'dn-burst-funnel-stats-settings';

		ob_start();
		dn_bfs_render_admin_page();
		$html = ob_get_clean();
		dn_bfs_assert_true( false !== strpos( $html, 'id="dnbfs-app" data-page="settings"' ), 'mount point' );

		dn_bfs_enqueue_admin_app( 'funnel-stats_page_dn-burst-funnel-stats-settings' );
		dn_bfs_assert_true( wp_script_is( 'dnbfs-admin', 'enqueued' ), 'script enqueued' );

		$inline = implode( '', (array) wp_scripts()->get_data( 'dnbfs-admin', 'before' ) );
		dn_bfs_assert_true( false !== strpos( $inline, '"restNamespace":"dnbfs\/v1"' ), 'config printed' );

		$config = dn_bfs_admin_config();
		dn_bfs_assert_same( 'settings', $config['page'] );
		dn_bfs_assert_same( dn_bfs_dashboard_card_keys(), $config['cards'] );
		dn_bfs_assert_same( array_keys( dn_bfs_settings_groups() ), $config['settingsGroups'] );

		$_GET = array();
		wp_dequeue_script( 'dnbfs-admin' );
	}
);

dn_bfs_it(
	'app assets are not loaded on other admin pages',
	function () {
		dn_bfs_it_login_admin();
		$_GET['page'] = 'woocommerce';
		wp_dequeue_script( 'dnbfs-admin' );

		dn_bfs_enqueue_admin_app( 'woocommerce_page_wc-admin' );
		dn_bfs_assert_true( ! wp_script_is( 'dnbfs-admin', 'enqueued' ), 'not enqueued' );
		$_GET = array();
	}
);

dn_bfs_it(
	'schema 6 migration removes legacy Burst-era data',
	function () {
		update_option( 'dn_atc_hits_2026_01_01', 5, false );
		update_option( 'dn_bfs_data_last_changed', '1', false );
		update_option( 'dn_burst_funnel_stats_last_refresh', time(), false );
		update_option( 'dn_burst_funnel_stats_url_tracking_settings', array( 'default_group' => 'campaign' ), false );
		set_transient( 'dn_bfs_dash_test', 1, 60 );
		wp_schedule_event( time() + 60, 'hourly', 'dn_burst_funnel_stats_refresh_cache' );
		update_option( 'dn_burst_funnel_stats_schema_version', '5', false );

		dn_burst_funnel_stats_maybe_migrate();

		dn_bfs_assert_same( '6', get_option( 'dn_burst_funnel_stats_schema_version' ) );
		dn_bfs_assert_true( false === get_option( 'dn_atc_hits_2026_01_01' ), 'atc option' );
		dn_bfs_assert_true( false === get_option( 'dn_bfs_data_last_changed' ), 'cache version' );
		dn_bfs_assert_true( false === get_option( 'dn_burst_funnel_stats_last_refresh' ), 'refresh time' );
		dn_bfs_assert_true( false === get_option( 'dn_burst_funnel_stats_url_tracking_settings' ), 'url tracking settings' );
		dn_bfs_assert_true( false === get_transient( 'dn_bfs_dash_test' ), 'legacy transient' );
		dn_bfs_assert_true( false === wp_next_scheduled( 'dn_burst_funnel_stats_refresh_cache' ), 'legacy cron' );
	}
);

dn_bfs_it(
	'legacy dashboard code is gone',
	function () {
		foreach ( array( 'dn_burst_dash_render_page', 'dn_burst_funnel_stats_ajax_load_tab', 'dn_burst_funnel_stats_render_settings_page', 'dn_burst_funnel_stats_render_import_export_page' ) as $function ) {
			dn_bfs_assert_true( ! function_exists( $function ), $function );
		}

		dn_bfs_assert_true( ! file_exists( DN_BURST_FUNNEL_STATS_PATH . 'assets/admin.js' ), 'old admin.js removed' );
	}
);
```

Đồng thời sửa `tests/integration/test-schema.php`: mọi chỗ kỳ vọng phiên bản schema đang là chuỗi cố định (`'4'`/`'5'`) đổi thành so với `DN_BURST_FUNNEL_STATS_SCHEMA_VERSION`.

Run tích hợp → Expected: test mới FAIL (`Call to undefined function dn_bfs_register_admin_menu()`).

- [ ] **Step 2: Tạo `includes/admin/pages.php`**

```php
<?php
/**
 * Admin menu, mount pages and the React app bundle.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_admin_pages() {
	return array(
		'dn-burst-funnel-stats'          => 'dashboard',
		'dn-burst-funnel-stats-settings' => 'settings',
	);
}

function dn_bfs_current_admin_page() {
	$slug  = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$pages = dn_bfs_admin_pages();

	return isset( $pages[ $slug ] ) ? $pages[ $slug ] : '';
}

function dn_bfs_register_admin_menu() {
	$capability = dn_bfs_admin_capability();

	add_menu_page( __( 'Funnel Stats', 'dn-burst-funnel-stats' ), __( 'Funnel Stats', 'dn-burst-funnel-stats' ), $capability, 'dn-burst-funnel-stats', 'dn_bfs_render_admin_page', 'dashicons-chart-area', 56 );
	add_submenu_page( 'dn-burst-funnel-stats', __( 'Dashboard', 'dn-burst-funnel-stats' ), __( 'Dashboard', 'dn-burst-funnel-stats' ), $capability, 'dn-burst-funnel-stats', 'dn_bfs_render_admin_page' );
	add_submenu_page( 'dn-burst-funnel-stats', __( 'Settings', 'dn-burst-funnel-stats' ), __( 'Settings', 'dn-burst-funnel-stats' ), $capability, 'dn-burst-funnel-stats-settings', 'dn_bfs_render_admin_page' );
}
add_action( 'admin_menu', 'dn_bfs_register_admin_menu' );

function dn_bfs_render_admin_page() {
	if ( ! dn_bfs_admin_permission() ) {
		return;
	}

	$page = dn_bfs_current_admin_page();

	printf( '<div class="wrap dnbfs-wrap"><div id="dnbfs-app" data-page="%s"></div></div>', esc_attr( '' !== $page ? $page : 'dashboard' ) );
}

function dn_bfs_admin_nocache() {
	if ( '' !== dn_bfs_current_admin_page() && ! headers_sent() ) {
		nocache_headers();
	}
}
add_action( 'admin_init', 'dn_bfs_admin_nocache' );

function dn_bfs_admin_config() {
	$settings = dn_bfs_get_tracking_settings();
	$page     = dn_bfs_current_admin_page();

	return array(
		'page'             => '' !== $page ? $page : 'dashboard',
		'restNamespace'    => 'dnbfs/v1',
		'currency'         => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
		'currencySymbol'   => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '',
		'locale'           => str_replace( '_', '-', get_user_locale() ),
		'timezone'         => wp_timezone_string(),
		'defaults'         => array(
			'period'  => $settings['default_date_range'],
			'compare' => $settings['default_compare'],
		),
		'presets'          => dn_bfs_get_date_presets(),
		'dimensions'       => dn_bfs_report_dimensions(),
		'filterDimensions' => dn_bfs_filter_dimensions(),
		'metrics'          => array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() ),
		'cards'            => dn_bfs_dashboard_card_keys(),
		'settingsGroups'   => array_keys( dn_bfs_settings_groups() ),
		'version'          => DN_BURST_FUNNEL_STATS_VERSION,
		'adminUrl'         => admin_url( 'admin.php' ),
	);
}

function dn_bfs_enqueue_admin_app( $hook_suffix ) {
	unset( $hook_suffix );

	if ( '' === dn_bfs_current_admin_page() ) {
		return;
	}

	$asset_path = DN_BURST_FUNNEL_STATS_PATH . 'build/index.asset.php';

	if ( ! file_exists( $asset_path ) ) {
		return;
	}

	$asset = include $asset_path;

	wp_enqueue_script( 'dnbfs-admin', DN_BURST_FUNNEL_STATS_URL . 'build/index.js', $asset['dependencies'], $asset['version'], true );
	wp_set_script_translations( 'dnbfs-admin', 'dn-burst-funnel-stats' );
	wp_add_inline_script( 'dnbfs-admin', 'window.dnbfsAdmin=' . wp_json_encode( dn_bfs_admin_config() ) . ';', 'before' );
	wp_enqueue_style( 'wp-components' );

	if ( file_exists( DN_BURST_FUNNEL_STATS_PATH . 'build/index.css' ) ) {
		wp_enqueue_style( 'dnbfs-admin', DN_BURST_FUNNEL_STATS_URL . 'build/index.css', array( 'wp-components' ), $asset['version'] );
	}
}
add_action( 'admin_enqueue_scripts', 'dn_bfs_enqueue_admin_app' );
```

- [ ] **Step 3: Sửa `dn-burst-funnel-stats.php`**
1. `define('DN_BURST_FUNNEL_STATS_SCHEMA_VERSION', '5');` → `'6'`.
2. Thêm `'pages'` vào mảng `dn_burst_funnel_stats_load_admin()`.
3. Trong `dn_burst_funnel_stats_bootstrap()`: xóa các dòng `require_once` của `includes/dashboard.php`, `includes/admin-menu.php`, `includes/settings.php`, `includes/import-export.php`, `includes/ajax.php` và dòng `dn_burst_dash_schedule_refresh_event();`.
4. Trong `dn_burst_funnel_stats_maybe_migrate()`: xóa khối tạo option `dn_burst_funnel_stats_url_tracking_settings`; ngay trước `dn_bfs_install_schema();` gọi `dn_bfs_migrate_legacy_cleanup();`.
5. Thêm hàm (đặt ngay trên `dn_burst_funnel_stats_maybe_migrate()`):

```php
/**
 * Remove options, transients and cron events left by the Burst-based dashboard.
 *
 * @return void
 */
function dn_bfs_migrate_legacy_cleanup()
{
  global $wpdb;

  $patterns = array('dn\_atc\_%', '\_transient\_dn\_atc\_%', '\_transient\_timeout\_dn\_atc\_%', '\_transient\_dn\_bfs\_%', '\_transient\_timeout\_dn\_bfs\_%');

  foreach ($patterns as $pattern) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern));
  }

  foreach (array('dn_bfs_data_last_changed', 'dn_burst_funnel_stats_last_refresh', 'dn_burst_funnel_stats_url_tracking_settings') as $option) {
    delete_option($option);
  }

  wp_clear_scheduled_hook('dn_burst_funnel_stats_refresh_cache');
  wp_cache_delete('alloptions', 'options');
}
```

6. `dn_burst_funnel_stats_plugin_action_links()`: giữ link Dashboard (`admin.php?page=dn-burst-funnel-stats`) và Settings (`admin.php?page=dn-burst-funnel-stats-settings`) — không đổi nếu đã đúng.

- [ ] **Step 4: Xóa code cũ**

```bash
git rm includes/dashboard.php includes/ajax.php includes/admin-menu.php includes/import-export.php includes/settings.php assets/admin.js assets/admin.css
```

Sau đó tìm các hàm cũ không còn ai gọi trong `includes/tracking.php`:

```bash
grep -rn "dn_bfs_is_ip_excluded\|dn_bfs_is_bot_request\|dn_bfs_is_selected_page_request\|dn_bfs_should_track_request\|dn_burst_funnel_stats_should_track_product\|dn_burst_dash_" includes dn-burst-funnel-stats.php uninstall.php tests
```

Xóa khỏi `includes/tracking.php` những hàm trong danh sách trên mà grep chỉ thấy định nghĩa của chính nó (không còn lời gọi nào). `dn_bfs_should_track_product()` vẫn được `store.php`/`wc-events.php` dùng — **giữ**. Không được còn tham chiếu `dn_burst_dash_*` nào.

- [ ] **Step 5: Chạy test**

Run tích hợp → Expected: tất cả PASS (thêm 5 test). Run unit + `php74` → OK.

Run: `curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/wp-admin/` → Expected `302` (không fatal error). Run `docker compose -f docker/docker-compose.yml logs wordpress --tail 30` → không có PHP Fatal/Warning mới.

- [ ] **Step 6: Commit**

```bash
git add -A includes dn-burst-funnel-stats.php tests assets
git commit -m "feat(admin): mount the React app pages, remove the Burst-era dashboard, migrate to schema 6

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Widget trên Dashboard WordPress

**Files:**
- Create: `includes/admin/dashboard-widget.php`, `assets/dashboard-widget.js`, `tests/integration/test-dashboard-widget.php`
- Modify: `dn-burst-funnel-stats.php` (thêm `'dashboard-widget'`)

**Interfaces:**
- Consumes: `dn_bfs_calculate_date_range()`, `dn_bfs_report_summary()`, `dn_bfs_report_realtime()`, `dn_bfs_admin_permission()`, route `GET /admin/realtime`.
- Produces: `dn_bfs_dashboard_widget_data( $now = null ): array|null` → `online`, `visitors`, `orders`, `revenue` (mỗi mục `array( 'today' => number, 'yesterday' => number )`); widget `dnbfs_overview`; script `dnbfs-dashboard-widget` chỉ nạp ở `index.php`.

- [ ] **Step 1: Viết test fail — `tests/integration/test-dashboard-widget.php`**

```php
<?php

require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/rest.php';

dn_bfs_it_today(
	'dashboard widget data compares today with yesterday and counts online visitors',
	function () {
		$now = dn_bfs_it_now();
		$s   = dn_bfs_it_seed_session( array( 'started_at' => $now - 60, 'last_activity' => $now - 30 ) );
		dn_bfs_it_seed_pageview( $s, '/', $now - 60 );
		$y = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 1 ) ) );
		dn_bfs_it_seed_pageview( $y, '/', dn_bfs_it_day_noon( 1 ) );

		$data = dn_bfs_dashboard_widget_data( $now );

		dn_bfs_assert_same( 1, $data['online'] );
		dn_bfs_assert_same( 1, $data['visitors']['today'] );
		dn_bfs_assert_same( 1, $data['visitors']['yesterday'] );
		dn_bfs_assert_same( 0, $data['orders']['today'] );
	}
);

dn_bfs_it_today(
	'dashboard widget renders the table and the live online badge',
	function () {
		ob_start();
		dn_bfs_render_dashboard_widget();
		$html = ob_get_clean();

		dn_bfs_assert_true( false !== strpos( $html, 'data-dnbfs-online' ), 'online badge' );
		dn_bfs_assert_true( false !== strpos( $html, 'page=dn-burst-funnel-stats' ), 'dashboard link' );
	}
);

dn_bfs_it(
	'dashboard widget is registered only for users with the capability',
	function () {
		global $wp_meta_boxes;

		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		set_current_screen( 'dashboard' );
		$wp_meta_boxes = array();

		wp_set_current_user( 0 );
		dn_bfs_register_dashboard_widget();
		dn_bfs_assert_true( empty( $wp_meta_boxes['dashboard']['normal']['core']['dnbfs_overview'] ), 'hidden for guests' );

		dn_bfs_it_login_admin();
		dn_bfs_register_dashboard_widget();
		dn_bfs_assert_true( ! empty( $wp_meta_boxes['dashboard']['normal']['core']['dnbfs_overview'] ), 'shown for admins' );
	}
);
```

Run tích hợp → Expected: test mới FAIL.

- [ ] **Step 2: Tạo `includes/admin/dashboard-widget.php`**

```php
<?php
/**
 * Compact "today" widget on the main WordPress dashboard.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_dashboard_widget_data( $now = null ) {
	$range   = dn_bfs_calculate_date_range( 'today', 'previous_period' );
	$summary = dn_bfs_report_summary( $range, array(), $now );

	if ( is_wp_error( $summary ) ) {
		return null;
	}

	$realtime = dn_bfs_report_realtime( $now );
	$data     = array( 'online' => (int) $realtime['online'] );

	foreach ( array( 'visitors', 'orders', 'revenue' ) as $metric ) {
		$data[ $metric ] = array(
			'today'     => $summary['current'][ $metric ],
			'yesterday' => null === $summary['previous'] ? 0 : $summary['previous'][ $metric ],
		);
	}

	return $data;
}

function dn_bfs_register_dashboard_widget() {
	if ( ! dn_bfs_admin_permission() ) {
		return;
	}

	wp_add_dashboard_widget( 'dnbfs_overview', __( 'Funnel Stats', 'dn-burst-funnel-stats' ), 'dn_bfs_render_dashboard_widget' );
}
add_action( 'wp_dashboard_setup', 'dn_bfs_register_dashboard_widget' );

function dn_bfs_render_dashboard_widget() {
	$data = dn_bfs_dashboard_widget_data();

	if ( null === $data ) {
		echo '<p>' . esc_html__( 'Stats are not available right now.', 'dn-burst-funnel-stats' ) . '</p>';
		return;
	}

	$money = function ( $value ) {
		return function_exists( 'wc_price' ) ? wc_price( $value ) : esc_html( number_format_i18n( $value, 2 ) );
	};
	$rows  = array(
		array( __( 'Visitors', 'dn-burst-funnel-stats' ), esc_html( number_format_i18n( $data['visitors']['today'] ) ), esc_html( number_format_i18n( $data['visitors']['yesterday'] ) ) ),
		array( __( 'Orders', 'dn-burst-funnel-stats' ), esc_html( number_format_i18n( $data['orders']['today'] ) ), esc_html( number_format_i18n( $data['orders']['yesterday'] ) ) ),
		array( __( 'Sales', 'dn-burst-funnel-stats' ), $money( $data['revenue']['today'] ), $money( $data['revenue']['yesterday'] ) ),
	);
	?>
	<p>
		<strong data-dnbfs-online><?php echo esc_html( number_format_i18n( $data['online'] ) ); ?></strong>
		<?php esc_html_e( 'visitors online now', 'dn-burst-funnel-stats' ); ?>
	</p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"></th>
				<th scope="col"><?php esc_html_e( 'Today', 'dn-burst-funnel-stats' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Yesterday', 'dn-burst-funnel-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
					<td><?php echo wp_kses_post( $row[1] ); ?></td>
					<td><?php echo wp_kses_post( $row[2] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=dn-burst-funnel-stats' ) ); ?>"><?php esc_html_e( 'Open the dashboard', 'dn-burst-funnel-stats' ); ?></a></p>
	<?php
}

function dn_bfs_enqueue_dashboard_widget( $hook_suffix ) {
	if ( 'index.php' !== $hook_suffix || ! dn_bfs_admin_permission() ) {
		return;
	}

	$path = DN_BURST_FUNNEL_STATS_PATH . 'assets/dashboard-widget.js';

	wp_enqueue_script(
		'dnbfs-dashboard-widget',
		DN_BURST_FUNNEL_STATS_URL . 'assets/dashboard-widget.js',
		array( 'wp-api-fetch' ),
		file_exists( $path ) ? (string) filemtime( $path ) : DN_BURST_FUNNEL_STATS_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'dn_bfs_enqueue_dashboard_widget' );
```

- [ ] **Step 3: Tạo `assets/dashboard-widget.js`**

```js
/* Refresh the online-visitor count in the DN Burst Funnel Stats dashboard widget. */
( function () {
	var badge = document.querySelector( '[data-dnbfs-online]' );

	if ( ! badge || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	function refresh() {
		window.wp.apiFetch( { path: '/dnbfs/v1/admin/realtime' } ).then( function ( response ) {
			if ( response && response.data && 'number' === typeof response.data.online ) {
				badge.textContent = String( response.data.online );
			}
		} ).catch( function () {} );
	}

	setInterval( refresh, 30000 );
} )();
```

- [ ] **Step 4: Nạp module** — thêm `'dashboard-widget'` vào mảng `dn_burst_funnel_stats_load_admin()`.

- [ ] **Step 5: Chạy test**

Run tích hợp → Expected: tất cả PASS (thêm 3 test). Run unit + `php74` → OK.

- [ ] **Step 6: Kiểm tra trên trình duyệt** — đăng nhập `http://localhost:8080/wp-admin/` (tài khoản trong `docker/.env.example`): widget "Funnel Stats" hiện trên Dashboard; menu "Funnel Stats" có Dashboard và Settings; hai trang hiện chữ "Dashboard is loading…" / "Settings are loading…" (bundle React đã mount); Console không có lỗi JS.

- [ ] **Step 7: Commit**

```bash
git add includes/admin/dashboard-widget.php assets/dashboard-widget.js tests/integration/test-dashboard-widget.php dn-burst-funnel-stats.php
git commit -m "feat(admin): add the WordPress dashboard widget with live online count

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Tự kiểm tra kế hoạch so với spec (phạm vi 3A)

| Yêu cầu | Task |
|---|---|
| §8.1 Build `@wordpress/scripts`, bundle commit, Node trong Docker, mount + URL page | 1, 6 |
| §8.1 REST nội bộ cookie + nonce, quyền `manage_options` + filter | 3 |
| §8.4 Settings 6 nhóm (Chung, Tracking, Chống spam, WooCommerce, GeoIP, Dữ liệu), gửi đủ khóa, che license, xóa backoff | 2, 4 |
| §8.4 Dữ liệu: tổng hợp lại, dung lượng bảng, Export/Import, xóa toàn bộ (gõ DELETE) | 4 |
| §8.4 Hệ thống: bảng, cron, `/collect`, tracker, GeoIP, phiên bản (+ lag, lỗi aggregator, engine, file công khai, proxy) | 5 |
| §8.5 Widget WP Dashboard, làm mới 30 giây | 7 |
| §8.6 Route nội bộ (summary, timeseries, breakdown, funnel, realtime, filters/values, preferences, settings, data/*, geoip/update, system/status) | 3, 4, 5 |
| §10 Gỡ code Burst cũ, xóa `dn_atc_*`, hủy cron cũ | 6 |
| Hợp đồng chuyển tiếp: 400 cho bộ lọc/chỉ số sai, chuyển nguyên lỗi 422, kẹp khoảng ≤ 731 ngày, envelope có currency, retention theo `dn_bfs_raw_available_from`, mặc định `wc-refunded` | 2, 3 |

Ngoài phạm vi: giao diện Dashboard (3B); giao diện Settings, quản lý API key (bảng `dnbfs_api_keys` đã có), README, version 3.0.0 (3C); REST công khai (4).
