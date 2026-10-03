# Kế hoạch 2 — Tổng hợp dữ liệu và lớp báo cáo

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tổng hợp dữ liệu tracking thô thành bảng theo ngày, dọn dữ liệu cũ, tự cập nhật GeoIP, gỡ cài đặt sạch, và cung cấp `reports.php` — API PHP duy nhất mà dashboard (Kế hoạch 3) và REST công khai (Kế hoạch 4) dùng.

**Architecture:** Một "raw engine" (`includes/reports/raw.php`) tính mọi chỉ số cho một khoảng thời gian + một chiều phân tích + bộ lọc, đọc trực tiếp các bảng thô (traffic/events bằng SQL, đơn hàng bằng PHP theo trạng thái WooCommerce hiện tại). Aggregator gọi raw engine cho từng ngày và ghi vào `dnbfs_daily`. `reports.php` ghép `dnbfs_daily` (các ngày đã qua) với raw engine (hôm nay, hoặc khi có ≥ 2 bộ lọc). Cron mỗi giờ tổng hợp, cron mỗi ngày dọn dẹp.

**Tech Stack:** PHP 7.4+, WordPress 6.5+, WooCommerce (HPOS-compatible APIs: `wc_get_order`, `wc_create_order`), MariaDB 11, PHPUnit 9.6, WP-CLI integration harness, PharData (giải nén GeoLite2).

**Spec:** `docs/superpowers/specs/2026-10-03-native-tracking-design.md` (mục 6, 7, 8.3, 8.4 nhóm WooCommerce/GeoIP/Dữ liệu, 13).

## Global Constraints

- PHP tối thiểu 7.4: không dùng `match`, union type, named args, `str_contains`, enum, readonly, nullsafe. Không thêm return type mới (theo phong cách code hiện có).
- Tiền tố hàm: `dn_bfs_`. Tên bảng qua `dn_bfs_table( $name )`. Mọi SQL có biến đi qua `$wpdb->prepare`.
- Phong cách: hàm thủ tục, tab thụt lề, khoảng trắng kiểu WordPress trong `includes/`; file `dn-burst-funnel-stats.php` dùng 2 space và không có khoảng trắng trong ngoặc.
- Thời gian lưu dạng epoch UTC; ngày `Y-m-d` luôn theo `wp_timezone()` (`wp_date()`); khoảng thời gian dạng `[start, end)` (end loại trừ).
- `dnbfs_daily` giữ mãi mãi; dữ liệu thô giữ `raw_retention_days` ngày (mặc định 90); ngày `D` còn dữ liệu thô khi `D >= dn_bfs_raw_cutoff_date( $now )`.
- Dimension `blocked` trong `dnbfs_daily` là bộ đếm sống — aggregator **không bao giờ** xóa/ghi đè dòng `blocked`.
- Đơn hàng: chỉ đếm từ sự kiện `order` đã ghi (mỗi `order_id` một lần), theo **trạng thái hiện tại** của đơn. Sales = mọi trạng thái trừ `wc-cancelled`, `wc-failed`, `wc-checkout-draft`; revenue = total − refunded; Paid = `wc-processing` + `wc-completed`; Balance = `wc-pending` + `wc-on-hold` (đều chỉnh được ở settings WooCommerce). Đơn của phiên spam vẫn được đếm (là tiền thật).
- Đơn fallback có `session_id = 0`, `visitor_uid = ''`: luôn LEFT JOIN sessions, không bao giờ đếm `''` là một khách.
- Session có `pageviews = 0` (tạo từ server trước khi tracker chạy) không tính vào sessions/visitors/bounces.
- Checkouts = `COUNT(DISTINCT session_id)` của sự kiện `checkout_start`.
- Visitors cho khoảng nhiều ngày: chính xác (`COUNT(DISTINCT visitor_uid)` trên bảng thô) khi toàn bộ khoảng còn trong thời hạn dữ liệu thô; ngược lại cộng theo ngày và trả `estimated = true`.
- Bộ lọc hợp lệ: `channel, source, medium, campaign, device, country`. ≥ 2 bộ lọc, hoặc bộ lọc + breakdown → raw engine, chỉ khi khoảng nằm trong thời hạn thô; ngoài thời hạn trả `WP_Error( 'filter_out_of_retention', …, array( 'status' => 422 ) )`.
- Salt `dnbfs_salt_*`: chỉ giữ hôm nay và hôm qua.
- Commit message kết thúc bằng dòng `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Không commit `.superpowers/`.
- Lệnh (chạy từ gốc repo):
  - Unit: `docker compose -f docker/docker-compose.yml run --rm phpunit`
  - Tích hợp: `docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php`
  - Lint PHP 7.4 (từ Task 1): `docker compose -f docker/docker-compose.yml run --rm php74`

## Điều chỉnh so với spec (Task 1 ghi vào spec)

- Tracker ping mỗi **60 giây** thay vì 30 (giảm ~một nửa số request PHP; "online" vẫn là hoạt động trong 5 phút).
- `dnbfs_daily` thêm cột `items`, `tips`, `paid`, `balance` (cần cho thẻ Items/AOI, Sales/Tip, Paid/Balance).
- File mới ngoài danh sách spec: `includes/reports/metrics.php` (hàm thuần), `includes/reports/wc-settings.php`, `includes/reports/raw.php`, `includes/tracking/geoip-update.php`, `docker/lint-php74.sh`.

## Cấu trúc file

| File | Trách nhiệm |
|---|---|
| `docker/lint-php74.sh`, `docker/docker-compose.yml` (sửa) | Service `php74` kiểm tra cú pháp PHP 7.4 |
| `includes/tracking/store.php` (sửa) | Tách truy vấn chống trùng thành 2 truy vấn có index |
| `assets/tracker.js` (sửa) | Heartbeat 60 giây |
| `includes/tracking/schema.php` (sửa) | Cột `items, tips, paid, balance` cho `dnbfs_daily` |
| `includes/reports/metrics.php` | Danh sách chỉ số/chiều/bộ lọc, cộng gộp, chỉ số dẫn xuất, ngày — thuần |
| `includes/reports/wc-settings.php` | Settings báo cáo WooCommerce (trạng thái, từ khóa tip) |
| `includes/reports/raw.php` | Raw engine: traffic, events, đơn hàng, visitors chính xác |
| `includes/tracking/aggregator.php` | Tổng hợp theo ngày, ngày cần làm lại, cron chạy bù, lập lịch cron |
| `includes/tracking/cleanup.php` | Xóa dữ liệu thô quá hạn, visitors cũ, salt cũ |
| `includes/tracking/geoip-update.php` | Tải + kiểm tra + thay GeoLite2 |
| `uninstall.php` (viết lại) | Gỡ sạch bảng, option, cron, file |
| `includes/reports.php` | API báo cáo: summary, timeseries, funnel, breakdown, realtime |
| `dn-burst-funnel-stats.php` (sửa) | `dn_burst_funnel_stats_load_reports()`, lập lịch cron, deactivation |
| `tests/integration/seed.php` | Helper chèn dữ liệu mẫu cho test báo cáo |

---

### Task 1: Việc chuyển tiếp từ Kế hoạch 1 (lint 7.4, index chống trùng, heartbeat 60s, cột daily)

**Files:**
- Create: `docker/lint-php74.sh`
- Modify: `docker/docker-compose.yml`, `includes/tracking/store.php` (`dn_bfs_store_find_recent_event`), `assets/tracker.js`, `includes/tracking/schema.php`, `docs/superpowers/specs/2026-10-03-native-tracking-design.md`
- Test: `tests/integration/test-store-events.php` (thêm test)

**Interfaces:**
- Produces: service `php74`; bảng `dnbfs_daily` có thêm `items int unsigned`, `tips decimal(19,4)`, `paid decimal(19,4)`, `balance decimal(19,4)`; `dn_bfs_store_find_recent_event()` giữ nguyên chữ ký và kết quả.

- [ ] **Step 1: Tạo `docker/lint-php74.sh`**

```sh
#!/bin/sh
# Syntax-check plugin PHP files against PHP 7.4, the plugin's minimum version.
cd /app || exit 1

fail=0

for f in dn-burst-funnel-stats.php uninstall.php $(find includes -name '*.php'); do
	out=$(php -l "$f" 2>&1) || { echo "$out"; fail=1; }
done

[ "$fail" -eq 0 ] && echo "PHP 7.4 lint OK"

exit "$fail"
```

Run: `chmod +x docker/lint-php74.sh`

- [ ] **Step 2: Thêm service vào `docker/docker-compose.yml`** (ngay sau service `phpunit`, cùng thụt lề):

```yaml
  php74:
    image: php:7.4-cli
    profiles: ["tools"]
    working_dir: /app
    volumes:
      - ../:/app
    entrypoint: ["sh", "/app/docker/lint-php74.sh"]
```

Run: `docker compose -f docker/docker-compose.yml run --rm php74`
Expected: `PHP 7.4 lint OK`

- [ ] **Step 3: Viết test fail cho truy vấn chống trùng qua ip_hash khi phiên bắt đầu trước cửa sổ** — thêm vào cuối `tests/integration/test-store-events.php`:

```php
dn_bfs_it(
	'product view dedupe by ip_hash still works for a session that started before the window',
	function () {
		global $wpdb;

		$now     = time();
		$session = dn_bfs_it_session( $now - 1000, 'early' );
		$wpdb->update( dn_bfs_table( 'sessions' ), array( 'started_at' => $now - 4000 ), array( 'id' => $session['id'] ) );

		dn_bfs_store_record_product_view( $session, 101, dn_bfs_request_context( $now - 100, DN_BFS_IT_UA ) );
		$other  = dn_bfs_it_session( $now, 'late' );
		$result = dn_bfs_store_record_product_view( $other, 101, dn_bfs_request_context( $now, DN_BFS_IT_UA ) );

		dn_bfs_assert_same( 'duplicate', $result['reason'] );
	}
);
```

Run tích hợp. Expected: test mới PASS ngay với code cũ (đây là test bảo vệ hành vi trước khi refactor) — ghi lại output.

- [ ] **Step 4: Thay `dn_bfs_store_find_recent_event()` trong `includes/tracking/store.php`**

```php
function dn_bfs_store_find_recent_event( $type, $visitor_uid, $ip_hash, $product_id, $since ) {
	global $wpdb;

	$events = dn_bfs_table( 'events' );

	// Two indexed lookups instead of one OR across a join: visitor first, then same browser+IP.
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$events} WHERE visitor_uid = %s AND product_id = %d AND type = %s AND time >= %d ORDER BY time DESC LIMIT 1",
			$visitor_uid,
			(int) $product_id,
			$type,
			(int) $since
		),
		ARRAY_A
	);

	if ( ! $row && '' !== (string) $ip_hash ) {
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT e.* FROM ' . dn_bfs_table( 'sessions' ) . " s
				INNER JOIN {$events} e ON e.session_id = s.id
				WHERE s.ip_hash = %s AND s.started_at >= %d AND e.product_id = %d AND e.type = %s AND e.time >= %d
				ORDER BY e.time DESC LIMIT 1",
				$ip_hash,
				(int) $since - DAY_IN_SECONDS,
				(int) $product_id,
				$type,
				(int) $since
			),
			ARRAY_A
		);
	}

	return is_array( $row ) ? $row : null;
}
```

- [ ] **Step 5: Heartbeat 60 giây** — trong `assets/tracker.js`, đổi `}, 30000);` thành `}, 60000);`.

- [ ] **Step 6: Thêm cột vào `dnbfs_daily`** — trong `includes/tracking/schema.php`, ngay sau dòng `  revenue decimal(19,4) NOT NULL DEFAULT 0,` của bảng daily thêm:

```
  items int(10) unsigned NOT NULL DEFAULT 0,
  tips decimal(19,4) NOT NULL DEFAULT 0,
  paid decimal(19,4) NOT NULL DEFAULT 0,
  balance decimal(19,4) NOT NULL DEFAULT 0,
```

(Harness gọi `dn_bfs_install_schema()` khi nạp nên DB dev nhận cột mới.)

- [ ] **Step 7: Cập nhật spec** — `docs/superpowers/specs/2026-10-03-native-tracking-design.md`:
1. Mục 4.1: thay "Hit `ping`: mỗi 30 giây" bằng "Hit `ping`: mỗi 60 giây".
2. Mục 3, bảng `dnbfs_daily`: thêm `items` (INT UNSIGNED), `tips`, `paid`, `balance` (DECIMAL(19,4)) vào danh sách cột.
3. Mục 11: thêm các file `includes/reports/metrics.php`, `includes/reports/wc-settings.php`, `includes/reports/raw.php`, `includes/tracking/geoip-update.php`.

- [ ] **Step 8: Chạy toàn bộ test + lint**

Run tích hợp → Expected: `48 passed, 0 failed`. Run unit → `OK`. Run `php74` → `PHP 7.4 lint OK`.

- [ ] **Step 9: Commit**

```bash
git add docker includes/tracking/store.php assets/tracker.js includes/tracking/schema.php tests/integration/test-store-events.php docs/superpowers/specs/2026-10-03-native-tracking-design.md
git commit -m "chore: PHP 7.4 lint service, indexed dedupe lookups, 60s heartbeat, daily order columns

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Chỉ số, chiều phân tích, bộ lọc (thuần) + settings báo cáo WooCommerce

**Files:**
- Create: `includes/reports/metrics.php`, `includes/reports/wc-settings.php`, `tests/php/MetricsTest.php`, `tests/php/WcReportSettingsTest.php`
- Modify: `tests/php/bootstrap.php`, `dn-burst-funnel-stats.php`

**Interfaces:**
- Consumes: `dn_bfs_truncate()` (channel.php), `dn_bfs_normalize_lines()` (tracking.php).
- Produces:
  - `dn_bfs_metric_columns(): array` — `pageviews, visitors, sessions, new_visitors, bounces, duration_sum, product_views, atc, carts, checkouts, orders, revenue, items, tips, paid, balance`.
  - `dn_bfs_money_columns(): array` — `revenue, tips, paid, balance`.
  - `dn_bfs_order_columns(): array` — `orders, revenue, items, tips, paid, balance`.
  - `dn_bfs_empty_metrics(): array`, `dn_bfs_normalize_metrics( array $row ): array`, `dn_bfs_add_metrics( array $a, array $b ): array`.
  - `dn_bfs_session_dimensions(): array` — `channel, source, medium, campaign, referrer, device, browser, os, country, city, entry, exit`.
  - `dn_bfs_report_dimensions(): array` — `page, product` + session dimensions.
  - `dn_bfs_aggregate_dimensions(): array` — `total` + report dimensions.
  - `dn_bfs_order_dimensions(): array` — `total, channel, source, medium, campaign, country, device, product`.
  - `dn_bfs_filter_dimensions(): array` — `channel, source, medium, campaign, device, country`.
  - `dn_bfs_sanitize_filters( $filters ): array` — chỉ giữ khóa hợp lệ, giá trị scalar không rỗng, ≤ 191 ký tự, `ksort`.
  - `dn_bfs_derive_metrics( array $m ): array` — thêm `returning_visitors, bounce_rate, avg_duration, pages_per_session, conversion_rate, aov, aoi`.
  - `dn_bfs_derived_metric_names(): array`.
  - `dn_bfs_percent_change( $current, $previous ): float`.
  - `dn_bfs_date_shift( string $date, int $days ): string`, `dn_bfs_dates_between( string $start, string $end ): array`.
  - `dn_bfs_sort_report_rows( array $rows, string $orderby, string $order ): array`.
  - `dn_bfs_get_wc_report_settings(): array` — `sales_excluded_statuses`, `paid_statuses`, `balance_statuses`, `tip_keywords` (option `dn_burst_funnel_stats_wc_report_settings`).
  - `dn_bfs_sanitize_wc_report_settings( $settings ): array`.
  - `dn_bfs_order_status_key( $order ): string` — `'wc-' . $order->get_status()`.
  - `dn_burst_funnel_stats_load_reports()` trong file chính.

- [ ] **Step 1: Viết test fail — `tests/php/MetricsTest.php`**

