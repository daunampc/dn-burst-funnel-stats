=== DN Burst Funnel Stats ===
Contributors: toshstack.dev
Tags: woocommerce, analytics, funnel, ecommerce, dashboard
Requires at least: 6.5
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 3.1.3
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

Funnel Stats → Settings has General, Tracking, Anti-spam, WooCommerce, GeoIP, Data, System and API tabs.

= Public REST API =

Read-only statistics for your own servers, for example a NestJS backend. Create a key in Funnel Stats → Settings → API. The full key is shown once; only a hash is stored.

* Base URL: `https://your-store.example/wp-json/dnbfs/v1`
* Authentication: `Authorization: Bearer dnbfs_xxxxxxxx_…` or `X-DNBFS-Key: dnbfs_xxxxxxxx_…` (use the second header if your web server strips `Authorization`). A Bearer token is only read as the key when it starts with `dnbfs_`; otherwise the `X-DNBFS-Key` header is used.
* HTTPS only, based on the site address (`home_url()`). Sites whose address is on `localhost`, `127.0.0.1`, `::1`, `*.test` or `*.localhost` are exempt for development (filter `dn_bfs_api_require_https`). Behind a proxy or load balancer that terminates TLS, make WordPress see HTTPS, for example in `wp-config.php`: `if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) { $_SERVER['HTTPS'] = 'on'; }`. Only do this when your proxy always sets or overwrites `X-Forwarded-Proto` and the origin server cannot be reached directly; otherwise any client can send the header and claim HTTPS.
* No CORS headers are sent: call the API from a server, never from a browser.
* Each key has scopes (`stats:read`, `realtime:read`), optional allowed IPs (one IP or CIDR range per line) and a rate limit (default 60 requests per minute, counted atomically per key). Over the limit the API answers 429 with a `Retry-After` header.
* The IP allow-list uses the connection address (`REMOTE_ADDR`). `CF-Connecting-IP` is trusted only when the connection comes from a Cloudflare range (filter `dn_bfs_api_cloudflare_ranges`). `X-Forwarded-For` is trusted only when `REMOTE_ADDR` is a trusted proxy (filter `dn_bfs_api_trusted_proxies`, empty by default); the list is read right to left, skipping trusted proxies and Cloudflare addresses, and the first other address is used. This is independent of the "Client IP source" tracking setting.
* The allow-list trusts the client address the web server passes to PHP. If Apache `mod_remoteip`, nginx `real_ip_header` or a similar module rewrites `REMOTE_ADDR` from `X-Forwarded-For` (the official `wordpress` Docker image does this for private ranges), a client may be able to choose its address; limit those modules to your real proxies.
* Keys are hashed with the WordPress `AUTH_SALT`/`AUTH_KEY` salts. Changing the salts (some security plugins rotate them) invalidates every API key: create new keys afterwards.
* With a persistent object cache (Redis, Memcached), successful statistics are cached for 60 seconds. Without one they are not cached as whole responses, so the database is not filled with one cache row per query; today's raw-data figures are still cached for 60 seconds, and expired cache rows are removed every hour. Realtime is never cached.

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

`estimated: true` means visitor counts were summed day by day because raw data for the whole range is no longer kept. Errors look like `{"code": "invalid_date", "message": "…"}` with status 401 (missing, invalid or revoked key), 403 (missing scope, IP not allowed or HTTPS required), 422 (invalid parameters) or 429 (rate limited). Unknown routes and methods other than GET get the standard WordPress 404 body (`{"code": "rest_no_route", "message": "…", "data": {"status": 404}}`), not the `{code, message}` shape.

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
          this.http.get(`${this.config.getOrThrow<string>('DNBFS_URL')}/wp-json/dnbfs/v1/stats/summary`, {
            params: { start, end, compare: 'previous_period', 'filter[channel]': 'paid' },
            headers: { Authorization: `Bearer ${this.config.getOrThrow<string>('DNBFS_API_KEY')}` },
            timeout: 10000,
          }),
        );

        // data.data.current.revenue, data.meta.currency, data.meta.estimated …
        return data;
      }
    }

On a 429 response, wait for the number of seconds in the `Retry-After` header before retrying.

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

Raw hits are aggregated into daily totals every hour. If WP-Cron is disabled, make sure a system cron calls `wp-cron.php`. Settings → System shows the health of tracking, aggregation, GeoIP and the public REST API.

