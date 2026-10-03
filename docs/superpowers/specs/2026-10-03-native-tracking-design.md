# Native Tracking — thay thế Burst Statistics

- Ngày: 2026-10-03
- Phiên bản đích: plugin **3.0.0**, schema **4**
- Trạng thái: Thiết kế đã duyệt (gồm giao diện React)

## 1. Mục tiêu

Plugin tự thu thập toàn bộ dữ liệu analytics, **không đọc bảng `wp_burst_statistics` và không phụ thuộc plugin bên thứ 3** (chỉ còn yêu cầu WooCommerce).

Chỉ số cần có:

| Nhóm | Chỉ số |
|---|---|
| A. Truy cập | Pageviews, Visitors (duy nhất), Sessions, khách mới / quay lại |
| B. Hành vi | Bounce rate, thời gian phiên TB, số trang/phiên, trang vào, trang thoát, thời gian xem trang |
| C. Nguồn | Referrer, UTM source/medium/campaign/content/term, kênh (Direct / Organic Search / Social / Paid / Referral / Email) |
| D. Thiết bị | Device (desktop/mobile/tablet), trình duyệt, hệ điều hành |
| E. Vị trí | Quốc gia, thành phố |
| F. Phễu | Visitors → Product Views → Add To Cart = Cart → Checkout → Orders |
| G. Realtime | Số khách online (hoạt động trong 5 phút), trang đang xem |

Yêu cầu bắt buộc: chống trùng lặp, chống bot, chống spam trang và spam Add To Cart.

### Ngoài phạm vi
- Không chuyển dữ liệu lịch sử từ Burst; tracking bắt đầu từ ngày cài 3.0.0.
- Không chuyển option `dn_atc_*` cũ (định nghĩa đếm đã khác); chúng bị xóa khi nâng cấp.
- Không cookie banner / consent; không gọi API GeoIP bên ngoài.
- REST API công khai chỉ **đọc**; không ghi sự kiện, không webhook.

### Quy mô
10.000–100.000 pageviews/ngày, có thể tăng đột biến khi chạy ads.

## 2. Kiến trúc tổng quan

Cách thu thập **kết hợp**:

- **JS beacon** (`assets/tracker.js`) → `POST /wp-json/dnbfs/v1/collect` cho pageview, thời gian xem, heartbeat realtime. Hoạt động đúng khi có page cache.
- **Hook WooCommerce trên server** cho Add To Cart, Cart, Order — không giả mạo được bằng cách gọi JS.
- Cả hai liên kết với nhau qua cookie `dnbfs_vid` (khách) và `dnbfs_sid` (phiên).

```
Trình duyệt ──pv/ping──▶ /collect ──▶ guard ──▶ sessions / pageviews / events
WooCommerce hooks ─────────────────▶ guard ──▶ events (ATC + Cart, Order)
Cron mỗi giờ: aggregator ──▶ dnbfs_daily
Cron mỗi ngày: cleanup (retention, salt, GeoIP)
Dashboard + REST API công khai ──▶ reports.php ──▶ daily (quá khứ) + bảng thô (hôm nay, realtime)
```

## 3. Database

Tạo bằng `dbDelta` khi kích hoạt và khi `dn_burst_funnel_stats_schema_version` < 4. Tiền tố `{$wpdb->prefix}dnbfs_`. Mọi thời gian lưu dạng epoch UTC (`INT UNSIGNED`); ngày (`date`) trong `dnbfs_daily` là ngày theo múi giờ site.

### `dnbfs_visitors`
| Cột | Kiểu | Ghi chú |
|---|---|---|
| `visitor_uid` | CHAR(32) PK | từ cookie `dnbfs_vid` |
| `first_seen`, `last_seen` | INT UNSIGNED | index `last_seen` |
| `sessions_count` | INT UNSIGNED | |

### `dnbfs_sessions`
| Cột | Ghi chú |
|---|---|
| `id` BIGINT PK AI | |
| `session_uid` CHAR(32) UNIQUE | từ cookie `dnbfs_sid` |
| `visitor_uid` CHAR(32) | index |
| `started_at`, `last_activity` INT UNSIGNED | index cả hai |
| `is_new_visitor` TINYINT | |
| `entry_path`, `exit_path` VARCHAR(255) | |
| `pageviews` SMALLINT, `duration` INT (giây) | |
| `is_bounce` TINYINT | 1 khi phiên chỉ có 1 pageview |
| `referrer_host` VARCHAR(191), `channel` VARCHAR(20) | |
| `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term` VARCHAR(191) | |
| `device` VARCHAR(10), `browser` VARCHAR(40), `os` VARCHAR(40) | |
| `country` CHAR(2), `city` VARCHAR(100) | |
| `ip_hash` CHAR(64) | HMAC(IP, salt ngày); index (`ip_hash`, `started_at`) |
| `is_spam` TINYINT | index |

