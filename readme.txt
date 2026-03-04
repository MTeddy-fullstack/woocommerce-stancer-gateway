=== WooCommerce Stancer Gateway ===
Contributors: your-name
Tags: woocommerce, payment, gateway, stancer
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add Stancer as a payment gateway for WooCommerce.

== Description ==

WooCommerce Stancer Gateway provides a Stancer integration for WooCommerce stores.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress admin.
3. Configure Stancer keys under WooCommerce > Settings > Payments.

== Changelog ==

= 0.1.0 =
* Initial project bootstrap.
* Added Stancer return callback and webhook handling.
* Added WooCommerce refund integration through Stancer payment intents.
* Added admin logs page under WooCommerce.
* Hardened webhook security (live secret policy, timestamp signature validation, replay protection).
* Added redirect URL host allowlist for payment page redirections.
* Added sanitized/masked logging for payment identifiers.
