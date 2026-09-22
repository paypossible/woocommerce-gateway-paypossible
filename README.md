# WooCommerce Payment Gateway for PayPossible

Offer PayPossible financing and leasing at WooCommerce checkout. Customers are handed off to PayPossible's hosted application to complete the purchase; PayPossible then posts lifecycle events back to WooCommerce to drive order status.

## Features

- Hosted checkout via PayPossible — no card data touches your site.
- Lifecycle webhook handler: PayPossible `order` events (`pending`, `sent`, `approving`, `approved`, `shipped`, `processing`, `paid`, `cancelling`, `cancelled`, `refunding`, `refunded`) drive matching WooCommerce order status transitions and order notes.
- Merchant fulfillment in WooCommerce (marking an order Completed, or the WC Fulfillments API on WC 8.5+) posts to PayPossible's `/ship/` endpoint.
- Merchant-initiated refund or cancel posts to PayPossible's `/refund/` and `/cancel/` endpoints.
- WooCommerce Blocks checkout support.
- HPOS-compatible (order metadata written via `WC_Order::update_meta_data()`).

## Installation

1. Upload the `woocommerce-gateway-paypossible` folder to `/wp-content/plugins/` or install the zip via the WordPress admin.
2. Activate the plugin.
3. In WooCommerce → Settings → Payments → PayPossible, enter your Merchant ID and API Token. Leave Test Mode on for staging.

## Development

```bash
yarn install
yarn start   # watch build for the Blocks JS
yarn build   # production build + regenerate .pot
```

## License

MIT. See [LICENSE](LICENSE).