```php
<?php

use PHPUnit\Framework\TestCase;

class MetricsTest extends TestCase {
	public function test_empty_and_normalize() {
		$empty = dn_bfs_empty_metrics();

		$this->assertSame( dn_bfs_metric_columns(), array_keys( $empty ) );
		$this->assertSame( 0, $empty['sessions'] );
		$this->assertSame( 0.0, $empty['revenue'] );

		$row = dn_bfs_normalize_metrics( array( 'sessions' => '3', 'revenue' => '12.50', 'junk' => 1 ) );
		$this->assertSame( 3, $row['sessions'] );
		$this->assertSame( 12.5, $row['revenue'] );
		$this->assertArrayNotHasKey( 'junk', $row );
	}

	public function test_add_metrics() {
		$sum = dn_bfs_add_metrics(
			dn_bfs_normalize_metrics( array( 'sessions' => 2, 'revenue' => 1.1 ) ),
			dn_bfs_normalize_metrics( array( 'sessions' => 3, 'revenue' => 2.2 ) )
		);

		$this->assertSame( 5, $sum['sessions'] );
		$this->assertSame( 3.3, $sum['revenue'] );
	}

	public function test_dimension_lists() {
		$this->assertSame( array( 'channel', 'source', 'medium', 'campaign', 'device', 'country' ), dn_bfs_filter_dimensions() );
		$this->assertSame( 'total', dn_bfs_aggregate_dimensions()[0] );
		$this->assertContains( 'page', dn_bfs_report_dimensions() );
		$this->assertContains( 'product', dn_bfs_order_dimensions() );
		$this->assertNotContains( 'page', dn_bfs_order_dimensions() );
	}

	public function test_sanitize_filters() {
		$this->assertSame(
			array( 'campaign' => 'sale-10', 'device' => 'mobile' ),
			dn_bfs_sanitize_filters( array( 'device' => 'mobile', 'campaign' => ' sale-10 ', 'browser' => 'Chrome', 'country' => '', 'source' => array( 'x' ) ) )
		);
		$this->assertSame( array(), dn_bfs_sanitize_filters( 'nope' ) );
		$this->assertSame( 191, mb_strlen( dn_bfs_sanitize_filters( array( 'campaign' => str_repeat( 'ă', 300 ) ) )['campaign'], 'UTF-8' ) );
	}

	public function test_derive_metrics() {
		$m = dn_bfs_derive_metrics(
			dn_bfs_normalize_metrics(
				array(
					'visitors'     => 200,
					'new_visitors' => 150,
					'sessions'     => 250,
					'bounces'      => 100,
					'duration_sum' => 25000,
					'pageviews'    => 500,
					'orders'       => 5,
					'revenue'      => 250.0,
					'items'        => 8,
				)
			)
		);

		$this->assertSame( 50, $m['returning_visitors'] );
		$this->assertSame( 40.0, $m['bounce_rate'] );
		$this->assertSame( 100, $m['avg_duration'] );
		$this->assertSame( 2.0, $m['pages_per_session'] );
		$this->assertSame( 2.5, $m['conversion_rate'] );
		$this->assertSame( 50.0, $m['aov'] );
		$this->assertSame( 1.6, $m['aoi'] );

		$zero = dn_bfs_derive_metrics( dn_bfs_empty_metrics() );
		$this->assertSame( 0.0, $zero['bounce_rate'] );
		$this->assertSame( 0.0, $zero['aov'] );
		$this->assertSame( dn_bfs_derived_metric_names(), array_values( array_diff( array_keys( $zero ), dn_bfs_metric_columns() ) ) );
	}

	public function test_percent_change() {
		$this->assertSame( 50.0, dn_bfs_percent_change( 150, 100 ) );
		$this->assertSame( -25.0, dn_bfs_percent_change( 75, 100 ) );
		$this->assertSame( 100.0, dn_bfs_percent_change( 5, 0 ) );
		$this->assertSame( 0.0, dn_bfs_percent_change( 0, 0 ) );
	}

	public function test_dates() {
		$this->assertSame( '2026-03-01', dn_bfs_date_shift( '2026-02-28', 1 ) );
		$this->assertSame( '2025-12-31', dn_bfs_date_shift( '2026-01-01', -1 ) );
		$this->assertSame( array( '2026-02-27', '2026-02-28', '2026-03-01' ), dn_bfs_dates_between( '2026-02-27', '2026-03-01' ) );
		$this->assertSame( array(), dn_bfs_dates_between( '2026-03-02', '2026-03-01' ) );
	}

	public function test_sort_rows() {
		$rows = array(
			array( 'dim_value' => 'b', 'sessions' => 5 ),
			array( 'dim_value' => 'a', 'sessions' => 5 ),
			array( 'dim_value' => 'c', 'sessions' => 9 ),
		);

		$this->assertSame( array( 'c', 'a', 'b' ), array_column( dn_bfs_sort_report_rows( $rows, 'sessions', 'desc' ), 'dim_value' ) );
		$this->assertSame( array( 'a', 'b', 'c' ), array_column( dn_bfs_sort_report_rows( $rows, 'sessions', 'asc' ), 'dim_value' ) );
	}
}
```

- [ ] **Step 2: Viết test fail — `tests/php/WcReportSettingsTest.php`**

```php
<?php

use PHPUnit\Framework\TestCase;

class WcReportSettingsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['dn_bfs_test_options'] = array();
	}

	public function test_defaults() {
		$settings = dn_bfs_get_wc_report_settings();

		$this->assertSame( array( 'wc-cancelled', 'wc-failed', 'wc-checkout-draft' ), $settings['sales_excluded_statuses'] );
		$this->assertSame( array( 'wc-processing', 'wc-completed' ), $settings['paid_statuses'] );
		$this->assertSame( array( 'wc-pending', 'wc-on-hold' ), $settings['balance_statuses'] );
		$this->assertSame( array( 'tip', 'tips', 'gratuity' ), $settings['tip_keywords'] );
	}

	public function test_sanitize() {
		$clean = dn_bfs_sanitize_wc_report_settings(
			array(
				'sales_excluded_statuses' => array( 'wc-cancelled', 'processing', 'WC-Failed', '' ),
				'paid_statuses'           => 'wc-completed',
				'tip_keywords'            => "Tip\nBo Duoc\n\n",
			)
		);

		$this->assertSame( array( 'wc-cancelled', 'wc-failed' ), $clean['sales_excluded_statuses'] );
		$this->assertSame( array( 'wc-completed' ), $clean['paid_statuses'] );
		$this->assertSame( array( 'wc-pending', 'wc-on-hold' ), $clean['balance_statuses'] );
		$this->assertSame( array( 'tip', 'bo duoc' ), $clean['tip_keywords'] );
	}

	public function test_saved_values_are_used() {
		$GLOBALS['dn_bfs_test_options']['dn_burst_funnel_stats_wc_report_settings'] = array( 'paid_statuses' => array( 'wc-completed' ) );

		$this->assertSame( array( 'wc-completed' ), dn_bfs_get_wc_report_settings()['paid_statuses'] );
	}
}
```

- [ ] **Step 3: Nạp file mới trong unit bootstrap** — cuối `tests/php/bootstrap.php` thêm:

```php
foreach ( array( 'metrics', 'wc-settings' ) as $dn_bfs_file ) {
	require_once $dn_bfs_root . '/includes/reports/' . $dn_bfs_file . '.php';
}
```

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit --filter 'MetricsTest|WcReportSettingsTest'`
Expected: FAIL (`Failed opening required … includes/reports/metrics.php`).

- [ ] **Step 4: Tạo `includes/reports/metrics.php`**

```php
<?php
/**
 * Report metric, dimension and filter definitions. Pure functions only.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_metric_columns() {
	return array( 'pageviews', 'visitors', 'sessions', 'new_visitors', 'bounces', 'duration_sum', 'product_views', 'atc', 'carts', 'checkouts', 'orders', 'revenue', 'items', 'tips', 'paid', 'balance' );
}

function dn_bfs_money_columns() {
	return array( 'revenue', 'tips', 'paid', 'balance' );
}

function dn_bfs_order_columns() {
	return array( 'orders', 'revenue', 'items', 'tips', 'paid', 'balance' );
}

function dn_bfs_empty_metrics() {
	$metrics = array();

	foreach ( dn_bfs_metric_columns() as $column ) {
		$metrics[ $column ] = in_array( $column, dn_bfs_money_columns(), true ) ? 0.0 : 0;
	}

	return $metrics;
}

function dn_bfs_normalize_metrics( $row ) {
	$metrics = dn_bfs_empty_metrics();

	foreach ( dn_bfs_metric_columns() as $column ) {
		if ( ! isset( $row[ $column ] ) ) {
			continue;
		}

		$metrics[ $column ] = in_array( $column, dn_bfs_money_columns(), true ) ? round( (float) $row[ $column ], 4 ) : (int) $row[ $column ];
	}

	return $metrics;
}

function dn_bfs_add_metrics( $a, $b ) {
	$sum = dn_bfs_empty_metrics();

	foreach ( dn_bfs_metric_columns() as $column ) {
		$value = ( isset( $a[ $column ] ) ? $a[ $column ] : 0 ) + ( isset( $b[ $column ] ) ? $b[ $column ] : 0 );

		$sum[ $column ] = in_array( $column, dn_bfs_money_columns(), true ) ? round( (float) $value, 4 ) : (int) $value;
	}

	return $sum;
}

function dn_bfs_session_dimensions() {
	return array( 'channel', 'source', 'medium', 'campaign', 'referrer', 'device', 'browser', 'os', 'country', 'city', 'entry', 'exit' );
}

function dn_bfs_report_dimensions() {
	return array_merge( array( 'page', 'product' ), dn_bfs_session_dimensions() );
}

function dn_bfs_aggregate_dimensions() {
	return array_merge( array( 'total' ), dn_bfs_report_dimensions() );
}

function dn_bfs_order_dimensions() {
	return array( 'total', 'channel', 'source', 'medium', 'campaign', 'country', 'device', 'product' );
}

function dn_bfs_filter_dimensions() {
	return array( 'channel', 'source', 'medium', 'campaign', 'device', 'country' );
}

function dn_bfs_sanitize_filters( $filters ) {
	$clean = array();

	if ( ! is_array( $filters ) ) {
		return $clean;
	}

	foreach ( $filters as $dimension => $value ) {
		$dimension = sanitize_key( $dimension );

		if ( ! in_array( $dimension, dn_bfs_filter_dimensions(), true ) || ! is_scalar( $value ) ) {
			continue;
		}

		$value = dn_bfs_truncate( sanitize_text_field( (string) $value ), 191 );

		if ( '' !== $value ) {
			$clean[ $dimension ] = $value;
		}
	}

	ksort( $clean );

	return $clean;
}

function dn_bfs_derived_metric_names() {
	return array( 'returning_visitors', 'bounce_rate', 'avg_duration', 'pages_per_session', 'conversion_rate', 'aov', 'aoi' );
}

function dn_bfs_derive_metrics( $m ) {
	$sessions = (int) $m['sessions'];
	$visitors = (int) $m['visitors'];
	$orders   = (int) $m['orders'];

	return array_merge(
		$m,
		array(
			'returning_visitors' => max( 0, $visitors - (int) $m['new_visitors'] ),
			'bounce_rate'        => $sessions > 0 ? round( $m['bounces'] / $sessions * 100, 1 ) : 0.0,
			'avg_duration'       => $sessions > 0 ? (int) round( $m['duration_sum'] / $sessions ) : 0,
			'pages_per_session'  => $sessions > 0 ? round( $m['pageviews'] / $sessions, 2 ) : 0.0,
			'conversion_rate'    => $visitors > 0 ? round( $orders / $visitors * 100, 2 ) : 0.0,
			'aov'                => $orders > 0 ? round( $m['revenue'] / $orders, 2 ) : 0.0,
			'aoi'                => $orders > 0 ? round( $m['items'] / $orders, 2 ) : 0.0,
		)
	);
}

function dn_bfs_percent_change( $current, $previous ) {
	$current  = (float) $current;
	$previous = (float) $previous;

	if ( $previous <= 0 ) {
		return $current > 0 ? 100.0 : 0.0;
	}

	return round( ( $current - $previous ) / $previous * 100, 1 );
}

function dn_bfs_date_shift( $date, $days ) {
	$day = new DateTimeImmutable( $date . ' 00:00:00', new DateTimeZone( 'UTC' ) );

	return $day->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' days' )->format( 'Y-m-d' );
}

function dn_bfs_dates_between( $start, $end ) {
	$dates = array();

	for ( $cursor = $start; $cursor <= $end; $cursor = dn_bfs_date_shift( $cursor, 1 ) ) {
		$dates[] = $cursor;
	}

	return $dates;
}

function dn_bfs_sort_report_rows( $rows, $orderby, $order ) {
	$direction = 'asc' === strtolower( (string) $order ) ? 1 : -1;

	usort(
		$rows,
		function ( $a, $b ) use ( $orderby, $direction ) {
			$left  = isset( $a[ $orderby ] ) ? (float) $a[ $orderby ] : 0.0;
			$right = isset( $b[ $orderby ] ) ? (float) $b[ $orderby ] : 0.0;

			if ( $left === $right ) {
				return strcmp( (string) $a['dim_value'], (string) $b['dim_value'] );
			}

			return ( $left < $right ? -1 : 1 ) * $direction;
		}
	);

	return $rows;
}
```

- [ ] **Step 5: Tạo `includes/reports/wc-settings.php`**

```php
<?php
/**
 * WooCommerce order-status rules used by revenue reports.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_wc_report_defaults() {
	return array(
		'sales_excluded_statuses' => array( 'wc-cancelled', 'wc-failed', 'wc-checkout-draft' ),
		'paid_statuses'           => array( 'wc-processing', 'wc-completed' ),
		'balance_statuses'        => array( 'wc-pending', 'wc-on-hold' ),
		'tip_keywords'            => array( 'tip', 'tips', 'gratuity' ),
	);
}

function dn_bfs_sanitize_order_statuses( $value ) {
	$statuses = array();

	foreach ( (array) $value as $status ) {
		$status = sanitize_key( $status );

		if ( 0 === strpos( $status, 'wc-' ) && strlen( $status ) > 3 ) {
			$statuses[] = $status;
		}
	}

	return array_values( array_unique( $statuses ) );
}

function dn_bfs_sanitize_wc_report_settings( $settings ) {
	$settings = is_array( $settings ) ? $settings : array();
	$clean    = dn_bfs_wc_report_defaults();

	foreach ( array( 'sales_excluded_statuses', 'paid_statuses', 'balance_statuses' ) as $key ) {
		if ( array_key_exists( $key, $settings ) ) {
			$clean[ $key ] = dn_bfs_sanitize_order_statuses( $settings[ $key ] );
		}
	}

	if ( array_key_exists( 'tip_keywords', $settings ) ) {
		$clean['tip_keywords'] = array_values( array_unique( array_map( 'strtolower', dn_bfs_normalize_lines( $settings['tip_keywords'] ) ) ) );
	}

	return $clean;
}

function dn_bfs_get_wc_report_settings() {
	$saved = get_option( 'dn_burst_funnel_stats_wc_report_settings', array() );

	return dn_bfs_sanitize_wc_report_settings( is_array( $saved ) ? $saved : array() );
}

function dn_bfs_order_status_key( $order ) {
	return 'wc-' . $order->get_status();
}
```

- [ ] **Step 6: Chạy unit test**

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit`
Expected: `OK` (tất cả, gồm 2 file test mới).

- [ ] **Step 7: Nạp module báo cáo trong plugin** — trong `dn-burst-funnel-stats.php`, ngay sau hàm `dn_burst_funnel_stats_load_tracking()` thêm:

```php
/**
 * Load report modules (aggregation inputs and the report API).
 *
 * @return void
 */
function dn_burst_funnel_stats_load_reports()
{
  foreach (array('reports/metrics', 'reports/wc-settings') as $module) {
    require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/' . $module . '.php';
  }
}
```

và gọi `dn_burst_funnel_stats_load_reports();` ngay sau mỗi lời gọi `dn_burst_funnel_stats_load_tracking();` (trong `dn_burst_funnel_stats_activate()` và `dn_burst_funnel_stats_bootstrap()`). Các task sau thêm module của mình vào mảng này.

- [ ] **Step 8: Kiểm tra plugin vẫn nạp được**

Run tích hợp → Expected: `48 passed, 0 failed`. Run `php74` → `PHP 7.4 lint OK`.

- [ ] **Step 9: Commit**

```bash
git add includes/reports tests/php dn-burst-funnel-stats.php
git commit -m "feat(reports): add metric/dimension definitions and WooCommerce report settings

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Raw engine — traffic và sự kiện

**Files:**
- Create: `includes/reports/raw.php`, `tests/integration/seed.php`, `tests/integration/test-raw-traffic.php`
- Modify: `dn-burst-funnel-stats.php` (thêm `'reports/raw'` vào `dn_burst_funnel_stats_load_reports()`)

