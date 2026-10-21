=== Beltoft Licenses for WooCommerce ===
Contributors: beltoft
Tags: license keys, software licensing, woocommerce, subscriptions, plugin updates
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell software licenses with WooCommerce and WooCommerce Subscriptions: license keys, site activations, a license API and update downloads.

== Description ==

Beltoft Licenses turns WooCommerce products into licensed software.

* License keys in a format you choose, with an optional prefix.
* A License tab on products: software slug, sites per license (variations can set their own, for site tiers; 0 is unlimited), current version and a free-plan option.
* One license per unit when an order is paid; a full refund revokes it. A free plan is claimed once per customer.
* Works with WooCommerce Subscriptions without taking over any of its work: billing, renewals, retries, switching and its emails stay with Subscriptions. Licenses follow the subscription: active while it is, a grace period while it is on hold (3 days by default), to the end of the paid period when cancelled, and the new site limit after a switch.
* A daily job marks licenses past their expiry as expired.
* A license API for your plugins and themes: validate, activate, deactivate and download, with short-lived signed download links.
* Release files served from a protected folder, by PHP or by the web server (nginx X-Accel-Redirect, Apache X-Sendfile).
* Extra API paths and an optional required header, so plugins already built against another license server can keep their URLs.
* A "Medusa compatible" response format for stores moving from a Medusa license backend.

Everything is configured under Settings → Licenses. Nothing is tied to one site.

Still to come: release uploads, the My Account page and the admin license screens.

= License API =

Base: `/wp-json/beltoft-licenses/v1/`

* `POST validate` — `license_key`, optional `domain`, `plugin_slug`, `version`
* `POST activate` — `license_key`, `domain`, optional `plugin_slug`
* `POST deactivate` — `license_key`, `domain`
* `POST download` — `license_key`, `domain`, optional `plugin_slug`; returns a download link
* `GET download-file?token=` — the zip of the current version
* `GET versions?slug=` — the current version of a product

== Installation ==

1. Install and activate WooCommerce, then this plugin.
2. Go to Settings → Licenses and review the defaults.
3. On a product, open the License tab, tick "Licensed product" and enter its software slug (the plugin or theme folder name).

== Frequently Asked Questions ==

= Where are release files stored? =

By default in a protected folder inside uploads. On nginx, deny direct access to `wp-content/uploads/beltoft-licenses-private/`, or choose a folder outside the web root in the settings.

= Does deleting the plugin delete licenses? =

Only if "Delete all licenses, releases and settings when the plugin is deleted" is ticked under Settings → Licenses → Advanced.

== Changelog ==

= 0.2.0 =
* License tab on products, licenses from paid orders, refunds, free plans.
* WooCommerce Subscriptions: licenses follow the subscription, with a grace period after a failed payment.
* Daily expiry job.

= 0.1.0 =
* First version: license storage, settings, and the license API.
