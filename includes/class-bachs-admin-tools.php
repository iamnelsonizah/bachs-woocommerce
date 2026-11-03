<?php
/**
 * Merchant tools for Bachs resources that live outside a WooCommerce order.
 *
 * The tools are intentionally available only to WooCommerce managers. Every
 * write uses an admin-post handler, a nonce, strict input validation, and the
 * same fixed-host API client used by the payment gateway.
 *
 * @package Bachs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bachs_Admin_Tools {
	const PAGE_SLUG   = 'bachs-tools';
	const STATE_OPTION = 'bachs_wc_admin_tool_state';

	/** Register the WooCommerce submenu and protected request handler. */
	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_bachs_admin_tool', array( __CLASS__, 'handle_action' ) );
	}

	/** Add a single entry point for Bachs merchant resources. */
	public static function register_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Bachs tools', 'bachs-woocommerce' ),
			__( 'Bachs tools', 'bachs-woocommerce' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/** Render the selected merchant tool. */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Bachs settings.', 'bachs-woocommerce' ) );
		}

		$tabs = array(
			'products'      => __( 'Products', 'bachs-woocommerce' ),
			'subscriptions' => __( 'Subscriptions', 'bachs-woocommerce' ),
			'disputes'      => __( 'Disputes', 'bachs-woocommerce' ),
			'payouts'       => __( 'Balances and payouts', 'bachs-woocommerce' ),
			'virtual'       => __( 'Virtual account', 'bachs-woocommerce' ),
			'checkout'      => __( 'Checkout settings', 'bachs-woocommerce' ),
			'portal'        => __( 'Customer portal', 'bachs-woocommerce' ),
		);
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'products';
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'products';
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Bachs tools', 'bachs-woocommerce' ) . '</h1>';
		self::render_notice();
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Bachs tools', 'bachs-woocommerce' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $key ), admin_url( 'admin.php' ) );
			echo '<a class="nav-tab ' . ( $tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		if ( '' === Bachs_Settings::api_key() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Configure an API key for the selected Bachs environment in WooCommerce → Settings → Payments → Bachs before using these tools.', 'bachs-woocommerce' ) . '</p></div></div>';
			return;
		}

		$api = new Bachs_Api_Client();
		try {
			switch ( $tab ) {
				case 'products':
					self::render_products( $api );
					break;
				case 'subscriptions':
					self::render_subscriptions( $api );
					break;
				case 'disputes':
					self::render_disputes( $api );
					break;
				case 'payouts':
					self::render_payouts( $api );
					break;
				case 'virtual':
					self::render_virtual_account( $api );
					break;
				case 'checkout':
					self::render_checkout_settings( $api );
					break;
				case 'portal':
					self::render_customer_portal();
					break;
			}
		} catch ( Bachs_Api_Exception $exception ) {
			self::render_api_error( $exception );
		}

		echo '</div>';
	}

	/** Validate and dispatch a WooCommerce manager's write action. */
	public static function handle_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Bachs resources.', 'bachs-woocommerce' ) );
		}
		check_admin_referer( 'bachs_admin_tool_action' );

		$tab    = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$action = isset( $_POST['bachs_tool_action'] ) ? sanitize_key( wp_unslash( $_POST['bachs_tool_action'] ) ) : '';
		$api    = new Bachs_Api_Client();
		try {
			switch ( $action ) {
				case 'create_product':
					self::handle_create_product( $api );
					$tab = 'products';
					break;
				case 'retry_product':
					self::handle_retry_product( $api );
					$tab = 'products';
					break;
				case 'cancel_subscription':
					self::handle_cancel_subscription( $api );
					$tab = 'subscriptions';
					break;
				case 'save_dispute_evidence':
					self::handle_save_dispute_evidence( $api );
					$tab = 'disputes';
					break;
				case 'upload_dispute_document':
					self::handle_upload_dispute_document( $api );
					$tab = 'disputes';
					break;
				case 'submit_dispute':
					self::handle_submit_dispute( $api );
					$tab = 'disputes';
					break;
				case 'create_payout':
					self::handle_create_payout( $api );
					$tab = 'payouts';
					break;
				case 'retry_payout':
					self::handle_retry_payout( $api );
					$tab = 'payouts';
					break;
				case 'save_payout_schedule':
					self::handle_save_payout_schedule( $api );
					$tab = 'payouts';
					break;
				case 'create_virtual_account':
					self::handle_create_virtual_account( $api );
					$tab = 'virtual';
					break;
				case 'save_checkout_settings':
					self::handle_save_checkout_settings( $api );
					$tab = 'checkout';
					break;
				case 'create_portal_session':
					self::handle_create_portal_session( $api );
					$tab = 'portal';
					break;
				default:
					wp_die( esc_html__( 'The requested Bachs action is not available.', 'bachs-woocommerce' ) );
			}
			$extra = array();
			if ( 'disputes' === $tab && isset( $_POST['dispute_id'] ) ) {
				$posted_dispute_id = sanitize_text_field( wp_unslash( $_POST['dispute_id'] ) );
				if ( preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $posted_dispute_id ) ) {
					$extra['dispute_id'] = $posted_dispute_id;
				}
			}
			self::redirect( $tab, 'success', '', $extra );
		} catch ( Bachs_Api_Exception $exception ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'Bachs admin tool request failed (' . $exception->get_error_code() . ').',
					array( 'source' => 'bachs-woocommerce' )
				);
			}
			$extra = self::posted_dispute_return_args( $tab );
			self::redirect( $tab, 'api_error', $exception->get_error_code(), $extra );
		} catch ( InvalidArgumentException $exception ) {
			self::redirect( $tab, 'invalid', '', self::posted_dispute_return_args( $tab ) );
		} catch ( Throwable $exception ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Bachs admin tool action failed (' . get_class( $exception ) . ').', array( 'source' => 'bachs-woocommerce' ) );
			}
			self::redirect( $tab, 'api_error', '', self::posted_dispute_return_args( $tab ) );
		}
	}

	/** Render products and the form for creating a product in the Bachs catalog. */
	private static function render_products( $api ) {
		$cursor = isset( $_GET['cursor'] ) ? sanitize_text_field( wp_unslash( $_GET['cursor'] ) ) : '';
		$page = $api->list_products( array( 'limit' => 25, 'cursor' => $cursor ) );
		echo '<h2>' . esc_html__( 'Bachs product catalog', 'bachs-woocommerce' ) . '</h2>';
		$products = self::items( $page, array( 'items', 'products' ) );
		if ( empty( $products ) ) {
			echo '<p>' . esc_html__( 'No products were returned for this page.', 'bachs-woocommerce' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Price', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Billing', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Status', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'ID', 'bachs-woocommerce' ) . '</th></tr></thead><tbody>';
			foreach ( $products as $product ) {
				$price = isset( $product['price'] ) && is_array( $product['price'] ) ? $product['price'] : array();
				$cycle = isset( $product['billing_cycle'] ) && is_array( $product['billing_cycle'] ) ? $product['billing_cycle'] : array();
				$billing = empty( $cycle ) ? __( 'One-time', 'bachs-woocommerce' ) : self::billing_label( $cycle );
				echo '<tr><td>' . esc_html( self::value( $product, 'name' ) ) . '</td><td>' . esc_html( self::value( $price, 'amount' ) . ' ' . self::value( $price, 'currency' ) ) . '</td><td>' . esc_html( $billing ) . '</td><td>' . esc_html( self::value( $product, 'status' ) ) . '</td><td><code>' . esc_html( self::value( $product, 'id' ) ) . '</code></td></tr>';
			}
			echo '</tbody></table>';
		}
		$pagination = isset( $page['pagination'] ) && is_array( $page['pagination'] ) ? $page['pagination'] : array();
		self::render_cursor_pagination( 'products', $pagination );
		$pending = get_transient( self::product_operation_transient_key() );
		if ( is_array( $pending ) && isset( $pending['environment'], $pending['reference'] ) && Bachs_Settings::environment() === $pending['environment'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'A product request may have reached Bachs without a response. Retry the saved request with the same reference to recover it safely.', 'bachs-woocommerce' ) . ' <code>' . esc_html( $pending['reference'] ) . '</code></p>';
			self::form_start( 'products', 'retry_product' );
			self::form_end( __( 'Retry saved product request', 'bachs-woocommerce' ) );
			echo '</div>';
		}

		echo '<h2>' . esc_html__( 'Create a Bachs product', 'bachs-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'This adds a product to the Bachs catalog. It does not change WooCommerce product prices or checkout line items.', 'bachs-woocommerce' ) . '</p>';
		self::form_start( 'products', 'create_product' );
		echo '<table class="form-table"><tr><th><label for="bachs-product-name">' . esc_html__( 'Name', 'bachs-woocommerce' ) . '</label></th><td><input required maxlength="255" class="regular-text" id="bachs-product-name" name="name"></td></tr>';
		echo '<tr><th><label for="bachs-product-reference">' . esc_html__( 'Stable reference', 'bachs-woocommerce' ) . '</label></th><td><input required class="regular-text" maxlength="80" pattern="[A-Za-z0-9_-]+" id="bachs-product-reference" name="reference"><p class="description">' . esc_html__( 'Use a unique reference for this catalog item. It is used to make retries safe.', 'bachs-woocommerce' ) . '</p></td></tr>';
		echo '<tr><th><label for="bachs-product-description">' . esc_html__( 'Description', 'bachs-woocommerce' ) . '</label></th><td><textarea class="large-text" rows="3" maxlength="5000" id="bachs-product-description" name="description"></textarea></td></tr>';
		echo '<tr><th><label for="bachs-product-currency">' . esc_html__( 'Currency', 'bachs-woocommerce' ) . '</label></th><td><input required class="small-text" maxlength="3" pattern="[A-Za-z]{3}" value="' . esc_attr( get_woocommerce_currency() ) . '" id="bachs-product-currency" name="currency"></td></tr>';
		echo '<tr><th><label for="bachs-product-amount">' . esc_html__( 'Amount', 'bachs-woocommerce' ) . '</label></th><td><input required class="regular-text" inputmode="decimal" pattern="[0-9]+(\\.[0-9]{1,6})?" id="bachs-product-amount" name="amount"></td></tr>';
		echo '<tr><th><label for="bachs-product-interval">' . esc_html__( 'Recurring cadence', 'bachs-woocommerce' ) . '</label></th><td><select id="bachs-product-interval" name="interval"><option value="">' . esc_html__( 'One-time product', 'bachs-woocommerce' ) . '</option><option value="day">' . esc_html__( 'Every N days', 'bachs-woocommerce' ) . '</option><option value="week">' . esc_html__( 'Every N weeks', 'bachs-woocommerce' ) . '</option><option value="month">' . esc_html__( 'Every N months', 'bachs-woocommerce' ) . '</option><option value="year">' . esc_html__( 'Every N years', 'bachs-woocommerce' ) . '</option></select> <input class="small-text" type="number" min="1" max="12" value="1" name="frequency" aria-label="' . esc_attr__( 'Billing frequency', 'bachs-woocommerce' ) . '"></td></tr></table>';
		self::form_end( __( 'Create product', 'bachs-woocommerce' ) );
	}

	/** Render Bachs subscriptions and safe cancellation controls. */
	private static function render_subscriptions( $api ) {
		$offset = isset( $_GET['offset'] ) ? absint( wp_unslash( $_GET['offset'] ) ) : 0;
		$page = $api->list_subscriptions( array( 'limit' => 25, 'offset' => $offset ) );
		$items = self::items( $page, array( 'items', 'subscriptions' ) );
		echo '<h2>' . esc_html__( 'Bachs subscriptions', 'bachs-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'These are Bachs subscriptions for the selected environment. This page does not create WooCommerce subscription orders or control product access.', 'bachs-woocommerce' ) . '</p>';
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'No subscriptions were returned for this page.', 'bachs-woocommerce' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Customer', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Product', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Amount', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Status', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Next billing', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Actions', 'bachs-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$customer = isset( $item['customer'] ) && is_array( $item['customer'] ) ? $item['customer'] : array();
			$product = isset( $item['product'] ) && is_array( $item['product'] ) ? $item['product'] : array();
			$can_cancel = in_array( self::value( $item, 'status' ), array( 'active', 'trialing', 'past_due', 'paused' ), true );
			echo '<tr><td>' . esc_html( self::value( $customer, 'email' ) ) . '</td><td>' . esc_html( self::value( $product, 'name' ) ) . '</td><td>' . esc_html( self::value( $item, 'amount' ) . ' ' . self::value( $item, 'currency' ) ) . '</td><td>' . esc_html( self::value( $item, 'status' ) ) . '</td><td>' . esc_html( self::value( $item, 'next_billed_at' ) ) . '</td><td>';
			if ( $can_cancel && '' !== self::value( $item, 'id' ) ) {
				self::form_start( 'subscriptions', 'cancel_subscription' );
				echo '<input type="hidden" name="subscription_id" value="' . esc_attr( self::value( $item, 'id' ) ) . '">';
				echo '<label><input type="checkbox" name="cancel_at_period_end" value="yes" checked> ' . esc_html__( 'At period end', 'bachs-woocommerce' ) . '</label> ';
				echo '<input type="text" maxlength="255" name="reason" placeholder="' . esc_attr__( 'Reason (optional)', 'bachs-woocommerce' ) . '"> ';
				echo '<label><input required type="checkbox" name="confirm_cancel" value="yes"> ' . esc_html__( 'Confirm cancellation', 'bachs-woocommerce' ) . '</label> ';
				echo '<button class="button" type="submit">' . esc_html__( 'Cancel subscription', 'bachs-woocommerce' ) . '</button>';
				self::form_close();
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$total = isset( $page['pagination']['total'] ) ? absint( $page['pagination']['total'] ) : ( isset( $page['total'] ) ? absint( $page['total'] ) : 0 );
		self::render_offset_pagination( 'subscriptions', $offset, 25, $total );
	}

	/** Render dispute list and its text evidence editor. */
	private static function render_disputes( $api ) {
		$offset = isset( $_GET['offset'] ) ? absint( wp_unslash( $_GET['offset'] ) ) : 0;
		$page = $api->list_disputes( array( 'limit' => 25, 'offset' => $offset ) );
		$items = self::items( $page, array( 'items', 'disputes' ) );
		echo '<h2>' . esc_html__( 'Bachs disputes', 'bachs-woocommerce' ) . '</h2>';
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'No disputes were returned for this page.', 'bachs-woocommerce' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'ID', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Reason', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Amount', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Status', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Deadline', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Review', 'bachs-woocommerce' ) . '</th></tr></thead><tbody>';
			foreach ( $items as $item ) {
				$id = self::value( $item, 'dispute_id' );
				$detail_url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'disputes', 'dispute_id' => $id ), admin_url( 'admin.php' ) );
				echo '<tr><td><code>' . esc_html( $id ) . '</code></td><td>' . esc_html( self::value( $item, 'reason' ) ) . '</td><td>' . esc_html( self::value( $item, 'amount' ) . ' ' . self::value( $item, 'currency' ) ) . '</td><td>' . esc_html( self::value( $item, 'status' ) ) . '</td><td>' . esc_html( self::value( $item, 'response_deadline_at' ) ) . '</td><td><a class="button" href="' . esc_url( $detail_url ) . '">' . esc_html__( 'Review evidence', 'bachs-woocommerce' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		self::render_offset_pagination( 'disputes', $offset, 25, isset( $page['total'] ) ? absint( $page['total'] ) : 0 );

		$dispute_id = isset( $_GET['dispute_id'] ) ? sanitize_text_field( wp_unslash( $_GET['dispute_id'] ) ) : '';
		if ( '' === $dispute_id ) {
			return;
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $dispute_id ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The dispute ID is invalid.', 'bachs-woocommerce' ) . '</p></div>';
			return;
		}
		$dispute = $api->retrieve_dispute( $dispute_id );
		$evidence = isset( $dispute['evidence'] ) && is_array( $dispute['evidence'] ) ? $dispute['evidence'] : array();
		if ( ! isset( $dispute['is_response_editable'] ) || true !== $dispute['is_response_editable'] ) {
			echo '<h2>' . esc_html__( 'Evidence for dispute', 'bachs-woocommerce' ) . ' <code>' . esc_html( $dispute_id ) . '</code></h2>';
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Bachs reports that a response for this dispute is no longer editable. Review its status in Bachs before taking further action.', 'bachs-woocommerce' ) . '</p></div>';
			return;
		}
		$fields = array(
			'customer_name' => __( 'Customer name', 'bachs-woocommerce' ),
			'customer_email_address' => __( 'Customer email', 'bachs-woocommerce' ),
			'billing_address' => __( 'Billing address', 'bachs-woocommerce' ),
			'product_description' => __( 'Product or service description', 'bachs-woocommerce' ),
			'service_date' => __( 'Service date', 'bachs-woocommerce' ),
			'access_activity_log' => __( 'Access or activity log', 'bachs-woocommerce' ),
			'customer_communication_attachment_id' => __( 'Customer communication upload ID', 'bachs-woocommerce' ),
			'cancellation_policy_attachment_id' => __( 'Cancellation policy upload ID', 'bachs-woocommerce' ),
			'refund_policy_attachment_id' => __( 'Refund policy upload ID', 'bachs-woocommerce' ),
			'uncategorized_attachment_id' => __( 'Other supporting document upload ID', 'bachs-woocommerce' ),
			'cancellation_policy_disclosure' => __( 'Cancellation policy disclosure', 'bachs-woocommerce' ),
			'refund_policy_disclosure' => __( 'Refund policy disclosure', 'bachs-woocommerce' ),
			'refund_refusal_explanation' => __( 'Refund refusal explanation', 'bachs-woocommerce' ),
			'notes' => __( 'Additional notes', 'bachs-woocommerce' ),
		);
		echo '<h2>' . esc_html__( 'Evidence for dispute', 'bachs-woocommerce' ) . ' <code>' . esc_html( $dispute_id ) . '</code></h2>';
		$uploaded = get_transient( self::dispute_upload_transient_key( $dispute_id ) );
		if ( is_array( $uploaded ) && isset( $uploaded['document_id'] ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Uploaded document ID. Copy it into the matching evidence field below before saving:', 'bachs-woocommerce' ) . ' <code>' . esc_html( $uploaded['document_id'] ) . '</code></p></div>';
		}
		echo '<h3>' . esc_html__( 'Upload supporting document', 'bachs-woocommerce' ) . '</h3><p>' . esc_html__( 'Accepted formats: PDF, JPEG, PNG, and GIF. Maximum file size: 10 MB. The file is sent to Bachs and is not added to the WordPress media library.', 'bachs-woocommerce' ) . '</p>';
		self::form_start( 'disputes', 'upload_dispute_document' );
		echo '<input type="hidden" name="dispute_id" value="' . esc_attr( $dispute_id ) . '"><p><label for="bachs-dispute-document">' . esc_html__( 'Supporting document', 'bachs-woocommerce' ) . '</label> <input required type="file" id="bachs-dispute-document" name="document" accept=".pdf,.jpg,.jpeg,.png,.gif,application/pdf,image/jpeg,image/png,image/gif"></p>';
		self::form_end( __( 'Upload document', 'bachs-woocommerce' ) );
		self::form_start( 'disputes', 'save_dispute_evidence' );
		echo '<input type="hidden" name="dispute_id" value="' . esc_attr( $dispute_id ) . '"><table class="form-table">';
		foreach ( $fields as $key => $label ) {
			$value = isset( $evidence[ $key ] ) && is_scalar( $evidence[ $key ] ) ? (string) $evidence[ $key ] : '';
			echo '<tr><th><label for="bachs-evidence-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><textarea class="large-text" rows="2" maxlength="5000" id="bachs-evidence-' . esc_attr( $key ) . '" name="evidence[' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea></td></tr>';
		}
		echo '</table>';
		self::form_end( __( 'Save evidence', 'bachs-woocommerce' ) );
		self::form_start( 'disputes', 'submit_dispute' );
		echo '<input type="hidden" name="dispute_id" value="' . esc_attr( $dispute_id ) . '"><p><label><input required type="checkbox" name="confirm_submit" value="yes"> ' . esc_html__( 'I have reviewed this response and want to submit it to Bachs. Submission may be final.', 'bachs-woocommerce' ) . '</label></p>';
		self::form_end( __( 'Submit dispute response', 'bachs-woocommerce' ), 'button button-primary' );
	}

	/** Render balances, approved destinations, and payout history. */
	private static function render_payouts( $api ) {
		$balances = $api->get_balances();
		$destination_offset = isset( $_GET['destination_offset'] ) ? absint( wp_unslash( $_GET['destination_offset'] ) ) : 0;
		$payout_offset = isset( $_GET['offset'] ) ? absint( wp_unslash( $_GET['offset'] ) ) : 0;
		$destinations_page = $api->list_payout_destinations( array( 'limit' => 50, 'offset' => $destination_offset ) );
		$payouts_page = $api->list_payouts( array( 'limit' => 25, 'offset' => $payout_offset ) );
		$balance_items = self::items( $balances, array( 'balances', 'items' ) );
		$destinations = self::items( $destinations_page, array( 'destinations', 'items' ) );
		$payouts = self::items( $payouts_page, array( 'items', 'payouts' ) );
		echo '<h2>' . esc_html__( 'Balances', 'bachs-woocommerce' ) . '</h2>';
		if ( empty( $balance_items ) ) {
			echo '<p>' . esc_html__( 'No balance buckets were returned.', 'bachs-woocommerce' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Currency', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Available', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Pending', 'bachs-woocommerce' ) . '</th></tr></thead><tbody>';
			foreach ( $balance_items as $balance ) {
				echo '<tr><td>' . esc_html( self::value( $balance, 'currency' ) ) . '</td><td>' . esc_html( self::value( $balance, 'available_balance' ) ) . '</td><td>' . esc_html( self::value( $balance, 'pending_balance' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		self::render_payout_schedules( $api, $balance_items );

		echo '<h2>' . esc_html__( 'Payout destinations', 'bachs-woocommerce' ) . '</h2>';
		$usable_destinations = array();
		if ( ! empty( $destinations ) ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Destination', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Currency', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Status', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Usable', 'bachs-woocommerce' ) . '</th></tr></thead><tbody>';
			foreach ( $destinations as $destination ) {
				$name = self::value( $destination, 'name' );
				if ( '' === $name ) {
					$name = self::value( $destination, 'bank_name' );
				}
				$usable = isset( $destination['is_usable'] ) && true === $destination['is_usable'];
				if ( $usable && '' !== self::value( $destination, 'id' ) ) {
					$usable_destinations[] = $destination;
				}
				echo '<tr><td>' . esc_html( $name . ' (' . self::value( $destination, 'id' ) . ')' ) . '</td><td>' . esc_html( self::value( $destination, 'currency' ) ) . '</td><td>' . esc_html( self::value( $destination, 'status' ) ) . '</td><td>' . esc_html( $usable ? __( 'Yes', 'bachs-woocommerce' ) : __( 'No', 'bachs-woocommerce' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'No payout destinations were returned. Add and approve a destination in your Bachs account before creating a payout.', 'bachs-woocommerce' ) . '</p>';
		}
		self::render_offset_pagination( 'payouts', $destination_offset, 50, isset( $destinations_page['total'] ) ? absint( $destinations_page['total'] ) : 0, 'destination_offset' );

		echo '<h2>' . esc_html__( 'Create a payout', 'bachs-woocommerce' ) . '</h2>';
		echo '<p class="notice notice-warning inline"><strong>' . esc_html__( 'This sends money from your Bachs balance.', 'bachs-woocommerce' ) . '</strong> ' . esc_html__( 'Confirm the destination and amount in Bachs before submitting. The payout fee is charged on top of the requested amount.', 'bachs-woocommerce' ) . '</p>';
		if ( empty( $usable_destinations ) ) {
			echo '<p>' . esc_html__( 'A usable destination is required before this store can create a payout.', 'bachs-woocommerce' ) . '</p>';
		} else {
			$pending = get_transient( self::payout_operation_transient_key() );
			if ( is_array( $pending ) && isset( $pending['environment'], $pending['payload'] ) && Bachs_Settings::environment() === $pending['environment'] ) {
				$reference = self::value( $pending['payload'], 'reference' );
				$reconciled = $api->list_payouts( array( 'limit' => 1, 'offset' => 0, 'reference' => $reference ) );
				$known_payouts = self::items( $reconciled, array( 'items' ) );
				if ( ! empty( $known_payouts ) ) {
					echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Bachs already has a payout with the saved reference. Its latest status is:', 'bachs-woocommerce' ) . ' <strong>' . esc_html( self::value( $known_payouts[0], 'status' ) ) . '</strong></p></div>';
				} else {
					echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'A payout request may have reached Bachs without a response. Check the recent payout history, then use this retry control to repeat the exact saved request with its original idempotency key.', 'bachs-woocommerce' ) . ' <code>' . esc_html( $reference ) . '</code></p>';
					self::form_start( 'payouts', 'retry_payout' );
					echo '<p><label><input required type="checkbox" name="confirm_payout" value="yes"> ' . esc_html__( 'I have checked the payout history and confirm retrying this exact request.', 'bachs-woocommerce' ) . '</label></p>';
					self::form_end( __( 'Retry saved payout request', 'bachs-woocommerce' ), 'button button-primary' );
					echo '</div>';
				}
			}
			self::form_start( 'payouts', 'create_payout' );
			echo '<table class="form-table"><tr><th><label for="bachs-payout-destination">' . esc_html__( 'Destination', 'bachs-woocommerce' ) . '</label></th><td><select required id="bachs-payout-destination" name="destination">';
			foreach ( $usable_destinations as $destination ) {
				$label = self::value( $destination, 'name' );
				if ( '' === $label ) {
					$label = self::value( $destination, 'bank_name' );
				}
				$label .= ' — ' . self::value( $destination, 'currency' ) . ' — ' . self::value( $destination, 'id' );
				echo '<option value="' . esc_attr( self::value( $destination, 'id' ) ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr><tr><th><label for="bachs-payout-amount">' . esc_html__( 'Recipient amount', 'bachs-woocommerce' ) . '</label></th><td><input required class="regular-text" inputmode="decimal" pattern="[0-9]+(\\.[0-9]{1,6})?" id="bachs-payout-amount" name="amount"></td></tr>';
			echo '<tr><th><label for="bachs-payout-reference">' . esc_html__( 'Unique reference', 'bachs-woocommerce' ) . '</label></th><td><input required class="regular-text" maxlength="100" id="bachs-payout-reference" name="reference"><p class="description">' . esc_html__( 'Keep this reference unchanged when reconciling an uncertain request. A different request needs a new reference.', 'bachs-woocommerce' ) . '</p></td></tr></table>';
			echo '<p><label><input required type="checkbox" name="confirm_payout" value="yes"> ' . esc_html__( 'I confirm that this payout should be sent.', 'bachs-woocommerce' ) . '</label></p>';
			self::form_end( __( 'Create payout', 'bachs-woocommerce' ), 'button button-primary' );
		}

		echo '<h2>' . esc_html__( 'Recent payouts', 'bachs-woocommerce' ) . '</h2>';
		if ( empty( $payouts ) ) {
			echo '<p>' . esc_html__( 'No payouts were returned for this page.', 'bachs-woocommerce' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'ID', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Amount', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Status', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Created', 'bachs-woocommerce' ) . '</th></tr></thead><tbody>';
			foreach ( $payouts as $payout ) {
				echo '<tr><td><code>' . esc_html( self::value( $payout, 'id' ) ) . '</code></td><td>' . esc_html( self::value( $payout, 'amount' ) . ' ' . self::value( $payout, 'currency' ) ) . '</td><td>' . esc_html( self::value( $payout, 'status' ) ) . '</td><td>' . esc_html( self::value( $payout, 'created_at' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		self::render_offset_pagination( 'payouts', $payout_offset, 25, isset( $payouts_page['total'] ) ? absint( $payouts_page['total'] ) : 0 );
	}

	/** Show and edit each balance currency's automatic payout schedule. */
	private static function render_payout_schedules( $api, $balance_items ) {
		$schedules_response = $api->get_payout_schedule();
		$schedules = isset( $schedules_response['schedule_by_currency'] ) && is_array( $schedules_response['schedule_by_currency'] )
			? $schedules_response['schedule_by_currency']
			: array();

		echo '<h2>' . esc_html__( 'Automatic payout schedules', 'bachs-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Schedules can automatically send eligible settled customer collections to the account’s default payout destination. A saved schedule controls when Bachs starts a payout; it does not guarantee bank or wallet delivery time. Editing one currency leaves other currencies unchanged.', 'bachs-woocommerce' ) . '</p>';
		if ( ! empty( $schedules ) ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Currency', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Interval', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Next run (UTC)', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Minimum balance', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Status', 'bachs-woocommerce' ) . '</th></tr></thead><tbody>';
			foreach ( $schedules as $currency => $schedule ) {
				if ( ! is_array( $schedule ) || ! preg_match( '/^[A-Z0-9_]{3,10}$/', (string) $currency ) ) {
					continue;
				}
				$interval = self::value( $schedule, 'interval' );
				$days = array();
				if ( isset( $schedule['weekly_payout_days'] ) && is_array( $schedule['weekly_payout_days'] ) ) {
					$days = array_map( 'sanitize_key', $schedule['weekly_payout_days'] );
				} elseif ( isset( $schedule['monthly_payout_days'] ) && is_array( $schedule['monthly_payout_days'] ) ) {
					$days = array_map( 'absint', $schedule['monthly_payout_days'] );
				}
				$details = '' !== self::value( $schedule, 'payout_currency' ) && $currency !== self::value( $schedule, 'payout_currency' )
					? ' ' . sprintf( __( '(pays out as %s)', 'bachs-woocommerce' ), self::value( $schedule, 'payout_currency' ) )
					: '';
				$next_run = self::value( $schedule, 'next_run_at' );
				$minimum = self::value( $schedule, 'minimum_amount' );
				$status = self::value( $schedule, 'disabled_reason' );
				if ( '' === $status ) {
					$status = 'manual' === $interval ? __( 'Automatic payouts off', 'bachs-woocommerce' ) : __( 'Enabled', 'bachs-woocommerce' );
				}
				$row_details = '' !== $next_run ? $next_run : __( 'Not scheduled', 'bachs-woocommerce' );
				if ( ! empty( $days ) ) {
					$row_details .= ' (' . implode( ', ', $days ) . ')';
				}
				echo '<tr><td>' . esc_html( $currency . $details ) . '</td><td>' . esc_html( $interval ) . '</td><td>' . esc_html( $row_details ) . '</td><td>' . esc_html( $minimum ) . '</td><td>' . esc_html( $status ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'No automatic payout schedules are configured.', 'bachs-woocommerce' ) . '</p>';
		}

		if ( empty( $balance_items ) ) {
			echo '<p>' . esc_html__( 'A balance currency is required before you can configure a schedule.', 'bachs-woocommerce' ) . '</p>';
			return;
		}

		echo '<h3>' . esc_html__( 'Set a schedule for one currency', 'bachs-woocommerce' ) . '</h3>';
		echo '<p>' . esc_html__( 'Changing a schedule replaces that currency’s settings. Select Manual to disable automatic payouts for that currency.', 'bachs-woocommerce' ) . '</p>';
		self::form_start( 'payouts', 'save_payout_schedule' );
		echo '<table class="form-table"><tr><th><label for="bachs-schedule-currency">' . esc_html__( 'Balance currency', 'bachs-woocommerce' ) . '</label></th><td><select required id="bachs-schedule-currency" name="currency">';
		foreach ( $balance_items as $balance ) {
			$currency = strtoupper( self::value( $balance, 'currency' ) );
			if ( preg_match( '/^[A-Z0-9_]{3,10}$/', $currency ) ) {
				echo '<option value="' . esc_attr( $currency ) . '">' . esc_html( $currency ) . '</option>';
			}
		}
		echo '</select></td></tr><tr><th><label for="bachs-schedule-interval">' . esc_html__( 'Interval', 'bachs-woocommerce' ) . '</label></th><td><select required id="bachs-schedule-interval" name="interval">';
		foreach ( array( 'manual' => __( 'Manual (turn automatic payouts off)', 'bachs-woocommerce' ), 'instant' => __( 'Instant after settlement', 'bachs-woocommerce' ), 'daily' => __( 'Daily', 'bachs-woocommerce' ), 'weekly' => __( 'Weekly', 'bachs-woocommerce' ), 'monthly' => __( 'Monthly', 'bachs-woocommerce' ) ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr><tr><th><label for="bachs-schedule-hour">' . esc_html__( 'Run hour (UTC)', 'bachs-woocommerce' ) . '</label></th><td><input type="number" min="0" max="23" value="10" id="bachs-schedule-hour" name="anchor_hour_utc"></td></tr><tr><th>' . esc_html__( 'Weekly days', 'bachs-woocommerce' ) . '</th><td>';
		foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $day ) {
			echo '<label style="display:inline-block;margin:0 10px 5px 0"><input type="checkbox" name="weekly_days[]" value="' . esc_attr( $day ) . '"> ' . esc_html( ucfirst( $day ) ) . '</label>';
		}
		echo '</td></tr><tr><th><label for="bachs-schedule-monthly-days">' . esc_html__( 'Monthly days', 'bachs-woocommerce' ) . '</label></th><td><input type="text" class="regular-text" id="bachs-schedule-monthly-days" name="monthly_days" value="1"><p class="description">' . esc_html__( 'Comma-separated dates from 1 to 31, for example 1,15. Dates beyond a short month run on its last day.', 'bachs-woocommerce' ) . '</p></td></tr><tr><th><label for="bachs-schedule-minimum">' . esc_html__( 'Minimum amount', 'bachs-woocommerce' ) . '</label></th><td><input type="text" inputmode="decimal" pattern="[0-9]+(\\.[0-9]{1,6})?" id="bachs-schedule-minimum" name="minimum_amount"><p class="description">' . esc_html__( 'Optional floor in the balance currency. Below this eligible total, Bachs waits for a later run.', 'bachs-woocommerce' ) . '</p></td></tr></table>';
		echo '<p><label><input required type="checkbox" name="confirm_schedule" value="yes"> ' . esc_html__( 'I understand this changes the automatic payout schedule for the selected Bachs balance currency.', 'bachs-woocommerce' ) . '</label></p>';
		self::form_end( __( 'Save payout schedule', 'bachs-woocommerce' ), 'button button-primary' );
	}

	/** Show the merchant's stored virtual account and a create/retrieve control. */
	private static function render_virtual_account( $api ) {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$env = Bachs_Settings::environment();
		$id = isset( $state['virtual_accounts'][ $env ] ) ? sanitize_text_field( $state['virtual_accounts'][ $env ] ) : '';
		echo '<h2>' . esc_html__( 'NGN virtual account', 'bachs-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Bachs issues one fixed NGN account for the merchant account in the selected environment. It is shared by your Bachs customers; it is not a separate account for each WooCommerce customer.', 'bachs-woocommerce' ) . '</p>';
		if ( '' !== $id ) {
			$account = $api->retrieve_virtual_account( $id );
			echo '<table class="widefat striped"><tbody>';
			foreach ( array( 'account_name' => __( 'Account name', 'bachs-woocommerce' ), 'account_number' => __( 'Account number', 'bachs-woocommerce' ), 'bank_name' => __( 'Bank', 'bachs-woocommerce' ), 'currency' => __( 'Currency', 'bachs-woocommerce' ), 'status' => __( 'Status', 'bachs-woocommerce' ) ) as $key => $label ) {
				echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( self::value( $account, $key ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'No virtual account ID is stored for this environment. Creating the account is safe to repeat: Bachs returns the existing NGN account if one has already been issued.', 'bachs-woocommerce' ) . '</p>';
		}
		self::form_start( 'virtual', 'create_virtual_account' );
		echo '<p><label><input required type="checkbox" name="confirm_virtual_account" value="yes"> ' . esc_html__( 'I understand this issues or retrieves the merchant account number for the selected environment.', 'bachs-woocommerce' ) . '</label></p>';
		self::form_end( __( 'Create or retrieve NGN account', 'bachs-woocommerce' ) );
	}

	/** Render and update Bachs checkout payment-method settings. */
	private static function render_checkout_settings( $api ) {
		$settings = $api->get_checkout_settings();
		$methods = isset( $settings['enabled_payment_methods'] ) && is_array( $settings['enabled_payment_methods'] ) ? $settings['enabled_payment_methods'] : array();
		echo '<h2>' . esc_html__( 'Bachs checkout settings', 'bachs-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'These are merchant-level Bachs settings. Changes may affect other integrations that use the same Bachs account.', 'bachs-woocommerce' ) . '</p>';
		if ( empty( $methods ) ) {
			echo '<p>' . esc_html__( 'Bachs did not return payment-method settings for this API key.', 'bachs-woocommerce' ) . '</p>';
			return;
		}
		self::form_start( 'checkout', 'save_checkout_settings' );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Payment method', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Enabled', 'bachs-woocommerce' ) . '</th><th>' . esc_html__( 'Currencies', 'bachs-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $methods as $method => $config ) {
			if ( ! is_array( $config ) || ! preg_match( '/^[A-Z0-9_]{1,64}$/', (string) $method ) ) {
				continue;
			}
			$enabled = isset( $config['enabled'] ) && true === $config['enabled'];
			echo '<tr><td><code>' . esc_html( $method ) . '</code></td><td><label><input type="checkbox" name="methods[' . esc_attr( $method ) . '][enabled]" value="yes" ' . checked( $enabled, true, false ) . '> ' . esc_html__( 'Enabled', 'bachs-woocommerce' ) . '</label></td><td>';
			if ( isset( $config['currencies'] ) && is_array( $config['currencies'] ) ) {
				foreach ( $config['currencies'] as $currency => $currency_enabled ) {
					if ( ! preg_match( '/^[A-Z0-9_]{1,64}$/', (string) $currency ) ) {
						continue;
					}
					echo '<label style="display:inline-block;margin:0 10px 5px 0"><input type="checkbox" name="methods[' . esc_attr( $method ) . '][currencies][' . esc_attr( $currency ) . ']" value="yes" ' . checked( true === $currency_enabled, true, false ) . '> ' . esc_html( $currency ) . '</label>';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table><p><label for="bachs-fee-preference">' . esc_html__( 'Fee preference', 'bachs-woocommerce' ) . '</label> <select id="bachs-fee-preference" name="fee_preference">';
		$fee = isset( $settings['fee_preference'] ) ? sanitize_key( $settings['fee_preference'] ) : 'org_pays';
		foreach ( array( 'org_pays' => __( 'Organization pays', 'bachs-woocommerce' ), 'customer_pays' => __( 'Customer pays', 'bachs-woocommerce' ) ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $fee, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		self::form_end( __( 'Save Bachs checkout settings', 'bachs-woocommerce' ), 'button button-primary' );
	}

	/** Render a tool to create a portal session for an existing Bachs customer ID. */
	private static function render_customer_portal() {
		$transient_key = self::portal_transient_key();
		$url = get_transient( $transient_key );
		if ( is_string( $url ) && '' !== $url ) {
			delete_transient( $transient_key );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'The customer portal session is ready. It expires soon and can be used by the customer to manage their Bachs billing details.', 'bachs-woocommerce' ) . ' <a target="_blank" rel="noopener noreferrer" href="' . esc_url( $url ) . '">' . esc_html__( 'Open portal session', 'bachs-woocommerce' ) . '</a></p></div>';
		}
		echo '<h2>' . esc_html__( 'Create a customer portal session', 'bachs-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Enter a Bachs customer ID from the Bachs dashboard. Portal URLs are short-lived; share them only with the matching customer.', 'bachs-woocommerce' ) . '</p>';
		self::form_start( 'portal', 'create_portal_session' );
		echo '<p><label for="bachs-customer-id">' . esc_html__( 'Bachs customer ID', 'bachs-woocommerce' ) . '</label> <input required class="regular-text" id="bachs-customer-id" name="customer_id" pattern="[A-Za-z0-9_-]{1,128}"></p>';
		self::form_end( __( 'Create portal session', 'bachs-woocommerce' ) );
	}

	/** Create a fixed-price or recurring product in the merchant's Bachs catalog. */
	private static function handle_create_product( $api ) {
		$name = self::post_text( 'name', 255 );
		$description = self::post_textarea( 'description', 5000 );
		$currency = strtoupper( self::post_text( 'currency', 3 ) );
		$amount = self::post_decimal( 'amount' );
		$reference = self::post_text( 'reference', 80 );
		if ( '' === $name || ! preg_match( '/^[A-Z]{3}$/', $currency ) || false === $amount || ! self::is_positive_decimal( $amount ) || ! preg_match( '/^[A-Za-z0-9_-]{1,80}$/', $reference ) ) {
			throw new InvalidArgumentException( 'Invalid product values.' );
		}
		$state = get_option( self::STATE_OPTION, array() );
		$references = isset( $state['product_references'][ Bachs_Settings::environment() ] ) && is_array( $state['product_references'][ Bachs_Settings::environment() ] ) ? $state['product_references'][ Bachs_Settings::environment() ] : array();
		if ( isset( $references[ $reference ] ) ) {
			throw new InvalidArgumentException( 'Product reference already exists.' );
		}
		$payload = array(
			'name'  => $name,
			'price' => array( 'currency' => $currency, 'amount' => $amount ),
			'metadata' => array( 'woocommerce_reference' => $reference ),
		);
		if ( '' !== $description ) {
			$payload['description'] = $description;
		}
		$interval = self::post_text( 'interval', 16 );
		if ( '' !== $interval ) {
			if ( ! in_array( $interval, array( 'day', 'week', 'month', 'year' ), true ) ) {
				throw new InvalidArgumentException( 'Invalid billing interval.' );
			}
			$frequency = isset( $_POST['frequency'] ) ? absint( wp_unslash( $_POST['frequency'] ) ) : 0;
			if ( $frequency < 1 || $frequency > 12 ) {
				throw new InvalidArgumentException( 'Invalid billing frequency.' );
			}
			$payload['billing_cycle'] = array( 'interval' => $interval, 'frequency' => $frequency );
		}
		$key = self::stable_operation_key( 'product', $reference );
		$pending = get_transient( self::product_operation_transient_key() );
		if ( is_array( $pending ) && isset( $pending['environment'], $pending['payload'], $pending['reference'], $pending['idempotency_key'] ) && Bachs_Settings::environment() === $pending['environment'] ) {
			if ( $reference !== $pending['reference'] || $payload !== $pending['payload'] ) {
				throw new InvalidArgumentException( 'An uncertain product request is already saved. Retry it exactly before starting another product.' );
			}
			$key = $pending['idempotency_key'];
		} else {
			set_transient( self::product_operation_transient_key(), array( 'environment' => Bachs_Settings::environment(), 'reference' => $reference, 'payload' => $payload, 'idempotency_key' => $key ), DAY_IN_SECONDS );
		}
		$product = $api->create_product( $payload, $key );
		self::complete_product_operation( $product, $reference );
	}

	/** Retry an uncertain product write using the exact saved payload and key. */
	private static function handle_retry_product( $api ) {
		$operation = get_transient( self::product_operation_transient_key() );
		if ( ! is_array( $operation ) || ! isset( $operation['environment'], $operation['payload'], $operation['reference'], $operation['idempotency_key'] ) || Bachs_Settings::environment() !== $operation['environment'] ) {
			throw new InvalidArgumentException( 'No matching product operation is available to retry.' );
		}
		$product = $api->create_product( $operation['payload'], $operation['idempotency_key'] );
		self::complete_product_operation( $product, $operation['reference'] );
	}

	/** Cancel a Bachs subscription with an explicit timing choice. */
	private static function handle_cancel_subscription( $api ) {
		if ( ! isset( $_POST['confirm_cancel'] ) || 'yes' !== wp_unslash( $_POST['confirm_cancel'] ) ) {
			throw new InvalidArgumentException( 'Subscription cancellation was not confirmed.' );
		}
		$id = self::post_identifier( 'subscription_id' );
		$payload = array(
			'cancel_at_period_end' => isset( $_POST['cancel_at_period_end'] ) && 'yes' === wp_unslash( $_POST['cancel_at_period_end'] ),
		);
		$reason = self::post_text( 'reason', 255 );
		if ( '' !== $reason ) {
			$payload['reason'] = $reason;
		}
		$api->cancel_subscription( $id, $payload );
	}

	/** Save only the supported text evidence fields for an open dispute. */
	private static function handle_save_dispute_evidence( $api ) {
		$id = self::post_identifier( 'dispute_id' );
		$allowed = array( 'customer_name', 'customer_email_address', 'billing_address', 'product_description', 'service_date', 'access_activity_log', 'customer_communication_attachment_id', 'cancellation_policy_attachment_id', 'cancellation_policy_disclosure', 'refund_policy_attachment_id', 'refund_policy_disclosure', 'refund_refusal_explanation', 'uncategorized_attachment_id', 'notes' );
		$posted = isset( $_POST['evidence'] ) && is_array( $_POST['evidence'] ) ? wp_unslash( $_POST['evidence'] ) : array();
		$payload = array();
		foreach ( $allowed as $key ) {
			if ( ! isset( $posted[ $key ] ) || ! is_scalar( $posted[ $key ] ) ) {
				continue;
			}
			$value = sanitize_textarea_field( (string) $posted[ $key ] );
			if ( '' !== $value ) {
				$payload[ $key ] = substr( $value, 0, 5000 );
			}
		}
		if ( empty( $payload ) ) {
			throw new InvalidArgumentException( 'No evidence fields were supplied.' );
		}
		$api->update_dispute_evidence( $id, $payload );
	}

	/** Validate and forward one uploaded document without retaining it in WordPress. */
	private static function handle_upload_dispute_document( $api ) {
		$id = self::post_identifier( 'dispute_id' );
		if ( ! isset( $_FILES['document'] ) || ! is_array( $_FILES['document'] ) ) {
			throw new InvalidArgumentException( 'Missing dispute document.' );
		}
		$file = $_FILES['document'];
		if ( ! isset( $file['error'], $file['tmp_name'], $file['name'], $file['size'] ) || ! is_scalar( $file['error'] ) || ! is_string( $file['tmp_name'] ) || ! is_string( $file['name'] ) || ! is_numeric( $file['size'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			throw new InvalidArgumentException( 'The dispute document upload failed.' );
		}
		$size = (int) $file['size'];
		$max_size = defined( 'MB_IN_BYTES' ) ? 10 * MB_IN_BYTES : 10485760;
		if ( $size < 1 || $size > $max_size || ! is_readable( $file['tmp_name'] ) ) {
			throw new InvalidArgumentException( 'The dispute document is empty or too large.' );
		}
		$filename = sanitize_file_name( wp_basename( (string) $file['name'] ) );
		$mimes = array(
			'pdf'  => 'application/pdf',
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
		);
		$detected = wp_check_filetype_and_ext( $file['tmp_name'], $filename, $mimes );
		if ( empty( $detected['type'] ) || ! in_array( $detected['type'], array_values( $mimes ), true ) ) {
			throw new InvalidArgumentException( 'The dispute document type is not allowed.' );
		}
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$actual_mime = $finfo ? finfo_file( $finfo, $file['tmp_name'] ) : false;
			if ( $finfo ) {
				finfo_close( $finfo );
			}
			if ( ! is_string( $actual_mime ) || $actual_mime !== $detected['type'] ) {
				throw new InvalidArgumentException( 'The uploaded file content does not match its file type.' );
			}
		}
		$contents = file_get_contents( $file['tmp_name'] );
		if ( ! is_string( $contents ) || strlen( $contents ) !== $size ) {
			throw new InvalidArgumentException( 'The dispute document could not be read.' );
		}
		$uploaded = $api->upload_dispute_document( $filename, $detected['type'], $contents );
		if ( empty( $uploaded['document_id'] ) || ! is_scalar( $uploaded['document_id'] ) ) {
			throw new Bachs_Api_Exception( __( 'Bachs did not return a document identifier.', 'bachs-woocommerce' ), 0, 'invalid_response' );
		}
		set_transient(
			self::dispute_upload_transient_key( $id ),
			array( 'document_id' => sanitize_text_field( (string) $uploaded['document_id'] ) ),
			10 * MINUTE_IN_SECONDS
		);
	}

	/** Require a deliberate confirmation before final dispute submission. */
	private static function handle_submit_dispute( $api ) {
		if ( ! isset( $_POST['confirm_submit'] ) || 'yes' !== wp_unslash( $_POST['confirm_submit'] ) ) {
			throw new InvalidArgumentException( 'Dispute submission was not confirmed.' );
		}
		$api->submit_dispute( self::post_identifier( 'dispute_id' ) );
	}

	/** Create a payout with a stable key derived from the merchant reference. */
	private static function handle_create_payout( $api ) {
		if ( ! isset( $_POST['confirm_payout'] ) || 'yes' !== wp_unslash( $_POST['confirm_payout'] ) ) {
			throw new InvalidArgumentException( 'Payout was not confirmed.' );
		}
		$destination = self::post_identifier( 'destination' );
		$amount = self::post_decimal( 'amount' );
		$reference = self::post_text( 'reference', 100 );
		if ( false === $amount || ! self::is_positive_decimal( $amount ) || ! preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $reference ) ) {
			throw new InvalidArgumentException( 'Invalid payout values.' );
		}
		$payload = array( 'destination' => $destination, 'amount' => $amount, 'reference' => $reference );
		$key = self::stable_operation_key( 'payout', $reference );
		$pending = get_transient( self::payout_operation_transient_key() );
		if ( is_array( $pending ) && isset( $pending['environment'], $pending['payload'], $pending['idempotency_key'] ) && Bachs_Settings::environment() === $pending['environment'] ) {
			if ( $payload !== $pending['payload'] ) {
				throw new InvalidArgumentException( 'A payout with an uncertain outcome is already saved. Retry that exact operation first.' );
			}
			$key = $pending['idempotency_key'];
		} else {
			set_transient( self::payout_operation_transient_key(), array( 'environment' => Bachs_Settings::environment(), 'payload' => $payload, 'idempotency_key' => $key ), DAY_IN_SECONDS );
		}
		$api->create_payout( $payload, $key );
		delete_transient( self::payout_operation_transient_key() );
	}

	/** Retry a payout only after a manager confirms checking the payout history. */
	private static function handle_retry_payout( $api ) {
		if ( ! isset( $_POST['confirm_payout'] ) || 'yes' !== wp_unslash( $_POST['confirm_payout'] ) ) {
			throw new InvalidArgumentException( 'Payout retry was not confirmed.' );
		}
		$pending = get_transient( self::payout_operation_transient_key() );
		if ( ! is_array( $pending ) || ! isset( $pending['environment'], $pending['payload'], $pending['idempotency_key'] ) || Bachs_Settings::environment() !== $pending['environment'] ) {
			throw new InvalidArgumentException( 'No matching payout operation is available to retry.' );
		}
		$api->create_payout( $pending['payload'], $pending['idempotency_key'] );
		delete_transient( self::payout_operation_transient_key() );
	}

	/** Validate and update the schedule for exactly one Bachs balance currency. */
	private static function handle_save_payout_schedule( $api ) {
		if ( ! isset( $_POST['confirm_schedule'] ) || 'yes' !== wp_unslash( $_POST['confirm_schedule'] ) ) {
			throw new InvalidArgumentException( 'Payout schedule change was not confirmed.' );
		}
		$currency = strtoupper( self::post_text( 'currency', 10 ) );
		if ( ! preg_match( '/^[A-Z0-9_]{3,10}$/', $currency ) ) {
			throw new InvalidArgumentException( 'Invalid balance currency.' );
		}
		$balances = $api->get_balances();
		$balance_items = self::items( $balances, array( 'balances', 'items' ) );
		$available = false;
		foreach ( $balance_items as $balance ) {
			if ( $currency === strtoupper( self::value( $balance, 'currency' ) ) ) {
				$available = true;
				break;
			}
		}
		if ( ! $available ) {
			throw new InvalidArgumentException( 'The selected currency is not in the merchant balance.' );
		}

		$interval = self::post_text( 'interval', 16 );
		if ( ! in_array( $interval, array( 'manual', 'instant', 'daily', 'weekly', 'monthly' ), true ) ) {
			throw new InvalidArgumentException( 'Invalid payout interval.' );
		}
		$schedule = array( 'interval' => $interval );
		if ( in_array( $interval, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			$hour = isset( $_POST['anchor_hour_utc'] ) && is_scalar( $_POST['anchor_hour_utc'] ) ? (string) wp_unslash( $_POST['anchor_hour_utc'] ) : '';
			if ( ! preg_match( '/^(?:[0-9]|1[0-9]|2[0-3])$/', $hour ) ) {
				throw new InvalidArgumentException( 'Invalid payout schedule hour.' );
			}
			$schedule['anchor_hour_utc'] = (int) $hour;
		}
		if ( 'weekly' === $interval ) {
			$posted_days = isset( $_POST['weekly_days'] ) && is_array( $_POST['weekly_days'] ) ? wp_unslash( $_POST['weekly_days'] ) : array();
			$days = array();
			foreach ( $posted_days as $day ) {
				if ( is_scalar( $day ) ) {
					$days[] = sanitize_key( (string) $day );
				}
			}
			$days = array_values( array_unique( array_intersect( $days, array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) ) ) );
			if ( empty( $days ) ) {
				throw new InvalidArgumentException( 'Select at least one weekly payout day.' );
			}
			$schedule['weekly_payout_days'] = $days;
		}
		if ( 'monthly' === $interval ) {
			$raw_days = self::post_text( 'monthly_days', 100 );
			$days = array_map( 'trim', explode( ',', $raw_days ) );
			if ( empty( $days ) ) {
				throw new InvalidArgumentException( 'Select at least one monthly payout day.' );
			}
			$monthly_days = array();
			foreach ( $days as $day ) {
				if ( ! preg_match( '/^(?:[1-9]|[12][0-9]|3[01])$/', $day ) ) {
					throw new InvalidArgumentException( 'Monthly payout days must be from 1 to 31.' );
				}
				$monthly_days[] = (int) $day;
			}
			$schedule['monthly_payout_days'] = array_values( array_unique( $monthly_days ) );
		}
		$minimum_text = self::post_text( 'minimum_amount', 32 );
		if ( '' !== $minimum_text && 'manual' !== $interval ) {
			$minimum = self::post_decimal( 'minimum_amount' );
			if ( false === $minimum || ! self::is_positive_decimal( $minimum ) ) {
				throw new InvalidArgumentException( 'The minimum payout amount must be a positive decimal.' );
			}
			$schedule['minimum_amount'] = $minimum;
		}

		// The API replaces a supplied currency and leaves every omitted one intact.
		$api->update_payout_schedule( array( 'schedule_by_currency' => array( $currency => $schedule ) ) );
	}

	/** Create or retrieve the NGN virtual account for the selected environment. */
	private static function handle_create_virtual_account( $api ) {
		if ( ! isset( $_POST['confirm_virtual_account'] ) || 'yes' !== wp_unslash( $_POST['confirm_virtual_account'] ) ) {
			throw new InvalidArgumentException( 'Virtual account action was not confirmed.' );
		}
		$key = 'bachs-wc-virtual-account-' . Bachs_Settings::environment() . '-ngn';
		$account = $api->create_virtual_account( array( 'currency' => 'NGN' ), $key );
		if ( empty( $account['id'] ) || ! is_scalar( $account['id'] ) ) {
			throw new Bachs_Api_Exception( __( 'Bachs did not return a virtual account identifier.', 'bachs-woocommerce' ), 0, 'invalid_response' );
		}
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		if ( ! isset( $state['virtual_accounts'] ) || ! is_array( $state['virtual_accounts'] ) ) {
			$state['virtual_accounts'] = array();
		}
		$state['virtual_accounts'][ Bachs_Settings::environment() ] = sanitize_text_field( (string) $account['id'] );
		update_option( self::STATE_OPTION, $state, false );
	}

	/** Merge administrator-selected booleans into current Bachs checkout settings. */
	private static function handle_save_checkout_settings( $api ) {
		$current = $api->get_checkout_settings();
		$methods = isset( $current['enabled_payment_methods'] ) && is_array( $current['enabled_payment_methods'] ) ? $current['enabled_payment_methods'] : array();
		$posted = isset( $_POST['methods'] ) && is_array( $_POST['methods'] ) ? wp_unslash( $_POST['methods'] ) : array();
		foreach ( $methods as $method => $config ) {
			if ( ! isset( $posted[ $method ] ) || ! is_array( $posted[ $method ] ) || ! is_array( $config ) ) {
				$methods[ $method ]['enabled'] = false;
				if ( isset( $methods[ $method ]['currencies'] ) && is_array( $methods[ $method ]['currencies'] ) ) {
					foreach ( $methods[ $method ]['currencies'] as $currency => $unused ) {
						$methods[ $method ]['currencies'][ $currency ] = false;
					}
				}
				continue;
			}
			$methods[ $method ]['enabled'] = isset( $posted[ $method ]['enabled'] ) && 'yes' === $posted[ $method ]['enabled'];
			if ( isset( $config['currencies'] ) && is_array( $config['currencies'] ) ) {
				$submitted_currencies = isset( $posted[ $method ]['currencies'] ) && is_array( $posted[ $method ]['currencies'] ) ? $posted[ $method ]['currencies'] : array();
				foreach ( $config['currencies'] as $currency => $unused ) {
					$methods[ $method ]['currencies'][ $currency ] = isset( $submitted_currencies[ $currency ] ) && 'yes' === $submitted_currencies[ $currency ];
				}
			}
		}
		$fee = isset( $_POST['fee_preference'] ) ? sanitize_key( wp_unslash( $_POST['fee_preference'] ) ) : '';
		if ( ! in_array( $fee, array( 'org_pays', 'customer_pays' ), true ) ) {
			throw new InvalidArgumentException( 'Invalid fee preference.' );
		}
		$api->update_checkout_settings( array( 'enabled_payment_methods' => $methods, 'fee_preference' => $fee ) );
	}

	/** Create and briefly retain a portal URL for display to this administrator. */
	private static function handle_create_portal_session( $api ) {
		$id = self::post_identifier( 'customer_id' );
		$session = $api->create_customer_portal_session( $id );
		$url = isset( $session['url'] ) && is_string( $session['url'] ) ? esc_url_raw( $session['url'] ) : '';
		$parts = wp_parse_url( $url );
		if ( '' === $url || ! is_array( $parts ) || 'https' !== ( isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '' ) || 'portal.bachs.io' !== ( isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '' ) ) {
			throw new Bachs_Api_Exception( __( 'Bachs returned an invalid customer portal URL.', 'bachs-woocommerce' ), 0, 'invalid_response' );
		}
		set_transient( self::portal_transient_key(), $url, 3 * MINUTE_IN_SECONDS );
	}

	/** Display known admin-post notices without exposing provider response bodies. */
	private static function render_notice() {
		if ( ! isset( $_GET['bachs_tool_status'] ) ) {
			return;
		}
		$status = sanitize_key( wp_unslash( $_GET['bachs_tool_status'] ) );
		if ( 'success' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The Bachs action was completed.', 'bachs-woocommerce' ) . '</p></div>';
		} elseif ( 'invalid' === $status ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The submitted values were incomplete or invalid. Review the form and try again.', 'bachs-woocommerce' ) . '</p></div>';
		} elseif ( 'api_error' === $status ) {
			$code = isset( $_GET['bachs_error_code'] ) ? sanitize_key( wp_unslash( $_GET['bachs_error_code'] ) ) : '';
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Bachs could not complete the request. Check the API key scopes and review the WooCommerce Bachs log.', 'bachs-woocommerce' ) . ( '' !== $code ? ' ' . esc_html__( 'Provider error code:', 'bachs-woocommerce' ) . ' <code>' . esc_html( $code ) . '</code>' : '' ) . '</p></div>';
		}
	}

	/** Show a bounded error message when a read request fails. */
	private static function render_api_error( $exception ) {
		$code = $exception->get_error_code();
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Bachs data could not be loaded. Check that the selected key has the required read scope.', 'bachs-woocommerce' ) . ( '' !== $code ? ' ' . esc_html__( 'Provider error code:', 'bachs-woocommerce' ) . ' <code>' . esc_html( $code ) . '</code>' : '' ) . '</p></div>';
	}

	/** Start a protected admin-post form. */
	private static function form_start( $tab, $action ) {
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bachs_admin_tool"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><input type="hidden" name="bachs_tool_action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( 'bachs_admin_tool_action' );
	}

	/** Finish a form with its submit button. */
	private static function form_end( $label, $class = 'button button-secondary' ) {
		echo '<p><button class="' . esc_attr( $class ) . '" type="submit">' . esc_html( $label ) . '</button></p></form>';
	}

	/** Close an inline form that already renders its submit button. */
	private static function form_close() {
		echo '</form>';
	}

	/** Return the first array page collection from a provider response. */
	private static function items( $response, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $response[ $key ] ) && is_array( $response[ $key ] ) ) {
				return array_values( array_filter( $response[ $key ], 'is_array' ) );
			}
		}
		return array();
	}

	/** Safely read a scalar provider field as text. */
	private static function value( $array, $key ) {
		return isset( $array[ $key ] ) && is_scalar( $array[ $key ] ) ? (string) $array[ $key ] : '';
	}

	/** Format a product billing cadence. */
	private static function billing_label( $cycle ) {
		$frequency = isset( $cycle['frequency'] ) ? absint( $cycle['frequency'] ) : 1;
		$interval = isset( $cycle['interval'] ) && is_scalar( $cycle['interval'] ) ? sanitize_key( $cycle['interval'] ) : '';
		return sprintf(
			/* translators: 1: interval frequency, 2: billing interval. */
			__( 'Every %1$d %2$s', 'bachs-woocommerce' ),
			$frequency,
			$interval
		);
	}

	/** Render offset-based pagination for provider lists. */
	private static function render_offset_pagination( $tab, $offset, $limit, $total, $parameter = 'offset' ) {
		if ( $total <= $limit && 0 === $offset ) {
			return;
		}
		$base = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) );
		echo '<div class="tablenav"><div class="tablenav-pages">';
		if ( $offset > 0 ) {
			$previous = add_query_arg( $parameter, max( 0, $offset - $limit ), $base );
			echo '<a class="button" href="' . esc_url( $previous ) . '">' . esc_html__( 'Previous', 'bachs-woocommerce' ) . '</a> ';
		}
		if ( $offset + $limit < $total ) {
			$next = add_query_arg( $parameter, $offset + $limit, $base );
			echo '<a class="button" href="' . esc_url( $next ) . '">' . esc_html__( 'Next', 'bachs-woocommerce' ) . '</a>';
		}
		echo '</div></div>';
	}

	/** Render cursor-based pagination for the Bachs product catalog. */
	private static function render_cursor_pagination( $tab, $pagination ) {
		if ( ! is_array( $pagination ) ) {
			return;
		}
		$base = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) );
		echo '<div class="tablenav"><div class="tablenav-pages">';
		if ( isset( $pagination['prev_cursor'] ) && is_string( $pagination['prev_cursor'] ) && '' !== $pagination['prev_cursor'] ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'cursor', $pagination['prev_cursor'], $base ) ) . '">' . esc_html__( 'Previous', 'bachs-woocommerce' ) . '</a> ';
		}
		if ( isset( $pagination['next_cursor'] ) && is_string( $pagination['next_cursor'] ) && '' !== $pagination['next_cursor'] ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'cursor', $pagination['next_cursor'], $base ) ) . '">' . esc_html__( 'Next', 'bachs-woocommerce' ) . '</a>';
		}
		echo '</div></div>';
	}

	/** Read a bounded scalar text field from an admin-post request. */
	private static function post_text( $key, $max_length ) {
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return '';
		}
		$value = sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
		return strlen( $value ) <= $max_length ? $value : '';
	}

	/** Read and bound a multiline text field. */
	private static function post_textarea( $key, $max_length ) {
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return '';
		}
		$value = sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) );
		return strlen( $value ) <= $max_length ? $value : '';
	}

	/** Read a positive, bounded decimal string without floating-point coercion. */
	private static function post_decimal( $key ) {
		$value = self::post_text( $key, 32 );
		if ( ! preg_match( '/^\d+(?:\.\d{1,6})?$/', $value ) ) {
			return false;
		}
		return false === strpos( $value, '.' ) ? $value : rtrim( rtrim( $value, '0' ), '.' );
	}

	/** Check whether an amount is greater than zero. */
	private static function is_positive_decimal( $value ) {
		return is_string( $value ) && preg_match( '/[1-9]/', $value );
	}

	/** Read an opaque provider ID using a conservative character allow-list. */
	private static function post_identifier( $key ) {
		$value = self::post_text( $key, 128 );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $value ) ) {
			throw new InvalidArgumentException( 'Invalid provider ID.' );
		}
		return $value;
	}

	/** Build an API idempotency key from a stable, validated token. */
	private static function stable_operation_key( $prefix, $reference ) {
		return 'bachs-wc-' . $prefix . '-' . substr( hash( 'sha256', Bachs_Settings::environment() . ':' . $reference ), 0, 48 );
	}

	/** Redirect back to the selected tool with a safe result code. */
	private static function redirect( $tab, $status, $error_code = '', $extra = array() ) {
		$allowed = array( 'products', 'subscriptions', 'disputes', 'payouts', 'virtual', 'checkout', 'portal' );
		if ( ! in_array( $tab, $allowed, true ) ) {
			$tab = 'products';
		}
		$args = array( 'page' => self::PAGE_SLUG, 'tab' => $tab, 'bachs_tool_status' => $status );
		if ( 'disputes' === $tab && isset( $extra['dispute_id'] ) && preg_match( '/^[A-Za-z0-9_-]{1,128}$/', (string) $extra['dispute_id'] ) ) {
			$args['dispute_id'] = (string) $extra['dispute_id'];
		}
		if ( '' !== $error_code ) {
			$args['bachs_error_code'] = sanitize_key( $error_code );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Use a user-specific, short-lived transient for a customer portal URL. */
	private static function portal_transient_key() {
		return 'bachs_wc_portal_' . absint( get_current_user_id() );
	}

	/** Build a transient key that cannot be controlled outside the dispute ID allow-list. */
	private static function dispute_upload_transient_key( $dispute_id ) {
		return 'bachs_wc_upload_' . absint( get_current_user_id() ) . '_' . substr( hash( 'sha256', (string) $dispute_id ), 0, 16 );
	}

	/** Use separate short-lived operation slots for the current admin and environment. */
	private static function product_operation_transient_key() {
		return 'bachs_wc_product_op_' . absint( get_current_user_id() ) . '_' . Bachs_Settings::environment();
	}

	/** Save the completed product reference locally and remove its retry payload. */
	private static function complete_product_operation( $product, $reference ) {
		$id = isset( $product['id'] ) && is_scalar( $product['id'] ) ? sanitize_text_field( (string) $product['id'] ) : '';
		if ( '' === $id ) {
			throw new Bachs_Api_Exception( __( 'Bachs did not return a product identifier.', 'bachs-woocommerce' ), 0, 'invalid_response' );
		}
		if ( '' !== $id ) {
			$state = get_option( self::STATE_OPTION, array() );
			if ( ! is_array( $state ) ) {
				$state = array();
			}
			if ( ! isset( $state['product_references'] ) || ! is_array( $state['product_references'] ) ) {
				$state['product_references'] = array();
			}
			if ( ! isset( $state['product_references'][ Bachs_Settings::environment() ] ) || ! is_array( $state['product_references'][ Bachs_Settings::environment() ] ) ) {
				$state['product_references'][ Bachs_Settings::environment() ] = array();
			}
			$state['product_references'][ Bachs_Settings::environment() ][ $reference ] = $id;
			update_option( self::STATE_OPTION, $state, false );
		}
		delete_transient( self::product_operation_transient_key() );
	}

	/** Use separate short-lived payout retry slots for the current admin and environment. */
	private static function payout_operation_transient_key() {
		return 'bachs_wc_payout_op_' . absint( get_current_user_id() ) . '_' . Bachs_Settings::environment();
	}

	/** Preserve dispute context when an evidence action redirects back. */
	private static function posted_dispute_return_args( $tab ) {
		if ( 'disputes' !== $tab || ! isset( $_POST['dispute_id'] ) ) {
			return array();
		}
		$id = sanitize_text_field( wp_unslash( $_POST['dispute_id'] ) );
		return preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $id ) ? array( 'dispute_id' => $id ) : array();
	}
}
