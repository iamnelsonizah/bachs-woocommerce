# Contributing

Thanks for helping improve Bachs Payments for WooCommerce. Contributions should keep payment behavior secure, explicit, and compatible with WooCommerce's order APIs.

## Before opening a pull request

- Search existing issues and pull requests so work is not duplicated.
- Describe the user-visible behavior and any effect on payment, refund, or order-status handling.
- Keep each change focused and preserve the project's modular class structure.
- Use WooCommerce CRUD APIs for orders and refunds so both legacy order storage and HPOS remain supported.
- Do not add or log API keys, webhook secrets, payment-card data, customer data, or unredacted webhook bodies.
- Update `README.md`, `readme.txt`, or `CHANGELOG.md` when behavior, setup, compatibility, or release scope changes.

## Local checks

The plugin has no Composer dependencies or JavaScript build step. From the repository root, run:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/js/blocks.js
node --check assets/js/overlay-checkout.js
```

For changes to checkout, webhook, payment, or refund behavior, validate with a Sandbox Bachs account and a WooCommerce store before requesting review. Never use Live credentials for development.

## Pull request details

Include:

- A concise summary of the change and why it is needed.
- Compatibility versions used for validation.
- Relevant screenshots for changes to WooCommerce admin or checkout UI.
- Any required documentation, webhook event, API scope, or configuration updates.

