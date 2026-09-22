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

		add_action( 'woocommerce_order_status_completed', array( $this, 'notify_shipped' ), 10, 1 );
		add_action( 'woocommerce_fulfillment_after_fulfill', array( $this, 'notify_shipped_from_fulfillment' ), 10, 1 );
		add_action( 'woocommerce_order_refunded', array( $this, 'notify_refunded' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'notify_cancelled' ), 10, 1 );
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
				'reference_id' => $item_id,
				'description'  => $product->get_name(),
				'sku'          => $product->get_sku(),
				'price'        => $product->get_price( 'edit' ),
				'quantity'     => $item->get_quantity(),
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
		$nonce        = bin2hex( random_bytes( 16 ) );
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
			wc_add_notice( __( 'There was an error starting application. Please try again.', 'woocommerce-gateway-paypossible' ), 'error' );
			return;
		}

		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );

		if ( ! isset( $response_data['app_url'] ) ) {
			wc_add_notice( __( 'There was an error starting application. Please try again.', 'woocommerce-gateway-paypossible' ), 'error' );
			return;
		}

		$app_url = $response_data['app_url'];
		$lead_id = $response_data['id'];

		$order->update_meta_data( '_paypossible_callback_nonce', $nonce );
		$order->update_meta_data( '_paypossible_lead_id', $lead_id );
		$order->update_status( 'pending', __( 'Awaiting customer application.', 'woocommerce-gateway-paypossible' ) );
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $app_url,
		);
	}

	/**
	 * Webhook receiver for PayPossible lifecycle events.
	 *
	 * PayPossible POSTs a JSON body of the form { id, type, status } to the
	 * callback URL we handed it in process_payment(), which carries the
	 * per-order nonce and WooCommerce order id as query args.
	 */
	public function callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$nonce    = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

		if ( ! $order_id || '' === $nonce ) {
			wp_send_json( array( 'error' => 'Missing order_id or nonce' ), 400 );
			return;
		}

		$order        = wc_get_order( $order_id );
		$stored_nonce = $order ? (string) $order->get_meta( '_paypossible_callback_nonce' ) : '';

		if ( ! $order || '' === $stored_nonce || ! hash_equals( $stored_nonce, $nonce ) ) {
			wp_send_json( array( 'error' => 'Nonce does not match order ID' ), 400 );
			return;
		}

		$raw   = file_get_contents( 'php://input' );
		$event = json_decode( $raw, true );

		if ( ! is_array( $event ) || empty( $event['type'] ) || empty( $event['status'] ) || empty( $event['id'] ) ) {
			wp_send_json( array( 'error' => 'Invalid event payload' ), 400 );
			return;
		}

		if ( 'order' !== $event['type'] ) {
			wp_send_json(
				array(
					'success' => true,
					'ignored' => true,
				),
				200
			);
			return;
		}

		$this->handle_order_event( $order, (string) $event['id'], (string) $event['status'] );
		wp_send_json( array( 'success' => true ), 200 );
	}

	/**
	 * Apply a PayPossible order-type event to a WooCommerce order.
	 *
	 * Idempotent: repeated deliveries of the same status are ignored. WC's
	 * own status transitions handle stock reduction and date_paid; this
	 * method never touches stock directly.
	 *
	 * @param WC_Order $order       The WooCommerce order.
	 * @param string   $pp_order_id The PayPossible order id from the event.
	 * @param string   $pp_status   The PayPossible status from the event.
	 */
	private function handle_order_event( $order, $pp_order_id, $pp_status ) {
		if ( '' === (string) $order->get_meta( '_paypossible_order_id' ) ) {
			$order->update_meta_data( '_paypossible_order_id', $pp_order_id );
		}

		if ( $pp_status === (string) $order->get_meta( '_paypossible_last_order_status' ) ) {
			return;
		}
		$order->update_meta_data( '_paypossible_last_order_status', $pp_status );

		$note = sprintf( 'PayPossible order %s: %s', $pp_order_id, $pp_status );

		switch ( $pp_status ) {
			case 'pending':
			case 'sent':
			case 'approving':
			case 'processing':
			case 'paid':
			case 'shipped':
			case 'cancelling':
			case 'refunding':
				$order->add_order_note( $note );
				$order->save();
				break;

			case 'approved':
				$order->add_order_note( $note );
				$order->payment_complete( $pp_order_id );
				break;

			case 'cancelled':
				$order->add_order_note( $note );
				if ( ! $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
					$order->update_status( 'cancelled', $note );
				} else {
					$order->save();
				}
				break;

			case 'refunded':
				$order->add_order_note( $note );
				if ( ! $order->has_status( 'refunded' ) ) {
					$order->update_status( 'refunded', $note );
				} else {
					$order->save();
				}
				break;

			default:
				$order->add_order_note( sprintf( 'PayPossible order %s: unknown status "%s"', $pp_order_id, $pp_status ) );
				$order->save();
		}
	}

	/**
	 * Notify PayPossible that the merchant marked the order fulfilled.
	 *
	 * @param int $order_id The WooCommerce order id.
	 */
	public function notify_shipped( $order_id ) {
		$this->notify_lifecycle( $order_id, 'ship', '_paypossible_shipped_notified' );
	}

	/**
	 * Bridge from the WC Fulfillments API (woocommerce_fulfillment_after_fulfill)
	 * to notify_shipped(). Only entity_type=order fulfillments have an order id.
	 *
	 * @param mixed $fulfillment Fulfillment object.
	 */
	public function notify_shipped_from_fulfillment( $fulfillment ) {
		if ( ! is_object( $fulfillment ) ) {
			return;
		}
		if ( method_exists( $fulfillment, 'get_entity_type' ) && 'order' !== $fulfillment->get_entity_type() ) {
			return;
		}
		$order_id = method_exists( $fulfillment, 'get_entity_id' ) ? (int) $fulfillment->get_entity_id() : 0;
		if ( $order_id ) {
			$this->notify_shipped( $order_id );
		}
	}

	/**
	 * Notify PayPossible that the WooCommerce order was refunded.
	 *
	 * @param int $order_id  The WooCommerce order id.
	 * @param int $refund_id The refund id (unused).
	 */
	public function notify_refunded( $order_id, $refund_id ) {
		$this->notify_lifecycle( $order_id, 'refund', '_paypossible_refunded_notified' );
	}

	/**
	 * Notify PayPossible that the WooCommerce order was cancelled.
	 *
	 * @param int $order_id The WooCommerce order id.
	 */
	public function notify_cancelled( $order_id ) {
		$this->notify_lifecycle( $order_id, 'cancel', '_paypossible_cancelled_notified' );
	}

	/**
	 * Common outbound lifecycle notification.
	 *
	 * @param int    $order_id The WooCommerce order id.
	 * @param string $action   'ship', 'refund', or 'cancel'.
	 * @param string $flag_key Order meta key that guards against duplicate sends.
	 */
	private function notify_lifecycle( $order_id, $action, $flag_key ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'paypossible' !== $order->get_payment_method() ) {
			return;
		}
		if ( 'yes' === (string) $order->get_meta( $flag_key ) ) {
			return;
		}

		$pp_order_id = (string) $order->get_meta( '_paypossible_order_id' );
		if ( '' === $pp_order_id ) {
			$order->add_order_note( sprintf( 'PayPossible %s: skipped, no PayPossible order id yet.', $action ) );
			$order->save();
			return;
		}

		$response = $this->send_paypossible_request( '/api/v1/orders/' . rawurlencode( $pp_order_id ) . '/' . $action . '/' );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			$order->add_order_note( sprintf(
				'PayPossible %s notification failed: %s',
				$action,
				is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response )
			) );
			$order->save();
			return;
		}

		$order->update_meta_data( $flag_key, 'yes' );
		$order->add_order_note( sprintf( 'PayPossible %s notification sent.', $action ) );
		$order->save();
	}

	/**
	 * POST to a PayPossible API endpoint with Token auth.
	 *
	 * @param string     $path   The path beginning with a leading slash.
	 * @param array|null $body   Optional JSON body.
	 * @param string     $method HTTP method.
	 * @return array|WP_Error
	 */
	private function send_paypossible_request( $path, $body = null, $method = 'POST' ) {
		$args = array(
			'method'  => $method,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Token ' . $this->token,
			),
			'timeout' => 10,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		return wp_remote_request( 'https://' . $this->domain . $path, $args );
	}

	/**
	 * Get the callback URL
	 *
	 * @param string $order_id The Order ID.
	 * @param string $nonce The nonce.
	 */
	public function get_callback_url( $order_id, $nonce ) {
		return add_query_arg(
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
