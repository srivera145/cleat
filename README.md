# Cleat

Self-hosted billing and invoicing for PHP 8.2. Subscriptions, invoices, hosted
invoice pages and reusable payment links, with every piece of billing state in
your own MySQL or MariaDB tables. Authorize.net stores card tokens (CIM) and
moves money. Nothing else lives there.

- **Your database is the source of truth.** Cleat's own scheduler decides when
  to charge. It never uses Authorize.net ARB.
- **Cashier-style API.** `$customer->newSubscription('premium-monthly')->withTrialDays(7)->create($card)`.
- **No card data on your server.** Cards are tokenized in the browser with
  Accept.js. Cleat accepts only the opaque token and rejects anything that
  looks like a card number.
- **Framework-free.** The pay page, checkout and webhook handlers return a
  `Response` object; your app sends it. No dependency on Keel or any framework.
- **Integer cents everywhere.** No floats on the money path, enforced by a test.

Part of the EchoDial stack: Helm (local server), Keel (SaaS starter kit),
Deck (CSS framework). Keel will offer Cleat and Stripe side by side behind a
shared billing interface.

| Layer | What it does | Runs on |
| --- | --- | --- |
| **Cleat Billing** (this package) | One-time charges, subscriptions, invoices, hosted invoice links, payment links | Authorize.net |
| **Cleat Connect** (planned) | Connected accounts, split payments, payouts | A PayFac-as-a-Service driver (likely Finix) |

Connect's interface and tables already exist so nothing needs migrating later.
Until a Connect driver ships, anything given a connected account throws
`ConnectNotConfiguredException` rather than charging the platform.

---

## Contents

