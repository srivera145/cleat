# Changelog

All notable changes to Cleat are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Cleat uses
[Semantic Versioning](https://semver.org/).

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

[0.1.0]: https://github.com/srivera145/cleat/releases/tag/v0.1.0
