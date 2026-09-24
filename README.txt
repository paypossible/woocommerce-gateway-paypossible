=== WooCommerce PayPossible Gateway ===

Contributors: PayPossible, Inc.

Tags: woocommerce, payment, paypossible, financing, leasing

Requires at least: 4.0

Tested up to: 6.6

Stable tag: 1.1.1

License: MIT

License URI: https://github.com/paypossible/woocommerce-gateway-paypossible/blob/main/LICENSE

Offer PayPossible financing and leasing at WooCommerce checkout.

== Description ==

Add PayPossible financing and leasing to your WooCommerce checkout. Customers apply on PayPossible's hosted application; PayPossible sends lifecycle events back to WooCommerce to keep order status in sync. Merchants can fulfill, cancel, and refund from the standard WooCommerce admin — the plugin makes the corresponding calls to PayPossible in the background.

Requires WooCommerce 4.2+. Works with HPOS and the WooCommerce Blocks checkout.

== Installation ==

1. Upload the `woocommerce-gateway-paypossible` folder to `/wp-content/plugins/`, or install the plugin zip through the WordPress admin.
2. Activate the plugin.
3. Go to WooCommerce → Settings → Payments and enable "PayPossible."
4. Click "Manage" on the PayPossible row and fill in:
   * Merchant ID — issued by PayPossible.
   * API Token — issued by PayPossible.
   * Test Mode — leave on while integrating; the plugin will talk to app-staging.paypossible.com. Turn off for production (app.paypossible.com). Note: swap Merchant ID and API Token when you switch environments.
5. Optional: adjust the checkout title, description, and "Order Button" text.

== Usage ==

= What the customer sees =

At checkout the customer picks "Check My Payment Options (Financing, Leasing)" (or your configured title), clicks the order button ("Check Eligibility" by default), and is redirected to PayPossible's hosted application. Their cart stays intact on the store while they apply. When PayPossible approves them, they return to the WooCommerce thank-you page for their order. If they're declined, PayPossible still redirects them back — WooCommerce marks the order as Failed and the thank-you page shows a "Try a different payment method" button that opens the order-pay flow with the customer's items intact.

= Order lifecycle =

PayPossible posts events to a webhook the plugin registers at `/wc-api/paypossible/` on your site. Every unique event adds an order note so merchants can see what PayPossible has told the plugin. Duplicate events are silently ignored.

**Order-type events → WooCommerce status:**

* `pending`, `sent`, `approving` → order note only; order stays Pending.
* `approved` → WC runs payment_complete(): the order moves to Processing (or Completed for virtual products), stock is reduced, and the PayPossible order id is stored as the transaction id.
* `shipped`, `processing`, `paid` → order note only.
* `cancelling`, `refunding` → order note only.
* `cancelled` → WC status → Cancelled.
* `refunded` → WC status → Refunded.

**Lead-type events → WooCommerce status:**

* `pending`, `sent`, `approving`, `approved` → order note only; the corresponding order-type events drive the real transitions.
* `declined` → WC status → Failed. Admin gets the standard WooCommerce failed-order email.
* `expired` → WC status → Cancelled.

**Offer / loan events**: order note only, no state change.

= Merchant actions =

When a merchant takes action on a PayPossible order in the WC admin, the plugin makes the matching call to PayPossible. Before each call the plugin GETs the PayPossible order status; if PayPossible already has the action complete, the call is skipped and an order note explains why.

* **Ship** — marking an order Completed (or fulfilling it via the WC Fulfillments UI on WC 8.5+) posts to `POST /api/v1/orders/<id>/ship/`.
* **Refund** — the "Refund via PayPossible" button in the standard refund modal posts to `POST /api/v1/orders/<id>/refund/`. If PayPossible rejects the call, the WooCommerce refund record is NOT created and the error is shown inline in the refund modal.
* **Cancel** — cancelling the order in the WC admin posts to `POST /api/v1/orders/<id>/cancel/`.

