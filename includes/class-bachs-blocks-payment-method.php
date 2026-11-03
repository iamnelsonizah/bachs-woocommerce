<?php
/**
 * Server-side registration for WooCommerce Checkout Blocks.
 *
 * This file is loaded only after WooCommerce Blocks exposes its integration API.
 *
 * @package Bachs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bachs_Blocks_Payment_Method extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
	/** @var string */
	protected $name = 'bachs';

	/**
	 * Load gateway settings for the current checkout block request.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( Bachs_Settings::OPTION_NAME, array() );
		if ( ! is_array( $this->settings ) ) {
			$this->settings = array();
		}
	}

	/**
	 * Check whether the gateway is enabled and configured.
	 *
	 * @return bool
	 */
	public function is_active() {
		$environment = Bachs_Settings::environment();
		$key         = Bachs_Settings::api_key();
		$prefix      = 'live' === $environment ? 'sk_live_' : 'sk_sandbox_';
		return 'yes' === $this->setting( 'enabled', 'no' )
			&& 0 === strpos( $key, $prefix )
			&& Bachs_Settings::has_current_webhook_secret()
			&& ( 'sandbox' === $environment || is_ssl() );
	}

	/**
	 * Register the small no-build client script for Checkout Blocks.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'bachs-wc-blocks',
			BACHS_WC_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
			BACHS_WC_VERSION,
			true
		);

		return array( 'bachs-wc-blocks' );
	}

	/**
	 * Provide only public checkout copy to the browser.
	 *
	 * Secret keys and webhook secrets are never localized into frontend scripts.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->setting( 'title', __( 'Bachs', 'bachs-woocommerce' ) ),
			'description' => $this->setting( 'description', __( 'You will complete payment on Bachs secure checkout, then return to this store.', 'bachs-woocommerce' ) ),
			'supports'    => array( 'products' ),
		);
	}

	/**
	 * Read a string setting from the gateway options.
	 *
	 * @param string $key Option key.
	 * @param string $default Fallback.
	 * @return string
	 */
	private function setting( $key, $default ) {
		$value = isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
		return is_string( $value ) ? $value : $default;
	}
}