**Interfaces:**
- Consumes: Task 2 (`dn_bfs_empty_metrics`, `dn_bfs_normalize_metrics`, `dn_bfs_add_metrics`, `dn_bfs_sanitize_filters`, dimension lists).
- Produces:
  - `dn_bfs_raw_session_column( string $dimension ): string`, `dn_bfs_raw_event_column( string $dimension ): string`, `dn_bfs_raw_filter_sql( array $filters, string $alias ): string` (`'s'` hoặc `'e'`).
  - `dn_bfs_raw_traffic_rows( int $start, int $end, string $dimension, array $filters ): array` → `dim_value => metrics` (pageviews, visitors, sessions, new_visitors, bounces, duration_sum).
  - `dn_bfs_raw_event_rows( int $start, int $end, string $dimension, array $filters ): array` → `dim_value => metrics` (product_views, atc, carts, checkouts).
  - `dn_bfs_raw_distinct_visitors( int $start, int $end, array $filters, bool $new_only = false ): int`.
  - Helper test: `dn_bfs_it_seed_session( array $overrides = array() ): array`, `dn_bfs_it_seed_pageview( array $session, string $path, int $time, array $overrides = array() ): int`, `dn_bfs_it_seed_event( array $session, string $type, int $time, array $fields = array() ): int`, `dn_bfs_it_day_noon( int $days_ago ): int`.

- [ ] **Step 1: Tạo `tests/integration/seed.php`**

```php
<?php
/**
 * Direct-insert fixtures for report tests (bypasses the tracking guard and limits).
 */

function dn_bfs_it_day_noon( $days_ago ) {
	$date = dn_bfs_date_shift( wp_date( 'Y-m-d', time() ), -1 * (int) $days_ago );

	return ( new DateTimeImmutable( $date . ' 12:00:00', wp_timezone() ) )->getTimestamp();
}

function dn_bfs_it_seed_session( $overrides = array() ) {
	global $wpdb;
	static $counter = 0;

	$counter++;
	$time = isset( $overrides['started_at'] ) ? (int) $overrides['started_at'] : time();
	$row  = array_merge(
		array(
			'session_uid'    => md5( 'seed-session-' . $counter . '-' . wp_rand() ),
			'visitor_uid'    => md5( 'seed-visitor-' . $counter ),
			'started_at'     => $time,
			'last_activity'  => $time,
			'is_new_visitor' => 1,
			'entry_path'     => '/',
			'exit_path'      => '/',
			'pageviews'      => 1,
			'duration'       => 0,
			'is_bounce'      => 1,
			'referrer_host'  => '',
			'channel'        => 'direct',
			'utm_source'     => '',
			'utm_medium'     => '',
			'utm_campaign'   => '',
			'utm_content'    => '',
			'utm_term'       => '',
			'device'         => 'desktop',
			'browser'        => 'Chrome',
			'os'             => 'Windows',
			'country'        => 'VN',
			'city'           => '',
			'ip_hash'        => '',
			'is_spam'        => 0,
		),
		$overrides
	);

	$wpdb->insert( dn_bfs_table( 'sessions' ), $row );
	$row['id'] = (int) $wpdb->insert_id;

	return $row;
}

function dn_bfs_it_seed_pageview( $session, $path, $time, $overrides = array() ) {
	global $wpdb;

	$wpdb->insert(
		dn_bfs_table( 'pageviews' ),
		array_merge(
			array(
				'session_id'   => (int) $session['id'],
				'visitor_uid'  => $session['visitor_uid'],
				'time'         => (int) $time,
				'path'         => $path,
				'page_type'    => 'other',
				'object_id'    => 0,
				'time_on_page' => 0,
			),
			$overrides
		)
	);

	return (int) $wpdb->insert_id;
}

function dn_bfs_it_seed_event( $session, $type, $time, $fields = array() ) {
	global $wpdb;

	$wpdb->insert(
		dn_bfs_table( 'events' ),
		array_merge(
			array(
				'session_id'   => (int) $session['id'],
				'visitor_uid'  => (string) $session['visitor_uid'],
				'time'         => (int) $time,
				'type'         => $type,
				'product_id'   => 0,
				'qty'          => 0,
				'value'        => 0,
				'channel'      => (string) $session['channel'],
				'utm_source'   => (string) $session['utm_source'],
				'utm_medium'   => (string) $session['utm_medium'],
				'utm_campaign' => (string) $session['utm_campaign'],
				'country'      => (string) $session['country'],
				'device'       => (string) $session['device'],
			),
			$fields
		)
	);

	return (int) $wpdb->insert_id;
}

function dn_bfs_it_day_range( $days_ago ) {
	return dn_bfs_day_bounds_for_test( dn_bfs_date_shift( wp_date( 'Y-m-d', time() ), -1 * $days_ago ) );
}

function dn_bfs_day_bounds_for_test( $date ) {
	$start = new DateTimeImmutable( $date . ' 00:00:00', wp_timezone() );

	return array( $start->getTimestamp(), $start->modify( '+1 day' )->getTimestamp() );
}
```

- [ ] **Step 2: Viết test fail — `tests/integration/test-raw-traffic.php`**

```php
<?php

require_once __DIR__ . '/seed.php';

function dn_bfs_it_seed_traffic_day( $day_ts ) {
	$paid = dn_bfs_it_seed_session(
		array(
			'started_at'   => $day_ts,
			'channel'      => 'paid',
			'utm_source'   => 'facebook',
			'utm_medium'   => 'cpc',
			'utm_campaign' => 'sale-10',
			'device'       => 'mobile',
			'pageviews'    => 3,
			'is_bounce'    => 0,
			'duration'     => 90,
			'entry_path'   => '/product/a/',
			'exit_path'    => '/checkout/',
		)
	);
	$direct = dn_bfs_it_seed_session( array( 'started_at' => $day_ts + 60, 'is_new_visitor' => 0 ) );
	$spam   = dn_bfs_it_seed_session( array( 'started_at' => $day_ts + 120, 'is_spam' => 1, 'pageviews' => 50 ) );
	$silent = dn_bfs_it_seed_session( array( 'started_at' => $day_ts + 180, 'pageviews' => 0 ) );

	dn_bfs_it_seed_pageview( $paid, '/product/a/', $day_ts, array( 'time_on_page' => 40 ) );
	dn_bfs_it_seed_pageview( $paid, '/cart/', $day_ts + 30, array( 'time_on_page' => 20 ) );
	dn_bfs_it_seed_pageview( $paid, '/checkout/', $day_ts + 60, array( 'time_on_page' => 30 ) );
	dn_bfs_it_seed_pageview( $direct, '/', $day_ts + 60 );
	dn_bfs_it_seed_pageview( $spam, '/', $day_ts + 120 );

	dn_bfs_it_seed_event( $paid, 'product_view', $day_ts, array( 'product_id' => 101 ) );
	dn_bfs_it_seed_event( $direct, 'product_view', $day_ts + 60, array( 'product_id' => 101 ) );
	dn_bfs_it_seed_event( $paid, 'add_to_cart', $day_ts + 20, array( 'product_id' => 101, 'qty' => 1 ) );
	dn_bfs_it_seed_event( $paid, 'cart', $day_ts + 20, array( 'product_id' => 101, 'qty' => 1 ) );
	dn_bfs_it_seed_event( $paid, 'checkout_start', $day_ts + 60 );
	dn_bfs_it_seed_event( $paid, 'checkout_start', $day_ts + 61 );
	dn_bfs_it_seed_event( $spam, 'product_view', $day_ts + 120, array( 'product_id' => 101 ) );
	dn_bfs_it_seed_event( $silent, 'add_to_cart', $day_ts + 180, array( 'product_id' => 102, 'qty' => 1 ) );
	dn_bfs_it_seed_event( $silent, 'cart', $day_ts + 180, array( 'product_id' => 102, 'qty' => 1 ) );
}

dn_bfs_it(
	'raw traffic totals exclude spam and zero-pageview sessions',
	function () {
		dn_bfs_it_seed_traffic_day( dn_bfs_it_day_noon( 1 ) );
		list( $start, $end ) = dn_bfs_it_day_range( 1 );

		$total = dn_bfs_raw_traffic_rows( $start, $end, 'total', array() )[''];

		dn_bfs_assert_same( 2, $total['sessions'] );
		dn_bfs_assert_same( 2, $total['visitors'] );
		dn_bfs_assert_same( 1, $total['new_visitors'] );
		dn_bfs_assert_same( 4, $total['pageviews'] );
		dn_bfs_assert_same( 1, $total['bounces'] );
		dn_bfs_assert_same( 90, $total['duration_sum'] );
	}
);

dn_bfs_it(
	'raw traffic groups by session dimensions and respects filters',
	function () {
		dn_bfs_it_seed_traffic_day( dn_bfs_it_day_noon( 1 ) );
		list( $start, $end ) = dn_bfs_it_day_range( 1 );

		$channels = dn_bfs_raw_traffic_rows( $start, $end, 'channel', array() );
		dn_bfs_assert_same( 1, $channels['paid']['sessions'] );
		dn_bfs_assert_same( 3, $channels['paid']['pageviews'] );
		dn_bfs_assert_same( 1, $channels['direct']['sessions'] );

		$filtered = dn_bfs_raw_traffic_rows( $start, $end, 'total', array( 'campaign' => 'sale-10' ) )[''];
		dn_bfs_assert_same( 1, $filtered['sessions'] );

		$entry = dn_bfs_raw_traffic_rows( $start, $end, 'entry', array() );
		dn_bfs_assert_same( 1, $entry['/product/a/']['sessions'] );
	}
);

dn_bfs_it(
	'raw page rows count pageviews, visitors, sessions and time on page',
	function () {
		dn_bfs_it_seed_traffic_day( dn_bfs_it_day_noon( 1 ) );
		list( $start, $end ) = dn_bfs_it_day_range( 1 );

		$pages = dn_bfs_raw_traffic_rows( $start, $end, 'page', array() );

		dn_bfs_assert_same( 1, $pages['/cart/']['pageviews'] );
		dn_bfs_assert_same( 20, $pages['/cart/']['duration_sum'] );
		dn_bfs_assert_same( 1, $pages['/']['pageviews'] );
		dn_bfs_assert_true( ! isset( $pages['/']['bounces'] ) || 0 === $pages['/']['bounces'], 'no bounces on page rows' );
	}
);

dn_bfs_it(
	'raw event rows count views, add to cart, cart and distinct checkouts',
	function () {
		dn_bfs_it_seed_traffic_day( dn_bfs_it_day_noon( 1 ) );
		list( $start, $end ) = dn_bfs_it_day_range( 1 );

		$total = dn_bfs_raw_event_rows( $start, $end, 'total', array() )[''];
		dn_bfs_assert_same( 2, $total['product_views'] );
		dn_bfs_assert_same( 2, $total['atc'] );
		dn_bfs_assert_same( 2, $total['carts'] );
		dn_bfs_assert_same( 1, $total['checkouts'] );

		$products = dn_bfs_raw_event_rows( $start, $end, 'product', array() );
		dn_bfs_assert_same( 2, $products['101']['product_views'] );
		dn_bfs_assert_same( 1, $products['102']['atc'] );
		dn_bfs_assert_true( ! isset( $products['0'] ), 'checkout_start has no product row' );

		$paid = dn_bfs_raw_event_rows( $start, $end, 'total', array( 'channel' => 'paid' ) )[''];
		dn_bfs_assert_same( 1, $paid['product_views'] );
	}
);

dn_bfs_it(
	'distinct visitors across days counts a returning visitor once',
	function () {
		$first = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 2 ), 'visitor_uid' => md5( 'same' ) ) );
		dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 1 ), 'visitor_uid' => md5( 'same' ), 'is_new_visitor' => 0 ) );
		dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 1 ), 'visitor_uid' => md5( 'other' ) ) );
		unset( $first );

		list( $start ) = dn_bfs_it_day_range( 2 );
		list( , $end ) = dn_bfs_it_day_range( 1 );

		dn_bfs_assert_same( 2, dn_bfs_raw_distinct_visitors( $start, $end, array() ) );
		dn_bfs_assert_same( 2, dn_bfs_raw_distinct_visitors( $start, $end, array(), true ) );
		dn_bfs_assert_same( 2, dn_bfs_raw_distinct_visitors( $start, $end, array( 'country' => 'VN', 'device' => 'desktop' ) ) );
		dn_bfs_assert_same( 0, dn_bfs_raw_distinct_visitors( $start, $end, array( 'device' => 'mobile' ) ) );
	}
);
```

- [ ] **Step 3: Chạy, xác nhận fail**

Run tích hợp. Expected: 5 test mới FAIL (`Call to undefined function dn_bfs_raw_traffic_rows()`).

- [ ] **Step 4: Tạo `includes/reports/raw.php`**

```php
<?php
/**
 * Raw report engine: computes metrics for a time range, a dimension and filters
 * straight from the tracking tables. The aggregator and the report API share it.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_raw_session_column( $dimension ) {
	$map = array(
		'channel'  => 's.channel',
		'source'   => 's.utm_source',
		'medium'   => 's.utm_medium',
		'campaign' => 's.utm_campaign',
		'referrer' => 's.referrer_host',
		'device'   => 's.device',
		'browser'  => 's.browser',
		'os'       => 's.os',
		'country'  => 's.country',
		'city'     => 's.city',
		'entry'    => 's.entry_path',
		'exit'     => 's.exit_path',
	);

	return isset( $map[ $dimension ] ) ? $map[ $dimension ] : '';
}

/**
 * Events carry their own attribution copy (so fallback orders without a session
 * still group correctly); other dimensions come from the joined session.
 */
function dn_bfs_raw_event_column( $dimension ) {
	$own = array(
		'channel'  => 'e.channel',
		'source'   => 'e.utm_source',
		'medium'   => 'e.utm_medium',
		'campaign' => 'e.utm_campaign',
		'country'  => 'e.country',
		'device'   => 'e.device',
		'product'  => 'e.product_id',
	);

	if ( isset( $own[ $dimension ] ) ) {
		return $own[ $dimension ];
	}

	$column = dn_bfs_raw_session_column( $dimension );

	return '' === $column ? '' : 'COALESCE(' . $column . ", '')";
}

function dn_bfs_raw_filter_sql( $filters, $alias ) {
	global $wpdb;

	$sql = '';

	foreach ( dn_bfs_sanitize_filters( $filters ) as $dimension => $value ) {
		$column = 's' === $alias ? dn_bfs_raw_session_column( $dimension ) : dn_bfs_raw_event_column( $dimension );
		$sql   .= $wpdb->prepare( " AND {$column} = %s", $value );
	}

	return $sql;
}

function dn_bfs_raw_collect( $results ) {
	$rows = array();

	foreach ( (array) $results as $result ) {
		$key          = (string) $result['dim_value'];
		$rows[ $key ] = isset( $rows[ $key ] ) ? dn_bfs_add_metrics( $rows[ $key ], dn_bfs_normalize_metrics( $result ) ) : dn_bfs_normalize_metrics( $result );
	}

	return $rows;
}

function dn_bfs_raw_traffic_rows( $start, $end, $dimension, $filters ) {
	global $wpdb;

	$sessions  = dn_bfs_table( 'sessions' );
	$pageviews = dn_bfs_table( 'pageviews' );
	$where_s   = dn_bfs_raw_filter_sql( $filters, 's' );

	if ( 'product' === $dimension ) {
		return array();
	}

	if ( 'page' === $dimension ) {
		return dn_bfs_raw_collect(
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.path AS dim_value, COUNT(*) AS pageviews, COUNT(DISTINCT p.visitor_uid) AS visitors,
						COUNT(DISTINCT p.session_id) AS sessions, COALESCE(SUM(p.time_on_page), 0) AS duration_sum
					FROM {$pageviews} p INNER JOIN {$sessions} s ON s.id = p.session_id
					WHERE p.time >= %d AND p.time < %d AND s.is_spam = 0 {$where_s}
					GROUP BY p.path",
					(int) $start,
					(int) $end
				),
				ARRAY_A
			)
		);
	}

	$column = 'total' === $dimension ? "''" : dn_bfs_raw_session_column( $dimension );

	if ( '' === $column ) {
		return array();
	}

	$session_rows = dn_bfs_raw_collect(
		$wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$column} AS dim_value, COUNT(*) AS sessions, COUNT(DISTINCT s.visitor_uid) AS visitors,
					COUNT(DISTINCT CASE WHEN s.is_new_visitor = 1 THEN s.visitor_uid END) AS new_visitors,
					SUM(CASE WHEN s.is_bounce = 1 AND s.pageviews = 1 THEN 1 ELSE 0 END) AS bounces,
					COALESCE(SUM(s.duration), 0) AS duration_sum
				FROM {$sessions} s
				WHERE s.started_at >= %d AND s.started_at < %d AND s.is_spam = 0 AND s.pageviews > 0 {$where_s}
				GROUP BY dim_value",
				(int) $start,
				(int) $end
			),
			ARRAY_A
		)
	);

	$pageview_rows = dn_bfs_raw_collect(
		$wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$column} AS dim_value, COUNT(*) AS pageviews
				FROM {$pageviews} p INNER JOIN {$sessions} s ON s.id = p.session_id
				WHERE p.time >= %d AND p.time < %d AND s.is_spam = 0 {$where_s}
				GROUP BY dim_value",
				(int) $start,
				(int) $end
			),
			ARRAY_A
		)
	);

	foreach ( $pageview_rows as $key => $row ) {
		$session_rows[ $key ] = isset( $session_rows[ $key ] ) ? dn_bfs_add_metrics( $session_rows[ $key ], $row ) : $row;
	}

	return $session_rows;
}

function dn_bfs_raw_event_rows( $start, $end, $dimension, $filters ) {
	global $wpdb;

	if ( 'page' === $dimension ) {
		return array();
	}

	$column = 'total' === $dimension ? "''" : dn_bfs_raw_event_column( $dimension );

	if ( '' === $column ) {
		return array();
	}

	$product_only = 'product' === $dimension ? ' AND e.product_id > 0' : '';

	return dn_bfs_raw_collect(
		$wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$column} AS dim_value,
					SUM(CASE WHEN e.type = 'product_view' THEN 1 ELSE 0 END) AS product_views,
					SUM(CASE WHEN e.type = 'add_to_cart' THEN 1 ELSE 0 END) AS atc,
					SUM(CASE WHEN e.type = 'cart' THEN 1 ELSE 0 END) AS carts,
					COUNT(DISTINCT CASE WHEN e.type = 'checkout_start' THEN e.session_id END) AS checkouts
				FROM " . dn_bfs_table( 'events' ) . ' e LEFT JOIN ' . dn_bfs_table( 'sessions' ) . " s ON s.id = e.session_id
				WHERE e.time >= %d AND e.time < %d
					AND e.type IN ('product_view', 'add_to_cart', 'cart', 'checkout_start')
					AND (s.id IS NULL OR s.is_spam = 0)" . dn_bfs_raw_filter_sql( $filters, 'e' ) . $product_only . '
				GROUP BY dim_value',
				(int) $start,
				(int) $end
			),
			ARRAY_A
		)
	);
}

function dn_bfs_raw_distinct_visitors( $start, $end, $filters, $new_only = false ) {
	global $wpdb;

	$new_sql = $new_only ? ' AND s.is_new_visitor = 1' : '';

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(DISTINCT s.visitor_uid) FROM ' . dn_bfs_table( 'sessions' ) . " s
			WHERE s.started_at >= %d AND s.started_at < %d AND s.is_spam = 0 AND s.pageviews > 0{$new_sql}" . dn_bfs_raw_filter_sql( $filters, 's' ),
			(int) $start,
			(int) $end
		)
	);
}
```

