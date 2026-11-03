=== Bachs Payments for WooCommerce ===
Contributors: bachs
Requires at least: 6.2
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept WooCommerce payments through Bachs hosted checkout with signed webhook confirmation.

== Description ==

Bachs Payments for WooCommerce adds a native WooCommerce payment method for Bachs. It creates checkout sessions server-side, sends customers to Bachs hosted checkout or an optional overlay, and updates orders after a signed Bachs webhook or authenticated reconciliation confirms payment.

The plugin supports one-time payments, asynchronous refund requests (including crypto refund addresses), Sandbox/Live configuration, manual payment reconciliation, WooCommerce Checkout Blocks, and HPOS. The WooCommerce admin also includes Bachs tools for products, Bachs subscriptions, disputes, balances and payouts, virtual accounts, account checkout settings, and customer portal sessions. A native WooCommerce refund record is created only after Bachs confirms the refund succeeded.

The gateway creates one-time WooCommerce orders. The Bachs admin subscription list does not create WooCommerce subscription orders or manage product access. Product creation adds a product to Bachs and does not synchronize WooCommerce products or change checkout pricing. Connected-account marketplace workflows, payout destination creation or editing, cross-currency payout quotes, and per-customer virtual-account reconciliation are not included. See `README.md` for the current scope and roadmap.

== Installation ==

1. Clone `https://github.com/iamnelsonizah/bachs-woocommerce.git` into `wp-content/plugins/bachs-woocommerce/`, or download the GitHub source ZIP and rename the extracted folder to `bachs-woocommerce`.
2. To create a ZIP for WordPress's Upload Plugin screen, run `git archive --format=zip --prefix=bachs-woocommerce/ HEAD --output=bachs-woocommerce.zip` from the checked-out repository and upload that file under Plugins > Add Plugin > Upload Plugin.
3. Activate Bachs Payments for WooCommerce.
4. Open WooCommerce > Settings > Payments > Bachs.
5. Configure a Sandbox API key and Sandbox webhook signing secret.
6. Register the webhook URL shown in the gateway settings in the Bachs Developer Portal.
7. Subscribe to `collection.succeeded`, `collection.failed`, `collection.underpaid`, `checkout.expired`, `refund.paid`, and `refund.failed`.
8. Complete a Sandbox order and confirm its status changes after the signed webhook arrives. Customer return redirects do not confirm payment.

See `README.md` for full installation, API permission, merchant tools, security, refund, and troubleshooting instructions. This PHP plugin is installed as a WordPress plugin; the separate Bachs Node.js and Python SDKs do not install this plugin.

== External services ==

This plugin connects to Bachs (`https://bachs.io/`) to create checkout sessions, retrieve payments, request refunds, manage selected account resources in WooCommerce admin, and receive signed payment and refund webhooks. Checkout is hosted by Bachs; this plugin does not collect card numbers or CVV values.

Data sent to Bachs can include the order amount and currency, an order reference and ID, return URLs, and available customer name, email, and phone. The plugin stores Bachs IDs and payment state in WooCommerce order metadata. The provider's terms and privacy policy apply to its processing.

== Changelog ==

= 0.3.0 =
* Add a setup overview to Bachs tools with environment readiness checks and WooCommerce Analytics guidance.
* Group gateway settings into customer checkout, Sandbox, Live, and webhook sections.
* Add a clear recovery action when an overlay checkout session is missing or expired.

= 0.2.0 =
* Add Bachs admin tools for product creation, subscription cancellation, dispute evidence, balances and payouts, virtual accounts, checkout settings, and customer portal sessions.
* Add review and management of automatic payout schedules by balance currency.
* Support crypto refund wallet addresses and clear them after a confirmed refund.
* Preserve the original refund payload and idempotency key during uncertain refund retries.

= 0.1.0 =
* Initial modular gateway with hosted and overlay checkout, webhook verification, refunds, reconciliation, Checkout Blocks, and HPOS declarations.
