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
	 * List Bachs products using cursor pagination.
	 *
	 * @param array $filters Supported filters such as limit and cursor.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function list_products( $filters = array() ) {
		return $this->request( 'GET', $this->query_path( '/v1/products', $filters, array( 'limit', 'cursor', 'include_archived' ) ) );
	}

	/**
	 * Create a Bachs catalog product.
	 *
	 * @param array  $payload Product data.
	 * @param string $idempotency_key Stable key for retries.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function create_product( $payload, $idempotency_key ) {
		return $this->request( 'POST', '/v1/products', $payload, $idempotency_key );
	}

	/**
	 * List the merchant's subscriptions using offset pagination.
	 *
	 * @param array $filters Supported filters such as limit, offset and status_filter.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function list_subscriptions( $filters = array() ) {
		return $this->request( 'GET', $this->query_path( '/v1/subscriptions', $filters, array( 'limit', 'offset', 'status', 'customer_id' ) ) );
	}

	/**
	 * Cancel a subscription now or at the end of its current billing period.
	 *
	 * @param string $subscription_id Bachs subscription identifier.
	 * @param array  $payload Cancellation options.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function cancel_subscription( $subscription_id, $payload ) {
		return $this->request( 'DELETE', '/v1/subscriptions/' . rawurlencode( $subscription_id ), $payload );
	}

	/**
	 * Read available and pending balance buckets.
	 *
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function get_balances() {
		return $this->request( 'GET', '/v1/balances' );
	}

	/**
	 * Read automatic payout schedules for the merchant's account.
	 *
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function get_payout_schedule() {
		return $this->request( 'GET', '/v1/balance_settings' );
	}

	/**
	 * Update schedules for only the currencies provided in the payload.
	 *
	 * Bachs leaves omitted currencies unchanged. Each included currency is
	 * replaced in full, so callers should send the complete desired schedule.
	 *
	 * @param array $payload Schedule map keyed by balance currency.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function update_payout_schedule( $payload ) {
		return $this->request( 'POST', '/v1/balance_settings', $payload );
	}

	/**
	 * List approved and pending payout destinations.
	 *
	 * @param array $filters Supported filters such as limit and offset.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function list_payout_destinations( $filters = array() ) {
		return $this->request( 'GET', $this->query_path( '/v1/payouts/destinations', $filters, array( 'limit', 'offset', 'status', 'currency' ) ) );
	}

	/**
	 * List recent payouts.
	 *
	 * @param array $filters Supported filters such as limit and offset.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function list_payouts( $filters = array() ) {
		return $this->request( 'GET', $this->query_path( '/v1/payouts', $filters, array( 'limit', 'offset', 'status_filter', 'reference' ) ) );
	}

	/**
	 * Create a payout to a registered Bachs destination.
	 *
	 * @param array  $payload Destination and payout amount.
	 * @param string $idempotency_key Stable key for retries.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function create_payout( $payload, $idempotency_key ) {
		return $this->request( 'POST', '/v1/payouts', $payload, $idempotency_key );
	}

	/**
	 * List open disputes using offset pagination.
	 *
	 * @param array $filters Supported filters such as limit and offset.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function list_disputes( $filters = array() ) {
		return $this->request( 'GET', $this->query_path( '/v1/disputes', $filters, array( 'limit', 'offset', 'status' ) ) );
	}

	/**
	 * Retrieve a dispute and its current evidence fields.
	 *
	 * @param string $dispute_id Bachs dispute identifier.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function retrieve_dispute( $dispute_id ) {
		return $this->request( 'GET', '/v1/disputes/' . rawurlencode( $dispute_id ) );
	}

	/**
	 * Save evidence fields before submitting a dispute response.
	 *
	 * @param string $dispute_id Bachs dispute identifier.
	 * @param array  $payload Evidence fields.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function update_dispute_evidence( $dispute_id, $payload ) {
		return $this->request( 'PATCH', '/v1/disputes/' . rawurlencode( $dispute_id ) . '/evidence', $payload );
	}

	/**
	 * Submit the saved dispute evidence to Bachs.
	 *
	 * @param string $dispute_id Bachs dispute identifier.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function submit_dispute( $dispute_id ) {
		return $this->request( 'POST', '/v1/disputes/' . rawurlencode( $dispute_id ) . '/submit', array() );
	}

	/**
	 * Upload a single supported dispute evidence document to Bachs.
	 *
	 * The caller validates the actual file type and size. The filename is still
	 * reduced to a safe basename before it enters the multipart body.
	 *
	 * @param string $filename Safe client filename.
	 * @param string $mime_type Verified MIME type.
	 * @param string $contents File bytes, up to the provider's 10 MB limit.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function upload_dispute_document( $filename, $mime_type, $contents ) {
		$filename = sanitize_file_name( wp_basename( $filename ) );
		$allowed_mime_types = array( 'application/pdf', 'image/jpeg', 'image/png', 'image/gif' );
		if ( '' === $filename || ! in_array( $mime_type, $allowed_mime_types, true ) || ! is_string( $contents ) || strlen( $contents ) > 10485760 ) {
			throw new Bachs_Api_Exception( __( 'The dispute document is invalid or exceeds the upload limit.', 'bachs-woocommerce' ), 0, 'invalid_upload' );
		}

		$boundary = 'bachs-' . wp_generate_password( 32, false, false );
		$body = '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . str_replace( '"', '', $filename ) . "\"\r\n";
		$body .= 'Content-Type: ' . $mime_type . "\r\n\r\n";
		$body .= $contents . "\r\n--" . $boundary . "--\r\n";

		return $this->request( 'POST', '/v1/disputes/uploads', $body, '', 'multipart/form-data; boundary=' . $boundary );
	}

	/**
	 * Create or retrieve the merchant's fixed virtual account for a currency.
	 *
	 * @param array  $payload Virtual account request.
	 * @param string $idempotency_key Stable key for retries.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function create_virtual_account( $payload, $idempotency_key ) {
		return $this->request( 'POST', '/v1/virtual-accounts', $payload, $idempotency_key );
	}

	/**
	 * Read a previously issued virtual account.
	 *
	 * @param string $virtual_account_id Bachs virtual account identifier.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function retrieve_virtual_account( $virtual_account_id ) {
		return $this->request( 'GET', '/v1/virtual-accounts/' . rawurlencode( $virtual_account_id ) );
	}

	/**
	 * Retrieve Bachs checkout payment-method preferences.
	 *
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function get_checkout_settings() {
		return $this->request( 'GET', '/v1/accounts/checkout/settings' );
	}

	/**
	 * Update Bachs checkout payment-method preferences.
	 *
	 * @param array $payload Bachs checkout settings.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function update_checkout_settings( $payload ) {
		return $this->request( 'PUT', '/v1/accounts/checkout/settings', $payload );
	}

	/**
	 * Create a short-lived customer portal session for a known Bachs customer.
	 *
	 * @param string $customer_id Bachs customer identifier.
	 * @return array
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public function create_customer_portal_session( $customer_id ) {
		return $this->request( 'POST', '/v1/customers/' . rawurlencode( $customer_id ) . '/portal-sessions', null );
	}

	/**
	 * Send a request to a fixed Bachs API host.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Internal API path.
	 * @param array|string|null $payload Request body, or null when an endpoint has no body.
	 * @param string $idempotency_key Optional key for safe POST retries.
	 * @param string $content_type Content type for non-GET requests.
	 * @return array
	 * @throws Bachs_Api_Exception When transport or API validation fails.
	 */
	private function request( $method, $path, $payload = array(), $idempotency_key = '', $content_type = 'application/json' ) {
		if ( ! in_array( $this->environment, array( 'sandbox', 'live' ), true ) ) {
			throw new Bachs_Api_Exception( __( 'The Bachs environment is not configured correctly.', 'bachs-woocommerce' ) );
		}

		$expected_prefix = 'live' === $this->environment ? 'sk_live_' : 'sk_sandbox_';
		if ( '' === $this->api_key || 0 !== strpos( $this->api_key, $expected_prefix ) ) {
			throw new Bachs_Api_Exception( __( 'The Bachs API key does not match the selected environment.', 'bachs-woocommerce' ) );
		}

		if ( ! preg_match( '#^/v1/[A-Za-z0-9/_%-]+(?:\?[A-Za-z0-9_=&%.-]*)?$#', $path ) ) {
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

		if ( 'GET' !== strtoupper( $method ) && null !== $payload ) {
			$args['headers']['Content-Type'] = $content_type;
			$args['body'] = is_string( $payload ) ? $payload : wp_json_encode( $payload );
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

	/**
	 * Build a query string from a fixed allow-list of Bachs API filters.
	 *
	 * @param string $path API path.
	 * @param array  $filters User-supplied filters.
	 * @param array  $allowed Allowed filter names.
	 * @return string
	 */
	private function query_path( $path, $filters, $allowed ) {
		$query = array();
		foreach ( $allowed as $name ) {
			if ( ! isset( $filters[ $name ] ) || ! is_scalar( $filters[ $name ] ) || '' === (string) $filters[ $name ] ) {
				continue;
			}
			$query[ $name ] = sanitize_text_field( (string) $filters[ $name ] );
		}

		return empty( $query ) ? $path : $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}
}