### `dnbfs_pageviews`
`id` BIGINT PK, `session_id` BIGINT (index), `visitor_uid` CHAR(32), `time` INT (index), `path` VARCHAR(255), `page_type` VARCHAR(12) (`home|product|category|cart|checkout|thankyou|other`), `object_id` BIGINT, `time_on_page` INT.

### `dnbfs_events`
`id` BIGINT PK, `session_id` BIGINT (index), `visitor_uid` CHAR(32), `time` INT (index), `type` VARCHAR(16) (`product_view|add_to_cart|cart|checkout_start|order`), `product_id` BIGINT, `qty` INT, `value` DECIMAL(19,4), `attempts` SMALLINT (số lần gọi ATC bị bỏ qua dồn vào sự kiện này, tối đa 65535), `last_attempt_at` INT (thời điểm lần gọi ATC bị bỏ qua gần nhất; dùng cho giới hạn ATC/phút), `order_id` BIGINT NULL UNIQUE (chỉ điền khi `type=order`; các loại khác để NULL — UNIQUE cho phép nhiều NULL), cùng các cột ghi kèm cho attribution: `channel`, `utm_source`, `utm_medium`, `utm_campaign`, `country`, `device`.

Index thêm: (`visitor_uid`, `product_id`, `type`, `time`) cho quy tắc 5 phút.

Sự kiện `order` **không bao giờ bị xóa** bởi cleanup.

### `dnbfs_daily`
PK (`date`, `dimension`, `dim_hash`). Cột: `date` DATE, `dimension` VARCHAR(16), `dim_value` VARCHAR(255), `dim_hash` CHAR(32) = md5(dim_value), `pageviews`, `visitors`, `sessions`, `new_visitors`, `bounces`, `duration_sum`, `product_views`, `atc`, `carts`, `checkouts`, `orders` (INT UNSIGNED), `revenue` DECIMAL(19,4).

Dimension: `total`, `page`, `entry`, `exit`, `product`, `channel`, `source`, `medium`, `campaign`, `referrer`, `device`, `browser`, `os`, `country`, `city`, `blocked` (dim_value = lý do chặn, dùng cột `pageviews` làm bộ đếm).

### `dnbfs_api_keys`
`id`, `name`, `prefix` CHAR(8) UNIQUE, `key_hash` CHAR(64), `scopes` VARCHAR(191) (CSV), `allowed_ips` TEXT (CIDR, mỗi dòng một rule), `rate_limit` SMALLINT, `last_used_at`, `created_at`, `revoked_at` INT.

## 4. Thu thập dữ liệu

### 4.1 Tracker JS
- File `assets/tracker.js`, không phụ thuộc jQuery, nạp `defer` trên frontend (không nạp trong admin, preview, customizer, và không nạp cho người dùng đăng nhập có vai trò bị loại trừ).
- PHP in `window.dnbfsPage = {type, id, endpoint, tz, timeout, cookieDays}` (inline trước script; `tz` = độ lệch múi giờ site tính bằng phút, `timeout` = phút hết phiên, `cookieDays` = số ngày của cookie khách) dựa trên `is_front_page`, `is_product`, `is_product_category|is_shop`, `is_cart`, `is_checkout && !is_order_received_page`, `is_order_received_page`.
- Cookie (`path=/`, `SameSite=Lax`, `Secure` khi HTTPS):
  - `dnbfs_vid`: 32 hex ngẫu nhiên (`crypto.getRandomValues`), 1 năm.
  - `dnbfs_sid`: 32 hex, hết hạn sau 30 phút không hoạt động. Phiên mới khi: hết 30 phút, sang ngày mới (múi giờ site, truyền qua `dnbfsPage.tz`), hoặc `utm_campaign` trên URL khác campaign của phiên hiện tại. Ngày và campaign của phiên lưu trong cookie `dnbfs_sm` dạng `Y-m-d~campaign` (tách ở dấu `~` đầu tiên; campaign có thể chứa `~`).
  - Cookie không đặt `domain`, không `HttpOnly` (JS cần đọc); server dùng cùng định dạng.
