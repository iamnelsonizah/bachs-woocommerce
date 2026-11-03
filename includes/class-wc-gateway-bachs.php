<?php
/**
 * WooCommerce payment gateway for Bachs hosted checkout.
 *
 * @package Bachs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WC_Gateway_Bachs extends WC_Payment_Gateway {
	/**
	 * Set up gateway identity, merchant settings, and supported WooCommerce flows.
	 */
	public function __construct() {
		$this->id                 = 'bachs';
		$this->method_title       = __( 'Bachs', 'bachs-woocommerce' );
		$this->method_description = __( 'Accept payments through Bachs secure hosted checkout.', 'bachs-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->order_button_text  = __( 'Continue to Bachs', 'bachs-woocommerce' );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled', 'no' );
		$this->title       = $this->get_option( 'title', __( 'Bachs', 'bachs-woocommerce' ) );
		$this->description = $this->get_option( 'description', __( 'Pay securely using Bachs checkout.', 'bachs-woocommerce' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
	}

	/**
	 * Define merchant-facing settings.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'bachs-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Bachs payments', 'bachs-woocommerce' ),
				'default' => 'no',
			),
			'title' => array(
				'title'       => __( 'Title', 'bachs-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Name shown to customers at checkout.', 'bachs-woocommerce' ),
				'default'     => __( 'Bachs', 'bachs-woocommerce' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'bachs-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Short instructions shown under the payment method.', 'bachs-woocommerce' ),
				'default'     => __( 'Pay securely using Bachs checkout.', 'bachs-woocommerce' ),
			),
			'environment' => array(
				'title'       => __( 'Environment', 'bachs-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Use Sandbox until webhook delivery and order updates have been confirmed.', 'bachs-woocommerce' ),
				'default'     => 'sandbox',
				'options'     => array(
					'sandbox' => __( 'Sandbox', 'bachs-woocommerce' ),
					'live'    => __( 'Live', 'bachs-woocommerce' ),
				),
			),
			'checkout_presentation' => array(
				'title'       => __( 'Checkout presentation', 'bachs-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Hosted checkout redirects the customer to Bachs. Overlay checkout opens the same Bachs-hosted page in a modal.', 'bachs-woocommerce' ),
				'default'     => 'redirect',
				'options'     => array(
					'redirect' => __( 'Hosted checkout redirect', 'bachs-woocommerce' ),
					'overlay'  => __( 'Bachs overlay', 'bachs-woocommerce' ),
				),
			),
			'sandbox_secret_key' => array(
				'title'       => __( 'Sandbox API key', 'bachs-woocommerce' ),
				'type'        => 'bachs_secret',
				'description' => __( 'Use a restricted sk_sandbox_ key with payments:write, payments:read, refunds:read, and refunds:write scopes.', 'bachs-woocommerce' ),
			),
			'sandbox_webhook_secret' => array(
				'title'       => __( 'Sandbox webhook signing secret', 'bachs-woocommerce' ),
				'type'        => 'bachs_secret',
				'description' => __( 'Signing secret for the Sandbox webhook endpoint registered in Bachs.', 'bachs-woocommerce' ),
			),
			'sandbox_webhook_secret_previous' => array(
				'title'       => __( 'Previous Sandbox webhook secret', 'bachs-woocommerce' ),
				'type'        => 'bachs_secret',
				'description' => __( 'Optional previous secret during a Bachs webhook secret rotation. Remove it when the overlap period ends.', 'bachs-woocommerce' ),
			),
			'live_secret_key' => array(
				'title'       => __( 'Live API key', 'bachs-woocommerce' ),
				'type'        => 'bachs_secret',
				'description' => __( 'Use a restricted sk_live_ key with payments:write, payments:read, refunds:read, and refunds:write scopes.', 'bachs-woocommerce' ),
			),
			'live_webhook_secret' => array(
				'title'       => __( 'Live webhook signing secret', 'bachs-woocommerce' ),
				'type'        => 'bachs_secret',
				'description' => __( 'Signing secret for the Live webhook endpoint registered in Bachs.', 'bachs-woocommerce' ),
			),
			'live_webhook_secret_previous' => array(
				'title'       => __( 'Previous Live webhook secret', 'bachs-woocommerce' ),
				'type'        => 'bachs_secret',
				'description' => __( 'Optional previous secret during a Bachs webhook secret rotation. Remove it when the overlap period ends.', 'bachs-woocommerce' ),
			),
			'webhook_url' => array(
				'title' => __( 'Webhook endpoint', 'bachs-woocommerce' ),
				'type'  => 'bachs_webhook_url',
			),
			'webhook_events' => array(
				'title' => __( 'Webhook events', 'bachs-woocommerce' ),
				'type'  => 'bachs_webhook_events',
			),
		);
	}

	/**
	 * Render a secret input without ever putting a saved secret back in HTML.
	 *
	 * @param string $key Setting key.
	 * @param array $data Field definition.
	 * @return string
	 */
	public function generate_bachs_secret_html( $key, $data ) {
		$constant = $this->constant_for_secret( $key );
		$label    = isset( $data['title'] ) ? $data['title'] : $key;
		$description = isset( $data['description'] ) ? $data['description'] : '';
		$saved    = defined( $constant ) || '' !== (string) $this->get_option( $key, '' );
		$notice   = defined( $constant )
			? __( 'Configured in wp-config.php; the dashboard value is ignored.', 'bachs-woocommerce' )
			: ( $saved ? __( 'A value is saved. Leave this field blank to keep it, or enter a new value to replace it.', 'bachs-woocommerce' ) : __( 'No value is saved yet.', 'bachs-woocommerce' ) );

		$html  = '<tr valign="top"><th scope="row" class="titledesc"><label for="woocommerce_bachs_settings_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td class="forminp">';
		if ( ! defined( $constant ) ) {
			$html .= '<input class="regular-text" type="password" autocomplete="new-password" value="" placeholder="' . esc_attr( $notice ) . '" name="' . esc_attr( $this->get_field_key( $key ) ) . '" id="woocommerce_bachs_settings_' . esc_attr( $key ) . '" />';
		} else {
			$html .= '<p><code>' . esc_html( $constant ) . '</code></p>';
		}
		$html .= '<p class="description">' . esc_html( $notice );
		if ( '' !== $description ) {
			$html .= ' ' . esc_html( $description );
		}
		$html .= '</p></td></tr>';

		return $html;
	}

	/**
	 * Preserve a stored secret when the merchant leaves its input blank.
	 *
	 * @param string $key Setting key.
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function validate_bachs_secret_field( $key, $value ) {
		if ( defined( $this->constant_for_secret( $key ) ) ) {
			return (string) $this->get_option( $key, '' );
		}

		$value = trim( (string) wp_unslash( $value ) );
		if ( '' === $value ) {
			return (string) $this->get_option( $key, '' );
		}

		if ( strlen( $value ) > 4096 || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			add_settings_error( Bachs_Settings::OPTION_NAME, 'invalid_secret', __( 'The value was not saved because it contains invalid characters or is too long.', 'bachs-woocommerce' ) );
			return (string) $this->get_option( $key, '' );
		}

		if ( 'sandbox_secret_key' === $key && 0 !== strpos( $value, 'sk_sandbox_' ) ) {
			add_settings_error( Bachs_Settings::OPTION_NAME, 'invalid_sandbox_key', __( 'Sandbox API keys must start with sk_sandbox_.', 'bachs-woocommerce' ) );
			return (string) $this->get_option( $key, '' );
		}

		if ( 'live_secret_key' === $key && 0 !== strpos( $value, 'sk_live_' ) ) {
			add_settings_error( Bachs_Settings::OPTION_NAME, 'invalid_live_key', __( 'Live API keys must start with sk_live_.', 'bachs-woocommerce' ) );
			return (string) $this->get_option( $key, '' );
		}

		return $value;
	}

	/**
	 * Render the webhook destination for copying into Bachs.
	 *
	 * @param string $key Setting key.
	 * @param array $data Field definition.
	 * @return string
	 */
	public function generate_bachs_webhook_url_html( $key, $data ) {
		$url = rest_url( Bachs_Webhook_Controller::ROUTE_NAMESPACE . Bachs_Webhook_Controller::ROUTE );
		return '<tr valign="top"><th scope="row" class="titledesc">' . esc_html( $data['title'] ) . '</th><td class="forminp"><code>' . esc_html( $url ) . '</code><p class="description">' . esc_html__( 'Use this endpoint separately for Sandbox and Live. Your store must be publicly reachable over HTTPS for live payments.', 'bachs-woocommerce' ) . '</p></td></tr>';
	}

	/**
	 * Tell merchants which event types the gateway handles.
	 *
	 * @param string $key Setting key.
	 * @param array $data Field definition.
	 * @return string
	 */
	public function generate_bachs_webhook_events_html( $key, $data ) {
		$events = 'collection.succeeded, collection.failed, collection.underpaid, checkout.expired, refund.paid, refund.failed';
		return '<tr valign="top"><th scope="row" class="titledesc">' . esc_html( $data['title'] ) . '</th><td class="forminp"><code>' . esc_html( $events ) . '</code><p class="description">' . esc_html__( 'Subscribe the endpoint to these events in the Bachs Developer Portal.', 'bachs-woocommerce' ) . '</p></td></tr>';
	}

	/**
	 * Check whether checkout is configured and safe to offer.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		$environment = Bachs_Settings::environment();
		$key          = Bachs_Settings::api_key();
		$expected     = 'live' === $environment ? 'sk_live_' : 'sk_sandbox_';
		if ( '' === $key || 0 !== strpos( $key, $expected ) || ! Bachs_Settings::has_current_webhook_secret() ) {
			return false;
		}

		if ( 'live' === $environment && ! is_ssl() ) {
			return false;
		}

		return true;
	}

	/**
	 * Start the session on the server and redirect to hosted checkout or overlay.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'The order could not be loaded. Please try again.', 'bachs-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( $order->is_paid() ) {
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		try {
			$session = Bachs_Order_Service::create_checkout_session( $order, new Bachs_Api_Client(), $this );
			if ( 'overlay' === Bachs_Settings::checkout_presentation() ) {
				return array(
					'result'   => 'success',
					'redirect' => $order->get_checkout_payment_url(),
				);
			}

			return array(
				'result'   => 'success',
				'redirect' => $session['checkout_url'],
			);
		} catch ( Bachs_Api_Exception $exception ) {
			$this->log_api_failure( 'checkout', $exception );
			wc_add_notice( __( 'Bachs checkout could not be started. Please try again or choose another payment method.', 'bachs-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
	}

	/**
	 * Render the overlay page for an order-pay request.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			echo '<p>' . esc_html__( 'The order could not be loaded.', 'bachs-woocommerce' ) . '</p>';
			return;
		}

		$checkout_url = (string) $order->get_meta( Bachs_Order_Service::META_CHECKOUT_URL, true );
		if ( '' === $checkout_url ) {
			echo '<p>' . esc_html__( 'The Bachs checkout session is missing. Return to checkout and try again.', 'bachs-woocommerce' ) . '</p>';
			return;
		}

		wp_register_script( 'bachs-checkout-sdk', 'https://checkout.bachs.io/bachs.js', array(), null, true );
		wp_register_script( 'bachs-wc-overlay', BACHS_WC_URL . 'assets/js/overlay-checkout.js', array( 'bachs-checkout-sdk' ), BACHS_WC_VERSION, true );
		wp_localize_script(
			'bachs-wc-overlay',
			'BachsWooCheckout',
			array(
				'checkoutUrl' => $checkout_url,
				'returnUrl'   => $this->get_return_url( $order ),
				'buttonText'  => __( 'Open secure Bachs checkout', 'bachs-woocommerce' ),
				'messages'    => array(
					'completed' => __( 'Payment submitted. Returning to your order; the store will confirm it from Bachs.', 'bachs-woocommerce' ),
					'failed'    => __( 'The payment did not complete. You can try again.', 'bachs-woocommerce' ),
					'expired'   => __( 'This checkout session expired. Return to checkout and start a new payment.', 'bachs-woocommerce' ),
					'closed'    => __( 'Checkout closed. You can reopen it or use the hosted checkout link.', 'bachs-woocommerce' ),
					'error'     => __( 'The Bachs checkout could not be opened. Use the hosted checkout link or try again.', 'bachs-woocommerce' ),
					'loading'   => __( 'Opening Bachs secure checkout…', 'bachs-woocommerce' ),
					'opened'    => __( 'Complete your payment in the Bachs checkout window.', 'bachs-woocommerce' ),
					'not_loaded'=> __( 'The Bachs overlay could not be loaded. Use the hosted checkout link below.', 'bachs-woocommerce' ),
				),
			)
		);
		wp_enqueue_script( 'bachs-wc-overlay' );

		echo '<div class="bachs-wc-overlay">';
		echo '<p id="bachs-wc-overlay-status" role="status" aria-live="polite">' . esc_html__( 'Continue to open Bachs secure checkout. Your payment is confirmed by the store after Bachs sends a signed notification.', 'bachs-woocommerce' ) . '</p>';
		echo '<button type="button" class="button alt" id="bachs-wc-overlay-open">' . esc_html__( 'Open secure Bachs checkout', 'bachs-woocommerce' ) . '</button> ';
		echo '<a href="' . esc_url( $checkout_url ) . '">' . esc_html__( 'Open the hosted checkout page instead', 'bachs-woocommerce' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Request a provider refund from the dedicated order screen panel.
	 *
	 * This is intentionally separate from WooCommerce's synchronous refund API:
	 * Bachs refunds settle asynchronously, so a local WooCommerce refund record
	 * is created only after Bachs confirms the refund by webhook or API lookup.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @param string|null $amount Requested partial amount, or empty for full refund.
	 * @param string $reason Merchant reason.
	 * @param string $refund_address Optional crypto refund destination.
	 * @return bool|WP_Error
	 */
	public function request_bachs_refund( $order_id, $amount = '', $reason = '', $refund_address = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'bachs_order_missing', __( 'The WooCommerce order could not be loaded.', 'bachs-woocommerce' ) );
		}

		$charge_id = (string) $order->get_meta( Bachs_Order_Service::META_CHARGE_ID, true );
		if ( '' === $charge_id ) {
			return new WP_Error( 'bachs_charge_missing', __( 'The Bachs charge ID is missing for this order.', 'bachs-woocommerce' ) );
		}

		$current_status = (string) $order->get_meta( Bachs_Order_Service::META_REFUND_STATUS, true );
		$refund_address = trim( (string) $refund_address );
		if ( strlen( $refund_address ) > 255 || preg_match( '/[\x00-\x1F\x7F]/', $refund_address ) ) {
			return new WP_Error( 'bachs_refund_address_invalid', __( 'The crypto refund wallet address is invalid or too long.', 'bachs-woocommerce' ) );
		}
		if ( in_array( $current_status, array( 'recording', 'processing', 'success' ), true ) ) {
			return new WP_Error( 'bachs_refund_exists', __( 'Bachs already has a pending or successful refund for this charge.', 'bachs-woocommerce' ) );
		}

		$api = new Bachs_Api_Client();
		try {
			if ( 'requesting' === $current_status ) {
				// A timed-out request may already have created a refund. Check provider
				// state first because Bachs only caches successful idempotent responses
				// for a limited period.
				try {
					$existing_refund = $api->retrieve_refund_by_charge( $charge_id );
					$existing_status = Bachs_Order_Service::apply_refund_record( $order, $existing_refund );
					if ( 'failed' === $existing_status ) {
						return new WP_Error( 'bachs_refund_failed', __( 'Bachs could not complete the refund. No money was returned.', 'bachs-woocommerce' ) );
					}
					return true;
				} catch ( Bachs_Api_Exception $lookup_exception ) {
					if ( 'not_found' !== $lookup_exception->get_error_code() ) {
						throw $lookup_exception;
					}
				}

				$payment = $api->retrieve_payment( $charge_id );
				$requires_refund_address = self::payment_requires_crypto_refund_address( $payment );
				$saved_address = (string) $order->get_meta( Bachs_Order_Service::META_REFUND_ADDRESS, true );
				if ( '' !== $refund_address && $refund_address !== $saved_address ) {
					return new WP_Error( 'bachs_refund_retry_payload_changed', __( 'The wallet address cannot be changed while retrying an uncertain refund. Reconcile the original request with Bachs first.', 'bachs-woocommerce' ) );
				}
				if ( $requires_refund_address && '' === $saved_address ) {
					return new WP_Error( 'bachs_crypto_refund_address_missing', __( 'The original crypto refund wallet address is missing. Reconcile the original request with Bachs before retrying.', 'bachs-woocommerce' ) );
				}
				$payload = array( 'charge_id' => $charge_id );
				if ( $requires_refund_address ) {
					$payload['refund_address'] = $saved_address;
				}
				$saved_reason = (string) $order->get_meta( Bachs_Order_Service::META_REFUND_REASON, true );
				$saved_amount = (string) $order->get_meta( Bachs_Order_Service::META_REFUND_AMOUNT, true );
				$full_amount  = wc_format_decimal( $order->get_total(), wc_get_price_decimals() );
				if ( '' !== $saved_reason ) {
					$payload['reason'] = $saved_reason;
				}
				if ( '' !== $saved_amount && ! self::decimal_values_match( $saved_amount, $full_amount ) ) {
					$payload['amount'] = $saved_amount;
				}
				$fingerprint = hash( 'sha256', wp_json_encode( $payload ) );
				$stored_fingerprint = (string) $order->get_meta( Bachs_Order_Service::META_REFUND_FINGERPRINT, true );
				$reference = (string) $order->get_meta( Bachs_Order_Service::META_REFUND_REFERENCE, true );
				$idempotency = (string) $order->get_meta( Bachs_Order_Service::META_REFUND_IDEMPOTENCY, true );
				if ( '' === $stored_fingerprint || ! hash_equals( $stored_fingerprint, $fingerprint ) || '' === $reference || '' === $idempotency ) {
					return new WP_Error( 'bachs_refund_retry_payload_changed', __( 'The saved refund details no longer match the original request. Reconcile with Bachs before taking any further action.', 'bachs-woocommerce' ) );
				}
				$payload['reference'] = $reference;
				$payload['idempotency_key'] = $idempotency;
				if ( '' === $payload['reference'] || '' === $payload['idempotency_key'] ) {
					return new WP_Error( 'bachs_refund_retry_missing_key', __( 'The original refund request key is missing. Reconcile the order with Bachs before creating a new request.', 'bachs-woocommerce' ) );
				}

				return $this->persist_refund_response( $order, $api->create_refund( $payload ) );
			}

			$payment = $api->retrieve_payment( $charge_id );
			if ( ! isset( $payment['is_refundable'] ) || true !== $payment['is_refundable'] ) {
				return new WP_Error( 'bachs_not_refundable', __( 'Bachs reports that this payment cannot be refunded.', 'bachs-woocommerce' ) );
			}
			if ( $order->get_total_refunded() > 0 ) {
				return new WP_Error( 'bachs_existing_local_refund', __( 'A refund is already recorded on this WooCommerce order.', 'bachs-woocommerce' ) );
			}

			$payload = array(
				'charge_id' => $charge_id,
			);
			$requires_refund_address = self::payment_requires_crypto_refund_address( $payment );
			if ( $requires_refund_address ) {
				if ( '' === $refund_address ) {
					return new WP_Error( 'bachs_crypto_refund_address_required', __( 'Enter the customer’s crypto refund wallet address before requesting this refund.', 'bachs-woocommerce' ) );
				}
				$payload['refund_address'] = $refund_address;
			}
			$clean_reason = sanitize_textarea_field( $reason );
			if ( '' !== $clean_reason ) {
				$payload['reason'] = substr( $clean_reason, 0, 500 );
			}
			$raw_amount = null === $amount ? '' : trim( (string) $amount );
			if ( '' !== $raw_amount && ! preg_match( '/^\d+(?:\.\d+)?$/', $raw_amount ) ) {
				return new WP_Error( 'bachs_refund_amount_invalid', __( 'Enter a valid decimal refund amount.', 'bachs-woocommerce' ) );
			}
			$amount_text = '' === $raw_amount ? '' : self::normalize_decimal( $raw_amount );
			if ( false === $amount_text ) {
				return new WP_Error( 'bachs_refund_amount_precision', __( 'The refund amount has more decimal places than this store currency allows.', 'bachs-woocommerce' ) );
			}
			if ( '' !== $amount_text && ! self::is_positive_decimal( $amount_text ) ) {
				return new WP_Error( 'bachs_refund_amount_invalid', __( 'Enter a valid refund amount greater than zero.', 'bachs-woocommerce' ) );
			}

			$full_amount = wc_format_decimal( $order->get_total(), wc_get_price_decimals() );
			if ( '' !== $amount_text && ! self::decimal_values_match( $amount_text, $full_amount ) ) {
				if ( ! self::decimal_is_less_than_or_equal( $amount_text, $full_amount ) ) {
					return new WP_Error( 'bachs_refund_amount_too_large', __( 'The refund amount cannot exceed the WooCommerce order total.', 'bachs-woocommerce' ) );
				}
				$payment_currency = isset( $payment['currency'] ) && is_scalar( $payment['currency'] ) ? strtoupper( (string) $payment['currency'] ) : '';
				if ( $payment_currency !== strtoupper( $order->get_currency() ) ) {
					return new WP_Error( 'bachs_refund_currency_mismatch', __( 'A partial refund is unavailable because the Bachs settlement currency differs from the WooCommerce order currency.', 'bachs-woocommerce' ) );
				}
				$payload['amount'] = $amount_text;
			}
			$requested_amount = isset( $payload['amount'] ) ? $payload['amount'] : $full_amount;

			$fingerprint = hash( 'sha256', wp_json_encode( $payload ) );
			$stored_fingerprint = (string) $order->get_meta( Bachs_Order_Service::META_REFUND_FINGERPRINT, true );
			$reference = (string) $order->get_meta( Bachs_Order_Service::META_REFUND_REFERENCE, true );
			$idempotency = (string) $order->get_meta( Bachs_Order_Service::META_REFUND_IDEMPOTENCY, true );

			if ( $fingerprint !== $stored_fingerprint || '' === $reference || '' === $idempotency || 'failed' === $current_status ) {
				$attempt     = str_replace( '-', '', wp_generate_uuid4() );
				$reference   = 'wc-refund-' . $order->get_id() . '-' . substr( $attempt, 0, 18 );
				$idempotency = 'wc-refund-' . $order->get_id() . '-' . $attempt;
				$order->update_meta_data( Bachs_Order_Service::META_REFUND_REFERENCE, $reference );
				$order->update_meta_data( Bachs_Order_Service::META_REFUND_IDEMPOTENCY, $idempotency );
				$order->update_meta_data( Bachs_Order_Service::META_REFUND_FINGERPRINT, $fingerprint );
				$order->update_meta_data( Bachs_Order_Service::META_REFUND_AMOUNT, $requested_amount );
				$order->update_meta_data( Bachs_Order_Service::META_REFUND_REASON, isset( $payload['reason'] ) ? $payload['reason'] : '' );
				$order->update_meta_data( Bachs_Order_Service::META_REFUND_STATUS, 'requesting' );
				$order->save();
			}

			$payload['reference']        = $reference;
			$payload['idempotency_key'] = $idempotency;
			if ( $requires_refund_address ) {
				$order->update_meta_data( Bachs_Order_Service::META_REFUND_ADDRESS, $refund_address );
				$order->save();
			}
			return $this->persist_refund_response( $order, $api->create_refund( $payload ) );
		} catch ( Bachs_Api_Exception $exception ) {
			// The saved idempotency key is intentionally retained after a timeout.
			// A retry of the same WooCommerce refund can safely recover the request.
			$this->log_api_failure( 'refund', $exception );
			return new WP_Error( 'bachs_refund_request_failed', __( 'Bachs could not confirm the refund request. Retry from the order after checking its status in Bachs.', 'bachs-woocommerce' ) );
		} catch ( Throwable $exception ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Refund state update failed (' . get_class( $exception ) . ').', array( 'source' => 'bachs-woocommerce' ) );
			}
			return new WP_Error( 'bachs_refund_state_failed', __( 'Bachs returned a refund state that WooCommerce could not record. Reconcile the order with Bachs before retrying.', 'bachs-woocommerce' ) );
		}
	}

	/**
	 * Detect whether a payment needs a destination for a crypto refund.
	 *
	 * Bachs represents some crypto currencies with a network suffix and others
	 * with a ticker. Payment method details are checked as a fallback because a
	 * crypto payment can be denominated in a fiat settlement currency.
	 *
	 * @param array $payment Bachs payment response.
	 * @return bool
	 */
	private static function payment_requires_crypto_refund_address( $payment ) {
		$currency = isset( $payment['currency'] ) && is_scalar( $payment['currency'] ) ? strtoupper( (string) $payment['currency'] ) : '';
		if ( preg_match( '/^(BTC|ETH|SOL|BNB|USDC|USDT)(?:_[A-Z0-9]+)?$/', $currency ) || ( '' !== $currency && ! preg_match( '/^[A-Z]{3}$/', $currency ) ) ) {
			return true;
		}

		$method_values = array();
		foreach ( array( 'payment_method', 'payment_method_type', 'method' ) as $key ) {
			if ( isset( $payment[ $key ] ) && is_scalar( $payment[ $key ] ) ) {
				$method_values[] = strtolower( (string) $payment[ $key ] );
			}
		}
		if ( isset( $payment['payment_method_details'] ) && is_array( $payment['payment_method_details'] ) ) {
			foreach ( $payment['payment_method_details'] as $detail_key => $value ) {
				if ( false !== strpos( strtolower( (string) $detail_key ), 'crypto' ) ) {
					return true;
				}
				if ( is_scalar( $value ) ) {
					$method_values[] = strtolower( (string) $value );
				}
			}
		}

		foreach ( $method_values as $method ) {
			if ( false !== strpos( $method, 'crypto' ) || false !== strpos( $method, 'stablecoin' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Apply the provider's refund state and create a local record after success.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array $refund Bachs refund response.
	 * @return bool|WP_Error
	 */
	private function persist_refund_response( $order, $refund ) {
		$refund_status = isset( $refund['status'] ) && is_scalar( $refund['status'] ) ? strtolower( sanitize_text_field( $refund['status'] ) ) : 'processing';
		if ( ! in_array( $refund_status, array( 'processing', 'success', 'failed' ), true ) ) {
			$refund_status = 'processing';
		}

		$refund['status'] = $refund_status;
		Bachs_Order_Service::apply_refund_record( $order, $refund );

		if ( 'failed' === $refund_status ) {
			return new WP_Error( 'bachs_refund_failed', __( 'Bachs could not complete the refund. No money was returned.', 'bachs-woocommerce' ) );
		}

		return true;
	}

	/**
	 * Map a secret field to its optional wp-config.php constant.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private function constant_for_secret( $key ) {
		$constants = array(
			'sandbox_secret_key'              => 'BACHS_WC_SANDBOX_SECRET_KEY',
			'live_secret_key'                 => 'BACHS_WC_LIVE_SECRET_KEY',
			'sandbox_webhook_secret'          => 'BACHS_WC_SANDBOX_WEBHOOK_SECRET',
			'sandbox_webhook_secret_previous' => 'BACHS_WC_SANDBOX_WEBHOOK_SECRET_PREVIOUS',
			'live_webhook_secret'             => 'BACHS_WC_LIVE_WEBHOOK_SECRET',
			'live_webhook_secret_previous'    => 'BACHS_WC_LIVE_WEBHOOK_SECRET_PREVIOUS',
		);
		return isset( $constants[ $key ] ) ? $constants[ $key ] : '';
	}

	/**
	 * Keep local validation of amount strings free from floating-point math.
	 *
	 * @param string $amount Decimal amount.
	 * @return bool
	 */
	private static function is_positive_decimal( $amount ) {
		return preg_match( '/^\d+(?:\.\d+)?$/', $amount ) && '' !== ltrim( str_replace( '.', '', $amount ), '0' );
	}

	/**
	 * Compare decimal amount strings at WooCommerce's configured precision.
	 *
	 * @param string $left First amount.
	 * @param string $right Second amount.
	 * @return bool
	 */
	private static function decimal_values_match( $left, $right ) {
		$left_normalized  = self::normalize_decimal( $left );
		$right_normalized = self::normalize_decimal( $right );
		return false !== $left_normalized && false !== $right_normalized && $left_normalized === $right_normalized;
	}

	/**
	 * Compare non-negative decimal strings without floating-point conversion.
	 *
	 * @param string $left First value.
	 * @param string $right Second value.
	 * @return bool
	 */
	private static function decimal_is_less_than_or_equal( $left, $right ) {
		$left_value  = self::normalize_decimal( $left );
		$right_value = self::normalize_decimal( $right );
		if ( false === $left_value || false === $right_value ) {
			return false;
		}

		$left_parts  = explode( '.', $left_value, 2 );
		$right_parts = explode( '.', $right_value, 2 );
		if ( strlen( $left_parts[0] ) !== strlen( $right_parts[0] ) ) {
			return strlen( $left_parts[0] ) < strlen( $right_parts[0] );
		}
		if ( $left_parts[0] !== $right_parts[0] ) {
			return strcmp( $left_parts[0], $right_parts[0] ) < 0;
		}
		$left_decimals  = isset( $left_parts[1] ) ? $left_parts[1] : '';
		$right_decimals = isset( $right_parts[1] ) ? $right_parts[1] : '';
		return strcmp( $left_decimals, $right_decimals ) <= 0;
	}

	/**
	 * Normalize a non-negative decimal at the store currency precision.
	 *
	 * Extra trailing zeroes are accepted, while extra non-zero digits are
	 * rejected instead of being silently rounded.
	 *
	 * @param string|float $amount Decimal input.
	 * @return string|false
	 */
	private static function normalize_decimal( $amount ) {
		$amount = trim( (string) $amount );
		if ( ! preg_match( '/^\d+(?:\.\d+)?$/', $amount ) ) {
			return false;
		}

		$precision = wc_get_price_decimals();
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
	 * Log only provider status and error code, never credentials or payloads.
	 *
	 * @param string $operation Operation name.
	 * @param Bachs_Api_Exception $exception API exception.
	 * @return void
	 */
	private function log_api_failure( $operation, $exception ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf( '%s request failed (HTTP %d, code %s).', $operation, $exception->get_http_status(), $exception->get_error_code() ),
				array( 'source' => 'bachs-woocommerce' )
			);
		}
	}
}