== GitHub Updates ==

The plugin updates from GitHub releases. Create a release with a tag such as `v3.0.1` and attach the plugin zip (any `.zip` asset works; its top-level folder must be `dn-burst-funnel-stats/`).

== Changelog ==

= 3.1.3 =
* Order metrics (Orders / AOV, Items / AOI, Sales / Tip, Paid / Balance, Conversion) now count every WooCommerce order: orders placed before the plugin was installed and orders created outside the checkout (admin, REST API, mobile app, payment gateways or express checkouts that skip the checkout hooks) are recorded too, and they follow the dashboard filters and breakdowns.
* Past orders are imported automatically once in the background after the update (WP-Cron, about 20 seconds of work per minute until done); daily totals are rebuilt for the affected days, including days older than the raw data retention. An hourly check also records orders of the last 3 days that are still untracked 15 minutes after they were created. Orders placed at checkout by an excluded role or IP are not imported (this is remembered for orders placed from this version on; older orders cannot be told apart and are imported).
* Better attribution for orders without a tracked session, from WooCommerce order attribution: the source type maps to the channel (UTM tags, Organic search, Referral / Social by referrer, Direct for typed-in visits, a new Admin channel for orders created in wp-admin or the mobile app), the referrer host becomes the source for organic and referral orders, Google Ads and other ad click ids on the landing URL count as paid (with gad_campaignid as the campaign), and the WooCommerce device type fills the device. If a tracked session turns up later for an imported order, its attribution replaces the fallback.
* Settings → Data has a WooCommerce orders panel with the import status, the number of orders imported, the last run, order counts and an "Import past orders now" button. Deleting all data resets the import.

= 3.1.2 =
* Ad URLs detects Google Ads auto-tagged clicks (gclid / gbraid / wbraid with gad_campaignid), Microsoft Ads (msclkid), TikTok (ttclid), X (twclid) and LinkedIn (li_fat_id) clicks without utm_* tags: the campaign falls back to utm_id, gad_campaignid, campaign_id or hsa_cam, the source is set from the network and the medium to cpc. A new ad click also starts a new session. fbclid alone is treated as Facebook social traffic, not paid.
* The Ad URLs tab shows only campaign traffic: rows without a campaign, source or medium are left out, with a tagging hint when the period has none. Existing rows are not changed.
* Compact dashboard layout: one toolbar row with the date range, filters, data status and Customize cards, a slimmer title bar and tighter spacing on every tab and on Settings, so all 12 default cards fit on screen.
* Redesigned metric cards with per-card accent colors and icons, a larger value, colored trend pills (inverted for bounce rate) and a comparison line.

= 3.1.1 =
* The dashboard shows the same 12 cards as the classic dashboard by default (Visits, Product visits, Cart visits, Add to cart, Checkout, Orders / AOV, Items / AOI, Conversion rate, Created campaigns, Sales / Tip, Fulfillment orders, Paid / Balance); the other cards stay available under Customize and "Reset to default" restores this set.
* The default date range is now Today. Sites still using the old Month to date default are moved to Today once on upgrade.
* Expanded the bot list: AI crawlers, link previewers, uptime monitors, SEO crawlers, security scanners and HTTP libraries are no longer counted as visitors.
* The tracker no longer records a pageview while the browser is prerendering a page; it waits until the page is actually shown.

= 3.1.0 =
* New public read-only REST API under `/wp-json/dnbfs/v1`: `meta`, `stats/summary`, `stats/timeseries`, `stats/breakdown`, `stats/funnel`, `stats/realtime` and `openapi.json`.
* API keys with a visible prefix and HMAC-hashed secret storage, per-key scopes and an optional IP allow-list that resolves the client IP in a spoof-resistant way.
* Atomic per-key rate limiting; limited requests receive `429` with a `Retry-After` header.
* New Settings → API tab to create, scope and revoke keys; System status now checks the API.
* README includes a NestJS integration example.
* Chart money values use the WooCommerce thousand and decimal separators.
* The WooCommerce rule-change warning no longer triggers when only the order of rules changes.

= 3.0.0 =
* Built-in visitor tracking replaces Burst Statistics.
* Upgraded dashboard with filters, drill-down, customizable cards and online visitors.
* New Settings screen and WordPress dashboard widget.