- Không gửi gì khi `navigator.webdriver === true` hoặc khi `document.visibilityState === 'prerender'`/trang chưa từng hiển thị (chờ `visibilitychange` sang `visible`).
- Hit `pv` (khi trang hiển thị lần đầu): `{t:'pv', vid, sid, path, query, ref, ptype, pid, sw}`. Tracker gửi toàn bộ query string (tối đa 1024 ký tự sau khi server cắt); server chỉ lưu các trường suy ra từ UTM (`utm_*`, kênh), không lưu query thô.
- Hit `ping`: mỗi 30 giây khi tab đang hiển thị và khi `visibilitychange→hidden`; `pagehide` chỉ gửi ping nếu trang vẫn đang hiển thị (tránh gửi hai lần). Mang `{t:'ping', vid, sid, pvid, engaged}`; `engaged` = giây thực sự hiển thị, tối đa 1800. `pvid` là id pageview trả về từ hit `pv` (khi `sendBeacon` không đọc được phản hồi thì hit `pv` dùng `fetch(..., {keepalive:true})`).
- Gửi bằng `navigator.sendBeacon`/`fetch keepalive`, body `text/plain` chứa JSON (tránh preflight).

### 4.2 Endpoint `/collect`
1. Chạy guard (mục 5). Bị chặn → tăng bộ đếm `blocked` theo lý do, trả `204`.
2. Phân tích user-agent (`ua-parser.php`) → device/browser/os.
3. Geo (`geo.php`): `HTTP_CF_IPCOUNTRY` (+ `HTTP_CF_IPCITY` nếu có); không có thì tra GeoLite2-City `.mmdb` (nếu đã cài). Giá trị không xác định: `country = ''`.
4. Kênh (`channel.php`): ưu tiên UTM (`utm_medium` ∈ cpc/ppc/paid* → Paid; email → Email; social → Social), sau đó referrer host theo danh sách công cụ tìm kiếm / mạng xã hội; referrer cùng host hoặc rỗng → Direct; còn lại → Referral.
5. `pv`: upsert visitor (`is_new_visitor` = chưa có trong `dnbfs_visitors`), tạo/cập nhật session (`last_activity`, `exit_path`, `pageviews`, `is_bounce`), insert pageview, trả `{pvid}`.
   - `ptype=product` → ghi sự kiện `product_view` (qua quy tắc 5 phút).
   - `ptype=checkout` → ghi `checkout_start`, tối đa 1 lần/phiên.
6. `ping`: cập nhật `time_on_page` của pageview (chỉ tăng, không giảm), `duration` và `last_activity` của session.

### 4.3 Hook WooCommerce (`wc-events.php`)
- `woocommerce_add_to_cart`: đọc cookie `dnbfs_vid`/`dnbfs_sid` của request; nếu thiếu thì tạo mới và set cookie (`path=/`, không `domain`). Khi server tạo `dnbfs_sid` mới thì cũng set `dnbfs_sm` = `<ngày site>~<utm_campaign của hit>` để tracker tiếp tục cùng phiên ở trang sau. Request thường (không AJAX/REST/`wc-ajax`) lấy path + query từ `REQUEST_URI` (giữ UTM của landing `?add-to-cart=ID&utm_*`) và dùng `HTTP_REFERER` làm referrer khi khác host; request AJAX/REST lấy path + query từ referer cùng site. Qua guard + quy tắc 5 phút → ghi **đồng thời** sự kiện `add_to_cart` và `cart` (cùng `product_id`, `qty`, `value = giá × qty`) trong một lần gọi. Nếu bị bỏ qua do quy tắc 5 phút: cộng `qty` vào sự kiện đã tính gần nhất, không tạo dòng mới, không ghi `cart`.
- **Cart trên phễu luôn bằng Add To Cart.** Pageview của trang `/cart` vẫn được lưu cho báo cáo trang nhưng không dùng cho chỉ số Cart.
- `woocommerce_checkout_order_processed` và `woocommerce_store_api_checkout_order_processed`: ghi sự kiện `order` (`order_id` duy nhất) kèm attribution của phiên (không có phiên hợp lệ → attribution WooCommerce `_wc_order_attribution_*`, `session_id = 0`, không tạo phiên); chỉ sau khi sự kiện đã được lưu (hoặc đã tồn tại theo `order_id`) mới lưu meta `_dnbfs_session_uid` và `_dnbfs_visitor_uid` vào đơn. Đơn hàng là doanh thu đã được server xác nhận nên **không** qua bộ lọc bot/UA rỗng; chỉ áp dụng tắt tracking, vai trò loại trừ, IP loại trừ.
- Doanh thu/số đơn khi tổng hợp lấy theo **trạng thái hiện tại** của đơn: loại `cancelled`, `failed`, `checkout-draft`; trừ tiền hoàn (`get_total_refunded`).
- Tùy chọn Settings **"Ép chuyển đến trang Cart sau khi thêm vào giỏ"**: khi bật, filter `pre_option_woocommerce_cart_redirect_after_add` → `yes` và `pre_option_woocommerce_enable_ajax_add_to_cart` → `no`.

