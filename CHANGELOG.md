# Changelog

All notable changes to Cleat are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Cleat uses
[Semantic Versioning](https://semver.org/).

## [0.1.1] - 2026-10-07

The first pass against a live Authorize.net sandbox. What was tested, the
results and what is still unverified are in [SANDBOX.md](SANDBOX.md).

### Fixed

- Webhook signatures. `X-ANET-Signature` is HMAC-SHA512 keyed with the
  Signature Key exactly as the merchant interface shows it, the 128-character
  hex string. 0.1.0 also accepted `hex2bin(signature_key)`, which never
  matches a real notification; that form is no longer accepted.
- A held charge declined in fraud review could end up `voided` with nothing
  else done. Authorize.net voids the declined transaction and sends
  `void.created` a few milliseconds before `fraud.declined`, and the void was
  recorded first. Either notification now settles the charge as `failed`
  (`fraud_declined`), so `PaymentFailed` fires and an automatic renewal enters
  dunning. A void no longer overwrites a failed charge.
- The hosted invoice page's receipt named the customer's saved card even when
  the customer paid with a different card. It now names the card only when the
  saved card was charged, as the receipt email already did.
- The hosted pages' CSP blocked Accept.js's first request: Accept.js fetches
  `AcceptCore.js` from its own host before it falls back to a script tag, and
  Chrome logged a CSP violation on every card page. `connect-src` now allows
  `jstest.authorize.net` (sandbox) or `js.authorize.net` (production).

### Added

- `examples/sandbox-check.php`: scripted end-to-end checks against the
  sandbox (CIM profiles, approve, decline, error, held, refund-to-void,
  `findTransaction`, timeout recovery, webhooks), plus webhook registration,
  ping, replay and signature checks, and `--refund-settled` for the day after.
- `SANDBOX.md`: the results of the sandbox pass, the triggers that work, and
  what is still unverified.
- Webhook regression tests built on real captured sandbox notifications.

### Changed

- The examples read `examples/.env` (gitignored). With sandbox keys they use
  Authorize.net and the `cleat_sandbox` database, and refuse to run unless
  `AUTHORIZENET_SANDBOX=true`. `examples/seed.php` creates the right database.
- `examples/public/webhook.php` can save each raw notification for debugging
  when `CLEAT_WEBHOOK_CAPTURE_DIR` is set.

## [0.1.0] - 2026-10-02

First release of Cleat Billing.

### Added

- Catalog of products and prices, with integer cents throughout (`Money`).
- Customers with cards tokenized in the browser by Accept.js and stored in
  Authorize.net CIM. Cleat rejects anything that looks like a raw card number.
- Subscriptions with trials, proration on plan swaps, cancellation and a
  scheduler of its own. Cleat never uses Authorize.net ARB.
- One-time charges, invoices and refunds, with a void fallback for unsettled
  transactions.
- Hosted invoice pay page, reusable payment links and checkout, returned as
  framework-free `Response` objects.
- Runner (`bin/cleat-run.php`) for cron or Task Scheduler, with dunning for
  failed renewals.
- Authorize.net webhook handler with signature verification, plus timeout
  reconciliation against unsettled transactions.
- Events, receipt, invoice and payment-failed emails, and overridable
  templates styled with Deck.
- CSRF protection, rate limiting and a captcha hook on public pages.
- `FakeGateway` for tests and local demos, and an example app in `examples/`.
- Connect interface and tables (connected accounts, transfers, payouts). No
  driver ships yet; `NullConnectGateway` throws
  `ConnectNotConfiguredException`.
- MySQL 8 / MariaDB 10.11 schema in `database/migrations`, runnable from PHP
  with `Cleat\Support\Schema::migrate()`.
- Test suite and CI on MariaDB 10.11, MySQL 8.0 and MySQL 8.4.

### Known limitations

- The Authorize.net gateway has not yet been run against a live sandbox. See
  "Not yet verified against a live sandbox" in the README.

[0.1.1]: https://github.com/srivera145/cleat/releases/tag/v0.1.1
[0.1.0]: https://github.com/srivera145/cleat/releases/tag/v0.1.0
