# Authorize.net sandbox pass

Cleat 0.1.0 passed its test suite against `FakeGateway` but had never talked to
a real Authorize.net account. This is the record of the first end-to-end pass
against a live sandbox, on 2026-10-07, which became 0.1.1.

| | |
|---|---|
| Gateway | Authorize.net sandbox, Live Mode (`apitest.authorize.net`, Accept.js from `jstest.authorize.net`) |
| App | `examples/` on PHP 8.2.32's built-in server, reached through a cloudflared quick tunnel (HTTPS) |
| Database | `cleat_sandbox` on MariaDB 10.11.18 |
| Browsers | Chrome (by hand, desktop) and headless Chrome at exactly 375px and 1280px |
| Tests | 144 passing after the fixes below (138 before the pass) |

## Results

Every transaction ID below is in the sandbox account. Checks 1 to 10 are
`php examples/sandbox-check.php --held-amount=600.00` (second run, after the
fixes; the first run gave the same results apart from the bugs listed later).

| Area | Result | Evidence |
|---|---|---|
| CIM customer + payment profile from opaque data | Pass | profile 528049584, payment profile 540031368; stored locally as Visa / 1111 / 2030-12 |
| Same, from a real Accept.js token in the browser | Pass | invoice CLT-000012 (save on): profile 528048858, MasterCard / 0015 / 2030-10; trial checkout: Visa / 1111 / 2031-11 |
| `chargeProfile` approve | Pass | tx 80060642342, response 1; charge `succeeded`, invoice paid |
| `chargeToken` approve, card not saved | Pass | tx 80060642343, response 1, source `one_time_token`; saved card unchanged |
| `chargeToken` from a real Accept.js token (browser, save off) | Pass | checkout CLT-000017 tx 80060640965; invoice CLT-000013 tx 80060642030 |
| Decline | Pass | ZIP 46282 on the token: tx 80060642346, response 2, reason 2; on a saved profile: tx 80060642349, response 2, reason 2; charge `failed` |
| Decline in the checkout page | Pass | CLT-000018 tx 80060641108: the page shows "Your card was declined. Please check the details or try a different card."; the gateway's "This transaction has been declined." never appears; the one-time invoice is voided |
| Error (response code 3) | Pass | expired card 01/2020: response 3, reason 8 ("The credit card has expired."), no transaction ID; charge `failed`, `GatewayResult` status error |
| Held for review (response code 4) | Pass | FDS Amount Filter: tx 80060642356 and 80060642357, response 4, reason 253; charge `held`, invoice open, no retry scheduled. In the browser: CLT-000015 and CLT-000016 showed "Payment received for review" |
| Held, then approved | Pass | 80060642356 approved via `updateHeldTransactionRequest`; `fraud.approved` ef791151… → charge `succeeded`, invoice paid. By hand in the merchant interface: CLT-000015, `fraud.approved` f86b94fa… |
| Held, then declined | Pass after fix 2 | 80060642357 declined via the API; `void.created` 845058c7… and `fraud.declined` 41944af6… → charge `failed` (`fraud_declined`), invoice open, no refund row |
| Refund of an unsettled charge → void | Pass | refund of 80060642343: response 3, reason 54 ("The referenced transaction does not meet the criteria for issuing a credit."); void: response 1; refund row `void` / `succeeded`; charge `voided` |
| `findTransaction` | Pass | CLT-000019, $3.58 → 80060642342 `approved`; voided CLT-000020 → `not_captured`; unknown invoice → no match |
| `getTransaction` | Pass | 80060642342: `capturedPendingSettlement`, CLT-000019, $3.58, card …1111 |
| Timeout recovery by `reconcile()` | Pass | response of 80060642344 discarded after a real capture; `findTransaction` found it; charge `succeeded`, reason `timeout_captured`, one charge row |
| Timeout recovery by the webhook alone | Pass | `--timeout-webhook`: response of 80060642697 discarded; `authcapture.created` 688ecb62… arrived 20s later and settled it by invoice number (`timeout_captured`, invoice paid, one charge row) |
| Payment profile deleted outside Cleat | Pass | `--profile-deleted`: payment profile 540031480 deleted at the gateway; `paymentProfile.deleted` c1869f9a… cleared the card locally |
| Webhook signature | Pass after fix 1 | 30 of 30 real signed deliveries verify with the Signature Key **hex string** as the HMAC key; 0 with `hex2bin(key)` |
| Webhooks received | Pass | 28 notifications across all six subscribed event types, every one processed, none failed |
| Duplicate delivery | Pass | real notifications 00f038ff… (`void.created`) and 5e83309f… (`authcapture.created`) replayed through the tunnel with their real signatures: `200 Duplicate notification`, one stored row each, no second refund or charge |
| Hosted pages under the CSP | Pass after fix 4 | headless Chrome, both checkouts and the invoice page at 375px and 1280px: Accept.js and AcceptCore.js load from `jstest.authorize.net` (200), `window.Accept` defined, 0 CSP violations, no horizontal scroll; full flows at both widths tokenized through `apitest.authorize.net` (200) and completed |
| Browser flows by hand | Pass | one-time checkout, decline, trial checkout (subscription 1 `trialing` until 2026-10-21, card saved, nothing charged), invoice pay with a new card saved (A) and not saved (B), with the saved card (C), and two held invoices (D, E) |

