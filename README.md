# Bachs Payments for WooCommerce

Bachs Payments for WooCommerce adds Bachs hosted checkout to a WooCommerce store. The plugin creates checkout sessions on the server, sends customers to Bachs to enter payment details, and updates orders after signed Bachs notifications or authenticated reconciliation.

> **Current source version:** 0.3.0. This integration is intended to be evaluated in Sandbox first. Verify checkout, webhooks, refunds, and any enabled merchant tools against your Bachs account before using them in Live.

## Contents

- [What is included](#what-is-included)
- [Requirements](#requirements)
- [Install the plugin](#install-the-plugin)
- [Configure Sandbox](#configure-sandbox)
- [Configure API keys and webhook secrets](#configure-api-keys-and-webhook-secrets)
- [Bachs merchant tools](#bachs-merchant-tools)
- [How checkout works](#how-checkout-works)
- [Refund an order](#refund-an-order)
- [Reconcile an order](#reconcile-an-order)
- [Security and customer data](#security-and-customer-data)
- [Known limitations and roadmap](#known-limitations-and-roadmap)
- [Node.js and Python packages](#nodejs-and-python-packages)
- [Troubleshooting](#troubleshooting)
- [Developer notes](#developer-notes)
- [Contributing and security reports](#contributing-and-security-reports)
- [License](#license)

## What is included

- One-time WooCommerce product orders priced from the server-side order total.
- Bachs hosted checkout with the payment methods enabled for the merchant's Bachs account.
- Hosted redirect and optional Bachs overlay checkout.
- Separate Sandbox and Live API keys and webhook signing secrets.
- Signed webhook handling for successful, failed, underpaid, expired, and refund events.
- Full or partial refund requests from the WooCommerce order screen.
- Manual payment and refund reconciliation against the Bachs API.
- A Bachs tools overview with environment readiness checks and direct links to setup and WooCommerce Analytics.
- Classic checkout, Cart and Checkout Blocks registration, and HPOS compatibility declarations.

The gateway provides one-time WooCommerce payments plus a manager-only screen for selected Bachs merchant resources. It does not reproduce every Bachs dashboard or API feature. See [Known limitations and roadmap](#known-limitations-and-roadmap) for workflows that need a separate integration.

## Requirements

- WordPress 6.2 or later.
- WooCommerce 8.0 or later.
- PHP 7.4 or later.
- A Bachs merchant account with Sandbox access.
- A public HTTPS store URL for Live payments and webhook delivery.

## Install the plugin

### Install from GitHub source

Clone the public repository directly into the WordPress plugins directory:

```bash
git clone https://github.com/iamnelsonizah/bachs-woocommerce.git wp-content/plugins/bachs-woocommerce
```

Or download the repository ZIP from **Code → Download ZIP**, extract it, and rename the extracted `bachs-woocommerce-main` folder to `bachs-woocommerce` before uploading it to `wp-content/plugins/`. In WordPress, activate **Bachs Payments for WooCommerce** under **Plugins → Installed Plugins**.

To install through **Plugins → Add New Plugin → Upload Plugin**, first package the checked-out source revision as a plugin ZIP:

```bash
git archive --format=zip --prefix=bachs-woocommerce/ HEAD --output=bachs-woocommerce.zip
```

Upload `bachs-woocommerce.zip` in WordPress. The archive must contain a top-level `bachs-woocommerce/` directory with `bachs-woocommerce.php` directly inside it. When a versioned ZIP is attached to a GitHub Release, download that asset and upload it the same way.

After activation, confirm WooCommerce is active. Gateway settings are under **WooCommerce → Settings → Payments → Bachs**; merchant tools are under **WooCommerce → Bachs tools**.

The Bachs tools screen opens on an **Overview** with a short Sandbox-first checklist and checks for the active environment, API key, webhook secret, and HTTPS requirement. Use **WooCommerce → Analytics** for orders, sales, and product reporting; the Bachs tools screen manages Bachs account resources such as disputes and payouts.

### Install manually

Copy the `bachs-woocommerce` plugin folder into:

```text
wp-content/plugins/bachs-woocommerce/
```

The main plugin file should be at `wp-content/plugins/bachs-woocommerce/bachs-woocommerce.php`. Then activate **Bachs Payments for WooCommerce** from **Plugins** in WordPress.

### Install with WP-CLI

```bash
wp plugin install /path/to/bachs-woocommerce.zip --activate
```

For development, clone the source into the plugins directory:

```bash
git clone https://github.com/iamnelsonizah/bachs-woocommerce.git wp-content/plugins/bachs-woocommerce
```

## Configure Sandbox

Complete Sandbox setup and a test order before switching the store to Live.

1. In the Bachs Developer Portal, create a restricted **Sandbox** API key with the scopes listed in [API key scopes](#api-key-scopes).
2. In WordPress, open **WooCommerce → Settings → Payments → Bachs**.
3. Enable Bachs and leave **Environment** set to **Sandbox**.
4. Choose **Hosted checkout redirect** or **Bachs overlay**. Redirect is the default and simplest option.
5. Enter the Sandbox API key and the Sandbox webhook signing secret, then save the settings.
6. Copy the **Webhook endpoint** shown on the settings page. In the Bachs Developer Portal, add that URL as a Sandbox webhook destination.
7. Subscribe the destination to the events listed under [Webhook events](#webhook-events). Use the signing secret from that Sandbox destination in the WooCommerce settings.
8. Place a Sandbox order from the storefront and complete payment on Bachs checkout.
9. Confirm the order is marked paid after the signed webhook arrives. The customer's browser returning to the store does not confirm payment.

The webhook URL follows this pattern, but copy the exact URL from your store's settings:

```text
https://your-store.example/wp-json/bachs-wc/v1/webhook
```

WordPress and Bachs must both be able to reach this URL. Local development sites need a public HTTPS tunnel. Security plugins, firewalls, or caching layers must allow requests to this WordPress REST route.

### Switch to Live

After Sandbox checkout, order updates, and refunds have been validated:

1. Create a separate restricted **Live** API key in Bachs.
2. Register a separate Live webhook destination using the same endpoint URL, and copy its Live signing secret.
3. In WooCommerce, enter the Live API key and Live webhook secret, then select **Live** and save.
4. Confirm the store is served over HTTPS and run a controlled Live transaction before broad launch.

Sandbox and Live credentials and webhook destinations are separate. Keep the selected environment and credentials paired.

## Configure API keys and webhook secrets

### API key scopes

Create restricted keys with only the scopes this integration uses:

| Scope | Used for |
| --- | --- |
| `payments:write` | Create Bachs checkout sessions. |
| `payments:read` | Reconcile payment state and check refund eligibility. |
| `refunds:write` | Request full or partial refunds. |
| `refunds:read` | Reconcile asynchronous refund state. |
| `products:read` | List Bachs catalog products in the merchant tools. |
| `products:write` | Create Bachs catalog products. |
| `customers:write` | Create short-lived customer portal sessions for known Bachs customer IDs. |
| `disputes:read` | List and retrieve disputes. |
| `disputes:write` | Upload evidence, update it, and submit a dispute response. |
| `balance:read` | Read available and pending balances. |
| `balance:write` | Update automatic payout schedules. |
| `payouts:read` | List payout destinations and payout history. |
| `payouts:write` | Create a confirmed payout. |
| `virtual_accounts:read` | Retrieve the stored merchant virtual account. |
| `virtual_accounts:write` | Create or retrieve the merchant virtual account. |

The plugin does not require `webhooks:write`; create webhook destinations in the Bachs Developer Portal. The current Bachs permissions reference does not list scope names for its subscription or account checkout-settings endpoints. Check the permissions shown in your Bachs account or ask Bachs support before enabling those tabs. See the [Bachs API permissions reference](https://docs.bachs.io/api-reference/permissions).

### Bachs merchant tools

The **WooCommerce → Bachs tools** page is available to users with the `manage_woocommerce` capability. It uses the API key for the currently selected Sandbox or Live environment. Give each key only the permissions needed for the tabs the store will use.

The **Overview** tab checks that the payment method, active environment key, webhook secret, and Live HTTPS requirement are ready. The gateway settings page groups customer checkout choices and credentials by environment. If an overlay checkout expires, the customer can return directly to the WooCommerce payment page and try again; WooCommerce creates a fresh Bachs session when payment is resubmitted.

| Tab | What it does | Important behavior |
| --- | --- | --- |
| Products | List and create fixed-price or recurring Bachs catalog products. | Bachs catalog records are separate from WooCommerce products and prices. |
| Subscriptions | List Bachs subscriptions and cancel now or at period end. | This is account administration; it does not create WooCommerce subscription orders or change customer access. |
| Disputes | Review disputes, upload PDF/JPEG/PNG/GIF evidence (up to 10 MB), save evidence, and submit a response. | Submission is explicit and may be final. Evidence files are sent directly to Bachs, not stored in the WordPress media library. |
| Balances and payouts | View balances, payout destinations and payout history; submit or reconcile a payout; view or update automatic payout schedules. | Payouts move merchant funds. The form requires confirmation, a usable destination, and a unique reference. Schedules automatically send eligible settled collections to the default destination. Fees are additional. |
| Virtual account | Create or retrieve the merchant's fixed NGN virtual account. | The account is shared at merchant level, not created per WooCommerce customer. Availability depends on the Bachs account. |
| Checkout settings | View and update enabled Bachs payment methods, currencies, and fee preference. | These settings apply to the Bachs account and may affect other integrations. |
| Customer portal | Create a short-lived billing portal link for a Bachs customer ID. | Enter the ID from Bachs; the plugin does not yet map WooCommerce customers to Bachs customers. Share the generated link only with that customer. |

The payout retry control retains the original payload and idempotency key after an uncertain response. Check Bachs payout history by reference before retrying. Crypto refund retries likewise require the saved address and original request details; reconcile an uncertain refund with Bachs before changing any of them.

### Store credentials in `wp-config.php`

For production, use `wp-config.php` or your deployment secret manager instead of storing credentials in the WordPress settings database. Add constants before the `/* That's all, stop editing! */` line:

```php
define( 'BACHS_WC_ENVIRONMENT', 'sandbox' ); // Set to 'live' when launching.
define( 'BACHS_WC_SANDBOX_SECRET_KEY', 'sk_sandbox_replace_me' );
define( 'BACHS_WC_LIVE_SECRET_KEY', 'sk_live_replace_me' );
define( 'BACHS_WC_SANDBOX_WEBHOOK_SECRET', 'replace_with_sandbox_signing_secret' );
define( 'BACHS_WC_LIVE_WEBHOOK_SECRET', 'replace_with_live_signing_secret' );
```

Do not leave placeholder values in place. When these constants are defined, they take precedence over the corresponding dashboard fields. `BACHS_WC_ENVIRONMENT` also takes precedence over the environment selector.

During webhook-secret rotation, configure the new secret as current and the old secret as previous. The plugin accepts both during the overlap period. Remove the previous secret when Bachs has stopped signing with it:

```php
define( 'BACHS_WC_SANDBOX_WEBHOOK_SECRET_PREVIOUS', 'old_sandbox_signing_secret' );
define( 'BACHS_WC_LIVE_WEBHOOK_SECRET_PREVIOUS', 'old_live_signing_secret' );
```

Never commit real keys or signing secrets to Git, publish them in an issue, or expose them in browser JavaScript. The settings page keeps saved secret values blank and does not render them back into HTML.

## How checkout works

1. WooCommerce calculates the order total and currency.
2. The plugin creates a Bachs checkout session on the server and stores its identifiers against the order.
3. The customer is redirected to Bachs hosted checkout or opens the Bachs overlay.
4. Bachs sends a signed webhook to the store.
5. The plugin verifies the signature and timestamp, matches the event to the order, and checks the reported status, amount, and currency.
6. WooCommerce marks the order paid only after those checks pass.

The plugin sends the order amount and currency, a generated order reference, the WooCommerce order ID in metadata, success and cancel URLs, and available billing name, email, and phone. Payment details are entered on Bachs checkout; the plugin does not collect card numbers, CVV values, or PINs.

The overlay's browser events provide checkout feedback only. A browser redirect, query parameter, or JavaScript event does not mark an order paid. If Bachs reports an underpayment, an accepted overpayment, or a mismatched amount/currency, the order is put on hold for review.

## Webhook events

Subscribe the endpoint to these event types in the Bachs Developer Portal:

- `collection.succeeded`
- `collection.failed`
- `collection.underpaid`
- `checkout.expired`
- `refund.paid`
- `refund.failed`

The plugin verifies Bachs's `X-Bachs-Signature-V2` header against the raw request body and timestamp. It supports multiple `v1` signatures during secret rotation and the legacy signature headers. Timestamps more than five minutes from the store clock are rejected, so keep the server clock synchronized.

Matched events update the relevant WooCommerce order. Unknown orders are acknowledged without changing an order. Processing errors return a `5xx` response so Bachs can retry. Duplicate event IDs are ignored. For a missed event, resend it from the Bachs Developer Portal or use **Order actions → Reconcile payment with Bachs** on the order.

## Refund an order

1. Open the paid order in WooCommerce.
2. Find the **Bachs payment** panel.
3. Enter an amount for a partial refund, or leave the amount blank for a full refund. Add a reason if useful.
4. Select **Request Bachs refund** and review the order notes and Bachs refund status.

Bachs refunds are asynchronous. The plugin creates the WooCommerce refund record only after Bachs confirms that funds reached the customer. If a request times out, retrying first checks Bachs by charge. A failed refund can be retried; a request still processing cannot.

**Review the refund limit before submitting:** Bachs permits one refund that has not failed per charge. A partial refund uses that operation, so the remaining balance cannot later be refunded in a second operation. Refund availability depends on the payment method. Crypto refunds require a destination wallet address; confirm the address and network with the customer before submitting it. The plugin stores the address in protected WooCommerce order metadata while the refund is pending and removes it after a confirmed refund.

## Reconcile an order

If an order remains pending or its refund status is unclear:

1. Open the WooCommerce order.
2. Choose **Reconcile payment with Bachs** from **Order actions** and apply the action.
3. Review the order notes and status.

Reconciliation retrieves the payment using the stored Bachs charge or checkout ID. It also checks pending refunds and completed refunds reported by Bachs. API keys need the corresponding `payments:read` and `refunds:read` scopes.

## Security and customer data

- API calls use fixed Bachs hosts for each environment, WordPress's HTTP API, TLS verification, disabled redirects, and a bounded response size.
- Checkout and refund requests use persisted idempotency keys. Refund retries check Bachs state before repeating an uncertain request. See [Bachs idempotency guidance](https://docs.bachs.io/guides/idempotency).
- Webhook signatures are checked before order changes; payment amount, currency, status, and order identifiers are verified.
- Secret keys, webhook signatures, customer details, and full request/response bodies are not written to plugin logs.
- Order metadata stores Bachs checkout/payment/refund identifiers, the checkout URL, refund state, and a bounded list of processed event IDs.
- Uninstalling the plugin removes its gateway settings. Payment and refund metadata remains attached to WooCommerce orders for financial history.

The integration sends checkout and billing data to Bachs and receives signed payment/refund notifications. Review the [Bachs Privacy Policy](https://bachs.io/privacy), [Bachs terms](https://bachs.io/buyer-terms), and [Bachs developer documentation](https://docs.bachs.io/) for provider-side handling.

## Known limitations and roadmap

This plugin covers the one-time WooCommerce gateway and the merchant tools listed above. It does not yet implement these broader workflows:

- WooCommerce Subscriptions integration: subscription checkout, renewal orders, token/payment-method lifecycle, cancellation synchronization, retries, and access/entitlement updates. The admin tab only lists and cancels subscriptions created in Bachs.
- WooCommerce customer and product synchronization with Bachs. Product creation in the admin tab is a Bachs-side catalog action only.
- Automatic association of a WooCommerce customer with a Bachs customer or subscription, including automatic customer portal links.
- Payout destination creation or editing and cross-currency payout quotes for manual payouts.
- Connected accounts, persons, transfers, and platform fees for marketplace use.
- Per-customer virtual accounts and automatic matching of virtual-account deposits to WooCommerce orders.
- Automated dispute evidence collection from WooCommerce order, fulfillment, or support records.

The API reference covers additional Bachs resources that may be added in separate releases. An account's available resources and permissions depend on its Bachs configuration. Do not infer support for a feature from the presence of a corresponding Bachs API endpoint.

## Node.js and Python packages

This repository is a **PHP WordPress plugin**, so install it into WordPress as a plugin folder or ZIP. `npm install` and `pip install` do not install a WooCommerce plugin.

Bachs publishes separate server-side SDK packages for applications written in Node.js or Python. Those SDKs call the Bachs API from those applications; they do not install, configure, or extend this WooCommerce plugin:

```bash
npm install @bachs/sdk
pip install bachs
```

The official announcement lists Node.js 22+ and Python 3.10+ requirements. See [Bachs SDK announcement](https://bachs.io/blog/bachs-sdks-nodejs-python), [Node SDK documentation](https://docs.bachs.io/sdks/node), and [Python SDK documentation](https://docs.bachs.io/sdks/python).

## Troubleshooting

### Bachs does not appear at checkout

- Confirm WooCommerce is active and Bachs is enabled under **WooCommerce → Settings → Payments**.
- Confirm the selected environment matches the key prefix: Sandbox keys start with `sk_sandbox_`; Live keys start with `sk_live_`.
- Confirm a current webhook signing secret is configured for the selected environment.
- Live checkout requires HTTPS.

### Checkout cannot be created

- Check that the API key has `payments:write` and matches the selected environment.
- Confirm the currency and amount are supported by the Bachs account.
- Confirm the site's success and cancel URLs can be reached by the customer's browser.
- Review **WooCommerce → Status → Logs** and select the `bachs-woocommerce` source. Logs contain only operation names, HTTP statuses, and provider error codes.

### The order remains pending

- Confirm the correct Sandbox or Live webhook destination is enabled and subscribed to `collection.succeeded`.
- Confirm its signing secret matches the selected environment in WooCommerce.
- Verify the public REST endpoint can receive requests over HTTPS and that a firewall/security plugin is not blocking it.
- Check the Bachs webhook delivery history for response codes. Use the order reconciliation action if a delivery was missed.

### The order is on hold

Compare the payment status, amount, and currency in Bachs with the WooCommerce order. Do not fulfill an order until the difference is understood.

### A refund cannot be requested

Check the payment's `is_refundable` value and its payment method in Bachs. A charge can have only one non-failed refund operation. Some payment methods do not support refunds.

## Developer notes

The plugin has no Composer dependencies or JavaScript build step. Main code areas:

| File | Responsibility |
| --- | --- |
| `bachs-woocommerce.php` | Plugin bootstrap and WooCommerce compatibility declarations. |
| `includes/class-wc-gateway-bachs.php` | Gateway settings, checkout, overlay, and refund requests. |
| `includes/class-bachs-api-client.php` | Fixed-host Bachs API client. |
| `includes/class-bachs-settings.php` | Environment and secret resolution. |
| `includes/class-bachs-webhook-verifier.php` | Raw-body timestamped HMAC verification. |
| `includes/class-bachs-webhook-controller.php` | Public REST webhook endpoint. |
| `includes/class-bachs-order-service.php` | Order mapping, payment transitions, and refund recording. |
| `includes/class-bachs-admin-order-actions.php` | Manual payment/refund reconciliation. |
| `includes/class-bachs-admin-refund-panel.php` | Refund controls on classic and HPOS order screens. |
| `includes/class-bachs-admin-tools.php` | Protected admin workflows for selected Bachs merchant resources. |
| `includes/class-bachs-blocks-support.php` | Conditional Checkout Blocks registration. |
| `assets/js/` | Small no-build scripts for Checkout Blocks and overlay feedback. |

Before opening a pull request, run syntax checks from the repository root:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/js/blocks.js
node --check assets/js/overlay-checkout.js
```

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for contribution guidelines and [`SECURITY.md`](SECURITY.md) for responsible vulnerability reporting.

## Contributing and security reports

Pull requests are welcome. Keep changes focused, explain their effect on the WooCommerce checkout/order lifecycle, and include the WordPress, WooCommerce, and PHP versions used for validation. Run the syntax checks above and perform Sandbox checkout/webhook verification for changes that affect payment flows. Never include real merchant credentials, customer data, or webhook payloads containing personal information in commits or issues.

Report security vulnerabilities privately. Follow [`SECURITY.md`](SECURITY.md) and avoid public issue details until a fix is available.

## License

GPL-2.0-or-later. See [`LICENSE.txt`](LICENSE.txt).
