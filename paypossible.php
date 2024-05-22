<?php
/**
 * Plugin Name: WooCommerce PayPossible Gateway
 * Plugin URI: https://github.com/paypossible/woocommerce-gateway-paypossible
 * Description: WooCommerce payment gateway for PayPossible.
 * Version: 1.0
 * Author: PayPossible, Inc.
 * Author URI: https://paypossible.com
 * Requires Plugins: woocommerce
 * Text Domain: paypossible
 */

// phpcs:disable WordPress.Files.FileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Make sure WooCommerce is active.
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

add_action( 'plugins_loaded', 'init_paypossible_gateway' );

/**
 * Initialize the payment gate
 */
function init_paypossible_gateway() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	/**
	 * The payment gateway class
	 */
	class WC_PayPossible_Gateway extends WC_Payment_Gateway {
		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
			$this->id           = 'paypossible';
			$this->method_title = 'PayPossible';
			$this->has_fields   = false;
			$this->supports     = array( 'products' );

			$this->init_form_fields();
			$this->init_settings();

			$this->enabled     = 'yes' === $this->get_option( 'enabled' );
			$this->title       = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			$this->test_mode   = 'yes' === $this->get_option( 'test_mode' );
			$this->domain      = $this->test_mode ? 'app-staging.paypossible.com' : 'app.paypossible.com';
			$this->merchant_id = $this->get_option( 'merchant_id' );
			$this->token       = $this->get_option( 'token' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_api_paypossible', array( $this, 'webhook' ) );
		}

		/**
		 * Initialize Gateway Settings Form Fields.
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'     => array(
					'title'   => 'Enable/Disable',
					'type'    => 'checkbox',
					'label'   => 'Enable PayPossible Gateway',
					'default' => 'no',
				),
				'title'       => array(
					'title'       => 'Title',
					'type'        => 'text',
					'description' => 'This controls the title which the user sees during checkout.',
					'default'     => 'Check My Payment Options (Financing, Leasing)',
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'This controls the description which the user sees during checkout.',
					'default'     => 'Checking your eligibility will not affect your credit.',
				),
				'test_mode'   => array(
					'title'       => 'Test Mode',
					'type'        => 'checkbox',
					'label'       => 'Enable Test Mode',
					'description' => 'Place payment gateway in test mode.',
					'default'     => 'yes',
					'desc_tip'    => true,
				),
				'merchant_id' => array(
					'title'       => 'Merchant ID',
					'type'        => 'text',
					'description' => 'Your PayPossible Merchant ID.',
					'desc_tip'    => true,
				),
				'token'       => array(
					'title'       => 'API Token',
					'type'        => 'password',
					'description' => 'Your PayPossible API token.',
					'desc_tip'    => true,
				),
			);
		}

		/**
		 * Process the payment and return the result.
		 *
		 * @param int $order_id The ID of the WooCommece Order.
		 * @return array
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			$cart_items = array();
			foreach ( $order->get_items() as $item_id => $item ) {
				$product      = $item->get_product();
				$cart_items[] = array(
					'description' => $product->get_name(),
					'sku'         => $product->get_sku(),
					'price'       => $product->get_price(),
					'quantity'    => $item->get_quantity(),
				);
			}
			$discount_total = $order->get_total_discount();
			$shipping_total = $order->get_shipping_total();
			$tax_total      = $order->get_total_tax();

			$request_data = json_encode(
				array(
					'discount'     => $discount_total,
					'items'        => $cart_items,
					'reference_id' => $order->get_id(),
					'shipping'     => $shipping_total,
					'tax'          => $tax_total,
				)
			);
			var_dump( $request_body );

			$response = wp_remote_post(
				'https://' . $this->domain . '/api/v1/carts/',
				array(
					'body'    => $request_data,
					'headers' => array(
						'Content-Type' => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				wc_add_notice( __( 'There was an error transferring cart. Please try again.', 'error', 'paypossible' ) );
				return array(
					'result'   => 'failure',
					'redirect' => '',
				);
			}

			$response_body = wp_remote_retrieve_body( $response );
			var_dump( $response_body );
			$response_data = json_decode( $response_body, true );
				$cart_url  = $response_data['url'];

				$address      = array(
					'street1' => $order->get_billing_address_1(),
					'street2' => $order->get_billing_address_2(),
					'city'    => $order->get_billing_city(),
					'state'   => $order->get_billing_state(),
					'zip'     => $order->get_billing_postcode(),
				);
				$personal     = array(
					'first_name' => $order->get_billing_first_name(),
					'last_name'  => $order->get_billing_last_name(),
					'email'      => $order->get_billing_email(),
					'phone'      => $order->get_billing_phone(),
				);
				$merchant_url = 'https://' . $this->domain . '/api/v1/merchants/' . $this->merchant_id . '/';

				$request_data = json_encode(
					array(
						'address'    => $address,
						'cart'       => array( 'url' => $cart_url ),
						'ip_address' => $order->get_customer_ip_address(),
						'merchant'   => array( 'url' => $merchant_url ),
						'personal'   => $personal,
					)
				);
				var_dump( $request_data );

				$response = wp_remote_post(
					'https://' . $this->domain . '/api/v1/leads/',
					array(
						'body'    => $request_data,
						'headers' => array(
							'Content-Type'  => 'application/json',
							'Authorization' => 'Token ' . $this->token,
						),
					)
				);

			if ( is_wp_error( $response ) ) {
				wc_add_notice( __( 'There was an error starting applicationt. Please try again.', 'error', 'paypossible' ) );
				return array(
					'result'   => 'failure',
					'redirect' => '',
				);
			}

				$response_body = wp_remote_retrieve_body( $response );
				var_dump( $response_body );
					$response_data = json_decode( $response_body, true );
					$app_url       = $response_data['app_url'];

			$order->update_status( 'on-hold', __( 'Awaiting customer application.', 'paypossible' ) );
			// $order->reduce_order_stock();
			WC()->cart->empty_cart();

			return array(
				'result'   => 'success',
				'redirect' => $app_url,
			);
		}

		/**
		 * Payment complete webhook
		 */
		public function webhook() {
			$order = wc_get_order( $_GET['id'] );
			$order->payment_complete();
			$order->reduce_order_stock();
			update_option( 'webhook_debug', $_GET );
		}
	}

	/**
	 * Wire up the payment gateway class
	 *
	 * @param array $methods WooCommerce payment methods.
	 */
	function add_paypossible_gateway_class( $methods ) {
		$methods[] = 'WC_PayPossible_Gateway';
		return $methods;
	}

	add_filter( 'woocommerce_payment_gateways', 'add_paypossible_gateway_class' );
}