If any of these automatic notifications fail (network error, PayPossible returned an error), an order note describes the failure. The order's "Actions" dropdown (top-right of the order edit page) will show "Notify PayPossible: shipped" and "Notify PayPossible: cancel order" entries so a merchant can retry manually. The result of a manual retry surfaces as an admin notice at the top of the order edit page.

== Frequently Asked Questions ==

= How do I enable PayPossible payment options? =

After installing and activating the plugin, go to WooCommerce → Settings → Payments and enter your PayPossible Merchant ID and API Token.

= What happens if a customer is declined? =

PayPossible sends a `lead:declined` webhook. The plugin transitions the WooCommerce order to Failed and sends the admin the standard failed-order email. When the customer returns from PayPossible, the thank-you page shows a "Try a different payment method" button that opens WooCommerce's order-pay page for the same order — the customer can pick another gateway without re-entering their cart.

= Does this work with HPOS (High-Performance Order Storage)? =

Yes. All order metadata is written via WC_Order::update_meta_data() and the plugin uses wc_get_order() throughout.

= Does this work with the WooCommerce Blocks checkout? =

Yes.

= What if a PayPossible notification fails? =

An order note describes the failure. For ship/cancel, a retry entry appears in the order's Actions dropdown. For refunds, the failure blocks the WC refund modal and shows the error inline — the merchant can retry or click "Refund manually" to record a local refund without touching PayPossible.

== Changelog ==

= 1.1.1 =
* Fix: process_payment() no longer returns null on failure paths. WooCommerce Blocks calls array_merge() on the return value and would crash with a fatal TypeError under PHP 8+. Each failure path now returns array( 'result' => 'failure', 'message' => ... ) so both classic and Blocks checkouts render the error inline.
* Cart-create and lead-create failures now log endpoint, HTTP response code, and response body via wc_get_logger() (WooCommerce > Status > Logs, source: paypossible). The API token is never logged.
* Bumped the timeout on the cart and lead POSTs from 5 seconds to 10 seconds.

= 1.1.0 =

Callback / lifecycle handling
* Callback endpoint parses PayPossible JSON events ({id, type, status}) and maps order-type statuses onto WooCommerce statuses.
* Order note added for every unique callback event (including lead, offer, and loan events).
* Duplicate callbacks silently deduped on (type, id, status).
* Lead events: lead:declined transitions WC to failed, lead:expired transitions WC to cancelled.
* order:approved runs $order->payment_complete() with the PayPossible order id set as WC's transaction id.
* Callback nonce hardened: 128-bit random_bytes, read from $_GET, constant-time compare via hash_equals.

Merchant admin actions
* Refunds: implements WC_Payment_Gateway::process_refund() so the WC Refund modal exposes a "Refund via PayPossible" button. Failures veto the WC-side refund record and surface inline.
* Ship: notify PayPossible on woocommerce_order_status_completed and on WC 8.5+ Fulfillments.
* Cancel: notify PayPossible on woocommerce_order_status_cancelled.
* Order Actions dropdown gains "Notify PayPossible: shipped" and "Notify PayPossible: cancel order" for retry after a failed automatic notification.
* Admin notice surfaces Order Actions results on the order edit screen (HPOS-aware).
* All outbound /ship/, /refund/, /cancel/ calls GET the remote PayPossible status first and skip if the action is already terminal remotely.

Checkout / UX
* reference_id on each PayPossible cart item is now the WooCommerce order-item id.
* Cart is no longer emptied at redirect. It's kept until the customer lands on the thank-you page for a successful order, so declined/abandoned customers can retry with another payment method.
* Failed PayPossible orders show a "Try a different payment method" button on the thank-you page.

Fixes
* Store callback nonce and lead id as WC order meta instead of order-item meta.
* Eliminate double stock reduction on payment completion.
* Fix typo in checkout error message.

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.1.1 =
Fixes a fatal error in the WooCommerce Blocks checkout when a PayPossible API call fails, and adds diagnostic logging for those failures.

= 1.1.0 =
Adds full PayPossible lifecycle handling, first-class WC refund / ship / cancel integration, remote-authoritative idempotency, and several correctness and security fixes.

= 1.0.0 =
Initial release of the PayPossible WooCommerce plugin.