## 5. Chống trùng lặp, bot, spam (`guard.php`)

Lọc theo thứ tự, dừng ở lớp đầu tiên chặn.

### Lớp 1 — Hợp lệ request (chỉ áp dụng cho `/collect`)
- Chỉ `POST`; body ≤ 4096 byte; JSON hợp lệ; `vid`, `sid` khớp `/^[a-f0-9]{32}$/`; `path` bắt đầu bằng `/`, ≤ 255 ký tự.
- `Origin` hoặc `Referer` phải trùng host của `home_url()`; thiếu cả hai → chặn.
- Không dùng nonce (trang cache sẽ mang nonce hết hạn).

### Lớp 2 — Loại trừ và bot (cả `/collect` và hook WC; đơn hàng chỉ áp dụng loại trừ vai trò/IP, không áp dụng lọc bot/UA rỗng)
- Người dùng đăng nhập có vai trò thuộc danh sách loại trừ (mặc định `administrator`, `shop_manager`).
- IP thuộc danh sách loại trừ (giữ logic CIDR hiện có trong `tracking.php`).
- User-agent rỗng, chứa từ khóa bot mặc định (danh sách hiện có + `headlesschrome`, `phantomjs`, `puppeteer`, `selenium`, `lighthouse`, `ptst`, `python-requests`, `curl`, `wget`, `go-http-client`, `axios`, `node-fetch`) hoặc từ khóa tự thêm.
- IP client lấy theo setting `client_ip_source` (mặc định `auto`) để giới hạn theo IP không bị gộp sau Cloudflare/proxy.

### Lớp 3 — Chống trùng lặp
| Đối tượng | Quy tắc |
|---|---|
| Pageview | Cùng session + cùng path trong 10 giây → không tạo pageview mới (trả `pvid` cũ) |
| Product view | Mỗi **visitor × product**: tính 1 lần; xem lại trong **5 phút** kể từ lần được tính → bỏ qua. Sản phẩm khác được tính ngay |
| Add To Cart (+ Cart) | Mỗi **visitor × product**: tính 1 lần; thêm lại trong **5 phút** → bỏ qua (chỉ cộng qty). Sản phẩm khác được tính ngay |
| Checkout | 1 lần / session |
| Order | Duy nhất theo `order_id` |

Cửa sổ 5 phút kiểm tra theo `visitor_uid` **hoặc** `ip_hash` (cùng ngày) để chặn bot xóa cookie. Cửa sổ 5 phút chỉnh được trong Settings.

### Lớp 4 — Giới hạn tần suất (mặc định, chỉnh được trong Settings)
| Giới hạn | Khi vượt |
|---|---|
| 60 pageview / phút / session | chặn + `is_spam=1` cho session |
| 20 session mới / giờ / `ip_hash` (IP + user-agent) | chặn phiên vượt ngưỡng (không đánh dấu spam các phiên trước đó) |
| 20 lần gọi Add To Cart / phút / visitor (kể cả lần bị bỏ qua) | chặn + `is_spam=1` |
| 300 pageview / session | chặn + `is_spam=1` |

Bộ đếm truy vấn trực tiếp trên bảng thô (có index thời gian); số lần gọi ATC bị bỏ qua được cộng vào cột `attempts` của sự kiện ATC đã tính gần nhất, nên giới hạn ATC/phút = số sự kiện ATC trong 60 giây + tổng `attempts` của chúng.

Session `is_spam=1` bị loại khỏi mọi thống kê, kể cả các lượt đã ghi trước khi bị đánh dấu; ngày của session đó được đánh dấu cần tổng hợp lại.

IP gốc không bao giờ được lưu; `ip_hash = hash_hmac( 'sha256', ip . '|' . user_agent, salt_ngày )`; salt lưu trong option `dnbfs_salt_{Y-m-d}`, đổi mỗi ngày.

## 6. Tổng hợp và dọn dẹp

### Aggregator (cron `dnbfs_aggregate`, mỗi giờ)
- Xử lý các ngày từ `dnbfs_last_aggregated_date + 1` đến hôm qua, cộng các ngày trong option `dnbfs_dirty_dates`.
- Khóa bằng option `dnbfs_aggregate_lock` (hết hạn 10 phút).
- Với mỗi ngày D: `DELETE` các dòng của D trong `dnbfs_daily`, rồi `INSERT … SELECT … GROUP BY` cho từng dimension, loại session `is_spam=1`. Số đơn/doanh thu join trạng thái đơn hiện tại (HPOS hoặc posts, dùng `wc_get_orders` theo lô id nếu cần).
- Đánh dấu ngày cần tổng hợp lại khi: `woocommerce_order_status_changed`, `woocommerce_order_refunded` (ngày tạo đơn); session bị đánh dấu spam sau khi ngày đã tổng hợp.
- Ngày nằm ngoài thời hạn dữ liệu thô: chỉ tính lại các cột đơn hàng/doanh thu từ sự kiện `order` (có attribution ghi kèm); các cột traffic giữ nguyên.

