# Changelog

All notable changes to Cleat are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Cleat uses
[Semantic Versioning](https://semver.org/).

## [0.1.3] - Unreleased

Price changes at the end of a period, and the trial-ending and
subscription-canceled emails. Run `database/migrations/003_period_end_changes.sql`
when upgrading; see "Upgrading" in the README. Made for Bearing Cloud
(downgrades at period end, and the lifecycle emails its plans need).

### Added

- `Subscription::swapAtPeriodEnd($lookupKey)`: move to another price when the
  current period or trial ends, with no proration and nothing charged now.
  The Runner applies it as it rolls the period; the new period follows the
  new price's interval. `scheduledPrice()` and `cancelScheduledSwap()`.
- The trial-ending notice: the Runner fires `TrialEnding` and sends the
  `trial_ending` email `trial_ending_days` (default 3) before a trial
  converts, once per subscription, never for a trial set to cancel.
- The `subscription_canceled` email, on `cancel()` (stays active until the
  period end, and can be reactivated), `cancelNow()` and a final dunning
  cancel. Not for a subscription that never started.
- Config: `trial_ending_days` (1-30 or `null`) and `emails.trial_ending` /
  `emails.subscription_canceled`.
- Migration 003: `cleat_subscriptions.scheduled_price_id` and
  `trial_ending_notified_at`, both nullable, additive and safe to run twice.

### Changed

- `swap()`, `cancel()`, `cancelNow()` and a final dunning cancel clear a
  scheduled price change.
- `Schema::assertMigrated()` also requires migration 003.
- `RunReport` has `trialEndingNotices`.

## [0.1.2] - 2026-10-07

Stored-credential (card-on-file) indicators on every charge of a saved card.
Run `database/migrations/002_stored_credentials.sql` when upgrading; see
"Upgrading" in the README.

### Added

- Every charge of a saved card says who started it and why, as Visa and
  Mastercard's stored credential framework expects:
  - A new subscription's first payment (`newSubscription()->create()`,
    checkout for a recurring price) is sent as the first customer-initiated
    transaction of a recurring series (`isFirstRecurringPayment`).
  - The pay page's "save this card" charge is the card's first
    customer-initiated transaction.
  - Paying with the saved card on the pay page is a customer-initiated
    stored-credential transaction (`isStoredCredentials`).
  - Renewals and trial conversions are merchant-initiated recurring
    transactions (`recurringBilling`, `isSubsequentAuth`) and carry the
    card's original network transaction ID.
  - Dunning retries are merchant-initiated resubmissions
    (`reason=resubmission`) with the original ID.
  - `$customer->charge()`, `invoiceFor()`, `swap()` and `$invoice->pay()` are
    merchant-initiated unscheduled transactions.
  - One-time cards that are not saved carry no indicators.
- `Gateway\StoredCredential`, the indicators as a value object (initiator,
  type, first, original network transaction ID, reason).
  `$invoice->pay(storedCredential: ...)` lets an app mark a charge as
  customer-initiated.
- The network transaction ID of every charge is stored in
  `cleat_charges.network_transaction_id`. The saved card's original ID, from
  its first approved stored-credential charge or, in production, from CIM's
  `liveMode` validation, is stored in
  `cleat_customers.original_network_transaction_id`. It is cleared whenever
  the card changes or is deleted.
- A card with no original ID on file (saved before 0.1.2, or saved in the
  sandbox and never charged) gets the merchant-initiated flags without it.
  One notice is logged per customer, and the next approved charge's ID is
  kept.
- `examples/sandbox-check.php --stored-credential` sends every kind of charge
  to the sandbox. It prints the card-on-file fields of each request as sent
  and the IDs that came back, and checks what Authorize.net recorded.
- `FakeGateway` records the `StoredCredential` passed to each charge and
  returns network transaction IDs. `$liveValidation` and
  `$returnNetworkTransIds` simulate production card saves and processors
  that return no ID.

### Changed

- `GatewayInterface::chargeProfile()` takes a last parameter,
  `?StoredCredential $storedCredential = null`. Custom gateways must add it.
- `GatewayResult` has a `networkTransactionId` property.
- Migration 002 adds two nullable columns. It is additive, instant on MySQL 8
  and MariaDB 10.11, and safe to run twice. Cleat refuses to save or charge a
  card until it has run, so a missing migration fails before any money moves.

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

[0.1.2]: https://github.com/srivera145/cleat/releases/tag/v0.1.2
[0.1.1]: https://github.com/srivera145/cleat/releases/tag/v0.1.1
[0.1.0]: https://github.com/srivera145/cleat/releases/tag/v0.1.0
