=== Beltoft Licenses for WooCommerce ===
Contributors: beltoft
Tags: license keys, software licensing, woocommerce, subscriptions, plugin updates
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell software licenses with WooCommerce and WooCommerce Subscriptions: license keys, site activations, a license API and update downloads.

== Description ==

Beltoft Licenses turns WooCommerce products into licensed software.

* License keys in a format you choose, with an optional prefix.
* A License tab on products: software slug, sites per license (variations can set their own, for site tiers; 0 is unlimited), current version and a free-plan option.
* One license per unit when an order is paid; a full refund revokes it. A free plan is claimed once per customer.
* Works with WooCommerce Subscriptions without taking over any of its work: billing, renewals, retries, switching and its emails stay with Subscriptions. Licenses follow the subscription: active while it is, a grace period while it is on hold (3 days by default), to the end of the paid period when cancelled, and the new site limit after a switch.
* A daily job (or hourly, by setting) marks licenses past their expiry as expired.
* A license API for your plugins and themes: validate, activate, deactivate and download, with short-lived signed download links.
* Products → Releases: upload a version with its changelog, choose which version is current (to roll back), delete old ones. A zip must contain one folder named after the software.
* GitHub releases: a published release of a listed repository becomes a release, from its attached zip or its source zip (repackaged to the right folder name). Private repositories work with an access token.
* My Account → Licenses (the slug and label are settings): each license with its key and a copy button, sites in use, validity, the latest version, "Free this site" for each active site, a download of the current version, and a link to its subscription. A small layout stylesheet is included, and themes can override the template or turn the stylesheet off.
* License keys on the order received page, the customer's order view and customer order emails (once paid).
* A history of each license (created, status and expiry changes, activations), shared with plugins built on top.
* Hooks for plugins built on top: `beltoft_licenses_account_license` adds a panel to each license on the account page; `beltoft_licenses_license_created`, `_license_updated`, `_status_changed`, `_expiry_changed`, `_activated` and `_deactivated` follow every change. `_activated` and `_deactivated` fire for sites and for resources of other kinds alike, with the site or resource identifier as the second argument and the activation row (with its `kind`) as the third. Filters: `beltoft_licenses_api_license` keeps a license out of the API; `beltoft_licenses_resource_label` and `beltoft_licenses_account_show_key` change what the account page shows.
* Release files served from a protected folder, by PHP or by the web server (nginx X-Accel-Redirect, Apache X-Sendfile).
* Extra API paths and an optional required header, so plugins already built against another license server can keep their URLs.
* A "Medusa compatible" response format for stores moving from a Medusa license backend.

Everything is configured under Settings → Licenses. Nothing is tied to one site.

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

= How do I connect GitHub? =

Under Settings → Licenses → GitHub releases, list each repository as `owner/repository => software-slug` and set a secret. On GitHub, add a webhook to the repository (or organisation) with the URL shown there, content type `application/json`, the same secret, and the "Releases" event. Attach a built `.zip` to the release, or the source zip is used.

= How do I import licenses from another system? =

Write a JSON file and run `wp beltoft-licenses import <file.json> --dry-run`, then again without `--dry-run`. The whole file is checked first: if anything is wrong, nothing is written. Licenses are matched by `external_id`, then by key, so running a newer export again applies only what changed.

The file is `{"licenses": [...], "releases": [...]}`; both lists are optional. Each license has:

* `license_key` (required, kept as it is), `product_slug` (required, the software slug of a product) and `email` (required; a user with that email sees the license in their account);
* `external_id`: its ID in the old system, unique;
* `status`: active, expired, revoked or suspended (default active);
* `max_activations`: 0 for unlimited (default 1);
* `expires_at`, `created_at`: ISO 8601 dates, or null;
* `subscription_id`, `order_id`: an existing subscription or order;
* `activations`: a list of `{"domain", "activated_at", "deactivated_at"}`. A site without `deactivated_at` is live. Sites the file does not list are left as they are.

Each release has `product_slug`, `version`, `file` (a zip, relative to the JSON file), `changelog`, and `current` (true to serve it to licensed sites).

Imported sites are not checked against the site limit. Plugins built on this one can call `BeltoftLicenses\Import::is_running()` to skip their own side effects while an import writes.

= Does deleting the plugin delete licenses? =

Only if "Delete all licenses, releases and settings when the plugin is deleted" is ticked under Settings → Licenses → Advanced.

== Changelog ==

= 0.7.2 =
* Customer email "New version available": when a newer release becomes the current version, every customer with an active license for that software gets one email with the new version, a short changelog and a link to My Account → Licenses to download it. Sent in the background in batches through Action Scheduler, once per release, never for revoked, suspended or expired licenses, never for a rollback and never during an import. Editable under WooCommerce → Settings → Emails.
* Action `beltoft_licenses_version_current` and filter `beltoft_licenses_send_update_email`.

= 0.7.1 =
* The first order email now has the license keys of subscription products: licenses are issued when the order is paid, before WooCommerce sends its processing email.
* A full refund cancels the subscriptions linked to the order (setting, on by default). Refunding order lines revokes that many licenses of each line.
* A resubscribe takes back the customer's expired key and its sites instead of issuing a new key (setting, on by default).
* Reactivating a license in the admin clears an expiry that has already passed. Saving the license screen without an expiry field no longer clears the expiry.
* Customer emails "License revoked" and "License expired" (WooCommerce → Settings → Emails, overridable templates), sent once per license, never during an import.
* A revoked or expired license shows its status beside the key in order pages and emails. Revoked and suspended licenses no longer show "Until <date>".
* Filters `beltoft_licenses_send_status_email`, `beltoft_licenses_order_subscriptions`, `beltoft_licenses_resubscribed_subscription` and `beltoft_licenses_is_subscription_product`.

= 0.7.0 =
* Activation kinds and a resource API for plugins built on top: `add_resource`, `close_resource`, `update_resource_meta`, `resources` and `activation`. Site activations keep kind `site`; other kinds match `[a-z0-9_-]{1,20}`. `beltoft_licenses_activated` and `_deactivated` also fire for resources (identifier second, activation row third).
* Action `beltoft_licenses_license_updated` after any license change.
* Filter `beltoft_licenses_api_license` to keep licenses out of the API.
* Display filters: `beltoft_licenses_resource_label` and `beltoft_licenses_account_show_key`.
* Setting: check expiry every hour instead of daily.

= 0.6.0 =
* `wp beltoft-licenses import`: licenses, activations and releases from a JSON file, with a dry run and delta imports.
* WooCommerce Subscriptions' renewal and expiry notices list the subscription's license keys and sites.

= 0.5.0 =
* WooCommerce → Licenses: search, filters, bulk revoke, suspend and reactivate, CSV export, a screen per license (status, expiry, site limit, sites, history) and manual issue.
* A Licenses box on order and subscription screens.

= 0.4.0 =
* My Account → Licenses, keys on order pages and emails, license history.

= 0.3.0 =
* Releases: upload screen, current version and rollback, GitHub release webhook.

= 0.2.0 =
* License tab on products, licenses from paid orders, refunds, free plans.
* WooCommerce Subscriptions: licenses follow the subscription, with a grace period after a failed payment.
* Daily expiry job.

= 0.1.0 =
* First version: license storage, settings, and the license API.
