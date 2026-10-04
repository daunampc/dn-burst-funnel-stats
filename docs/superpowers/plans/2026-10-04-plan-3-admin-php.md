# Kế hoạch 3 — Trang admin PHP (nâng cấp giao diện cũ)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nâng cấp dashboard và trang Settings cũ (PHP + jQuery + biểu đồ canvas tự vẽ) để dùng dữ liệu tracking riêng qua `includes/reports.php`, thêm bộ lọc chung, panel chi tiết, tùy chỉnh thẻ, khách online, widget WP Dashboard, Settings 7 tab — không dùng React, không có bước build.

**Architecture:** Trang admin render bằng PHP như cũ; chuyển tab, sắp xếp/phân trang bảng, panel chi tiết và lưu thẻ dùng `admin-ajax.php` trả HTML/JSON (giữ mô hình `assets/admin.js` cũ). Mỗi handler AJAX là lớp mỏng quanh một hàm "payload" thuần trả `array|WP_Error` để test được. Settings dùng form `admin-post.php` theo nhóm qua mô hình settings chung. Code dashboard cũ (Burst) bị xóa khi phần mới được nối vào menu.

**Tech Stack:** PHP 7.4+, WordPress 6.5+ (admin-ajax, admin-post, jQuery, jQuery UI Sortable có sẵn), WooCommerce, MariaDB, WP-CLI integration harness.

**Spec:** `docs/superpowers/specs/2026-10-03-native-tracking-design.md` (mục 8 — được viết lại ở Task 1 cho hướng PHP; 10; 13).

## Global Constraints

