=== WooCommerce PayPossible Gateway ===

Contributors: PayPossible, Inc.

Tags: woocommerce, payment, paypossible, financing, leasing

Requires at least: 4.0

Tested up to: 6.6

Stable tag: 1.1.0

License: MIT

License URI: https://github.com/paypossible/woocommerce-gateway-paypossible/blob/main/LICENSE

Integrate PayPossible into your WooCommerce store.

== Description ==

This plugin allows you to integrate PayPossible payment options into your WooCommerce store.

== Installation ==

1. Upload the `woocommerce-gateway-paypossible` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce settings and configure the PayPossible options

== Frequently Asked Questions ==

= How do I enable PayPossible payment options? =

After installing and activating the plugin, go to WooCommerce settings and enter your PayPossible API credentials.

== Changelog ==

= 1.1.0 =
* Send the WooCommerce order-item id as reference_id on each PayPossible cart item.
* Callback endpoint now handles PayPossible lifecycle JSON events (lead, offer, loan, order) and maps order statuses onto WooCommerce order statuses.
* Merchant fulfillment in WooCommerce (order completed or WC Fulfillments) posts to the PayPossible /ship/ endpoint.
* Merchant refund or cancel in WooCommerce posts to the PayPossible /refund/ and /cancel/ endpoints.
* All lifecycle notifications (inbound and outbound) are idempotent.
* Callback nonce hardened: 128-bit random_bytes, read from $_GET, constant-time compare.
* Fix: callback nonce and lead id are now stored as WC order meta (were stored in order-item meta with the wrong id).
* Fix: stock reduction no longer runs twice on payment completion.
* Fix: typo in checkout error message.

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.1.0 =
Handles PayPossible lifecycle events, notifies PayPossible on merchant fulfillment/refund/cancel, and fixes several correctness and security issues.

= 1.0.0 =
Initial release of the PayPossible WooCommerce plugin.
