<?php
/**
 * WooCommerce order mapping and payment state transitions for Bachs.
 *
 * All order reads and writes use WooCommerce CRUD methods so the code works
 * with both legacy order storage and High-Performance Order Storage (HPOS).
 *
 * @package Bachs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bachs_Order_Service {
	const META_CHECKOUT_ID       = '_bachs_checkout_id';
	const META_CHECKOUT_URL      = '_bachs_checkout_url';
	const META_CHECKOUT_EXPIRES  = '_bachs_checkout_expires_at';
	const META_CHECKOUT_REFERENCE = '_bachs_checkout_reference';
	const META_CHECKOUT_IDEMPOTENCY = '_bachs_checkout_idempotency_key';
	const META_CHECKOUT_FINGERPRINT = '_bachs_checkout_fingerprint';
	const META_CHECKOUT_ENVIRONMENT = '_bachs_checkout_environment';
	const META_CHARGE_ID         = '_bachs_charge_id';
	const META_REFUND_ID         = '_bachs_refund_id';
	const META_REFUND_REFERENCE  = '_bachs_refund_reference';
	const META_REFUND_IDEMPOTENCY = '_bachs_refund_idempotency_key';
	const META_REFUND_FINGERPRINT = '_bachs_refund_fingerprint';
	const META_REFUND_STATUS     = '_bachs_refund_status';
	const META_REFUND_AMOUNT     = '_bachs_refund_amount';
	const META_REFUND_REASON     = '_bachs_refund_reason';
	const META_REFUND_ADDRESS    = '_bachs_refund_address';
	const META_REFUND_LOCAL_ID   = '_bachs_refund_local_id';
	const META_EVENT_IDS         = '_bachs_processed_event_ids';

	/**
	 * Create or reuse an open checkout session for an order.
	 *
	 * The request fingerprint lets a retry reuse its original idempotency key
	 * after a timeout, while a changed order starts a distinct checkout attempt.
	 *
	 * @param WC_Order        $order WooCommerce order.
	 * @param Bachs_Api_Client $api Bachs API client.
	 * @param WC_Payment_Gateway $gateway Gateway instance for return URLs.
	 * @return array
	 * @throws Bachs_Api_Exception When session creation fails or is malformed.
	 */
	public static function create_checkout_session( $order, $api, $gateway ) {
		if ( ! $order instanceof WC_Order ) {
			throw new Bachs_Api_Exception( __( 'The WooCommerce order could not be loaded.', 'bachs-woocommerce' ) );
		}

		$lock = self::acquire_order_operation_lock( 'checkout', $order->get_id() );
		if ( false === $lock ) {
			throw new Bachs_Api_Exception( __( 'Checkout is already being prepared for this order. Please try again shortly.', 'bachs-woocommerce' ), 0, 'checkout_in_progress' );
		}

		try {
			$order = wc_get_order( $order->get_id() );
			if ( ! $order instanceof WC_Order ) {
				throw new Bachs_Api_Exception( __( 'The WooCommerce order could not be reloaded.', 'bachs-woocommerce' ) );
			}
			return self::create_checkout_session_locked( $order, $api, $gateway );
		} finally {
			self::release_order_operation_lock( $lock );
		}
	}

	/**
	 * Create or reuse a session while holding the order's checkout lock.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param Bachs_Api_Client $api Bachs API client.
	 * @param WC_Payment_Gateway $gateway Gateway instance for return URLs.
	 * @return array
	 * @throws Bachs_Api_Exception When Bachs rejects the request or its response is invalid.
	 */
	private static function create_checkout_session_locked( $order, $api, $gateway ) {
		$amount   = self::format_amount( $order->get_total() );
		$currency = strtoupper( $order->get_currency() );
		if ( ! self::is_positive_amount( $amount ) || ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			throw new Bachs_Api_Exception( __( 'The WooCommerce order total or currency is invalid.', 'bachs-woocommerce' ) );
		}

		$payload = array(
			'pricing'     => array(
				'currency' => $currency,
				'amount'   => $amount,
			),
			'success_url' => $gateway->get_return_url( $order ),
			'cancel_url'  => $order->get_checkout_payment_url(),
			'metadata'    => array(
				'integration' => 'woocommerce',
				'order_id'    => (string) $order->get_id(),
			),
		);

		$customer = self::customer_payload( $order );
		if ( ! empty( $customer ) ) {
			$payload['customer'] = $customer;
		}

		$environment = Bachs_Settings::environment();
		$fingerprint = hash( 'sha256', wp_json_encode( array( $environment, $payload ) ) );
		$checkout_url = (string) $order->get_meta( self::META_CHECKOUT_URL, true );
		$expires_at = (string) $order->get_meta( self::META_CHECKOUT_EXPIRES, true );
		$stored_fingerprint = (string) $order->get_meta( self::META_CHECKOUT_FINGERPRINT, true );
		$stored_environment = (string) $order->get_meta( self::META_CHECKOUT_ENVIRONMENT, true );
		$stored_checkout_id = (string) $order->get_meta( self::META_CHECKOUT_ID, true );
		$known_session_expired = '' !== $stored_checkout_id && ! self::is_future_date( $expires_at );

		if ( $fingerprint === $stored_fingerprint && $environment === $stored_environment && '' !== $checkout_url && self::is_future_date( $expires_at ) ) {
			self::assert_checkout_url( $checkout_url, $environment );
			return array(
				'checkout_id'  => (string) $order->get_meta( self::META_CHECKOUT_ID, true ),
				'checkout_url' => $checkout_url,
				'expires_at'   => $expires_at,
				'reference'    => (string) $order->get_meta( self::META_CHECKOUT_REFERENCE, true ),
			);
		}

		$reference   = (string) $order->get_meta( self::META_CHECKOUT_REFERENCE, true );
		$idempotency = (string) $order->get_meta( self::META_CHECKOUT_IDEMPOTENCY, true );
		if ( $fingerprint !== $stored_fingerprint || $environment !== $stored_environment || '' === $reference || '' === $idempotency || $known_session_expired ) {
			$attempt      = str_replace( '-', '', wp_generate_uuid4() );
			$reference    = 'wc-' . $order->get_id() . '-' . substr( $attempt, 0, 18 );
			$idempotency  = 'wc-checkout-' . $order->get_id() . '-' . $attempt;

			$order->update_meta_data( self::META_CHECKOUT_REFERENCE, $reference );
			$order->update_meta_data( self::META_CHECKOUT_IDEMPOTENCY, $idempotency );
			$order->update_meta_data( self::META_CHECKOUT_FINGERPRINT, $fingerprint );
			$order->update_meta_data( self::META_CHECKOUT_ENVIRONMENT, $environment );
			$order->delete_meta_data( self::META_CHECKOUT_ID );
			$order->delete_meta_data( self::META_CHECKOUT_URL );
			$order->delete_meta_data( self::META_CHECKOUT_EXPIRES );
			$order->save();
		}

		$payload['reference'] = $reference;
		$session = $api->create_checkout_session( $payload, $idempotency );
		if ( empty( $session['checkout_id'] ) || empty( $session['checkout_url'] ) || empty( $session['expires_at'] ) ) {
			throw new Bachs_Api_Exception( __( 'Bachs returned an incomplete checkout session.', 'bachs-woocommerce' ), 0, 'invalid_checkout_session' );
		}

		$session['checkout_id']  = sanitize_text_field( $session['checkout_id'] );
		$session['checkout_url'] = esc_url_raw( $session['checkout_url'] );
		$session['expires_at']   = sanitize_text_field( $session['expires_at'] );
		self::assert_checkout_url( $session['checkout_url'], $environment );

		$order->update_meta_data( self::META_CHECKOUT_ID, $session['checkout_id'] );
		$order->update_meta_data( self::META_CHECKOUT_URL, $session['checkout_url'] );
		$order->update_meta_data( self::META_CHECKOUT_EXPIRES, $session['expires_at'] );
		$order->update_meta_data( self::META_CHECKOUT_REFERENCE, $reference );
		$order->save();

		return $session;
	}

	/**
	 * Apply a webhook event once, using stable Bachs and WooCommerce identifiers.
	 *
	 * @param array $event Decoded, signature-verified webhook event.
	 * @return int|false Order ID when matched, false when no order matched.
	 */
	public static function handle_event( $event ) {
		$type = isset( $event['type'] ) ? strtolower( preg_replace( '/[^a-z0-9._-]/', '', (string) $event['type'] ) ) : '';
		$data = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();
		$id   = isset( $event['id'] ) ? sanitize_text_field( $event['id'] ) : '';
		$is_refund_event = in_array( $type, array( 'refund.paid', 'refund.failed' ), true );

		$order = self::find_order( $data, $is_refund_event );
		if ( ! $order ) {
			return false;
		}

		if ( '' !== $id && self::has_processed_event( $order, $id ) ) {
			return (int) $order->get_id();
		}

		if ( ! self::event_matches_order( $order, $data, $is_refund_event ) ) {
			$order->add_order_note( __( 'A signed Bachs event did not match this order\'s stored checkout identifiers. No payment state was changed.', 'bachs-woocommerce' ) );
			if ( '' !== $id ) {
				self::remember_event( $order, $id );
			}
			return (int) $order->get_id();
		}

		$handled = true;
		switch ( $type ) {
			case 'collection.succeeded':
				self::apply_success( $order, $data );
				break;

			case 'collection.failed':
				self::apply_failure( $order, __( 'Bachs reported that the payment failed.', 'bachs-woocommerce' ), 'failed' );
				break;

			case 'collection.underpaid':
				self::apply_failure( $order, __( 'Bachs reported an underpaid collection. Review the payment before fulfilling this order.', 'bachs-woocommerce' ), 'on-hold' );
				break;

			case 'checkout.expired':
				self::apply_failure( $order, __( 'The Bachs checkout session expired before payment was confirmed.', 'bachs-woocommerce' ), 'cancelled' );
				break;

			case 'refund.paid':
				self::apply_refund_status( $order, $data, 'success' );
				break;

			case 'refund.failed':
				self::apply_refund_status( $order, $data, 'failed' );
				break;

			default:
				$handled = false;
				break;
		}

		if ( $handled && '' !== $id ) {
			self::remember_event( $order, $id );
		}

		return (int) $order->get_id();
	}

	/**
	 * Reconcile an order using authenticated Bachs API responses.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param Bachs_Api_Client $api Bachs API client.
	 * @return bool Whether the order was matched to a confirmed/failed state.
	 * @throws Bachs_Api_Exception When the API request fails.
	 */
	public static function reconcile( $order, $api ) {
		$checkout_id = (string) $order->get_meta( self::META_CHECKOUT_ID, true );
		$charge_id   = (string) $order->get_meta( self::META_CHARGE_ID, true );

		if ( '' === $charge_id && '' !== $checkout_id ) {
			$session = $api->retrieve_checkout_session( $checkout_id );
			if ( isset( $session['charge'] ) && is_array( $session['charge'] ) ) {
				$charge_id = isset( $session['charge']['payment_id'] ) ? $session['charge']['payment_id'] : ( isset( $session['charge']['charge_id'] ) ? $session['charge']['charge_id'] : '' );
			}
			if ( '' === $charge_id ) {
				if ( isset( $session['status'] ) && 'expired' === strtolower( $session['status'] ) ) {
					self::apply_failure( $order, __( 'Bachs confirms that the checkout session expired.', 'bachs-woocommerce' ), 'cancelled' );
					return true;
				}
				$order->add_order_note( __( 'Bachs reconciliation found no payment attempt for this checkout session.', 'bachs-woocommerce' ) );
				return false;
			}
		}

		if ( '' === $charge_id ) {
			$order->add_order_note( __( 'Bachs reconciliation is not available because this order has no checkout session or charge ID.', 'bachs-woocommerce' ) );
			return false;
		}

		$payment = $api->retrieve_payment( $charge_id );
		self::apply_payment_record( $order, $payment );

		$refund_status = (string) $order->get_meta( self::META_REFUND_STATUS, true );
		$payment_status = isset( $payment['status'] ) && is_scalar( $payment['status'] ) ? strtolower( sanitize_text_field( $payment['status'] ) ) : '';
		$provider_refund_exists = in_array( $payment_status, array( 'refunded', 'partially_refunded', 'auto_refunded' ), true );
		$refund_needs_reconciliation = in_array( $refund_status, array( 'requesting', 'processing', 'recording', 'success' ), true ) || $provider_refund_exists;
		if ( $refund_needs_reconciliation && '' === (string) $order->get_meta( self::META_REFUND_LOCAL_ID, true ) ) {
			try {
				$refund = $api->retrieve_refund_by_charge( $charge_id );
				$provider_status = isset( $refund['status'] ) && is_scalar( $refund['status'] ) ? strtolower( sanitize_text_field( $refund['status'] ) ) : '';
				if ( in_array( $provider_status, array( 'success', 'failed', 'processing' ), true ) ) {
					self::apply_refund_record( $order, $refund );
				}
			} catch ( Bachs_Api_Exception $exception ) {
				if ( 'not_found' !== $exception->get_error_code() ) {
					throw $exception;
				}
			}
		}

		return true;
	}

	/**
	 * Apply the provider's payment state after a signed event or API retrieval.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array $payment Bachs payment object.
	 * @return void
	 */
	public static function apply_payment_record( $order, $payment ) {
		$status = isset( $payment['status'] ) && is_scalar( $payment['status'] ) ? strtolower( sanitize_text_field( $payment['status'] ) ) : '';
		$data   = $payment;
		$data['charge_id'] = isset( $payment['payment_id'] ) ? $payment['payment_id'] : ( isset( $payment['charge_id'] ) ? $payment['charge_id'] : '' );

		if ( 'succeeded' === $status ) {
			self::apply_success( $order, $data );
		} elseif ( 'accepted' === $status || 'overpaid' === $status ) {
			self::apply_failure( $order, __( 'Bachs accepted a payment amount that differs from the WooCommerce order. Review the payment before fulfilling the order.', 'bachs-woocommerce' ), 'on-hold' );
		} elseif ( in_array( $status, array( 'refunded', 'partially_refunded', 'auto_refunded' ), true ) ) {
			// Refund state is reconciled separately from the charge's payment state.
			return;
		} elseif ( 'underpaid' === $status ) {
			self::apply_failure( $order, __( 'Bachs confirms that this payment is underpaid. Review it before fulfilling the order.', 'bachs-woocommerce' ), 'on-hold' );
		} elseif ( in_array( $status, array( 'failed', 'expired', 'cancelled' ), true ) ) {
			$target = 'failed' === $status ? 'failed' : 'cancelled';
			self::apply_failure( $order, sprintf( __( 'Bachs confirms that the payment is %s.', 'bachs-woocommerce' ), $status ), $target );
		} else {
			$order->add_order_note( __( 'Bachs reconciliation found that the payment is still pending. The order was not fulfilled.', 'bachs-woocommerce' ) );
		}
	}

	/**
	 * Apply a refund object returned by an authenticated Bachs API request.
	 *
	 * Webhooks and API reconciliation share the same transition logic so a
	 * missed event can be repaired without creating a second local refund.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array $refund Bachs refund object.
	 * @return string Applied provider status.
	 * @throws RuntimeException When the provider response has an unsupported state.
	 */
	public static function apply_refund_record( $order, $refund ) {
		$status = isset( $refund['status'] ) && is_scalar( $refund['status'] )
			? strtolower( sanitize_text_field( (string) $refund['status'] ) )
			: '';
		if ( ! in_array( $status, array( 'processing', 'success', 'failed' ), true ) ) {
			throw new RuntimeException( 'Bachs returned an unsupported refund status.' );
		}

		self::apply_refund_status( $order, $refund, $status );
		return $status;
	}

	/**
	 * Find an order using the checkout, reference, or charge IDs from Bachs.
	 *
	 * @param array $data Event data.
	 * @param bool $is_refund_event Whether the reference belongs to a refund.
	 * @return WC_Order|false
	 */
	private static function find_order( $data, $is_refund_event = false ) {
		$charge_id = isset( $data['charge_id'] ) ? $data['charge_id'] : ( isset( $data['payment_id'] ) ? $data['payment_id'] : '' );
		if ( $is_refund_event ) {
			// A refund's `reference` belongs to the refund, not the checkout session.
			$lookups = array(
				self::META_CHECKOUT_ID       => isset( $data['checkout_id'] ) ? $data['checkout_id'] : '',
				self::META_CHARGE_ID         => $charge_id,
				self::META_REFUND_REFERENCE  => isset( $data['reference'] ) ? $data['reference'] : '',
				self::META_REFUND_ID         => isset( $data['refund_id'] ) ? $data['refund_id'] : '',
			);
		} else {
			$lookups = array(
				self::META_CHECKOUT_ID        => isset( $data['checkout_id'] ) ? $data['checkout_id'] : '',
				self::META_CHECKOUT_REFERENCE => isset( $data['reference'] ) ? $data['reference'] : '',
				self::META_CHARGE_ID          => $charge_id,
			);
		}

		foreach ( $lookups as $meta_key => $value ) {
			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}

			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'return'     => 'objects',
					'meta_query' => array(
						array(
							'key'   => $meta_key,
							'value' => sanitize_text_field( (string) $value ),
						),
					),
				)
			);

			if ( ! empty( $orders ) && $orders[0] instanceof WC_Order ) {
				return $orders[0];
			}
		}

		return false;
	}

	/**
	 * Check every correlation identifier present in an event, not just the one
	 * that happened to find the order. This prevents mismatched references from
	 * changing an otherwise unrelated WooCommerce order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array $data Provider event data.
	 * @param bool $is_refund_event Whether the event describes a refund.
	 * @return bool
	 */
	private static function event_matches_order( $order, $data, $is_refund_event = false ) {
		$charge_id = isset( $data['charge_id'] ) ? $data['charge_id'] : ( isset( $data['payment_id'] ) ? $data['payment_id'] : '' );
		if ( $is_refund_event ) {
			$identifiers = array(
				self::META_CHECKOUT_ID => isset( $data['checkout_id'] ) ? $data['checkout_id'] : '',
				self::META_CHARGE_ID   => $charge_id,
			);
			if ( ! is_scalar( $charge_id ) || '' === (string) $charge_id ) {
				$identifiers[ self::META_REFUND_REFERENCE ] = isset( $data['reference'] ) ? $data['reference'] : '';
				$identifiers[ self::META_REFUND_ID ] = isset( $data['refund_id'] ) ? $data['refund_id'] : '';
			}
		} else {
			$identifiers = array(
				self::META_CHECKOUT_ID         => isset( $data['checkout_id'] ) ? $data['checkout_id'] : '',
				self::META_CHECKOUT_REFERENCE => isset( $data['reference'] ) ? $data['reference'] : '',
				self::META_CHARGE_ID           => $charge_id,
			);
		}

		foreach ( $identifiers as $meta_key => $incoming ) {
			$stored = (string) $order->get_meta( $meta_key, true );
			if ( '' !== $stored && is_scalar( $incoming ) && '' !== (string) $incoming && $stored !== (string) $incoming ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate and complete a confirmed payment without trusting browser redirects.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array $data Payment event data.
	 * @return void
	 */
	private static function apply_success( $order, $data ) {
		$charge_value = isset( $data['charge_id'] ) ? $data['charge_id'] : ( isset( $data['payment_id'] ) ? $data['payment_id'] : '' );
		$charge_id    = is_scalar( $charge_value ) ? sanitize_text_field( (string) $charge_value ) : '';
		$amount       = isset( $data['amount'] ) && is_scalar( $data['amount'] ) ? (string) $data['amount'] : '';
		$currency     = isset( $data['currency'] ) && is_scalar( $data['currency'] ) ? strtoupper( sanitize_text_field( $data['currency'] ) ) : '';
		$status       = isset( $data['status'] ) && is_scalar( $data['status'] ) ? strtolower( sanitize_text_field( $data['status'] ) ) : 'succeeded';

		if ( '' !== $charge_id ) {
			$existing_charge = (string) $order->get_meta( self::META_CHARGE_ID, true );
			if ( '' !== $existing_charge && $existing_charge !== $charge_id ) {
				$order->add_order_note( __( 'A Bachs payment event referenced a different charge ID than the charge already recorded for this order. No payment state was changed.', 'bachs-woocommerce' ) );
				return;
			}
			$order->update_meta_data( self::META_CHARGE_ID, $charge_id );
		}

		$expected_amount   = self::format_amount( $order->get_total() );
		$expected_currency = strtoupper( $order->get_currency() );
		if ( 'succeeded' !== $status || '' === $charge_id || ! self::amounts_match( $expected_amount, $amount ) || $expected_currency !== $currency ) {
			$order->save();
			if ( ! $order->is_paid() ) {
				$order->update_status( 'on-hold', __( 'Bachs reported a payment, but its status, amount, or currency did not match the WooCommerce order. Verify it in Bachs before fulfilling.', 'bachs-woocommerce' ) );
			} else {
				$order->add_order_note( __( 'A Bachs payment event did not match the already-paid WooCommerce order. No order state was changed.', 'bachs-woocommerce' ) );
			}
			return;
		}

		$order->save();
		if ( $order->is_paid() ) {
			return;
		}

		$order->payment_complete( $charge_id );
		$method = isset( $data['payment_method'] ) && is_scalar( $data['payment_method'] ) ? sanitize_text_field( $data['payment_method'] ) : '';
		$message = __( 'Payment confirmed by a signed Bachs webhook.', 'bachs-woocommerce' );
		if ( '' !== $method ) {
			$message .= ' ' . sprintf( __( 'Payment method: %s.', 'bachs-woocommerce' ), $method );
		}
		$order->add_order_note( $message );
	}

	/**
	 * Apply a non-success state without letting a late event undo payment.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string $message Order note.
	 * @param string $status WooCommerce target state.
	 * @return void
	 */
	private static function apply_failure( $order, $message, $status ) {
		if ( $order->is_paid() ) {
			return;
		}

		if ( $order->get_status() !== $status ) {
			$order->update_status( $status, $message );
		}
	}

	/**
	 * Record an asynchronous refund outcome from Bachs.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array $data Refund event payload.
	 * @param string $status Provider refund status.
	 * @return void
	 */
	private static function apply_refund_status( $order, $data, $status ) {
		$refund_id = isset( $data['refund_id'] ) && is_scalar( $data['refund_id'] ) ? sanitize_text_field( $data['refund_id'] ) : '';
		$charge_id = isset( $data['charge_id'] ) && is_scalar( $data['charge_id'] ) ? sanitize_text_field( $data['charge_id'] ) : '';
		$stored_charge_id = (string) $order->get_meta( self::META_CHARGE_ID, true );
		if ( '' !== $charge_id && '' !== $stored_charge_id && $charge_id !== $stored_charge_id ) {
			$order->add_order_note( __( 'A Bachs refund event referenced a different charge ID than the one stored for this order. No refund state was changed.', 'bachs-woocommerce' ) );
			return;
		}

		$current_status = (string) $order->get_meta( self::META_REFUND_STATUS, true );
		if ( 'success' === $current_status && 'failed' === $status ) {
			return;
		}
		if ( 'success' === $current_status && 'success' === $status && '' !== (string) $order->get_meta( self::META_REFUND_LOCAL_ID, true ) ) {
			return;
		}
		if ( 'failed' === $current_status && 'failed' === $status ) {
			return;
		}

		if ( 'processing' === $status ) {
			$order->update_meta_data( self::META_REFUND_STATUS, 'processing' );
			if ( '' !== $refund_id ) {
				$order->update_meta_data( self::META_REFUND_ID, $refund_id );
			}
			$reference = isset( $data['reference'] ) && is_scalar( $data['reference'] ) ? sanitize_text_field( (string) $data['reference'] ) : '';
			if ( '' !== $reference ) {
				$order->update_meta_data( self::META_REFUND_REFERENCE, $reference );
			}
			$order->save();
			$order->add_order_note( __( 'Bachs confirms that the refund is still processing. WooCommerce will record it after the refund succeeds.', 'bachs-woocommerce' ) );
			return;
		}

		if ( 'success' === $status && '' === (string) $order->get_meta( self::META_REFUND_LOCAL_ID, true ) ) {
			$order = self::record_successful_refund( $order, $data, $refund_id );
		}

		$order->update_meta_data( self::META_REFUND_STATUS, $status );
		if ( 'success' === $status ) {
			// The wallet address is needed only until the refund is confirmed.
			$order->delete_meta_data( self::META_REFUND_ADDRESS );
		}
		if ( '' !== $refund_id ) {
			$order->update_meta_data( self::META_REFUND_ID, $refund_id );
		}
		$order->save();

		if ( 'success' === $status ) {
			$order->add_order_note( __( 'Bachs confirmed that the refund reached the customer.', 'bachs-woocommerce' ) );
		} else {
			$order->add_order_note( __( 'Bachs reported that the refund failed. No money was returned; review the order and retry if appropriate.', 'bachs-woocommerce' ) );
		}
	}

	/**
	 * Create exactly one WooCommerce refund after Bachs confirms settlement.
	 *
	 * A short option lock covers the period between checking for a local refund
	 * and storing its ID. Provider IDs are attached before WooCommerce's first
	 * save so a webhook retry can recover after an interrupted request.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array $data Provider refund record.
	 * @param string $refund_id Bachs refund ID, when supplied.
	 * @return WC_Order Fresh order object with refund metadata saved.
	 * @throws RuntimeException When WooCommerce cannot safely record the refund.
	 */
	private static function record_successful_refund( $order, $data, $refund_id ) {
		$lock = self::acquire_order_operation_lock( 'refund', $order->get_id() );
		if ( false === $lock ) {
			throw new RuntimeException( 'Another request is recording the Bachs refund.' );
		}

		try {
			$order = wc_get_order( $order->get_id() );
			if ( ! $order instanceof WC_Order ) {
				throw new RuntimeException( 'The WooCommerce order could not be reloaded.' );
			}
			if ( '' !== (string) $order->get_meta( self::META_REFUND_LOCAL_ID, true ) ) {
				return $order;
			}

			$amount = isset( $data['refunded_amount'] ) && is_scalar( $data['refunded_amount'] ) && null !== $data['refunded_amount']
				? $data['refunded_amount']
				: ( isset( $data['requested_amount'] ) && is_scalar( $data['requested_amount'] ) ? $data['requested_amount'] : $order->get_meta( self::META_REFUND_AMOUNT, true ) );
			$amount = self::normalize_amount( $amount, wc_get_price_decimals() );
			if ( false === $amount || ! self::is_positive_amount( $amount ) ) {
				throw new RuntimeException( 'Bachs confirmed a refund without a valid amount.' );
			}

			$order->update_meta_data( self::META_REFUND_STATUS, 'recording' );
			$order->save();
			$refund_reference = isset( $data['reference'] ) && is_scalar( $data['reference'] )
				? sanitize_text_field( (string) $data['reference'] )
				: (string) $order->get_meta( self::META_REFUND_REFERENCE, true );
			$local_refund = self::find_local_refund( $order, $refund_id, $refund_reference );
			if ( ! $local_refund ) {
				$attach_provider_ids = static function ( $refund ) use ( $refund_id, $refund_reference ) {
					if ( ! $refund instanceof WC_Order_Refund ) {
						return;
					}
					if ( '' !== $refund_id ) {
						$refund->update_meta_data( '_bachs_refund_id', $refund_id );
					}
					if ( '' !== $refund_reference ) {
						$refund->update_meta_data( '_bachs_refund_reference', $refund_reference );
					}
				};

				add_action( 'woocommerce_create_refund', $attach_provider_ids, 10, 1 );
				try {
					$local_refund = wc_create_refund(
						array(
							'amount'         => $amount,
							'reason'         => (string) $order->get_meta( self::META_REFUND_REASON, true ),
							'order_id'       => $order->get_id(),
							'refund_payment' => false,
							'restock_items'  => false,
						)
					);
				} finally {
					remove_action( 'woocommerce_create_refund', $attach_provider_ids, 10 );
				}
			}

			if ( is_wp_error( $local_refund ) || ! $local_refund instanceof WC_Order_Refund ) {
				throw new RuntimeException( 'WooCommerce could not record the confirmed Bachs refund.' );
			}

			if ( '' !== $refund_id ) {
				$local_refund->update_meta_data( '_bachs_refund_id', $refund_id );
			}
			if ( '' !== $refund_reference ) {
				$local_refund->update_meta_data( '_bachs_refund_reference', $refund_reference );
				$order->update_meta_data( self::META_REFUND_REFERENCE, $refund_reference );
			}
			$local_refund->save();
			$order->update_meta_data( self::META_REFUND_LOCAL_ID, $local_refund->get_id() );
			$order->save();
			return $order;
		} finally {
			self::release_order_operation_lock( $lock );
		}
	}

	/**
	 * Acquire a short-lived lock for a per-order provider operation.
	 *
	 * @param string $operation Operation name, such as checkout or refund.
	 * @param int $order_id WooCommerce order ID.
	 * @return array|false Lock identifier and value, or false when already held.
	 */
	private static function acquire_order_operation_lock( $operation, $order_id ) {
		$name = 'bachs_wc_' . sanitize_key( $operation ) . '_lock_' . absint( $order_id );
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$value = time() . ':' . wp_generate_uuid4();
			if ( add_option( $name, $value, '', false ) ) {
				return array( 'name' => $name, 'value' => $value );
			}

			$existing = (string) get_option( $name, '' );
			$parts = explode( ':', $existing, 2 );
			if ( isset( $parts[0] ) && ctype_digit( $parts[0] ) && time() - (int) $parts[0] > 120 ) {
				delete_option( $name );
				continue;
			}
			return false;
		}

		return false;
	}

	/**
	 * Release an order-operation lock only when this request still owns it.
	 *
	 * @param array $lock Lock identifier and value.
	 * @return void
	 */
	private static function release_order_operation_lock( $lock ) {
		if ( isset( $lock['name'], $lock['value'] ) && $lock['value'] === get_option( $lock['name'] ) ) {
			delete_option( $lock['name'] );
		}
	}

	/**
	 * Recover a local refund created before an interrupted webhook request.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string $provider_refund_id Bachs refund ID.
	 * @param string $reference Bachs refund reference.
	 * @return WC_Order_Refund|false
	 */
	private static function find_local_refund( $order, $provider_refund_id, $reference ) {
		foreach ( $order->get_refunds() as $refund ) {
			if ( '' !== $provider_refund_id && $provider_refund_id === (string) $refund->get_meta( '_bachs_refund_id', true ) ) {
				return $refund;
			}
			if ( '' !== $reference && $reference === (string) $refund->get_meta( '_bachs_refund_reference', true ) ) {
				return $refund;
			}
		}

		return false;
	}

	/**
	 * Keep a bounded list of processed IDs on the order for webhook deduplication.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string $event_id Provider event ID.
	 * @return void
	 */
	private static function remember_event( $order, $event_id ) {
		$ids = $order->get_meta( self::META_EVENT_IDS, true );
		$ids = is_array( $ids ) ? $ids : array();
		$ids[] = $event_id;
		$ids = array_values( array_unique( array_slice( $ids, -100 ) ) );
		$order->update_meta_data( self::META_EVENT_IDS, $ids );
		$order->save();
	}

	/**
	 * Check whether a provider event has already changed this order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string $event_id Provider event ID.
	 * @return bool
	 */
	private static function has_processed_event( $order, $event_id ) {
		$ids = $order->get_meta( self::META_EVENT_IDS, true );
		return is_array( $ids ) && in_array( $event_id, $ids, true );
	}

	/**
	 * Construct the limited customer details Bachs can use on hosted checkout.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private static function customer_payload( $order ) {
		$customer = array();
		$email    = sanitize_email( $order->get_billing_email() );
		$name     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$phone    = trim( (string) $order->get_billing_phone() );

		if ( '' !== $email ) {
			$customer['email'] = $email;
		}
		if ( '' !== $name ) {
			$customer['name'] = sanitize_text_field( $name );
		}
		if ( '' !== $phone ) {
			$customer['phone_number'] = sanitize_text_field( $phone );
		}

		return $customer;
	}

	/**
	 * Format WooCommerce money as the decimal string Bachs expects.
	 *
	 * @param string|float $amount WooCommerce amount.
	 * @return string
	 */
	private static function format_amount( $amount ) {
		return wc_format_decimal( $amount, wc_get_price_decimals() );
	}

	/**
	 * Compare decimal strings at WooCommerce's configured precision.
	 *
	 * @param string $expected Expected order amount.
	 * @param string $actual Provider amount.
	 * @return bool
	 */
	private static function amounts_match( $expected, $actual ) {
		$precision = wc_get_price_decimals();
		$expected  = self::normalize_amount( $expected, $precision );
		$actual    = self::normalize_amount( $actual, $precision );
		return false !== $expected && false !== $actual && $expected === $actual;
	}

	/**
	 * Normalize a decimal without floating-point conversion.
	 *
	 * @param string $amount Decimal input.
	 * @param int $precision Decimal places.
	 * @return string|false
	 */
	private static function normalize_amount( $amount, $precision ) {
		$amount = trim( (string) $amount );
		if ( ! preg_match( '/^\d+(?:\.\d+)?$/', $amount ) ) {
			return false;
		}

		$parts = explode( '.', $amount, 2 );
		$whole = ltrim( $parts[0], '0' );
		$whole = '' === $whole ? '0' : $whole;
		$decimals = isset( $parts[1] ) ? $parts[1] : '';
		if ( strlen( $decimals ) > $precision ) {
			$extra_decimals = substr( $decimals, $precision );
			if ( '' !== trim( $extra_decimals, '0' ) ) {
				return false;
			}
			$decimals = substr( $decimals, 0, $precision );
		}

		return 0 === $precision ? $whole : $whole . '.' . str_pad( $decimals, $precision, '0' );
	}

	/**
	 * Validate a positive decimal string without converting it to a float.
	 *
	 * @param string $amount Decimal amount.
	 * @return bool
	 */
	private static function is_positive_amount( $amount ) {
		return preg_match( '/^\d+(?:\.\d+)?$/', (string) $amount ) && '' !== ltrim( str_replace( '.', '', (string) $amount ), '0' );
	}

	/**
	 * Confirm a saved expiration timestamp is still valid.
	 *
	 * @param string $date ISO 8601 timestamp.
	 * @return bool
	 */
	private static function is_future_date( $date ) {
		$timestamp = strtotime( $date );
		return $timestamp && $timestamp > time();
	}

	/**
	 * Restrict redirects to Bachs's documented checkout origins.
	 *
	 * @param string $url Provider checkout URL.
	 * @param string $environment Current environment.
	 * @return void
	 * @throws Bachs_Api_Exception When the checkout URL is not trusted.
	 */
	private static function assert_checkout_url( $url, $environment ) {
		$parsed = wp_parse_url( $url );
		$host   = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
		$hosts  = 'live' === $environment ? array( 'checkout.bachs.io' ) : array( 'sandbox-checkout.bachs.io' );

		if ( ! is_array( $parsed ) || ! isset( $parsed['scheme'] ) || 'https' !== strtolower( $parsed['scheme'] ) || ! in_array( $host, $hosts, true ) ) {
			throw new Bachs_Api_Exception( __( 'Bachs returned a checkout URL outside the expected secure checkout host.', 'bachs-woocommerce' ), 0, 'invalid_checkout_host' );
		}
	}
}