- [ ] **Step 5: Nạp module** — trong `dn_burst_funnel_stats_load_reports()` đổi mảng thành `array('reports/metrics', 'reports/wc-settings', 'reports/raw')`.

- [ ] **Step 6: Chạy test**

Run tích hợp → Expected: `53 passed, 0 failed`. Run `php74` → OK.

- [ ] **Step 7: Commit**

```bash
git add includes/reports/raw.php tests/integration/seed.php tests/integration/test-raw-traffic.php dn-burst-funnel-stats.php
git commit -m "feat(reports): add raw engine for traffic and event metrics

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Raw engine — đơn hàng theo trạng thái WooCommerce + gộp `dn_bfs_raw_rows()`

**Files:**
- Modify: `includes/reports/raw.php` (thêm vào cuối)
- Create: `tests/integration/test-raw-orders.php`

**Interfaces:**
- Consumes: Task 2 (`dn_bfs_get_wc_report_settings`, `dn_bfs_order_status_key`), Task 3.
- Produces:
  - `dn_bfs_raw_get_order( int $order_id )` → `WC_Order|false` (cache tĩnh trong request; `dn_bfs_raw_get_order( 0, true )` xóa cache — chỉ dùng trong test).
  - `dn_bfs_order_tip_total( WC_Order $order, array $keywords ): float`.
  - `dn_bfs_raw_order_rows( int $start, int $end, string $dimension, array $filters ): array` → `dim_value => metrics` (orders, revenue, items, tips, paid, balance).
  - `dn_bfs_raw_rows( int $start, int $end, string $dimension, array $filters ): array` → gộp traffic + events + orders.

- [ ] **Step 1: Viết test fail — `tests/integration/test-raw-orders.php`**

```php
<?php

require_once __DIR__ . '/seed.php';

function dn_bfs_it_order( $product_id, $qty, $status, $tip = 0.0 ) {
	$order = wc_create_order();
	$order->add_product( wc_get_product( $product_id ), $qty );

	if ( $tip > 0 ) {
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Tip' );
		$fee->set_total( $tip );
		$order->add_item( $fee );
	}

	$order->calculate_totals();
	$order->set_status( $status );
	$order->save();

	return $order;
}

function dn_bfs_it_first_product() {
	return (int) wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' ) )[0];
}

dn_bfs_it(
	'order rows use current WooCommerce status for sales, paid and balance',
	function () {
		dn_bfs_raw_get_order( 0, true );

		$product = dn_bfs_it_first_product();
		$price   = (float) wc_get_product( $product )->get_price();
		$day     = dn_bfs_it_day_noon( 1 );
		$session = dn_bfs_it_seed_session( array( 'started_at' => $day, 'channel' => 'paid', 'utm_campaign' => 'sale-10', 'browser' => 'Safari' ) );

		$processing = dn_bfs_it_order( $product, 2, 'processing', 3.0 );
		$pending    = dn_bfs_it_order( $product, 1, 'pending' );
		$cancelled  = dn_bfs_it_order( $product, 5, 'cancelled' );

		dn_bfs_it_seed_event( $session, 'order', $day, array( 'order_id' => $processing->get_id() ) );
		dn_bfs_it_seed_event( $session, 'order', $day + 1, array( 'order_id' => $pending->get_id() ) );
		dn_bfs_it_seed_event( $session, 'order', $day + 2, array( 'order_id' => $cancelled->get_id() ) );

		wc_create_refund( array( 'order_id' => $processing->get_id(), 'amount' => 5 ) );
		dn_bfs_raw_get_order( 0, true );

		list( $start, $end ) = dn_bfs_it_day_range( 1 );
		$total = dn_bfs_raw_order_rows( $start, $end, 'total', array() )[''];

		dn_bfs_assert_same( 2, $total['orders'] );
		dn_bfs_assert_same( 3, $total['items'] );
		dn_bfs_assert_same( round( $price * 3 + 3.0 - 5, 4 ), $total['revenue'] );
		dn_bfs_assert_same( 3.0, $total['tips'] );
		dn_bfs_assert_same( round( (float) $processing->get_total(), 4 ), $total['paid'] );
		dn_bfs_assert_same( round( (float) $pending->get_total(), 4 ), $total['balance'] );

		$browsers = dn_bfs_raw_order_rows( $start, $end, 'browser', array() );
		dn_bfs_assert_same( 2, $browsers['Safari']['orders'] );
	}
);

dn_bfs_it(
	'fallback orders without a session group by their own attribution and spam sessions still count',
	function () {
		dn_bfs_raw_get_order( 0, true );

		$product  = dn_bfs_it_first_product();
		$day      = dn_bfs_it_day_noon( 1 );
		$spam     = dn_bfs_it_seed_session( array( 'started_at' => $day, 'is_spam' => 1, 'utm_campaign' => 'spam-camp' ) );
		$fallback = array(
			'id'           => 0,
			'visitor_uid'  => '',
			'channel'      => 'paid',
			'utm_source'   => 'google',
			'utm_medium'   => 'cpc',
			'utm_campaign' => 'spring',
			'country'      => 'US',
			'device'       => '',
		);

		$a = dn_bfs_it_order( $product, 1, 'completed' );
		$b = dn_bfs_it_order( $product, 1, 'completed' );
		dn_bfs_it_seed_event( $fallback, 'order', $day, array( 'order_id' => $a->get_id() ) );
		dn_bfs_it_seed_event( $spam, 'order', $day, array( 'order_id' => $b->get_id() ) );

		list( $start, $end ) = dn_bfs_it_day_range( 1 );
		$campaigns = dn_bfs_raw_order_rows( $start, $end, 'campaign', array() );

		dn_bfs_assert_same( 1, $campaigns['spring']['orders'] );
		dn_bfs_assert_same( 1, $campaigns['spam-camp']['orders'] );
		dn_bfs_assert_same( 1, dn_bfs_raw_order_rows( $start, $end, 'total', array( 'campaign' => 'spring' ) )['']['orders'] );
		dn_bfs_assert_same( 2, dn_bfs_raw_order_rows( $start, $end, 'city', array() )['']['orders'] );
	}
);

dn_bfs_it(
	'product order rows count each order once per product with line totals',
	function () {
		dn_bfs_raw_get_order( 0, true );

		$product = dn_bfs_it_first_product();
		$day     = dn_bfs_it_day_noon( 1 );
		$session = dn_bfs_it_seed_session( array( 'started_at' => $day ) );
		$order   = dn_bfs_it_order( $product, 3, 'processing' );

		dn_bfs_it_seed_event( $session, 'order', $day, array( 'order_id' => $order->get_id() ) );

		list( $start, $end ) = dn_bfs_it_day_range( 1 );
		$rows = dn_bfs_raw_order_rows( $start, $end, 'product', array() );
		$row  = $rows[ (string) $product ];

		dn_bfs_assert_same( 1, $row['orders'] );
		dn_bfs_assert_same( 3, $row['items'] );
		dn_bfs_assert_same( round( (float) $order->get_subtotal(), 4 ), $row['revenue'] );
		dn_bfs_assert_same( array(), dn_bfs_raw_order_rows( $start, $end, 'page', array() ) );
	}
);

dn_bfs_it(
	'raw rows merge traffic, events and orders',
	function () {
		dn_bfs_raw_get_order( 0, true );

		$product = dn_bfs_it_first_product();
		$day     = dn_bfs_it_day_noon( 1 );
		$session = dn_bfs_it_seed_session( array( 'started_at' => $day, 'channel' => 'social' ) );
		dn_bfs_it_seed_pageview( $session, '/', $day );
		dn_bfs_it_seed_event( $session, 'add_to_cart', $day, array( 'product_id' => $product, 'qty' => 1 ) );
		$order = dn_bfs_it_order( $product, 1, 'completed' );
		dn_bfs_it_seed_event( $session, 'order', $day, array( 'order_id' => $order->get_id() ) );

		list( $start, $end ) = dn_bfs_it_day_range( 1 );
		$social = dn_bfs_raw_rows( $start, $end, 'channel', array() )['social'];

		dn_bfs_assert_same( 1, $social['sessions'] );
		dn_bfs_assert_same( 1, $social['pageviews'] );
		dn_bfs_assert_same( 1, $social['atc'] );
		dn_bfs_assert_same( 1, $social['orders'] );
		dn_bfs_assert_same( array(), dn_bfs_raw_rows( $start, $end, 'nope', array() ) );
	}
);
```

- [ ] **Step 2: Chạy, xác nhận fail**

Run tích hợp. Expected: 4 test mới FAIL (`Call to undefined function dn_bfs_raw_get_order()`); 53 test cũ vẫn PASS.

- [ ] **Step 3: Thêm vào cuối `includes/reports/raw.php`**

```php
function dn_bfs_raw_get_order( $order_id, $reset = false ) {
	static $orders = array();

	if ( $reset ) {
		$orders = array();
		return false;
	}

	$order_id = (int) $order_id;

	if ( ! array_key_exists( $order_id, $orders ) ) {
		$order               = $order_id > 0 ? wc_get_order( $order_id ) : false;
		$orders[ $order_id ] = $order instanceof WC_Order ? $order : false;
	}

	return $orders[ $order_id ];
}

function dn_bfs_order_tip_total( $order, $keywords ) {
	$total = 0.0;

	foreach ( $order->get_items( 'fee' ) as $fee ) {
		$name = strtolower( (string) $fee->get_name() );

		foreach ( (array) $keywords as $keyword ) {
			if ( '' !== $keyword && false !== strpos( $name, strtolower( (string) $keyword ) ) ) {
				$total += (float) $fee->get_total();
				break;
			}
		}
	}

	return $total;
}

function dn_bfs_raw_order_rows( $start, $end, $dimension, $filters ) {
	global $wpdb;

	if ( 'page' === $dimension ) {
		return array();
	}

	$column = 'total' === $dimension ? "''" : dn_bfs_raw_event_column( $dimension );

	if ( '' === $column ) {
		return array();
	}

	if ( 'product' === $dimension ) {
		$column = "''";
	}

	// Orders are confirmed revenue: spam sessions are not excluded here.
	$results = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT e.order_id, {$column} AS dim_value
			FROM " . dn_bfs_table( 'events' ) . ' e LEFT JOIN ' . dn_bfs_table( 'sessions' ) . " s ON s.id = e.session_id
			WHERE e.type = 'order' AND e.time >= %d AND e.time < %d" . dn_bfs_raw_filter_sql( $filters, 'e' ),
			(int) $start,
			(int) $end
		),
		ARRAY_A
	);

	$settings = dn_bfs_get_wc_report_settings();
	$sums     = array();

	foreach ( (array) $results as $result ) {
		$order = dn_bfs_raw_get_order( (int) $result['order_id'] );

		if ( ! $order ) {
			continue;
		}

		$status   = dn_bfs_order_status_key( $order );
		$is_sale  = ! in_array( $status, $settings['sales_excluded_statuses'], true );
		$order_total = (float) $order->get_total();

		if ( 'product' === $dimension ) {
			if ( ! $is_sale ) {
				continue;
			}

			$seen = array();

			foreach ( $order->get_items() as $item ) {
				$key = (string) (int) $item->get_product_id();

				if ( ! isset( $sums[ $key ] ) ) {
					$sums[ $key ] = dn_bfs_empty_metrics();
				}

				if ( ! isset( $seen[ $key ] ) ) {
					$sums[ $key ]['orders']++;
					$seen[ $key ] = true;
				}

				$sums[ $key ]['revenue'] += (float) $item->get_total();
				$sums[ $key ]['items']   += (int) $item->get_quantity();
			}

			continue;
		}

		$key = (string) $result['dim_value'];

		if ( ! isset( $sums[ $key ] ) ) {
			$sums[ $key ] = dn_bfs_empty_metrics();
		}

		if ( $is_sale ) {
			$sums[ $key ]['orders']++;
			$sums[ $key ]['revenue'] += max( 0.0, $order_total - (float) $order->get_total_refunded() );
			$sums[ $key ]['items']   += (int) $order->get_item_count();
			$sums[ $key ]['tips']    += dn_bfs_order_tip_total( $order, $settings['tip_keywords'] );
		}

		if ( in_array( $status, $settings['paid_statuses'], true ) ) {
			$sums[ $key ]['paid'] += $order_total;
		}

		if ( in_array( $status, $settings['balance_statuses'], true ) ) {
			$sums[ $key ]['balance'] += $order_total;
		}
	}

	return array_map( 'dn_bfs_normalize_metrics', $sums );
}

function dn_bfs_raw_rows( $start, $end, $dimension, $filters ) {
	if ( ! in_array( $dimension, dn_bfs_aggregate_dimensions(), true ) ) {
		return array();
	}

	$rows = array();

	foreach ( array( 'dn_bfs_raw_traffic_rows', 'dn_bfs_raw_event_rows', 'dn_bfs_raw_order_rows' ) as $source ) {
		foreach ( call_user_func( $source, $start, $end, $dimension, $filters ) as $key => $metrics ) {
			$key          = (string) $key;
			$rows[ $key ] = isset( $rows[ $key ] ) ? dn_bfs_add_metrics( $rows[ $key ], $metrics ) : $metrics;
		}
	}

	return $rows;
}
```

- [ ] **Step 4: Chạy test**

Run tích hợp → Expected: `57 passed, 0 failed`. Run unit → OK. Run `php74` → OK.

- [ ] **Step 5: Commit**

```bash
git add includes/reports/raw.php tests/integration/test-raw-orders.php
git commit -m "feat(reports): add order metrics by current WooCommerce status and merged raw rows

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Aggregator theo ngày, ngày cần làm lại, cron chạy bù

**Files:**
- Create: `includes/tracking/aggregator.php`, `tests/integration/test-aggregator.php`
- Modify: `dn-burst-funnel-stats.php` (nạp module, lập lịch cron, deactivation)

