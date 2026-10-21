# Beltoft Licenses for WooCommerce

Sell software licenses with WooCommerce and WooCommerce Subscriptions: license keys, site activations, a license API for your plugins and themes, and update downloads.

See [readme.txt](readme.txt) for the user documentation and the license API.

## Status

`0.1.0`: storage, settings (Settings → Licenses) and the license API. Selling from products, the Subscriptions lifecycle, release uploads and the My Account page are in progress.

## Development

There are no runtime dependencies and no build step.

Tests use the WordPress PHPUnit test suite, with WooCommerce loaded from a WordPress install's plugins directory:

```bash
WP_TESTS_DIR=/path/to/wordpress-tests-lib \
WP_TESTS_PHPUNIT_POLYFILLS_PATH=/path/to/vendor/yoast/phpunit-polyfills \
SITE_PLUGINS=/path/to/wp-content/plugins \
phpunit
```

`tests/fixtures/medusa/` holds responses recorded from a Medusa license backend. The "Medusa compatible" response format is tested against them.

Releases are built from the repository without the files listed in `.distignore`, and must pass [Plugin Check](https://wordpress.org/plugins/plugin-check/) with no errors.

## License

GPL-2.0-or-later. Author: [beltoft.net](https://beltoft.net/).
