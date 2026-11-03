# Changelog

## 0.2.0

- Added the **WooCommerce → Bachs tools** area for Bachs products, subscription administration, disputes, balances and payouts, virtual accounts, checkout settings, and customer portal sessions.
- Added validated dispute evidence file uploads and explicit dispute submission.
- Added manager-confirmed payout creation with reference lookup and idempotent retry handling.
- Added per-currency automatic payout schedule review and updates with an explicit confirmation step.
- Added crypto refund destination collection and secure retry checks that preserve the original request payload.
- Expanded installation, API permission, security, and feature-scope documentation.

## 0.1.0

- Added the native WooCommerce Bachs payment gateway.
- Added hosted redirect and Bachs overlay checkout.
- Added server-side checkout session creation with idempotency.
- Added timestamped webhook signature verification, deduplication, and order updates.
- Added full and partial refund requests with WooCommerce refund records created only after provider confirmation.
- Added manual payment reconciliation, Checkout Blocks support, and HPOS compatibility declarations.