### Cleanup (cron `dnbfs_cleanup`, mỗi ngày)
- Xóa sessions, pageviews, events (trừ `order`) cũ hơn **N ngày** (Settings, mặc định 90), theo lô 5.000 dòng.
- Xóa visitors có `last_seen` > 13 tháng.
- Xóa salt cũ hơn 2 ngày.
- Mỗi 30 ngày: tải GeoLite2-City bằng license key (nếu có) vào `wp-content/uploads/dnbfs/GeoLite2-City.mmdb`, kiểm tra checksum, thay thế nguyên tử.

### Gỡ cài đặt
Drop các bảng `dnbfs_*`; xóa option `dn_burst_funnel_stats_*`, `dnbfs_*`, `dn_bfs_*`, `dn_atc_*` và transient liên quan; hủy cron; xóa thư mục `uploads/dnbfs`.

## 7. Lớp báo cáo (`reports.php`)

API PHP duy nhất cho dashboard và REST:
- Mọi hàm nhận thêm `$filters` (mảng `dimension => value`, xem 8.3).
- `dn_bfs_report_summary( $range, $filters )` → tổng các chỉ số + kỳ so sánh + % thay đổi.
- `dn_bfs_report_timeseries( $range, $metrics, $filters )`.
- `dn_bfs_report_breakdown( $range, $dimension, $filters, $orderby, $order, $limit, $offset )`.
- `dn_bfs_report_funnel( $range, $filters )`.
- `dn_bfs_report_realtime()`.

Nguồn: `dnbfs_daily` cho ngày đã qua; bảng thô cho hôm nay (cache 60 giây); realtime không cache. Visitors cho khoảng nhiều ngày: nếu toàn bộ khoảng còn trong thời hạn dữ liệu thô → `COUNT(DISTINCT visitor_uid)` chính xác; nếu không → cộng theo ngày và trả cờ `estimated = true`.

Định nghĩa chỉ số WooCommerce (sửa lỗi bản cũ; tập trạng thái là giá trị mặc định, chỉnh được ở Settings → WooCommerce):
- Sales = tổng đơn được tính (loại cancelled/failed/checkout-draft) trừ hoàn tiền.
- Paid = đơn `processing` + `completed`. Balance = đơn `pending` + `on-hold` (không cộng vào Paid).
- Conversion rate = Orders / Visitors.

## 8. Giao diện admin (React)

### 8.1 Công nghệ
- React + `@wordpress/components` + `@wordpress/data` + `@wordpress/api-fetch` + Chart.js; build bằng `@wordpress/scripts`.
- Mã nguồn `src/admin/`, bundle ra `build/` (commit vào repo; cài plugin không cần Node). Nạp bằng `build/*.asset.php` (dependencies + version).
- Máy dev không có Node: build chạy trong container `node:20` (`docker compose run --rm node npm run build`).
- Mỗi trang admin chỉ in `<div id="dnbfs-app" data-page="dashboard|settings">`. React đồng bộ trạng thái lên URL (`tab`, `period`, `compare`, `start`, `end`, `filter`) để có thể đánh dấu/chia sẻ link.
- Dữ liệu qua **REST nội bộ** `dnbfs/v1/admin/*` (cookie + nonce `wp_rest`), quyền `manage_options` (filter `dn_bfs_capability`). Dùng chung `reports.php` với API công khai.
- Bỏ `assets/admin.js`, `assets/admin.css`, `includes/ajax.php` và phần render HTML của `dashboard.php`.
- Menu admin chỉ còn **Funnel Stats → Dashboard** và **Settings** (Import/Export gộp vào Settings → Dữ liệu; bỏ trang URL Tracking riêng — đã có tab Ad URLs).

