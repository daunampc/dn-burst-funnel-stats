=== DN Burst Funnel Stats ===
Contributors: toshstack.dev
Tags: burst statistics, woocommerce, analytics, funnel, ecommerce, dashboard
Requires at least: 6.5
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight WooCommerce funnel dashboard powered by Burst Statistics visits and WooCommerce order data.

== Description ==

DN Burst Funnel Stats adds a simple funnel analytics dashboard for WooCommerce stores using Burst Statistics.

The plugin combines visitor data from Burst Statistics with WooCommerce order data to help store owners quickly understand how customers move through the sales funnel.

It is designed to be lightweight, easy to install, and useful for daily store monitoring.

= Main metrics =

* Visits
* Product Visits
* Cart Visits
* Add To Cart
* Checkout Visits
* Orders / AOV
* Items / AOI
* Conversion Rate
* Created Campaigns
* Sales / Tip
* Fulfillment Orders
* Paid / Balance

= Data sources =

DN Burst Funnel Stats reads data from:

* Burst Statistics page visit data
* WooCommerce order data
* WooCommerce product and cart/checkout paths
* WooCommerce add-to-cart hooks

= Important note =

Visits, Product Visits, Cart Visits, Checkout Visits, and related funnel visit metrics depend on Burst Statistics tracking data.

WooCommerce sales, orders, items, AOV, fulfillment, paid, and balance metrics depend on WooCommerce order data.

== Requirements ==

This plugin requires:

* WordPress 6.5 or higher
* PHP 7.4 or higher
* WooCommerce
* Burst Statistics

Recommended:

* Pretty permalinks enabled
* WooCommerce cart and checkout pages configured
* Burst Statistics tracking active on frontend pages

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/dn-burst-funnel-stats/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Make sure WooCommerce is active.
4. Make sure Burst Statistics is active and tracking visits.
5. Open the dashboard from the WordPress admin menu.

== Usage ==

After activation, open the Funnel Stats dashboard from the WordPress admin area.

The dashboard provides quick filters for:

* Today
* Yesterday
* 7 days
* 30 days

Each filter compares the selected period against the previous matching period.

For example:

* Today compares against yesterday.
* 7 days compares against the previous 7 days.
* 30 days compares against the previous 30 days.

== How Product Visits are counted ==

Product Visits are read from the Burst Statistics table.

The plugin checks Burst page visit records and matches product page URLs based on WooCommerce product permalink paths.

If Product Visits show zero, check that:

* Burst Statistics is recording frontend visits.
* Product page URLs exist in the Burst Statistics database table.
* The product permalink structure is correct.
* Cache, consent tools, or ad blockers are not blocking Burst tracking.

== How Add To Cart is counted ==

Add To Cart events are tracked using the WooCommerce `woocommerce_add_to_cart` hook.

The plugin stores daily Add To Cart counts in WordPress options.

To reduce duplicate and invalid tracking, the plugin may ignore:

* Known bot user agents
* Ignored IP addresses
* Duplicate Add To Cart events from the same visitor/product within the dedupe window
* Excessive repeated Add To Cart events from the same IP

== GitHub Updates ==

This plugin includes a GitHub release updater.

To enable GitHub-based updates:

1. Create a GitHub repository, for example:

   `toshstack-dev/dn-burst-funnel-stats`

2. In `dn-burst-funnel-stats.php`, update the repository constant if your repository is different:

   `DN_BURST_FUNNEL_STATS_GITHUB_REPO`

3. Increase the plugin version in both places:

   * Plugin header: `Version`
   * Constant: `DN_BURST_FUNNEL_STATS_VERSION`

4. Create a GitHub release with a semantic version tag, for example:

   * `v1.0.1`
   * `v1.0.2`
   * `v1.1.0`

5. Attach the plugin ZIP file to the GitHub release for best update compatibility.

Recommended ZIP name:

`dn-burst-funnel-stats.zip`

== Developer Notes ==

= Version update checklist =

Before publishing a new release:

1. Update the plugin header version.
2. Update `DN_BURST_FUNNEL_STATS_VERSION`.
3. Confirm the plugin ZIP contains the correct root folder.
4. Create a new GitHub release tag.
5. Upload the ZIP file to the release.
6. Test the update from WordPress Admin > Plugins.

= Recommended release tag format =

Use tags with a leading `v`:

* `v1.0.1`
* `v1.0.2`
* `v1.1.0`

= Plugin folder structure =

Recommended structure:

`dn-burst-funnel-stats/`
`dn-burst-funnel-stats/dn-burst-funnel-stats.php`
`dn-burst-funnel-stats/README.MD`


== Frequently Asked Questions ==

= Does this plugin replace Burst Statistics? =

No. DN Burst Funnel Stats depends on Burst Statistics for visit tracking data.

= Does this plugin replace WooCommerce Analytics? =

No. It provides a simplified funnel dashboard focused on key store metrics.

= Why are Product Visits showing zero? =

Product Visits depend on Burst Statistics page visit data.

If Product Visits are zero, first confirm that Burst is recording product page URLs in the Burst Statistics database table.

= Why are Add To Cart counts different from WooCommerce cart sessions? =

Add To Cart is counted from WooCommerce add-to-cart events. It may use deduplication and bot filtering, so it may not match every cart session exactly.

= Does this plugin track users by itself? =

The plugin stores Add To Cart counts using WooCommerce hooks. Visit-based metrics are read from Burst Statistics.

= Does it support custom WooCommerce product permalinks? =

Yes, the plugin attempts to detect WooCommerce product paths and match product visit URLs from Burst Statistics data.
