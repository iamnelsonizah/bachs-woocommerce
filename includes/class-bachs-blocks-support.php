<?php
/**
 * Conditional loader for WooCommerce Cart and Checkout Blocks support.
 *
 * @package Bachs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bachs_Blocks_Support {
	/**
	 * Register the integration hook without requiring the Blocks package.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( __CLASS__, 'register_payment_method' ) );
	}

	/**
	 * Load and register the Blocks integration when WooCommerce provides it.
	 *
	 * @param object $registry Payment method registry.
	 * @return void
	 */
	public static function register_payment_method( $registry ) {
		$abstract = '\\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType';
		if ( ! class_exists( $abstract ) || ! is_object( $registry ) ) {
			return;
		}

		require_once BACHS_WC_PATH . 'includes/class-bachs-blocks-payment-method.php';
		if ( class_exists( 'Bachs_Blocks_Payment_Method' ) ) {
			$registry->register( new Bachs_Blocks_Payment_Method() );
		}
	}
}
