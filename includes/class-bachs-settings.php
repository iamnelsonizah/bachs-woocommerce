<?php
/**
 * Central access to merchant configuration and secret precedence.
 *
 * Constants allow hosting providers to keep credentials outside the database.
 * Dashboard fields are provided for stores that do not have a secrets manager.
 *
 * @package Bachs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bachs_Settings {
	const OPTION_NAME = 'woocommerce_bachs_settings';

	/**
	 * Return the saved gateway options.
	 *
	 * @return array
	 */
	public static function all() {
		$options = get_option( self::OPTION_NAME, array() );
		return is_array( $options ) ? $options : array();
	}

	/**
	 * Return the currently selected environment.
	 *
	 * @return string
	 */
	public static function environment() {
		$environment = defined( 'BACHS_WC_ENVIRONMENT' )
			? constant( 'BACHS_WC_ENVIRONMENT' )
			: self::option( 'environment', 'sandbox' );

		return in_array( $environment, array( 'sandbox', 'live' ), true ) ? $environment : 'sandbox';
	}

	/**
	 * Resolve the secret API key for the active environment.
	 *
	 * @return string
	 */
	public static function api_key() {
		$environment = self::environment();
		$constant    = 'live' === $environment ? 'BACHS_WC_LIVE_SECRET_KEY' : 'BACHS_WC_SANDBOX_SECRET_KEY';
		$option      = 'live' === $environment ? 'live_secret_key' : 'sandbox_secret_key';
		$key         = defined( $constant ) ? constant( $constant ) : self::option( $option, '' );

		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * Return the active webhook signing secrets, newest first.
	 *
	 * During Bachs secret rotation, both the current and previous secret may be
	 * used to sign deliveries. Testing each signature against both preserves
	 * webhook delivery while the merchant changes the configured secret.
	 *
	 * @return array
	 */
	public static function webhook_secrets() {
		$environment = self::environment();
		$current_name = 'live' === $environment ? 'BACHS_WC_LIVE_WEBHOOK_SECRET' : 'BACHS_WC_SANDBOX_WEBHOOK_SECRET';
		$previous_name = 'live' === $environment ? 'BACHS_WC_LIVE_WEBHOOK_SECRET_PREVIOUS' : 'BACHS_WC_SANDBOX_WEBHOOK_SECRET_PREVIOUS';
		$current_option = 'live' === $environment ? 'live_webhook_secret' : 'sandbox_webhook_secret';
		$previous_option = 'live' === $environment ? 'live_webhook_secret_previous' : 'sandbox_webhook_secret_previous';

		$current  = defined( $current_name ) ? constant( $current_name ) : self::option( $current_option, '' );
		$previous = defined( $previous_name ) ? constant( $previous_name ) : self::option( $previous_option, '' );
		$secrets  = array_filter(
			array_map(
				'trim',
				array( is_string( $current ) ? $current : '', is_string( $previous ) ? $previous : '' )
			)
		);

		return array_values( array_unique( $secrets ) );
	}

	/**
	 * Check that the active environment has its current webhook secret configured.
	 *
	 * @return bool
	 */
	public static function has_current_webhook_secret() {
		$environment = self::environment();
		$constant    = 'live' === $environment ? 'BACHS_WC_LIVE_WEBHOOK_SECRET' : 'BACHS_WC_SANDBOX_WEBHOOK_SECRET';
		$option      = 'live' === $environment ? 'live_webhook_secret' : 'sandbox_webhook_secret';
		$secret      = defined( $constant ) ? constant( $constant ) : self::option( $option, '' );
		return is_string( $secret ) && '' !== trim( $secret );
	}

	/**
	 * Return the merchant's checkout display preference.
	 *
	 * @return string
	 */
	public static function checkout_presentation() {
		$presentation = self::option( 'checkout_presentation', 'redirect' );
		return in_array( $presentation, array( 'redirect', 'overlay' ), true ) ? $presentation : 'redirect';
	}

	/**
	 * Read an option with a safe string fallback.
	 *
	 * @param string $key Option key.
	 * @param string $default Default value.
	 * @return string
	 */
	private static function option( $key, $default ) {
		$options = self::all();
		$value   = isset( $options[ $key ] ) ? $options[ $key ] : $default;
		return is_string( $value ) ? $value : $default;
	}
}
