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