- **Không React, không Node, không bước build.** JS viết tay kiểu ES5 + jQuery trong `assets/*.js` như code cũ.
- Giữ nền giao diện cũ: class CSS `dn-burst-*`, topbar, date picker, khung "Data status", lưới thẻ, 4 biểu đồ canvas, nav tab WordPress.
- PHP tối thiểu 7.4 (không `match`, union type, named args, `str_contains`, enum, readonly, nullsafe; không thêm return type). Hàm mới tiền tố `dn_bfs_` (hàm render dashboard `dn_bfs_dash_*`). Mọi SQL có biến qua `$wpdb->prepare`. Mọi output escape (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- Phong cách: tab + khoảng trắng kiểu WordPress trong `includes/`; `dn-burst-funnel-stats.php` 2 space, không khoảng trắng trong ngoặc.
- Quyền: `dn_bfs_admin_permission()` (= `current_user_can( dn_bfs_admin_capability() )`, filter `dn_bfs_capability`, mặc định `manage_options`). Nonce AJAX: action `dn_bfs_admin`, trường `nonce`.
- AJAX action: `dn_bfs_load_tab`, `dn_bfs_table`, `dn_bfs_drilldown`, `dn_bfs_realtime`, `dn_bfs_save_cards`, `dn_bfs_filter_values`, `dn_bfs_update_now`. Lỗi → `wp_send_json_error( array( 'message', 'code' ), status )` với status từ `WP_Error` (mặc định 400).
- Tham số khoảng ngày/bộ lọc (AJAX): `period`, `compare`, `start`, `end`, `filter[dimension]=value`. Trên URL trang: `dn_period`, `dn_compare`, `dn_start`, `dn_end`, `dn_filter[dimension]`, `dn_tab`. Khoảng custom ≤ 731 ngày. Mã lỗi tham số: `invalid_period`, `invalid_compare`, `invalid_date`, `range_too_long`, `invalid_filter`, `invalid_metric` (400); lỗi `filter_out_of_retention` (422) từ `reports.php` hiển thị dạng notice.
- Settings: lưu theo nhóm, form luôn gửi đủ khóa (checkbox thiếu = 0, danh sách thiếu = `[]`); license MaxMind luôn che bằng `********`; đổi key → xóa `dnbfs_geoip_attempted_at`. Mặc định loại khỏi doanh thu có `wc-refunded`.
- Tiền: Sales/Paid/Items net hoàn tiền; Tip gross; Balance = pending + on-hold (ghi trong tooltip thẻ). Hiển thị ghi chú khi `estimated = true`. Không lọc được giá trị rỗng (không cho drill-down hàng `(none)`).
- Commit message kết thúc bằng `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Không commit `.superpowers/`.
- Lệnh (từ gốc repo):
  - Unit: `docker compose -f docker/docker-compose.yml run --rm phpunit`
  - Tích hợp: `docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php`
  - Lint 7.4: `docker compose -f docker/docker-compose.yml run --rm php74`

## Cấu trúc file

| File | Trách nhiệm |
|---|---|
| `includes/admin/request.php` | Quyền, lỗi, phân tích + kiểm tra khoảng ngày/bộ lọc/chỉ số (Kế hoạch 4 dùng lại) |
| `includes/admin/settings-model.php` | 6 nhóm settings: đọc, ghi, che license, meta |
| `includes/admin/system-status.php` | Các kiểm tra trạng thái hệ thống |
| `includes/admin/dashboard-data.php` | Danh sách thẻ, thẻ theo user, dữ liệu thẻ + biểu đồ từ `reports.php` |
| `includes/admin/dashboard-page.php` | Render trang dashboard: topbar, date picker, data status, bộ lọc, tab, thẻ, biểu đồ, bảng, panel chi tiết |
| `includes/admin/ajax.php` | Handler AJAX + hàm payload |
| `includes/admin/data-tools.php` | Thống kê dữ liệu, tổng hợp lại, xóa toàn bộ, export/import, cập nhật GeoIP, thống kê bị chặn |
| `includes/admin/settings-page.php` | Trang Settings 7 tab, form theo nhóm, handler admin-post |
| `includes/admin/menu.php` | Menu + nạp CSS/JS cho trang plugin |
| `includes/admin/dashboard-widget.php`, `assets/dashboard-widget.js` | Widget WP Dashboard |
| `assets/admin.js`, `assets/admin.css` (sửa) | Tương tác mới, giữ code biểu đồ |
| Xóa | `includes/dashboard.php`, `includes/ajax.php`, `includes/admin-menu.php`, `includes/settings.php`, `includes/import-export.php` |
| `tests/integration/admin-helpers.php` | Helper đăng nhập admin trong test |

---

### Task 1: Phân tích tham số + quyền; cập nhật spec cho hướng PHP

**Files:**
- Create: `includes/admin/request.php`, `tests/integration/admin-helpers.php`, `tests/integration/test-admin-request.php`
- Modify: `dn-burst-funnel-stats.php` (thêm `dn_burst_funnel_stats_load_admin()`), `docs/superpowers/specs/2026-10-03-native-tracking-design.md` (mục 8)

**Interfaces:**
- Consumes: `dn_bfs_calculate_date_range()`, `dn_bfs_get_date_presets()`, `dn_bfs_get_tracking_settings()`, `dn_bfs_filter_dimensions()`, `dn_bfs_sanitize_filters()`, `dn_bfs_metric_columns()`, `dn_bfs_derived_metric_names()`, `dn_bfs_dates_between()`.
- Produces:
  - `dn_bfs_admin_capability(): string`, `dn_bfs_admin_permission(): bool`.
  - `dn_bfs_request_error( string $code, string $message, int $status = 400, array $extra = array() ): WP_Error`.
  - `dn_bfs_valid_date_string( $value ): bool`.
  - `dn_bfs_parse_range( array $params ): array|WP_Error` (khóa `period`, `compare`, `start`, `end`; rỗng → mặc định settings).
  - `dn_bfs_parse_filters( array $params ): array|WP_Error` (khóa `filter`).
  - `dn_bfs_parse_metrics( $value ): array|WP_Error` (rỗng → `array( 'sessions', 'orders', 'revenue' )`).
  - `dn_bfs_range_meta( array $range ): array` → `period, compare, start, end, label, range_label, compare_label, previous_range_label`.
  - `dn_bfs_params_from_query( array $query ): array` → đổi `dn_period/dn_compare/dn_start/dn_end/dn_filter/dn_tab` thành `period/compare/start/end/filter/tab`.
  - `dn_burst_funnel_stats_load_admin()` (file chính) nạp `includes/admin/<module>.php`; gọi trong bootstrap sau `dn_burst_funnel_stats_load_reports();` và sau `require_once … 'includes/date-ranges.php';`.
  - Helper test `dn_bfs_it_login_admin(): int`.

- [ ] **Step 1: Cập nhật spec mục 8** — trong `docs/superpowers/specs/2026-10-03-native-tracking-design.md`, thay toàn bộ mục `## 8. Giao diện admin (React)` (tới trước `## 9.`) bằng:

```markdown
## 8. Giao diện admin (PHP, nâng cấp giao diện cũ)

Quyết định của chủ sản phẩm (2026-10-04): **không dùng React**. Trang admin render bằng PHP trong WordPress, giữ nền giao diện cũ (topbar, date picker, khung Data status, lưới thẻ, 4 biểu đồ canvas tự vẽ, nav tab) và nâng cấp. JS viết tay bằng jQuery, không có bước build.

### 8.1 Kiến trúc
- Menu **Funnel Stats → Dashboard** và **Settings** (gộp URL Tracking vào tab Ad URLs, Import/Export vào Settings → Dữ liệu).
- Trang render bằng PHP lần đầu; chuyển tab, sắp xếp/phân trang bảng, panel chi tiết, lưu thẻ, khách online, "Update now" dùng `admin-ajax.php` (nonce `dn_bfs_admin`) trả HTML/JSON. Trạng thái khoảng ngày + bộ lọc đồng bộ lên URL (`dn_period`, `dn_compare`, `dn_start`, `dn_end`, `dn_filter[…]`, `dn_tab`).
- Dữ liệu lấy từ `includes/reports.php`; quyền `manage_options` (filter `dn_bfs_capability`).

### 8.2 Dashboard
- **Thanh trên:** tiêu đề tab, badge khách online (bấm mở danh sách trang đang xem, tự làm mới 30 giây), date picker cũ, khung Data status ("Aggregated through", "Next update", nút "Update now" chạy aggregator).
- **Bộ lọc chung** dạng chip dưới thanh công cụ (`Kênh`, `Source`, `Medium`, `Campaign`, `Thiết bị`, `Quốc gia`), có gợi ý giá trị.
- **Overview:** 15 thẻ (Visitors, Pageviews, Sessions, Khách mới/quay lại, Bounce rate, Thời gian phiên TB, Trang/phiên, Product Views, Add To Cart (= Cart), Checkout, Orders/AOV, Items/AOI, Conversion Rate, Sales/Tip, Paid/Balance) theo kiểu thẻ cũ; nút **Tùy chỉnh** cho ẩn/hiện + kéo thả (jQuery UI Sortable), lưu user meta `dnbfs_cards`. 4 biểu đồ canvas cũ: Sales/Orders, Phễu (Visitors → Product views → Add to cart (= Cart) → Checkout → Orders), Conversion, Top campaigns theo doanh thu.
- **Tab:** Overview, Pages (trang / trang vào / trang thoát), Sources (kênh / referrer / source / medium), Ad URLs (campaign / source / medium), Products, Brands, Countries (quốc gia / thành phố), Devices (thiết bị / trình duyệt / HĐH). Bảng sắp xếp, phân trang 25 dòng, ghi chú khi số liệu ước tính.
- **Panel chi tiết:** bấm dòng có chiều lọc được → panel trượt bên phải (tóm tắt, biểu đồ Visitors/Orders theo ngày, phễu) + nút "Áp làm bộ lọc".

### 8.3 Ngữ nghĩa bộ lọc chung
(giữ nguyên nội dung 8.3 cũ: 0–1 bộ lọc đọc `dnbfs_daily`; ≥ 2 bộ lọc hoặc bộ lọc + bảng theo chiều khác đọc bảng thô và chỉ hợp lệ trong thời hạn thô — theo `dn_bfs_raw_available_from()`; ngoài thời hạn hiển thị thông báo.)

### 8.4 Settings (7 tab)
Chung, Tracking, Chống spam, WooCommerce, GeoIP, Dữ liệu, Hệ thống — form PHP theo nhóm (`admin-post.php`), mỗi lần lưu gửi đủ khóa; license MaxMind luôn che; tab Dữ liệu có thống kê bảng, tổng hợp lại khoảng ngày, Export/Import settings, xóa toàn bộ dữ liệu (gõ `DELETE`); tab GeoIP có trạng thái + nút cập nhật; tab Hệ thống hiển thị các kiểm tra (bảng, schema, độ trễ tổng hợp, cron, endpoint `/collect`, tracker, GeoIP, file GeoIP công khai, proxy, phiên bản). Quản lý API key thuộc Kế hoạch 4.

### 8.5 Widget trên WP Dashboard
Render PHP: khách online, Visitors / Orders / Sales hôm nay so với hôm qua, link mở dashboard; số online tự làm mới 30 giây qua admin-ajax.
```

(Giữ nội dung chi tiết của mục 8.3 cũ ngay dưới tiêu đề 8.3 thay cho dòng "(giữ nguyên …)" — copy đoạn 8.3 cũ vào.)

- [ ] **Step 2: Tạo `tests/integration/admin-helpers.php`**

```php
<?php
/**
 * Helpers for admin-side integration tests.
 */

function dn_bfs_it_login_admin() {
	$admin_id = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
	wp_set_current_user( $admin_id );

	return $admin_id;
}
```

- [ ] **Step 3: Viết test fail — `tests/integration/test-admin-request.php`**

```php
<?php

require_once __DIR__ . '/admin-helpers.php';

dn_bfs_it(
	'range parsing accepts presets, custom ranges and defaults',
	function () {
		$range = dn_bfs_parse_range( array( 'period' => 'last_week', 'compare' => 'none' ) );
		dn_bfs_assert_same( 'last_week', $range['period'] );
		dn_bfs_assert_same( 'none', $range['compare'] );

		$custom = dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '2026-01-01', 'end' => '2026-01-31' ) );
		dn_bfs_assert_same( '2026-01-01', $custom['custom_start'] );
		dn_bfs_assert_same( '2026-01-31', $custom['custom_end'] );

		dn_bfs_it_settings( array( 'default_date_range' => 'yesterday', 'default_compare' => 'previous_period' ) );
		$default = dn_bfs_parse_range( array() );
		dn_bfs_assert_same( 'yesterday', $default['period'] );
		dn_bfs_assert_same( 'previous_period', $default['compare'] );

		$meta = dn_bfs_range_meta( $custom );
		dn_bfs_assert_same( array( 'period', 'compare', 'start', 'end', 'label', 'range_label', 'compare_label', 'previous_range_label' ), array_keys( $meta ) );
	}
);

dn_bfs_it(
	'invalid range, filter and metric parameters return specific errors',
	function () {
		$cases = array(
			array( dn_bfs_parse_range( array( 'period' => 'nope' ) ), 'invalid_period' ),
			array( dn_bfs_parse_range( array( 'compare' => 'nope' ) ), 'invalid_compare' ),
			array( dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '2026-13-01', 'end' => '2026-01-02' ) ), 'invalid_date' ),
			array( dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '2026-02-01', 'end' => '2026-01-01' ) ), 'invalid_date' ),
			array( dn_bfs_parse_range( array( 'period' => 'custom', 'start' => '2020-01-01', 'end' => '2026-01-01' ) ), 'range_too_long' ),
			array( dn_bfs_parse_filters( array( 'filter' => array( 'browser' => 'Chrome' ) ) ), 'invalid_filter' ),
			array( dn_bfs_parse_filters( array( 'filter' => array( 'campaign' => ' ' ) ) ), 'invalid_filter' ),
			array( dn_bfs_parse_filters( array( 'filter' => 'campaign' ) ), 'invalid_filter' ),
			array( dn_bfs_parse_metrics( 'sessions,nope' ), 'invalid_metric' ),
		);

		foreach ( $cases as $case ) {
			dn_bfs_assert_true( is_wp_error( $case[0] ), $case[1] );
			dn_bfs_assert_same( $case[1], $case[0]->get_error_code() );
			dn_bfs_assert_same( 400, $case[0]->get_error_data()['status'] );
		}
	}
);

dn_bfs_it(
	'valid filters and metrics are normalized',
	function () {
		dn_bfs_assert_same( array( 'campaign' => 'sale-10', 'device' => 'mobile' ), dn_bfs_parse_filters( array( 'filter' => array( 'device' => 'mobile', 'campaign' => ' sale-10 ' ) ) ) );
		dn_bfs_assert_same( array(), dn_bfs_parse_filters( array() ) );
		dn_bfs_assert_same( array( 'sessions', 'bounce_rate' ), dn_bfs_parse_metrics( array( 'sessions', 'bounce_rate', 'sessions' ) ) );
		dn_bfs_assert_same( array( 'sessions', 'orders', 'revenue' ), dn_bfs_parse_metrics( '' ) );
	}
);

dn_bfs_it(
	'query parameters from the dashboard URL are mapped',
	function () {
		$params = dn_bfs_params_from_query(
			array(
				'dn_period'  => 'custom',
				'dn_compare' => 'none',
				'dn_start'   => '2026-01-01',
				'dn_end'     => '2026-01-02',
				'dn_tab'     => 'sources',
				'dn_filter'  => array( 'campaign' => 'x' ),
			)
		);

		dn_bfs_assert_same( 'custom', $params['period'] );
		dn_bfs_assert_same( 'sources', $params['tab'] );
		dn_bfs_assert_same( array( 'campaign' => 'x' ), $params['filter'] );
		dn_bfs_assert_same( array(), dn_bfs_params_from_query( array() )['filter'] );
	}
);

dn_bfs_it(
	'admin permission follows the capability filter',
	function () {
		wp_set_current_user( 0 );
		dn_bfs_assert_same( false, dn_bfs_admin_permission() );

		dn_bfs_it_login_admin();
		dn_bfs_assert_same( true, dn_bfs_admin_permission() );

		add_filter( 'dn_bfs_capability', function () { return 'do_not_exist_cap'; } );
		dn_bfs_assert_same( false, dn_bfs_admin_permission() );
		remove_all_filters( 'dn_bfs_capability' );
	}
);
```

Run tích hợp → Expected: test mới FAIL (`Call to undefined function dn_bfs_parse_range()`).

- [ ] **Step 4: Tạo `includes/admin/request.php`**

```php
<?php
/**
 * Admin permissions and validation of report parameters (shared with the public API).
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

function dn_bfs_request_error( $code, $message, $status = 400, $extra = array() ) {
	return new WP_Error( $code, $message, array_merge( array( 'status' => (int) $status ), $extra ) );
}

function dn_bfs_valid_date_string( $value ) {
	if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
		return false;
	}

	$date = DateTime::createFromFormat( '!Y-m-d', $value );

	return $date && $date->format( 'Y-m-d' ) === $value;
}

function dn_bfs_parse_range( $params ) {
	$settings = dn_bfs_get_tracking_settings();
	$period   = isset( $params['period'] ) && is_string( $params['period'] ) && '' !== $params['period'] ? $params['period'] : $settings['default_date_range'];
	$compare  = isset( $params['compare'] ) && is_string( $params['compare'] ) && '' !== $params['compare'] ? $params['compare'] : $settings['default_compare'];

	if ( ! array_key_exists( $period, dn_bfs_get_date_presets() ) ) {
		return dn_bfs_request_error( 'invalid_period', __( 'Unknown date range.', 'dn-burst-funnel-stats' ) );
	}

	if ( ! in_array( $compare, array( 'none', 'previous_period', 'previous_year' ), true ) ) {
		return dn_bfs_request_error( 'invalid_compare', __( 'Unknown comparison mode.', 'dn-burst-funnel-stats' ) );
	}

	$start = '';
	$end   = '';

	if ( 'custom' === $period ) {
		$start = isset( $params['start'] ) ? $params['start'] : '';
		$end   = isset( $params['end'] ) ? $params['end'] : '';

		if ( ! dn_bfs_valid_date_string( $start ) || ! dn_bfs_valid_date_string( $end ) || $start > $end ) {
			return dn_bfs_request_error( 'invalid_date', __( 'Use valid start and end dates in YYYY-MM-DD format.', 'dn-burst-funnel-stats' ) );
		}

		if ( count( dn_bfs_dates_between( $start, $end ) ) > 731 ) {
			return dn_bfs_request_error( 'range_too_long', __( 'Custom ranges can cover at most 731 days.', 'dn-burst-funnel-stats' ) );
		}
	}

	return dn_bfs_calculate_date_range( $period, $compare, $start, $end );
}

function dn_bfs_parse_filters( $params ) {
	$raw = isset( $params['filter'] ) ? $params['filter'] : array();

	if ( ! is_array( $raw ) ) {
		return dn_bfs_request_error( 'invalid_filter', __( 'Filters must be sent as filter[dimension]=value.', 'dn-burst-funnel-stats' ) );
	}

	foreach ( $raw as $dimension => $value ) {
		if ( ! in_array( $dimension, dn_bfs_filter_dimensions(), true ) ) {
			/* translators: %s: filter dimension. */
			return dn_bfs_request_error( 'invalid_filter', sprintf( __( 'Unknown filter: %s.', 'dn-burst-funnel-stats' ), $dimension ) );
		}

		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			/* translators: %s: filter dimension. */
			return dn_bfs_request_error( 'invalid_filter', sprintf( __( 'Filter %s needs a value.', 'dn-burst-funnel-stats' ), $dimension ) );
		}
	}

	return dn_bfs_sanitize_filters( $raw );
}

function dn_bfs_parse_metrics( $value ) {
	$metrics = is_array( $value ) ? $value : array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );

	if ( empty( $metrics ) ) {
		return array( 'sessions', 'orders', 'revenue' );
	}

	$allowed = array_merge( dn_bfs_metric_columns(), dn_bfs_derived_metric_names() );

	foreach ( $metrics as $metric ) {
		if ( ! in_array( $metric, $allowed, true ) ) {
			/* translators: %s: metric name. */
			return dn_bfs_request_error( 'invalid_metric', sprintf( __( 'Unknown metric: %s.', 'dn-burst-funnel-stats' ), (string) $metric ) );
		}
	}

	return array_values( array_unique( $metrics ) );
}

function dn_bfs_range_meta( $range ) {
	return array(
		'period'               => $range['period'],
		'compare'              => $range['compare'],
		'start'                => $range['custom_start'],
		'end'                  => $range['custom_end'],
		'label'                => $range['current_label'],
		'range_label'          => $range['current_range_label'],
		'compare_label'        => $range['compare_label'],
		'previous_range_label' => $range['previous_range_label'],
	);
}

function dn_bfs_params_from_query( $query ) {
	$pick = function ( $key ) use ( $query ) {
		return isset( $query[ $key ] ) && is_string( $query[ $key ] ) ? $query[ $key ] : '';
	};

	return array(
		'period'  => $pick( 'dn_period' ),
		'compare' => $pick( 'dn_compare' ),
		'start'   => $pick( 'dn_start' ),
		'end'     => $pick( 'dn_end' ),
		'tab'     => $pick( 'dn_tab' ),
		'filter'  => isset( $query['dn_filter'] ) && is_array( $query['dn_filter'] ) ? $query['dn_filter'] : array(),
	);
}
```

- [ ] **Step 5: Nạp module admin** — trong `dn-burst-funnel-stats.php` thêm ngay sau `dn_burst_funnel_stats_load_reports()`:

```php
/**
 * Load admin modules (AJAX handlers run outside wp-admin screens, so load on every request).
 *
 * @return void
 */
function dn_burst_funnel_stats_load_admin()
{
  foreach (array('request') as $module) {
    require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/admin/' . $module . '.php';
  }
}
```

Trong `dn_burst_funnel_stats_bootstrap()` gọi `dn_burst_funnel_stats_load_admin();` ngay sau dòng `require_once DN_BURST_FUNNEL_STATS_PATH . 'includes/date-ranges.php';`. Các task sau thêm module vào mảng này.

- [ ] **Step 6: Chạy test** — tích hợp: tất cả PASS (thêm 5 test); unit + `php74` → OK.

- [ ] **Step 7: Commit**

```bash
git add includes/admin/request.php tests/integration/admin-helpers.php tests/integration/test-admin-request.php dn-burst-funnel-stats.php docs/superpowers/specs/2026-10-03-native-tracking-design.md
git commit -m "feat(admin): add report parameter validation and PHP admin spec

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Mô hình Settings 6 nhóm

**Files:**
- Create: `includes/admin/settings-model.php`, `tests/integration/test-settings-model.php`
- Modify: `includes/tracking.php` (`default_date_range`), `includes/reports/wc-settings.php` (chỉ khi thiếu `wc-refunded`), `tests/php/TrackingSettingsTest.php`, `dn-burst-funnel-stats.php` (thêm `'settings-model'`)

**Interfaces:**
- Consumes: `dn_bfs_get_tracking_settings()`, `dn_bfs_sanitize_tracking_settings()`, `dn_bfs_get_wc_report_settings()`, `dn_bfs_sanitize_wc_report_settings()`, `dn_bfs_get_date_presets()`, `dn_bfs_raw_available_from()`, `dn_bfs_geo_db_path()`, `dn_bfs_now()`.
- Produces: `dn_bfs_default_range_presets()`; `DN_BFS_SECRET_MASK`; `dn_bfs_settings_groups()` (`general, tracking, antispam, woocommerce, geoip, data`); `dn_bfs_wc_report_setting_keys()`; `dn_bfs_geoip_status()` (`database, size, updated_at, attempted_at, last_error, license_set`); `dn_bfs_settings_group_meta( $group, $tracking )`; `dn_bfs_get_settings_group( $group ): array|WP_Error` (`values`, `meta`); `dn_bfs_save_settings_group( $group, $input ): array|WP_Error` (`missing_keys` / `unknown_keys` / `invalid_settings` 400, `invalid_group` 404).

- [ ] **Step 1: Viết test unit fail** — thêm vào `tests/php/TrackingSettingsTest.php`:

```php
	public function test_default_date_range_accepts_presets() {
		$this->assertSame( 'last_week', dn_bfs_sanitize_tracking_settings( array( 'default_date_range' => 'last_week' ) )['default_date_range'] );
		$this->assertSame( 'month_to_date', dn_bfs_sanitize_tracking_settings( array( 'default_date_range' => 'custom' ) )['default_date_range'] );
		$this->assertSame( 'month_to_date', dn_bfs_sanitize_tracking_settings( array( 'default_date_range' => 'nope' ) )['default_date_range'] );
		$this->assertContains( 'year_to_date', dn_bfs_default_range_presets() );
	}
```

Run: `docker compose -f docker/docker-compose.yml run --rm phpunit --filter TrackingSettingsTest` → Expected: FAIL.

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

Run lại → PASS.

- [ ] **Step 3: Mặc định `wc-refunded`** — nếu `dn_bfs_wc_report_defaults()` (`includes/reports/wc-settings.php`) chưa có `wc-refunded` trong `sales_excluded_statuses` thì thêm vào cuối mảng; nếu đã có thì bỏ qua.

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

Run tích hợp → Expected: test mới FAIL.

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

- [ ] **Step 6: Nạp module** — mảng `dn_burst_funnel_stats_load_admin()` thành `array('request', 'settings-model')`.

- [ ] **Step 7: Chạy test** — tích hợp PASS (thêm 6 test); unit + `php74` → OK.

- [ ] **Step 8: Commit**

```bash
git add includes/admin/settings-model.php includes/tracking.php includes/reports/wc-settings.php tests dn-burst-funnel-stats.php
git commit -m "feat(admin): add settings groups model with masked license and full-group saves

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Kiểm tra trạng thái hệ thống

**Files:**
- Create: `includes/admin/system-status.php`, `tests/integration/test-system-status.php`
- Modify: `includes/tracking/collector.php`, `dn-burst-funnel-stats.php` (thêm `'system-status'`)

**Interfaces:**
- Consumes: `dn_bfs_schema_tables_exist()`, `dn_bfs_last_closable_date()`, `dn_bfs_geoip_status()`, `dn_bfs_geo_db_path()`, `dn_bfs_get_tracking_settings()`, `dn_bfs_dates_between()`, `dn_bfs_date_shift()`.
- Produces: collector trả `200 {"ok":true}` khi có header `X-DNBFS-Check: 1` (không ghi gì); `dn_bfs_status_check( $key, $label, $status, $detail )`; `dn_bfs_system_status( $now = null ): array` — thứ tự khóa `tables, schema, aggregation, cron, collect, tracker, geoip, geoip_public, proxy, versions`; `status` ∈ `ok|warning|error|info`.

- [ ] **Step 1: Viết test fail — `tests/integration/test-system-status.php`**

```php
<?php

require_once __DIR__ . '/admin-helpers.php';

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

```

Run tích hợp → Expected: test mới FAIL.

- [ ] **Step 2: Header kiểm tra trong collector** — đầu thân `dn_bfs_rest_collect()` trong `includes/tracking/collector.php` (trước `$now = dn_bfs_now();`):

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

```

- [ ] **Step 4: Nạp module** — thêm `'system-status'` vào mảng `dn_burst_funnel_stats_load_admin()`.

- [ ] **Step 5: Chạy test** — tích hợp PASS (thêm 4 test); unit + `php74` → OK.

- [ ] **Step 6: Commit**

```bash
git add includes/admin/system-status.php includes/tracking/collector.php tests/integration/test-system-status.php dn-burst-funnel-stats.php
git commit -m "feat(admin): add system status checks and loopback health header

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Dữ liệu thẻ và biểu đồ cho dashboard

**Files:**
- Create: `includes/admin/dashboard-data.php`, `tests/integration/test-dashboard-data.php`
- Modify: `dn-burst-funnel-stats.php` (thêm `'dashboard-data'`)

**Interfaces:**
- Consumes: `dn_bfs_report_summary()`, `dn_bfs_report_timeseries()`, `dn_bfs_report_funnel()`, `dn_bfs_report_breakdown()`, `dn_bfs_request_error()`.
- Produces:
  - `dn_bfs_dashboard_card_keys(): array` — `visitors, pageviews, sessions, new_returning, bounce_rate, avg_duration, pages_per_session, product_views, atc, checkouts, orders_aov, items_aoi, conversion_rate, sales_tip, paid_balance`.
  - `dn_bfs_get_user_cards( int $user_id ): array`; `dn_bfs_save_user_cards( int $user_id, $cards ): array|WP_Error` (`null` = về mặc định; `invalid_card` 400).
  - Định dạng: `dn_bfs_dash_money( $value ): string` (HTML từ `wc_price`), `dn_bfs_dash_number( $value, $decimals = 0 )`, `dn_bfs_dash_percent( $value, $decimals = 1 )`, `dn_bfs_dash_duration( $seconds )` (`2m 05s` / `45s`), `dn_bfs_dash_change( array $summary, string $metric ): string` (`+12.5%`, `''` khi không so sánh), `dn_bfs_dash_date_label( string $date ): string`.
  - `dn_bfs_dashboard_cards( array $summary ): array` — `key => array( title, icon, main, secondary, compare, change, help )`.
  - `dn_bfs_dashboard_funnel_payload( array $funnel ): array` — `labels`, `values`, `format` (5 bước, bỏ bước `carts` vì luôn bằng `atc`).
  - `dn_bfs_dashboard_charts( array $range, array $filters ): array|WP_Error` — `estimated`, `sales`, `funnel`, `conversion`, `top` (cùng dạng payload biểu đồ cũ: `labels`, `format`, `series[]` với `label, values, color, format, axis`).

- [ ] **Step 1: Viết test fail — `tests/integration/test-dashboard-data.php`**

```php
<?php

require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/admin-helpers.php';

function dn_bfs_it_dash_order( $product_id, $qty, $status ) {
	$order = wc_create_order();
	$order->add_product( wc_get_product( $product_id ), $qty );
	$order->calculate_totals();
	$order->set_status( $status );
	$order->save();

	return $order;
}

function dn_bfs_it_seed_dashboard_day() {
	$now     = dn_bfs_it_now();
	$product = (int) wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' ) )[0];
	$session = dn_bfs_it_seed_session( array( 'started_at' => $now - 120, 'utm_campaign' => 'sale-10', 'channel' => 'paid', 'pageviews' => 2, 'is_bounce' => 0, 'duration' => 65 ) );

	dn_bfs_it_seed_pageview( $session, '/', $now - 120 );
	dn_bfs_it_seed_pageview( $session, '/product/a/', $now - 100 );
	dn_bfs_it_seed_event( $session, 'product_view', $now - 100, array( 'product_id' => $product ) );
	dn_bfs_it_seed_event( $session, 'add_to_cart', $now - 90, array( 'product_id' => $product, 'qty' => 1 ) );
	dn_bfs_it_seed_event( $session, 'cart', $now - 90, array( 'product_id' => $product, 'qty' => 1 ) );
	dn_bfs_it_seed_event( $session, 'checkout_start', $now - 80 );

	$order = dn_bfs_it_dash_order( $product, 2, 'processing' );
	dn_bfs_it_seed_event( $session, 'order', $now - 60, array( 'order_id' => $order->get_id() ) );
	dn_bfs_raw_get_order( 0, true );

	return $order;
}

dn_bfs_it(
	'user cards default to every card, save order and validate keys',
	function () {
		$user = dn_bfs_it_login_admin();
		delete_user_meta( $user, 'dnbfs_cards' );

		dn_bfs_assert_same( dn_bfs_dashboard_card_keys(), dn_bfs_get_user_cards( $user ) );
		dn_bfs_assert_same( array( 'orders_aov', 'visitors' ), dn_bfs_save_user_cards( $user, array( 'orders_aov', 'visitors', 'orders_aov' ) ) );
		dn_bfs_assert_same( array(), dn_bfs_save_user_cards( $user, array() ) );
		dn_bfs_assert_same( 'invalid_card', dn_bfs_save_user_cards( $user, array( 'nope' ) )->get_error_code() );
		dn_bfs_assert_same( dn_bfs_dashboard_card_keys(), dn_bfs_save_user_cards( $user, null ) );
	}
);

dn_bfs_it_today(
	'cards are built from the summary with comparison and help text',
	function () {
		$order   = dn_bfs_it_seed_dashboard_day();
		$summary = dn_bfs_report_summary( dn_bfs_calculate_date_range( 'today', 'previous_period' ) );
		$cards   = dn_bfs_dashboard_cards( $summary );

		dn_bfs_assert_same( dn_bfs_dashboard_card_keys(), array_keys( $cards ) );
		dn_bfs_assert_same( '1', $cards['visitors']['main'] );
		dn_bfs_assert_same( '0', $cards['visitors']['compare'] );
		dn_bfs_assert_same( '+100.0%', $cards['visitors']['change'] );
		dn_bfs_assert_true( false !== strpos( $cards['atc']['secondary'], '1' ), 'cart count shown' );
		dn_bfs_assert_same( '1m 05s', $cards['avg_duration']['main'] );
		dn_bfs_assert_same( '1', $cards['orders_aov']['main'] );
		dn_bfs_assert_same( wp_strip_all_tags( wc_price( $summary['current']['revenue'] ) ), wp_strip_all_tags( $cards['sales_tip']['main'] ) );
		dn_bfs_assert_true( $summary['current']['revenue'] > 0 && abs( $summary['current']['revenue'] - (float) $order->get_total() ) < 0.01, 'revenue equals the order total' );
		dn_bfs_assert_true( '' !== $cards['paid_balance']['help'], 'help text' );

		$none = dn_bfs_dashboard_cards( dn_bfs_report_summary( dn_bfs_calculate_date_range( 'today', 'none' ) ) );
		dn_bfs_assert_same( '', $none['visitors']['compare'] );
		dn_bfs_assert_same( '', $none['visitors']['change'] );
	}
);

dn_bfs_it_today(
	'chart payloads follow the original chart format',
	function () {
		dn_bfs_it_seed_dashboard_day();
		$charts = dn_bfs_dashboard_charts( dn_bfs_calculate_date_range( 'today', 'none' ), array() );

		dn_bfs_assert_same( 1, count( $charts['sales']['labels'] ) );
		dn_bfs_assert_same( array( 'left', 'right' ), array_column( $charts['sales']['series'], 'axis' ) );
		dn_bfs_assert_same( array( 1, 1, 1, 1, 1 ), $charts['funnel']['values'] );
		dn_bfs_assert_same( 5, count( $charts['funnel']['labels'] ) );
		dn_bfs_assert_same( array( 'sale-10' ), $charts['top']['labels'] );
		dn_bfs_assert_same( 'percent', $charts['conversion']['format'] );
		dn_bfs_assert_same( false, $charts['estimated'] );
	}
);

dn_bfs_it(
	'formatting helpers',
	function () {
		dn_bfs_assert_same( '2m 05s', dn_bfs_dash_duration( 125 ) );
		dn_bfs_assert_same( '45s', dn_bfs_dash_duration( 45 ) );
		dn_bfs_assert_same( '0s', dn_bfs_dash_duration( -5 ) );
		dn_bfs_assert_same( '', dn_bfs_dash_change( array( 'previous' => null, 'change' => array() ), 'visitors' ) );
		dn_bfs_assert_same( '-25.0%', dn_bfs_dash_change( array( 'previous' => array(), 'change' => array( 'visitors' => -25.0 ) ), 'visitors' ) );
	}
);
```

Run tích hợp → Expected: test mới FAIL (`Call to undefined function dn_bfs_dashboard_card_keys()`).

- [ ] **Step 2: Tạo `includes/admin/dashboard-data.php`**

```php
<?php
/**
 * Dashboard cards and chart payloads built from the report API.
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

function dn_bfs_save_user_cards( $user_id, $cards ) {
	if ( null === $cards ) {
		delete_user_meta( (int) $user_id, 'dnbfs_cards' );

		return dn_bfs_get_user_cards( $user_id );
	}

	if ( ! is_array( $cards ) ) {
		return dn_bfs_request_error( 'invalid_card', __( 'Cards must be a list.', 'dn-burst-funnel-stats' ) );
	}

	foreach ( $cards as $card ) {
		if ( ! is_string( $card ) || ! in_array( $card, dn_bfs_dashboard_card_keys(), true ) ) {
			return dn_bfs_request_error( 'invalid_card', __( 'Unknown dashboard card.', 'dn-burst-funnel-stats' ) );
		}
	}

	update_user_meta( (int) $user_id, 'dnbfs_cards', array_values( array_unique( $cards ) ) );

	return dn_bfs_get_user_cards( $user_id );
}

function dn_bfs_dash_money( $value ) {
	return function_exists( 'wc_price' ) ? wc_price( (float) $value ) : esc_html( number_format_i18n( (float) $value, 2 ) );
}

function dn_bfs_dash_number( $value, $decimals = 0 ) {
	return number_format_i18n( (float) $value, $decimals );
}

function dn_bfs_dash_percent( $value, $decimals = 1 ) {
	return number_format_i18n( (float) $value, $decimals ) . '%';
}

function dn_bfs_dash_duration( $seconds ) {
	$total   = max( 0, (int) round( (float) $seconds ) );
	$minutes = (int) floor( $total / 60 );
	$rest    = $total % 60;

	return $minutes > 0 ? sprintf( '%dm %02ds', $minutes, $rest ) : sprintf( '%ds', $rest );
}

function dn_bfs_dash_change( $summary, $metric ) {
	if ( null === $summary['previous'] || ! isset( $summary['change'][ $metric ] ) ) {
		return '';
	}

	$change = (float) $summary['change'][ $metric ];

	return ( $change > 0 ? '+' : '' ) . number_format_i18n( $change, 1 ) . '%';
}

function dn_bfs_dash_date_label( $date ) {
	return wp_date( 'M j', ( new DateTimeImmutable( $date . ' 12:00:00', wp_timezone() ) )->getTimestamp() );
}

function dn_bfs_dashboard_cards( $summary ) {
	$cur      = $summary['current'];
	$prev     = $summary['previous'];
	$has_prev = null !== $prev;
	$was      = function ( $metric ) use ( $prev ) {
		return null === $prev ? 0 : $prev[ $metric ];
	};
	$share    = function ( $value ) use ( $cur ) {
		return $cur['visitors'] > 0 ? dn_bfs_dash_percent( $value / $cur['visitors'] * 100 ) : '0%';
	};
	$card     = function ( $title, $icon, $main, $secondary, $compare, $change, $help ) {
		return array(
			'title'     => $title,
			'icon'      => $icon,
			'main'      => $main,
			'secondary' => $secondary,
			'compare'   => $compare,
			'change'    => $change,
			'help'      => $help,
		);
	};

	return array(
		'visitors'          => $card( __( 'Visitors', 'dn-burst-funnel-stats' ), 'eye', dn_bfs_dash_number( $cur['visitors'] ), '', $has_prev ? dn_bfs_dash_number( $was( 'visitors' ) ) : '', dn_bfs_dash_change( $summary, 'visitors' ), __( 'Unique visitors, identified by a first-party browser cookie.', 'dn-burst-funnel-stats' ) ),
		'pageviews'         => $card( __( 'Pageviews', 'dn-burst-funnel-stats' ), 'layers', dn_bfs_dash_number( $cur['pageviews'] ), '', $has_prev ? dn_bfs_dash_number( $was( 'pageviews' ) ) : '', dn_bfs_dash_change( $summary, 'pageviews' ), __( 'Pages viewed. Reloads of the same page within a few seconds count once.', 'dn-burst-funnel-stats' ) ),
		'sessions'          => $card( __( 'Sessions', 'dn-burst-funnel-stats' ), 'user', dn_bfs_dash_number( $cur['sessions'] ), '', $has_prev ? dn_bfs_dash_number( $was( 'sessions' ) ) : '', dn_bfs_dash_change( $summary, 'sessions' ), __( 'Visits. A session ends after 30 minutes without activity or at midnight.', 'dn-burst-funnel-stats' ) ),
		/* translators: %s: number of returning visitors. */
		'new_returning'     => $card( __( 'New / Returning', 'dn-burst-funnel-stats' ), 'user', dn_bfs_dash_number( $cur['new_visitors'] ), sprintf( __( 'Returning: %s', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['returning_visitors'] ) ), $has_prev ? dn_bfs_dash_number( $was( 'new_visitors' ) ) : '', dn_bfs_dash_change( $summary, 'new_visitors' ), __( 'New visitors had no earlier visit; returning visitors came back.', 'dn-burst-funnel-stats' ) ),
		'bounce_rate'       => $card( __( 'Bounce Rate', 'dn-burst-funnel-stats' ), 'check', dn_bfs_dash_percent( $cur['bounce_rate'] ), '', $has_prev ? dn_bfs_dash_percent( $was( 'bounce_rate' ) ) : '', dn_bfs_dash_change( $summary, 'bounce_rate' ), __( 'Sessions with a single pageview.', 'dn-burst-funnel-stats' ) ),
		'avg_duration'      => $card( __( 'Avg. Session Time', 'dn-burst-funnel-stats' ), 'clock', dn_bfs_dash_duration( $cur['avg_duration'] ), '', $has_prev ? dn_bfs_dash_duration( $was( 'avg_duration' ) ) : '', dn_bfs_dash_change( $summary, 'avg_duration' ), __( 'Average time the tab was visible per session.', 'dn-burst-funnel-stats' ) ),
		'pages_per_session' => $card( __( 'Pages / Session', 'dn-burst-funnel-stats' ), 'layers', dn_bfs_dash_number( $cur['pages_per_session'], 2 ), '', $has_prev ? dn_bfs_dash_number( $was( 'pages_per_session' ), 2 ) : '', dn_bfs_dash_change( $summary, 'pages_per_session' ), __( 'Average pageviews per session.', 'dn-burst-funnel-stats' ) ),
		'product_views'     => $card( __( 'Product Views', 'dn-burst-funnel-stats' ), 'product', dn_bfs_dash_number( $cur['product_views'] ), $share( $cur['product_views'] ), $has_prev ? dn_bfs_dash_number( $was( 'product_views' ) ) : '', dn_bfs_dash_change( $summary, 'product_views' ), __( 'Each visitor counts once per product within the anti-spam window.', 'dn-burst-funnel-stats' ) ),
		/* translators: %s: number of cart events. */
		'atc'               => $card( __( 'Add To Cart', 'dn-burst-funnel-stats' ), 'cart', dn_bfs_dash_number( $cur['atc'] ), sprintf( __( 'Cart: %s', 'dn-burst-funnel-stats' ), dn_bfs_dash_number( $cur['carts'] ) ), $has_prev ? dn_bfs_dash_number( $was( 'atc' ) ) : '', dn_bfs_dash_change( $summary, 'atc' ), __( 'Add to cart and Cart are recorded together, so they are always equal.', 'dn-burst-funnel-stats' ) ),
		'checkouts'         => $card( __( 'Checkout', 'dn-burst-funnel-stats' ), 'checkout', dn_bfs_dash_number( $cur['checkouts'] ), $share( $cur['checkouts'] ), $has_prev ? dn_bfs_dash_number( $was( 'checkouts' ) ) : '', dn_bfs_dash_change( $summary, 'checkouts' ), __( 'Sessions that reached the checkout page.', 'dn-burst-funnel-stats' ) ),
		'orders_aov'        => $card( __( 'Orders / AOV', 'dn-burst-funnel-stats' ), 'orders', dn_bfs_dash_number( $cur['orders'] ), dn_bfs_dash_money( $cur['aov'] ), $has_prev ? dn_bfs_dash_number( $was( 'orders' ) ) : '', dn_bfs_dash_change( $summary, 'orders' ), __( 'Orders exclude cancelled, failed, draft and fully refunded orders. AOV is net of refunds.', 'dn-burst-funnel-stats' ) ),
		'items_aoi'         => $card( __( 'Items / AOI', 'dn-burst-funnel-stats' ), 'box', dn_bfs_dash_number( $cur['items'] ), dn_bfs_dash_number( $cur['aoi'], 2 ), $has_prev ? dn_bfs_dash_number( $was( 'items' ) ) : '', dn_bfs_dash_change( $summary, 'items' ), __( 'Items sold, net of refunded quantities.', 'dn-burst-funnel-stats' ) ),
		'conversion_rate'   => $card( __( 'Conversion Rate', 'dn-burst-funnel-stats' ), 'check', dn_bfs_dash_percent( $cur['conversion_rate'], 2 ), '', $has_prev ? dn_bfs_dash_percent( $was( 'conversion_rate' ), 2 ) : '', dn_bfs_dash_change( $summary, 'conversion_rate' ), __( 'Orders divided by visitors.', 'dn-burst-funnel-stats' ) ),
		/* translators: %s: tip total. */
		'sales_tip'         => $card( __( 'Sales / Tip', 'dn-burst-funnel-stats' ), 'dollar', dn_bfs_dash_money( $cur['revenue'] ), sprintf( __( 'Tip: %s', 'dn-burst-funnel-stats' ), dn_bfs_dash_money( $cur['tips'] ) ), $has_prev ? dn_bfs_dash_money( $was( 'revenue' ) ) : '', dn_bfs_dash_change( $summary, 'revenue' ), __( 'Sales are net of refunds; tips are gross fee totals.', 'dn-burst-funnel-stats' ) ),
		'paid_balance'      => $card( __( 'Paid / Balance', 'dn-burst-funnel-stats' ), 'money', dn_bfs_dash_money( $cur['paid'] ) . ' / ' . dn_bfs_dash_money( $cur['balance'] ), '', $has_prev ? dn_bfs_dash_money( $was( 'paid' ) ) : '', dn_bfs_dash_change( $summary, 'paid' ), __( 'Paid = processing and completed orders, net of refunds. Balance = pending and on-hold orders.', 'dn-burst-funnel-stats' ) ),
	);
}

function dn_bfs_dashboard_funnel_payload( $funnel ) {
	$labels = array(
		'visitors'      => __( 'Visitors', 'dn-burst-funnel-stats' ),
		'product_views' => __( 'Product views', 'dn-burst-funnel-stats' ),
		'atc'           => __( 'Add to cart (= Cart)', 'dn-burst-funnel-stats' ),
		'checkouts'     => __( 'Checkout', 'dn-burst-funnel-stats' ),
		'orders'        => __( 'Orders', 'dn-burst-funnel-stats' ),
	);
	$steps  = array();

	foreach ( $funnel as $step ) {
		if ( isset( $labels[ $step['key'] ] ) ) {
			$steps[ $step['key'] ] = (int) $step['value'];
		}
	}

	return array(
		'format' => 'integer',
		'labels' => array_values( array_intersect_key( $labels, $steps ) ),
		'values' => array_values( $steps ),
	);
}

function dn_bfs_dashboard_charts( $range, $filters ) {
	$series = dn_bfs_report_timeseries( $range, array( 'revenue', 'orders', 'conversion_rate' ), $filters );

	if ( is_wp_error( $series ) ) {
		return $series;
	}

	$funnel = dn_bfs_report_funnel( $range, $filters );

	if ( is_wp_error( $funnel ) ) {
		return $funnel;
	}

	$top = dn_bfs_report_breakdown( $range, 'campaign', $filters, 'revenue', 'desc', 8, 0 );

	if ( is_wp_error( $top ) ) {
		return $top;
	}

	$labels     = array_map( 'dn_bfs_dash_date_label', $series['labels'] );
	$top_labels = array();
	$top_values = array();

	foreach ( $top['rows'] as $row ) {
		if ( (float) $row['revenue'] <= 0 ) {
			continue;
		}

		$top_labels[] = '' === (string) $row['dim_value'] ? __( '(none)', 'dn-burst-funnel-stats' ) : (string) $row['dim_value'];
		$top_values[] = round( (float) $row['revenue'], 2 );
	}

	return array(
		'estimated'  => ! empty( $series['estimated'] ) || ! empty( $top['estimated'] ),
		'sales'      => array(
			'labels' => $labels,
			'format' => 'money',
			'series' => array(
				array(
					'label'  => __( 'Net sales', 'dn-burst-funnel-stats' ),
					'values' => $series['series']['revenue'],
					'color'  => '#2271b1',
					'format' => 'money',
					'axis'   => 'left',
				),
				array(
					'label'  => __( 'Orders', 'dn-burst-funnel-stats' ),
					'values' => $series['series']['orders'],
					'color'  => '#7f54b3',
					'format' => 'integer',
					'axis'   => 'right',
				),
			),
		),
		'funnel'     => dn_bfs_dashboard_funnel_payload( $funnel ),
		'conversion' => array(
			'labels' => $labels,
			'format' => 'percent',
			'values' => $series['series']['conversion_rate'],
			'series' => array(
				array(
					'label'  => __( 'Conversion rate', 'dn-burst-funnel-stats' ),
					'values' => $series['series']['conversion_rate'],
					'color'  => '#d63638',
					'format' => 'percent',
					'axis'   => 'left',
				),
			),
		),
		'top'        => array(
			'labels' => $top_labels,
			'format' => 'money',
			'values' => $top_values,
			'series' => array(
				array(
					'label'  => __( 'Sales', 'dn-burst-funnel-stats' ),
					'values' => $top_values,
					'color'  => '#2271b1',
					'format' => 'money',
					'axis'   => 'left',
				),
			),
		),
	);
}
```

- [ ] **Step 3: Nạp module** — thêm `'dashboard-data'` vào mảng `dn_burst_funnel_stats_load_admin()`.

- [ ] **Step 4: Chạy test** — tích hợp PASS (thêm 4 test); unit + `php74` → OK.

- [ ] **Step 5: Commit**

```bash
git add includes/admin/dashboard-data.php tests/integration/test-dashboard-data.php dn-burst-funnel-stats.php
git commit -m "feat(admin): build dashboard cards and chart payloads from the report API

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Render dashboard (PHP) + handler AJAX

**Files:**
- Create: `includes/admin/dashboard-page.php`, `includes/admin/ajax.php`, `tests/integration/test-dashboard-page.php`
- Modify: `dn-burst-funnel-stats.php` (thêm `'dashboard-page', 'ajax'`)

**Interfaces:**
- Consumes: Task 1, 4; `reports.php`; `dn_bfs_aggregate_run()`.
- Produces:
  - `dn_bfs_dash_tabs(): array` — `tab => array( 'label', 'dimensions' => string[] )` cho `overview, pages, sources, ad-urls, products, brands, countries, devices`.
  - `dn_bfs_dash_sanitize_tab()`, `dn_bfs_dash_dimension_labels()`, `dn_bfs_dash_channel_labels()`, `dn_bfs_dash_metric_labels()`, `dn_bfs_dash_columns( $dimension )`, `dn_bfs_dash_format_cell( $metric, $value )`, `dn_bfs_dash_value_label( $dimension, $row )`, `dn_bfs_dash_notice( $message, $type = 'warning' )`, `dn_bfs_dash_estimate_note()`, `dn_bfs_dash_request( $params ): array|WP_Error` (`array( $range, $filters )`), `dn_bfs_dash_url( $tab, $range, $filters )`, `dn_bfs_dash_status_labels(): array` (`last`, `next`).
  - Render (giữ markup + class CSS cũ): `dn_bfs_dash_icon()`, biểu đồ `dn_bfs_dash_render_chart_panel( $title, $type, $data )` (+ các helper legend/total), `dn_bfs_dash_render_card( $key, $card, $compare_label, $hidden )`, `dn_bfs_dash_render_date_picker( $range, $tab )`, `dn_bfs_dash_render_data_status()`, `dn_bfs_dash_render_online_badge()`, `dn_bfs_dash_render_filter_bar( $filters )`.
  - HTML (trả chuỗi): `dn_bfs_dash_overview_html( $range, $filters )`, `dn_bfs_dash_breakdown_html( $tab, $range, $filters, $args )`, `dn_bfs_dash_table_html( $tab, $dimension, $range, $filters, $args = array() )`, `dn_bfs_dash_brand_breakdown( $range, $filters, $orderby, $order, $limit, $offset ): array|WP_Error`, `dn_bfs_dash_drilldown_html( $range, $filters, $dimension, $value )`, `dn_bfs_dash_tab_html( $tab, $range, $filters, $args = array() )`.
  - `dn_bfs_dash_render_page()` (callback menu — nối ở Task 7).
  - AJAX payload: `dn_bfs_ajax_tab_payload( $params )`, `dn_bfs_ajax_table_payload( $params )`, `dn_bfs_ajax_drilldown_payload( $params )`, `dn_bfs_ajax_realtime_payload()`, `dn_bfs_ajax_save_cards_payload( $user_id, $params )`, `dn_bfs_ajax_filter_values_payload( $params )`, `dn_bfs_ajax_update_now_payload()` — trả `array|WP_Error`; handler `wp_ajax_dn_bfs_*` (guard quyền + nonce `dn_bfs_admin`).
  - Markup data-attribute JS dùng (Task 7): `[data-dn-tab]`, `[data-dn-tab-content]`, `[data-dn-cards]`, `[data-dn-card]`, `[data-dn-card-visible]`, `[data-dn-cards-edit|save|cancel|reset]`, `[data-dn-dimension] input[name=dn_dimension]`, `[data-dn-table-region]`, `[data-dn-table]` (`data-tab`, `data-dimension`, `data-orderby`, `data-order`, `data-page`), `[data-dn-sort]` + `data-dn-order`, `[data-dn-page]`, `tr[data-dn-drill-dimension][data-dn-drill-value]`, `[data-dn-drawer-close]`, `[data-dn-apply-filter]` (`data-dimension`, `data-value`), `[data-dn-filter-bar]`, `[data-dn-filter-chips]`, `[data-dn-filter-dim]`, `[data-dn-filter-remove]`, `[data-dn-filter-toggle]`, `[data-dn-filter-form]`, `[data-dn-filter-dimension]`, `[data-dn-filter-value]`, `#dn-filter-values`, `[data-dn-online]`, `[data-dn-online-count]`, `[data-dn-online-popover]`, `[data-dn-status-panel]`, `[data-dn-update-now]`, `[data-dn-last-update]`, `[data-dn-next-update]`, `[data-dn-status-message]`, date picker `[data-dn-date-*]` như cũ.

- [ ] **Step 1: Viết test fail — `tests/integration/test-dashboard-page.php`**

```php
<?php

require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/admin-helpers.php';

function dn_bfs_it_seed_page_traffic() {
	$now = dn_bfs_it_now();

	foreach ( array( array( 'paid', 'sale-10', 'mobile' ), array( 'direct', '', 'desktop' ) ) as $i => $row ) {
		$s = dn_bfs_it_seed_session( array( 'started_at' => $now - 100 - $i, 'channel' => $row[0], 'utm_campaign' => $row[1], 'device' => $row[2] ) );
		dn_bfs_it_seed_pageview( $s, '/landing-' . $i . '/', $now - 100 - $i );
	}
}

dn_bfs_it_today(
	'overview payload renders cards, charts and range meta',
	function () {
		$user = dn_bfs_it_login_admin();
		delete_user_meta( $user, 'dnbfs_cards' );
		dn_bfs_it_seed_page_traffic();

		$payload = dn_bfs_ajax_tab_payload( array( 'tab' => 'overview', 'period' => 'today', 'compare' => 'previous_period' ) );

		dn_bfs_assert_same( 'overview', $payload['tab'] );
		dn_bfs_assert_same( 'Overview', $payload['title'] );
		dn_bfs_assert_same( 15, substr_count( $payload['html'], 'data-dn-card="' ) );
		dn_bfs_assert_same( 4, substr_count( $payload['html'], 'data-dn-chart="' ) );
		dn_bfs_assert_true( isset( $payload['range']['current_range_label'] ), 'range meta' );
	}
);

dn_bfs_it_today(
	'hidden cards keep their place after visible ones and carry the hidden class',
	function () {
		$user = dn_bfs_it_login_admin();
		dn_bfs_save_user_cards( $user, array( 'orders_aov' ) );

		$html = dn_bfs_ajax_tab_payload( array( 'tab' => 'overview', 'period' => 'today' ) )['html'];

		dn_bfs_assert_true( strpos( $html, 'data-dn-card="orders_aov"' ) < strpos( $html, 'data-dn-card="visitors"' ), 'visible card first' );
		dn_bfs_assert_true( (bool) preg_match( '/class="dn-burst-card is-hidden" data-dn-card="visitors"/', $html ), 'visitors hidden' );
		dn_bfs_save_user_cards( $user, null );
	}
);

dn_bfs_it_today(
	'breakdown tables render dimension switches, sorting and drillable rows',
	function () {
		dn_bfs_it_login_admin();
		dn_bfs_it_seed_page_traffic();

		$sources = dn_bfs_ajax_tab_payload( array( 'tab' => 'sources', 'period' => 'today' ) )['html'];
		dn_bfs_assert_same( 4, substr_count( $sources, 'name="dn_dimension"' ) );
		dn_bfs_assert_true( false !== strpos( $sources, 'data-dn-drill-dimension="channel" data-dn-drill-value="paid"' ), 'drillable paid row' );
		dn_bfs_assert_true( false !== strpos( $sources, 'Paid' ), 'channel label' );

		$campaigns = dn_bfs_ajax_table_payload( array( 'tab' => 'ad-urls', 'dimension' => 'campaign', 'orderby' => 'visitors', 'order' => 'asc', 'period' => 'today' ) )['html'];
		dn_bfs_assert_true( false !== strpos( $campaigns, '(none)' ), 'empty campaign shown' );
		dn_bfs_assert_same( 1, substr_count( $campaigns, 'data-dn-drill-dimension="campaign"' ) );
		dn_bfs_assert_true( false !== strpos( $campaigns, 'data-orderby="visitors" data-order="asc"' ), 'sort state' );

		$pages = dn_bfs_ajax_tab_payload( array( 'tab' => 'pages', 'period' => 'today' ) )['html'];
		dn_bfs_assert_true( false === strpos( $pages, 'data-dn-drill-dimension' ), 'pages not drillable' );

		dn_bfs_assert_same( 'invalid_tab', dn_bfs_ajax_table_payload( array( 'tab' => 'overview' ) )->get_error_code() );
	}
);

dn_bfs_it_today(
	'brands tab groups products by brand',
	function () {
		dn_bfs_it_login_admin();

		$product  = (int) wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' ) )[0];
		$taxonomy = taxonomy_exists( 'product_brand' ) ? 'product_brand' : '';

		if ( '' === $taxonomy ) {
			register_taxonomy( 'product_brand', 'product' );
			$taxonomy = 'product_brand';
		}

		wp_set_object_terms( $product, 'Acme', $taxonomy );
		$s = dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_now() - 60 ) );
		dn_bfs_it_seed_event( $s, 'product_view', dn_bfs_it_now() - 60, array( 'product_id' => $product ) );

		$html = dn_bfs_ajax_tab_payload( array( 'tab' => 'brands', 'period' => 'today' ) )['html'];
		dn_bfs_assert_true( false !== strpos( $html, 'Acme' ), 'brand row' );

		wp_set_object_terms( $product, array(), $taxonomy );
	}
);

dn_bfs_it_today(
	'drill-down shows a filtered summary with an apply button',
	function () {
		dn_bfs_it_login_admin();
		dn_bfs_it_seed_page_traffic();

		$html = dn_bfs_ajax_drilldown_payload( array( 'period' => 'today', 'dimension' => 'campaign', 'value' => 'sale-10' ) )['html'];
		dn_bfs_assert_true( false !== strpos( $html, 'Campaign: sale-10' ), 'title' );
		dn_bfs_assert_true( false !== strpos( $html, 'data-dn-apply-filter' ), 'apply button' );
		dn_bfs_assert_same( 2, substr_count( $html, 'data-dn-chart="' ) );

		$refused = dn_bfs_ajax_drilldown_payload( array( 'period' => 'today', 'dimension' => 'browser', 'value' => 'Chrome' ) )['html'];
		dn_bfs_assert_true( false !== strpos( $refused, 'notice' ), 'refused' );
	}
);

dn_bfs_it_today(
	'out-of-retention filters show a notice instead of failing',
	function () {
		dn_bfs_it_login_admin();

		$payload = dn_bfs_ajax_tab_payload(
			array(
				'tab'    => 'overview',
				'period' => 'custom',
				'start'  => dn_bfs_date_shift( wp_date( 'Y-m-d', dn_bfs_it_now() ), -400 ),
				'end'    => wp_date( 'Y-m-d', dn_bfs_it_now() ),
				'filter' => array( 'channel' => 'paid', 'device' => 'mobile' ),
			)
		);

		dn_bfs_assert_true( false !== strpos( $payload['html'], 'notice-warning' ), 'notice' );
		dn_bfs_assert_same( 'invalid_filter', dn_bfs_ajax_tab_payload( array( 'filter' => array( 'browser' => 'x' ) ) )->get_error_code() );
	}
);

dn_bfs_it_today(
	'cards, filter values, realtime and update-now payloads',
	function () {
		$user = dn_bfs_it_login_admin();
		dn_bfs_it_seed_page_traffic();

		dn_bfs_assert_same( array( 'visitors' ), dn_bfs_ajax_save_cards_payload( $user, array( 'cards' => array( 'visitors' ) ) )['cards'] );
		dn_bfs_assert_same( array(), dn_bfs_ajax_save_cards_payload( $user, array( 'cards_sent' => 1 ) )['cards'] );
		dn_bfs_assert_same( dn_bfs_dashboard_card_keys(), dn_bfs_ajax_save_cards_payload( $user, array( 'reset' => 1 ) )['cards'] );
		dn_bfs_assert_same( 'invalid_card', dn_bfs_ajax_save_cards_payload( $user, array( 'cards' => array( 'x' ) ) )->get_error_code() );

		dn_bfs_assert_same( array( 'sale-10' ), dn_bfs_ajax_filter_values_payload( array( 'dimension' => 'campaign', 'search' => 'sale' ) )['values'] );
		dn_bfs_assert_same( 'invalid_dimension', dn_bfs_ajax_filter_values_payload( array( 'dimension' => 'page' ) )->get_error_code() );

		dn_bfs_assert_true( isset( dn_bfs_ajax_realtime_payload()['online'] ), 'realtime' );

		$update = dn_bfs_ajax_update_now_payload();
		dn_bfs_assert_true( isset( $update['message'], $update['lastUpdate'], $update['nextUpdate'] ), 'update labels' );
	}
);

dn_bfs_it_today(
	'dashboard page renders toolbar, filter chips and tabs from the URL',
	function () {
		dn_bfs_it_login_admin();
		$_GET = array( 'page' => 'dn-burst-funnel-stats', 'dn_tab' => 'sources', 'dn_period' => 'today', 'dn_filter' => array( 'campaign' => 'sale-10' ) );

		ob_start();
		dn_bfs_dash_render_page();
		$html = ob_get_clean();

		dn_bfs_assert_true( false !== strpos( $html, 'data-dn-filter-dim="campaign"' ), 'chip' );
		dn_bfs_assert_true( false !== strpos( $html, 'Campaign: sale-10' ), 'chip label' );
		dn_bfs_assert_true( (bool) preg_match( '/nav-tab nav-tab-active" data-dn-tab="sources"/', $html ), 'active tab' );
		dn_bfs_assert_true( false !== strpos( $html, 'data-dn-online' ), 'online badge' );
		dn_bfs_assert_true( false !== strpos( $html, 'data-dn-update-now' ), 'update now' );

		$_GET = array( 'page' => 'dn-burst-funnel-stats', 'dn_period' => 'nope' );
		ob_start();
		dn_bfs_dash_render_page();
		$fallback = ob_get_clean();
		dn_bfs_assert_true( false !== strpos( $fallback, 'Unknown date range.' ), 'invalid URL notice' );
		$_GET = array();
	}
);
```

Run tích hợp → Expected: test mới FAIL.

- [ ] **Step 2: Tạo `includes/admin/dashboard-page.php`**

```php
<?php
/**
 * Dashboard page rendering, upgraded from the original layout.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_dash_tabs() {
	return array(
		'overview'  => array( 'label' => __( 'Overview', 'dn-burst-funnel-stats' ), 'dimensions' => array() ),
		'pages'     => array( 'label' => __( 'Pages', 'dn-burst-funnel-stats' ), 'dimensions' => array( 'page', 'entry', 'exit' ) ),
		'sources'   => array( 'label' => __( 'Sources', 'dn-burst-funnel-stats' ), 'dimensions' => array( 'channel', 'referrer', 'source', 'medium' ) ),
		'ad-urls'   => array( 'label' => __( 'Ad URLs', 'dn-burst-funnel-stats' ), 'dimensions' => array( 'campaign', 'source', 'medium' ) ),
		'products'  => array( 'label' => __( 'Products', 'dn-burst-funnel-stats' ), 'dimensions' => array( 'product' ) ),
		'brands'    => array( 'label' => __( 'Brands', 'dn-burst-funnel-stats' ), 'dimensions' => array( 'brand' ) ),
		'countries' => array( 'label' => __( 'Countries', 'dn-burst-funnel-stats' ), 'dimensions' => array( 'country', 'city' ) ),
		'devices'   => array( 'label' => __( 'Devices', 'dn-burst-funnel-stats' ), 'dimensions' => array( 'device', 'browser', 'os' ) ),
	);
}

function dn_bfs_dash_sanitize_tab( $tab ) {
	$tab = sanitize_key( (string) $tab );

	return array_key_exists( $tab, dn_bfs_dash_tabs() ) ? $tab : 'overview';
}

function dn_bfs_dash_dimension_labels() {
	return array(
		'page'     => __( 'Page', 'dn-burst-funnel-stats' ),
		'entry'    => __( 'Entry page', 'dn-burst-funnel-stats' ),
		'exit'     => __( 'Exit page', 'dn-burst-funnel-stats' ),
		'channel'  => __( 'Channel', 'dn-burst-funnel-stats' ),
		'referrer' => __( 'Referrer', 'dn-burst-funnel-stats' ),
		'source'   => __( 'Source', 'dn-burst-funnel-stats' ),
		'medium'   => __( 'Medium', 'dn-burst-funnel-stats' ),
		'campaign' => __( 'Campaign', 'dn-burst-funnel-stats' ),
		'product'  => __( 'Product', 'dn-burst-funnel-stats' ),
		'brand'    => __( 'Brand', 'dn-burst-funnel-stats' ),
		'country'  => __( 'Country', 'dn-burst-funnel-stats' ),
		'city'     => __( 'City', 'dn-burst-funnel-stats' ),
		'device'   => __( 'Device', 'dn-burst-funnel-stats' ),
		'browser'  => __( 'Browser', 'dn-burst-funnel-stats' ),
		'os'       => __( 'Operating system', 'dn-burst-funnel-stats' ),
	);
}

function dn_bfs_dash_channel_labels() {
	return array(
		'direct'         => __( 'Direct', 'dn-burst-funnel-stats' ),
		'organic_search' => __( 'Organic search', 'dn-burst-funnel-stats' ),
		'social'         => __( 'Social', 'dn-burst-funnel-stats' ),
		'paid'           => __( 'Paid', 'dn-burst-funnel-stats' ),
		'email'          => __( 'Email', 'dn-burst-funnel-stats' ),
		'referral'       => __( 'Referral', 'dn-burst-funnel-stats' ),
	);
}

function dn_bfs_dash_metric_labels() {
	return array(
		'pageviews'       => __( 'Pageviews', 'dn-burst-funnel-stats' ),
		'visitors'        => __( 'Visitors', 'dn-burst-funnel-stats' ),
		'sessions'        => __( 'Sessions', 'dn-burst-funnel-stats' ),
		'bounce_rate'     => __( 'Bounce rate', 'dn-burst-funnel-stats' ),
		'avg_duration'    => __( 'Avg. time', 'dn-burst-funnel-stats' ),
		'product_views'   => __( 'Product views', 'dn-burst-funnel-stats' ),
		'atc'             => __( 'Add to cart', 'dn-burst-funnel-stats' ),
		'checkouts'       => __( 'Checkout', 'dn-burst-funnel-stats' ),
		'orders'          => __( 'Orders', 'dn-burst-funnel-stats' ),
		'items'           => __( 'Items', 'dn-burst-funnel-stats' ),
		'revenue'         => __( 'Sales', 'dn-burst-funnel-stats' ),
		'conversion_rate' => __( 'Conversion', 'dn-burst-funnel-stats' ),
	);
}

function dn_bfs_dash_columns( $dimension ) {
	if ( 'page' === $dimension ) {
		return array( 'pageviews', 'visitors', 'avg_duration' );
	}

	if ( in_array( $dimension, array( 'entry', 'exit' ), true ) ) {
		return array( 'sessions', 'visitors', 'bounce_rate', 'avg_duration', 'orders', 'conversion_rate' );
	}

	if ( in_array( $dimension, array( 'product', 'brand' ), true ) ) {
		return array( 'product_views', 'atc', 'orders', 'items', 'revenue' );
	}

	return array( 'visitors', 'sessions', 'bounce_rate', 'product_views', 'atc', 'checkouts', 'orders', 'revenue', 'conversion_rate' );
}

function dn_bfs_dash_format_cell( $metric, $value ) {
	if ( in_array( $metric, array( 'revenue', 'tips', 'paid', 'balance', 'aov' ), true ) ) {
		return dn_bfs_dash_money( $value );
	}

	if ( 'bounce_rate' === $metric ) {
		return esc_html( dn_bfs_dash_percent( $value ) );
	}

	if ( 'conversion_rate' === $metric ) {
		return esc_html( dn_bfs_dash_percent( $value, 2 ) );
	}

	if ( 'avg_duration' === $metric ) {
		return esc_html( dn_bfs_dash_duration( $value ) );
	}

	if ( in_array( $metric, array( 'pages_per_session', 'aoi' ), true ) ) {
		return esc_html( dn_bfs_dash_number( $value, 2 ) );
	}

	return esc_html( dn_bfs_dash_number( $value ) );
}

function dn_bfs_dash_value_label( $dimension, $row ) {
	$value = (string) $row['dim_value'];

	if ( '' === $value ) {
		return __( '(none)', 'dn-burst-funnel-stats' );
	}

	if ( 'channel' === $dimension ) {
		$labels = dn_bfs_dash_channel_labels();

		return isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
	}

	return isset( $row['label'] ) && '' !== (string) $row['label'] ? (string) $row['label'] : $value;
}

function dn_bfs_dash_notice( $message, $type = 'warning' ) {
	return sprintf( '<div class="notice notice-%1$s inline dn-burst-notice"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $message ) );
}

function dn_bfs_dash_estimate_note() {
	return '<p class="dn-burst-estimate-note">' . esc_html__( 'Visitor counts are estimated for this range (summed per day).', 'dn-burst-funnel-stats' ) . '</p>';
}

function dn_bfs_dash_request( $params ) {
	$range = dn_bfs_parse_range( $params );

	if ( is_wp_error( $range ) ) {
		return $range;
	}

	$filters = dn_bfs_parse_filters( $params );

	if ( is_wp_error( $filters ) ) {
		return $filters;
	}

	return array( $range, $filters );
}

function dn_bfs_dash_url( $tab, $range, $filters ) {
	$args = array(
		'page'       => 'dn-burst-funnel-stats',
		'dn_tab'     => $tab,
		'dn_period'  => $range['period'],
		'dn_compare' => $range['compare'],
	);

	if ( 'custom' === $range['period'] ) {
		$args['dn_start'] = $range['custom_start'];
		$args['dn_end']   = $range['custom_end'];
	}

	if ( $filters ) {
		$args['dn_filter'] = array_map( 'rawurlencode', $filters );
	}

	return add_query_arg( array_map( function ( $value ) {
		return is_array( $value ) ? $value : rawurlencode( (string) $value );
	}, $args ), admin_url( 'admin.php' ) );
}

function dn_bfs_dash_status_labels() {
	$last = (string) get_option( 'dnbfs_last_aggregated_date', '' );
	$next = wp_next_scheduled( 'dnbfs_aggregate' );

	return array(
		'last' => '' !== $last ? wp_date( get_option( 'date_format' ), ( new DateTimeImmutable( $last . ' 12:00:00', wp_timezone() ) )->getTimestamp() ) : __( 'Not yet', 'dn-burst-funnel-stats' ),
		'next' => $next ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) : __( 'Not scheduled', 'dn-burst-funnel-stats' ),
	);
}

function dn_bfs_dash_icon( $icon ) {
	$map = array(
		'eye'      => '◉',
		'cart'     => '🛒',
		'checkout' => '🛍',
		'orders'   => '⮂',
		'box'      => '⬢',
		'check'    => '◔',
		'dollar'   => '$',
		'money'    => '$',
		'product'  => '◫',
		'layers'   => '▤',
		'user'     => '☺',
		'clock'    => '◷',
	);

	return isset( $map[ $icon ] ) ? $map[ $icon ] : '•';
}

function dn_bfs_dash_chart_series_total( $series ) {
	$total = 0;

	foreach ( (array) $series as $value ) {
		$total += (float) $value;
	}

	return $total;
}

function dn_bfs_dash_format_chart_value( $value, $format = 'integer' ) {
	if ( 'money' === $format ) {
		return dn_bfs_dash_money( $value );
	}

	if ( 'percent' === $format ) {
		return esc_html( number_format_i18n( (float) $value, 1 ) . '%' );
	}

	return esc_html( number_format_i18n( (float) $value ) );
}

function dn_bfs_dash_chart_subtitle( $type ) {
	$subtitles = array(
		'sales'      => __( 'Net sales and order volume for the selected range.', 'dn-burst-funnel-stats' ),
		'funnel'     => __( 'Step-by-step movement from visitors through completed orders.', 'dn-burst-funnel-stats' ),
		'conversion' => __( 'Daily orders divided by visitors.', 'dn-burst-funnel-stats' ),
		'top-sales'  => __( 'Campaigns ranked by WooCommerce sales.', 'dn-burst-funnel-stats' ),
		'trend'      => __( 'Daily visitors and orders.', 'dn-burst-funnel-stats' ),
	);

	return isset( $subtitles[ $type ] ) ? $subtitles[ $type ] : '';
}

function dn_bfs_dash_chart_total( $type, $data ) {
	$first = isset( $data['series'][0]['values'] ) ? $data['series'][0]['values'] : ( isset( $data['values'] ) ? $data['values'] : array() );

	if ( in_array( $type, array( 'sales', 'top-sales' ), true ) ) {
		return dn_bfs_dash_money( dn_bfs_dash_chart_series_total( $first ) );
	}

	if ( 'conversion' === $type && ! empty( $first ) ) {
		return dn_bfs_dash_format_chart_value( array_sum( array_map( 'floatval', $first ) ) / count( $first ), 'percent' );
	}

	if ( 'funnel' === $type && ! empty( $data['values'] ) ) {
		$values = array_values( (array) $data['values'] );

		return dn_bfs_dash_format_chart_value( end( $values ), 'integer' );
	}

	return '';
}

function dn_bfs_dash_chart_total_label( $type ) {
	$labels = array(
		'sales'      => __( 'Total sales', 'dn-burst-funnel-stats' ),
		'funnel'     => __( 'Orders', 'dn-burst-funnel-stats' ),
		'conversion' => __( 'Avg. rate', 'dn-burst-funnel-stats' ),
		'top-sales'  => __( 'Campaign sales', 'dn-burst-funnel-stats' ),
	);

	return isset( $labels[ $type ] ) ? $labels[ $type ] : '';
}

function dn_bfs_dash_chart_has_values( $data ) {
	$values = array();

	if ( ! empty( $data['series'] ) ) {
		foreach ( (array) $data['series'] as $series ) {
			$values = array_merge( $values, isset( $series['values'] ) ? (array) $series['values'] : array() );
		}
	} elseif ( isset( $data['values'] ) ) {
		$values = (array) $data['values'];
	}

	foreach ( $values as $value ) {
		if ( (float) $value > 0 ) {
			return true;
		}
	}

	return false;
}

function dn_bfs_dash_render_chart_legend( $type, $data ) {
	if ( 'funnel' === $type ) {
		$labels = isset( $data['labels'] ) ? array_values( (array) $data['labels'] ) : array();
		$values = isset( $data['values'] ) ? array_values( (array) $data['values'] ) : array();
		$base   = isset( $values[0] ) ? max( 1, (float) $values[0] ) : 1;
		$colors = array( '#2271b1', '#00a32a', '#dba617', '#7f54b3', '#d63638' );
		?>
		<div class="dn-burst-funnel-legend" aria-label="<?php echo esc_attr__( 'Funnel step details', 'dn-burst-funnel-stats' ); ?>">
			<?php foreach ( $labels as $index => $label ) : ?>
				<?php
				$value      = isset( $values[ $index ] ) ? (float) $values[ $index ] : 0;
				$previous   = $index > 0 && isset( $values[ $index - 1 ] ) ? (float) $values[ $index - 1 ] : $base;
				$step_rate  = $previous > 0 ? ( $value / $previous ) * 100 : 0;
				$visit_rate = $base > 0 ? ( $value / $base ) * 100 : 0;
				?>
				<div class="dn-burst-funnel-legend-row">
					<span class="dn-burst-chart-legend-label">
						<span class="dn-burst-chart-legend-dot" style="background-color: <?php echo esc_attr( $colors[ $index % count( $colors ) ] ); ?>"></span>
						<span><?php echo esc_html( $label ); ?></span>
					</span>
					<strong><?php echo esc_html( number_format_i18n( $value ) ); ?></strong>
					<?php /* translators: 1: step conversion rate, 2: share of visitors. */ ?>
					<span><?php echo esc_html( sprintf( __( '%1$s step, %2$s of visitors', 'dn-burst-funnel-stats' ), number_format_i18n( $step_rate, 1 ) . '%', number_format_i18n( $visit_rate, 1 ) . '%' ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return;
	}
	?>
	<div class="dn-burst-chart-legend" aria-label="<?php echo esc_attr__( 'Chart legend', 'dn-burst-funnel-stats' ); ?>">
		<?php foreach ( (array) ( isset( $data['series'] ) ? $data['series'] : array() ) as $row ) : ?>
			<div class="dn-burst-chart-legend-row">
				<span class="dn-burst-chart-legend-label">
					<span class="dn-burst-chart-legend-dot" style="background-color: <?php echo esc_attr( isset( $row['color'] ) ? $row['color'] : '#2271b1' ); ?>"></span>
					<span><?php echo esc_html( isset( $row['label'] ) ? $row['label'] : '' ); ?></span>
				</span>
				<strong><?php echo wp_kses_post( dn_bfs_dash_format_chart_value( dn_bfs_dash_chart_series_total( isset( $row['values'] ) ? $row['values'] : array() ), isset( $row['format'] ) ? $row['format'] : 'integer' ) ); ?></strong>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

function dn_bfs_dash_render_chart_panel( $title, $type, $data ) {
	$data         = is_array( $data ) ? $data : array();
	$data['type'] = $type;
	$total        = dn_bfs_dash_chart_total( $type, $data );
	$total_label  = dn_bfs_dash_chart_total_label( $type );
	$subtitle     = dn_bfs_dash_chart_subtitle( $type );
	$has_values   = dn_bfs_dash_chart_has_values( $data );
	?>
	<div class="dn-burst-panel dn-burst-chart-panel <?php echo $has_values ? '' : 'is-empty'; ?>" data-dn-chart-panel>
		<div class="dn-burst-chart-header">
			<div>
				<h2 class="dn-burst-chart-title"><?php echo esc_html( $title ); ?></h2>
				<?php if ( '' !== $subtitle ) : ?>
					<p class="dn-burst-chart-subtitle"><?php echo esc_html( $subtitle ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( '' !== $total && '' !== $total_label ) : ?>
				<div class="dn-burst-chart-total">
					<span><?php echo esc_html( $total_label ); ?></span>
					<strong><?php echo wp_kses_post( $total ); ?></strong>
				</div>
			<?php endif; ?>
		</div>
		<div class="dn-burst-chart-body">
			<canvas class="dn-burst-chart" height="250" data-dn-chart="<?php echo esc_attr( $type ); ?>" data-chart="<?php echo esc_attr( wp_json_encode( $data ) ); ?>"></canvas>
			<p class="dn-burst-chart-empty" <?php echo $has_values ? 'hidden' : ''; ?>><?php esc_html_e( 'No chart data is available for this period yet.', 'dn-burst-funnel-stats' ); ?></p>
			<div class="dn-burst-chart-tooltip" role="status" aria-live="polite" hidden></div>
		</div>
		<?php dn_bfs_dash_render_chart_legend( $type, $data ); ?>
	</div>
	<?php
}

function dn_bfs_dash_render_card( $key, $card, $compare_label, $hidden ) {
	$main     = (string) $card['main'];
	$is_large = strlen( wp_strip_all_tags( $main ) ) <= 8;
	?>
	<div class="dn-burst-card<?php echo $hidden ? ' is-hidden' : ''; ?>" data-dn-card="<?php echo esc_attr( $key ); ?>" title="<?php echo esc_attr( $card['help'] ); ?>">
		<label class="dn-burst-card-toggle">
			<input type="checkbox" data-dn-card-visible <?php checked( ! $hidden ); ?> />
			<span class="screen-reader-text"><?php esc_html_e( 'Show this card', 'dn-burst-funnel-stats' ); ?></span>
		</label>
		<div class="dn-burst-card-icon"><?php echo esc_html( dn_bfs_dash_icon( $card['icon'] ) ); ?></div>
		<h3 class="dn-burst-card-title"><?php echo esc_html( $card['title'] ); ?></h3>
		<div class="dn-burst-main-line">
			<div class="dn-burst-main <?php echo $is_large ? 'is-large' : ''; ?>"><?php echo wp_kses_post( $main ); ?></div>
			<?php if ( '' !== $card['secondary'] ) : ?>
				<div class="dn-burst-secondary"><?php echo wp_kses_post( $card['secondary'] ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $card['change'] ) : ?>
				<span class="dn-burst-change <?php echo 0 === strpos( $card['change'], '-' ) ? 'is-down' : 'is-up'; ?>"><?php echo esc_html( $card['change'] ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( '' !== $card['compare'] ) : ?>
			<div class="dn-burst-compare"><strong><?php echo wp_kses_post( $card['compare'] ); ?></strong> <?php echo esc_html( $compare_label ); ?></div>
		<?php endif; ?>
	</div>
	<?php
}

function dn_bfs_dash_render_date_picker( $range, $tab ) {
	$presets = dn_bfs_get_date_presets();
	?>
	<div class="dn-burst-date-control" data-dn-date-control>
		<button type="button" class="button dn-burst-date-toggle" data-dn-date-toggle>
			<?php /* translators: 1: preset label, 2: formatted date range. */ ?>
			<span class="dn-burst-date-title"><?php echo esc_html( sprintf( __( '%1$s (%2$s)', 'dn-burst-funnel-stats' ), $range['current_label'], $range['current_range_label'] ) ); ?></span>
			<span class="dn-burst-date-compare" <?php echo 'none' === $range['compare'] ? 'hidden' : ''; ?>><?php echo esc_html( 'none' === $range['compare'] ? '' : $range['compare_label'] . ' (' . $range['previous_range_label'] . ')' ); ?></span>
		</button>
		<form method="get" class="dn-burst-date-popover" data-dn-date-popover hidden>
			<input type="hidden" name="page" value="dn-burst-funnel-stats" />
			<input type="hidden" name="dn_tab" value="<?php echo esc_attr( $tab ); ?>" />
			<h2><?php esc_html_e( 'Select a date range', 'dn-burst-funnel-stats' ); ?></h2>
			<div class="dn-burst-date-tabs">
				<button type="button" class="button <?php echo 'custom' !== $range['period'] ? 'is-active' : ''; ?>" data-dn-date-mode="presets"><?php esc_html_e( 'Presets', 'dn-burst-funnel-stats' ); ?></button>
				<button type="button" class="button <?php echo 'custom' === $range['period'] ? 'is-active' : ''; ?>" data-dn-date-mode="custom"><?php esc_html_e( 'Custom', 'dn-burst-funnel-stats' ); ?></button>
			</div>
			<div class="dn-burst-date-pane <?php echo 'custom' !== $range['period'] ? 'is-active' : ''; ?>" data-dn-date-pane="presets">
				<?php foreach ( $presets as $key => $label ) : ?>
					<?php if ( 'custom' === $key ) { continue; } ?>
					<label><input type="radio" name="dn_period" value="<?php echo esc_attr( $key ); ?>" <?php checked( $range['period'], $key ); ?> /> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</div>
			<div class="dn-burst-date-pane <?php echo 'custom' === $range['period'] ? 'is-active' : ''; ?>" data-dn-date-pane="custom">
				<label class="dn-burst-custom-radio"><input type="radio" name="dn_period" value="custom" <?php checked( $range['period'], 'custom' ); ?> /> <?php esc_html_e( 'Custom range', 'dn-burst-funnel-stats' ); ?></label>
				<label><?php esc_html_e( 'Start date', 'dn-burst-funnel-stats' ); ?> <input type="date" name="dn_start" value="<?php echo esc_attr( $range['custom_start'] ); ?>" /></label>
				<label><?php esc_html_e( 'End date', 'dn-burst-funnel-stats' ); ?> <input type="date" name="dn_end" value="<?php echo esc_attr( $range['custom_end'] ); ?>" /></label>
			</div>
			<fieldset class="dn-burst-compare-options">
				<legend><?php esc_html_e( 'Compare', 'dn-burst-funnel-stats' ); ?></legend>
				<label><input type="radio" name="dn_compare" value="previous_period" <?php checked( $range['compare'], 'previous_period' ); ?> /> <?php esc_html_e( 'Previous period', 'dn-burst-funnel-stats' ); ?></label>
				<label><input type="radio" name="dn_compare" value="previous_year" <?php checked( $range['compare'], 'previous_year' ); ?> /> <?php esc_html_e( 'Previous year', 'dn-burst-funnel-stats' ); ?></label>
				<label><input type="radio" name="dn_compare" value="none" <?php checked( $range['compare'], 'none' ); ?> /> <?php esc_html_e( 'None', 'dn-burst-funnel-stats' ); ?></label>
			</fieldset>
			<button type="submit" class="button button-primary" data-dn-date-update><?php esc_html_e( 'Update', 'dn-burst-funnel-stats' ); ?></button>
		</form>
	</div>
	<?php
}

function dn_bfs_dash_render_data_status() {
	$labels = dn_bfs_dash_status_labels();
	?>
	<div class="dn-burst-data-status" data-dn-status-panel>
		<div class="dn-burst-data-status-card">
			<div class="dn-burst-data-status-item">
				<span><?php esc_html_e( 'Aggregated through', 'dn-burst-funnel-stats' ); ?></span>
				<strong data-dn-last-update><?php echo esc_html( $labels['last'] ); ?></strong>
			</div>
			<div class="dn-burst-data-status-item">
				<span><?php esc_html_e( 'Next update', 'dn-burst-funnel-stats' ); ?></span>
				<strong data-dn-next-update><?php echo esc_html( $labels['next'] ); ?></strong>
			</div>
			<button type="button" class="button dn-burst-data-status-button" data-dn-update-now><?php esc_html_e( 'Update now', 'dn-burst-funnel-stats' ); ?></button>
		</div>
		<div class="dn-burst-status-message" data-dn-status-message aria-live="polite"></div>
	</div>
	<?php
}

function dn_bfs_dash_render_online_badge() {
	$realtime = dn_bfs_report_realtime();
	?>
	<div class="dn-burst-online-wrap">
		<button type="button" class="dn-burst-topbar-action dn-burst-online" data-dn-online aria-expanded="false">
			<span class="dn-burst-online-dot" aria-hidden="true"></span>
			<strong data-dn-online-count><?php echo esc_html( number_format_i18n( $realtime['online'] ) ); ?></strong>
			<span><?php esc_html_e( 'online now', 'dn-burst-funnel-stats' ); ?></span>
		</button>
		<div class="dn-burst-online-popover" data-dn-online-popover hidden></div>
	</div>
	<?php
}

function dn_bfs_dash_render_filter_bar( $filters ) {
	$labels = dn_bfs_dash_dimension_labels();
	?>
	<div class="dn-burst-filter-bar" data-dn-filter-bar>
		<span class="dn-burst-toolbar-label"><?php esc_html_e( 'Filters:', 'dn-burst-funnel-stats' ); ?></span>
		<span class="dn-burst-filter-chips" data-dn-filter-chips>
			<?php foreach ( $filters as $dimension => $value ) : ?>
				<span class="dn-burst-chip" data-dn-filter-dim="<?php echo esc_attr( $dimension ); ?>">
					<span><?php echo esc_html( $labels[ $dimension ] . ': ' . $value ); ?></span>
					<button type="button" class="dn-burst-chip-remove" data-dn-filter-remove aria-label="<?php esc_attr_e( 'Remove filter', 'dn-burst-funnel-stats' ); ?>">&times;</button>
				</span>
			<?php endforeach; ?>
		</span>
		<button type="button" class="button button-small" data-dn-filter-toggle><?php esc_html_e( 'Add filter', 'dn-burst-funnel-stats' ); ?></button>
		<form class="dn-burst-filter-popover" data-dn-filter-form hidden>
			<label>
				<span><?php esc_html_e( 'Dimension', 'dn-burst-funnel-stats' ); ?></span>
				<select name="dimension" data-dn-filter-dimension>
					<?php foreach ( dn_bfs_filter_dimensions() as $dimension ) : ?>
						<option value="<?php echo esc_attr( $dimension ); ?>"><?php echo esc_html( $labels[ $dimension ] ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Value', 'dn-burst-funnel-stats' ); ?></span>
				<input type="text" name="value" list="dn-filter-values" autocomplete="off" data-dn-filter-value />
			</label>
			<datalist id="dn-filter-values"></datalist>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'dn-burst-funnel-stats' ); ?></button>
		</form>
	</div>
	<?php
}

function dn_bfs_dash_overview_html( $range, $filters ) {
	$summary = dn_bfs_report_summary( $range, $filters );

	if ( is_wp_error( $summary ) ) {
		return dn_bfs_dash_notice( $summary->get_error_message() );
	}

	$cards         = dn_bfs_dashboard_cards( $summary );
	$visible       = dn_bfs_get_user_cards( get_current_user_id() );
	$order         = array_merge( $visible, array_values( array_diff( dn_bfs_dashboard_card_keys(), $visible ) ) );
	$compare_label = 'none' === $range['compare'] ? '' : $range['compare_label'];
	$charts        = dn_bfs_dashboard_charts( $range, $filters );

	ob_start();
	?>
	<div class="dn-burst-cards-toolbar">
		<button type="button" class="button" data-dn-cards-edit><?php esc_html_e( 'Customize cards', 'dn-burst-funnel-stats' ); ?></button>
		<button type="button" class="button button-primary" data-dn-cards-save hidden><?php esc_html_e( 'Save cards', 'dn-burst-funnel-stats' ); ?></button>
		<button type="button" class="button" data-dn-cards-cancel hidden><?php esc_html_e( 'Cancel', 'dn-burst-funnel-stats' ); ?></button>
		<button type="button" class="button-link" data-dn-cards-reset hidden><?php esc_html_e( 'Reset to default', 'dn-burst-funnel-stats' ); ?></button>
	</div>
	<?php
	if ( $summary['estimated'] ) {
		echo wp_kses_post( dn_bfs_dash_estimate_note() );
	}
	?>
	<div class="dn-burst-grid" data-dn-cards>
		<?php
		foreach ( $order as $key ) {
			dn_bfs_dash_render_card( $key, $cards[ $key ], $compare_label, ! in_array( $key, $visible, true ) );
		}
		?>
	</div>
	<?php if ( is_wp_error( $charts ) ) : ?>
		<?php echo wp_kses_post( dn_bfs_dash_notice( $charts->get_error_message() ) ); ?>
	<?php else : ?>
		<div class="dn-burst-chart-grid">
			<?php dn_bfs_dash_render_chart_panel( __( 'Sales / Orders', 'dn-burst-funnel-stats' ), 'sales', $charts['sales'] ); ?>
			<?php dn_bfs_dash_render_chart_panel( __( 'Funnel', 'dn-burst-funnel-stats' ), 'funnel', $charts['funnel'] ); ?>
			<?php dn_bfs_dash_render_chart_panel( __( 'Conversion Rate', 'dn-burst-funnel-stats' ), 'conversion', $charts['conversion'] ); ?>
			<?php dn_bfs_dash_render_chart_panel( __( 'Top Campaigns by Sales', 'dn-burst-funnel-stats' ), 'top-sales', $charts['top'] ); ?>
		</div>
	<?php endif; ?>
	<?php
	return ob_get_clean();
}

function dn_bfs_dash_brand_breakdown( $range, $filters, $orderby, $order, $limit, $offset ) {
	$products = dn_bfs_report_breakdown( $range, 'product', $filters, 'revenue', 'desc', 500, 0 );

	if ( is_wp_error( $products ) ) {
		return $products;
	}

	$taxonomies = array_values( array_filter( array( 'product_brand', 'pa_brand' ), 'taxonomy_exists' ) );
	$brands     = array();

	foreach ( $products['rows'] as $row ) {
		$name  = __( 'Unassigned', 'dn-burst-funnel-stats' );
		$terms = $taxonomies && (int) $row['dim_value'] > 0 ? wp_get_post_terms( (int) $row['dim_value'], $taxonomies, array( 'fields' => 'names' ) ) : array();

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$name = (string) reset( $terms );
		}

		$brands[ $name ] = isset( $brands[ $name ] ) ? dn_bfs_add_metrics( $brands[ $name ], dn_bfs_normalize_metrics( $row ) ) : dn_bfs_normalize_metrics( $row );
	}

	$rows = array();

	foreach ( $brands as $name => $metrics ) {
		$rows[] = array_merge(
			array(
				'dim_value' => (string) $name,
				'label'     => (string) $name,
			),
			dn_bfs_derive_metrics( $metrics )
		);
	}

	$rows = dn_bfs_sort_report_rows( $rows, '' !== $orderby ? $orderby : 'revenue', $order );

	return array(
		'rows'      => array_slice( $rows, $offset, $limit ),
		'total'     => count( $rows ),
		'estimated' => $products['estimated'],
	);
}

function dn_bfs_dash_table_html( $tab, $dimension, $range, $filters, $args = array() ) {
	$columns  = dn_bfs_dash_columns( $dimension );
	$orderby  = isset( $args['orderby'] ) && in_array( $args['orderby'], $columns, true ) ? $args['orderby'] : $columns[0];
	$order    = isset( $args['order'] ) && 'asc' === $args['order'] ? 'asc' : 'desc';
	$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
	$per_page = 25;
	$offset   = ( $page - 1 ) * $per_page;
	$result   = 'brand' === $dimension
		? dn_bfs_dash_brand_breakdown( $range, $filters, $orderby, $order, $per_page, $offset )
		: dn_bfs_report_breakdown( $range, $dimension, $filters, $orderby, $order, $per_page, $offset );

	if ( is_wp_error( $result ) ) {
		return dn_bfs_dash_notice( $result->get_error_message() );
	}

	$pages      = max( 1, (int) ceil( $result['total'] / $per_page ) );
	$drillable  = in_array( $dimension, dn_bfs_filter_dimensions(), true );
	$labels     = dn_bfs_dash_metric_labels();
	$dim_labels = dn_bfs_dash_dimension_labels();

	ob_start();
	?>
	<div class="dn-burst-table-wrap" data-dn-table data-tab="<?php echo esc_attr( $tab ); ?>" data-dimension="<?php echo esc_attr( $dimension ); ?>" data-orderby="<?php echo esc_attr( $orderby ); ?>" data-order="<?php echo esc_attr( $order ); ?>" data-page="<?php echo esc_attr( $page ); ?>">
		<?php
		if ( ! empty( $result['estimated'] ) ) {
			echo wp_kses_post( dn_bfs_dash_estimate_note() );
		}
		?>
		<table class="widefat striped dn-burst-data-table">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html( $dim_labels[ $dimension ] ); ?></th>
					<?php foreach ( $columns as $column ) : ?>
						<?php $next = ( $column === $orderby && 'desc' === $order ) ? 'asc' : 'desc'; ?>
						<th scope="col" class="<?php echo $column === $orderby ? esc_attr( 'is-sorted is-' . $order ) : ''; ?>">
							<a href="#" data-dn-sort="<?php echo esc_attr( $column ); ?>" data-dn-order="<?php echo esc_attr( $next ); ?>"><?php echo esc_html( $labels[ $column ] ); ?></a>
						</th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $result['rows'] ) ) : ?>
					<tr><td colspan="<?php echo esc_attr( count( $columns ) + 1 ); ?>"><?php esc_html_e( 'No data is available for this period.', 'dn-burst-funnel-stats' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $result['rows'] as $row ) : ?>
						<?php
						$value     = (string) $row['dim_value'];
						$can_drill = $drillable && '' !== $value;
						?>
						<tr<?php if ( $can_drill ) : ?> class="is-drillable" data-dn-drill-dimension="<?php echo esc_attr( $dimension ); ?>" data-dn-drill-value="<?php echo esc_attr( $value ); ?>" tabindex="0"<?php endif; ?>>
							<td><?php echo esc_html( dn_bfs_dash_value_label( $dimension, $row ) ); ?></td>
							<?php foreach ( $columns as $column ) : ?>
								<td><?php echo wp_kses_post( dn_bfs_dash_format_cell( $column, isset( $row[ $column ] ) ? $row[ $column ] : 0 ) ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php /* translators: %d: number of rows. */ ?>
					<span class="displaying-num"><?php echo esc_html( sprintf( _n( '%d item', '%d items', $result['total'], 'dn-burst-funnel-stats' ), $result['total'] ) ); ?></span>
					<?php if ( $page > 1 ) : ?>
						<a class="button" href="#" data-dn-page="<?php echo esc_attr( $page - 1 ); ?>"><?php esc_html_e( 'Previous', 'dn-burst-funnel-stats' ); ?></a>
					<?php endif; ?>
					<span class="paging-input"><?php echo esc_html( $page . ' / ' . $pages ); ?></span>
					<?php if ( $page < $pages ) : ?>
						<a class="button" href="#" data-dn-page="<?php echo esc_attr( $page + 1 ); ?>"><?php esc_html_e( 'Next', 'dn-burst-funnel-stats' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

function dn_bfs_dash_breakdown_html( $tab, $range, $filters, $args ) {
	$tabs       = dn_bfs_dash_tabs();
	$dimensions = $tabs[ $tab ]['dimensions'];
	$dimension  = isset( $args['dimension'] ) && in_array( $args['dimension'], $dimensions, true ) ? $args['dimension'] : $dimensions[0];
	$labels     = dn_bfs_dash_dimension_labels();

	ob_start();
	?>
	<div class="dn-burst-breakdown">
		<div class="dn-burst-panel dn-burst-panel-toolbar">
			<div><h2><?php echo esc_html( $tabs[ $tab ]['label'] ); ?></h2></div>
			<?php if ( count( $dimensions ) > 1 ) : ?>
				<fieldset class="dn-burst-radio-group" data-dn-dimension>
					<legend class="screen-reader-text"><?php esc_html_e( 'Group by', 'dn-burst-funnel-stats' ); ?></legend>
					<?php foreach ( $dimensions as $option ) : ?>
						<label><input type="radio" name="dn_dimension" value="<?php echo esc_attr( $option ); ?>" <?php checked( $option, $dimension ); ?> /> <?php echo esc_html( $labels[ $option ] ); ?></label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
		</div>
		<div data-dn-table-region>
			<?php echo dn_bfs_dash_table_html( $tab, $dimension, $range, $filters, $args ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

function dn_bfs_dash_drilldown_html( $range, $filters, $dimension, $value ) {
	$value = trim( (string) $value );

	if ( ! in_array( $dimension, dn_bfs_filter_dimensions(), true ) || '' === $value ) {
		return dn_bfs_dash_notice( __( 'This row cannot be explored.', 'dn-burst-funnel-stats' ) );
	}

	$filters[ $dimension ] = $value;
	$filters               = dn_bfs_sanitize_filters( $filters );
	$summary               = dn_bfs_report_summary( $range, $filters );
	$series                = is_wp_error( $summary ) ? $summary : dn_bfs_report_timeseries( $range, array( 'visitors', 'orders' ), $filters );
	$funnel                = is_wp_error( $series ) ? $series : dn_bfs_report_funnel( $range, $filters );

	if ( is_wp_error( $funnel ) ) {
		return dn_bfs_dash_notice( $funnel->get_error_message() );
	}

	$labels   = dn_bfs_dash_dimension_labels();
	$channels = dn_bfs_dash_channel_labels();
	$display  = 'channel' === $dimension && isset( $channels[ $value ] ) ? $channels[ $value ] : $value;
	$cur      = $summary['current'];
	$stats    = array(
		'visitors'        => array( __( 'Visitors', 'dn-burst-funnel-stats' ), esc_html( dn_bfs_dash_number( $cur['visitors'] ) ) ),
		'sessions'        => array( __( 'Sessions', 'dn-burst-funnel-stats' ), esc_html( dn_bfs_dash_number( $cur['sessions'] ) ) ),
		'orders'          => array( __( 'Orders', 'dn-burst-funnel-stats' ), esc_html( dn_bfs_dash_number( $cur['orders'] ) ) ),
		'revenue'         => array( __( 'Sales', 'dn-burst-funnel-stats' ), dn_bfs_dash_money( $cur['revenue'] ) ),
		'conversion_rate' => array( __( 'Conversion', 'dn-burst-funnel-stats' ), esc_html( dn_bfs_dash_percent( $cur['conversion_rate'], 2 ) ) ),
	);
	$trend    = array(
		'labels' => array_map( 'dn_bfs_dash_date_label', $series['labels'] ),
		'format' => 'integer',
		'series' => array(
			array(
				'label'  => __( 'Visitors', 'dn-burst-funnel-stats' ),
				'values' => $series['series']['visitors'],
				'color'  => '#2271b1',
				'format' => 'integer',
				'axis'   => 'left',
			),
			array(
				'label'  => __( 'Orders', 'dn-burst-funnel-stats' ),
				'values' => $series['series']['orders'],
				'color'  => '#7f54b3',
				'format' => 'integer',
				'axis'   => 'right',
			),
		),
	);

	ob_start();
	?>
	<div class="dn-burst-drawer-header">
		<h2><?php echo esc_html( $labels[ $dimension ] . ': ' . $display ); ?></h2>
		<button type="button" class="button-link dn-burst-drawer-close" data-dn-drawer-close aria-label="<?php esc_attr_e( 'Close', 'dn-burst-funnel-stats' ); ?>">&times;</button>
	</div>
	<p class="dn-burst-drawer-range"><?php echo esc_html( $range['current_label'] . ' (' . $range['current_range_label'] . ')' ); ?></p>
	<?php
	if ( $summary['estimated'] ) {
		echo wp_kses_post( dn_bfs_dash_estimate_note() );
	}
	?>
	<div class="dn-burst-drawer-stats">
		<?php foreach ( $stats as $metric => $stat ) : ?>
			<div class="dn-burst-drawer-stat">
				<span><?php echo esc_html( $stat[0] ); ?></span>
				<strong><?php echo wp_kses_post( $stat[1] ); ?></strong>
				<?php $change = dn_bfs_dash_change( $summary, $metric ); ?>
				<?php if ( '' !== $change ) : ?>
					<em class="<?php echo 0 === strpos( $change, '-' ) ? 'is-down' : 'is-up'; ?>"><?php echo esc_html( $change ); ?></em>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php dn_bfs_dash_render_chart_panel( __( 'Visitors / Orders', 'dn-burst-funnel-stats' ), 'trend', $trend ); ?>
	<?php dn_bfs_dash_render_chart_panel( __( 'Funnel', 'dn-burst-funnel-stats' ), 'funnel', dn_bfs_dashboard_funnel_payload( $funnel ) ); ?>
	<p>
		<button type="button" class="button button-primary" data-dn-apply-filter data-dimension="<?php echo esc_attr( $dimension ); ?>" data-value="<?php echo esc_attr( $value ); ?>"><?php esc_html_e( 'Apply as filter', 'dn-burst-funnel-stats' ); ?></button>
	</p>
	<?php
	return ob_get_clean();
}

function dn_bfs_dash_tab_html( $tab, $range, $filters, $args = array() ) {
	$tab = dn_bfs_dash_sanitize_tab( $tab );

	return 'overview' === $tab ? dn_bfs_dash_overview_html( $range, $filters ) : dn_bfs_dash_breakdown_html( $tab, $range, $filters, $args );
}

function dn_bfs_dash_render_page() {
	if ( ! dn_bfs_admin_permission() ) {
		return;
	}

	$params  = dn_bfs_params_from_query( wp_unslash( $_GET ) );
	$tab     = dn_bfs_dash_sanitize_tab( $params['tab'] );
	$request = dn_bfs_dash_request( $params );
	$notice  = '';

	if ( is_wp_error( $request ) ) {
		$notice  = $request->get_error_message();
		$request = dn_bfs_dash_request( array() );
	}

	list( $range, $filters ) = $request;
	$tabs                    = dn_bfs_dash_tabs();
	?>
	<div class="wrap dn-burst-wrap dn-burst-funnel-stats" data-dn-dashboard>
		<header class="dn-burst-topbar">
			<h1 class="dn-burst-topbar-title"><?php echo esc_html( $tabs[ $tab ]['label'] ); ?></h1>
			<div class="dn-burst-topbar-actions"><?php dn_bfs_dash_render_online_badge(); ?></div>
		</header>
		<?php
		if ( '' !== $notice ) {
			echo wp_kses_post( dn_bfs_dash_notice( $notice ) );
		}
		?>
		<div class="dn-burst-dashboard-toolbar">
			<div class="dn-burst-dashboard-toolbar-section dn-burst-date-range-control">
				<div class="dn-burst-toolbar-label"><?php esc_html_e( 'Date range:', 'dn-burst-funnel-stats' ); ?></div>
				<?php dn_bfs_dash_render_date_picker( $range, $tab ); ?>
			</div>
			<div class="dn-burst-dashboard-toolbar-section dn-burst-data-status-control">
				<div class="dn-burst-toolbar-label"><?php esc_html_e( 'Data status:', 'dn-burst-funnel-stats' ); ?></div>
				<?php dn_bfs_dash_render_data_status(); ?>
			</div>
		</div>
		<?php dn_bfs_dash_render_filter_bar( $filters ); ?>
		<div class="dn-burst-dashboard-content">
			<nav class="nav-tab-wrapper dn-burst-tabs" aria-label="<?php echo esc_attr__( 'Dashboard tabs', 'dn-burst-funnel-stats' ); ?>">
				<?php foreach ( $tabs as $key => $definition ) : ?>
					<a href="<?php echo esc_url( dn_bfs_dash_url( $key, $range, $filters ) ); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>" data-dn-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $definition['label'] ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="dn-burst-tab-content" data-dn-tab-content aria-live="polite">
				<?php echo dn_bfs_dash_tab_html( $tab, $range, $filters ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
			</div>
		</div>
	</div>
	<?php
}
```

- [ ] **Step 3: Tạo `includes/admin/ajax.php`**

```php
<?php
/**
 * Admin AJAX handlers for the dashboard.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_ajax_guard() {
	nocache_headers();

	if ( ! dn_bfs_admin_permission() ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to view these stats.', 'dn-burst-funnel-stats' ) ), 403 );
	}

	if ( ! check_ajax_referer( 'dn_bfs_admin', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed. Refresh the page and try again.', 'dn-burst-funnel-stats' ) ), 403 );
	}
}

function dn_bfs_ajax_respond( $payload ) {
	if ( is_wp_error( $payload ) ) {
		$data = $payload->get_error_data();

		wp_send_json_error(
			array(
				'message' => $payload->get_error_message(),
				'code'    => $payload->get_error_code(),
			),
			is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400
		);
	}

	wp_send_json_success( $payload );
}

function dn_bfs_ajax_params() {
	return wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in dn_bfs_ajax_guard().
}

function dn_bfs_ajax_range_meta( $range ) {
	return array(
		'current_label'        => $range['current_label'],
		'current_range_label'  => $range['current_range_label'],
		'compare'              => $range['compare'],
		'compare_label'        => $range['compare_label'],
		'previous_range_label' => $range['previous_range_label'],
	);
}

function dn_bfs_ajax_tab_payload( $params ) {
	$request = dn_bfs_dash_request( $params );

	if ( is_wp_error( $request ) ) {
		return $request;
	}

	list( $range, $filters ) = $request;
	$tab                     = dn_bfs_dash_sanitize_tab( isset( $params['tab'] ) ? $params['tab'] : 'overview' );
	$tabs                    = dn_bfs_dash_tabs();

	return array(
		'html'  => dn_bfs_dash_tab_html( $tab, $range, $filters, $params ),
		'tab'   => $tab,
		'title' => $tabs[ $tab ]['label'],
		'range' => dn_bfs_ajax_range_meta( $range ),
	);
}

function dn_bfs_ajax_table_payload( $params ) {
	$tab = dn_bfs_dash_sanitize_tab( isset( $params['tab'] ) ? $params['tab'] : '' );

	if ( 'overview' === $tab ) {
		return dn_bfs_request_error( 'invalid_tab', __( 'This tab has no table.', 'dn-burst-funnel-stats' ) );
	}

	$request = dn_bfs_dash_request( $params );

	if ( is_wp_error( $request ) ) {
		return $request;
	}

	list( $range, $filters ) = $request;
	$tabs                    = dn_bfs_dash_tabs();
	$dimensions              = $tabs[ $tab ]['dimensions'];
	$dimension               = isset( $params['dimension'] ) && in_array( $params['dimension'], $dimensions, true ) ? $params['dimension'] : $dimensions[0];

	return array( 'html' => dn_bfs_dash_table_html( $tab, $dimension, $range, $filters, $params ) );
}

function dn_bfs_ajax_drilldown_payload( $params ) {
	$request = dn_bfs_dash_request( $params );

	if ( is_wp_error( $request ) ) {
		return $request;
	}

	list( $range, $filters ) = $request;

	return array(
		'html' => dn_bfs_dash_drilldown_html(
			$range,
			$filters,
			isset( $params['dimension'] ) ? (string) $params['dimension'] : '',
			isset( $params['value'] ) && is_scalar( $params['value'] ) ? (string) $params['value'] : ''
		),
	);
}

function dn_bfs_ajax_realtime_payload() {
	return dn_bfs_report_realtime();
}

function dn_bfs_ajax_save_cards_payload( $user_id, $params ) {
	if ( ! empty( $params['reset'] ) ) {
		$cards = dn_bfs_save_user_cards( $user_id, null );
	} else {
		$cards = dn_bfs_save_user_cards( $user_id, isset( $params['cards'] ) && is_array( $params['cards'] ) ? array_values( $params['cards'] ) : array() );
	}

	return is_wp_error( $cards ) ? $cards : array( 'cards' => $cards );
}

function dn_bfs_ajax_filter_values_payload( $params ) {
	global $wpdb;

	$dimension = isset( $params['dimension'] ) ? (string) $params['dimension'] : '';

	if ( ! in_array( $dimension, dn_bfs_filter_dimensions(), true ) ) {
		return dn_bfs_request_error( 'invalid_dimension', __( 'Suggestions are only available for filter dimensions.', 'dn-burst-funnel-stats' ) );
	}

	$search = dn_bfs_truncate( sanitize_text_field( isset( $params['search'] ) && is_scalar( $params['search'] ) ? (string) $params['search'] : '' ), 100 );
	$now    = dn_bfs_now();
	$totals = array();

	$daily = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT dim_value, SUM(sessions) AS sessions FROM ' . dn_bfs_table( 'daily' ) . '
			WHERE dimension = %s AND date >= %s AND dim_value <> %s AND dim_value LIKE %s
			GROUP BY dim_hash, dim_value',
			$dimension,
			dn_bfs_date_shift( wp_date( 'Y-m-d', $now ), -90 ),
			'',
			'%' . $wpdb->esc_like( $search ) . '%'
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

	return array( 'values' => array_slice( array_map( 'strval', array_keys( $totals ) ), 0, 20 ) );
}

function dn_bfs_ajax_update_now_payload() {
	$result = dn_bfs_aggregate_run();
	$labels = dn_bfs_dash_status_labels();
	$ok     = ! empty( $result['ok'] );

	if ( $ok ) {
		$message = __( 'Data refreshed.', 'dn-burst-funnel-stats' );
	} elseif ( isset( $result['reason'] ) && 'locked' === $result['reason'] ) {
		$message = __( 'An update is already running. Try again in a minute.', 'dn-burst-funnel-stats' );
	} else {
		$message = __( 'The update failed. See Settings → System.', 'dn-burst-funnel-stats' );
	}

	return array(
		'ok'         => $ok,
		'message'    => $message,
		'lastUpdate' => $labels['last'],
		'nextUpdate' => $labels['next'],
	);
}

function dn_bfs_ajax_load_tab() {
	dn_bfs_ajax_guard();
	dn_bfs_ajax_respond( dn_bfs_ajax_tab_payload( dn_bfs_ajax_params() ) );
}
add_action( 'wp_ajax_dn_bfs_load_tab', 'dn_bfs_ajax_load_tab' );

function dn_bfs_ajax_table() {
	dn_bfs_ajax_guard();
	dn_bfs_ajax_respond( dn_bfs_ajax_table_payload( dn_bfs_ajax_params() ) );
}
add_action( 'wp_ajax_dn_bfs_table', 'dn_bfs_ajax_table' );

function dn_bfs_ajax_drilldown() {
	dn_bfs_ajax_guard();
	dn_bfs_ajax_respond( dn_bfs_ajax_drilldown_payload( dn_bfs_ajax_params() ) );
}
add_action( 'wp_ajax_dn_bfs_drilldown', 'dn_bfs_ajax_drilldown' );

function dn_bfs_ajax_realtime() {
	dn_bfs_ajax_guard();
	dn_bfs_ajax_respond( dn_bfs_ajax_realtime_payload() );
}
add_action( 'wp_ajax_dn_bfs_realtime', 'dn_bfs_ajax_realtime' );

function dn_bfs_ajax_save_cards() {
	dn_bfs_ajax_guard();
	dn_bfs_ajax_respond( dn_bfs_ajax_save_cards_payload( get_current_user_id(), dn_bfs_ajax_params() ) );
}
add_action( 'wp_ajax_dn_bfs_save_cards', 'dn_bfs_ajax_save_cards' );

function dn_bfs_ajax_filter_values() {
	dn_bfs_ajax_guard();
	dn_bfs_ajax_respond( dn_bfs_ajax_filter_values_payload( dn_bfs_ajax_params() ) );
}
add_action( 'wp_ajax_dn_bfs_filter_values', 'dn_bfs_ajax_filter_values' );

function dn_bfs_ajax_update_now() {
	dn_bfs_ajax_guard();
	dn_bfs_ajax_respond( dn_bfs_ajax_update_now_payload() );
}
add_action( 'wp_ajax_dn_bfs_update_now', 'dn_bfs_ajax_update_now' );
```

- [ ] **Step 4: Nạp module** — thêm `'dashboard-page', 'ajax'` vào mảng `dn_burst_funnel_stats_load_admin()`. Lưu ý: file cũ `includes/dashboard.php` vẫn còn được nạp tới Task 7 — tên hàm mới (`dn_bfs_dash_*`, `dn_bfs_ajax_*`) không trùng tên cũ (`dn_burst_dash_*`, `dn_burst_funnel_stats_ajax_*`).

- [ ] **Step 5: Chạy test** — tích hợp PASS (thêm 8 test); unit + `php74` → OK.

- [ ] **Step 6: Commit**

```bash
git add includes/admin/dashboard-page.php includes/admin/ajax.php tests/integration/test-dashboard-page.php dn-burst-funnel-stats.php
git commit -m "feat(admin): render the upgraded dashboard and its AJAX payloads in PHP

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Trang Settings 7 tab + công cụ dữ liệu

**Files:**
- Create: `includes/admin/data-tools.php`, `includes/admin/settings-page.php`, `tests/integration/test-settings-page.php`
- Modify: `dn-burst-funnel-stats.php` (thêm `'data-tools', 'settings-page'`)

**Interfaces:**
- Consumes: Task 1–3; `dn_bfs_schema_tables()`, `dn_bfs_mark_dirty_date()`, `dn_bfs_aggregate_run()`, `dn_bfs_last_closable_date()`, `dn_bfs_get_dirty_dates()`, `dn_bfs_geoip_update()`, `dn_bfs_tracking_int_ranges()`, `dn_bfs_get_client_ip()`.
- Produces:
  - Data tools: `dn_bfs_data_stats(): array` (`tables` → `rows`, `bytes`; `last_aggregated`, `raw_available_from`, `retention_days`, `dirty_dates`, `last_error`), `dn_bfs_reaggregate_range( $start, $end ): array|WP_Error` (≤ 92 ngày; `queued`, `result`), `dn_bfs_purge_all_data( $confirm ): true|WP_Error`, `dn_bfs_export_settings(): array`, `dn_bfs_import_settings( $payload ): array|WP_Error`, `dn_bfs_geoip_update_now(): array|WP_Error`, `dn_bfs_blocked_stats( $days = 7, $now = null ): array` (`reason => count`).
  - Settings page: `dn_bfs_settings_tabs()`, `dn_bfs_settings_current_tab()`, `dn_bfs_settings_fields(): array` (`group => field[]`; field: `key, type, label, description?, text?, options?, unit?, min?, max?, post_type?`; type ∈ `checkbox, select, radio, number, lines, password, checklist, statuses, posts`), `dn_bfs_settings_input_from_post( $group, $post ): array`, `dn_bfs_settings_save_from_post( $group, $post ): string` (mã notice), `dn_bfs_settings_data_task( $task, $post, $files ): array( 'tab', 'notice' )`, `dn_bfs_settings_notice( $code ): array( type, message )`, `dn_bfs_settings_url( $tab, $args = array() )`, `dn_bfs_render_post_multiselect( $name, $posts, $selected_ids )`, `dn_bfs_render_setting_field( $field, $value, $meta )`, `dn_bfs_render_settings_page()` (callback menu — nối ở Task 7).
  - admin-post: `dn_bfs_save_settings` (form theo nhóm, nonce `dn_bfs_save_settings_<group>`), `dn_bfs_data_task` (nonce `dn_bfs_data_<task>`; task `reaggregate|purge|import|geoip_update`), `dn_bfs_export` (nonce `dn_bfs_data_export`, tải JSON).

- [ ] **Step 1: Viết test fail — `tests/integration/test-settings-page.php`**

```php
<?php

require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/admin-helpers.php';

dn_bfs_it(
	'every settings group has field definitions matching the model keys',
	function () {
		$fields = dn_bfs_settings_fields();

		foreach ( dn_bfs_settings_groups() as $group => $keys ) {
			dn_bfs_assert_same( $keys, array_column( $fields[ $group ], 'key' ), $group );
		}

		dn_bfs_assert_same( array( 'general', 'tracking', 'antispam', 'woocommerce', 'geoip', 'data', 'system' ), array_keys( dn_bfs_settings_tabs() ) );
	}
);

dn_bfs_it(
	'form input fills unchecked checkboxes and empty lists',
	function () {
		$input = dn_bfs_settings_input_from_post( 'antispam', array( 'dn_bfs' => array( 'dedupe_window' => '600', 'custom_bot_user_agents' => "spider\nfoo" ) ) );

		dn_bfs_assert_same( 600, $input['dedupe_window'] );
		dn_bfs_assert_same( 0, $input['exclude_bots'] );
		dn_bfs_assert_same( 0, $input['block_empty_ua'] );
		dn_bfs_assert_same( "spider\nfoo", $input['custom_bot_user_agents'] );

		$tracking = dn_bfs_settings_input_from_post( 'tracking', array( 'dn_bfs' => array() ) );
		dn_bfs_assert_same( array(), $tracking['excluded_roles'] );
		dn_bfs_assert_same( array(), $tracking['selected_page_ids'] );
	}
);

dn_bfs_it(
	'saving from a posted form stores the whole group',
	function () {
		dn_bfs_assert_same( 'saved', dn_bfs_settings_save_from_post( 'antispam', array( 'dn_bfs' => array( 'dedupe_window' => '600', 'exclude_bots' => '1' ) ) ) );

		$settings = dn_bfs_get_tracking_settings();
		dn_bfs_assert_same( 600, $settings['dedupe_window'] );
		dn_bfs_assert_same( 1, $settings['exclude_bots'] );
		dn_bfs_assert_same( 0, $settings['block_empty_ua'] );
		dn_bfs_assert_same( 'invalid_group', dn_bfs_settings_save_from_post( 'nope', array() ) );
	}
);

dn_bfs_it_today(
	'data tools re-aggregate, purge with confirmation and round-trip settings',
	function () {
		dn_bfs_it_seed_session( array( 'started_at' => dn_bfs_it_day_noon( 2 ) ) );
		$day = wp_date( 'Y-m-d', dn_bfs_it_day_noon( 2 ) );

		$result = dn_bfs_reaggregate_range( $day, $day );
		dn_bfs_assert_same( 1, $result['queued'] );
		dn_bfs_assert_same( 'range_too_long', dn_bfs_reaggregate_range( '2025-01-01', '2025-12-31' )->get_error_code() );
		dn_bfs_assert_same( 'invalid_date', dn_bfs_reaggregate_range( 'x', $day )->get_error_code() );

		dn_bfs_assert_same( 'confirm_required', dn_bfs_purge_all_data( 'yes' )->get_error_code() );
		dn_bfs_assert_same( true, dn_bfs_purge_all_data( 'DELETE' ) );
		dn_bfs_assert_same( 0, dn_bfs_it_count( 'sessions' ) );

		dn_bfs_it_settings( array( 'maxmind_license_key' => 'SECRET_KEY', 'session_timeout' => 45 ) );
		$export = dn_bfs_export_settings();
		dn_bfs_assert_true( ! isset( $export['settings']['tracking']['maxmind_license_key'] ), 'no license' );

		dn_bfs_it_settings( array( 'maxmind_license_key' => 'SECRET_KEY', 'session_timeout' => 30 ) );
		dn_bfs_assert_same( array( 'tracking', 'woocommerce_report' ), dn_bfs_import_settings( $export )['imported'] );
		dn_bfs_assert_same( 45, dn_bfs_get_tracking_settings()['session_timeout'] );
		dn_bfs_assert_same( 'SECRET_KEY', dn_bfs_get_tracking_settings()['maxmind_license_key'] );
		dn_bfs_assert_same( 'invalid_import', dn_bfs_import_settings( array( 'meta' => array( 'plugin' => 'x' ) ) )->get_error_code() );

		dn_bfs_assert_true( isset( dn_bfs_data_stats()['tables']['sessions'] ), 'stats' );
	}
);

dn_bfs_it_today(
	'data tasks map to notices and blocked stats sum reasons',
	function () {
		dn_bfs_assert_same( array( 'tab' => 'data', 'notice' => 'confirm_required' ), dn_bfs_settings_data_task( 'purge', array( 'confirm' => 'no' ), array() ) );
		dn_bfs_assert_same( array( 'tab' => 'data', 'notice' => 'missing_file' ), dn_bfs_settings_data_task( 'import', array(), array() ) );
		dn_bfs_assert_same( array( 'tab' => 'geoip', 'notice' => 'no_license' ), dn_bfs_settings_data_task( 'geoip_update', array(), array() ) );

		dn_bfs_store_count_blocked( 'bot', dn_bfs_it_now() );
		dn_bfs_store_count_blocked( 'bot', dn_bfs_it_now() );
		dn_bfs_store_count_blocked( 'bad_origin', dn_bfs_it_now() );
		dn_bfs_assert_same( array( 'bot' => 2, 'bad_origin' => 1 ), dn_bfs_blocked_stats( 7 ) );

		dn_bfs_assert_same( 'success', dn_bfs_settings_notice( 'saved' )[0] );
		dn_bfs_assert_same( 'error', dn_bfs_settings_notice( 'geoip_checksum_mismatch' )[0] );
	}
);

dn_bfs_it(
	'settings page renders each tab',
	function () {
		dn_bfs_it_login_admin();

		foreach ( array( 'general', 'tracking', 'antispam', 'woocommerce', 'geoip', 'data', 'system' ) as $tab ) {
			$_GET = array( 'page' => 'dn-burst-funnel-stats-settings', 'tab' => $tab );

			add_filter( 'pre_http_request', '__return_empty_array' );
			ob_start();
			dn_bfs_render_settings_page();
			$html = ob_get_clean();
			remove_filter( 'pre_http_request', '__return_empty_array' );

			dn_bfs_assert_true( (bool) preg_match( '/nav-tab nav-tab-active"[^>]*>/', $html ), $tab . ' active tab' );

			if ( isset( dn_bfs_settings_groups()[ $tab ] ) ) {
				foreach ( dn_bfs_settings_groups()[ $tab ] as $key ) {
					dn_bfs_assert_true( false !== strpos( $html, 'dn_bfs[' . $key . ']' ), $tab . ': ' . $key );
				}
			}
		}

		dn_bfs_assert_true( false !== strpos( $html, 'dn-burst-status-badge' ), 'system checks' );
		$_GET = array();
	}
);
```

Run tích hợp → Expected: test mới FAIL.

- [ ] **Step 2: Tạo `includes/admin/data-tools.php`**

```php
<?php
/**
 * Data tools behind Settings → Data and GeoIP.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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

function dn_bfs_reaggregate_range( $start, $end ) {
	if ( ! dn_bfs_valid_date_string( $start ) || ! dn_bfs_valid_date_string( $end ) || $start > $end ) {
		return dn_bfs_request_error( 'invalid_date', __( 'Use valid start and end dates in YYYY-MM-DD format.', 'dn-burst-funnel-stats' ) );
	}

	$dates = dn_bfs_dates_between( $start, $end );

	if ( count( $dates ) > 92 ) {
		return dn_bfs_request_error( 'range_too_long', __( 'Re-aggregate at most 92 days at a time.', 'dn-burst-funnel-stats' ) );
	}

	$closable = dn_bfs_last_closable_date( dn_bfs_now() );
	$queued   = 0;

	foreach ( $dates as $date ) {
		if ( $date <= $closable ) {
			dn_bfs_mark_dirty_date( $date );
			$queued++;
		}
	}

	return array(
		'queued' => $queued,
		'result' => dn_bfs_aggregate_run(),
	);
}

function dn_bfs_purge_all_data( $confirm ) {
	global $wpdb;

	if ( 'DELETE' !== $confirm ) {
		return dn_bfs_request_error( 'confirm_required', __( 'Type DELETE to confirm.', 'dn-burst-funnel-stats' ) );
	}

	foreach ( array( 'visitors', 'sessions', 'pageviews', 'events', 'daily' ) as $name ) {
		$wpdb->query( 'TRUNCATE TABLE ' . dn_bfs_table( $name ) );
	}

	foreach ( dn_bfs_get_dirty_dates() as $date ) {
		delete_option( 'dnbfs_dirty_' . $date );
	}

	foreach ( array( 'dnbfs_last_aggregated_date', 'dnbfs_aggregate_last_error', 'dnbfs_aggregate_lock' ) as $option ) {
		delete_option( $option );
	}

	return true;
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
		return dn_bfs_request_error( 'invalid_import', __( 'This file is not a DN Burst Funnel Stats export.', 'dn-burst-funnel-stats' ) );
	}

	$imported = array();

	if ( isset( $payload['settings']['tracking'] ) && is_array( $payload['settings']['tracking'] ) ) {
		$incoming = $payload['settings']['tracking'];
		unset( $incoming['maxmind_license_key'] );

		update_option( 'dn_burst_funnel_stats_tracking_settings', dn_bfs_sanitize_tracking_settings( array_merge( dn_bfs_get_tracking_settings(), $incoming ) ), false );
		$imported[] = 'tracking';
	}

	if ( isset( $payload['settings']['woocommerce_report'] ) && is_array( $payload['settings']['woocommerce_report'] ) ) {
		update_option( 'dn_burst_funnel_stats_wc_report_settings', dn_bfs_sanitize_wc_report_settings( $payload['settings']['woocommerce_report'] ), false );
		$imported[] = 'woocommerce_report';
	}

	return array( 'imported' => $imported );
}

function dn_bfs_geoip_update_now() {
	$settings = dn_bfs_get_tracking_settings();

	if ( '' === $settings['maxmind_license_key'] ) {
		return dn_bfs_request_error( 'no_license', __( 'Add a MaxMind license key first.', 'dn-burst-funnel-stats' ) );
	}

	$result           = dn_bfs_geoip_update( $settings['maxmind_license_key'] );
	$result['status'] = dn_bfs_geoip_status();

	return $result;
}

function dn_bfs_blocked_stats( $days = 7, $now = null ) {
	global $wpdb;

	$now   = null === $now ? dn_bfs_now() : (int) $now;
	$since = dn_bfs_date_shift( wp_date( 'Y-m-d', $now ), -1 * ( max( 1, (int) $days ) - 1 ) );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT dim_value, SUM(pageviews) AS total FROM ' . dn_bfs_table( 'daily' ) . " WHERE dimension = 'blocked' AND date >= %s GROUP BY dim_hash, dim_value ORDER BY total DESC, dim_value ASC",
			$since
		),
		ARRAY_A
	);
	$stats = array();

	foreach ( (array) $rows as $row ) {
		$stats[ (string) $row['dim_value'] ] = (int) $row['total'];
	}

	return $stats;
}
```

- [ ] **Step 3: Tạo `includes/admin/settings-page.php`**

```php
<?php
/**
 * Settings screen: seven tabs of WordPress forms on top of the settings model.
 *
 * @package DN_Burst_Funnel_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dn_bfs_settings_tabs() {
	return array(
		'general'     => __( 'General', 'dn-burst-funnel-stats' ),
		'tracking'    => __( 'Tracking', 'dn-burst-funnel-stats' ),
		'antispam'    => __( 'Anti-spam', 'dn-burst-funnel-stats' ),
		'woocommerce' => __( 'WooCommerce', 'dn-burst-funnel-stats' ),
		'geoip'       => __( 'GeoIP', 'dn-burst-funnel-stats' ),
		'data'        => __( 'Data', 'dn-burst-funnel-stats' ),
		'system'      => __( 'System', 'dn-burst-funnel-stats' ),
	);
}

function dn_bfs_settings_current_tab() {
	$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification

	return array_key_exists( $tab, dn_bfs_settings_tabs() ) ? $tab : 'general';
}

function dn_bfs_settings_url( $tab, $args = array() ) {
	return add_query_arg(
		array_merge(
			array(
				'page' => 'dn-burst-funnel-stats-settings',
				'tab'  => $tab,
			),
			$args
		),
		admin_url( 'admin.php' )
	);
}

function dn_bfs_settings_fields() {
	$ranges = dn_bfs_tracking_int_ranges();
	$number = function ( $key, $label, $unit, $description = '' ) use ( $ranges ) {
		return array(
			'key'         => $key,
			'type'        => 'number',
			'label'       => $label,
			'unit'        => $unit,
			'min'         => $ranges[ $key ][0],
			'max'         => $ranges[ $key ][1],
			'description' => $description,
		);
	};

	return array(
		'general'     => array(
			array( 'key' => 'tracking_enabled', 'type' => 'checkbox', 'label' => __( 'Tracking', 'dn-burst-funnel-stats' ), 'text' => __( 'Record visits, add to cart and orders', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'default_date_range', 'type' => 'select', 'label' => __( 'Default date range', 'dn-burst-funnel-stats' ), 'options' => 'presets' ),
			array( 'key' => 'default_compare', 'type' => 'select', 'label' => __( 'Default comparison', 'dn-burst-funnel-stats' ), 'options' => array( 'previous_period' => __( 'Previous period', 'dn-burst-funnel-stats' ), 'previous_year' => __( 'Previous year', 'dn-burst-funnel-stats' ), 'none' => __( 'None', 'dn-burst-funnel-stats' ) ) ),
		),
		'tracking'    => array(
			array( 'key' => 'excluded_roles', 'type' => 'checklist', 'label' => __( 'Excluded roles', 'dn-burst-funnel-stats' ), 'options' => 'roles', 'description' => __( 'Logged-in users with these roles are never tracked.', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'excluded_ips', 'type' => 'lines', 'label' => __( 'Excluded IP addresses', 'dn-burst-funnel-stats' ), 'description' => __( 'One IP address or CIDR range per line, for example 192.168.1.0/24.', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'client_ip_source', 'type' => 'select', 'label' => __( 'Client IP source', 'dn-burst-funnel-stats' ), 'options' => array( 'auto' => __( 'Automatic (Cloudflare when present)', 'dn-burst-funnel-stats' ), 'remote_addr' => 'REMOTE_ADDR', 'x_forwarded_for' => 'X-Forwarded-For', 'x_real_ip' => 'X-Real-IP' ), 'description' => __( 'Change this only when the site is behind a proxy other than Cloudflare.', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'page_tracking_mode', 'type' => 'radio', 'label' => __( 'Pages', 'dn-burst-funnel-stats' ), 'options' => array( 'full' => __( 'Track the whole site', 'dn-burst-funnel-stats' ), 'selected' => __( 'Track only selected pages', 'dn-burst-funnel-stats' ) ) ),
			array( 'key' => 'selected_page_ids', 'type' => 'posts', 'post_type' => 'page', 'label' => __( 'Selected pages', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'product_tracking_mode', 'type' => 'radio', 'label' => __( 'Products', 'dn-burst-funnel-stats' ), 'options' => array( 'all' => __( 'Track all products', 'dn-burst-funnel-stats' ), 'selected' => __( 'Track only selected products', 'dn-burst-funnel-stats' ) ) ),
			array( 'key' => 'selected_product_ids', 'type' => 'posts', 'post_type' => 'product', 'label' => __( 'Selected products', 'dn-burst-funnel-stats' ) ),
			$number( 'session_timeout', __( 'Session timeout', 'dn-burst-funnel-stats' ), __( 'minutes', 'dn-burst-funnel-stats' ) ),
			$number( 'cookie_days', __( 'Visitor cookie lifetime', 'dn-burst-funnel-stats' ), __( 'days', 'dn-burst-funnel-stats' ) ),
		),
		'antispam'    => array(
			$number( 'dedupe_window', __( 'Product view and add to cart window', 'dn-burst-funnel-stats' ), __( 'seconds', 'dn-burst-funnel-stats' ), __( 'Each visitor counts once per product within this window (300 seconds = 5 minutes).', 'dn-burst-funnel-stats' ) ),
			$number( 'reload_window', __( 'Ignore reloads of the same page within', 'dn-burst-funnel-stats' ), __( 'seconds', 'dn-burst-funnel-stats' ) ),
			$number( 'limit_pv_per_min', __( 'Pageviews per minute per session', 'dn-burst-funnel-stats' ), '' ),
			$number( 'limit_sessions_per_hour', __( 'New sessions per hour per IP and browser', 'dn-burst-funnel-stats' ), '', __( 'Raise this for mobile networks where many shoppers share one IP.', 'dn-burst-funnel-stats' ) ),
			$number( 'limit_atc_per_min', __( 'Add to cart calls per minute per visitor', 'dn-burst-funnel-stats' ), '' ),
			$number( 'limit_pv_per_session', __( 'Pageviews per session', 'dn-burst-funnel-stats' ), '' ),
			array( 'key' => 'exclude_bots', 'type' => 'checkbox', 'label' => __( 'Bots', 'dn-burst-funnel-stats' ), 'text' => __( 'Ignore known bots and crawlers', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'block_empty_ua', 'type' => 'checkbox', 'label' => __( 'Empty user agent', 'dn-burst-funnel-stats' ), 'text' => __( 'Ignore requests without a user agent', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'custom_bot_user_agents', 'type' => 'lines', 'label' => __( 'Extra bot keywords', 'dn-burst-funnel-stats' ), 'description' => __( 'One keyword per line. User agents containing a keyword are ignored.', 'dn-burst-funnel-stats' ) ),
		),
		'woocommerce' => array(
			array( 'key' => 'force_cart_redirect', 'type' => 'checkbox', 'label' => __( 'Add to cart', 'dn-burst-funnel-stats' ), 'text' => __( 'Send shoppers to the Cart page after adding a product', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'sales_excluded_statuses', 'type' => 'statuses', 'label' => __( 'Not counted as sales', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'paid_statuses', 'type' => 'statuses', 'label' => __( 'Counted as paid', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'balance_statuses', 'type' => 'statuses', 'label' => __( 'Counted as balance', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'tip_keywords', 'type' => 'lines', 'label' => __( 'Tip fee keywords', 'dn-burst-funnel-stats' ), 'description' => __( 'Order fees whose name contains one of these words count as tips.', 'dn-burst-funnel-stats' ) ),
		),
		'geoip'       => array(
			array( 'key' => 'prefer_cloudflare', 'type' => 'checkbox', 'label' => __( 'Cloudflare', 'dn-burst-funnel-stats' ), 'text' => __( 'Use Cloudflare country headers when present', 'dn-burst-funnel-stats' ) ),
			array( 'key' => 'maxmind_license_key', 'type' => 'password', 'label' => __( 'MaxMind license key', 'dn-burst-funnel-stats' ), 'description' => __( 'Free key from maxmind.com. The GeoLite2 City database is downloaded and refreshed every 30 days.', 'dn-burst-funnel-stats' ) ),
		),
		'data'        => array(
			$number( 'raw_retention_days', __( 'Keep raw tracking data for', 'dn-burst-funnel-stats' ), __( 'days', 'dn-burst-funnel-stats' ), __( 'Daily totals are kept forever. Combined filters only work inside this window.', 'dn-burst-funnel-stats' ) ),
		),
	);
}

function dn_bfs_settings_input_from_post( $group, $post ) {
	$fields = dn_bfs_settings_fields();

	if ( ! isset( $fields[ $group ] ) ) {
		return array();
	}

	$raw   = isset( $post['dn_bfs'] ) && is_array( $post['dn_bfs'] ) ? $post['dn_bfs'] : array();
	$input = array();

	foreach ( $fields[ $group ] as $field ) {
		$key   = $field['key'];
		$value = isset( $raw[ $key ] ) ? $raw[ $key ] : null;

		switch ( $field['type'] ) {
			case 'checkbox':
				$input[ $key ] = empty( $value ) ? 0 : 1;
				break;
			case 'checklist':
			case 'statuses':
			case 'posts':
				$input[ $key ] = is_array( $value ) ? array_values( $value ) : array();
				break;
			case 'number':
				$input[ $key ] = (int) $value;
				break;
			default:
				$input[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}
	}

	return $input;
}

function dn_bfs_settings_save_from_post( $group, $post ) {
	$result = dn_bfs_save_settings_group( $group, dn_bfs_settings_input_from_post( $group, $post ) );

	return is_wp_error( $result ) ? $result->get_error_code() : 'saved';
}

function dn_bfs_settings_data_task( $task, $post, $files ) {
	switch ( $task ) {
		case 'reaggregate':
			$result = dn_bfs_reaggregate_range( isset( $post['start'] ) ? (string) $post['start'] : '', isset( $post['end'] ) ? (string) $post['end'] : '' );

			return array( 'tab' => 'data', 'notice' => is_wp_error( $result ) ? $result->get_error_code() : 'reaggregated' );
		case 'purge':
			$result = dn_bfs_purge_all_data( isset( $post['confirm'] ) ? (string) $post['confirm'] : '' );

			return array( 'tab' => 'data', 'notice' => is_wp_error( $result ) ? $result->get_error_code() : 'purged' );
		case 'import':
			if ( empty( $files['import_file']['tmp_name'] ) || ! is_uploaded_file( $files['import_file']['tmp_name'] ) ) {
				return array( 'tab' => 'data', 'notice' => 'missing_file' );
			}

			$result = dn_bfs_import_settings( json_decode( (string) file_get_contents( $files['import_file']['tmp_name'] ), true ) );

			return array( 'tab' => 'data', 'notice' => is_wp_error( $result ) ? $result->get_error_code() : 'imported' );
		case 'geoip_update':
			$result = dn_bfs_geoip_update_now();

			if ( is_wp_error( $result ) ) {
				return array( 'tab' => 'geoip', 'notice' => $result->get_error_code() );
			}

			return array( 'tab' => 'geoip', 'notice' => $result['ok'] ? 'geoip_updated' : 'geoip_' . $result['reason'] );
	}

	return array( 'tab' => 'data', 'notice' => 'invalid_task' );
}

function dn_bfs_settings_notice( $code ) {
	$messages = array(
		'saved'            => array( 'success', __( 'Settings saved.', 'dn-burst-funnel-stats' ) ),
		'reaggregated'     => array( 'success', __( 'The selected days were re-aggregated.', 'dn-burst-funnel-stats' ) ),
		'purged'           => array( 'success', __( 'All tracking data was deleted.', 'dn-burst-funnel-stats' ) ),
		'imported'         => array( 'success', __( 'Settings imported.', 'dn-burst-funnel-stats' ) ),
		'geoip_updated'    => array( 'success', __( 'The GeoIP database was updated.', 'dn-burst-funnel-stats' ) ),
		'confirm_required' => array( 'error', __( 'Type DELETE to confirm.', 'dn-burst-funnel-stats' ) ),
		'invalid_import'   => array( 'error', __( 'This file is not a DN Burst Funnel Stats export.', 'dn-burst-funnel-stats' ) ),
		'missing_file'     => array( 'error', __( 'Choose a JSON file to import.', 'dn-burst-funnel-stats' ) ),
		'no_license'       => array( 'error', __( 'Add a MaxMind license key first.', 'dn-burst-funnel-stats' ) ),
		'invalid_date'     => array( 'error', __( 'Use valid start and end dates.', 'dn-burst-funnel-stats' ) ),
		'range_too_long'   => array( 'error', __( 'Re-aggregate at most 92 days at a time.', 'dn-burst-funnel-stats' ) ),
	);

	if ( isset( $messages[ $code ] ) ) {
		return $messages[ $code ];
	}

	if ( 0 === strpos( $code, 'geoip_' ) ) {
		/* translators: %s: error code. */
		return array( 'error', sprintf( __( 'The GeoIP update failed: %s.', 'dn-burst-funnel-stats' ), substr( $code, 6 ) ) );
	}

	return array( 'error', __( 'Something went wrong. Please try again.', 'dn-burst-funnel-stats' ) );
}