### 8.2 Dashboard — bố cục tab ngang
- **Thanh trên**: date picker (11 preset + custom) và chọn so sánh; **bộ lọc chung** dạng chip (`Kênh`, `Source`, `Medium`, `Campaign`, `Thiết bị`, `Quốc gia`; ví dụ `Campaign: sale-10 ×`); badge số khách online (bấm mở panel realtime: số online, trang đang xem, nguồn); nút làm mới.
- **Overview**:
  - Lưới thẻ: Visitors, Pageviews, Sessions, Khách mới/quay lại, Bounce rate, Thời gian phiên TB, Trang/phiên, Product Views, Add To Cart (= Cart), Checkout, Orders/AOV, Items/AOI, Conversion Rate, Sales/Tip, Paid/Balance. Mỗi thẻ có giá trị kỳ so sánh và % thay đổi.
  - Nút **Tùy chỉnh**: ẩn/hiện và kéo thả sắp xếp thẻ; lưu vào user meta `dnbfs_cards` (mỗi admin một cấu hình) qua `POST admin/preferences`; có "Khôi phục mặc định".
  - Biểu đồ Sales/Orders theo ngày (2 trục), phễu Visitors → Product Views → ATC = Cart → Checkout → Orders, top 10 trang, top 10 campaign.
- **Tab**: Pages (trang / trang vào / trang thoát), Sources (kênh / referrer / source / medium), Ad URLs (campaign / source / medium kèm phễu + doanh thu + CR), Products (views, ATC, tỷ lệ view→ATC, đơn, doanh thu), Devices (thiết bị / trình duyệt / HĐH), Locations (quốc gia / thành phố), Brands. Bảng sắp xếp được, phân trang phía server (25 dòng/trang).
- **Drill-down**: bấm một dòng → panel trượt bên phải với biểu đồ theo ngày, phễu riêng, top nguồn, top thiết bị. Panel = bộ lọc chung áp riêng cho dòng đó; nút "Áp làm bộ lọc" áp cho toàn dashboard.
- Trạng thái tải (skeleton), trạng thái rỗng, lỗi có nút Thử lại; giá trị `estimated` hiển thị ghi chú "ước tính".

### 8.3 Ngữ nghĩa bộ lọc chung
`dnbfs_daily` chỉ lưu từng dimension riêng lẻ, nên:
- **0 hoặc 1 bộ lọc** + summary/timeseries/funnel → đọc `dnbfs_daily` (dòng của dimension được lọc), dùng được mọi khoảng thời gian.
- **≥ 2 bộ lọc**, hoặc **bộ lọc + breakdown theo dimension khác** → truy vấn bảng thô; chỉ hợp lệ khi toàn bộ khoảng nằm trong thời hạn dữ liệu thô. Ngoài thời hạn: API trả `422 filter_out_of_retention` cho chỉ số traffic; chỉ số đơn hàng/doanh thu vẫn tính được từ sự kiện `order` (attribution ghi kèm). Giao diện hiển thị thông báo giải thích.
- Bộ lọc là tham số `filter[dimension]=value` trên mọi endpoint báo cáo (nội bộ và công khai).

### 8.4 Settings — danh sách nhóm dọc
Mỗi nhóm một form riêng, nút Lưu riêng, validate tức thì, cảnh báo khi rời trang chưa lưu. Lưu qua `POST admin/settings/{group}`.

| Nhóm | Cấu hình |
|---|---|
| Chung | Bật/tắt tracking, khoảng ngày mặc định, so sánh mặc định |
| Tracking | Vai trò loại trừ, IP/CIDR loại trừ (báo dòng không hợp lệ), chế độ trang (tất cả/chọn) + chọn trang, chế độ sản phẩm (tất cả/chọn) + chọn sản phẩm, thời gian hết phiên (mặc định 30 phút), thời hạn cookie (mặc định 365 ngày), nguồn IP client (`auto` = CF-Connecting-IP khi có CF-Ray, `remote_addr`, `x_forwarded_for`, `x_real_ip`) |
| Chống spam | Cửa sổ chống trùng sản phẩm (5 phút), bỏ qua F5 (10 giây), 4 ngưỡng giới hạn tần suất, từ khóa bot tự thêm, chặn UA rỗng, biểu đồ lượt bị chặn theo lý do 7 ngày |
| WooCommerce | Ép chuyển đến trang Cart, trạng thái đơn tính Sales / Paid / Balance, từ khóa phí Tip |
| GeoIP | Ưu tiên Cloudflare, license key MaxMind, trạng thái file (ngày cập nhật, dung lượng), nút "Cập nhật ngay" |
| Dữ liệu | Số ngày giữ dữ liệu thô, tổng hợp lại khoảng ngày, dung lượng từng bảng, Export/Import Settings (JSON), xóa toàn bộ dữ liệu (gõ `DELETE` để xác nhận) |
| API keys | Danh sách (tên, prefix, quyền, lần dùng cuối, trạng thái), tạo key trong modal (key hiện một lần + nút sao chép), sửa tên/quyền/IP/giới hạn, thu hồi; link `openapi.json` |
| Hệ thống | Đèn xanh/vàng/đỏ: bảng đủ và đúng schema, cron aggregate/cleanup chạy trong 2 giờ/2 ngày gần nhất, test `/collect` (gọi loopback), tracker có trong HTML trang chủ, GeoIP sẵn sàng, phiên bản plugin/WC/PHP/MySQL |

