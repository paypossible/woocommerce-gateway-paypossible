# WooCommerce Payment Gateway for PayPossible

Offer PayPossible financing and leasing at WooCommerce checkout. Customers apply on PayPossible's hosted application; PayPossible then posts lifecycle events back to WooCommerce to drive order status. Merchants ship, cancel, and refund from the standard WooCommerce admin — the plugin makes the matching PayPossible API calls in the background.

Requires WooCommerce 4.2+. HPOS and WC Blocks checkout compatible.

## Installation

1. Upload the `woocommerce-gateway-paypossible` folder to `/wp-content/plugins/`, or install the plugin zip through the WordPress admin.
2. Activate the plugin.
3. Go to **WooCommerce → Settings → Payments** and enable "PayPossible."
4. Click **Manage** on the PayPossible row and fill in:
   - **Merchant ID** — issued by PayPossible.
   - **API Token** — issued by PayPossible.
   - **Test Mode** — leave on while integrating; the plugin talks to `app-staging.paypossible.com`. Turn off for production (`app.paypossible.com`). Note: swap Merchant ID and API Token when you switch environments.
5. Optionally adjust the checkout title, description, and order-button text.

## Customer flow

1. At checkout the customer picks "Check My Payment Options (Financing, Leasing)" (or your configured title) and clicks the order button.
2. The plugin creates the WooCommerce order in `pending`, hands the cart off to PayPossible, and redirects the customer to PayPossible's hosted application.
3. The customer's cart is preserved during the application flow — nothing is cleared until they land on a successful thank-you page.
4. On approval, PayPossible redirects the customer back to the WooCommerce thank-you page. The `approved` webhook fires and WooCommerce advances the order via `payment_complete()`.
5. On decline (`lead:declined` webhook), the WooCommerce order transitions to `failed`. The thank-you page shows a **Try a different payment method** button linking to WooCommerce's order-pay endpoint — the customer picks another gateway on the same order without re-entering their cart.

## Order lifecycle

PayPossible posts JSON events to a webhook the plugin exposes at `/wc-api/paypossible/`:

```json
{ "id": "<object id>", "type": "order|lead|offer|loan", "status": "<status>" }
```

Every unique event adds an order note visible in the WC admin. Duplicates are silently deduped on `(type, id, status)`.

### Inbound — PayPossible → WooCommerce

**`type: "order"`** events map to WC status transitions:

| PayPossible status                        | Effect on WooCommerce                                                                                             |
| ----------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `pending`, `sent`, `approving`            | Order note only. WC order stays `pending`.                                                                         |
| `approved`                                | `$order->payment_complete()` runs. WC → `processing` (or `completed` for virtual). PayPossible order id → WC transaction id. Stock reduced by WooCommerce. |
| `shipped`, `processing`, `paid`           | Order note only.                                                                                                   |
| `cancelling`, `refunding`                 | Order note only.                                                                                                   |
| `cancelled`                               | WC → `cancelled` (unless already terminal).                                                                        |
| `refunded`                                | WC → `refunded` (unless already terminal).                                                                         |

**`type: "lead"`** events are mostly informational — the corresponding `order`-type events drive the real transitions:

| PayPossible status                                     | Effect on WooCommerce                                                    |
| ------------------------------------------------------ | ------------------------------------------------------------------------ |
| `pending`, `sent`, `approving`, `approved`             | Order note only.                                                          |
| `declined`                                             | WC → `failed`. Admin gets WooCommerce's failed-order email. Thank-you page shows a retry button. |
| `expired`                                              | WC → `cancelled`.                                                         |

**`type: "offer"` / `type: "loan"`** events add an order note but do not change WC status.

### Outbound — WooCommerce → PayPossible

Every outbound call first GETs `/api/v1/orders/<pp-id>/` and skips the POST if PayPossible already has the action complete on their side (`shipped` for ship; `cancelling`/`cancelled` for cancel; `refunding`/`refunded` for refund).

| Merchant action in WC                                  | PayPossible call                                    | How it's triggered                                                                 |
| ------------------------------------------------------ | --------------------------------------------------- | ---------------------------------------------------------------------------------- |
| Mark order Completed / fulfill via WC Fulfillments     | `POST /api/v1/orders/<id>/ship/`                    | `woocommerce_order_status_completed` or `woocommerce_fulfillment_after_fulfill`    |
| Click **Refund via PayPossible** in the refund modal   | `POST /api/v1/orders/<id>/refund/`                  | `WC_Payment_Gateway::process_refund()` — failures veto the WC-side refund record   |
| Cancel order in WC admin                               | `POST /api/v1/orders/<id>/cancel/`                  | `woocommerce_order_status_cancelled`                                                |
| Programmatic refund (`wc_create_refund` / REST)        | `POST /api/v1/orders/<id>/refund/`                  | `woocommerce_order_refunded` (safety net for refunds that bypass the refund modal) |

If any of these calls fail, an order note describes the error. For ship/cancel, the order's **Actions** dropdown will surface "Notify PayPossible: shipped" and "Notify PayPossible: cancel order" entries for a merchant retry — the result appears as an admin notice on the order edit page. For refunds via the refund modal, the WC-side record is not created and the error is shown inline.

## Order metadata written by the plugin

All keys live on the WC order (`postmeta` on legacy storage, `wc_orders_meta` on HPOS).

| Key                              | Purpose                                                                     |
| -------------------------------- | --------------------------------------------------------------------------- |
| `_paypossible_callback_nonce`    | 128-bit shared secret checked on every incoming webhook (`hash_equals`).    |
| `_paypossible_lead_id`           | PayPossible lead id, stored at redirect time.                                |
| `_paypossible_order_id`          | PayPossible order id, stored on the first `type: "order"` event.             |
| `_transaction_id` (WC-native)    | Same as `_paypossible_order_id` — set by `payment_complete()` on `approved`. |
| `_paypossible_seen_events`       | JSON array of `type:id:status` triples used for callback dedupe.             |
| `_paypossible_shipped_notified`  | `"yes"` after a successful `/ship/` call.                                    |
| `_paypossible_refunded_notified` | `"yes"` after a successful `/refund/` call.                                  |
| `_paypossible_cancelled_notified`| `"yes"` after a successful `/cancel/` call.                                  |
| `_paypossible_cart_cleared`      | `"yes"` after the customer's cart has been cleared on a successful thank-you page visit. |

## Development

```bash
yarn install
yarn start   # watch build for the Blocks JS
yarn build   # production build + regenerate .pot
```

PHP style: WooCommerce-Core PHPCS ruleset (`phpcs.xml`).

## License

MIT. See [LICENSE](LICENSE).
