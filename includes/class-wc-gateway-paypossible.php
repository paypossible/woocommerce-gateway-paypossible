<?php
/**
 * WC_Gateway_PayPossible class
 *
 * @package  WooCommerce PayPossible Gateway
 * @since    1.0.0
 */

	/**
	 * The payment gateway class
	 */
class WC_Gateway_PayPossible extends WC_Payment_Gateway {
	/**
	 * The base domain to use.
	 *
	 * @var string
	 */
	private $domain;

	/**
	 * The ID of the PayPossible merchant.
	 *
	 * @var string
	 */
	private $merchant_id;

	/**
	 * Whether or not the gateway is in test mode.
	 *
	 * @var boolean
	 */
	private $test_mode;

	/**
	 * The PayPossible API token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->id                 = 'paypossible';
		$this->method_title       = 'PayPossible';
		$this->method_description = __( 'Offer customers payment options at checkout, including financing and leasing.', 'woocommerce-gateway-paypossible' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled           = $this->get_option( 'enabled' );
		$this->title             = $this->get_option( 'title' );
		$this->description       = $this->get_option( 'description' );
		$this->order_button_text = $this->get_option( 'order_button_text' );
		$this->test_mode         = 'yes' === $this->get_option( 'test_mode' );
		$this->domain            = $this->test_mode ? 'app-staging.paypossible.com' : 'app.paypossible.com';
		$this->merchant_id       = $this->get_option( 'merchant_id' );
		$this->token             = $this->get_option( 'token' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_paypossible', array( $this, 'callback' ) );
	}

	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'           => array(
				'title'   => 'Enable/Disable',
				'type'    => 'checkbox',
				'label'   => 'Enable PayPossible Gateway',
				'default' => 'no',
			),
			'title'             => array(
				'title'       => __( 'Title', 'woocommerce-gateway-paypossible' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-gateway-paypossible' ),
				'default'     => __( 'Check My Payment Options (Financing, Leasing)', 'woocommerce-gateway-paypossible' ),
				'desc_tip'    => true,
			),
			'description'       => array(
				'title'       => __( 'Description', 'woocommerce-gateway-paypossible' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-gateway-paypossible' ),
				'default'     => __( 'Checking your eligibility will not affect your credit.', 'woocommerce-gateway-paypossible' ),
				'desc_tip'    => true,
			),
			'order_button_text' => array(
				'title'       => __( 'Order Button Text', 'woocommerce-gateway-paypossible' ),
				'type'        => 'text',
				'description' => __( 'This controls the order button label which the user sees during checkout.', 'woocommerce-gateway-paypossible' ),
				'default'     => __( 'Check Eligibility', 'woocommerce-gateway-paypossible' ),
				'desc_tip'    => true,
			),
			'test_mode'         => array(
				'title'       => __( 'Test Mode', 'woocommerce-gateway-paypossible' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Test Mode', 'woocommerce-gateway-paypossible' ),
				'description' => __( 'Place payment gateway in test mode.', 'woocommerce-gateway-paypossible' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'merchant_id'       => array(
				'title'       => __( 'Merchant ID', 'woocommerce-gateway-paypossible' ),
				'type'        => 'text',
				'description' => __( 'Your PayPossible Merchant ID.', 'woocommerce-gateway-paypossible' ),
				'desc_tip'    => true,
			),
			'token'             => array(
				'title'       => __( 'API Token', 'woocommerce-gateway-paypossible' ),
				'type'        => 'password',
				'description' => __( 'Your PayPossible API token.', 'woocommerce-gateway-paypossible' ),
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
				'price'       => $product->get_price( 'edit' ),
				'quantity'    => $item->get_quantity(),
			);
		}
		$discount_total = number_format( $order->get_total_discount(), 2, '.', '' );
		$shipping_total = number_format( $order->get_total_shipping(), 2, '.', '' );
		$tax_total      = number_format( $order->get_total_tax(), 2, '.', '' );

		$request_data = wp_json_encode(
			array(
				'discount'     => $discount_total,
				'items'        => $cart_items,
				'reference_id' => $order->get_id(),
				'shipping'     => $shipping_total,
				'tax'          => $tax_total,
			)
		);

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
			wc_add_notice( __( 'There was an error transferring cart. Please try again.', 'woocommerce-gateway-paypossible' ), 'error' );
			return;
		}

		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );

		if ( ! isset( $response_data['url'] ) ) {
			wc_add_notice( __( 'There was an error transferring cart. Please try again.', 'woocommerce-gateway-paypossible' ), 'error' );
			return;
		}

		$cart_url = $response_data['url'];

		$address      = array(
			'street1' => $order->get_billing_address_1(),
			'street2' => $order->get_billing_address_2(),
			'city'    => $order->get_billing_city(),
			'state'   => $order->get_billing_state(),
			'zip'     => $order->get_billing_postcode(),
		);
		$merchant_url = 'https://' . $this->domain . '/api/v1/merchants/' . $this->merchant_id . '/';
		$nonce        = substr( str_shuffle( MD5( microtime() ) ), 0, 12 );
		$personal     = array(
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'email'      => $order->get_billing_email(),
			'phone'      => $order->get_billing_phone(),
		);

		$request_data = wp_json_encode(
			array(
				'address'      => $address,
				'agree'        => true,
				'callback_url' => $this->get_callback_url( $order_id, $nonce ),
				'cancel_url'   => $order->get_cancel_order_url_raw(),
				'cart'         => array( 'url' => $cart_url ),
				'channel'      => 'woocommerce',
				'ip_address'   => $order->get_customer_ip_address(),
				'merchant'     => array( 'url' => $merchant_url ),
				'personal'     => $personal,
				'redirect_url' => $this->get_return_url( $order ),
			)
		);

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
			wc_add_notice( __( 'There was an error starting applicationt. Please try again.', 'woocommerce-gateway-paypossible' ), 'error' );
			return;
		}

		$response_body     = wp_remote_retrieve_body( $response );
			$response_data = json_decode( $response_body, true );

		if ( ! isset( $response_data['app_url'] ) ) {
			wc_add_notice( __( 'There was an error starting applicationt. Please try again.', 'woocommerce-gateway-paypossible' ), 'error' );
			return;
		}

		$app_url = $response_data['app_url'];
		$lead_id = $response_data['id'];

		$order->update_status( 'on-hold', __( 'Awaiting customer application.', 'woocommerce-gateway-paypossible' ) );
		WC()->cart->empty_cart();

		wc_add_order_item_meta( $order_id, 'callback_nonce', $nonce );
		wc_add_order_item_meta( $order_id, 'lead_id', $lead_id );

		return array(
			'result'   => 'success',
			'redirect' => $app_url,
		);
	}

	/**
	 * Payment complete callback
	 */
	public function callback() {
		header( 'HTTP/1.1 200 OK' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$nonce    = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : null;
		$order_id = isset( $_GET['order_id'] ) ? sanitize_text_field( wp_unslash( $_GET['order_id'] ) ) : null;

		if ( is_null( $nonce ) || is_null( $order_id ) ) {
			return;
		}

		if ( wc_get_order_item_meta( $order_id, 'callback_nonce' ) !== $nonce ) {
			return;
		}

			$order = wc_get_order( $order_id );
		$order->payment_complete();
	}

	/**
	 * Get the callback URL
	 *
	 * @param string $order_id The Order ID.
	 * @param string $nonce The nonce.
	 */
	public function get_callback_url( $order_id, $nonce ) {
		returnadd_query_arg(
			array(
				'nonce'    => $nonce,
				'order_id' => $order_id,
			),
			$this->get_callback_endpoint()
		);
	}

	/**
	 * Get the callback endpoint
	 */
	public function get_callback_endpoint() {
		return home_url( '/wc-api/paypossible/' );
	}
}