function dn_bfs_handle_save_settings() {
	$group = isset( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked below.

	if ( ! dn_bfs_admin_permission() ) {
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'dn-burst-funnel-stats' ), 403 );
	}

	check_admin_referer( 'dn_bfs_save_settings_' . $group );

	wp_safe_redirect( dn_bfs_settings_url( $group, array( 'dn_notice' => dn_bfs_settings_save_from_post( $group, wp_unslash( $_POST ) ) ) ) );
	exit;
}
add_action( 'admin_post_dn_bfs_save_settings', 'dn_bfs_handle_save_settings' );

function dn_bfs_handle_data_task() {
	$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked below.

	if ( ! dn_bfs_admin_permission() ) {
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'dn-burst-funnel-stats' ), 403 );
	}

	check_admin_referer( 'dn_bfs_data_' . $task );

	$result = dn_bfs_settings_data_task( $task, wp_unslash( $_POST ), $_FILES );

	wp_safe_redirect( dn_bfs_settings_url( $result['tab'], array( 'dn_notice' => $result['notice'] ) ) );
	exit;
}
add_action( 'admin_post_dn_bfs_data_task', 'dn_bfs_handle_data_task' );

function dn_bfs_handle_export() {
	if ( ! dn_bfs_admin_permission() ) {
		wp_die( esc_html__( 'You do not have permission to export these settings.', 'dn-burst-funnel-stats' ), 403 );
	}

	check_admin_referer( 'dn_bfs_data_export' );
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=dn-burst-funnel-stats-settings-' . gmdate( 'Y-m-d' ) . '.json' );
	echo wp_json_encode( dn_bfs_export_settings(), JSON_PRETTY_PRINT );
	exit;
}
add_action( 'admin_post_dn_bfs_export', 'dn_bfs_handle_export' );

