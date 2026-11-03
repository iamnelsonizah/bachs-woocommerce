<?php
/**
 * Asynchronous refund controls for Bachs orders in both WooCommerce order UIs.
 *
 * A WooCommerce refund record is created only when Bachs confirms the refund,
 * avoiding an order that appears refunded while the provider request is pending.
 *
 * @package Bachs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bachs_Admin_Refund_Panel {
	/**
	 * Register the classic and HPOS order meta boxes and admin form handler.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'admin_post_bachs_request_refund', array( __CLASS__, 'handle_request' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_notice' ) );
	}

	/**
	 * Register order panels on the legacy post screen and the HPOS order screen.
	 *
	 * @return void
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'bachs_wc_payment_panel',
			__( 'Bachs payment', 'bachs-woocommerce' ),
			array( __CLASS__, 'render' ),
			'shop_order',
			'side',
			'high'
		);
		add_meta_box(
			'bachs_wc_payment_panel',
			__( 'Bachs payment', 'bachs-woocommerce' ),
			array( __CLASS__, 'render' ),
			'woocommerce_page_wc-orders',
			'side',
			'high'
		);
	}

	/**
	 * Render payment identifiers and the asynchronous refund request form.
	 *
	 * @return void
	 */
	public static function render() {
		$order_id = 0;
		if ( isset( $_GET['id'] ) ) {
			$order_id = absint( wp_unslash( $_GET['id'] ) );
		} elseif ( isset( $_GET['post'] ) ) {
			$order_id = absint( wp_unslash( $_GET['post'] ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'bachs' !== $order->get_payment_method() || ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}

		$charge_id = (string) $order->get_meta( Bachs_Order_Service::META_CHARGE_ID, true );
		$checkout_id = (string) $order->get_meta( Bachs_Order_Service::META_CHECKOUT_ID, true );
		$refund_status = (string) $order->get_meta( Bachs_Order_Service::META_REFUND_STATUS, true );

		if ( '' !== $charge_id ) {
			echo '<p><strong>' . esc_html__( 'Bachs charge:', 'bachs-woocommerce' ) . '</strong><br><code>' . esc_html( $charge_id ) . '</code></p>';
		} elseif ( '' !== $checkout_id ) {
			echo '<p><strong>' . esc_html__( 'Bachs checkout:', 'bachs-woocommerce' ) . '</strong><br><code>' . esc_html( $checkout_id ) . '</code></p>';
		} else {
			echo '<p>' . esc_html__( 'Bachs checkout has not been created for this order.', 'bachs-woocommerce' ) . '</p>';
		}

		if ( '' !== $refund_status ) {
			echo '<p><strong>' . esc_html__( 'Bachs refund status:', 'bachs-woocommerce' ) . '</strong> ' . esc_html( $refund_status ) . '</p>';
		}

		if ( ! $order->is_paid() || '' === $charge_id || $order->get_total_refunded() > 0 || in_array( $refund_status, array( 'recording', 'processing', 'success' ), true ) ) {
			return;
		}

		$button = 'requesting' === $refund_status || 'failed' === $refund_status
			? __( 'Retry Bachs refund request', 'bachs-woocommerce' )
			: __( 'Request Bachs refund', 'bachs-woocommerce' );

		echo '<hr><p>' . esc_html__( 'Bachs completes refunds asynchronously. WooCommerce records a refund only after Bachs confirms it.', 'bachs-woocommerce' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="bachs_request_refund">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
		wp_nonce_field( 'bachs_request_refund_' . $order->get_id() );
		if ( 'requesting' === $refund_status ) {
			echo '<p>' . esc_html__( 'This checks Bachs for the original request first. If no refund exists, it retries with the saved amount and reason.', 'bachs-woocommerce' ) . '</p>';
		} else {
			echo '<p><label for="bachs-refund-amount"><strong>' . esc_html__( 'Amount', 'bachs-woocommerce' ) . '</strong></label><br>';
			echo '<input type="text" class="widefat" id="bachs-refund-amount" name="amount" inputmode="decimal" placeholder="' . esc_attr__( 'Leave blank for a full refund', 'bachs-woocommerce' ) . '"></p>';
			echo '<p><label for="bachs-refund-reason"><strong>' . esc_html__( 'Reason', 'bachs-woocommerce' ) . '</strong></label><br>';
			echo '<textarea class="widefat" rows="3" id="bachs-refund-reason" name="reason"></textarea></p>';
		}
		submit_button( $button, 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Validate the admin request and start the provider refund operation.
	 *
	 * @return void
	 */
	public static function handle_request() {
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		if ( ! $order_id || ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_die( esc_html__( 'You are not allowed to refund this order.', 'bachs-woocommerce' ) );
		}
		check_admin_referer( 'bachs_request_refund_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'bachs' !== $order->get_payment_method() ) {
			wp_die( esc_html__( 'This is not a Bachs order.', 'bachs-woocommerce' ) );
		}

		$amount = isset( $_POST['amount'] ) ? trim( (string) wp_unslash( $_POST['amount'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$result = ( new WC_Gateway_Bachs() )->request_bachs_refund( $order_id, $amount, $reason );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note( $result->get_error_message() );
			$state = 'error';
		} else {
			$state = 'requested';
		}

		wp_safe_redirect( add_query_arg( 'bachs_refund_result', $state, self::order_edit_url( $order ) ) );
		exit;
	}

	/**
	 * Show a short result after redirecting back to the order.
	 *
	 * @return void
	 */
	public static function show_notice() {
		if ( ! isset( $_GET['bachs_refund_result'] ) ) {
			return;
		}

		$result = sanitize_key( wp_unslash( $_GET['bachs_refund_result'] ) );
		if ( 'requested' === $result ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Bachs accepted the refund request. Its final result will arrive by webhook or can be checked with order reconciliation.', 'bachs-woocommerce' ) . '</p></div>';
		} elseif ( 'error' === $result ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The Bachs refund request was not confirmed. See the order notes for details.', 'bachs-woocommerce' ) . '</p></div>';
		}
	}

	/**
	 * Build an edit URL that works with both legacy storage and HPOS.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	private static function order_edit_url( $order ) {
		$order_util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
		if ( class_exists( $order_util ) && method_exists( $order_util, 'custom_orders_table_usage_is_enabled' ) && $order_util::custom_orders_table_usage_is_enabled() ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . absint( $order->get_id() ) );
		}

		$url = get_edit_post_link( $order->get_id(), 'url' );
		return $url ? $url : admin_url( 'post.php?post=' . absint( $order->get_id() ) . '&action=edit' );
	}
}
