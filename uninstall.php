<?php
/**
 * Remove gateway configuration on explicit uninstall. Order metadata is kept
 * with WooCommerce orders for financial history and reconciliation purposes.
 *
 * @package Bachs_WooCommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'woocommerce_bachs_settings' );