**Interfaces:**
- Consumes: Task 4 (`dn_bfs_raw_rows`, `dn_bfs_raw_order_rows`), Task 2 (dimension/column lists).
- Produces:
  - `dn_bfs_day_bounds( string $date ): array` → `array( int $start, int $end )` theo múi giờ site.
  - `dn_bfs_raw_cutoff_date( int $now ): string`.
  - `dn_bfs_daily_write_rows( string $date, string $dimension, array $rows, array $columns = array() ): void` — upsert; `$columns` rỗng = mọi cột chỉ số.
  - `dn_bfs_aggregate_day( string $date, int $now ): string` → `'full'` hoặc `'orders'`.
  - `dn_bfs_mark_dirty_date( string $date, int $now = 0 ): void` (bỏ qua ngày ≥ hôm nay / sai định dạng) — mỗi ngày một option `dnbfs_dirty_<Y-m-d>` (autoload no, `add_option`), không mất khi đánh dấu đồng thời.
  - `dn_bfs_get_dirty_dates(): array` → danh sách `Y-m-d` đã sắp xếp (truy vấn `LIKE 'dnbfs\_dirty\_%'`); runner xóa option của ngày **trước** khi tổng hợp lại (đánh dấu lại trong lúc chạy vẫn còn cho lần sau).
  - Action `dn_bfs_before_aggregate_day( string $date )` ở đầu `dn_bfs_aggregate_day()`; filter `dn_bfs_aggregate_time_budget` (giây, mặc định 25; kiểm tra trước mỗi ngày trừ ngày đầu, nên mỗi lần chạy luôn xử lý ≥ 1 ngày).
  - `dn_bfs_first_tracked_date(): string` (`''` khi chưa có dữ liệu).
  - `dn_bfs_aggregate_run( $now = null, $max_days = 31 ): array` → `array( 'ok' => bool, 'reason' => string, 'processed' => string[] )`; option `dnbfs_last_aggregated_date`, khóa `dnbfs_aggregate_lock` dạng `"<expiry>|<token>"` (hết hạn 10 phút, token `wp_generate_password( 12, false )`; lấy khóa bằng chèn nguyên tử, khóa hết hạn bị chiếm lại; gia hạn sau mỗi ngày và nhả chỉ khi token khớp).
  - `dn_bfs_schedule_crons(): void` (hook `dnbfs_aggregate` hourly, `dnbfs_cleanup` daily), `dn_bfs_unschedule_crons(): void`.
  - Hooks: `woocommerce_order_status_changed`, `woocommerce_order_refunded`, `dn_bfs_session_marked_spam` → đánh dấu ngày cần làm lại.

- [ ] **Step 1: Viết test fail — `tests/integration/test-aggregator.php`**

```php
<?php

require_once __DIR__ . '/seed.php';

function dn_bfs_it_daily( $date, $dimension, $value = '' ) {
	global $wpdb;

	return $wpdb->get_row(
		$wpdb->prepare( 'SELECT * FROM ' . dn_bfs_table( 'daily' ) . ' WHERE date = %s AND dimension = %s AND dim_hash = %s', $date, $dimension, md5( $value ) ),
		ARRAY_A
	);
}

function dn_bfs_it_reset_aggregator_state() {
	delete_option( 'dnbfs_last_aggregated_date' );
	delete_option( 'dnbfs_dirty_dates' );
	delete_option( 'dnbfs_aggregate_lock' );
	dn_bfs_raw_get_order( 0, true );
}

dn_bfs_it(
	'aggregate day writes every dimension and keeps blocked counters',
	function () {
		dn_bfs_it_reset_aggregator_state();
		$day_ts = dn_bfs_it_day_noon( 1 );
		$date   = wp_date( 'Y-m-d', $day_ts );

		$session = dn_bfs_it_seed_session( array( 'started_at' => $day_ts, 'channel' => 'paid', 'utm_campaign' => 'sale-10', 'pageviews' => 2, 'is_bounce' => 0 ) );
		dn_bfs_it_seed_pageview( $session, '/', $day_ts );
		dn_bfs_it_seed_pageview( $session, '/cart/', $day_ts + 10 );
		dn_bfs_it_seed_event( $session, 'product_view', $day_ts, array( 'product_id' => 101 ) );
		dn_bfs_store_count_blocked( 'bot', $day_ts );

		dn_bfs_assert_same( 'full', dn_bfs_aggregate_day( $date, time() ) );
		dn_bfs_assert_same( 'full', dn_bfs_aggregate_day( $date, time() ) );

		dn_bfs_assert_same( '1', dn_bfs_it_daily( $date, 'total' )['sessions'] );
		dn_bfs_assert_same( '2', dn_bfs_it_daily( $date, 'total' )['pageviews'] );
		dn_bfs_assert_same( '1', dn_bfs_it_daily( $date, 'campaign', 'sale-10' )['sessions'] );
		dn_bfs_assert_same( '1', dn_bfs_it_daily( $date, 'page', '/cart/' )['pageviews'] );
		dn_bfs_assert_same( '1', dn_bfs_it_daily( $date, 'product', '101' )['product_views'] );
		dn_bfs_assert_same( '1', dn_bfs_it_daily( $date, 'blocked', 'bot' )['pageviews'] );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'daily', $GLOBALS['wpdb']->prepare( "date = %s AND dimension = 'total'", $date ) ) );
	}
);

dn_bfs_it(
	'dates older than raw retention only recompute order columns',
	function () {
		global $wpdb;

		dn_bfs_it_reset_aggregator_state();
		dn_bfs_it_settings( array( 'raw_retention_days' => 7 ) );

		$day_ts = dn_bfs_it_day_noon( 10 );
		$date   = wp_date( 'Y-m-d', $day_ts );
		$wpdb->insert( dn_bfs_table( 'daily' ), array( 'date' => $date, 'dimension' => 'total', 'dim_hash' => md5( '' ), 'dim_value' => '', 'sessions' => 5, 'orders' => 9 ) );

		$product = (int) wc_get_products( array( 'limit' => 1, 'return' => 'ids' ) )[0];
		$order   = wc_create_order();
		$order->add_product( wc_get_product( $product ), 1 );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->save();
		dn_bfs_it_seed_event( array( 'id' => 0, 'visitor_uid' => '', 'channel' => 'direct', 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'country' => '', 'device' => '' ), 'order', $day_ts, array( 'order_id' => $order->get_id() ) );

		dn_bfs_assert_same( 'orders', dn_bfs_aggregate_day( $date, time() ) );

		$row = dn_bfs_it_daily( $date, 'total' );
		dn_bfs_assert_same( '5', $row['sessions'] );
		dn_bfs_assert_same( '1', $row['orders'] );
	}
);

dn_bfs_it(
	'order status change and spam marking flag past dates as dirty',
	function () {
		dn_bfs_it_reset_aggregator_state();
		$day_ts  = dn_bfs_it_day_noon( 2 );
		$date    = wp_date( 'Y-m-d', $day_ts );
		$session = dn_bfs_it_seed_session( array( 'started_at' => $day_ts ) );

		$product = (int) wc_get_products( array( 'limit' => 1, 'return' => 'ids' ) )[0];
		$order   = wc_create_order();
		$order->add_product( wc_get_product( $product ), 1 );
		$order->calculate_totals();
		$order->save();
		dn_bfs_it_seed_event( $session, 'order', $day_ts, array( 'order_id' => $order->get_id() ) );

		$order->update_status( 'cancelled' );
		dn_bfs_assert_true( isset( get_option( 'dnbfs_dirty_dates' )[ $date ] ), 'order date dirty' );

		delete_option( 'dnbfs_dirty_dates' );
		dn_bfs_store_mark_spam( (int) $session['id'] );
		dn_bfs_assert_true( isset( get_option( 'dnbfs_dirty_dates' )[ $date ] ), 'spam date dirty' );

		dn_bfs_mark_dirty_date( wp_date( 'Y-m-d', time() ) );
		dn_bfs_assert_true( ! isset( get_option( 'dnbfs_dirty_dates' )[ wp_date( 'Y-m-d', time() ) ] ), 'today never dirty' );
	}
);

dn_bfs_it(
	'aggregate run catches up from the first tracked date and drains dirty dates',
	function () {
		dn_bfs_it_reset_aggregator_state();

		dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 3 ) ) );
		$result    = dn_bfs_aggregate_run( time() );
		$yesterday = dn_bfs_date_shift( wp_date( 'Y-m-d', time() ), -1 );

		dn_bfs_assert_true( $result['ok'], 'ok' );
		dn_bfs_assert_same( 3, count( $result['processed'] ) );
		dn_bfs_assert_same( $yesterday, get_option( 'dnbfs_last_aggregated_date' ) );

		$dirty = dn_bfs_date_shift( $yesterday, -5 );
		update_option( 'dnbfs_dirty_dates', array( $dirty => true ), false );
		$again = dn_bfs_aggregate_run( time() );

		dn_bfs_assert_same( array( $dirty ), $again['processed'] );
		dn_bfs_assert_same( array(), get_option( 'dnbfs_dirty_dates' ) );
	}
);

dn_bfs_it(
	'aggregate run respects the lock and an empty install starts at yesterday',
	function () {
		dn_bfs_it_reset_aggregator_state();

		update_option( 'dnbfs_aggregate_lock', time() + 300, false );
		dn_bfs_assert_same( 'locked', dn_bfs_aggregate_run( time() )['reason'] );
		delete_option( 'dnbfs_aggregate_lock' );

		$result = dn_bfs_aggregate_run( time() );
		dn_bfs_assert_same( array(), $result['processed'] );
		dn_bfs_assert_same( dn_bfs_date_shift( wp_date( 'Y-m-d', time() ), -1 ), get_option( 'dnbfs_last_aggregated_date' ) );
		dn_bfs_assert_true( false === get_option( 'dnbfs_aggregate_lock' ), 'lock released' );
	}
);

dn_bfs_it(
	'crons are scheduled',
	function () {
		dn_bfs_schedule_crons();

		dn_bfs_assert_true( (bool) wp_next_scheduled( 'dnbfs_aggregate' ), 'aggregate scheduled' );
		dn_bfs_assert_true( (bool) wp_next_scheduled( 'dnbfs_cleanup' ), 'cleanup scheduled' );
	}
);
```

- [ ] **Step 2: Chạy, xác nhận fail**

Run tích hợp. Expected: 6 test mới FAIL (`Call to undefined function dn_bfs_aggregate_day()`).

- [ ] **Step 3: Tạo `includes/tracking/aggregator.php`**

```php
<?php
/**
 * Daily aggregation of raw tracking data into dnbfs_daily.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_day_bounds( $date ) {
	$start = new DateTimeImmutable( $date . ' 00:00:00', wp_timezone() );

	return array( $start->getTimestamp(), $start->modify( '+1 day' )->getTimestamp() );
}

function dn_bfs_raw_cutoff_date( $now ) {
	$settings = dn_bfs_get_tracking_settings();

	return dn_bfs_date_shift( wp_date( 'Y-m-d', (int) $now ), -1 * (int) $settings['raw_retention_days'] );
}

function dn_bfs_daily_write_rows( $date, $dimension, $rows, $columns = array() ) {
	global $wpdb;

	$columns = empty( $columns ) ? dn_bfs_metric_columns() : array_values( $columns );
	$table   = dn_bfs_table( 'daily' );
	$updates = array();

	foreach ( $columns as $column ) {
		$updates[] = "{$column} = VALUES({$column})";
	}

	foreach ( $rows as $value => $metrics ) {
		$value        = dn_bfs_truncate( (string) $value, 255 );
		$placeholders = array();
		$args         = array( $date, $dimension, md5( $value ), $value );

		foreach ( $columns as $column ) {
			$placeholders[] = in_array( $column, dn_bfs_money_columns(), true ) ? '%f' : '%d';
			$args[]         = $metrics[ $column ];
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (date, dimension, dim_hash, dim_value, " . implode( ', ', $columns ) . ')
				VALUES (%s, %s, %s, %s, ' . implode( ', ', $placeholders ) . ')
				ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates ),
				$args
			)
		);
	}
}

function dn_bfs_aggregate_day( $date, $now ) {
	global $wpdb;

	list( $start, $end ) = dn_bfs_day_bounds( $date );
	$table               = dn_bfs_table( 'daily' );

	if ( $date >= dn_bfs_raw_cutoff_date( $now ) ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE date = %s AND dimension <> 'blocked'", $date ) );

		foreach ( dn_bfs_aggregate_dimensions() as $dimension ) {
			dn_bfs_daily_write_rows( $date, $dimension, dn_bfs_raw_rows( $start, $end, $dimension, array() ) );
		}

		return 'full';
	}

	// Raw traffic is gone; order events are kept forever, so only order columns are rebuilt.
	$resets = array();

	foreach ( dn_bfs_order_columns() as $column ) {
		$resets[] = "{$column} = 0";
	}

	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $resets ) . " WHERE date = %s AND dimension <> 'blocked'", $date ) );

	foreach ( dn_bfs_order_dimensions() as $dimension ) {
		dn_bfs_daily_write_rows( $date, $dimension, dn_bfs_raw_order_rows( $start, $end, $dimension, array() ), dn_bfs_order_columns() );
	}

	return 'orders';
}

function dn_bfs_mark_dirty_date( $date, $now = 0 ) {
	$now = $now ? (int) $now : dn_bfs_now();

	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) || $date >= wp_date( 'Y-m-d', $now ) ) {
		return;
	}

	$dirty = get_option( 'dnbfs_dirty_dates', array() );
	$dirty = is_array( $dirty ) ? $dirty : array();

	$dirty[ $date ] = true;
	update_option( 'dnbfs_dirty_dates', $dirty, false );
}

function dn_bfs_mark_order_dirty( $order_id ) {
	global $wpdb;

	$time = $wpdb->get_var( $wpdb->prepare( 'SELECT time FROM ' . dn_bfs_table( 'events' ) . ' WHERE order_id = %d', (int) $order_id ) );

	if ( $time ) {
		dn_bfs_mark_dirty_date( wp_date( 'Y-m-d', (int) $time ) );
	}
}
add_action( 'woocommerce_order_status_changed', 'dn_bfs_mark_order_dirty', 10, 1 );
add_action( 'woocommerce_order_refunded', 'dn_bfs_mark_order_dirty', 10, 1 );

function dn_bfs_mark_spam_session_dirty( $session_id, $started_at ) {
	unset( $session_id );

	if ( $started_at ) {
		dn_bfs_mark_dirty_date( wp_date( 'Y-m-d', (int) $started_at ) );
	}
}
add_action( 'dn_bfs_session_marked_spam', 'dn_bfs_mark_spam_session_dirty', 10, 2 );

function dn_bfs_first_tracked_date() {
	global $wpdb;

	$times = array_filter(
		array(
			(int) $wpdb->get_var( 'SELECT MIN(started_at) FROM ' . dn_bfs_table( 'sessions' ) ),
			(int) $wpdb->get_var( 'SELECT MIN(time) FROM ' . dn_bfs_table( 'events' ) ),
		)
	);

	return empty( $times ) ? '' : wp_date( 'Y-m-d', min( $times ) );
}

function dn_bfs_aggregate_run( $now = null, $max_days = 31 ) {
	$now  = null === $now ? dn_bfs_now() : (int) $now;
	$lock = (int) get_option( 'dnbfs_aggregate_lock', 0 );

	if ( $lock > $now ) {
		return array(
			'ok'        => false,
			'reason'    => 'locked',
			'processed' => array(),
		);
	}

	update_option( 'dnbfs_aggregate_lock', $now + 10 * MINUTE_IN_SECONDS, false );

	$today     = wp_date( 'Y-m-d', $now );
	$yesterday = dn_bfs_date_shift( $today, -1 );
	$last      = (string) get_option( 'dnbfs_last_aggregated_date', '' );
	$processed = array();

	if ( '' === $last ) {
		$first = dn_bfs_first_tracked_date();
		$last  = '' === $first || $first > $yesterday ? $yesterday : dn_bfs_date_shift( $first, -1 );
		update_option( 'dnbfs_last_aggregated_date', $last, false );
	}

	for ( $cursor = dn_bfs_date_shift( $last, 1 ); $cursor <= $yesterday && count( $processed ) < $max_days; $cursor = dn_bfs_date_shift( $cursor, 1 ) ) {
		dn_bfs_aggregate_day( $cursor, $now );
		update_option( 'dnbfs_last_aggregated_date', $cursor, false );
		$processed[] = $cursor;
	}

	$dirty = get_option( 'dnbfs_dirty_dates', array() );
	$dirty = is_array( $dirty ) ? $dirty : array();
	$done  = 0;

	foreach ( array_keys( $dirty ) as $date ) {
		if ( $done >= $max_days ) {
			break;
		}

		if ( $date < $today && ! in_array( $date, $processed, true ) ) {
			dn_bfs_aggregate_day( $date, $now );
			$processed[] = $date;
		}

		unset( $dirty[ $date ] );
		$done++;
	}

	update_option( 'dnbfs_dirty_dates', $dirty, false );
	delete_option( 'dnbfs_aggregate_lock' );

	return array(
		'ok'        => true,
		'reason'    => '',
		'processed' => $processed,
	);
}

function dn_bfs_cron_aggregate() {
	dn_bfs_aggregate_run();
}
add_action( 'dnbfs_aggregate', 'dn_bfs_cron_aggregate' );

function dn_bfs_schedule_crons() {
	if ( ! wp_next_scheduled( 'dnbfs_aggregate' ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'dnbfs_aggregate' );
	}

	if ( ! wp_next_scheduled( 'dnbfs_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dnbfs_cleanup' );
	}
}

function dn_bfs_unschedule_crons() {
	wp_clear_scheduled_hook( 'dnbfs_aggregate' );
	wp_clear_scheduled_hook( 'dnbfs_cleanup' );
}
```

