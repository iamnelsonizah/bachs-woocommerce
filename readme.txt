=== Bachs Payments for WooCommerce ===
Contributors: bachs
Requires at least: 6.2
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept WooCommerce payments through Bachs hosted checkout with signed webhook confirmation.

== Description ==

Bachs Payments for WooCommerce adds a native WooCommerce payment method for Bachs. It creates checkout sessions server-side, sends customers to Bachs hosted checkout or an optional overlay, and updates orders after a signed Bachs webhook or authenticated reconciliation confirms payment.

The plugin supports one-time payments, asynchronous refund requests, Sandbox/Live configuration, manual payment reconciliation, WooCommerce Checkout Blocks, and HPOS. A native WooCommerce refund record is created only after Bachs confirms the refund succeeded.

The first release is for one-time orders. It does not include subscription/customer portal workflows, dispute evidence, balances and payouts, connected accounts/transfers, product or customer synchronization, or virtual-account administration. See `README.md` for the current feature scope and roadmap.

== Installation ==

1. Use the release ZIP `bachs-woocommerce-0.1.0.zip` from the GitHub release/repository. In WordPress, open Plugins > Add Plugin > Upload Plugin and upload the ZIP. Alternatively, extract the `bachs-woocommerce` folder into `wp-content/plugins/`.
2. Activate Bachs Payments for WooCommerce.
3. Open WooCommerce > Settings > Payments > Bachs.
4. Configure a Sandbox API key and Sandbox webhook signing secret.
5. Register the webhook URL shown in the gateway settings in the Bachs Developer Portal.
6. Subscribe to `collection.succeeded`, `collection.failed`, `collection.underpaid`, `checkout.expired`, `refund.paid`, and `refund.failed`.
7. Complete a Sandbox order and confirm its status changes after the signed webhook arrives. Customer return redirects do not confirm payment.

See `README.md` for full setup, security, refund, and troubleshooting instructions.

== External services ==

This plugin connects to Bachs (`https://bachs.io/`) to create checkout sessions, retrieve payments, and request refunds. Bachs also sends signed payment and refund webhooks to the store. Checkout is hosted by Bachs; this plugin does not collect card numbers or CVV values.

Data sent to Bachs can include the order amount and currency, an order reference and ID, return URLs, and available customer name, email, and phone. The plugin stores Bachs IDs and payment state in WooCommerce order metadata. The provider's terms and privacy policy apply to its processing.

== Changelog ==

= 0.1.0 =
* Initial modular gateway with hosted and overlay checkout, webhook verification, refunds, reconciliation, Checkout Blocks, and HPOS declarations.
