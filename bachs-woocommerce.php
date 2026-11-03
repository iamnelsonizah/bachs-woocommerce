<?php
/**
 * Plugin Name: Bachs Payments for WooCommerce
 * Plugin URI:  https://bachs.io/
 * Description: Accept WooCommerce payments through Bachs hosted or overlay checkout.
 * Version:     0.3.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * Text Domain: bachs-woocommerce
 * Domain Path: /languages
 *
 * @package Bachs_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BACHS_WC_VERSION', '0.3.0' );
define( 'BACHS_WC_FILE', __FILE__ );
define( 'BACHS_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'BACHS_WC_URL', plugin_dir_url( __FILE__ ) );

require_once BACHS_WC_PATH . 'includes/class-bachs-settings.php';
require_once BACHS_WC_PATH . 'includes/class-bachs-api-exception.php';
require_once BACHS_WC_PATH . 'includes/class-bachs-api-client.php';
require_once BACHS_WC_PATH . 'includes/class-bachs-webhook-verifier.php';
require_once BACHS_WC_PATH . 'includes/class-bachs-order-service.php';
require_once BACHS_WC_PATH . 'includes/class-bachs-webhook-controller.php';
require_once BACHS_WC_PATH . 'includes/class-bachs-blocks-support.php';
require_once BACHS_WC_PATH . 'includes/class-bachs-admin-order-actions.php';
require_once BACHS_WC_PATH . 'includes/class-bachs-admin-refund-panel.php';
require_once BACHS_WC_PATH . 'includes/class-bachs-admin-tools.php';

/**
 * Register the gateway only after WooCommerce has loaded.
 *
 * @return void
 */
function bachs_wc_initialize() {
	load_plugin_textdomain( 'bachs-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'bachs_wc_missing_woocommerce_notice' );
		return;
	}

	require_once BACHS_WC_PATH . 'includes/class-wc-gateway-bachs.php';
	add_filter( 'woocommerce_payment_gateways', 'bachs_wc_register_gateway' );
	Bachs_Webhook_Controller::register_hooks();
	Bachs_Blocks_Support::register_hooks();
	Bachs_Admin_Order_Actions::register_hooks();
	Bachs_Admin_Refund_Panel::register_hooks();
	Bachs_Admin_Tools::register_hooks();
}
add_action( 'plugins_loaded', 'bachs_wc_initialize', 11 );

/**
 * Add the Bachs gateway to WooCommerce's gateway registry.
 *
 * @param array $gateways Registered gateway class names.
 * @return array
 */
function bachs_wc_register_gateway( $gateways ) {
	$gateways[] = 'WC_Gateway_Bachs';
	return $gateways;
}

/**
 * Tell WooCommerce this plugin uses order CRUD APIs and supports HPOS.
 *
 * @return void
 */
function bachs_wc_declare_compatibility() {
	if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BACHS_WC_FILE, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BACHS_WC_FILE, true );
}
add_action( 'before_woocommerce_init', 'bachs_wc_declare_compatibility' );

/**
 * Show a helpful admin notice when WooCommerce is not active.
 *
 * @return void
 */
function bachs_wc_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>' . esc_html__( 'Bachs Payments for WooCommerce requires WooCommerce to be installed and active.', 'bachs-woocommerce' ) . '</p></div>';
}
