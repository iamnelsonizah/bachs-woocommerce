<?php
/**
 * Public REST callback for signed Bachs events.
 *
 * @package Bachs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bachs_Webhook_Controller {
	const ROUTE_NAMESPACE = 'bachs-wc/v1';
	const ROUTE            = '/webhook';
	const MAX_BODY_BYTES   = 1048576;

	/**
	 * Register the REST route before the REST API builds its route map.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	/**
	 * Register a public endpoint. The signature, rather than a WordPress login,
	 * authenticates Bachs because provider requests cannot carry a WP nonce.
	 *
	 * @return void
	 */
	public static function register_route() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Verify and process a provider event.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function receive( $request ) {
		$raw_body = $request->get_body();
		if ( strlen( $raw_body ) > self::MAX_BODY_BYTES ) {
			return new WP_Error( 'bachs_payload_too_large', __( 'The Bachs webhook payload is too large.', 'bachs-woocommerce' ), array( 'status' => 413 ) );
		}

		$secrets = Bachs_Settings::webhook_secrets();
		if ( empty( $secrets ) ) {
			return new WP_Error( 'bachs_webhook_not_configured', __( 'The Bachs webhook signing secret is not configured.', 'bachs-woocommerce' ), array( 'status' => 503 ) );
		}

		$headers = array(
			'x-bachs-signature-v2' => $request->get_header( 'x-bachs-signature-v2' ),
			'x-bachs-timestamp'    => $request->get_header( 'x-bachs-timestamp' ),
			'x-bachs-signature'    => $request->get_header( 'x-bachs-signature' ),
		);

		if ( ! Bachs_Webhook_Verifier::verify( $raw_body, $headers, $secrets ) ) {
			return new WP_Error( 'bachs_invalid_signature', __( 'The Bachs webhook signature is invalid or expired.', 'bachs-woocommerce' ), array( 'status' => 400 ) );
		}

		$event = json_decode( $raw_body, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) || ! is_string( $event['id'] ) || empty( $event['type'] ) || ! is_string( $event['type'] ) || ! isset( $event['data'] ) || ! is_array( $event['data'] ) ) {
			return new WP_Error( 'bachs_invalid_event', __( 'The Bachs webhook payload is invalid.', 'bachs-woocommerce' ), array( 'status' => 400 ) );
		}

		$event_id = sanitize_text_field( $event['id'] );
		if ( '' === $event_id ) {
			return new WP_Error( 'bachs_missing_event_id', __( 'The Bachs webhook event ID is missing.', 'bachs-woocommerce' ), array( 'status' => 400 ) );
		}

		$lock = self::acquire_event_lock( $event_id );
		if ( ! $lock ) {
			// A 5xx asks Bachs to retry. A simultaneous request must not acknowledge
			// an event that the current request may not have finished processing.
			return new WP_Error( 'bachs_event_in_progress', __( 'This Bachs event is already being processed.', 'bachs-woocommerce' ), array( 'status' => 503 ) );
		}

		try {
			Bachs_Order_Service::handle_event( $event );
			return new WP_REST_Response( array( 'received' => true ), 200 );
		} catch ( Throwable $exception ) {
			// Do not log payloads or exception text: either may contain personal data.
			self::log_processing_failure( $exception );
			return new WP_Error( 'bachs_event_processing_failed', __( 'The Bachs event could not be processed. It can be retried.', 'bachs-woocommerce' ), array( 'status' => 500 ) );
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * Use WordPress's atomic option insert as a short-lived duplicate-event lock.
	 *
	 * @param string $event_id Provider event ID.
	 * @return string|false Lock option name or false when another request owns it.
	 */
	private static function acquire_event_lock( $event_id ) {
		$name = 'bachs_wc_lock_' . hash( 'sha256', $event_id );
		$now  = time();

		if ( add_option( $name, (string) $now, '', false ) ) {
			return $name;
		}

		$created_at = (int) get_option( $name, 0 );
		if ( $created_at > 0 && $now - $created_at > 120 ) {
			delete_option( $name );
			if ( add_option( $name, (string) $now, '', false ) ) {
				return $name;
			}
		}

		return false;
	}

	/**
	 * Write a minimal diagnostic that contains no event body or customer data.
	 *
	 * @param Throwable $exception Processing exception.
	 * @return void
	 */
	private static function log_processing_failure( $exception ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				'Webhook processing failed (' . get_class( $exception ) . ').',
				array( 'source' => 'bachs-woocommerce' )
			);
		}
	}
}