- [ ] **Step 4: Nối vào plugin** — trong `dn-burst-funnel-stats.php`:
1. `dn_burst_funnel_stats_load_tracking()`: thêm `'aggregator'` vào cuối mảng module.
2. `dn_burst_funnel_stats_bootstrap()`: ngay sau `dn_burst_funnel_stats_maybe_migrate();` thêm `  dn_bfs_schedule_crons();`.
3. Ngay sau dòng `register_activation_hook(__FILE__, 'dn_burst_funnel_stats_activate');` thêm:

```php

/**
 * Remove plugin cron events on deactivation.
 *
 * @return void
 */
function dn_burst_funnel_stats_deactivate()
{
  if (function_exists('dn_bfs_unschedule_crons')) {
    dn_bfs_unschedule_crons();
  }
}
register_deactivation_hook(__FILE__, 'dn_burst_funnel_stats_deactivate');
```

- [ ] **Step 5: Chạy test**

Run tích hợp → Expected: `63 passed, 0 failed`. Run `php74` → OK.

- [ ] **Step 6: Commit**

```bash
git add includes/tracking/aggregator.php tests/integration/test-aggregator.php dn-burst-funnel-stats.php
git commit -m "feat(tracking): aggregate raw data into daily rows with catch-up and dirty-date reruns

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Dọn dữ liệu cũ + gỡ cài đặt sạch

**Files:**
- Create: `includes/tracking/cleanup.php`, `tests/integration/test-cleanup.php`, `tests/integration/test-uninstall.php`
- Modify: `uninstall.php` (viết lại toàn bộ), `dn-burst-funnel-stats.php` (nạp module)

**Interfaces:**
- Consumes: Task 5 (`dn_bfs_raw_cutoff_date`, `dn_bfs_day_bounds`), Task 2 (`dn_bfs_date_shift`).
- Produces:
  - `dn_bfs_cleanup_delete( string $sql_with_limit ): int` — lặp xóa theo lô cho tới khi hết hoặc 200 lô.
  - `dn_bfs_purge_salts( int $now ): int`.
  - `dn_bfs_cleanup_run( $now = null ): array` → `array( 'pageviews', 'events', 'sessions', 'visitors', 'salts' )` (số dòng/option đã xóa). Gọi `dn_bfs_maybe_update_geoip( $now )` nếu hàm tồn tại (Task 7).
  - Hook `dnbfs_cleanup` → `dn_bfs_cleanup_run()`.

- [ ] **Step 1: Viết test fail — `tests/integration/test-cleanup.php`**

```php
<?php

require_once __DIR__ . '/seed.php';

dn_bfs_it(
	'cleanup purges raw rows older than retention but keeps order events and recent data',
	function () {
		dn_bfs_it_settings( array( 'raw_retention_days' => 7 ) );
		update_option( 'dnbfs_last_aggregated_date', dn_bfs_date_shift( wp_date( 'Y-m-d', time() ), -1 ), false );

		$old    = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 10 ) ) );
		$recent = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 2 ) ) );
		dn_bfs_it_seed_pageview( $old, '/', dn_bfs_it_day_noon( 10 ) );
		dn_bfs_it_seed_pageview( $recent, '/', dn_bfs_it_day_noon( 2 ) );
		dn_bfs_it_seed_event( $old, 'product_view', dn_bfs_it_day_noon( 10 ), array( 'product_id' => 5 ) );
		dn_bfs_it_seed_event( $old, 'order', dn_bfs_it_day_noon( 10 ), array( 'order_id' => 999001 ) );

		$result = dn_bfs_cleanup_run( time() );

		dn_bfs_assert_same( 1, $result['sessions'] );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'sessions' ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'pageviews' ) );
		dn_bfs_assert_same( 0, dn_bfs_it_count( 'events', "type = 'product_view'" ) );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'events', "type = 'order'" ) );
	}
);

dn_bfs_it(
	'cleanup never purges days that were not aggregated yet',
	function () {
		dn_bfs_it_settings( array( 'raw_retention_days' => 7 ) );
		update_option( 'dnbfs_last_aggregated_date', dn_bfs_date_shift( wp_date( 'Y-m-d', time() ), -12 ), false );

		dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 10 ) ) );
		dn_bfs_cleanup_run( time() );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'sessions' ) );

		delete_option( 'dnbfs_last_aggregated_date' );
		dn_bfs_cleanup_run( time() );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'sessions' ) );
	}
);

dn_bfs_it(
	'cleanup removes visitors unseen for 400 days and salts older than yesterday',
	function () {
		global $wpdb;

		$now = time();
		$wpdb->insert( dn_bfs_table( 'visitors' ), array( 'visitor_uid' => md5( 'old' ), 'first_seen' => $now - 500 * DAY_IN_SECONDS, 'last_seen' => $now - 401 * DAY_IN_SECONDS, 'sessions_count' => 1 ) );
		$wpdb->insert( dn_bfs_table( 'visitors' ), array( 'visitor_uid' => md5( 'new' ), 'first_seen' => $now, 'last_seen' => $now, 'sessions_count' => 1 ) );

		$today     = wp_date( 'Y-m-d', $now );
		$yesterday = dn_bfs_date_shift( $today, -1 );
		$old_day   = dn_bfs_date_shift( $today, -5 );

		foreach ( array( $today, $yesterday, $old_day ) as $date ) {
			update_option( 'dnbfs_salt_' . $date, 'salt', false );
		}

		$result = dn_bfs_cleanup_run( $now );

		dn_bfs_assert_same( 1, $result['visitors'] );
		dn_bfs_assert_same( 1, dn_bfs_it_count( 'visitors' ) );
		dn_bfs_assert_true( false === get_option( 'dnbfs_salt_' . $old_day ), 'old salt gone' );
		dn_bfs_assert_same( 'salt', get_option( 'dnbfs_salt_' . $yesterday ) );
		dn_bfs_assert_same( 'salt', get_option( 'dnbfs_salt_' . $today ) );
	}
);
```

- [ ] **Step 2: Viết test fail — `tests/integration/test-uninstall.php`**

```php
<?php