## Sandbox triggers

Checked against the Authorize.net [Testing Guide](https://developer.authorize.net/hello_world/testing_guide.html)
on 2026-10-07 and then against the sandbox itself. The sandbox must be in
**Live Mode**: Test Mode returns transaction ID 0.

| Outcome | Trigger | What the sandbox returned |
|---|---|---|
| Approve | Visa 4111 1111 1111 1111 (also Mastercard 5424 0000 0000 0015), any future expiry, CVV 123 | response 1 |
| Decline | ZIP **46282**: in Accept.js `cardData.zip`, or in the token used to create a saved profile | response 2, reason 2 |
| Error | an **expired** card, e.g. 01/2020 (tokenizing accepts it) | response 3, reason 8, transaction ID 0 |
| Held for review | Fraud Detection Suite → **Amount Filter**, upper limit $500.00, action "Authorize and hold for review"; then charge more than $500 | response 4, reason 253 |
| Approve / decline a hold | merchant interface (Suspicious Transaction Reports), or `updateHeldTransactionRequest` with `action` `approve` / `decline` | `fraud.approved`; or `void.created` followed ~4 ms later by `fraud.declined` |
| Error 54 | refund a transaction that has not settled | response 3, reason 54 |

Triggers from the brief that do **not** work in Live Mode:

- Card **4222222222222** with the dollar amount equal to a reason code. The
  Testing Guide no longer documents it, and older forum posts say it needs Test
  Mode. In Live Mode, $8.00 and $252.00 were simply approved (80060642351,
  80060642355).
- The deprecated amount triggers: $70.03 gave response 2, reason 3 and $70.05
  gave response 2, reason 35 (80060642353, 80060642352). Both are declines, not
  errors.
- No documented trigger produces response code 4 without an FDS filter.

## Bugs found and fixed

Each fix has a regression test, and each test was confirmed to fail against
0.1.0. No card data or keys appear below.

### 1. Webhook HMAC key form (`src/Webhooks/WebhookHandler.php`)

0.1.0 accepted signatures keyed with either `hex2bin(signature_key)` or the hex
string itself. Every real notification verifies only with the hex string, for
example:

```
X-ANET-Signature: sha512=<128 uppercase hex>
{"notificationId":"5e83309f-ecaf-45aa-bcf7-553ef3a0faed","eventType":"net.authorize.payment.authcapture.created","eventDate":"2026-10-07T20:46:39.4149544Z","webhookId":"6b2c4aa1-db4d-4a62-871a-69742e40c1c5","payload":{"responseCode":1,"authCode":"GLLSRQ","avsResponse":"Y","authAmount":8.68,"merchantReferenceId":"inv_11_customer_1","invoiceNumber":"CLT-000011","entityName":"transaction","id":"80060639975"}}
hex2bin(key): no match    hex string: match
```

The `hex2bin` form is removed. Tests: `WebhookTest::test_real_sandbox_notification_verifies_only_with_the_hex_string_key`
and `test_real_sandbox_authcapture_settles_a_timed_out_charge_once`, using that
notification byte for byte (`tests/fixtures/webhooks/`), re-signed with the
test key.

### 2. A declined fraud review left the charge `voided` (`src/Webhooks/WebhookHandler.php`)

Declining held transaction 80060642126 (invoice CLT-000016, $650.00) in the
merchant interface produced two notifications, the void first:

```
void.created    eventDate 21:38:08.5227  payload {"responseCode":1,"avsResponse":"Y","authAmount":650.00,"fraudList":[{"fraudFilter":"Amount Filter","fraudAction":"authAndHold"}],"invoiceNumber":"CLT-000016","entityName":"transaction","id":"80060642126"}
fraud.declined  eventDate 21:38:08.5267  payload (same)
```

`voided()` marked the held charge `voided`. Then `fraudResolved()` saw a charge
that was no longer held and did nothing. The charge kept reason 253, no
`PaymentFailed` fired, and dunning never ran. For an automatic renewal, the
invoice would have stayed open with nothing scheduled, so it would never be
retried. Now either notification settles a held charge as `failed`
(`fraud_declined`), and a void no longer overwrites a failed charge. Tests:
`test_fds_decline_with_void_first_still_runs_dunning_on_a_held_renewal` and
`test_fds_decline_with_fraud_declined_first_then_void_leaves_the_charge_failed`,
using both real notifications.

### 3. The pay page named the wrong card (`templates/pay_invoice.php`)

Invoice CLT-000013 was paid with a different Visa ending 1111, not saved (tx
80060642030, `one_time_token`, account `XXXX1111`). The receipt panel said
"Card: Mastercard ending 0015", which is the customer's saved card. It now shows
the card only when the saved card was charged, as the receipt email already
did. Test: `InvoicePayPageTest::test_receipt_names_the_saved_card_only_when_it_was_charged`.

### 4. The CSP blocked Accept.js's first request (`src/Http/HostedPage.php`)

On every page with a card form, Chrome logged:

```
Connecting to 'https://jstest.authorize.net/v1/AcceptCore.js' violates the following Content Security
Policy directive: "connect-src 'self' https://apitest.authorize.net". The action has been blocked.
```

Accept.js fetches `AcceptCore.js` from its own host first. Payments only worked
because it then falls back to a `<script>` tag. `connect-src` now includes the
Accept.js host (`jstest.authorize.net`, or `js.authorize.net` in production).
After the fix, the headless pass logged 0 CSP violations. Test:
`InvoicePayPageTest::test_csp_lets_accept_js_fetch_its_core_from_its_own_host`.

Outside `src/`: `examples/seed.php` read `CLEAT_DB_DSN` with `getenv()` and
ignored `examples/.env`, so with sandbox keys it created `cleat_demo` while the
bootstrap connected to `cleat_sandbox`. It now reads the same settings as the
bootstrap.

## Behaviour worth knowing

- **Webhook latency** ranged from about 15 seconds to about 8 minutes. Some
  first deliveries arrived only on Authorize.net's 3-minute retry. Size any
  wait for the webhook accordingly. Cleat's `reconcile()` does not depend on
  it.
- **The webhook ping does not work** against the tunnel. Twice it returned
  `500 PING_FAILED`, "Error occured in connecting to the endpoint", and no
  request arrived, while real notifications to the same URL were delivered.
  Pings also only go to an inactive webhook.
- **The unsettled transaction list lags a void.** For about 13 seconds after a
  void, `getUnsettledTransactionListRequest` still showed the transaction as
  `capturedPendingSettlement`. After that it showed `voided` with
  `settleAmount` 0.00, and `findTransaction` reported `not_captured`.
- **Declines send no webhook.** None of the 9 declined transactions produced a
  notification. Held transactions send no `authcapture.created`: only
  `fraud.approved`, or `void.created` plus `fraud.declined`.
- **Notification payloads** carry `invoiceNumber`, and `merchantReferenceId`,
  which is Cleat's idempotency key (the `refId`). `authAmount` is a JSON number
  (`8.68`, `600.00`). CIM reports the brand as `MasterCard`, which Cleat labels
  "Mastercard".
- **Tunnel artifacts.** Cloudflare's quick tunnel injects a same-origin
  `/cdn-cgi/.../email-decode.min.js`, which `script-src 'self'` allows. The
  example app has no `favicon.ico`, so browsers log a 404. Neither comes from
  Cleat.

## Stored credentials (0.1.2)

Cleat 0.1.2 sends card-on-file indicators on every charge of a saved card.
They were checked against the same sandbox account on 2026-10-07 and 08 with
`php examples/sandbox-check.php --stored-credential`. The script captures each
request on the wire and keeps only its card-on-file fields, and keeps only
the codes and IDs of each response. Afterwards it asks Authorize.net what it
recorded (`getTransactionDetailsRequest`).

Results of the final run (run 002213, 2026-10-08 00:22 UTC): 7 of 7 pass.
The request column is the card-on-file part of each request, exactly as
sent. Every charge row stored the `networkTransId` it got back.

| Check | Request (card-on-file fields) | Response | Result |
|---|---|---|---|
| 1. First CIT: `newSubscription()->create($card)` | `processingOptions.isFirstRecurringPayment: true` | 1, tx 80060647671, `networkTransId` XKUQ600PGWLL9PUPD7SCZUV | Pass: charge 47 and customer 18's `original_network_transaction_id` hold that ID |
| 2. MIT recurring (`Runner::charge()`'s call) | `transactionSettings` `recurringBilling=true`, `processingOptions.isSubsequentAuth: true`, `subsequentAuthInformation.originalNetworkTransId` XKUQ600PGWLL9PUPD7SCZUV | 1, tx 80060647672, `networkTransId` B7ELVCYQRYK6ZLIF02KAPA4 | Pass |
| 3. MIT resubmission (`Dunning::retry()`'s call) | `isSubsequentAuth: true`, `originalNetworkTransId` XKUQ600PGWLL9PUPD7SCZUV, `reason: resubmission` | 1, tx 80060647674, `networkTransId` 5STJ8W1R6XRAHO12WVEAFEQ | Pass |
| 4. Later CIT: `payWithSavedCard()` | `isStoredCredentials: true` | 1, tx 80060647676 | Pass: the card keeps its original ID |
| 5. MIT recurring, no original ID on file | `recurringBilling=true`, `isSubsequentAuth: true`, no `subsequentAuthInformation` | 1, tx 80060647680, `networkTransId` 1NX81SITUL46LG9UOCWRPRU | Pass: approved; one `info` notice for customer 19; that ID became the card's original ID |
| 6. One-time token, not saved | none | 1, tx 80060647682, `networkTransId` K44IDTWPPIJC7S244G9112P | Pass: stored on charge 52 only |
| 7. What Authorize.net recorded | | 80060647672: `originalNetworkTransId` XKUQ600PGWLL9PUPD7SCZUV, `recurringBilling` true; 80060647674: `originalNetworkTransId` XKUQ600PGWLL9PUPD7SCZUV | Pass |

An earlier run (231724), made before the recurring MIT gained
`isSubsequentAuth`, passed checks 1 to 6 the same way (first CIT 80060645184,
recurring MIT 80060645185, resubmission 80060645186). It is how the gap
described next was found.

### How a recurring MIT is sent

Authorize.net's [card-on-file guide](https://developer.authorize.net/api/reference/features/card-on-file.html)
shows a recurring merchant-initiated charge as `transactionSettings`
`recurringBilling=true` plus `subsequentAuthInformation.originalNetworkTransId`,
with no `processingOptions`. The sandbox records that form without the
original ID. Three renewals of the same card, each sent with the original ID
8177XDZDCSIYEXH16VYNABE (from first CIT 80060645184), were read back with
`getTransactionDetailsRequest`:

| Sent | Transaction | Recorded `recurringBilling` | Recorded `originalNetworkTransId` |
|---|---|---|---|
| `recurringBilling` + ID (the guide) | 80060645233 | true | **none** |
| `isSubsequentAuth` + ID | 80060645240 | false | 8177XDZDCSIYEXH16VYNABE |
| `recurringBilling` + `isSubsequentAuth` + ID | 80060645243 | true | 8177XDZDCSIYEXH16VYNABE |

Cleat sends the third form, which is the only one where the record keeps
both. It also matches Authorize.net's newer knowledge-base articles
(KA-10435, 000002704), which pair `isSubsequentAuth` with the MIT fields. The
sandbox validates none of these fields and never reaches a processor, so this
shows what Authorize.net records, not what a network receives.

### Migration 002 on this live 0.1.1 database

`cleat_sandbox` held the data of the 0.1.1 pass: 13 customers, 35 invoices,
34 charges, 1 subscription, 2 refunds and 28 webhook events.
`mariadb cleat_sandbox < database/migrations/002_stored_credentials.sql` was
run twice. Both runs exited 0, and the second changed nothing. Every table's
row count and a CRC32 checksum over every row's existing columns were the
same before and after. On MariaDB 10.11.18, MySQL 8.0.40 and MySQL 8.4.3, a
fresh 0.1.1 schema accepted both of 002's `ADD COLUMN`s with
`ALGORITHM=INSTANT`, so they are metadata-only changes.

### Worth knowing

- **Every approved transaction returns `networkTransId`**, one-time tokens
  included, as a 23-character alphanumeric string. Held transactions return
  none.
- **CIM in the sandbox keeps no original ID** on the payment profile:
  `getCustomerPaymentProfileRequest` showed no `originalNetworkTransId`, even
  after the first charge. Cards are saved in `testMode` here; production's
  `liveMode` validation is what the guide says provides it.
- **`getTransactionDetails` shows `originalNetworkTransId` only when
  `isSubsequentAuth` was sent** (see the table above).
- **The Transaction IP Velocity Filter holds charges.** A second run
  started two minutes after the first, with a test of the three forms in
  between. Three of its charges, 80060645281, 80060645282 and 80060645285,
  came back with response 4 and reason 252. `FDSFilters` named "Transaction
  IP Velocity Filter", which counts transactions per hour from the API
  caller's IP. The one-time token in the same run passed because it carries
  `customerIP`. That run is not part of the results. Its held transactions
  were left in review, and their charges are `held` in `cleat_sandbox`. Leave
  an hour between runs.

## Not verified

These are open items, not passes:

- **Refund of a settled transaction.** Sandbox batches settle once a day, so
  `refundTransaction` on a settled charge can only be tested the next day. Run
  `php examples/sandbox-check.php --refund-settled`. It refunds half of the
  first settled charge and then the rest, and expects two `refund`
  transactions, not a void, with the charge ending `refunded`. Settled
  candidates from this pass: 80060639946 ($9.24) and 80060642342 ($3.58).
  `refund.created` was subscribed but never fired, so it is unverified too.
- **A partial refund of an unsettled charge.** It should fail with error 54
  and no void. Covered by unit tests, not run live.
- **Automatic renewals and dunning against the sandbox.** The Runner never
  charged a renewal live. The trial from this pass (subscription 1) converts
  on 2026-10-21. The held-renewal path is covered by a test built on the real
  notifications.
- **A real duplicate delivery by Authorize.net.** None happened. Duplicates
  were tested by replaying captured notifications.
- **Production**: `api.authorize.net`, `js.authorize.net` and real card
  networks. The sandbox never reaches a processor.
- **Browsers other than Chrome**, and real mobile devices. The 375px pass was
  Chrome's device emulation.
- **Stored-credential indicators at a processor.** The sandbox accepts and
  records them but does not validate them. The `liveMode` validation that
  gives a card its original ID at save time, and the processors Authorize.net
  supports COF on, are production only. The runner's own renewal and the
  dunning schedule were not run live; `--stored-credential` makes the same
  `pay()` calls they make.

## Repeating the pass

1. Copy the sandbox keys into `examples/.env` (gitignored): `AUTHORIZENET_LOGIN_ID`,
   `AUTHORIZENET_TRANSACTION_KEY`, `AUTHORIZENET_SIGNATURE_KEY` and
   `AUTHORIZENET_CLIENT_KEY`, with `AUTHORIZENET_SANDBOX=true`.
   Optionally set `CLEAT_WEBHOOK_CAPTURE_DIR` to a folder outside the repo.
2. `php examples/seed.php` (creates and resets `cleat_sandbox`).
3. `php -S localhost:8080 -t examples/public examples/public/router.php`
   and `cloudflared tunnel --url http://localhost:8080`. Put the tunnel's
   https URL in `CLEAT_BASE_URL`.
4. `php examples/sandbox-check.php --webhook-register`. The endpoint is
   `/webhooks/authorizenet`.
5. In the merchant interface, set up the FDS Amount Filter described under
   Sandbox triggers.
6. `php examples/sandbox-check.php --held-amount=600.00`, then `--timeout-webhook`
   and `--profile-deleted`. `--stored-credential` checks the card-on-file
   indicators; give it an hour after other runs (Transaction IP Velocity
   Filter).
7. `php examples/sandbox-check.php --browser-setup` prints the checkout links and
   three fresh invoices for the browser flows. Afterwards, `--report` shows what
   Cleat recorded.
8. `--hmac-check` and `--replay=<captured file>` work on the captured
   notifications.
9. `php examples/sandbox-check.php --webhook-list`, then `--webhook-delete=<id>`.
   The quick-tunnel URL changes on every run.

The runner refuses to start unless `AUTHORIZENET_SANDBOX=true`, the gateway is
the sandbox, and the connected database is `cleat_sandbox`. It never prints the
transaction, signature or client key, opaque data, or a full card number.