1. [Requirements](#requirements)
2. [Install](#install)
3. [Configure](#configure)
4. [Catalog: products and prices](#catalog-products-and-prices)
5. [Customers and cards](#customers-and-cards)
6. [Subscriptions](#subscriptions)
7. [One-time charges and invoices](#one-time-charges-and-invoices)
8. [The hosted invoice page](#the-hosted-invoice-page)
9. [Payment links and checkout](#payment-links-and-checkout)
10. [Refunds](#refunds)
11. [The runner (cron)](#the-runner-cron)
12. [Dunning](#dunning)
13. [Webhooks](#webhooks)
14. [Events](#events)
15. [Email](#email)
16. [Templates](#templates)
17. [Security model](#security-model)
18. [How charging stays safe](#how-charging-stays-safe)
19. [Stored credentials (card on file)](#stored-credentials-card-on-file)
20. [Connect (stub)](#connect-stub)
21. [Testing](#testing)
22. [Schema notes](#schema-notes)
23. [Upgrading](#upgrading)
24. [Verified against the Authorize.net sandbox](#verified-against-the-authorizenet-sandbox)

---

## Requirements

- PHP 8.2+ with `pdo`, `pdo_mysql`, `curl`, `json`
- MariaDB 10.11+ or MySQL 8
- An Authorize.net account (sandbox or live) with CIM enabled, for real money.
  The bundled `FakeGateway` needs nothing.
- HTTPS on the pages that take cards (Accept.js refuses plain HTTP).

No other runtime dependencies.

## Install

```bash
composer require echodial/cleat
```

Run the migrations, in order, with your own migration tool. They are plain
SQL and safe to run twice:

```bash
mysql your_db < vendor/echodial/cleat/database/migrations/001_create_cleat_tables.sql
mysql your_db < vendor/echodial/cleat/database/migrations/002_stored_credentials.sql
```

Or from PHP: `Cleat\Support\Schema::migrate($pdo);`

## Configure

Copy `vendor/echodial/cleat/config/cleat.php` into your app, fill it in, and
call `configure()` once per request (or once per worker):

```php
use Cleat\Cleat;

$pdo = new PDO('mysql:host=127.0.0.1;dbname=app;charset=utf8mb4', 'user', 'pass');
$config = require __DIR__ . '/config/cleat.php';

Cleat::configure($pdo, $config, new MyMailer()); // mailer optional; see Email
```

| Key | Default | Notes |
| --- | --- | --- |
| `gateway` | `'fake'` | `'authorizenet'` for real money. |
| `authorizenet.login_id` / `transaction_key` | `''` | API credentials. The transaction key stays on the server and is never logged. |
| `authorizenet.client_key` | `''` | Public key for Accept.js. |
| `authorizenet.signature_key` | `''` | Webhook HMAC key. Webhooks are refused while it is empty. |
| `authorizenet.sandbox` | `true` | Sandbox endpoints, Accept.js from `jstest.authorize.net`, CIM `testMode`. |
| `connect.driver` | `null` | Must stay `null` until a Connect driver ships. |
| `currency` | `'USD'` | Default currency for new invoices. |
| `invoice_prefix` | `'CLT-'` | Numbers look like `CLT-000001`. At most 12 characters, so the number fits Authorize.net's 20. |
| `base_url` | `'http://localhost'` | Used to build pay and checkout URLs. |
| `routes` | `/pay/{token}`, `/checkout/{token}` | Paths your app serves the two pages on. |
| `app_key` | `''` | 32+ byte secret for CSRF. Required outside fake mode: Cleat refuses to boot without it. |
| `brand` | | `name`, `logo_url`, `support_email`, plus `hue` (Deck `--hue-brand`) and `color` (email button). |
| `invoice_link_days` | `30` | Hosted link lifetime from finalize, extended whenever the invoice is (re)sent. `null` = until paid or void. |
| `rate_limits` | see file | `per_ip`, `per_link`, `failures_per_ip` as `[max, window seconds]`. |
| `automation` | see [Dunning](#dunning) | Retry schedule and what happens after it. |
| `grace_days` | `0` | Days a `past_due` subscription still counts as `active()`. |
| `trial_ending_days` | `3` | The Runner sends the trial-ending notice this many days before a trial converts (1-30). `null` = none. |
| `emails` | both `true` | `trial_ending` and `subscription_canceled`: turn either email off. The events fire either way. |
| `deck_css` | jsDelivr `@echodial/deck@0.1` | Or a path on your own site. The CSP follows this origin. |
| `templates_path` | `null` | A folder checked before the package's templates (see [Templates](#templates)). |

Generate an app key with `php -r "echo bin2hex(random_bytes(32));"`.

## Catalog: products and prices

```php
use Cleat\Product;
use Cleat\Price;

$product = Product::create(['name' => 'Premium', 'description' => 'Everything.']);

Price::create($product, [
    'lookup_key' => 'premium-monthly',   // how your code refers to it
    'nickname' => 'Monthly',
    'amount' => 2900,                    // cents
    'billing_type' => 'recurring',
    'billing_interval' => 'month',       // day | week | month | year
    'interval_count' => 1,
    'trial_days' => 7,                   // default trial for this price
]);

Price::create($product, ['lookup_key' => 'setup-fee', 'amount' => 5000]); // one-time
```

A price's amount and interval never change. To change what something costs,
create a new price and `deactivate()` the old one. Existing subscriptions
keep the price they were sold.

## Customers and cards

```php
use Cleat\Customer;

$customer = Customer::create(['user_id' => $user->id, 'email' => $user->email, 'name' => $user->name]);
$customer = Customer::forUser($user->id);

// Guests (from payment links) have no user. Attach one to a user later, if the
// emails match and you choose to:
$customer = Customer::forUser($user->id, claimGuestWithEmail: $user->verifiedEmail);
```

`create()` also creates the customer's CIM profile. To save a card, tokenize it
in the browser with Accept.js (the bundled `templates/partials/payment_form.php`
does this) and pass only the opaque data:

```php
$customer->updatePaymentMethod([
    'dataDescriptor' => $_POST['dataDescriptor'],
    'dataValue' => $_POST['dataValue'],
]);

$customer->paymentMethodLabel(); // "Visa ending 4242"
```

The card becomes the default payment profile, and its brand, last four and
expiry are stored locally. The previous profile is deleted from CIM. A raw
card number passed anywhere throws `InvalidArgumentException` before any
network call.

## Subscriptions

```php
$subscription = $customer->newSubscription('premium-monthly')
    ->withTrialDays(7)      // overrides the price's trial_days; 0 or skipTrial() for none
    ->quantity(3)
    ->create($opaqueData);  // optional: attach this card first
```

- **With a trial:** status `trialing`, nothing charged, the period is the
  trial. The runner charges at the end of the trial.
- **Without:** the first invoice is created and charged immediately. On
  success the status is `active`. On a decline the subscription stays
  `incomplete`, the invoice stays open, and `PaymentFailedException` is thrown
  with the gateway result and the invoice's pay URL:

```php
try {
    $customer->newSubscription('premium-monthly')->create();
} catch (Cleat\Exceptions\PaymentFailedException $e) {
    return redirect($e->payUrl); // the customer can pay with another card
}
```

State checks:

```php
$subscription->active();         // trialing or active, or past_due within grace_days
$subscription->onTrial();
$subscription->onGracePeriod();  // canceled at period end, still usable until ends_at
$subscription->pastDue();
$customer->subscribed('premium-monthly');
```

Changes:

```php
$subscription->cancel();        // at period end; ends_at = current_period_end
$subscription->resume();        // only before ends_at
$subscription->cancelNow();     // immediately, no refund; stops pending retries
$subscription->swap('premium-yearly');               // prorated
$subscription->swap('basic-monthly', prorate: false);
$subscription->swapAtPeriodEnd('basic-monthly');     // at the end of the period or trial (0.1.3)
$subscription->cancelScheduledSwap();
```

**Changes at period end** (0.1.3) are the usual way to downgrade: nothing is
charged or credited now, and the customer keeps what they paid for until the
period (or trial) ends. The Runner applies the change as it rolls the period,
so the renewal invoice is for the new price and the new period follows the new
price's interval (yearly to monthly renews a month later). `scheduledPrice()`
says what is coming. Asking for the current price clears it, a later call
replaces it, and an immediate `swap()`, `cancel()` or `cancelNow()` drops it.
A subscription set to cancel must be resumed before it can schedule a change.

**Proration** uses the seconds left in the current period. The unused part of
the old price becomes a credit line and the rest of the period at the new
price becomes a debit line, both marked `is_proration`. A positive net is
invoiced and charged immediately. A negative net waits as pending lines and
comes off the next renewal; a credit bigger than the renewal carries forward
again. Period dates never change on a swap. If the immediate charge fails, the
swap still stands and the invoice enters dunning.

**Period math** works from the subscription's anchor day, so short months do
not drift it: Jan 31 → Feb 28 (29 in a leap year) → Mar 31 → Apr 30.

## One-time charges and invoices

```php
// Charge the stored card for a price, or an amount. Returns the paid invoice.
$invoice = $customer->charge('setup-fee', quantity: 2);
$invoice = $customer->invoiceFor('Rush fee', 2500);

// Build an invoice and email it. send() never charges.
$invoice = $customer->newInvoice()
    ->addItem('Consulting, March', 150000)
    ->addPrice('setup-fee', 1)
    ->tax(12000)                         // you compute tax; Cleat does not
    ->memo('Thanks for your business.')
    ->dueIn(14)
    ->send();                            // or ->finalize() to open without emailing

$invoice->paymentUrl();  // https://billing.example.com/pay/{64 hex chars}; null once paid or void
$invoice->pay();         // charge the stored card now
$invoice->void();
$invoice->send();        // re-send; extends the hosted link
$html = $invoice->render(); // print-ready HTML (A4 or Letter)
```

Statuses: `draft → open → paid | void | uncollectible`. Numbers come from a
row-locked sequence inside the finalize transaction, so they are gapless:
drafts have no number, and a rolled-back finalize gives its number back.
Line items are frozen once the invoice is finalized.

## The hosted invoice page

The customer opens the link from the invoice email and pays without logging
in: with their saved card, a new card, or a new card they choose to save.

```php
// public/pay.php, routed from /pay/{token}
use Cleat\Http\InvoicePayPage;

session_start();
$page = new InvoicePayPage(session_id());        // Cleat binds CSRF to your session
$response = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $page->submit($token, $_POST, $_SERVER['REMOTE_ADDR'])
    : $page->show($token);

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header("$name: $value");
}
echo $response->body;
```

| Situation | Response |
| --- | --- |
| Unknown or draft token | 404 |
| Link expired, invoice void or uncollectible | 410 |
| Already paid | 200, "this invoice is paid" view |
| Open | 200, invoice and card form |
| Declined | 402, form again with a generic message (never the gateway's reason text) |
| Held for fraud review | 200, "payment under review" |
| Bad or missing CSRF token | 403 |
| Rate limited | 429, plain text, `Retry-After` |

Paying a `past_due` subscription's invoice reactivates the subscription and
clears its scheduled retry. In Keel, Laravel or Slim, convert the `Response`
into the framework's own response object instead.

## Payment links and checkout

```php
use Cleat\PaymentLink;

$link = PaymentLink::create('premium-monthly', [
    'allow_quantity_change' => true,
    'max_quantity' => 10,
    'collect_phone' => true,
    'success_url' => 'https://example.com/welcome',  // else Cleat shows its own success page
    'max_uses' => 100,
    'expires_at' => '2026-12-31 23:59:59',
]);
$link->url();         // https://billing.example.com/checkout/{token}
$link->deactivate();
```

`CheckoutPage` is wired exactly like `InvoicePayPage`. On submit it checks
rate limits, then CSRF and the optional captcha, then validates input. Next it
finds or creates a guest customer and either charges a one-time price
(nothing stored) or saves the card and starts a subscription (honouring the
price's trial). Finally it counts the use atomically.

If two buyers race for the last use of a `max_uses` link, both may pass the
"sold out?" check while their cards are being charged. The one that counts
its use second is refunded (or voided if the charge has not settled), its
invoice is voided, and it gets a 410 saying so. This is tested with two real
PHP processes against the database.

A recurring checkout never replaces a stored card that another guest's
renewals depend on. If a guest with that email already has a card, a new
guest record is created.

## Refunds

```php
$refund = $charge->refund();       // full
$refund = $charge->refund(2500);   // partial, in cents

$refund->method;   // RefundMethod::Refund, or ::Void when the charge had not settled
```

Authorize.net only refunds settled transactions. For an unsettled one (error
54), a full refund becomes a void, and `$refund->method` says which happened.
A partial refund of an unsettled charge cannot be voided and throws
`RefundFailedException` explaining that it must wait for settlement.

## The runner (cron)

Everything time-based happens in `Billing\Runner`. Run it every 15 minutes:

```bash
# Linux
0,15,30,45 * * * * php /app/vendor/bin/cleat-run.php --bootstrap=/app/cleat-bootstrap.php

# Windows / Helm (Task Scheduler)
schtasks /create /sc minute /mo 15 /tn "Cleat runner" ^
  /tr "C:\Helm\resources\server\php\php.exe C:\app\vendor\bin\cleat-run.php --bootstrap=C:\app\cleat-bootstrap.php"
```

`cleat-bootstrap.php` is any file that calls `Cleat::configure(...)`. Options:
`--now="2026-03-01 00:00:00"` runs as of a given UTC time, and `--json`
prints the report. Exit code 0 means all good, 1 means some rows failed (each
is logged; the rest still ran), 2 means bad usage.

Each run, in order:

0. Trials ending within `trial_ending_days` (and not set to cancel): fire
   `TrialEnding` and send the trial-ending email, once per subscription.
1. Trials ending now or earlier: invoice the first paid period and charge it.
   No card on file takes the past-due path, with a pay-link email.
2. Active subscriptions whose period has ended (and are not set to cancel):
   roll the period, applying any change scheduled with `swapAtPeriodEnd()`,
   create the renewal invoice (with any pending proration lines), charge it.
3. Subscriptions set to cancel whose `ends_at` has passed: mark them canceled.
4. Open invoices with a retry due: retry through dunning.
5. Deactivate expired payment links and prune rate-limit rows older than 24 hours.

It is idempotent. Every step is claim-then-act: one short transaction locks a
row (`SELECT ... FOR UPDATE`), re-checks it, and changes it so it no longer
qualifies (rolls the period, sets a one-hour lease) before any gateway call.
A second run at the same moment, or a second server, finds nothing to claim.
Overlapping runs are also prevented with a named lock. From code:
`(new Runner())->run($now)`.

## Dunning

```php
'automation' => [
    'dunning_enabled' => true,
    'retry_attempts'  => [1, 3, 7],    // days after the first failure
    'failed_action'   => 'past_due',   // status while retrying
    'final_action'    => 'unpaid',     // or 'canceled' after the last retry fails
    'dispatch_emails' => true,
],
```

Every failed charge is recorded, and `PaymentFailed` fires with the attempt
number and whether a retry is scheduled. That much always happens.

**`dunning_enabled = true`.** For automatic charges on subscription invoices
(renewals, trial conversions, prorations):

- The subscription moves to `past_due` and `SubscriptionPastDue` fires.
- The invoice is retried 1, 3 and 7 days after the first failure.
- The `payment_failed` email, with the pay link and the next retry date, goes
  out each time.
- After the last retry fails, the subscription becomes `unpaid` (or
  `canceled`) and the invoice becomes `uncollectible`. `SubscriptionPastDue`
  or `SubscriptionCanceled` fires.
- A successful retry or pay-page payment makes the invoice paid and the
  subscription active, clears the schedule, fires `PaymentSucceeded` and
  `InvoicePaid`, and sends a receipt.

**`dunning_enabled = false`.** Nothing changes, nothing is scheduled, and
nothing is sent. Only the record and the event remain. What happens next is
up to you, and the pay link still works if you send it.

Customer attempts on the pay page, first payments of new subscriptions, and
one-off invoices never enter the retry schedule.

## Webhooks

```php
// public/webhook.php, registered in the merchant interface (Account > Webhooks)
use Cleat\Webhooks\WebhookHandler;

$response = (new WebhookHandler())->handle(file_get_contents('php://input'), getallheaders());
```

- The `X-ANET-Signature` header (`sha512=` + uppercase hex HMAC-SHA512 of the
  raw body, keyed with the Signature Key string exactly as the merchant
  interface shows it) is checked with `hash_equals`. A mismatch, or no
  configured signature key, returns 401.
- Events are stored keyed on `notificationId`. An event that was already
  processed returns 200 and does nothing. A processing error returns 500 so
  Authorize.net retries, and the event stays unprocessed until a retry succeeds.

| Event | What Cleat does |
| --- | --- |
| `net.authorize.payment.authcapture.created` | Reconciles a charge whose response never arrived (timeout, crash) by invoice number and amount, so the money is recorded and never taken twice. |
| `net.authorize.payment.fraud.approved` / `.declined` | Settles a `held` charge: paid, or failed and into dunning. |
| `net.authorize.payment.refund.created` / `void.created` | Records refunds and voids made in the merchant interface. |
| `net.authorize.customer.paymentProfile.deleted` | Clears the stored card fields. |
| anything else | Stored, ignored. |

## Events

```php
use Cleat\Cleat;
use Cleat\Events\PaymentFailed;

Cleat::events()->listen(PaymentFailed::class, function (PaymentFailed $e) {
    // $e->invoice, $e->charge, $e->attemptNumber, $e->willRetry
});

// Bridge everything into your framework's dispatcher:
Cleat::events()->listen('*', fn (object $event) => $keelEvents->dispatch($event));
```

`PaymentSucceeded`, `PaymentFailed`, `SubscriptionCreated`,
`SubscriptionCanceled`, `SubscriptionPastDue`, `TrialEnding` (0.1.3),
`InvoiceCreated`, `InvoiceSent`, `InvoicePaid`, `CheckoutCompleted`, and
`ChargeRefunded`.

Events fire after the database work they describe has committed; an event
raised in a transaction that rolls back is dropped. By default a listener that
throws is logged and skipped, because by then money has moved. Call
`Cleat::events()->throwListenerExceptions()` in tests.

## Email

Cleat sends five emails: the invoice (with a "Pay invoice" button), payment
failed (with the pay link), the receipt (also used for "your trial has
started"), and since 0.1.3 the trial-ending notice (`trial_ending_days` before
a trial converts) and subscription canceled (when `cancel()` schedules the
end, saying until when it keeps working; when `cancelNow()` or the last failed
dunning retry ends it). The Runner's own period-end cancellation sends nothing:
the customer already has the date. Each comes as HTML plus plain text, through
any mailer you wrap:

```php
use Cleat\Mail\MailerInterface;

final class PhpMailerAdapter implements MailerInterface
{
    public function __construct(private PHPMailer\PHPMailer\PHPMailer $mail) {}

    public function send(string $to, string $subject, string $html, ?string $text = null): void
    {
        $m = clone $this->mail;
        $m->addAddress($to);
        $m->Subject = $subject;
        $m->isHTML(true);
        $m->Body = $html;
        $m->AltBody = $text ?? '';
        $m->send();
    }
}
```

Without a mailer Cleat uses `NullMailer`. `ArrayMailer` keeps messages in
memory for tests. A mailer failure is logged and never undoes billing work.

## Templates

Plain PHP with Deck classes, in `templates/`:

| File | Used for |
| --- | --- |
| `invoice.php` | `$invoice->render()`: screen, PDF, print (A4 and Letter) |
| `pay_invoice.php` | the hosted invoice page |
| `checkout.php`, `checkout_success.php` | payment links |
| `error.php` | 403 / 404 / 410 pages |
| `emails/invoice.php`, `emails/payment_failed.php`, `emails/receipt.php` | email, inline styles, bulletproof table buttons |
| `partials/payment_form.php` | the Accept.js card form shared by every page that takes a card |

To customise one, set `templates_path` and drop a file with the same relative
path (for example `emails/receipt.php`) into that folder. Every template gets
`$e()` (escape), `$partial()` and `$json()`. Every printed value goes through
`$e()`, which a test enforces.

Deck loads from jsDelivr (`@echodial/deck@0.1/dist/deck.min.css`) unless
`deck_css` points elsewhere. Every class the templates use is a public class
in Deck's published API, or a `.cleat-*` class in Cleat's own scoped style
block; a test checks this against the installed package. Pages are mobile
first and checked at 375px and 1280px, in light and dark mode.

In fake mode the card form shows a test-card picker instead of loading
Accept.js, so the whole flow works locally with no Authorize.net account.

## Security model

- **No card data.** Card inputs have no `name` attribute, so they cannot post.
  Accept.js tokenizes in the browser with the public login id and client key,
  and only `dataDescriptor` + `dataValue` are submitted. The transaction key
  never reaches a browser or a log.
- **Headers.** Pay and checkout pages send `Cache-Control: no-store`,
  `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, and a CSP that
  allows only self, the Deck CDN, the Accept.js hosts (plus your logo's
  origin and a captcha's, if configured). Cleat's own inline script and style
  carry a per-response nonce; there is no `unsafe-inline`.
- **CSRF.** An HMAC over the link token, your session id and the issue time,
  keyed with `app_key`, valid for two hours.
- **Rate limits.** Database-backed, per IP, per link, and failed card attempts
  per IP (5 an hour by default, so the sixth gets 429).
- **Captcha.** Implement `Security\CaptchaInterface` (Turnstile, hCaptcha) and
  pass it to the page handler: `new CheckoutPage(session_id(), $turnstile)`.
- **Tokens, not ids.** URLs carry 64-hex-character tokens from `random_bytes`.
  Sequential ids never appear in a URL.
- **Customer-facing errors are generic.** The gateway's decline reason is
  kept on the charge row and never shown to the buyer.

## How charging stays safe

Every charge follows the same three steps:

1. **Claim, in one transaction.** Lock the invoice row. Refuse if another
   charge on it is `pending` or `held`. Write a `pending` charge row with a
   unique idempotency key (`inv_{id}_attempt_{n}` for automatic attempts) and
   bump `attempt_count`. Commit.
2. **Call the gateway**, outside any transaction, with a 30-second timeout.
3. **Record, in one transaction.** Write the outcome. On success, mark the
   invoice paid and reactivate its subscription.

A crash between 1 and 3 leaves a `pending` row that blocks every further
attempt until it is reconciled, by the authcapture webhook or by hand.

**Unknown outcomes.** A timeout, a dropped connection or an unreadable
response is recorded as `failed` with reason `timeout` (or `network`,
`gateway_exception`, `invalid_response`) and is never retried in the same
run. The money may still have moved, so such a charge blocks every further
attempt on its invoice until it is settled:

- Before any new attempt, `Invoice::reconcile()` asks the gateway
  (`findTransaction`: the unsettled transaction list, matched by invoice
  number and amount). If the gateway did capture it, the charge becomes
  `succeeded` (reason `timeout_captured`) and the invoice is paid; nothing is
  charged again. If it did not, the reason becomes `timeout_verified` and the
  new attempt goes ahead. If the gateway cannot be asked, the attempt is
  refused with `ChargeInProgressException` rather than risking a double charge.
- The `authcapture.created` webhook settles it the same way whenever it arrives.
- The customer sees "we couldn't confirm your payment", not "declined".
- A checkout with an unknown outcome is not voided. If it captures later, the
  purchase is completed then: the use is counted, `CheckoutCompleted` fires,
  and the buyer's retry shows that purchase instead of charging again.
- A webhook that settles a charge while its own request is still in flight
  wins; the late response never overwrites it. Webhooks for a charge or
  refund that is still in flight return 500 so Authorize.net redelivers them
  once Cleat has recorded its own result.

Authorize.net has no idempotency key. `refId` is only echoed back, so these
guarantees come from Cleat's own rows and locks.

Cleat refuses to charge while a database transaction is open on its
connection, because a host transaction would keep the pending row from being
committed first. Don't wrap Cleat payment calls in your own transaction.

It also refuses to save or charge a card until every migration has run, so a
missing migration fails before money moves, not while a payment is recorded.

## Stored credentials (card on file)

Card networks expect a stored card's transactions to say who started them.
The first one is customer-initiated (CIT) and establishes the stored
credential. Later charges Cleat makes on its own are merchant-initiated (MIT)
and carry the network transaction ID of that first transaction. Cleat sends
these indicators on every charge of a saved card:

| Charge | Sent as | Authorize.net fields |
| --- | --- | --- |
| New subscription's first payment: `newSubscription()->create()`, or a checkout for a recurring price | first CIT, recurring | `processingOptions.isFirstRecurringPayment` |
| Pay page, "save this card" | first CIT; recurring on a subscription's invoice | `isFirstRecurringPayment`, otherwise `isFirstSubsequentAuth` (sandbox) or `isStoredCredentials` (production; see below) |
| Pay page, saved card | CIT with a stored card | `processingOptions.isStoredCredentials` |
| Renewal or trial conversion (runner) | MIT, recurring | `transactionSettings` `recurringBilling=true`, `processingOptions.isSubsequentAuth`, `subsequentAuthInformation.originalNetworkTransId` |
| Dunning retry | MIT, resubmission | `isSubsequentAuth`, `originalNetworkTransId`, `reason=resubmission` |
| `$customer->charge()`, `invoiceFor()`, `swap()`, `$invoice->pay()` | MIT, unscheduled | `isSubsequentAuth`, `originalNetworkTransId` |
| One-time card, not saved (checkout for a one-time price, pay page with save off) | nothing: not a stored credential | none |

`$invoice->pay()` defaults to an unscheduled MIT. When your app charges a
saved card while the customer is present, say so:

```php
use Cleat\Gateway\StoredCredential;

$invoice->pay(storedCredential: StoredCredential::customerInitiated());
```

**The original network transaction ID.** Every charge's ID is kept in
`cleat_charges.network_transaction_id`. The saved card's original ID is kept
in `cleat_customers.original_network_transaction_id` and sent with every MIT.
It comes from the first approved stored-credential charge on the card. In
production it is already known when the card is saved: CIM's `liveMode`
validation is the card's first stored-credential transaction, and Cleat reads
its ID from the payment profile. That is also why, in production, a "save
this card" charge on a one-off invoice is sent with `isStoredCredentials`
rather than `isFirstSubsequentAuth`, as Authorize.net's card-on-file guide
asks. A new card starts with its own ID, never the old card's.

**When the ID is missing.** Cards saved before 0.1.2 have no original ID on
file, and neither does a card saved in the sandbox (`testMode`) that has not
been charged yet, such as one saved for a trial. Cleat sends the MIT flags
without the ID rather than failing (CIM then sends the ID stored with the
payment profile, if it has one) and logs one notice per customer at `info`
level. The first approved charge's ID then becomes the card's, so the notice
stops by itself.

`originalAuthAmount` is never sent: CIM stores it with the payment profile and
sends it itself.

## Connect (stub)

`Connect\ConnectGatewayInterface` defines `createAccount`, `onboardingUrl`,
`refreshAccount`, `chargeOnBehalfOf`, `transfer`, `payout` and `balance`.
`Cleat::connect()` returns `NullConnectGateway`, whose methods all throw
`ConnectNotConfiguredException`. `Connect\ConnectedAccount` reads and writes
`cleat_connected_accounts` with no gateway calls. `cleat_transfers` and
`cleat_payouts` exist and are unused. Application fees are computed from
`application_fee_percent` with `Money::percentage()`, which uses integer math
and banker's rounding. Connect never routes through `AuthorizeNetGateway`.

## Testing

```bash
composer install
composer test
```

The suite runs against MariaDB or MySQL with the `FakeGateway`. It creates
and rebuilds `cleat_test` from the real migration on every run. Set
`CLEAT_TEST_DB_HOST`, `_PORT`, `_NAME`, `_USER` and `_PASS` to point it
elsewhere (defaults: `127.0.0.1`, `3306`, `cleat_test`, `root`, empty).
Tests run with MySQL 8's default strict `sql_mode`.

To try everything in a browser with no Authorize.net account:

```bash
php examples/seed.php
php -S localhost:8080 -t examples/public examples/public/router.php
```

`FakeGateway` decides outcomes by token: `tok_decline`, `tok_insufficient`,
`tok_error`, `tok_held`, `tok_timeout`, `tok_timeout_captured`, `tok_invalid`; anything else approves
(`tok_visa`, `tok_mastercard`, `tok_amex`, `tok_discover` pick a brand).
Approved and held charges return a network transaction ID. Each call is
recorded in `$gateway->calls`, with the `StoredCredential` a charge was given
under `args['stored_credential']`, so a test can assert the exact
card-on-file indicators. Set `$gateway->liveValidation = true` to make saving
a card return an original network transaction ID, as production's `liveMode`
validation does, and `$gateway->returnNetworkTransIds = false` for a processor
that returns none.

## Schema notes

`database/migrations/001_create_cleat_tables.sql` follows the original spec,
with four small additions:

- `cleat_customers.phone`: checkout can collect a phone number.
- `cleat_invoices.number` is NULL while the invoice is a draft, so abandoned
  drafts never burn a number.
- `cleat_invoice_items.invoice_id` is nullable, and `subscription_id` is
  added. A line with no invoice is a pending proration credit waiting for the
  subscription's next renewal.
- `cleat_refunds`: one row per refund or void, so partial refunds, the void
  fallback and refunds made in the merchant interface are all recorded.

All amounts are `BIGINT` cents. All timestamps are `DATETIME` written in UTC,
so the connection's time zone can never shift them.

`database/migrations/002_stored_credentials.sql` (0.1.2) adds two nullable
`VARCHAR(255)` columns, `cleat_customers.original_network_transaction_id` and
`cleat_charges.network_transaction_id` (see
[Stored credentials](#stored-credentials-card-on-file)).

`database/migrations/003_period_end_changes.sql` (0.1.3) adds two nullable
columns to `cleat_subscriptions`: `scheduled_price_id` (the price
`swapAtPeriodEnd()` scheduled) and `trial_ending_notified_at` (so the notice
goes out once).

## Upgrading

### 0.1.2 to 0.1.3

1. **Run `003_period_end_changes.sql`** (or `Schema::migrate($pdo)`). Like 002
   it only adds two nullable columns at the end of a table, an instant change
   on MySQL 8 and MariaDB 10.11, and it is safe to run twice. Until it has
   run, Cleat throws a `CleatException` before saving or charging a card.
2. **Two new emails go out by default**: the trial-ending notice and
   subscription canceled. Set `emails.trial_ending` / `emails.subscription_canceled`
   to `false` (or `trial_ending_days` to `null`) to keep 0.1.2's behaviour.
   Hosts overriding templates can add `emails/trial_ending.php` and
   `emails/subscription_canceled.php` to their `templates_path`.
3. Nothing else changes. `swap()` still swaps immediately; it now also clears
   a change scheduled with `swapAtPeriodEnd()`.

### 0.1.1 to 0.1.2

1. **Run `002_stored_credentials.sql`** (or `Schema::migrate($pdo)`). It only
   adds two nullable columns at the end of their tables, which MySQL 8 and
   MariaDB 10.11 do instantly, without rebuilding or locking the table, so it
   is safe on a live database. It is safe to run twice. Until it has run,
   Cleat throws a `CleatException` before saving or charging a card.
2. **Custom gateways only:** `GatewayInterface::chargeProfile()` has a new last
   parameter, `?StoredCredential $storedCredential = null`. Add it to your
   implementation, and send the indicators it describes.
   `GatewayResult` has a new `networkTransactionId` property, and
   `createPaymentProfile()` may return `original_network_transaction_id`.
3. Nothing else changes. Cards saved before 0.1.2 keep working. Their first
   merchant-initiated charge goes out without an original network transaction
   ID, with one notice in your log, and the ID that charge returns is kept
   for the next ones.

## Verified against the Authorize.net sandbox

Version 0.1.1 was run end to end against a live Authorize.net sandbox:
CIM profiles from real Accept.js tokens, approvals, declines, errors and
fraud holds (approved and declined), the refund → error 54 → void fallback,
`findTransaction` timeout recovery, signed webhooks, and the hosted checkout
and invoice pages in Chrome under Cleat's CSP. Version 0.1.2 added the
stored-credential indicators: every kind of charge was sent to the sandbox,
approved, and checked in Authorize.net's transaction records.
[SANDBOX.md](SANDBOX.md) has the results, the test triggers that work, and
how to repeat the pass with `examples/sandbox-check.php`.

Still unverified, and worth checking before the first live charge:

- A refund of a **settled** transaction. Sandbox batches settle once a day;
  run `php examples/sandbox-check.php --refund-settled` the day after a pass.
- `findTransaction` only sees transactions that have not settled yet, so the
  webhook remains the backstop for anything older. Configure webhooks.
- Production endpoints (`api.authorize.net`, `js.authorize.net`) and live
  card networks. The sandbox never reaches a processor.
- How a processor treats the stored-credential indicators. Version 0.1.2
  checked in the sandbox that every kind is accepted and approved and that
  Authorize.net records the original network transaction ID and
  `recurringBilling` (`php examples/sandbox-check.php --stored-credential`).
  The sandbox does not validate the indicators or pass them to a network,
  and the `liveMode` validation that provides a card's ID at save time only
  happens in production.

MySQL 8 is covered by the CI workflow in `.github/workflows/ci.yml`;
locally the suite has run on MariaDB 10.11 in MySQL 8 strict mode.

## License

MIT
