<?php
/**
 * WC Gateway PayPossible Blocks Support Class
 *
 * @package  WooCommerce PayPossible Gateway
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * PayPossible Payment Blocks integration
 *
 * @since 1.0.0
 */
final class WC_Gateway_PayPossible_Blocks_Support extends AbstractPaymentMethodType {
	/**
	 * The gateway instance.
	 *
	 * @var WC_Gateway_PayPossible
	 */
	private $gateway;

	/**
	 * Payment method name/id/slug.
	 *
	 * @var string
	 */
	protected $name = 'paypossible';

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_paypossible_settings', array() );
		$gateways       = WC()->payment_gateways->payment_gateways();
		$this->gateway  = $gateways[ $this->name ];
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return $this->gateway->is_available();
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$script_path       = '/assets/js/frontend/blocks.js';
		$script_asset_path = WC_PayPossible::plugin_abspath() . 'assets/js/frontend/blocks.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => '1.0.0',
			);
		$script_url        = WC_PayPossible::plugin_url() . $script_path;

		wp_register_script(
			'wc-paypossible-blocks',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wc-paypossible-blocks', 'woocommerce-gateway-paypossible', WC_PayPossible::plugin_abspath() . 'languages/' );
		}

		return array( 'wc-paypossible-blocks' );
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'             => $this->get_setting( 'title' ),
			'description'       => $this->get_setting( 'description' ),
			'order_button_text' => $this->get_setting( 'order_button_text' ),
			'supports'          => array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ),
		);
	}
}
