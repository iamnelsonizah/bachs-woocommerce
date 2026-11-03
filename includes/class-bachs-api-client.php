<?php
/**
 * Small Bachs REST client built on WordPress's HTTP API.
 *
 * API hosts are fixed by environment, redirects are disabled, TLS verification
 * stays enabled, and request/response bodies are never written to logs.
 *
 * @package Bachs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bachs_Api_Client {
	/** @var string */
	private $environment;

	/** @var string */
	private $api_key;

	/**
	 * @param string|null $environment Optional environment override.
	 */
	public function __construct( $environment = null ) {
		$this->environment = $environment ? $environment : Bachs_Settings::environment();
		$this->api_key     = Bachs_Settings::api_key();
	}

	/**
	 * Create a Bachs hosted checkout session.
	 *
	 * @param array  $payload API request body.
	 * @param string $idempotency_key Stable key for retries of this checkout.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function create_checkout_session( $payload, $idempotency_key ) {
		return $this->request( 'POST', '/v1/checkout-sessions', $payload, $idempotency_key );
	}

	/**
	 * Retrieve a payment for reconciliation or refund eligibility checks.
	 *
	 * @param string $charge_id Bachs charge identifier.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function retrieve_payment( $charge_id ) {
		return $this->request( 'GET', '/v1/payments/' . rawurlencode( $charge_id ) );
	}

	/**
	 * Retrieve the session linked to a WooCommerce order for manual reconciliation.
	 *
	 * @param string $checkout_id Bachs checkout identifier.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function retrieve_checkout_session( $checkout_id ) {
		return $this->request( 'GET', '/v1/checkout-sessions/' . rawurlencode( $checkout_id ) );
	}

	/**
	 * Create one full or partial refund.
	 *
	 * @param array $payload API request body.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function create_refund( $payload ) {
		$key = isset( $payload['idempotency_key'] ) ? $payload['idempotency_key'] : '';
		return $this->request( 'POST', '/v1/refunds', $payload, $key );
	}

	/**
	 * Retrieve the refund associated with a charge during reconciliation.
	 *
	 * @param string $charge_id Bachs charge identifier.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function retrieve_refund_by_charge( $charge_id ) {
		return $this->request( 'GET', '/v1/refunds/by-charge/' . rawurlencode( $charge_id ) );
	}

	/**
	 * Send a JSON request to a fixed Bachs API host.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Internal API path.
	 * @param array  $payload Request body.
	 * @param string $idempotency_key Optional key for safe POST retries.
	 * @return array
	 * @throws Bachs_Api_Exception When transport or API validation fails.
	 */
	private function request( $method, $path, $payload = array(), $idempotency_key = '' ) {
		if ( ! in_array( $this->environment, array( 'sandbox', 'live' ), true ) ) {
			throw new Bachs_Api_Exception( __( 'The Bachs environment is not configured correctly.', 'bachs-woocommerce' ) );
		}

		$expected_prefix = 'live' === $this->environment ? 'sk_live_' : 'sk_sandbox_';
		if ( '' === $this->api_key || 0 !== strpos( $this->api_key, $expected_prefix ) ) {
			throw new Bachs_Api_Exception( __( 'The Bachs API key does not match the selected environment.', 'bachs-woocommerce' ) );
		}

		if ( ! preg_match( '#^/v1/[A-Za-z0-9/_-]+$#', $path ) ) {
			throw new Bachs_Api_Exception( __( 'The Bachs API path is invalid.', 'bachs-woocommerce' ) );
		}

		$base_url = 'live' === $this->environment ? 'https://api.bachs.io' : 'https://sandbox-api.bachs.io';
		$headers  = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Accept'        => 'application/json',
		);
		$args = array(
			'method'              => strtoupper( $method ),
			'timeout'             => 20,
			'redirection'         => 0,
			'limit_response_size' => 1048576,
			'sslverify'           => true,
			'headers'             => $headers,
		);

		if ( 'GET' !== strtoupper( $method ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $payload );
			if ( false === $args['body'] ) {
				throw new Bachs_Api_Exception( __( 'The Bachs request could not be encoded.', 'bachs-woocommerce' ) );
			}
		}

		if ( '' !== $idempotency_key ) {
			if ( strlen( $idempotency_key ) > 255 || preg_match( '/[\r\n]/', $idempotency_key ) ) {
				throw new Bachs_Api_Exception( __( 'The Bachs idempotency key is invalid.', 'bachs-woocommerce' ) );
			}
			$args['headers']['Idempotency-Key'] = $idempotency_key;
		}

		$response = wp_safe_remote_request( $base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new Bachs_Api_Exception( __( 'Bachs could not be reached. Please try again.', 'bachs-woocommerce' ), 0, 'transport_error' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			throw new Bachs_Api_Exception( __( 'Bachs returned an unreadable response. Please try again.', 'bachs-woocommerce' ), $status, 'invalid_response' );
		}

		if ( $status < 200 || $status >= 300 ) {
			$error_code = isset( $body['error_code'] ) ? sanitize_key( $body['error_code'] ) : 'api_error';
			throw new Bachs_Api_Exception( __( 'Bachs could not complete the request. Check the gateway settings and try again.', 'bachs-woocommerce' ), $status, $error_code );
		}

		return $body;
	}
}
