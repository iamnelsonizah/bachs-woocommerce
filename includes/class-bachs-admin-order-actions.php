<?php
/**
 * Merchant initiated payment reconciliation actions on WooCommerce orders.
 *
 * @package Bachs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bachs_Admin_Order_Actions {
	/**
	 * Register the order action and its handler.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'add_action' ), 10, 2 );
		add_action( 'woocommerce_order_action_bachs_reconcile_payment', array( __CLASS__, 'reconcile' ) );
	}

	/**
	 * Add a manual reconciliation item only to Bachs orders.
	 *
	 * @param array $actions Existing order actions.
	 * @param WC_Order|null $order Current order when supplied by WooCommerce.
	 * @return array
	 */
	public static function add_action( $actions, $order = null ) {
		if ( $order instanceof WC_Order && 'bachs' === $order->get_payment_method() ) {
			$actions['bachs_reconcile_payment'] = __( 'Reconcile payment with Bachs', 'bachs-woocommerce' );
		}
		return $actions;
	}

	/**
	 * Retrieve provider state and apply it through the same amount checks as webhooks.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	public static function reconcile( $order ) {
		if ( ! $order instanceof WC_Order || 'bachs' !== $order->get_payment_method() ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}

		try {
			Bachs_Order_Service::reconcile( $order, new Bachs_Api_Client() );
		} catch ( Bachs_Api_Exception $exception ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: provider error code. */
					__( 'Bachs payment reconciliation could not be completed. Error code: %s.', 'bachs-woocommerce' ),
					'' !== $exception->get_error_code() ? $exception->get_error_code() : 'request_failed'
				)
			);
		} catch ( Throwable $exception ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Order reconciliation failed (' . get_class( $exception ) . ').', array( 'source' => 'bachs-woocommerce' ) );
			}
			$order->add_order_note( __( 'Bachs reconciliation returned a state WooCommerce could not apply. Review the payment and refund state in Bachs.', 'bachs-woocommerce' ) );
		}
	}
}