dn_bfs_it(
	'uninstall drops tables, options, crons and the GeoIP folder',
	function () {
		global $wpdb;

		update_option( 'dnbfs_last_aggregated_date', '2026-01-01', false );
		update_option( 'dn_bfs_data_last_changed', '1', false );
		update_option( 'dn_atc_hits_2026_01_01', 3, false );
		set_transient( 'dnbfs_r_test', array( 1 ), 60 );
		dn_bfs_schedule_crons();
		wp_mkdir_p( dirname( dn_bfs_geo_db_path() ) );
		file_put_contents( dn_bfs_geo_db_path(), 'x' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'dn-burst-funnel-stats/dn-burst-funnel-stats.php' );
		}

		include dirname( __DIR__, 2 ) . '/uninstall.php';

		foreach ( dn_bfs_schema_tables() as $name ) {
			dn_bfs_assert_same( null, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', dn_bfs_table( $name ) ) ), $name );
		}

		dn_bfs_assert_true( false === get_option( 'dnbfs_last_aggregated_date' ), 'dnbfs option' );
		dn_bfs_assert_true( false === get_option( 'dn_bfs_data_last_changed' ), 'dn_bfs option' );
		dn_bfs_assert_true( false === get_option( 'dn_atc_hits_2026_01_01' ), 'legacy option' );
		dn_bfs_assert_true( false === get_option( 'dn_burst_funnel_stats_tracking_settings' ), 'settings' );
		dn_bfs_assert_true( false === get_transient( 'dnbfs_r_test' ), 'transient' );
		dn_bfs_assert_true( false === wp_next_scheduled( 'dnbfs_aggregate' ), 'cron' );
		dn_bfs_assert_true( ! file_exists( dn_bfs_geo_db_path() ), 'geoip file' );

		// Restore the environment for the remaining tests.
		dn_bfs_install_schema();
		update_option( 'dn_burst_funnel_stats_schema_version', DN_BURST_FUNNEL_STATS_SCHEMA_VERSION, false );
		dn_bfs_it_settings( array() );
		dn_bfs_schedule_crons();
	}
);
```

- [ ] **Step 3: Chạy, xác nhận fail**

Run tích hợp. Expected: 3 test cleanup FAIL (`Call to undefined function dn_bfs_cleanup_run()`), test uninstall FAIL (bảng `dnbfs_*` vẫn còn).

- [ ] **Step 4: Tạo `includes/tracking/cleanup.php`**

```php
<?php
/**
 * Daily housekeeping: raw-data retention, stale visitors, old salts, GeoIP refresh.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_cleanup_delete( $sql_with_limit ) {
	global $wpdb;

	$total = 0;

	for ( $batch = 0; $batch < 200; $batch++ ) {
		$deleted = (int) $wpdb->query( $sql_with_limit );
		$total  += $deleted;

		if ( $deleted < 5000 ) {
			break;
		}
	}

	return $total;
}

function dn_bfs_purge_salts( $now ) {
	global $wpdb;

	$keep    = array(
		'dnbfs_salt_' . wp_date( 'Y-m-d', $now ),
		'dnbfs_salt_' . dn_bfs_date_shift( wp_date( 'Y-m-d', $now ), -1 ),
	);
	$names   = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'dnbfs_salt_' ) . '%' ) );
	$removed = 0;

	foreach ( (array) $names as $name ) {
		if ( ! in_array( $name, $keep, true ) && delete_option( $name ) ) {
			$removed++;
		}
	}

	return $removed;
}

function dn_bfs_cleanup_run( $now = null ) {
	global $wpdb;

	$now    = null === $now ? dn_bfs_now() : (int) $now;
	$result = array(
		'pageviews' => 0,
		'events'    => 0,
		'sessions'  => 0,
		'visitors'  => 0,
		'salts'     => 0,
	);

	$last = (string) get_option( 'dnbfs_last_aggregated_date', '' );

	// Never drop raw rows for a day that has not been aggregated yet.
	if ( '' !== $last ) {
		$cutoff = min( dn_bfs_raw_cutoff_date( $now ), dn_bfs_date_shift( $last, 1 ) );
		list( $before ) = dn_bfs_day_bounds( $cutoff );

		$result['pageviews'] = dn_bfs_cleanup_delete( $wpdb->prepare( 'DELETE FROM ' . dn_bfs_table( 'pageviews' ) . ' WHERE time < %d LIMIT 5000', $before ) );
		$result['events']    = dn_bfs_cleanup_delete( $wpdb->prepare( 'DELETE FROM ' . dn_bfs_table( 'events' ) . " WHERE time < %d AND type <> 'order' LIMIT 5000", $before ) );
		$result['sessions']  = dn_bfs_cleanup_delete( $wpdb->prepare( 'DELETE FROM ' . dn_bfs_table( 'sessions' ) . ' WHERE started_at < %d LIMIT 5000', $before ) );
	}

	$result['visitors'] = dn_bfs_cleanup_delete( $wpdb->prepare( 'DELETE FROM ' . dn_bfs_table( 'visitors' ) . ' WHERE last_seen < %d LIMIT 5000', $now - 400 * DAY_IN_SECONDS ) );
	$result['salts']    = dn_bfs_purge_salts( $now );

	if ( function_exists( 'dn_bfs_maybe_update_geoip' ) ) {
		dn_bfs_maybe_update_geoip( $now );
	}

	return $result;
}

function dn_bfs_cron_cleanup() {
	dn_bfs_cleanup_run();
}
add_action( 'dnbfs_cleanup', 'dn_bfs_cron_cleanup' );
```

- [ ] **Step 5: Viết lại `uninstall.php`**

```php
<?php
/**
 * Uninstall cleanup: tables, options, transients, cron events and GeoIP files.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'visitors', 'sessions', 'pageviews', 'events', 'daily', 'api_keys' ) as $dn_bfs_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'dnbfs_' . $dn_bfs_table );
}

$dn_bfs_option_patterns = array(
	'dnbfs\_%',
	'dn\_bfs\_%',
	'dn\_burst\_funnel\_stats\_%',
	'dn\_atc\_%',
	'\_transient\_dnbfs\_%',
	'\_transient\_timeout\_dnbfs\_%',
	'\_transient\_dn\_bfs\_%',
	'\_transient\_timeout\_dn\_bfs\_%',
	'\_transient\_dn\_atc\_%',
	'\_transient\_timeout\_dn\_atc\_%',
	'\_site\_transient\_dn\_burst\_funnel\_stats\_%',
	'\_site\_transient\_timeout\_dn\_burst\_funnel\_stats\_%',
);

foreach ( $dn_bfs_option_patterns as $dn_bfs_pattern ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $dn_bfs_pattern ) );
}

wp_cache_flush();

foreach ( array( 'dnbfs_aggregate', 'dnbfs_cleanup', 'dn_burst_funnel_stats_refresh_cache' ) as $dn_bfs_hook ) {
	wp_clear_scheduled_hook( $dn_bfs_hook );
}

delete_metadata( 'user', 0, 'dnbfs_cards', '', true );

$dn_bfs_uploads = wp_upload_dir( null, false );
$dn_bfs_dir     = trailingslashit( $dn_bfs_uploads['basedir'] ) . 'dnbfs';

if ( is_dir( $dn_bfs_dir ) ) {
	foreach ( (array) glob( $dn_bfs_dir . '/*' ) as $dn_bfs_file ) {
		if ( is_file( $dn_bfs_file ) ) {
			unlink( $dn_bfs_file );
		}
	}

	rmdir( $dn_bfs_dir );
}
```

- [ ] **Step 6: Nạp module** — thêm `'cleanup'` vào cuối mảng module của `dn_burst_funnel_stats_load_tracking()`.

- [ ] **Step 7: Chạy test**

Run tích hợp → Expected: `67 passed, 0 failed` (test uninstall khôi phục môi trường nên các test sau nó vẫn PASS). Run `php74` → OK.

- [ ] **Step 8: Commit**

```bash
git add includes/tracking/cleanup.php uninstall.php tests/integration/test-cleanup.php tests/integration/test-uninstall.php dn-burst-funnel-stats.php
git commit -m "feat(tracking): purge expired raw data and salts; full uninstall cleanup

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Tự cập nhật GeoLite2

**Files:**
- Create: `includes/tracking/geoip-update.php`, `tests/integration/test-geoip-update.php`
- Modify: `dn-burst-funnel-stats.php` (nạp module)

**Interfaces:**
- Consumes: `dn_bfs_geo_db_path()`, `dn_bfs_geo_from_mmdb()` (geo.php), `lib/maxmind-db/autoload.php`.
- Produces:
  - `dn_bfs_geoip_download_url( string $license_key, string $suffix ): string` (filter `dn_bfs_geoip_download_url`).
  - `dn_bfs_geoip_update( string $license_key ): array` → `ok`/`reason` ∈ `no_license, download_failed, checksum_mismatch, extract_failed, mmdb_missing, write_failed, invalid_database`. Thành công: option `dnbfs_geoip_updated_at` = time(); lỗi: option `dnbfs_geoip_last_error` = reason.
  - `dn_bfs_maybe_update_geoip( int $now ): void` — chỉ chạy khi có license key và lần cập nhật cuối > 30 ngày.

- [ ] **Step 1: Viết test fail — `tests/integration/test-geoip-update.php`**

```php
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
```

- [ ] **Step 2: Chạy, xác nhận fail**

Run tích hợp. Expected: 3 test mới FAIL (`Call to undefined function dn_bfs_geoip_update()`).

- [ ] **Step 3: Tạo `includes/tracking/geoip-update.php`**

```php
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
	$expected = is_wp_error( $checksum ) ? '' : strtolower( (string) strtok( trim( wp_remote_retrieve_body( $checksum ) ), " \t" ) );

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
	} catch ( Exception $e ) {
		return dn_bfs_geoip_fail( 'extract_failed', array( $archive ) );
	}

	if ( '' === $found ) {
		return dn_bfs_geoip_fail( 'mmdb_missing', array( $archive ) );
	}

	$temp = $target . '.tmp';

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

	dn_bfs_geoip_update( $settings['maxmind_license_key'] );
}
```

- [ ] **Step 4: Nạp module** — thêm `'geoip-update'` vào cuối mảng module của `dn_burst_funnel_stats_load_tracking()`.

- [ ] **Step 5: Chạy test**

Run tích hợp → Expected: `70 passed, 0 failed`. Run `php74` → OK.

- [ ] **Step 6: Commit**

```bash
git add includes/tracking/geoip-update.php tests/integration/test-geoip-update.php dn-burst-funnel-stats.php
git commit -m "feat(tracking): download, verify and install GeoLite2 monthly

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: `reports.php` — summary, timeseries, funnel

**Files:**
- Create: `includes/reports.php`, `tests/integration/test-reports-summary.php`
- Modify: `dn-burst-funnel-stats.php` (thêm `'reports'` cuối mảng `dn_burst_funnel_stats_load_reports()` — file `includes/reports.php`)

**Interfaces:**
- Consumes: Task 2–5.
- Produces (dùng ở Kế hoạch 3 và 4):
  - `$range` = mảng từ `dn_bfs_calculate_date_range()`: cần `current_start`, `current_end`, `previous_start`, `previous_end` (epoch, end bao gồm), `compare` (`none|previous_period|previous_year`).
  - `dn_bfs_report_cache_ttl(): int` (filter `dn_bfs_report_cache_ttl`, mặc định 60; ≤ 0 tắt cache).
  - `dn_bfs_report_raw_rows( int $start, int $end, string $dimension, array $filters ): array` — `dn_bfs_raw_rows` có cache transient `dnbfs_r_*`.
  - `dn_bfs_report_period( int $start_ts, int $end_ts, int $now ): array` — `start_date, end_date, today, daily_end, has_daily, has_today, live_start_date, live_start_ts, has_live, incomplete, start_ts, end_ts` (end_ts loại trừ; không vượt quá `$now + 1`). `daily_end = min( end_date, hôm qua, dnbfs_last_aggregated_date )` (option rỗng → `has_daily = false`); phần live (đọc từ raw) bắt đầu ở `live_start_date` = ngày đầu tiên không có dòng daily trong khoảng; `has_live = live_start_date <= min( end_date, today )`; nếu phần live bắt đầu trước `dn_bfs_raw_cutoff_date( $now )` thì kẹp về cutoff và `incomplete = true` (summary/timeseries/breakdown trả `estimated = true`).
  - `dn_bfs_daily_totals( string $start_date, string $end_date, array $filters ): array` (metrics), `dn_bfs_daily_series( string $start_date, string $end_date, array $filters ): array` (`date => metrics`), `dn_bfs_daily_breakdown( string $start_date, string $end_date, string $dimension ): array` (`dim_value => metrics`).
  - `dn_bfs_report_period_metrics( int $start_ts, int $end_ts, array $filters, int $now ): array|WP_Error` → `array( 'metrics' => derived metrics, 'estimated' => bool )`.
  - `dn_bfs_report_summary( array $range, array $filters = array(), $now = null ): array|WP_Error` → `current`, `previous` (null khi compare none), `change` (`metric => percent`), `estimated`.
  - `dn_bfs_report_timeseries( array $range, array $metrics, array $filters = array(), $now = null ): array|WP_Error` → `labels` (Y-m-d), `series` (`metric => values[]`).
  - `dn_bfs_report_funnel( array $range, array $filters = array(), $now = null ): array|WP_Error` → danh sách `array( 'key', 'value' )` cho `visitors, product_views, atc, carts, checkouts, orders`.
  - `dn_bfs_report_error_out_of_retention(): WP_Error`.

- [ ] **Step 1: Viết test fail — `tests/integration/test-reports-summary.php`**

```php
<?php

require_once __DIR__ . '/seed.php';

add_filter( 'dn_bfs_report_cache_ttl', '__return_zero' );

function dn_bfs_it_range( $days_ago_start, $days_ago_end, $compare = 'previous_period' ) {
	$today = wp_date( 'Y-m-d', time() );
	list( $start ) = dn_bfs_day_bounds( dn_bfs_date_shift( $today, -1 * $days_ago_start ) );
	list( , $end ) = dn_bfs_day_bounds( dn_bfs_date_shift( $today, -1 * $days_ago_end ) );
	$days          = $days_ago_start - $days_ago_end + 1;

	return array(
		'current_start'  => $start,
		'current_end'    => $end - 1,
		'previous_start' => $start - $days * DAY_IN_SECONDS,
		'previous_end'   => $start - 1,
		'compare'        => $compare,
	);
}

function dn_bfs_it_seed_reporting_week() {
	delete_option( 'dnbfs_last_aggregated_date' );
	delete_option( 'dnbfs_dirty_dates' );

	foreach ( array( 3, 1 ) as $days_ago ) {
		$ts = dn_bfs_it_day_noon( $days_ago );
		$s  = dn_bfs_it_seed_session( array( 'started_at' => $ts, 'visitor_uid' => md5( 'loyal' ), 'is_new_visitor' => 3 === $days_ago ? 1 : 0, 'channel' => 'paid', 'device' => 'mobile' ) );
		dn_bfs_it_seed_pageview( $s, '/', $ts );
		dn_bfs_it_seed_event( $s, 'product_view', $ts, array( 'product_id' => 101 ) );
	}

	$today = dn_bfs_it_seed_session( array( 'started_at' => time() - 60, 'channel' => 'direct', 'device' => 'desktop' ) );
	dn_bfs_it_seed_pageview( $today, '/', time() - 60 );

	dn_bfs_aggregate_run( time() );
}

dn_bfs_it(
	'summary combines daily rows with today and counts visitors exactly within retention',
	function () {
		dn_bfs_it_seed_reporting_week();

		$summary = dn_bfs_report_summary( dn_bfs_it_range( 6, 0 ) );

		dn_bfs_assert_same( 3, $summary['current']['sessions'] );
		dn_bfs_assert_same( 3, $summary['current']['pageviews'] );
		dn_bfs_assert_same( 2, $summary['current']['visitors'] );
		dn_bfs_assert_same( 2, $summary['current']['product_views'] );
		dn_bfs_assert_same( false, $summary['estimated'] );
		dn_bfs_assert_same( 0, $summary['previous']['sessions'] );
		dn_bfs_assert_same( 100.0, $summary['change']['sessions'] );
	}
);

dn_bfs_it(
	'summary with one filter reads that dimension and none compare has no previous',
	function () {
		dn_bfs_it_seed_reporting_week();

		$summary = dn_bfs_report_summary( dn_bfs_it_range( 6, 0, 'none' ), array( 'channel' => 'paid' ) );

		dn_bfs_assert_same( 2, $summary['current']['sessions'] );
		dn_bfs_assert_same( 1, $summary['current']['visitors'] );
		dn_bfs_assert_same( null, $summary['previous'] );
	}
);

dn_bfs_it(
	'summary with two filters uses raw data and refuses ranges beyond retention',
	function () {
		dn_bfs_it_seed_reporting_week();

		$summary = dn_bfs_report_summary( dn_bfs_it_range( 6, 0, 'none' ), array( 'channel' => 'paid', 'device' => 'mobile' ) );
		dn_bfs_assert_same( 2, $summary['current']['sessions'] );

		$error = dn_bfs_report_summary( dn_bfs_it_range( 200, 0, 'none' ), array( 'channel' => 'paid', 'device' => 'mobile' ) );
		dn_bfs_assert_true( is_wp_error( $error ), 'error' );
		dn_bfs_assert_same( 'filter_out_of_retention', $error->get_error_code() );
	}
);

dn_bfs_it(
	'summary beyond retention without filters is estimated',
	function () {
		dn_bfs_it_seed_reporting_week();
		dn_bfs_it_settings( array( 'raw_retention_days' => 7 ) );

		$summary = dn_bfs_report_summary( dn_bfs_it_range( 30, 0, 'none' ) );

		dn_bfs_assert_same( true, $summary['estimated'] );
		dn_bfs_assert_same( 3, $summary['current']['visitors'] );
	}
);

dn_bfs_it(
	'timeseries returns one value per day including today',
	function () {
		dn_bfs_it_seed_reporting_week();

		$series = dn_bfs_report_timeseries( dn_bfs_it_range( 3, 0, 'none' ), array( 'sessions', 'bounce_rate' ) );

		dn_bfs_assert_same( 4, count( $series['labels'] ) );
		dn_bfs_assert_same( array( 1, 0, 1, 1 ), $series['series']['sessions'] );
		dn_bfs_assert_same( 4, count( $series['series']['bounce_rate'] ) );
	}
);

dn_bfs_it(
	'funnel lists the six steps in order',
	function () {
		dn_bfs_it_seed_reporting_week();

		$funnel = dn_bfs_report_funnel( dn_bfs_it_range( 6, 0, 'none' ) );

		dn_bfs_assert_same( array( 'visitors', 'product_views', 'atc', 'carts', 'checkouts', 'orders' ), array_column( $funnel, 'key' ) );
		dn_bfs_assert_same( 2, $funnel[0]['value'] );
		dn_bfs_assert_same( 2, $funnel[1]['value'] );
	}
);
```

- [ ] **Step 2: Chạy, xác nhận fail**

Run tích hợp. Expected: 6 test mới FAIL (`Call to undefined function dn_bfs_report_summary()`).

- [ ] **Step 3: Tạo `includes/reports.php`**

```php
<?php
/**
 * Report API shared by the admin dashboard and the public REST API.
 *
 * Past days come from dnbfs_daily; today (and multi-filter queries) come from
 * the raw engine.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_report_cache_ttl() {
	return (int) apply_filters( 'dn_bfs_report_cache_ttl', 60 );
}

function dn_bfs_report_raw_rows( $start, $end, $dimension, $filters ) {
	$ttl = dn_bfs_report_cache_ttl();

	if ( $ttl <= 0 ) {
		return dn_bfs_raw_rows( $start, $end, $dimension, $filters );
	}

	$key    = 'dnbfs_r_' . md5( wp_json_encode( array( (int) $start, (int) floor( $end / $ttl ), $dimension, dn_bfs_sanitize_filters( $filters ) ) ) );
	$cached = get_transient( $key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$rows = dn_bfs_raw_rows( $start, $end, $dimension, $filters );
	set_transient( $key, $rows, $ttl );

	return $rows;
}

function dn_bfs_report_error_out_of_retention() {
	return new WP_Error(
		'filter_out_of_retention',
		__( 'Combined filters are only available for dates that still have raw tracking data.', 'dn-burst-funnel-stats' ),
		array( 'status' => 422 )
	);
}

function dn_bfs_report_period( $start_ts, $end_ts, $now ) {
	$start_date = wp_date( 'Y-m-d', (int) $start_ts );
	$end_date   = wp_date( 'Y-m-d', (int) $end_ts );
	$today      = wp_date( 'Y-m-d', (int) $now );
	$yesterday  = dn_bfs_date_shift( $today, -1 );
	$daily_end  = $end_date < $yesterday ? $end_date : $yesterday;

	list( $period_start ) = dn_bfs_day_bounds( $start_date );
	list( , $last_end )   = dn_bfs_day_bounds( $end_date );

	return array(
		'start_date' => $start_date,
		'end_date'   => $end_date,
		'today'      => $today,
		'daily_end'  => $daily_end,
		'has_daily'  => $start_date <= $daily_end,
		'has_today'  => $start_date <= $today && $end_date >= $today,
		'start_ts'   => $period_start,
		'end_ts'     => min( $last_end, (int) $now + 1 ),
	);
}

function dn_bfs_daily_sum_sql() {
	$parts = array();

	foreach ( dn_bfs_metric_columns() as $column ) {
		$parts[] = "COALESCE(SUM({$column}), 0) AS {$column}";
	}

	return implode( ', ', $parts );
}

function dn_bfs_daily_filter_where( $filters ) {
	global $wpdb;

	$filters = dn_bfs_sanitize_filters( $filters );

	if ( empty( $filters ) ) {
		return "dimension = 'total'";
	}

	$dimension = key( $filters );

	return $wpdb->prepare( 'dimension = %s AND dim_hash = %s', $dimension, md5( $filters[ $dimension ] ) );
}

function dn_bfs_daily_totals( $start_date, $end_date, $filters ) {
	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT ' . dn_bfs_daily_sum_sql() . ' FROM ' . dn_bfs_table( 'daily' ) . ' WHERE date >= %s AND date <= %s AND ' . dn_bfs_daily_filter_where( $filters ),
			$start_date,
			$end_date
		),
		ARRAY_A
	);

	return dn_bfs_normalize_metrics( is_array( $row ) ? $row : array() );
}

function dn_bfs_daily_series( $start_date, $end_date, $filters ) {
	global $wpdb;

	$results = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT date, ' . dn_bfs_daily_sum_sql() . ' FROM ' . dn_bfs_table( 'daily' ) . ' WHERE date >= %s AND date <= %s AND ' . dn_bfs_daily_filter_where( $filters ) . ' GROUP BY date',
			$start_date,
			$end_date
		),
		ARRAY_A
	);

	$series = array();

	foreach ( (array) $results as $row ) {
		$series[ $row['date'] ] = dn_bfs_normalize_metrics( $row );
	}

	return $series;
}

function dn_bfs_daily_breakdown( $start_date, $end_date, $dimension ) {
	global $wpdb;

	$results = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT dim_value, ' . dn_bfs_daily_sum_sql() . ' FROM ' . dn_bfs_table( 'daily' ) . ' WHERE date >= %s AND date <= %s AND dimension = %s GROUP BY dim_hash, dim_value',
			$start_date,
			$end_date,
			$dimension
		),
		ARRAY_A
	);

	$rows = array();

	foreach ( (array) $results as $row ) {
		$rows[ (string) $row['dim_value'] ] = dn_bfs_normalize_metrics( $row );
	}

	return $rows;
}

function dn_bfs_report_period_metrics( $start_ts, $end_ts, $filters, $now ) {
	$filters      = dn_bfs_sanitize_filters( $filters );
	$period       = dn_bfs_report_period( $start_ts, $end_ts, $now );
	$in_retention = $period['start_date'] >= dn_bfs_raw_cutoff_date( $now );
	$metrics      = dn_bfs_empty_metrics();

	if ( $period['start_date'] > $period['today'] ) {
		return array(
			'metrics'   => dn_bfs_derive_metrics( $metrics ),
			'estimated' => false,
		);
	}

	if ( count( $filters ) > 1 ) {
		if ( ! $in_retention ) {
			return dn_bfs_report_error_out_of_retention();
		}

		$rows    = dn_bfs_report_raw_rows( $period['start_ts'], $period['end_ts'], 'total', $filters );
		$metrics = isset( $rows[''] ) ? $rows[''] : $metrics;
	} else {
		if ( $period['has_daily'] ) {
			$metrics = dn_bfs_add_metrics( $metrics, dn_bfs_daily_totals( $period['start_date'], $period['daily_end'], $filters ) );
		}

		if ( $period['has_today'] ) {
			list( $today_start ) = dn_bfs_day_bounds( $period['today'] );
			$rows                = dn_bfs_report_raw_rows( $today_start, (int) $now + 1, 'total', $filters );
			$metrics             = dn_bfs_add_metrics( $metrics, isset( $rows[''] ) ? $rows[''] : dn_bfs_empty_metrics() );
		}
	}

	$estimated = false;

	if ( $in_retention ) {
		$metrics['visitors']     = dn_bfs_raw_distinct_visitors( $period['start_ts'], $period['end_ts'], $filters );
		$metrics['new_visitors'] = dn_bfs_raw_distinct_visitors( $period['start_ts'], $period['end_ts'], $filters, true );
	} else {
		$estimated = $period['start_date'] !== $period['end_date'];
	}

	return array(
		'metrics'   => dn_bfs_derive_metrics( $metrics ),
		'estimated' => $estimated,
	);
}

function dn_bfs_report_summary( $range, $filters = array(), $now = null ) {
	$now     = null === $now ? dn_bfs_now() : (int) $now;
	$current = dn_bfs_report_period_metrics( $range['current_start'], $range['current_end'], $filters, $now );

	if ( is_wp_error( $current ) ) {
		return $current;
	}

	$previous = null;
	$change   = array();

	if ( isset( $range['compare'] ) && 'none' !== $range['compare'] ) {
		$previous = dn_bfs_report_period_metrics( $range['previous_start'], $range['previous_end'], $filters, $now );

		if ( is_wp_error( $previous ) ) {
			return $previous;
		}

		foreach ( $current['metrics'] as $key => $value ) {
			$change[ $key ] = dn_bfs_percent_change( $value, $previous['metrics'][ $key ] );
		}
	}

	return array(
		'current'   => $current['metrics'],
		'previous'  => null === $previous ? null : $previous['metrics'],
		'change'    => $change,
		'estimated' => $current['estimated'] || ( null !== $previous && $previous['estimated'] ),
	);
}

function dn_bfs_report_timeseries( $range, $metrics, $filters = array(), $now = null ) {
	$now     = null === $now ? dn_bfs_now() : (int) $now;
	$filters = dn_bfs_sanitize_filters( $filters );
	$period  = dn_bfs_report_period( $range['current_start'], $range['current_end'], $now );
	$allowed = array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() );
	$metrics = array_values( array_intersect( (array) $metrics, $allowed ) );
	$last    = $period['end_date'] < $period['today'] ? $period['end_date'] : $period['today'];
	$labels  = dn_bfs_dates_between( $period['start_date'], $last );
	$by_day  = array();

	if ( count( $filters ) > 1 ) {
		if ( $period['start_date'] < dn_bfs_raw_cutoff_date( $now ) ) {
			return dn_bfs_report_error_out_of_retention();
		}

		foreach ( $labels as $date ) {
			list( $start, $end ) = dn_bfs_day_bounds( $date );
			$rows                = dn_bfs_report_raw_rows( $start, min( $end, $now + 1 ), 'total', $filters );
			$by_day[ $date ]     = isset( $rows[''] ) ? $rows[''] : dn_bfs_empty_metrics();
		}
	} else {
		if ( $period['has_daily'] ) {
			$by_day = dn_bfs_daily_series( $period['start_date'], $period['daily_end'], $filters );
		}

		if ( $period['has_today'] ) {
			list( $today_start )         = dn_bfs_day_bounds( $period['today'] );
			$rows                        = dn_bfs_report_raw_rows( $today_start, $now + 1, 'total', $filters );
			$by_day[ $period['today'] ] = isset( $rows[''] ) ? $rows[''] : dn_bfs_empty_metrics();
		}
	}

	$series = array_fill_keys( $metrics, array() );

	foreach ( $labels as $date ) {
		$day = dn_bfs_derive_metrics( isset( $by_day[ $date ] ) ? $by_day[ $date ] : dn_bfs_empty_metrics() );

		foreach ( $metrics as $metric ) {
			$series[ $metric ][] = $day[ $metric ];
		}
	}

	return array(
		'labels' => $labels,
		'series' => $series,
	);
}

function dn_bfs_report_funnel( $range, $filters = array(), $now = null ) {
	$range['compare'] = 'none';
	$summary          = dn_bfs_report_summary( $range, $filters, $now );

	if ( is_wp_error( $summary ) ) {
		return $summary;
	}

	$steps = array();

	foreach ( array( 'visitors', 'product_views', 'atc', 'carts', 'checkouts', 'orders' ) as $key ) {
		$steps[] = array(
			'key'   => $key,
			'value' => (int) $summary['current'][ $key ],
		);
	}

	return $steps;
}
```

- [ ] **Step 4: Nạp module** — trong `dn_burst_funnel_stats_load_reports()` đổi mảng thành `array('reports/metrics', 'reports/wc-settings', 'reports/raw', 'reports')`. Lưu ý `reports.php` dùng `dn_bfs_day_bounds()` / `dn_bfs_raw_cutoff_date()` từ `includes/tracking/aggregator.php` (đã nạp trước bởi `dn_burst_funnel_stats_load_tracking()`).

- [ ] **Step 5: Chạy test**

Run tích hợp → Expected: `76 passed, 0 failed`. Run unit + `php74` → OK.

- [ ] **Step 6: Commit**

```bash
git add includes/reports.php tests/integration/test-reports-summary.php dn-burst-funnel-stats.php
git commit -m "feat(reports): add summary, timeseries and funnel report API

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: `reports.php` — breakdown và realtime

**Files:**
- Modify: `includes/reports.php` (thêm vào cuối)
- Create: `tests/integration/test-reports-breakdown.php`

**Interfaces:**
- Consumes: Task 8.
- Produces:
  - `dn_bfs_report_breakdown( array $range, string $dimension, array $filters = array(), string $orderby = '', string $order = 'desc', int $limit = 25, int $offset = 0, $now = null ): array|WP_Error` → `rows` (mỗi dòng: `dim_value`, `label`, metrics + derived), `total` (số dòng trước phân trang), `estimated`. Dimension không hợp lệ → `WP_Error( 'invalid_dimension', …, array( 'status' => 422 ) )`. `orderby` mặc định `product_views` cho `product`, `pageviews` cho dimension khác; `limit` kẹp 1..500.
  - `dn_bfs_report_realtime( $now = null ): array` → `online` (int), `pages` (`array( 'path', 'visitors' )`, tối đa 10), `channels` (`array( 'channel', 'visitors' )`, tối đa 10). Khách online = phiên không spam có `last_activity >= now - 300`.

- [ ] **Step 1: Viết test fail — `tests/integration/test-reports-breakdown.php`**

```php
<?php

require_once __DIR__ . '/seed.php';

add_filter( 'dn_bfs_report_cache_ttl', '__return_zero' );

function dn_bfs_it_breakdown_range( $days_back ) {
	$today         = wp_date( 'Y-m-d', time() );
	list( $start ) = dn_bfs_day_bounds( dn_bfs_date_shift( $today, -1 * $days_back ) );
	list( , $end ) = dn_bfs_day_bounds( $today );

	return array(
		'current_start'  => $start,
		'current_end'    => $end - 1,
		'previous_start' => $start,
		'previous_end'   => $start,
		'compare'        => 'none',
	);
}

function dn_bfs_it_seed_breakdown() {
	delete_option( 'dnbfs_last_aggregated_date' );
	delete_option( 'dnbfs_dirty_dates' );

	$yesterday = dn_bfs_it_day_noon( 1 );

	foreach ( array( 'sale-10', 'sale-10', 'spring' ) as $i => $campaign ) {
		$s = dn_bfs_it_seed_session( array( 'started_at' => $yesterday + $i, 'utm_campaign' => $campaign, 'device' => 0 === $i ? 'mobile' : 'desktop' ) );
		dn_bfs_it_seed_pageview( $s, '/', $yesterday + $i );
		dn_bfs_it_seed_event( $s, 'product_view', $yesterday + $i, array( 'product_id' => 101 + $i ) );
	}

	$today = dn_bfs_it_seed_session( array( 'started_at' => time() - 30, 'utm_campaign' => 'spring' ) );
	dn_bfs_it_seed_pageview( $today, '/sale/', time() - 30 );

	dn_bfs_aggregate_run( time() );
}

dn_bfs_it(
	'breakdown merges daily rows with today and sorts',
	function () {
		dn_bfs_it_seed_breakdown();

		$result = dn_bfs_report_breakdown( dn_bfs_it_breakdown_range( 3 ), 'campaign', array(), 'sessions', 'desc' );

		dn_bfs_assert_same( 2, $result['total'] );
		dn_bfs_assert_same( array( 'sale-10', 'spring' ), array_column( $result['rows'], 'dim_value' ) );
		dn_bfs_assert_same( 2, $result['rows'][0]['sessions'] );
		dn_bfs_assert_same( 2, $result['rows'][1]['sessions'] );
		dn_bfs_assert_same( true, $result['estimated'] );
	}
);

dn_bfs_it(
	'breakdown with a filter uses raw data, paginates and labels products',
	function () {
		dn_bfs_it_seed_breakdown();

		$filtered = dn_bfs_report_breakdown( dn_bfs_it_breakdown_range( 3 ), 'device', array( 'campaign' => 'sale-10' ) );
		dn_bfs_assert_same( array( 'desktop', 'mobile' ), array_column( dn_bfs_sort_report_rows( $filtered['rows'], 'sessions', 'asc' ), 'dim_value' ) );
		dn_bfs_assert_same( false, $filtered['estimated'] );

		$page = dn_bfs_report_breakdown( dn_bfs_it_breakdown_range( 3 ), 'product', array(), 'product_views', 'desc', 1, 1 );
		dn_bfs_assert_same( 3, $page['total'] );
		dn_bfs_assert_same( 1, count( $page['rows'] ) );
		dn_bfs_assert_true( '' !== $page['rows'][0]['label'], 'product label' );

		$error = dn_bfs_report_breakdown( dn_bfs_it_breakdown_range( 3 ), 'nope' );
		dn_bfs_assert_same( 'invalid_dimension', $error->get_error_code() );
	}
);

dn_bfs_it(
	'realtime counts active sessions and their current pages',
	function () {
		$now    = time();
		$active = dn_bfs_it_seed_session( array( 'started_at' => $now - 600, 'last_activity' => $now - 30, 'channel' => 'paid' ) );
		dn_bfs_it_seed_pageview( $active, '/', $now - 600 );
		dn_bfs_it_seed_pageview( $active, '/cart/', $now - 40 );
		$other = dn_bfs_it_seed_session( array( 'started_at' => $now - 100, 'last_activity' => $now - 100 ) );
		dn_bfs_it_seed_pageview( $other, '/cart/', $now - 100 );
		dn_bfs_it_seed_session( array( 'started_at' => $now - 3600, 'last_activity' => $now - 1000 ) );
		dn_bfs_it_seed_session( array( 'started_at' => $now - 50, 'last_activity' => $now - 50, 'is_spam' => 1 ) );

		$realtime = dn_bfs_report_realtime( $now );

		dn_bfs_assert_same( 2, $realtime['online'] );
		dn_bfs_assert_same( array( array( 'path' => '/cart/', 'visitors' => 2 ) ), $realtime['pages'] );
		dn_bfs_assert_same( 2, array_sum( array_column( $realtime['channels'], 'visitors' ) ) );
	}
);
```

- [ ] **Step 2: Chạy, xác nhận fail**

Run tích hợp. Expected: 3 test mới FAIL (`Call to undefined function dn_bfs_report_breakdown()`).

- [ ] **Step 3: Thêm vào cuối `includes/reports.php`**

```php
function dn_bfs_report_row_label( $dimension, $value ) {
	if ( 'product' === $dimension ) {
		$title = get_the_title( (int) $value );

		return '' !== $title ? $title : '#' . (int) $value;
	}

	return (string) $value;
}

function dn_bfs_report_breakdown( $range, $dimension, $filters = array(), $orderby = '', $order = 'desc', $limit = 25, $offset = 0, $now = null ) {
	$now = null === $now ? dn_bfs_now() : (int) $now;

	if ( ! in_array( $dimension, dn_bfs_report_dimensions(), true ) ) {
		return new WP_Error( 'invalid_dimension', __( 'Unknown report dimension.', 'dn-burst-funnel-stats' ), array( 'status' => 422 ) );
	}

	$filters   = dn_bfs_sanitize_filters( $filters );
	$period    = dn_bfs_report_period( $range['current_start'], $range['current_end'], $now );
	$rows      = array();
	$estimated = false;

	if ( ! empty( $filters ) ) {
		if ( $period['start_date'] < dn_bfs_raw_cutoff_date( $now ) ) {
			return dn_bfs_report_error_out_of_retention();
		}

		$rows = dn_bfs_report_raw_rows( $period['start_ts'], $period['end_ts'], $dimension, $filters );
	} else {
		if ( $period['has_daily'] ) {
			$rows = dn_bfs_daily_breakdown( $period['start_date'], $period['daily_end'], $dimension );
		}

		if ( $period['has_live'] ) {
			foreach ( dn_bfs_report_raw_rows( $period['live_start_ts'], $now + 1, $dimension, array() ) as $key => $metrics ) {
				$key          = (string) $key;
				$rows[ $key ] = isset( $rows[ $key ] ) ? dn_bfs_add_metrics( $rows[ $key ], $metrics ) : $metrics;
			}
		}

		// Per-row visitors are summed across days, so multi-day breakdowns are estimates.
		$estimated = $period['incomplete'] || $period['start_date'] !== $period['end_date'];
	}

	$list = array();

	foreach ( $rows as $value => $metrics ) {
		$list[] = array_merge(
			array(
				'dim_value' => (string) $value,
				'label'     => dn_bfs_report_row_label( $dimension, $value ),
			),
			dn_bfs_derive_metrics( $metrics )
		);
	}

	$allowed = array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() );
	$orderby = in_array( $orderby, $allowed, true ) ? $orderby : ( 'product' === $dimension ? 'product_views' : 'pageviews' );
	$list    = dn_bfs_sort_report_rows( $list, $orderby, $order );
	$limit   = max( 1, min( 500, (int) $limit ) );

	return array(
		'rows'      => array_slice( $list, max( 0, (int) $offset ), $limit ),
		'total'     => count( $list ),
		'estimated' => $estimated,
	);
}

function dn_bfs_report_realtime( $now = null ) {
	global $wpdb;

	$now       = null === $now ? dn_bfs_now() : (int) $now;
	$since     = $now - 5 * MINUTE_IN_SECONDS;
	$sessions  = dn_bfs_table( 'sessions' );
	$pageviews = dn_bfs_table( 'pageviews' );

	$online = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sessions} WHERE last_activity >= %d AND is_spam = 0", $since ) );

	$pages = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.path AS path, COUNT(*) AS visitors
			FROM {$pageviews} p
			INNER JOIN (
				SELECT session_id, MAX(id) AS last_id FROM {$pageviews} WHERE time >= %d GROUP BY session_id
			) latest ON latest.last_id = p.id
			INNER JOIN {$sessions} s ON s.id = p.session_id
			WHERE s.last_activity >= %d AND s.is_spam = 0
			GROUP BY p.path ORDER BY visitors DESC, p.path ASC LIMIT 10",
			$now - 30 * MINUTE_IN_SECONDS,
			$since
		),
		ARRAY_A
	);

	$channels = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT channel, COUNT(*) AS visitors FROM {$sessions} WHERE last_activity >= %d AND is_spam = 0 GROUP BY channel ORDER BY visitors DESC, channel ASC LIMIT 10",
			$since
		),
		ARRAY_A
	);

	$cast = function ( $rows, $key ) {
		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				$key       => (string) $row[ $key ],
				'visitors' => (int) $row['visitors'],
			);
		}

		return $out;
	};

	return array(
		'online'   => $online,
		'pages'    => $cast( $pages, 'path' ),
		'channels' => $cast( $channels, 'channel' ),
	);
}
```

- [ ] **Step 4: Chạy test**

Run tích hợp → Expected: `79 passed, 0 failed`. Run unit + `php74` → OK.

- [ ] **Step 5: Commit**

```bash
git add includes/reports.php tests/integration/test-reports-breakdown.php
git commit -m "feat(reports): add breakdown and realtime report API

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Tự kiểm tra kế hoạch so với spec (phạm vi Kế hoạch 2)

| Yêu cầu | Task |
|---|---|
| §6 Aggregator mỗi giờ, chạy bù, khóa, dirty dates (đổi trạng thái đơn, hoàn tiền, spam) | 5 |
| §6 Ngoài thời hạn thô chỉ tính lại cột đơn hàng | 5 |
| §6 Cleanup theo lô 5.000, visitors > 13 tháng, salt cũ, GeoLite2 30 ngày | 6, 7 |
| §6 Gỡ cài đặt | 6 |
| §7 API báo cáo + định nghĩa chỉ số WooCommerce (chỉnh được) | 2, 4, 8, 9 |
| §8.3 Ngữ nghĩa bộ lọc chung, lỗi `filter_out_of_retention` | 8, 9 |
| Hợp đồng chuyển tiếp: không xóa `blocked`, đơn fallback, phiên 0 pageview, checkouts distinct, purge salt, uninstall, lint 7.4, tối ưu truy vấn chống trùng, heartbeat | 1, 3, 4, 5, 6 |

Ngoài phạm vi (Kế hoạch 3): REST nội bộ + React UI dùng `reports.php`; trang Settings (gồm nhóm WooCommerce/GeoIP/Dữ liệu với nút "Cập nhật ngay" gọi `dn_bfs_geoip_update()` và "Tổng hợp lại" gọi `dn_bfs_aggregate_day()`); xóa dashboard cũ; cảnh báo hệ thống. Kế hoạch 4: REST công khai bằng API key dùng `reports.php`.