### 8.5 Widget trên WP Dashboard
- `wp_add_dashboard_widget`, render PHP (không nạp bundle React), chỉ hiện với người có quyền.
- Nội dung: khách online, Visitors / Orders / Sales hôm nay so với hôm qua, link mở dashboard. Số online tự làm mới 30 giây qua `fetch` `admin/realtime`.

### 8.6 REST nội bộ `dnbfs/v1/admin/*`
`GET summary`, `GET timeseries`, `GET breakdown`, `GET funnel`, `GET realtime`, `GET filters/values?dimension=&search=` (gợi ý giá trị cho chip lọc), `GET|POST preferences`, `GET|POST settings/{group}`, `GET|POST|PATCH|DELETE api-keys`, `POST data/reaggregate`, `POST data/purge`, `GET data/export`, `POST data/import`, `POST geoip/update`, `GET system/status`.

## 9. REST API công khai

Namespace `/wp-json/dnbfs/v1/`, chỉ GET, dành cho gọi server-to-server (ví dụ NestJS).

- Xác thực: `Authorization: Bearer <key>` hoặc `X-DNBFS-Key: <key>`. Key dạng `dnbfs_<prefix8>_<secret32>`; chỉ hiện một lần; lưu `prefix` + `hash_hmac('sha256', key, wp_salt('auth'))`; so sánh bằng `hash_equals`.
- Bắt buộc HTTPS trừ host localhost / `*.test` / `*.localhost`. Không gửi header CORS.
- Giới hạn tần suất theo key (mặc định 60/phút) → `429` + `Retry-After`. Kết quả cache 60 giây theo (endpoint, tham số).
- Mỗi request hợp lệ cập nhật `last_used_at` (tối đa 1 lần/phút).

| Endpoint | Quyền |
|---|---|
| `GET /meta` | key hợp lệ bất kỳ |
| `GET /stats/summary?start&end&compare=none\|previous_period\|previous_year` | `stats:read` |
| `GET /stats/timeseries?start&end&metrics=a,b` | `stats:read` |
| `GET /stats/breakdown?dimension&start&end&orderby&order&limit(≤500)&page` | `stats:read` |
| `GET /stats/funnel?start&end` | `stats:read` |
| `GET /stats/realtime` | `realtime:read` |
| `GET /openapi.json` | key hợp lệ bất kỳ |

- Ngày `YYYY-MM-DD` theo múi giờ site; khoảng tối đa 366 ngày.
- Phản hồi: `{ "data": …, "meta": { "timezone", "currency", "range": {start,end}, "estimated": bool } }`.
- Lỗi: `401` key sai/thu hồi, `403` thiếu quyền hoặc IP không được phép, `422` tham số sai, `429` vượt giới hạn. Body lỗi: `{ "code", "message" }`.
- README có ví dụ NestJS (`HttpService`, `Authorization: Bearer`).

## 10. Gỡ Burst và nâng cấp

- Bỏ `dn_burst_funnel_stats_is_burst_pro_active` và mọi kiểm tra Burst; header chỉ `Requires Plugins: woocommerce`.
- Bỏ submenu dưới menu `burst`, bỏ mọi truy vấn `wp_burst_statistics` và các hàm `dn_burst_dash_burst_*`, `dn_burst_dash_get_atc_*`.
- Migration schema 4: tạo bảng, thêm giá trị mặc định cho Settings mới, xóa option `dn_atc_*` và transient `dn_atc_*`, lên lịch cron `dnbfs_aggregate` và `dnbfs_cleanup`, hủy cron `dn_burst_funnel_stats_refresh_cache` cũ.
- Version 3.0.0 ở header và hằng số; cập nhật README (bỏ Burst, mô tả tracking, cookie, API).

## 11. Cấu trúc file

Giữ phong cách hàm có tiền tố như code hiện tại (`dn_bfs_`).

