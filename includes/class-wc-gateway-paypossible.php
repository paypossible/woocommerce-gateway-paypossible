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
		$this->supports           = array( 'products', 'refunds' );

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

		add_action( 'woocommerce_thankyou', array( $this, 'empty_cart_on_thankyou' ), 10, 1 );
		add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'thankyou_retry_link' ), 10, 2 );

		add_filter( 'woocommerce_order_actions', array( $this, 'add_order_actions' ) );
		add_action( 'woocommerce_order_action_paypossible_notify_shipped', array( $this, 'run_notify_shipped_action' ) );
		add_action( 'woocommerce_order_action_paypossible_notify_cancelled', array( $this, 'run_notify_cancelled_action' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
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

		$cart_endpoint = 'https://' . $this->domain . '/api/v1/carts/';
		$response      = wp_remote_post(
			$cart_endpoint,
			array(
				'body'    => $request_data,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_api_failure( 'cart_create', $cart_endpoint, $response );
			$message = __( 'There was an error transferring cart. Please try again.', 'woocommerce-gateway-paypossible' );
			wc_add_notice( $message, 'error' );
			return array(
				'result'  => 'failure',
				'message' => $message,
			);
		}

		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );

		if ( ! isset( $response_data['url'] ) ) {
			$this->log_api_failure( 'cart_create', $cart_endpoint, $response );
			$message = __( 'There was an error transferring cart. Please try again.', 'woocommerce-gateway-paypossible' );
			wc_add_notice( $message, 'error' );
			return array(
				'result'  => 'failure',
				'message' => $message,
			);
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

		$lead_endpoint = 'https://' . $this->domain . '/api/v1/leads/';
		$response      = wp_remote_post(
			$lead_endpoint,
			array(
				'body'    => $request_data,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Token ' . $this->token,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_api_failure( 'lead_create', $lead_endpoint, $response );
			$message = __( 'There was an error starting application. Please try again.', 'woocommerce-gateway-paypossible' );
			wc_add_notice( $message, 'error' );
			return array(
				'result'  => 'failure',
				'message' => $message,
			);
		}

		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );

		if ( ! isset( $response_data['app_url'] ) ) {
			$this->log_api_failure( 'lead_create', $lead_endpoint, $response );
			$message = __( 'There was an error starting application. Please try again.', 'woocommerce-gateway-paypossible' );
			wc_add_notice( $message, 'error' );
			return array(
				'result'  => 'failure',
				'message' => $message,
			);
		}

		$app_url = $response_data['app_url'];
		$lead_id = $response_data['id'];

		$order->update_meta_data( '_paypossible_callback_nonce', $nonce );
		$order->update_meta_data( '_paypossible_lead_id', $lead_id );
		$order->update_status( 'pending', __( 'Awaiting customer application.', 'woocommerce-gateway-paypossible' ) );

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

		$type   = (string) $event['type'];
		$obj_id = (string) $event['id'];
		$status = (string) $event['status'];

		$key  = $type . ':' . $obj_id . ':' . $status;
		$seen = json_decode( (string) $order->get_meta( '_paypossible_seen_events' ), true );
		if ( ! is_array( $seen ) ) {
			$seen = array();
		}
		if ( in_array( $key, $seen, true ) ) {
			wp_send_json(
				array(
					'success'   => true,
					'duplicate' => true,
				),
				200
			);
			return;
		}
		$seen[] = $key;
		$order->update_meta_data( '_paypossible_seen_events', wp_json_encode( $seen ) );

		$order->add_order_note( sprintf( 'PayPossible %s %s: %s', $type, $obj_id, $status ) );

		if ( 'order' === $type ) {
			$this->handle_order_event( $order, $obj_id, $status );
		} elseif ( 'lead' === $type ) {
			$this->handle_lead_event( $order, $obj_id, $status );
		}

		$order->save();
		wp_send_json( array( 'success' => true ), 200 );
	}

	/**
	 * Apply a PayPossible order-type event to a WooCommerce order.
	 *
	 * Transition-only. The callback wrapper owns dedupe and informational
	 * order notes; WC handles stock reduction on the resulting status
	 * transitions so we never touch stock directly.
	 *
	 * @param WC_Order $order       The WooCommerce order.
	 * @param string   $pp_order_id The PayPossible order id from the event.
	 * @param string   $pp_status   The PayPossible status from the event.
	 */
	private function handle_order_event( $order, $pp_order_id, $pp_status ) {
		if ( '' === (string) $order->get_meta( '_paypossible_order_id' ) ) {
			$order->update_meta_data( '_paypossible_order_id', $pp_order_id );
		}

		switch ( $pp_status ) {
			case 'approved':
				$order->payment_complete( $pp_order_id );
				break;

			case 'cancelled':
				if ( ! $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
					$order->update_status( 'cancelled' );
				}
				break;

			case 'refunded':
				if ( ! $order->has_status( 'refunded' ) ) {
					$order->update_status( 'refunded' );
				}
				break;
		}
	}

	/**
	 * Apply a PayPossible lead-type event to a WooCommerce order.
	 *
	 * Transition-only. The callback wrapper owns dedupe and informational
	 * order notes. Only terminal lead statuses (declined, expired) affect
	 * WC state — all other lead statuses are driven by the corresponding
	 * order-type events elsewhere in the lifecycle.
	 *
	 * @param WC_Order $order       The WooCommerce order.
	 * @param string   $lead_id     The PayPossible lead id from the event.
	 * @param string   $lead_status The PayPossible lead status from the event.
	 */
	private function handle_lead_event( $order, $lead_id, $lead_status ) {
		switch ( $lead_status ) {
			case 'declined':
				if ( ! $order->has_status( array( 'failed', 'cancelled', 'refunded' ) ) ) {
					$order->update_status( 'failed' );
				}
				break;

			case 'expired':
				if ( ! $order->has_status( array( 'failed', 'cancelled', 'refunded' ) ) ) {
					$order->update_status( 'cancelled' );
				}
				break;
		}
	}

	/**
	 * Empty the customer's cart when they land on the thank-you page for a
	 * successful PayPossible order. Runs at most once per order.
	 *
	 * @param int $order_id The WooCommerce order id.
	 */
	public function empty_cart_on_thankyou( $order_id ) {
		if ( ! $order_id ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || 'paypossible' !== $order->get_payment_method() ) {
			return;
		}
		if ( 'yes' === (string) $order->get_meta( '_paypossible_cart_cleared' ) ) {
			return;
		}
		if ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
			return;
		}
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		$order->update_meta_data( '_paypossible_cart_cleared', 'yes' );
		$order->save();
	}

	/**
	 * Append a "Try a different payment method" link to the thank-you page
	 * text for failed PayPossible orders. Points at the WC order-pay endpoint
	 * so the customer can retry the same order with another gateway.
	 *
	 * @param string        $text  The existing thank-you text.
	 * @param WC_Order|null $order The order shown on the thank-you page.
	 * @return string
	 */
	public function thankyou_retry_link( $text, $order ) {
		if ( ! $order || 'paypossible' !== $order->get_payment_method() || ! $order->has_status( 'failed' ) ) {
			return $text;
		}
		$retry_url = $order->get_checkout_payment_url();
		$label     = esc_html__( 'Try a different payment method', 'woocommerce-gateway-paypossible' );
		return $text . ' <a class="button" href="' . esc_url( $retry_url ) . '">' . $label . '</a>';
	}

	/**
	 * Notify PayPossible that the merchant marked the order fulfilled.
	 *
	 * @param int $order_id The WooCommerce order id.
	 * @return true|WP_Error
	 */
	public function notify_shipped( $order_id ) {
		return $this->notify_lifecycle( $order_id, 'ship', '_paypossible_shipped_notified' );
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
	 * @return true|WP_Error
	 */
	public function notify_refunded( $order_id, $refund_id ) {
		return $this->notify_lifecycle( $order_id, 'refund', '_paypossible_refunded_notified' );
	}

	/**
	 * Notify PayPossible that the WooCommerce order was cancelled.
	 *
	 * @param int $order_id The WooCommerce order id.
	 * @return true|WP_Error
	 */
	public function notify_cancelled( $order_id ) {
		return $this->notify_lifecycle( $order_id, 'cancel', '_paypossible_cancelled_notified' );
	}

	/**
	 * WC Payment Gateway refund entry point. Fires when the merchant clicks
	 * "Refund via PayPossible" in the WC refund modal. Returns WP_Error to
	 * veto the WC-side refund record on any failure.
	 *
	 * @param int    $order_id WooCommerce order id.
	 * @param float  $amount   Refund amount (unused in the bare POST body).
	 * @param string $reason   Merchant-provided reason (unused in the bare POST body).
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'invalid_order', __( 'Invalid order.', 'woocommerce-gateway-paypossible' ) );
		}
		if ( 'paypossible' !== $order->get_payment_method() ) {
			return new WP_Error( 'wrong_gateway', __( 'This order was not paid via PayPossible.', 'woocommerce-gateway-paypossible' ) );
		}
		return $this->notify_lifecycle( $order_id, 'refund', '_paypossible_refunded_notified' );
	}

	/**
	 * Common outbound lifecycle notification. Checks PayPossible's remote
	 * order status before POSTing so we no-op when the action is already
	 * complete on their side.
	 *
	 * @param int    $order_id The WooCommerce order id.
	 * @param string $action   'ship', 'refund', or 'cancel'.
	 * @param string $flag_key Order meta key that guards against duplicate sends.
	 * @return true|WP_Error
	 */
	private function notify_lifecycle( $order_id, $action, $flag_key ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'paypossible' !== $order->get_payment_method() ) {
			return true;
		}
		if ( 'yes' === (string) $order->get_meta( $flag_key ) ) {
			return true;
		}

		$pp_order_id = (string) $order->get_meta( '_paypossible_order_id' );
		if ( '' === $pp_order_id ) {
			$order->add_order_note( sprintf( 'PayPossible %s: skipped, no PayPossible order id yet.', $action ) );
			$order->save();
			return new WP_Error( 'no_paypossible_order', __( 'No PayPossible order id on this order yet.', 'woocommerce-gateway-paypossible' ) );
		}

		$terminal_statuses = array(
			'ship'   => array( 'shipped' ),
			'cancel' => array( 'cancelling', 'cancelled' ),
			'refund' => array( 'refunding', 'refunded' ),
		);

		$current = $this->get_paypossible_order_status( $pp_order_id );
		if ( null === $current ) {
			$order->add_order_note( sprintf( 'PayPossible %s: could not read remote order status; will retry.', $action ) );
			$order->save();
			return new WP_Error( 'status_lookup_failed', __( 'Could not read PayPossible order status.', 'woocommerce-gateway-paypossible' ) );
		}

		if ( in_array( $current, $terminal_statuses[ $action ], true ) ) {
			$order->update_meta_data( $flag_key, 'yes' );
			$order->add_order_note( sprintf( 'PayPossible %s: already %s remotely; skipping.', $action, $current ) );
			$order->save();
			return true;
		}

		$response = $this->send_paypossible_request( '/api/v1/orders/' . rawurlencode( $pp_order_id ) . '/' . $action . '/' );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			$error_msg = is_wp_error( $response ) ? $response->get_error_message() : (string) wp_remote_retrieve_response_code( $response );
			$order->add_order_note( sprintf( 'PayPossible %s notification failed: %s', $action, $error_msg ) );
			$order->save();
			return new WP_Error( 'paypossible_' . $action . '_failed', $error_msg );
		}

		$order->update_meta_data( $flag_key, 'yes' );
		$order->add_order_note( sprintf( 'PayPossible %s notification sent.', $action ) );
		$order->save();
		return true;
	}

	/**
	 * GET the current PayPossible order status.
	 *
	 * @param string $pp_order_id The PayPossible order id.
	 * @return string|null Status string on success, null if the call failed or
	 *                     the response was missing a status field.
	 */
	private function get_paypossible_order_status( $pp_order_id ) {
		$response = $this->send_paypossible_request(
			'/api/v1/orders/' . rawurlencode( $pp_order_id ) . '/',
			null,
			'GET'
		);
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['status'] ) ) {
			return null;
		}
		return (string) $body['status'];
	}

	/**
	 * Add "Notify PayPossible: shipped/cancel" entries to the Order Actions
	 * dropdown on the order edit screen. Only shown for PayPossible orders
	 * that have a PayPossible order id and haven't yet had a successful
	 * notification for the given action.
	 *
	 * @param array $actions Existing actions.
	 * @return array
	 */
	public function add_order_actions( $actions ) {
		global $theorder;
		if ( ! $theorder || 'paypossible' !== $theorder->get_payment_method() ) {
			return $actions;
		}
		if ( '' === (string) $theorder->get_meta( '_paypossible_order_id' ) ) {
			return $actions;
		}
		if ( 'yes' !== (string) $theorder->get_meta( '_paypossible_shipped_notified' ) ) {
			$actions['paypossible_notify_shipped'] = __( 'Notify PayPossible: shipped', 'woocommerce-gateway-paypossible' );
		}
		if ( 'yes' !== (string) $theorder->get_meta( '_paypossible_cancelled_notified' ) ) {
			$actions['paypossible_notify_cancelled'] = __( 'Notify PayPossible: cancel order', 'woocommerce-gateway-paypossible' );
		}
		return $actions;
	}

	/**
	 * Order Actions dropdown handler for the shipped notification.
	 *
	 * @param WC_Order $order The order the action was invoked on.
	 */
	public function run_notify_shipped_action( $order ) {
		$result = $this->notify_shipped( $order->get_id() );
		$this->store_admin_notice( $order->get_id(), $result, 'shipped' );
	}

	/**
	 * Order Actions dropdown handler for the cancel notification.
	 *
	 * @param WC_Order $order The order the action was invoked on.
	 */
	public function run_notify_cancelled_action( $order ) {
		$result = $this->notify_cancelled( $order->get_id() );
		$this->store_admin_notice( $order->get_id(), $result, 'cancelled' );
	}

	/**
	 * Store the outcome of an Order Actions notification in a transient so
	 * render_admin_notice() can surface it on the resulting order edit page.
	 *
	 * @param int             $order_id The WooCommerce order id.
	 * @param true|WP_Error   $result   Result from a notify_* call.
	 * @param string          $action   Human-readable action name for the message.
	 */
	private function store_admin_notice( $order_id, $result, $action ) {
		$key = 'paypossible_action_' . $order_id;
		if ( is_wp_error( $result ) ) {
			set_transient(
				$key,
				array(
					'type'    => 'error',
					'message' => sprintf( 'PayPossible %s failed: %s', $action, $result->get_error_message() ),
				),
				60
			);
		} else {
			set_transient(
				$key,
				array(
					'type'    => 'success',
					'message' => sprintf( 'PayPossible %s notification sent.', $action ),
				),
				60
			);
		}
	}

	/**
	 * Render any pending admin notice for the current order on the order
	 * edit screen. Tolerates both legacy CPT and HPOS order screens.
	 */
	public function render_admin_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		if ( 'shop_order' !== $screen->id && 'woocommerce_page_wc-orders' !== $screen->id ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : ( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		// phpcs:enable
		if ( ! $order_id ) {
			return;
		}
		$key    = 'paypossible_action_' . $order_id;
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		$class = 'error' === $notice['type'] ? 'notice-error' : 'notice-success';
		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Log an API failure via the WC logger. Includes the endpoint, HTTP
	 * status code, and response body (or WP_Error details for network
	 * failures). Never logs the outgoing request body or headers, so the
	 * API token stays out of the log.
	 *
	 * @param string         $stage    Short label for where the failure occurred.
	 * @param string         $endpoint The full URL we tried to hit.
	 * @param array|WP_Error $response The wp_remote_* result.
	 */
	private function log_api_failure( $stage, $endpoint, $response ) {
		if ( is_wp_error( $response ) ) {
			$detail = array(
				'stage'    => $stage,
				'endpoint' => $endpoint,
				'wp_error' => $response->get_error_code(),
				'message'  => $response->get_error_message(),
			);
		} else {
			$detail = array(
				'stage'     => $stage,
				'endpoint'  => $endpoint,
				'http_code' => (int) wp_remote_retrieve_response_code( $response ),
				'response'  => wp_remote_retrieve_body( $response ),
			);
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( wp_json_encode( $detail ), array( 'source' => 'paypossible' ) );
		}
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