function dn_bfs_render_post_multiselect( $name, $posts, $selected_ids ) {
	$selected_ids = array_map( 'absint', (array) $selected_ids );
	$id           = sanitize_key( $name );
	?>
	<div class="dn-burst-searchable-select" data-dn-searchable-select>
		<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>_search"><?php esc_html_e( 'Search items', 'dn-burst-funnel-stats' ); ?></label>
		<input type="search" id="<?php echo esc_attr( $id ); ?>_search" class="regular-text dn-burst-select-search" placeholder="<?php echo esc_attr__( 'Search by title…', 'dn-burst-funnel-stats' ); ?>" data-dn-select-search />
		<select name="<?php echo esc_attr( $name ); ?>[]" multiple size="10" class="dn-burst-multiselect" data-dn-select-list>
			<?php foreach ( $posts as $post ) : ?>
				<option value="<?php echo esc_attr( $post->ID ); ?>" <?php selected( in_array( (int) $post->ID, $selected_ids, true ) ); ?>><?php echo esc_html( get_the_title( $post->ID ) ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<?php
}

function dn_bfs_render_setting_field( $field, $value, $meta ) {
	$name = 'dn_bfs[' . $field['key'] . ']';
	$id   = 'dn_bfs_' . $field['key'];

	switch ( $field['type'] ) {
		case 'checkbox':
			printf( '<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>', esc_attr( $id ), esc_attr( $name ), checked( ! empty( $value ), true, false ), esc_html( $field['text'] ) );
			break;
		case 'select':
			$options = 'presets' === $field['options'] ? $meta['presets'] : $field['options'];
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( $options as $option => $label ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option ), selected( (string) $value, (string) $option, false ), esc_html( $label ) );
			}
			echo '</select>';
			break;
		case 'radio':
			echo '<fieldset>';
			foreach ( $field['options'] as $option => $label ) {
				printf( '<label><input type="radio" name="%1$s" value="%2$s" %3$s /> %4$s</label><br />', esc_attr( $name ), esc_attr( $option ), checked( (string) $value, (string) $option, false ), esc_html( $label ) );
			}
			echo '</fieldset>';
			break;
		case 'number':
			printf( '<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$s" min="%4$d" max="%5$d" step="1" /> %6$s', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), (int) $field['min'], (int) $field['max'], esc_html( $field['unit'] ) );
			break;
		case 'lines':
			printf( '<textarea class="large-text code" rows="6" id="%1$s" name="%2$s">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( implode( "\n", (array) $value ) ) );
			break;
		case 'password':
			printf( '<input type="password" class="regular-text" autocomplete="off" id="%1$s" name="%2$s" value="%3$s" />', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
			break;
		case 'checklist':
		case 'statuses':
			$options = 'statuses' === $field['type'] ? $meta['order_statuses'] : $meta['roles'];
			echo '<fieldset class="dn-burst-checklist">';
			foreach ( $options as $option => $label ) {
				printf( '<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>', esc_attr( $name ), esc_attr( $option ), checked( in_array( $option, (array) $value, true ), true, false ), esc_html( translate_user_role( $label ) ) );
			}
			echo '</fieldset>';
			break;
		case 'posts':
			$posts = 'page' === $field['post_type']
				? get_pages( array( 'post_status' => array( 'publish', 'private', 'draft' ), 'sort_column' => 'post_title' ) )
				: get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => 300, 'orderby' => 'title', 'order' => 'ASC' ) );
			dn_bfs_render_post_multiselect( $name, $posts, $value );
			break;
	}

	if ( ! empty( $field['description'] ) ) {
		echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
	}

	if ( 'excluded_ips' === $field['key'] ) {
		/* translators: %s: visitor IP address. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Your current IP appears to be: %s', 'dn-burst-funnel-stats' ), dn_bfs_get_client_ip() ) ) . '</p>';
	}
}

function dn_bfs_render_settings_form( $group ) {
	$data   = dn_bfs_get_settings_group( $group );
	$fields = dn_bfs_settings_fields();

	if ( 'tracking' === $group && ! empty( $data['meta']['invalid_excluded_ips'] ) ) {
		/* translators: %s: comma-separated invalid IP rules. */
		echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'These IP entries were ignored because they are invalid: %s', 'dn-burst-funnel-stats' ), implode( ', ', $data['meta']['invalid_excluded_ips'] ) ) ) . '</p></div>';
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dn-burst-settings-form">
		<?php wp_nonce_field( 'dn_bfs_save_settings_' . $group ); ?>
		<input type="hidden" name="action" value="dn_bfs_save_settings" />
		<input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>" />
		<div class="dn-burst-panel">
			<table class="form-table" role="presentation">
				<tbody>
					<?php foreach ( $fields[ $group ] as $field ) : ?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( 'dn_bfs_' . $field['key'] ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
							<td><?php dn_bfs_render_setting_field( $field, $data['values'][ $field['key'] ], $data['meta'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php submit_button( __( 'Save Settings', 'dn-burst-funnel-stats' ) ); ?>
	</form>
	<?php
}

function dn_bfs_render_data_task_form( $task, $button, $fields_html = '', $class = 'button', $multipart = false ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dn-burst-inline-form" <?php echo $multipart ? 'enctype="multipart/form-data"' : ''; ?>>
		<?php wp_nonce_field( 'dn_bfs_data_' . $task ); ?>
		<input type="hidden" name="action" value="dn_bfs_data_task" />
		<input type="hidden" name="task" value="<?php echo esc_attr( $task ); ?>" />
		<?php echo $fields_html; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts by callers. ?>
		<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $button ); ?></button>
	</form>
	<?php
}

function dn_bfs_render_settings_antispam_panel() {
	$stats = dn_bfs_blocked_stats( 7 );
	?>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'Blocked requests (last 7 days)', 'dn-burst-funnel-stats' ); ?></h2>
		<?php if ( empty( $stats ) ) : ?>
			<p><?php esc_html_e( 'Nothing was blocked.', 'dn-burst-funnel-stats' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<tbody>
					<?php foreach ( $stats as $reason => $count ) : ?>
						<tr><td><code><?php echo esc_html( $reason ); ?></code></td><td><?php echo esc_html( number_format_i18n( $count ) ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

function dn_bfs_render_settings_geoip_panel() {
	$status = dn_bfs_geoip_status();
	$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	?>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'GeoIP database', 'dn-burst-funnel-stats' ); ?></h2>
		<table class="widefat striped">
			<tbody>
				<tr><td><?php esc_html_e( 'Database file', 'dn-burst-funnel-stats' ); ?></td><td><?php echo esc_html( $status['database'] ? size_format( $status['size'] ) : __( 'Not downloaded', 'dn-burst-funnel-stats' ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Last update', 'dn-burst-funnel-stats' ); ?></td><td><?php echo esc_html( $status['updated_at'] ? wp_date( $format, $status['updated_at'] ) : '—' ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Last error', 'dn-burst-funnel-stats' ); ?></td><td><?php echo esc_html( '' !== $status['last_error'] ? $status['last_error'] : '—' ); ?></td></tr>
			</tbody>
		</table>
		<?php dn_bfs_render_data_task_form( 'geoip_update', __( 'Update database now', 'dn-burst-funnel-stats' ) ); ?>
	</div>
	<?php
}

function dn_bfs_render_settings_data_panels() {
	$stats = dn_bfs_data_stats();
	?>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'Storage', 'dn-burst-funnel-stats' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Table', 'dn-burst-funnel-stats' ); ?></th><th><?php esc_html_e( 'Rows (approx.)', 'dn-burst-funnel-stats' ); ?></th><th><?php esc_html_e( 'Size', 'dn-burst-funnel-stats' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $stats['tables'] as $name => $table ) : ?>
					<tr><td><code><?php echo esc_html( dn_bfs_table( $name ) ); ?></code></td><td><?php echo esc_html( number_format_i18n( $table['rows'] ) ); ?></td><td><?php echo esc_html( size_format( $table['bytes'] ) ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php /* translators: 1: last aggregated date, 2: first date with raw data. */ ?>
		<p class="description"><?php echo esc_html( sprintf( __( 'Aggregated through %1$s. Raw data is available from %2$s.', 'dn-burst-funnel-stats' ), '' !== $stats['last_aggregated'] ? $stats['last_aggregated'] : '—', $stats['raw_available_from'] ) ); ?></p>
	</div>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'Re-aggregate', 'dn-burst-funnel-stats' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Rebuild daily totals for a date range (at most 92 days).', 'dn-burst-funnel-stats' ); ?></p>
		<?php
		dn_bfs_render_data_task_form(
			'reaggregate',
			__( 'Re-aggregate', 'dn-burst-funnel-stats' ),
			'<input type="date" name="start" required /> <input type="date" name="end" required /> '
		);
		?>
	</div>
	<div class="dn-burst-panel">
		<h2><?php esc_html_e( 'Export / Import settings', 'dn-burst-funnel-stats' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dn-burst-inline-form">
			<?php wp_nonce_field( 'dn_bfs_data_export' ); ?>
			<input type="hidden" name="action" value="dn_bfs_export" />
			<button type="submit" class="button"><?php esc_html_e( 'Download settings (JSON)', 'dn-burst-funnel-stats' ); ?></button>
		</form>
		<?php dn_bfs_render_data_task_form( 'import', __( 'Import settings', 'dn-burst-funnel-stats' ), '<input type="file" name="import_file" accept=".json,application/json" required /> ', 'button', true ); ?>
		<p class="description"><?php esc_html_e( 'Exports never include the MaxMind license key or tracking data.', 'dn-burst-funnel-stats' ); ?></p>
	</div>
	<div class="dn-burst-panel dn-burst-danger-zone">
		<h2><?php esc_html_e( 'Delete all tracking data', 'dn-burst-funnel-stats' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Removes every visitor, session, pageview, event and daily total. Settings are kept. This cannot be undone.', 'dn-burst-funnel-stats' ); ?></p>
		<?php
		dn_bfs_render_data_task_form(
			'purge',
			__( 'Delete all data', 'dn-burst-funnel-stats' ),
			'<input type="text" name="confirm" placeholder="DELETE" autocomplete="off" required /> ',
			'button button-link-delete'
		);
		?>
	</div>
	<?php
}

function dn_bfs_render_settings_system() {
	?>
	<div class="dn-burst-panel">
		<table class="widefat striped dn-burst-status-table">
			<tbody>
				<?php foreach ( dn_bfs_system_status() as $check ) : ?>
					<tr>
						<td><span class="dn-burst-status-badge is-<?php echo esc_attr( $check['status'] ); ?>"><?php echo esc_html( $check['status'] ); ?></span></td>
						<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
						<td><?php echo esc_html( $check['detail'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

function dn_bfs_render_settings_page() {
	if ( ! dn_bfs_admin_permission() ) {
		return;
	}

	$tab    = dn_bfs_settings_current_tab();
	$notice = isset( $_GET['dn_notice'] ) && is_string( $_GET['dn_notice'] ) ? sanitize_key( wp_unslash( $_GET['dn_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	?>
	<div class="wrap dn-burst-wrap dn-burst-settings">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Funnel Stats Settings', 'dn-burst-funnel-stats' ); ?></h1>
		<?php if ( '' !== $notice ) : ?>
			<?php list( $type, $message ) = dn_bfs_settings_notice( $notice ); ?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
		<?php endif; ?>
		<nav class="nav-tab-wrapper dn-burst-tabs">
			<?php foreach ( dn_bfs_settings_tabs() as $key => $label ) : ?>
				<a href="<?php echo esc_url( dn_bfs_settings_url( $key ) ); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		if ( 'system' === $tab ) {
			dn_bfs_render_settings_system();
		} else {
			dn_bfs_render_settings_form( $tab );

			if ( 'antispam' === $tab ) {
				dn_bfs_render_settings_antispam_panel();
			} elseif ( 'geoip' === $tab ) {
				dn_bfs_render_settings_geoip_panel();
			} elseif ( 'data' === $tab ) {
				dn_bfs_render_settings_data_panels();
			}
		}
		?>
	</div>
	<?php
}
```

- [ ] **Step 4: Nạp module** — thêm `'data-tools', 'settings-page'` vào mảng `dn_burst_funnel_stats_load_admin()`. (Hàm cũ trong `includes/settings.php` có tiền tố `dn_burst_funnel_stats_*` — không trùng.)

- [ ] **Step 5: Chạy test** — tích hợp PASS (thêm 6 test); unit + `php74` → OK.

- [ ] **Step 6: Commit**

```bash
git add includes/admin/data-tools.php includes/admin/settings-page.php tests/integration/test-settings-page.php dn-burst-funnel-stats.php
git commit -m "feat(admin): add the seven-tab settings screen and data tools

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 7: Nối menu, nâng cấp `admin.js` / `admin.css`, xóa dashboard cũ

**Files:**
- Create: `includes/admin/menu.php`, `tests/integration/test-admin-menu.php`
- Modify: `assets/admin.js`, `assets/admin.css`, `dn-burst-funnel-stats.php`
- Delete: `includes/dashboard.php`, `includes/ajax.php`, `includes/admin-menu.php`, `includes/settings.php`, `includes/import-export.php`

**Interfaces:**
- Consumes: Task 5 (`dn_bfs_dash_render_page`, markup data-attribute), Task 6 (`dn_bfs_render_settings_page`), Task 1 (`dn_bfs_params_from_query`, `dn_bfs_admin_permission`).
- Produces:
  - `dn_bfs_admin_pages()`, `dn_bfs_current_admin_page(): string` (`dashboard|settings|''`), `dn_bfs_register_admin_menu()`, `dn_bfs_admin_script_data(): array` (→ `window.dnBfsAdmin`: `ajaxUrl, nonce, page, tab, period, compare, start, end, filters (object), filterLabels, strings`), `dn_bfs_enqueue_admin_assets( $hook )` (handle `dn-burst-funnel-stats-admin`, phụ thuộc `jquery`, `jquery-ui-sortable`).
  - `assets/admin.js` gọi các action AJAX của Task 5 và giữ nguyên code vẽ biểu đồ.

- [ ] **Step 1: Viết test fail — `tests/integration/test-admin-menu.php`**

```php
<?php

require_once __DIR__ . '/admin-helpers.php';

dn_bfs_it(
	'admin menu registers dashboard and settings pages only',
	function () {
		global $submenu;

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		dn_bfs_it_login_admin();
		$submenu = array();
		dn_bfs_register_admin_menu();

		dn_bfs_assert_same( array( 'dn-burst-funnel-stats', 'dn-burst-funnel-stats-settings' ), array_column( $submenu['dn-burst-funnel-stats'], 2 ) );
	}
);

dn_bfs_it(
	'assets load on plugin pages with the dashboard state',
	function () {
		dn_bfs_it_login_admin();
		$_GET = array( 'page' => 'dn-burst-funnel-stats', 'dn_tab' => 'sources', 'dn_period' => 'yesterday', 'dn_filter' => array( 'campaign' => 'x' ) );
		wp_dequeue_script( 'dn-burst-funnel-stats-admin' );

		dn_bfs_enqueue_admin_assets( 'toplevel_page_dn-burst-funnel-stats' );
		dn_bfs_assert_true( wp_script_is( 'dn-burst-funnel-stats-admin', 'enqueued' ), 'script' );
		dn_bfs_assert_true( in_array( 'jquery-ui-sortable', wp_scripts()->registered['dn-burst-funnel-stats-admin']->deps, true ), 'sortable dependency' );

		$data = dn_bfs_admin_script_data();
		dn_bfs_assert_same( 'dashboard', $data['page'] );
		dn_bfs_assert_same( 'sources', $data['tab'] );
		dn_bfs_assert_same( 'yesterday', $data['period'] );
		dn_bfs_assert_same( array( 'campaign' => 'x' ), (array) $data['filters'] );
		dn_bfs_assert_true( '' !== $data['nonce'], 'nonce' );

		$_GET = array( 'page' => 'woocommerce' );
		wp_dequeue_script( 'dn-burst-funnel-stats-admin' );
		dn_bfs_enqueue_admin_assets( 'woocommerce_page_wc-admin' );
		dn_bfs_assert_true( ! wp_script_is( 'dn-burst-funnel-stats-admin', 'enqueued' ), 'not on other pages' );
		$_GET = array();
	}
);

dn_bfs_it(
	'empty filters are sent as an object',
	function () {
		$_GET = array( 'page' => 'dn-burst-funnel-stats' );
		dn_bfs_assert_same( '{}', wp_json_encode( dn_bfs_admin_script_data()['filters'] ) );
		$_GET = array();
	}
);

dn_bfs_it(
	'the Burst-era dashboard code and AJAX actions are gone',
	function () {
		foreach ( array( 'dn_burst_dash_render_page', 'dn_burst_dash_build_data', 'dn_burst_funnel_stats_ajax_load_tab', 'dn_burst_funnel_stats_render_settings_page', 'dn_burst_funnel_stats_render_import_export_page' ) as $function ) {
			dn_bfs_assert_true( ! function_exists( $function ), $function );
		}

		dn_bfs_assert_true( false === has_action( 'wp_ajax_dn_burst_funnel_stats_load_tab' ), 'old ajax action' );
		dn_bfs_assert_true( false !== has_action( 'woocommerce_add_to_cart', 'dn_bfs_wc_on_add_to_cart' ), 'native ATC hook kept' );
		dn_bfs_assert_true( ! function_exists( 'dn_burst_dash_record_atc_url_groups' ), 'legacy ATC recorder removed' );
	}
);
```

Run tích hợp → Expected: test mới FAIL (`Call to undefined function dn_bfs_register_admin_menu()`).

- [ ] **Step 2: Tạo `includes/admin/menu.php`**

```php
<?php
/**
 * Admin menu and assets for the dashboard and settings screens.
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
	$slug  = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$pages = dn_bfs_admin_pages();

	return isset( $pages[ $slug ] ) ? $pages[ $slug ] : '';
}

function dn_bfs_register_admin_menu() {
	$capability = dn_bfs_admin_capability();

	add_menu_page( __( 'Funnel Stats', 'dn-burst-funnel-stats' ), __( 'Funnel Stats', 'dn-burst-funnel-stats' ), $capability, 'dn-burst-funnel-stats', 'dn_bfs_dash_render_page', 'dashicons-chart-area', 56 );
	add_submenu_page( 'dn-burst-funnel-stats', __( 'Dashboard', 'dn-burst-funnel-stats' ), __( 'Dashboard', 'dn-burst-funnel-stats' ), $capability, 'dn-burst-funnel-stats', 'dn_bfs_dash_render_page' );
	add_submenu_page( 'dn-burst-funnel-stats', __( 'Settings', 'dn-burst-funnel-stats' ), __( 'Settings', 'dn-burst-funnel-stats' ), $capability, 'dn-burst-funnel-stats-settings', 'dn_bfs_render_settings_page' );
}
add_action( 'admin_menu', 'dn_bfs_register_admin_menu' );

function dn_bfs_admin_nocache() {
	if ( '' !== dn_bfs_current_admin_page() && ! headers_sent() ) {
		nocache_headers();
	}
}
add_action( 'admin_init', 'dn_bfs_admin_nocache' );

function dn_bfs_admin_script_data() {
	$params  = dn_bfs_params_from_query( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$request = dn_bfs_dash_request( $params );

	if ( is_wp_error( $request ) ) {
		$request = dn_bfs_dash_request( array() );
	}

	list( $range, $filters ) = $request;
	$labels                  = dn_bfs_dash_dimension_labels();

	return array(
		'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
		'nonce'        => wp_create_nonce( 'dn_bfs_admin' ),
		'page'         => dn_bfs_current_admin_page(),
		'tab'          => dn_bfs_dash_sanitize_tab( $params['tab'] ),
		'period'       => $range['period'],
		'compare'      => $range['compare'],
		'start'        => 'custom' === $range['period'] ? $range['custom_start'] : '',
		'end'          => 'custom' === $range['period'] ? $range['custom_end'] : '',
		'filters'      => (object) $filters,
		'filterLabels' => array_intersect_key( $labels, array_flip( dn_bfs_filter_dimensions() ) ),
		'strings'      => array(
			'loading'      => __( 'Loading data…', 'dn-burst-funnel-stats' ),
			'error'        => __( 'Unable to load data. Please try again.', 'dn-burst-funnel-stats' ),
			'updated'      => __( 'Data refreshed.', 'dn-burst-funnel-stats' ),
			'removeFilter' => __( 'Remove filter', 'dn-burst-funnel-stats' ),
			'activePages'  => __( 'Pages being viewed', 'dn-burst-funnel-stats' ),
			'nobodyOnline' => __( 'Nobody is online right now.', 'dn-burst-funnel-stats' ),
		),
	);
}

function dn_bfs_enqueue_admin_assets( $hook_suffix ) {
	unset( $hook_suffix );

	if ( '' === dn_bfs_current_admin_page() ) {
		return;
	}

	$css = DN_BURST_FUNNEL_STATS_PATH . 'assets/admin.css';
	$js  = DN_BURST_FUNNEL_STATS_PATH . 'assets/admin.js';

	wp_enqueue_style( 'dn-burst-funnel-stats-admin', DN_BURST_FUNNEL_STATS_URL . 'assets/admin.css', array(), file_exists( $css ) ? (string) filemtime( $css ) : DN_BURST_FUNNEL_STATS_VERSION );
	wp_enqueue_script( 'dn-burst-funnel-stats-admin', DN_BURST_FUNNEL_STATS_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), file_exists( $js ) ? (string) filemtime( $js ) : DN_BURST_FUNNEL_STATS_VERSION, true );
	wp_localize_script( 'dn-burst-funnel-stats-admin', 'dnBfsAdmin', dn_bfs_admin_script_data() );
}
add_action( 'admin_enqueue_scripts', 'dn_bfs_enqueue_admin_assets' );
```

- [ ] **Step 3: Nối vào plugin và xóa code cũ** — trong `dn-burst-funnel-stats.php`:
1. Thêm `'menu'` vào mảng `dn_burst_funnel_stats_load_admin()`.
2. Trong `dn_burst_funnel_stats_bootstrap()` xóa các dòng `require_once` của `includes/dashboard.php`, `includes/admin-menu.php`, `includes/settings.php`, `includes/import-export.php`, `includes/ajax.php` và dòng `dn_burst_dash_schedule_refresh_event();`.

Rồi chạy:

```bash
git rm includes/dashboard.php includes/ajax.php includes/admin-menu.php includes/settings.php includes/import-export.php
grep -rn "dn_burst_dash_\|dn_burst_funnel_stats_ajax_\|dn_burst_funnel_stats_render_\|dnBurstFunnelStats" includes dn-burst-funnel-stats.php assets tests
```

Expected: grep không còn kết quả nào (ngoài chính `assets/admin.js` trước khi sửa ở Step 4).

- [ ] **Step 4: Nâng cấp `assets/admin.js`**

Giữ **nguyên văn** khối helper biểu đồ từ dòng `function normalizeSeries(data) {` tới hết hàm `function initCharts() { … }` (gồm `getAllValues`, `hasMeaningfulChartData`, `escapeHtml`, `formatValue`, `niceMax`, `truncateText`, `setupCanvas`, `drawGrid`, `drawLineAxisLabels`, `drawSmoothLine`, `bindTooltip`, `tooltipRows`, `renderLineChart`, `renderHorizontalBarChart`, `renderFunnelChart`, `renderChart`, `initCharts`) với hai sửa đổi:
- trong `renderFunnelChart`, `var colors = ['#2271b1', '#00a32a', '#dba617', '#7f54b3'];` → `var colors = ['#2271b1', '#00a32a', '#dba617', '#7f54b3', '#d63638'];`
- trong `normalizeSeries`, xóa nhánh `if (data && ($.isArray(data.netSales) || $.isArray(data.profits) || $.isArray(data.orders))) { … }` (payload mới luôn có `series`).

Thay **toàn bộ phần trước** `function normalizeSeries(data) {` bằng:

```js
(function ($) {
	'use strict';

	var config = window.dnBfsAdmin || {};
	var strings = config.strings || {};
	var state = {
		period: config.period || 'month_to_date',
		compare: config.compare || 'previous_year',
		start: config.start || '',
		end: config.end || '',
		filters: $.isPlainObject(config.filters) ? $.extend({}, config.filters) : {}
	};

	function requestData(extra) {
		return $.extend({
			nonce: config.nonce,
			period: state.period,
			compare: state.compare,
			start: state.start,
			end: state.end,
			filter: state.filters
		}, extra || {});
	}

	function post(action, extra) {
		return $.post(config.ajaxUrl, requestData($.extend({ action: action }, extra || {})));
	}

	function errorMessage(source) {
		var body = source && source.responseJSON ? source.responseJSON : source;
		return body && body.data && body.data.message ? body.data.message : (strings.error || 'Unable to load data. Please try again.');
	}

	function getCurrentTab() {
		var $active = $('[data-dn-tab].nav-tab-active');
		return $active.length ? $active.data('dn-tab') : (config.tab || 'overview');
	}

	function setLoading($region) {
		$region.addClass('is-loading');
		if (!$region.find('.dn-burst-loading').length) {
			$region.prepend($('<div class="dn-burst-loading"></div>').text(strings.loading || 'Loading data…'));
		}
	}

	function clearLoading($region) {
		$region.removeClass('is-loading').find('.dn-burst-loading').remove();
	}

	function showError($region, message) {
		$region.html($('<div class="notice notice-error inline"><p></p></div>').find('p').text(message).end());
	}

	function updateUrl(tab) {
		if (!window.history || !window.history.pushState) {
			return;
		}

		var url = new URL(window.location.href);
		var stale = [];

		url.searchParams.forEach(function (value, key) {
			if (key.indexOf('dn_filter[') === 0) {
				stale.push(key);
			}
		});
		stale.forEach(function (key) {
			url.searchParams.delete(key);
		});

		url.searchParams.set('dn_tab', tab);
		url.searchParams.set('dn_period', state.period);
		url.searchParams.set('dn_compare', state.compare);

		if (state.period === 'custom') {
			url.searchParams.set('dn_start', state.start);
			url.searchParams.set('dn_end', state.end);
		} else {
			url.searchParams.delete('dn_start');
			url.searchParams.delete('dn_end');
		}

		$.each(state.filters, function (dimension, value) {
			url.searchParams.set('dn_filter[' + dimension + ']', value);
		});

		window.history.pushState({ dnTab: tab }, '', url.toString());
	}

	function updateDateButton(range) {
		if (!range) {
			return;
		}

		var compare = range.compare !== 'none' && range.previous_range_label ? range.compare_label + ' (' + range.previous_range_label + ')' : '';

		$('.dn-burst-date-title').text(range.current_label + ' (' + range.current_range_label + ')');
		$('.dn-burst-date-compare').text(compare).prop('hidden', !compare);
	}

	function loadTab(tab, pushState) {
		var $content = $('[data-dn-tab-content]');

		if (!$content.length) {
			return;
		}

		setLoading($content);

		post('dn_bfs_load_tab', { tab: tab }).done(function (response) {
			if (!response || !response.success) {
				showError($content, errorMessage(response));
				return;
			}

			$content.html(response.data.html);
			$('[data-dn-tab]').removeClass('nav-tab-active');
			$('[data-dn-tab="' + tab + '"]').addClass('nav-tab-active');
			$('.dn-burst-topbar-title').text(response.data.title);
			config.tab = tab;
			updateDateButton(response.data.range);
			initCharts();

			if (pushState) {
				updateUrl(tab);
			}
		}).fail(function (xhr) {
			showError($content, errorMessage(xhr));
		}).always(function () {
			clearLoading($content);
		});
	}

	function loadTable($table, overrides) {
		var $region = $table.closest('[data-dn-table-region]');

		setLoading($region);

		post('dn_bfs_table', $.extend({
			tab: $table.attr('data-tab'),
			dimension: $table.attr('data-dimension'),
			orderby: $table.attr('data-orderby'),
			order: $table.attr('data-order'),
			page: $table.attr('data-page') || 1
		}, overrides || {})).done(function (response) {
			if (!response || !response.success) {
				showError($region, errorMessage(response));
				return;
			}

			$region.html(response.data.html);
		}).fail(function (xhr) {
			showError($region, errorMessage(xhr));
		}).always(function () {
			clearLoading($region);
		});
	}

	function renderFilterChips() {
		var $chips = $('[data-dn-filter-chips]').empty();
		var labels = config.filterLabels || {};

		$.each(state.filters, function (dimension, value) {
			var $chip = $('<span class="dn-burst-chip"></span>').attr('data-dn-filter-dim', dimension);

			$chip.append($('<span></span>').text((labels[dimension] || dimension) + ': ' + value));
			$chip.append($('<button type="button" class="dn-burst-chip-remove" data-dn-filter-remove></button>').attr('aria-label', strings.removeFilter || 'Remove filter').html('&times;'));
			$chips.append($chip);
		});
	}

	function setFilter(dimension, value) {
		if (value === '' || value === null || typeof value === 'undefined') {
			delete state.filters[dimension];
		} else {
			state.filters[dimension] = String(value);
		}

		renderFilterChips();
		loadTab(getCurrentTab(), true);
	}

	function drawer() {
		var $drawer = $('[data-dn-drawer]');

		if (!$drawer.length) {
			$drawer = $('<div class="dn-burst-drawer" data-dn-drawer role="dialog" aria-modal="false" hidden><div class="dn-burst-drawer-body" data-dn-drawer-body></div></div>').appendTo('body');
		}

		return $drawer;
	}

	function openDrilldown(dimension, value) {
		var $drawer = drawer();
		var $body = $drawer.find('[data-dn-drawer-body]').empty();

		$drawer.prop('hidden', false);
		setLoading($body);

		post('dn_bfs_drilldown', { dimension: dimension, value: value }).done(function (response) {
			if (!response || !response.success) {
				showError($body, errorMessage(response));
				return;
			}

			$body.html(response.data.html);
			initCharts();
		}).fail(function (xhr) {
			showError($body, errorMessage(xhr));
		}).always(function () {
			clearLoading($body);
		});
	}

	var cardsSnapshot = null;

	function cardsGrid() {
		return $('[data-dn-cards]');
	}

	function toggleCardButtons(editing) {
		$('[data-dn-cards-edit]').prop('hidden', editing);
		$('[data-dn-cards-save], [data-dn-cards-cancel], [data-dn-cards-reset]').prop('hidden', !editing);
	}

	function exitCardEdit() {
		var $grid = cardsGrid();

		if ($.fn.sortable && $grid.hasClass('ui-sortable')) {
			$grid.sortable('destroy');
		}

		$grid.removeClass('is-editing');
		toggleCardButtons(false);
	}

	function refreshOnline() {
		var $badge = $('[data-dn-online]');

		if (!$badge.length) {
			return;
		}

		post('dn_bfs_realtime').done(function (response) {
			if (!response || !response.success) {
				return;
			}

			var $popover = $('[data-dn-online-popover]').empty();
			var $list = $('<ul></ul>');

			$badge.find('[data-dn-online-count]').text(response.data.online);

			$.each(response.data.pages || [], function (_, row) {
				$list.append($('<li></li>').append($('<code></code>').text(row.path)).append($('<span></span>').text(' ' + row.visitors)));
			});

			$popover.append($('<h3></h3>').text(strings.activePages || 'Pages being viewed'));
			$popover.append($list.children().length ? $list : $('<p></p>').text(strings.nobodyOnline || 'Nobody is online right now.'));
		});
	}
```

Thay **toàn bộ phần sau** hàm `initCharts` (từ dòng `$(document).on('click', '[data-dn-tab]', …` tới hết file) bằng:

```js
	$(document).on('click', '[data-dn-tab]', function (event) {
		event.preventDefault();
		loadTab($(this).data('dn-tab'), true);
	});

	$(document).on('click', '[data-dn-date-toggle]', function () {
		$('[data-dn-date-popover]').prop('hidden', function (_, hidden) {
			return !hidden;
		});
	});

	$(document).on('click', '[data-dn-date-mode]', function () {
		var mode = $(this).data('dn-date-mode');

		$('[data-dn-date-mode]').removeClass('is-active');
		$(this).addClass('is-active');
		$('[data-dn-date-pane]').removeClass('is-active');
		$('[data-dn-date-pane="' + mode + '"]').addClass('is-active');

		if (mode === 'custom') {
			$('input[name="dn_period"][value="custom"]').prop('checked', true);
		}
	});

	$(document).on('submit', '[data-dn-date-popover]', function (event) {
		var $form = $(this);
		var custom = $form.find('[data-dn-date-pane="custom"]').hasClass('is-active');

		event.preventDefault();
		state.period = custom ? 'custom' : ($form.find('input[name="dn_period"]:checked').val() || 'month_to_date');
		state.compare = $form.find('input[name="dn_compare"]:checked').val() || 'previous_year';
		state.start = $form.find('input[name="dn_start"]').val() || '';
		state.end = $form.find('input[name="dn_end"]').val() || '';
		$form.prop('hidden', true);
		loadTab(getCurrentTab(), true);
	});

	$(document).on('change', '[data-dn-dimension] input[name="dn_dimension"]', function () {
		loadTable($(this).closest('.dn-burst-breakdown').find('[data-dn-table]'), { dimension: $(this).val(), orderby: '', order: 'desc', page: 1 });
	});

	$(document).on('click', '[data-dn-table] [data-dn-sort]', function (event) {
		event.preventDefault();
		loadTable($(this).closest('[data-dn-table]'), { orderby: $(this).attr('data-dn-sort'), order: $(this).attr('data-dn-order'), page: 1 });
	});

	$(document).on('click', '[data-dn-table] [data-dn-page]', function (event) {
		event.preventDefault();
		loadTable($(this).closest('[data-dn-table]'), { page: $(this).attr('data-dn-page') });
	});

	$(document).on('click keydown', 'tr[data-dn-drill-dimension]', function (event) {
		if (event.type === 'keydown' && event.key !== 'Enter') {
			return;
		}

		openDrilldown($(this).attr('data-dn-drill-dimension'), $(this).attr('data-dn-drill-value'));
	});

	$(document).on('click', '[data-dn-drawer-close]', function () {
		drawer().prop('hidden', true);
	});

	$(document).on('keydown', function (event) {
		if (event.key === 'Escape') {
			drawer().prop('hidden', true);
			$('[data-dn-filter-form], [data-dn-online-popover]').prop('hidden', true);
		}
	});

	$(document).on('click', '[data-dn-apply-filter]', function () {
		drawer().prop('hidden', true);
		setFilter($(this).attr('data-dimension'), $(this).attr('data-value'));
	});

	$(document).on('click', '[data-dn-filter-remove]', function () {
		setFilter($(this).closest('[data-dn-filter-dim]').attr('data-dn-filter-dim'), '');
	});

	$(document).on('click', '[data-dn-filter-toggle]', function () {
		$('[data-dn-filter-form]').prop('hidden', function (_, hidden) {
			return !hidden;
		});
	});

	var suggestTimer;

	$(document).on('input change', '[data-dn-filter-value], [data-dn-filter-dimension]', function () {
		var $form = $(this).closest('[data-dn-filter-form]');

		window.clearTimeout(suggestTimer);
		suggestTimer = window.setTimeout(function () {
			post('dn_bfs_filter_values', {
				dimension: $form.find('[data-dn-filter-dimension]').val(),
				search: $form.find('[data-dn-filter-value]').val()
			}).done(function (response) {
				var $list = $('#dn-filter-values').empty();

				if (response && response.success) {
					$.each(response.data.values || [], function (_, value) {
						$list.append($('<option></option>').attr('value', value));
					});
				}
			});
		}, 250);
	});

	$(document).on('submit', '[data-dn-filter-form]', function (event) {
		var $form = $(this);
		var value = $.trim($form.find('[data-dn-filter-value]').val());

		event.preventDefault();

		if (value) {
			$form.prop('hidden', true);
			$form.find('[data-dn-filter-value]').val('');
			setFilter($form.find('[data-dn-filter-dimension]').val(), value);
		}
	});

	$(document).on('click', '[data-dn-cards-edit]', function () {
		var $grid = cardsGrid();

		cardsSnapshot = $grid.html();
		$grid.addClass('is-editing');
		toggleCardButtons(true);

		if ($.fn.sortable) {
			$grid.sortable({ items: '[data-dn-card]', tolerance: 'pointer' });
		}
	});

	$(document).on('change', '[data-dn-card-visible]', function () {
		$(this).closest('[data-dn-card]').toggleClass('is-hidden', !this.checked);
	});

	$(document).on('click', '[data-dn-cards-cancel]', function () {
		exitCardEdit();
		cardsGrid().html(cardsSnapshot);
	});

	$(document).on('click', '[data-dn-cards-save]', function () {
		var cards = [];

		cardsGrid().find('[data-dn-card]').each(function () {
			if ($(this).find('[data-dn-card-visible]').prop('checked')) {
				cards.push($(this).attr('data-dn-card'));
			}
		});

		post('dn_bfs_save_cards', { cards: cards, cards_sent: 1 }).done(function (response) {
			if (response && response.success) {
				exitCardEdit();
			} else {
				window.alert(errorMessage(response));
			}
		}).fail(function (xhr) {
			window.alert(errorMessage(xhr));
		});
	});

	$(document).on('click', '[data-dn-cards-reset]', function () {
		post('dn_bfs_save_cards', { reset: 1 }).done(function () {
			exitCardEdit();
			loadTab(getCurrentTab(), false);
		});
	});

	$(document).on('click', '[data-dn-online]', function () {
		var $popover = $('[data-dn-online-popover]');

		$popover.prop('hidden', !$popover.prop('hidden'));
		$(this).attr('aria-expanded', String(!$popover.prop('hidden')));
	});

	$(document).on('click', '[data-dn-update-now]', function () {
		var $button = $(this);
		var $panel = $button.closest('[data-dn-status-panel]');
		var $message = $panel.find('[data-dn-status-message]');

		$button.prop('disabled', true);
		$message.text(strings.loading || 'Loading data…');

		post('dn_bfs_update_now').done(function (response) {
			if (!response || !response.success) {
				$message.text(errorMessage(response));
				return;
			}

			$panel.find('[data-dn-last-update]').text(response.data.lastUpdate || '');
			$panel.find('[data-dn-next-update]').text(response.data.nextUpdate || '');
			$message.text(response.data.message || strings.updated || '');
			loadTab(getCurrentTab(), false);
		}).fail(function (xhr) {
			$message.text(errorMessage(xhr));
		}).always(function () {
			$button.prop('disabled', false);
		});
	});

	$(document).on('input', '[data-dn-select-search]', function () {
		var query = $(this).val().toLowerCase();

		$(this).closest('[data-dn-searchable-select]').find('[data-dn-select-list] option').each(function () {
			$(this).prop('hidden', $(this).text().toLowerCase().indexOf(query) === -1);
		});
	});

	var resizeTimer;

	$(window).on('resize', function () {
		window.clearTimeout(resizeTimer);
		resizeTimer = window.setTimeout(initCharts, 120);
	});

	window.addEventListener('popstate', function () {
		var params = new URLSearchParams(window.location.search);

		state.period = params.get('dn_period') || config.period || 'month_to_date';
		state.compare = params.get('dn_compare') || config.compare || 'previous_year';
		state.start = params.get('dn_start') || '';
		state.end = params.get('dn_end') || '';
		state.filters = {};

		params.forEach(function (value, key) {
			var match = key.match(/^dn_filter\[(\w+)\]$/);

			if (match) {
				state.filters[match[1]] = value;
			}
		});

		renderFilterChips();
		loadTab(params.get('dn_tab') || 'overview', false);
	});

	$(function () {
		initCharts();

		if ($('[data-dn-online]').length) {
			refreshOnline();
			window.setInterval(refreshOnline, 30000);
		}
	});
})(jQuery);
```

- [ ] **Step 5: Thêm CSS vào cuối `assets/admin.css`**

```css
/* Native tracking upgrade: filters, online badge, drawer, card editing, settings. */
.dn-burst-filter-bar {
	position: relative;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin: 12px 0;
}

.dn-burst-filter-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.dn-burst-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 4px 2px 10px;
	border: 1px solid #c3c4c7;
	border-radius: 12px;
	background: #f6f7f7;
	font-size: 12px;
}

.dn-burst-chip-remove {
	border: 0;
	background: transparent;
	cursor: pointer;
	font-size: 14px;
	line-height: 1;
	padding: 2px 6px;
}

.dn-burst-filter-popover,
.dn-burst-online-popover {
	position: absolute;
	z-index: 100;
	top: 100%;
	min-width: 260px;
	padding: 12px;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	background: #fff;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
}

.dn-burst-filter-popover label {
	display: block;
	margin-bottom: 8px;
}

.dn-burst-filter-popover label span {
	display: block;
	font-weight: 600;
	margin-bottom: 2px;
}

.dn-burst-online-wrap {
	position: relative;
}

.dn-burst-online-popover {
	right: 0;
}

.dn-burst-online-popover ul {
	margin: 0;
}

.dn-burst-online-dot {
	display: inline-block;
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: #00a32a;
	box-shadow: 0 0 0 3px rgba(0, 163, 42, 0.2);
}

.dn-burst-cards-toolbar {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-bottom: 8px;
}

.dn-burst-card {
	position: relative;
}

.dn-burst-card-toggle {
	display: none;
	position: absolute;
	top: 8px;
	right: 8px;
}

.dn-burst-card.is-hidden {
	display: none;
}

.dn-burst-grid.is-editing .dn-burst-card {
	cursor: move;
	outline: 1px dashed #2271b1;
}

.dn-burst-grid.is-editing .dn-burst-card.is-hidden {
	display: block;
	opacity: 0.45;
}

.dn-burst-grid.is-editing .dn-burst-card-toggle {
	display: block;
}

.dn-burst-change.is-down {
	color: #d63638;
}

.dn-burst-change.is-up {
	color: #008a20;
}

.dn-burst-estimate-note {
	margin: 4px 0 12px;
	color: #646970;
	font-style: italic;
}

.dn-burst-data-table tr.is-drillable {
	cursor: pointer;
}

.dn-burst-data-table tr.is-drillable:hover td,
.dn-burst-data-table tr.is-drillable:focus td {
	background: #f0f6fc;
}

.dn-burst-data-table th.is-sorted a::after {
	content: " \2193";
}

.dn-burst-data-table th.is-sorted.is-asc a::after {
	content: " \2191";
}

.dn-burst-drawer {
	position: fixed;
	z-index: 100000;
	top: 32px;
	right: 0;
	bottom: 0;
	width: min(560px, 100%);
	overflow-y: auto;
	padding: 16px 20px;
	border-left: 1px solid #c3c4c7;
	background: #fff;
	box-shadow: -4px 0 16px rgba(0, 0, 0, 0.12);
}

.dn-burst-drawer-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.dn-burst-drawer-close {
	font-size: 24px;
	text-decoration: none;
}

.dn-burst-drawer-stats {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
	gap: 8px;
	margin: 12px 0;
}

.dn-burst-drawer-stat {
	padding: 8px;
	border: 1px solid #dcdcde;
	border-radius: 4px;
}

.dn-burst-drawer-stat span,
.dn-burst-drawer-stat em {
	display: block;
	font-size: 12px;
	color: #646970;
}

.dn-burst-drawer-stat strong {
	display: block;
	font-size: 18px;
}

.dn-burst-drawer-stat em.is-down {
	color: #d63638;
}

.dn-burst-drawer-stat em.is-up {
	color: #008a20;
}

.dn-burst-settings .dn-burst-panel {
	margin-top: 16px;
}

.dn-burst-checklist label {
	display: inline-block;
	min-width: 180px;
	margin: 0 12px 6px 0;
}

.dn-burst-inline-form {
	display: inline-flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin: 8px 12px 8px 0;
}

.dn-burst-danger-zone {
	border-left: 4px solid #d63638;
}

.dn-burst-status-badge {
	display: inline-block;
	min-width: 64px;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	text-align: center;
	text-transform: uppercase;
}

.dn-burst-status-badge.is-ok {
	background: #edfaef;
	color: #008a20;
}

.dn-burst-status-badge.is-warning {
	background: #fcf9e8;
	color: #996800;
}

.dn-burst-status-badge.is-error {
	background: #fcf0f1;
	color: #d63638;
}

.dn-burst-status-badge.is-info {
	background: #f0f6fc;
	color: #2271b1;
}

@media (max-width: 782px) {
	.dn-burst-drawer {
		top: 46px;
	}
}
```

- [ ] **Step 6: Chạy test** — tích hợp PASS (thêm 4 test); unit + `php74` → OK. Kiểm tra cú pháp JS bằng Node trong Docker:

```bash
docker run --rm -v "$PWD":/app -w /app node:20 node --check assets/admin.js
```

Expected: không in lỗi.

- [ ] **Step 7: Kiểm tra trên trình duyệt** — đăng nhập `http://localhost:8080/wp-admin/` (tài khoản trong `docker/.env.example`). Tạo vài lượt truy cập ở frontend trước (mở trang chủ, một sản phẩm, thêm vào giỏ, đặt đơn COD; mở thêm một tab với `?utm_source=facebook&utm_medium=cpc&utm_campaign=sale-10`). Kiểm tra lần lượt, Console không có lỗi JS:
1. Funnel Stats → Dashboard: 15 thẻ, 4 biểu đồ vẽ được; badge online hiện số; bấm badge → danh sách trang.
2. Đổi khoảng ngày → nội dung tải lại, URL đổi.
3. "Add filter" → Campaign → gõ `sale` thấy gợi ý `sale-10` → Apply → chip hiện, số liệu đổi, URL có `dn_filter[campaign]`; bấm × trên chip → bỏ lọc.
4. Các tab Pages/Sources/Ad URLs/Products/Brands/Countries/Devices: đổi radio, bấm tiêu đề cột để sắp xếp, phân trang (nếu có).
5. Tab Sources → bấm dòng "Paid" → panel trượt hiện tóm tắt + 2 biểu đồ → "Apply as filter" → chip Channel: paid.
6. "Customize cards" → bỏ chọn 2 thẻ, kéo một thẻ lên đầu → "Save cards" → tải lại trang thấy đúng thứ tự; "Reset to default".
7. "Update now" → thông báo "Data refreshed."
8. Settings: đủ 7 tab; lưu tab Anti-spam → "Settings saved."; tab System hiện các dòng trạng thái.

- [ ] **Step 8: Commit**

```bash
git add -A includes assets dn-burst-funnel-stats.php tests
git commit -m "feat(admin): wire the upgraded dashboard and settings, remove the Burst-era screens

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Widget trên Dashboard WordPress

**Files:**
- Create: `includes/admin/dashboard-widget.php`, `assets/dashboard-widget.js`, `tests/integration/test-dashboard-widget.php`
- Modify: `dn-burst-funnel-stats.php` (thêm `'dashboard-widget'`)

**Interfaces:**
- Consumes: `dn_bfs_calculate_date_range()`, `dn_bfs_report_summary()`, `dn_bfs_report_realtime()`, `dn_bfs_admin_permission()`, AJAX `dn_bfs_realtime` (Task 5).
- Produces: `dn_bfs_dashboard_widget_data( $now = null ): array|null` (`online`, `visitors|orders|revenue` → `today`, `yesterday`); widget `dnbfs_overview`; script `dnbfs-dashboard-widget` (chỉ trang `index.php`, localize `dnBfsWidget` = `ajaxUrl`, `nonce`).

- [ ] **Step 1: Viết test fail — `tests/integration/test-dashboard-widget.php`**

```php
<?php

require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/admin-helpers.php';

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
		array( 'jquery' ),
		file_exists( $path ) ? (string) filemtime( $path ) : DN_BURST_FUNNEL_STATS_VERSION,
		true
	);
	wp_localize_script(
		'dnbfs-dashboard-widget',
		'dnBfsWidget',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'dn_bfs_admin' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'dn_bfs_enqueue_dashboard_widget' );
```

- [ ] **Step 3: Tạo `assets/dashboard-widget.js`**

```js
/* Refresh the online-visitor count in the DN Burst Funnel Stats dashboard widget. */
( function ( $ ) {
	var config = window.dnBfsWidget || {};
	var $badge = $( '[data-dnbfs-online]' );

	if ( ! $badge.length || ! config.ajaxUrl ) {
		return;
	}

	function refresh() {
		$.post( config.ajaxUrl, { action: 'dn_bfs_realtime', nonce: config.nonce } ).done( function ( response ) {
			if ( response && response.success && 'number' === typeof response.data.online ) {
				$badge.text( String( response.data.online ) );
			}
		} );
	}

	window.setInterval( refresh, 30000 );
} )( jQuery );
```

- [ ] **Step 4: Nạp module** — thêm `'dashboard-widget'` vào mảng `dn_burst_funnel_stats_load_admin()`.

- [ ] **Step 5: Chạy test** — tích hợp PASS (thêm 3 test); unit + `php74` → OK. `docker run --rm -v "$PWD":/app -w /app node:20 node --check assets/dashboard-widget.js` → không lỗi.

- [ ] **Step 6: Kiểm tra trên trình duyệt** — `http://localhost:8080/wp-admin/` hiện widget "Funnel Stats" với số online và bảng Visitors/Orders/Sales hôm nay so với hôm qua; link "Open the dashboard" mở trang plugin.

- [ ] **Step 7: Commit**

```bash
git add includes/admin/dashboard-widget.php assets/dashboard-widget.js tests/integration/test-dashboard-widget.php dn-burst-funnel-stats.php
git commit -m "feat(admin): add the WordPress dashboard widget with live online count

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Migration schema 6, dọn mã cũ, README, phiên bản 3.0.0

**Files:**
- Create: `tests/integration/test-legacy-migration.php`
- Modify: `dn-burst-funnel-stats.php`, `includes/tracking.php`, `tests/integration/test-schema.php`, `README.MD`

**Interfaces:**
- Produces: `DN_BURST_FUNNEL_STATS_SCHEMA_VERSION = '6'`; `dn_bfs_migrate_legacy_cleanup()` (xóa option `dn_atc_*`, transient `dn_atc_*` và `dn_bfs_*`, `dn_bfs_data_last_changed`, `dn_burst_funnel_stats_last_refresh`, `dn_burst_funnel_stats_url_tracking_settings`; hủy cron `dn_burst_funnel_stats_refresh_cache`); phiên bản plugin `3.0.0`.

- [ ] **Step 1: Viết test fail — `tests/integration/test-legacy-migration.php`**

```php
<?php

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
	'plugin version is 3.0.0 and no legacy tracking helpers remain',
	function () {
		dn_bfs_assert_same( '3.0.0', DN_BURST_FUNNEL_STATS_VERSION );

		foreach ( array( 'dn_bfs_is_ip_excluded', 'dn_bfs_is_bot_request', 'dn_bfs_is_selected_page_request', 'dn_bfs_should_track_request' ) as $function ) {
			dn_bfs_assert_true( ! function_exists( $function ), $function );
		}

		dn_bfs_assert_true( function_exists( 'dn_bfs_should_track_product' ), 'product selection kept' );
	}
);
```

Sửa `tests/integration/test-schema.php`: mọi kỳ vọng phiên bản schema dạng chuỗi cố định đổi thành so với `DN_BURST_FUNNEL_STATS_SCHEMA_VERSION`.

Run tích hợp → Expected: 2 test mới FAIL.

- [ ] **Step 2: Migration** — trong `dn-burst-funnel-stats.php`:
1. `define('DN_BURST_FUNNEL_STATS_SCHEMA_VERSION', '5');` → `'6'`.
2. Thêm hàm ngay trên `dn_burst_funnel_stats_maybe_migrate()`:

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

3. Trong `dn_burst_funnel_stats_maybe_migrate()`: xóa khối tạo option `dn_burst_funnel_stats_url_tracking_settings`; gọi `dn_bfs_migrate_legacy_cleanup();` ngay trước `dn_bfs_install_schema();`.

- [ ] **Step 3: Dọn hàm cũ trong `includes/tracking.php`**

```bash
grep -rn "dn_bfs_is_ip_excluded\|dn_bfs_is_bot_request\|dn_bfs_is_selected_page_request\|dn_bfs_should_track_request" includes dn-burst-funnel-stats.php uninstall.php tests
```

Xóa khỏi `includes/tracking.php` bốn hàm trên (grep chỉ còn thấy định nghĩa của chính chúng). **Giữ** `dn_bfs_should_track_product()`, `dn_bfs_is_bot_user_agent()`, `dn_bfs_get_current_user_agent()`, `dn_bfs_get_client_ip()`.

- [ ] **Step 4: Phiên bản 3.0.0** — trong `dn-burst-funnel-stats.php`: header ` * Version: 2.2.0` → ` * Version: 3.0.0`; `define('DN_BURST_FUNNEL_STATS_VERSION', '2.2.0');` → `'3.0.0'`.

- [ ] **Step 5: Viết lại `README.MD`**

```text
=== DN Burst Funnel Stats ===
Contributors: toshstack.dev
Tags: woocommerce, analytics, funnel, ecommerce, dashboard
Requires at least: 6.5
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-friendly WooCommerce funnel analytics with built-in visitor tracking.

== Description ==

DN Burst Funnel Stats tracks visitors on your store and shows a WooCommerce funnel dashboard inside WordPress. It no longer needs Burst Statistics or any other analytics plugin.

= What it tracks =

* Visitors, sessions, pageviews, new and returning visitors
* Bounce rate, time on site, pages per session, entry and exit pages
* Channels (direct, organic search, social, paid, email, referral), referrers and UTM source / medium / campaign
* Device, browser, operating system, country and city
* Product views, add to cart (always equal to Cart), checkout and orders
* Visitors online right now

= Clean numbers =

* Each visitor counts once per product within five minutes for product views and add to cart
* Reloads of the same page within a few seconds count once
* Bots, excluded IPs and excluded roles are ignored
* Rate limits mark spam sessions and remove them from the reports
* Orders and revenue follow the current WooCommerce order status, net of refunds

= Dashboard =

Funnel Stats → Dashboard shows customizable cards, sales / funnel / conversion / top campaign charts, and tabs for pages, sources, ad URLs, products, brands, countries and devices. Filter the whole dashboard by channel, source, medium, campaign, device or country, and click a row to see its details.

= Settings =

Funnel Stats → Settings has General, Tracking, Anti-spam, WooCommerce, GeoIP, Data and System tabs.

== Requirements ==

* WordPress 6.5 or higher
* PHP 7.4 or higher
* WooCommerce

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/dn-burst-funnel-stats/`.
2. Activate the plugin from the Plugins screen.
3. Open Funnel Stats → Dashboard.
4. Optional: add a free MaxMind license key in Settings → GeoIP for city-level locations.

== Privacy ==

The plugin sets first-party cookies (`dnbfs_vid`, `dnbfs_sid`, `dnbfs_sm`) to recognize visitors and sessions. IP addresses are never stored; only a daily-rotating hash of the IP and browser is kept for spam protection. Raw tracking data is deleted after the retention period set in Settings → Data; daily totals are kept.

== Data and performance ==

Raw hits are aggregated into daily totals every hour. If WP-Cron is disabled, make sure a system cron calls `wp-cron.php`. Settings → System shows the health of tracking, aggregation and GeoIP.

== GitHub Updates ==

The plugin updates from GitHub releases. Create a release with a tag such as `v3.0.1` and attach `dn-burst-funnel-stats.zip`.

== Changelog ==

= 3.0.0 =
* Built-in visitor tracking replaces Burst Statistics.
* Upgraded dashboard with filters, drill-down, customizable cards and online visitors.
* New Settings screen and WordPress dashboard widget.
```

- [ ] **Step 6: Chạy test** — tích hợp PASS (thêm 2 test); unit + `php74` → OK. `curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/wp-admin/` → `302`; `docker compose -f docker/docker-compose.yml logs wordpress --tail 30` → không có PHP Fatal/Warning mới.

- [ ] **Step 7: Commit**

```bash
git add dn-burst-funnel-stats.php includes/tracking.php tests README.MD
git commit -m "chore: migrate to schema 6, remove legacy helpers, release 3.0.0

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Tự kiểm tra kế hoạch so với spec (mục 8 mới)

| Yêu cầu | Task |
|---|---|
| §8.1 PHP + jQuery, không build, admin-ajax nonce, URL đồng bộ, quyền + filter | 1, 5, 7 |
| §8.2 Topbar, badge online + popover, date picker cũ, Data status + Update now | 5, 7 |
| §8.2 Bộ lọc chip + gợi ý giá trị | 5, 7 |
| §8.2 15 thẻ, tùy chỉnh ẩn/hiện + kéo thả (user meta) | 4, 5, 7 |
| §8.2 4 biểu đồ canvas cũ (phễu 5 bước) | 4, 5, 7 |
| §8.2 8 tab với lựa chọn chiều, sắp xếp, phân trang, ghi chú ước tính | 5, 7 |
| §8.2 Panel chi tiết + "Áp làm bộ lọc" | 5, 7 |
| §8.3 Lỗi bộ lọc ngoài thời hạn hiển thị notice | 5 |
| §8.4 Settings 7 tab, gửi đủ khóa, che license, Data tools, GeoIP, System | 2, 3, 6 |
| §8.5 Widget WP Dashboard | 8 |
| §10 Gỡ Burst cũ, schema 6, 3.0.0, README | 7, 9 |
| Hợp đồng từ Kế hoạch 2: kiểm tra tham số (400), khoảng ≤ 731 ngày, `estimated`, tooltip tiền, `wc-refunded`, không lọc giá trị rỗng, cảnh báo hệ thống (độ trễ, lỗi aggregator, GeoIP, engine, file công khai, proxy), lưu license xóa backoff | 1–6 |

Ngoài phạm vi: REST công khai + quản lý API key (Kế hoạch 4, dùng lại `includes/admin/request.php`).