```
dn-burst-funnel-stats.php
includes/
  tracking.php            (settings tracking, IP/CIDR, bot keywords — mở rộng)
  tracking/schema.php
  tracking/context.php    (ngữ cảnh request: now, host, ip_hash, roles)
  tracking/store.php      (ghi DB, chống trùng, giới hạn, đánh dấu spam)
  tracking/collector.php
  tracking/guard.php
  tracking/ua-parser.php
  tracking/geo.php
  tracking/channel.php
  tracking/wc-events.php
  tracking/aggregator.php
  tracking/cleanup.php
  reports.php
  settings.php            (schema + sanitize cho 8 nhóm, không còn render HTML)
  api/auth.php
  api/routes.php          (REST công khai)
  api/openapi.php
  admin/routes.php        (REST nội bộ)
  admin/pages.php         (menu, nạp bundle, div mount)
  admin/dashboard-widget.php
  date-ranges.php
  class-github-updater.php
lib/maxmind-db/           (MaxMind\Db\Reader thuần PHP, Apache-2.0)
src/admin/                (React: dashboard/, settings/, components/, store/, utils/)
build/                    (bundle đã build, commit)
assets/tracker.js
package.json, composer.json, phpunit.xml.dist
tests/php/, src/admin/**/__tests__/
docker/
```

Import/Export (Settings → Dữ liệu): xuất/nhập Settings (không gồm API key); không xuất dữ liệu thô. Xóa `includes/ajax.php`, `includes/admin-menu.php`, `includes/dashboard.php`, `includes/import-export.php`, `assets/admin.js`, `assets/admin.css` sau khi phần thay thế hoàn tất.

## 12. Môi trường test bằng Docker

Máy dev không có PHP/Composer, nên mọi thứ chạy trong container.

`docker/docker-compose.yml`:
- `db`: MariaDB 11.
- `wordpress`: image `wordpress:php8.2-apache`, mount plugin vào `/var/www/html/wp-content/plugins/dn-burst-funnel-stats`, cổng `8080`.
- `wpcli`: image `wordpress:cli`, cùng volume.
- `phpunit`: image `composer` + PHP 8.2, chạy `composer install` và `vendor/bin/phpunit` trên thư mục plugin.
- `node`: image `node:20`, chạy `npm ci`, `npm run build`, `npm test` (Jest qua `@wordpress/scripts`).

`docker/setup.sh` (idempotent): cài WordPress (`http://localhost:8080`), cài + kích hoạt WooCommerce, bật permalink đẹp, tạo trang shop/cart/checkout, tạo 10 sản phẩm mẫu, bật thanh toán COD, kích hoạt plugin. Tài khoản admin test ghi trong `docker/.env.example`.

## 13. Kiểm thử

### PHPUnit (không cần WordPress — hàm thuần, WP functions được stub tối thiểu)
- UA parser: bộ user-agent mẫu desktop/mobile/tablet/bot.
- Channel: các tổ hợp UTM + referrer.
- Guard: hợp lệ request, bot keywords, cửa sổ 5 phút (visitor/ip_hash), các ngưỡng giới hạn.
- IP/CIDR (IPv4, IPv6).
- Date ranges và chia ngày theo múi giờ.
- API auth: định dạng key, hash, so sánh, scope.

### Jest (giao diện)
- Định dạng số/tiền/phần trăm, mã hóa và giải mã bộ lọc + khoảng ngày trên URL, logic ẩn/hiện + sắp xếp thẻ, validate form Settings.

### Tích hợp trên Docker (checklist thủ công + script curl)
1. Tải 1 trang → 1 visitor, 1 session, 1 pageview; F5 liên tục trong 10 giây → vẫn 1 pageview.
2. Xem sản phẩm A 3 lần trong 5 phút → 1 product view; xem B → +1; sau 5 phút xem A → +1.
3. Add To Cart A 5 lần trong 5 phút → ATC = 1, Cart = 1; ATC B → 2/2; sau 5 phút ATC A → 3/3.
4. Spam ATC > 20 lần/phút → session spam, biến mất khỏi thống kê.
5. `curl` gọi `/collect` không có Origin, hoặc với UA bot → bị chặn, bộ đếm `blocked` tăng.
6. Bật page cache (plugin cache đơn giản) → pageview vẫn được ghi.
7. Đặt đơn → Orders/Revenue đúng; hủy/hoàn tiền đơn của hôm qua → chạy aggregator → doanh thu ngày đó cập nhật.
8. Tắt cron 3 ngày (giả lập bằng option) → chạy aggregator → bù đủ ngày.
9. REST API: key sai → 401; thiếu scope → 403; vượt 60/phút → 429; summary khớp số dashboard.
10. Bật "Ép chuyển đến trang Cart" → thêm vào giỏ ở trang danh mục chuyển đến `/cart`.
11. Dashboard: đổi khoảng ngày, thêm/xóa chip lọc, drill-down, tùy chỉnh thẻ (reload vẫn giữ), URL chia sẻ mở đúng trạng thái.
12. Settings: lưu từng nhóm, validate lỗi, cảnh báo rời trang, tạo/thu hồi API key, trang Hệ thống toàn xanh.
13. Widget WP Dashboard hiển thị đúng và tự làm mới số online.
