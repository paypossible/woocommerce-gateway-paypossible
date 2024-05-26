<?php
/**
 * WC PayPossible Class
 *
 * @package  WooCommerce PayPossible Gateway
 * Plugin Name: WooCommerce PayPossible Gateway
 * Plugin URI: https://github.com/paypossible/woocommerce-gateway-paypossible
 * Description: WooCommerce payment gateway for PayPossible.
 * Version: 1.0.0
 * Author: PayPossible, Inc.
 * Author URI: https://paypossible.com
 * Requires Plugins: woocommerce
 * Text Domain: woocommece-gateway-paypossible
 */

// phpcs:disable WordPress.Files.FileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 *  Make sure WooCommerce is active.
 *
 *  @since 4.0
 */
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
	return;
}

/**
 * WC PayPossible gateway plugin class.
 *
 * @class WC_PayPossible
 */
class WC_PayPossible {
	/**
	 * Plugin bootstrapping.
	 */
	public static function init() {
		// PayPossible gateway class.
		add_action( 'plugins_loaded', array( __CLASS__, 'includes' ), 0 );

		// Make the PayPossible gateway available to WC.
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateway' ) );

		// Registers WooCommerce Blocks integration.
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'woocommerce_gateway_paypossible_woocommerce_block_support' ) );
	}

	/**
	 * Add the PayPossible gateway to the list of available gateways.
	 *
	 * @param array $gateways List of available gateways.
	 */
	public static function add_gateway( $gateways ) {
		$options = get_option( 'woocommerce_paypossible_settings', array() );

		if ( isset( $options['hide_for_non_admin_users'] ) ) {
			$hide_for_non_admin_users = $options['hide_for_non_admin_users'];
		} else {
			$hide_for_non_admin_users = 'no';
		}

		if ( ( 'yes' === $hide_for_non_admin_users && current_user_can( 'manage_options' ) ) || 'no' === $hide_for_non_admin_users ) {
			$gateways[] = 'WC_Gateway_PayPossible';
		}
		return $gateways;
	}

	/**
	 * Plugin includes.
	 */
	public static function includes() {
		// Make the WC_Gateway_PayPossible class available.
		if ( class_exists( 'WC_Payment_Gateway' ) ) {
			require_once 'includes/class-wc-gateway-paypossible.php';
		}
	}

	/**
	 * Plugin url.
	 *
	 * @return string
	 */
	public static function plugin_url() {
		return untrailingslashit( plugins_url( '/', __FILE__ ) );
	}

	/**
	 * Plugin url.
	 *
	 * @return string
	 */
	public static function plugin_abspath() {
		return trailingslashit( plugin_dir_path( __FILE__ ) );
	}

	/**
	 * Registers WooCommerce Blocks integration.
	 */
	public static function woocommerce_gateway_paypossible_woocommerce_block_support() {
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			require_once 'includes/blocks/class-wc-gateway-paypossible-blocks-support.php';
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
					$payment_method_registry->register( new WC_Gateway_PayPossible_Blocks_Support() );
				}
			);
		}
	}
}

WC_PayPossible::init();
