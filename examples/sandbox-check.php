<?php

declare(strict_types=1);

/*
 * End-to-end checks against the Authorize.net SANDBOX for everything that
 * needs no browser. Reads examples/.env (see env.php). Refuses to run unless
 * AUTHORIZENET_SANDBOX=true, and only ever talks to apitest.authorize.net and
 * the cleat_sandbox database.
 *
 *   php examples/sandbox-check.php                       the scripted checks
 *   php examples/sandbox-check.php --held-amount=600.00  also expect "held for review" at this
 *                                                        amount (needs an FDS Amount Filter)
 *   php examples/sandbox-check.php --refund-settled      refund a charge that has settled (a day later)
 *   php examples/sandbox-check.php --timeout-webhook[=SECONDS]  a lost response settled by its webhook
 *                                                        alone (sandbox delivery can take ~10 minutes)
 *   php examples/sandbox-check.php --profile-deleted[=SECONDS]  a payment profile deleted at the gateway
 *                                                        is cleared locally by its webhook
 *   php examples/sandbox-check.php --stored-credential   card-on-file indicators: first CIT, MITs with the
 *                                                        original network transaction id, later CIT, no id,
 *                                                        one-time token. Prints each request's card-on-file
 *                                                        fields as sent and the ids that came back
 *
 *   php examples/sandbox-check.php --browser-setup       invoices and checkout links for the browser pass
 *   php examples/sandbox-check.php --report              latest customers, charges, subscriptions, refunds
 *                                                        and webhook events in cleat_sandbox
 *
 *   php examples/sandbox-check.php --webhook-register    register CLEAT_BASE_URL/webhooks/authorizenet
 *   php examples/sandbox-check.php --webhook-ping=ID     send a test notification
 *   php examples/sandbox-check.php --webhook-list
 *   php examples/sandbox-check.php --webhook-delete=ID
 *   php examples/sandbox-check.php --notifications       recent deliveries, from Authorize.net
 *   php examples/sandbox-check.php --hmac-check          which signature key form verifies the
 *                                                        captured notifications
 *   php examples/sandbox-check.php --replay=FILE         deliver a captured notification again
 *
 * Captured notifications are the files CLEAT_WEBHOOK_CAPTURE_DIR collects (see
 * public/webhook.php).
 *
 * Test card data is tokenized here with securePaymentContainerRequest, the
 * same API call Accept.js makes in the browser, so every check below runs
 * through Cleat's real opaque-data paths. That helper exists only in this
 * script: Cleat itself never accepts a card number. Card numbers are printed
 * masked; keys and opaque data are never printed.
 */

use Cleat\Charge;
use Cleat\Cleat;
use Cleat\Customer;
use Cleat\Enums\ChargeStatus;
use Cleat\Exceptions\CleatException;
use Cleat\Exceptions\PaymentFailedException;
use Cleat\Exceptions\RefundFailedException;
use Cleat\Gateway\AuthorizeNetGateway;
use Cleat\Gateway\GatewayInterface;
use Cleat\Gateway\GatewayResult;
use Cleat\Gateway\StoredCredential;
use Cleat\Invoice;
use Cleat\Price;
use Cleat\Product;
use Cleat\Subscription;
use Cleat\Support\Db;
use Cleat\Support\Log;
use Cleat\Support\Schema;

require_once __DIR__ . '/env.php';

const SANDBOX_API = 'https://apitest.authorize.net/xml/v1/request.api';
const SANDBOX_REST = 'https://apitest.authorize.net/rest/v1';
const WEBHOOK_PATH = '/webhooks/authorizenet';
const WEBHOOK_EVENTS = [
    'net.authorize.payment.authcapture.created',
    'net.authorize.payment.refund.created',
    'net.authorize.payment.void.created',
    'net.authorize.payment.fraud.approved',
    'net.authorize.payment.fraud.declined',
    'net.authorize.customer.paymentProfile.deleted',
];
const TEST_IP = '203.0.113.10'; // documentation range

// --- Safety: sandbox only, cleat_sandbox only ----------------------------------

if (!example_uses_authorizenet()) {
    fail_hard('AUTHORIZENET_LOGIN_ID is empty. Fill in the sandbox keys in examples/.env first.');
}
example_require_sandbox();
foreach (['AUTHORIZENET_TRANSACTION_KEY', 'AUTHORIZENET_CLIENT_KEY', 'AUTHORIZENET_SIGNATURE_KEY'] as $name) {
    if (example_env($name) === '') {
        fail_hard("$name is empty in examples/.env.");
    }
}

require __DIR__ . '/bootstrap.php';

if (!Cleat::isSandbox() || !(Cleat::gateway() instanceof AuthorizeNetGateway) || AuthorizeNetGateway::SANDBOX_URL !== SANDBOX_API) {
    fail_hard('Cleat is not configured for the Authorize.net sandbox. Refusing to run.');
}
if (Db::value('SELECT DATABASE()') !== 'cleat_sandbox') {
    fail_hard('This script only uses the cleat_sandbox database (check CLEAT_DB_DSN). Refusing to run.');
}
Schema::migrate(Cleat::pdo()); // every migration is safe to run again

Log::setLogger(static function (string $level, string $message, array $context): void {
    // Cleat redacts contexts itself; they never hold request bodies or keys.
    out(sprintf('      log.%s: %s %s', $level, $message, $context === [] ? '' : json_encode($context, JSON_UNESCAPED_SLASHES)));
});

// The dispatch is at the end of the file, after RecordingGateway is declared.

// --- The scripted checks ------------------------------------------------------------

function run_checks(RecordingGateway $gw, ?int $heldAmount): int
{
    $run = gmdate('His');
    $results = [];
    $check = static function (string $id, string $name, ?bool $pass, string $evidence) use (&$results): void {
        $results[] = [$id, $name, $pass, $evidence];
        out(sprintf('  [%s] %s: %s', $pass === null ? 'INFO' : ($pass ? 'PASS' : 'FAIL'), $name, $evidence));
    };
    $transactions = [];

    out("Cleat sandbox check, run $run, " . gmdate('Y-m-d H:i:s') . ' UTC');

    // 1. CIM customer profile + payment profile from opaque data.
    section('1. Customer profile and payment profile');
    $customer = Customer::create(['email' => "sandbox+$run@example.com", 'name' => 'Sandbox Check']);
    $customer->updatePaymentMethod(sandbox_token('4111111111111111', '12', '2030', '123'));
    $row = Db::one('SELECT gateway_customer_id, gateway_payment_id, card_brand, card_last_four, card_exp FROM cleat_customers WHERE id = ?', [$customer->id]);
    $check('1', 'CIM profile + payment profile', $row['gateway_customer_id'] !== null && $row['gateway_payment_id'] !== null
        && $row['card_brand'] === 'Visa' && $row['card_last_four'] === '1111' && $row['card_exp'] === '2030-12',
        sprintf('customer %d: profile %s, payment profile %s, stored %s / %s / %s',
            $customer->id, $row['gateway_customer_id'], $row['gateway_payment_id'], $row['card_brand'], $row['card_last_four'], $row['card_exp']));
    $customer = $customer->refresh();

    // 2. chargeProfile, approved.
    section('2. chargeProfile approve (Visa 4111, stored profile)');
    $approveAmount = 100 + random_int(1, 899);
    $approveInvoice = invoice_for($customer, 'Sandbox approve', $approveAmount);
    attempt(fn () => $approveInvoice->pay());
    $c = charge_row($approveInvoice);
    $transactions['approve (chargeProfile)'] = $c['gateway_transaction_id'];
    $check('2', 'chargeProfile approve', $c['status'] === 'succeeded' && (int) $c['response_code'] === 1 && $c['source'] === 'stored_profile'
        && $c['gateway_transaction_id'] !== null && $approveInvoice->refresh()->status->value === 'paid' && $gw->last('chargeProfile')?->isApproved() === true,
        charge_evidence($c, $approveInvoice));

    // 3. chargeToken, approved (one-time card, nothing saved).
    section('3. chargeToken approve (one-time token, card not saved)');
    $tokenInvoice = invoice_for($customer, 'Sandbox one-time card', 100 + random_int(1, 899));
    $paymentId = $customer->gateway_payment_id;
    attempt(fn () => $tokenInvoice->payWithToken(sandbox_token('4111111111111111', '11', '2031', '123'), false, TEST_IP));
    $t = charge_row($tokenInvoice);
    $transactions['approve (chargeToken)'] = $t['gateway_transaction_id'];
    $check('3', 'chargeToken approve, card not saved', $t['status'] === 'succeeded' && (int) $t['response_code'] === 1 && $t['source'] === 'one_time_token'
        && $customer->refresh()->gateway_payment_id === $paymentId,
        charge_evidence($t, $tokenInvoice) . '; saved card unchanged');

    // Started here, checked in 9: a charge that reaches Authorize.net but whose
    // answer is thrown away, exactly like a timeout. Sandbox webhooks take from
    // seconds to ~4 minutes, so the rest of the run is the waiting time.
    section('3b. Lost response (checked in 9)');
    $lostInvoice = invoice_for($customer, 'Sandbox lost response', 100 + random_int(1, 899));
    $gw->dropNextResponse = true;
    attempt(fn () => $lostInvoice->payWithToken(sandbox_token('4111111111111111', '08', '2030', '123'), false, TEST_IP));
    $lostAt = time();
    $realTx = $gw->droppedTransactionId;
    $transactions['timeout recovery'] = $realTx;
    $lost = charge_row($lostInvoice);
    out(sprintf('    recorded as %s / %s; the real transaction is %s', $lost['status'], $lost['reason_code'], $realTx));

    // 4. Declines. ZIP 46282 is the Testing Guide's decline trigger.
    section('4. Decline (ZIP 46282)');
    $declineInvoice = invoice_for($customer, 'Sandbox decline', 100 + random_int(1, 899));
    [, $e] = attempt(fn () => $declineInvoice->payWithToken(sandbox_token('4111111111111111', '10', '2030', '123', '46282'), false, TEST_IP));
    $d = charge_row($declineInvoice);
    $transactions['decline (chargeToken, ZIP 46282)'] = $d['gateway_transaction_id'];
    $check('4a', 'decline via chargeToken mapped', $e instanceof PaymentFailedException && $e->result->isDeclined()
        && $d['status'] === 'failed' && (int) $d['response_code'] === 2,
        charge_evidence($d, $declineInvoice) . ($e instanceof PaymentFailedException ? '; customer sees: "' . $e->customerMessage() . '"' : ''));
    $check('4b', 'customer message is generic', $e instanceof PaymentFailedException
        && !str_contains($e->customerMessage(), (string) $e->result->reasonText),
        'gateway text "' . ($e?->result->reasonText ?? '') . '" is not in the customer message');

    $zipCustomer = Customer::create(['email' => "sandbox+$run-zip@example.com", 'name' => 'Sandbox Decline']);
    $zipCustomer->updatePaymentMethod(sandbox_token('4111111111111111', '09', '2030', '123', '46282'));
    $zipInvoice = invoice_for($zipCustomer->refresh(), 'Sandbox decline (profile)', 100 + random_int(1, 899));
    attempt(fn () => $zipInvoice->pay());
    $z = charge_row($zipInvoice);
    $transactions['decline (chargeProfile, ZIP 46282)'] = $z['gateway_transaction_id'];
    $check('4c', 'decline via chargeProfile (ZIP 46282 on the saved card)', (int) $z['response_code'] === 2 ? $z['status'] === 'failed' : null,
        charge_evidence($z, $zipInvoice));

    // 5. Error (response code 3). The Testing Guide documents no current trigger;
    // these are the candidates, each reported with what the sandbox returned.
    section('5. Error (response code 3) candidates');
    $errorCandidates = [
        'expired card 01/2020' => ['4111111111111111', '01', '2020', 101],
        'card 4222222222222, $8.00 (legacy: amount = reason code 8)' => ['4222222222222', '12', '2030', 800],
        '$70.05 (deprecated amount trigger)' => ['4111111111111111', '12', '2030', 7005],
        '$70.03 (deprecated amount trigger)' => ['4111111111111111', '12', '2030', 7003],
    ];
    $sawError = false;
    foreach ($errorCandidates as $label => [$card, $month, $year, $cents]) {
        $outcome = probe($customer, "Sandbox error probe: $label", $card, $month, $year, $cents);
        if ($outcome['charge'] !== null) {
            $transactions["probe: $label"] = $outcome['charge']['gateway_transaction_id'];
        }
        $code = $outcome['charge']['response_code'] ?? null;
        if ($code !== null && (int) $code === 3) {
            $sawError = true;
            $check('5', "error mapped ($label)", $outcome['charge']['status'] === 'failed' && $outcome['result']?->isError() === true,
                charge_evidence($outcome['charge'], $outcome['invoice']));
        } else {
            $check('5', "candidate $label", null, $outcome['summary']);
        }
    }
    if (!$sawError) {
        $check('5', 'error (code 3) on a charge', null, 'no candidate produced response code 3 on a charge; see the refund (error 54) in check 7 for a code 3 transaction response');
    }

    // 6. Held for review (response code 4). Only the FDS filters hold in the
    // sandbox. Two held charges: one approved, one declined through
    // updateHeldTransactionRequest (what the merchant interface's buttons
    // do); their outcomes are checked in 10, after the webhooks arrive.
    section('6. Held for review (response code 4)');
    $legacy = probe($customer, 'Sandbox held probe: card 4222222222222, $252.00 (legacy: amount = reason code 252)', '4222222222222', '12', '2030', 25200);
    $transactions['probe: card 4222222222222, $252.00'] = $legacy['charge']['gateway_transaction_id'] ?? null;
    $check('6', 'candidate card 4222222222222, $252.00 (legacy: amount = reason code 252)', null, $legacy['summary']);
    $held = [];
    if ($heldAmount === null) {
        $check('6', 'held (code 4)', null, 'skipped. Set up Fraud Detection Suite > Amount Filter (action: authorize and hold for review) and rerun with --held-amount=<amount above the limit>');
    }
    foreach ($heldAmount === null ? [] : ['approve' => $heldAmount, 'decline' => $heldAmount + 50] as $action => $cents) {
        $outcome = probe($customer, "Sandbox held for review, to $action", '4111111111111111', '12', '2030', $cents);
        $row = $outcome['charge'];
        $inv = $outcome['invoice'];
        $transactions["held, then $action"] = $row['gateway_transaction_id'] ?? null;
        $check('6', "held mapped (FDS Amount Filter, " . money($cents) . ')', $row !== null && (int) $row['response_code'] === 4 && $row['status'] === 'held'
            && $inv->status->value === 'open' && $inv->next_attempt_at === null, $outcome['summary']);
        if ($row !== null && $row['status'] === 'held') {
            $r = api('updateHeldTransactionRequest', [
                'merchantAuthentication' => ['name' => example_env('AUTHORIZENET_LOGIN_ID'), 'transactionKey' => example_env('AUTHORIZENET_TRANSACTION_KEY')],
                'heldTransactionRequest' => ['action' => $action, 'refTransId' => $row['gateway_transaction_id']],
            ]);
            out(sprintf('      updateHeldTransaction %s %s -> %s %s, response %s', $action, $row['gateway_transaction_id'], $r['messages']['resultCode'] ?? '?',
                $r['messages']['message'][0]['code'] ?? '', $r['transactionResponse']['responseCode'] ?? '-'));
            $held[$action] = ['row' => $row, 'invoice' => $inv];
        }
    }

    // 7. Refund an unsettled charge: error 54, then the void fallback.
    section('7. Refund on an unsettled charge (expect error 54, then void)');
    $tokenCharge = Charge::findOrFail((int) $t['id']);
    $gw->mark();
    try {
        $refund = $tokenCharge->refund();
        $calls = $gw->since();
        $first = $calls[0] ?? null;
        $second = $calls[1] ?? null;
        $tokenCharge = Charge::findOrFail($tokenCharge->id);
        $check('7', 'refund -> error 54 -> void', $first !== null && $first['call'] === 'refund' && $first['result']->reasonCode === '54'
            && (int) $first['result']->responseCode === 3 && $second !== null && $second['call'] === 'void' && $second['result']->isApproved()
            && $refund->method->value === 'void' && $refund->status->value === 'succeeded' && $tokenCharge->status === ChargeStatus::Voided,
            sprintf('refund on %s: response %s reason %s ("%s"); void: response %s, tx %s; refund row %d method=%s status=%s; charge %d now %s',
                $t['gateway_transaction_id'], $first['result']->responseCode ?? '-', $first['result']->reasonCode ?? '-', $first['result']->reasonText ?? '',
                $second['result']->responseCode ?? '-', $second['result']->transactionId ?? '-', $refund->id, $refund->method->value,
                $refund->status->value, $tokenCharge->id, $tokenCharge->status->value));
    } catch (RefundFailedException $e) {
        $check('7', 'refund -> error 54 -> void', false, $e->getMessage());
    }

    // 8. findTransaction (timeout recovery lookup) and getTransaction.
    section('8. findTransaction / getTransaction');
    $since = Cleat::now()->modify('-1 hour');
    $found = $gw->findTransaction((string) $approveInvoice->number, $approveAmount, $since);
    $check('8a', 'findTransaction finds a captured charge', $found->success && $found->get('transaction_id') === $c['gateway_transaction_id'] && $found->get('status') === 'approved',
        sprintf('invoice %s, %s: transaction %s, status %s', $approveInvoice->number, money($approveAmount), $found->get('transaction_id') ?? 'none', $found->get('status')));
    // The unsettled list lags a void by a few seconds (seen in the sandbox), so poll.
    $voidedAt = microtime(true);
    for ($i = 0; $i < 12; $i++) {
        $voided = $gw->findTransaction((string) $tokenInvoice->number, (int) $t['amount'], $since);
        if (!$voided->success || $voided->get('status') === 'not_captured') {
            break;
        }
        sleep(5);
    }
    $check('8b', 'findTransaction does not count a voided charge as captured', $voided->success && $voided->get('status') === 'not_captured',
        sprintf('invoice %s (voided): transaction %s, status %s, after %.0fs of list lag', $tokenInvoice->number, $voided->get('transaction_id') ?? 'none', $voided->get('status'), microtime(true) - $voidedAt));
    $missing = $gw->findTransaction('CLT-NOPE-' . $run, 12345, $since);
    $check('8c', 'findTransaction with no match', $missing->success && $missing->get('transaction_id') === null, 'unknown invoice number: transaction_id null');
    $details = $gw->getTransaction((string) $c['gateway_transaction_id']);
    $check('8d', 'getTransaction', $details->success && $details->get('invoice_number') === $approveInvoice->number
        && $details->get('amount') === $approveAmount && $details->get('card_last_four') === '1111',
        sprintf('%s: status %s, invoice %s, %s, card ...%s', $c['gateway_transaction_id'], $details->get('status'), $details->get('invoice_number'), money((int) $details->get('amount', 0)), $details->get('card_last_four')));

    // 9. Timeout recovery: the authcapture webhook (or, failing that,
    // reconcile()) must settle the lost charge from 3b without charging again.
    section('9. Timeout recovery (response discarded after a real capture)');
    out(sprintf('    waiting up to 5 minutes from the charge for the authcapture webhook of %s', $realTx));
    $settledBy = null;
    while ($settledBy === null && time() - $lostAt < 300) {
        $lost = charge_row($lostInvoice);
        if ($lost['status'] === 'succeeded') {
            $settledBy = 'the authcapture webhook';
            break;
        }
        sleep(5);
    }
    if ($settledBy === null) {
        $reconciled = $lostInvoice->refresh()->reconcile();
        $lost = charge_row($lostInvoice);
        $settledBy = $reconciled !== null ? 'reconcile()' : 'nothing';
    }
    $check('9', "timed-out charge settled by $settledBy", $lost['status'] === 'succeeded' && $lost['gateway_transaction_id'] === $realTx
        && $lost['reason_code'] === 'timeout_captured' && $lostInvoice->refresh()->status->value === 'paid'
        && (int) Db::value('SELECT COUNT(*) FROM cleat_charges WHERE invoice_id = ?', [$lostInvoice->id]) === 1,
        charge_evidence($lost, $lostInvoice) . ', one charge row');

    // 10. Signed webhooks for this run's transactions.
    section('10. Webhooks received for this run');
    $expected = array_filter([
        [$transactions['approve (chargeProfile)'], 'net.authorize.payment.authcapture.created'],
        [$transactions['approve (chargeToken)'], 'net.authorize.payment.authcapture.created'],
        [$transactions['approve (chargeToken)'], 'net.authorize.payment.void.created'],
        [$transactions['timeout recovery'], 'net.authorize.payment.authcapture.created'],
        [$transactions['held, then approve'] ?? null, 'net.authorize.payment.fraud.approved'],
        [$transactions['held, then decline'] ?? null, 'net.authorize.payment.fraud.declined'],
        [$transactions['held, then decline'] ?? null, 'net.authorize.payment.void.created'],
    ], static fn ($pair) => $pair[0] !== null);
    $events = [];
    for ($i = 0; $i < 60; $i++) {
        $events = webhook_events(array_values(array_filter($transactions)));
        $missing = array_filter($expected, static fn ($pair) => !isset($events[$pair[0]][$pair[1]]));
        if ($missing === []) {
            break;
        }
        sleep(5);
    }
    foreach ($expected as [$tx, $type]) {
        $ev = $events[$tx][$type] ?? null;
        $check('10', short_event($type) . " for $tx", $ev !== null && $ev['processed_at'] !== null,
            $ev === null ? 'not received within 5 minutes' : sprintf('notification %s, processed %s', $ev['id'], $ev['processed_at']));
    }
    foreach ($transactions as $label => $tx) {
        foreach ($events[$tx] ?? [] as $type => $ev) {
            if (!in_array([$tx, $type], $expected, true)) {
                $check('10', short_event($type) . " for $tx ($label)", null, sprintf('notification %s, processed %s', $ev['id'], $ev['processed_at'] ?? 'no'));
            }
        }
    }
    if (isset($held['approve'])) {
        $row = Db::one('SELECT * FROM cleat_charges WHERE id = ?', [$held['approve']['row']['id']]);
        $check('6b', 'held, approved in review -> succeeded', $row['status'] === 'succeeded' && $held['approve']['invoice']->refresh()->status->value === 'paid',
            charge_evidence($row, $held['approve']['invoice']));
    }
    if (isset($held['decline'])) {
        $row = Db::one('SELECT * FROM cleat_charges WHERE id = ?', [$held['decline']['row']['id']]);
        $check('6c', 'held, declined in review -> failed (fraud_declined), not voided', $row['status'] === 'failed' && $row['reason_code'] === 'fraud_declined'
            && $held['decline']['invoice']->refresh()->status->value === 'open' && (int) Db::value('SELECT COUNT(*) FROM cleat_refunds WHERE charge_id = ?', [$row['id']]) === 0,
            charge_evidence($row, $held['decline']['invoice']));
    }

    section('Summary');
    $failed = 0;
    foreach ($results as [$id, $name, $pass]) {
        out(sprintf('  %-4s %-4s %s', $id, $pass === null ? 'INFO' : ($pass ? 'PASS' : 'FAIL'), $name));
        $failed += $pass === false ? 1 : 0;
    }
    out('');
    out('Transactions this run (keep the approved chargeProfile one for --refund-settled tomorrow):');
    foreach ($transactions as $label => $tx) {
        out(sprintf('  %-60s %s', $label, $tx ?? '-'));
    }
    out($failed === 0 ? "\nAll checks passed." : "\n$failed check(s) failed.");
    return $failed === 0 ? 0 : 1;
}

/** A charge whose response is lost, settled by its authcapture webhook alone (no reconcile()). */
function timeout_webhook(RecordingGateway $gw, int $wait): int
{
    section('Timeout recovery by the authcapture webhook alone');
    $customer = Customer::create(['email' => 'sandbox+' . gmdate('His') . '-timeout@example.com', 'name' => 'Sandbox Timeout']);
    $invoice = invoice_for($customer, 'Sandbox lost response (webhook only)', 100 + random_int(1, 899));
    $gw->dropNextResponse = true;
    attempt(fn () => $invoice->payWithToken(sandbox_token('4111111111111111', '07', '2030', '123'), false, TEST_IP));
    $tx = $gw->droppedTransactionId;
    $start = time();
    out(sprintf('    real transaction %s; waiting up to %ds for its authcapture webhook', $tx, $wait));
    do {
        sleep(10);
        $row = charge_row($invoice);
    } while ($row['status'] !== 'succeeded' && time() - $start < $wait);
    $pass = $row['status'] === 'succeeded' && $row['gateway_transaction_id'] === $tx && $row['reason_code'] === 'timeout_captured'
        && $invoice->refresh()->status->value === 'paid' && count($invoice->charges()) === 1;
    $event = webhook_events([(string) $tx])[(string) $tx]['net.authorize.payment.authcapture.created'] ?? null;
    out(sprintf('  [%s] after %ds: %s; notification %s', $pass ? 'PASS' : 'FAIL', time() - $start, charge_evidence($row, $invoice), $event['id'] ?? 'none'));
    return $pass ? 0 : 1;
}

/** A payment profile deleted outside Cleat (as in the merchant interface): the webhook clears the card. */
function profile_deleted(GatewayInterface $gw, int $wait): int
{
    section('Payment profile deleted outside Cleat');
    $customer = Customer::create(['email' => 'sandbox+' . gmdate('His') . '-delete@example.com', 'name' => 'Sandbox Delete']);
    $customer->updatePaymentMethod(sandbox_token('4111111111111111', '06', '2030', '123'));
    $customer = $customer->refresh();
    $paymentProfileId = (string) $customer->gateway_payment_id;
    $deleted = $gw->deletePaymentProfile((string) $customer->gateway_customer_id, $paymentProfileId);
    if (!$deleted->success) {
        out('  [FAIL] the gateway did not delete the payment profile');
        return 1;
    }
    $start = time();
    out(sprintf('    deleted payment profile %s at the gateway; waiting up to %ds for the webhook', $paymentProfileId, $wait));
    do {
        sleep(10);
        $customer = $customer->refresh();
    } while ($customer->hasPaymentMethod() && time() - $start < $wait);
    $event = webhook_events([$paymentProfileId])[$paymentProfileId]['net.authorize.customer.paymentProfile.deleted'] ?? null;
    $pass = !$customer->hasPaymentMethod() && $customer->card_last_four === null && $event !== null;
    out(sprintf('  [%s] after %ds: customer %d card %s; notification %s', $pass ? 'PASS' : 'FAIL', time() - $start, $customer->id,
        $customer->paymentMethodLabel() ?? 'cleared', $event['id'] ?? 'none'));
    return $pass ? 0 : 1;
}

/**
 * Card-on-file indicators end to end. Every charge's card-on-file request
 * fields are captured on the wire (CofWire) and printed with the ids that
 * came back; card data and keys never are.
 */
function stored_credential(): int
{
    $wire = new CofWire();
    $gw = new RecordingGateway(new AuthorizeNetGateway(example_env('AUTHORIZENET_LOGIN_ID'), example_env('AUTHORIZENET_TRANSACTION_KEY'), true, $wire->send(...)));
    Cleat::setGateway($gw);
    $run = gmdate('His');
    $results = [];
    $check = static function (string $id, string $name, bool $pass, string $evidence) use (&$results): void {
        $results[] = [$id, $name, $pass];
        out(sprintf('  [%s] %s: %s', $pass ? 'PASS' : 'FAIL', $name, $evidence));
    };
    $amount = static fn (): int => 1000 + random_int(1, 8999); // distinct amounts: no duplicate-transaction filter

    out("Cleat stored-credential check, run $run, " . gmdate('Y-m-d H:i:s') . ' UTC');
    $product = Product::create(['name' => "Sandbox COF $run"]);
    Price::create($product, ['lookup_key' => "sandbox-cof-$run", 'nickname' => 'Monthly', 'amount' => $amount(), 'billing_type' => 'recurring', 'billing_interval' => 'month']);

    // 1. First CIT: a new subscription with a card saved in the same step.
    section('1. First CIT: newSubscription()->create($card), no trial');
    $customer = Customer::create(['email' => "sandbox+$run-cof@example.com", 'name' => 'Sandbox COF']);
    [$subscription] = attempt(fn () => $customer->newSubscription("sandbox-cof-$run")->create(sandbox_token('4111111111111111', '05', '2031', '123')));
    $first = $wire->last();
    $firstCharge = $subscription !== null ? Charge::findOrFail((int) charge_row($subscription->latestInvoice())['id']) : null;
    $customer = $customer->refresh();
    $original = $customer->original_network_transaction_id;
    $check('1', 'first CIT approved, network id stored on charge and card', $firstCharge?->succeeded() === true
        && $first['request']['processingOptions'] === ['isFirstRecurringPayment' => true] && !isset($first['request']['subsequentAuthInformation'])
        && $original !== null && $original === ($first['response']['networkTransId'] ?? null) && $firstCharge->network_transaction_id === $original,
        sprintf('charge %s, customer %d original_network_transaction_id %s', $firstCharge?->id ?? '-', $customer->id, $original ?? 'NULL'));

    // 2. MIT, recurring: the call Runner::charge() makes for renewals and trial conversions.
    section('2. MIT recurring (renewal): $invoice->pay(storedCredential: StoredCredential::recurring())');
    $renewal = subscription_invoice($customer, $subscription, 'Sandbox COF renewal', $amount());
    attempt(fn () => $renewal->pay(storedCredential: StoredCredential::recurring()));
    $mit = $wire->last();
    $mitRow = charge_row($renewal);
    $check('2', 'recurring MIT sent with the original id and approved', $mitRow['status'] === 'succeeded'
        && ($mit['request']['transactionSettings']['setting'] ?? null) === ['settingName' => 'recurringBilling', 'settingValue' => 'true']
        && ($mit['request']['processingOptions'] ?? null) === ['isSubsequentAuth' => true]
        && ($mit['request']['subsequentAuthInformation'] ?? null) === ['originalNetworkTransId' => $original] && $original !== null,
        charge_evidence($mitRow, $renewal) . ', network id ' . ($mitRow['network_transaction_id'] ?? '-'));

    // 3. MIT, retry: the call Dunning::retry() makes after a declined attempt.
    section('3. MIT resubmission (dunning retry): StoredCredential::resubmission()');
    $retry = subscription_invoice($customer, $subscription, 'Sandbox COF retry', $amount());
    attempt(fn () => $retry->pay(storedCredential: StoredCredential::resubmission()));
    $resub = $wire->last();
    $retryRow = charge_row($retry);
    $check('3', 'resubmission MIT sent with the original id and reason, approved', $retryRow['status'] === 'succeeded'
        && $resub['request']['processingOptions'] === ['isSubsequentAuth' => true]
        && $resub['request']['subsequentAuthInformation'] === ['originalNetworkTransId' => $original, 'reason' => 'resubmission'],
        charge_evidence($retryRow, $retry));

    // 4. Later CIT with the saved card (the pay page's "saved card").
    section('4. Later CIT: payWithSavedCard()');
    $later = invoice_for($customer, 'Sandbox COF later CIT', $amount());
    attempt(fn () => $later->payWithSavedCard(TEST_IP));
    $cit = $wire->last();
    $laterRow = charge_row($later);
    $check('4', 'later CIT sent with isStoredCredentials only, approved', $laterRow['status'] === 'succeeded'
        && $cit['request']['processingOptions'] === ['isStoredCredentials' => true] && !isset($cit['request']['subsequentAuthInformation'])
        && $customer->refresh()->original_network_transaction_id === $original,
        charge_evidence($laterRow, $later) . '; card keeps its original id');

    // 5. No original id (a card saved before 0.1.2, or never charged): MIT flags without it.
    section('5. MIT recurring with no original id on file');
    $legacy = Customer::create(['email' => "sandbox+$run-cof-legacy@example.com", 'name' => 'Sandbox COF legacy']);
    $legacy->updatePaymentMethod(sandbox_token('4111111111111111', '04', '2031', '123'));
    $legacy = $legacy->refresh();
    $noIdInvoice = invoice_for($legacy, 'Sandbox COF no original id', $amount());
    attempt(fn () => $noIdInvoice->pay(storedCredential: StoredCredential::recurring()));
    $noId = $wire->last();
    $noIdRow = charge_row($noIdInvoice);
    $healed = $legacy->refresh()->original_network_transaction_id;
    $check('5', 'recurring MIT without an original id is approved, and its id becomes the card\'s', $noIdRow['status'] === 'succeeded'
        && !isset($noId['request']['subsequentAuthInformation']) && isset($noId['request']['transactionSettings'])
        && ($noId['request']['processingOptions'] ?? null) === ['isSubsequentAuth' => true]
        && $healed !== null && $healed === $noIdRow['network_transaction_id'],
        charge_evidence($noIdRow, $noIdInvoice) . ', card original id now ' . ($healed ?? 'NULL'));

    // 6. One-time token, not saved: no card-on-file fields at all.
    section('6. One-time token, card not saved');
    $once = invoice_for($customer, 'Sandbox COF one-time', $amount());
    attempt(fn () => $once->payWithToken(sandbox_token('4111111111111111', '03', '2031', '123'), false, TEST_IP));
    $token = $wire->last();
    $onceRow = charge_row($once);
    $check('6', 'one-time token sent with no card-on-file fields', $onceRow['status'] === 'succeeded' && $onceRow['source'] === 'one_time_token'
        && array_keys($token['request']) === ['transactionType'] && $customer->refresh()->original_network_transaction_id === $original,
        charge_evidence($onceRow, $once) . ', network id ' . ($onceRow['network_transaction_id'] ?? '-'));

    // 7. What Authorize.net recorded for each transaction (getTransactionDetails).
    section('7. Authorize.net\'s side');
    $auth = ['name' => example_env('AUTHORIZENET_LOGIN_ID'), 'transactionKey' => example_env('AUTHORIZENET_TRANSACTION_KEY')];
    $recorded = [];
    foreach (['first CIT' => $firstCharge?->gateway_transaction_id, 'recurring MIT' => $mitRow['gateway_transaction_id'], 'resubmission MIT' => $retryRow['gateway_transaction_id']] as $label => $tx) {
        $t = $tx === null ? [] : (api('getTransactionDetailsRequest', ['merchantAuthentication' => $auth, 'transId' => $tx])['transaction'] ?? []);
        $recorded[$label] = $t;
        out(sprintf('    %-17s tx %s: networkTransId %s, originalNetworkTransId %s, recurringBilling %s', $label, $tx ?? '-', $t['networkTransId'] ?? '-',
            $t['originalNetworkTransId'] ?? '-', json_encode($t['recurringBilling'] ?? null)));
    }
    $check('7', 'Authorize.net recorded the original id on both MITs, and the renewal as recurring',
        ($recorded['recurring MIT']['originalNetworkTransId'] ?? null) === $original && ($recorded['recurring MIT']['recurringBilling'] ?? null) === true
        && ($recorded['resubmission MIT']['originalNetworkTransId'] ?? null) === $original && ($recorded['first CIT']['networkTransId'] ?? null) === $original,
        'getTransactionDetails, above');
    $profile = api('getCustomerPaymentProfileRequest', ['merchantAuthentication' => $auth, 'customerProfileId' => $customer->gateway_customer_id,
        'customerPaymentProfileId' => $customer->gateway_payment_id])['paymentProfile'] ?? [];
    out(sprintf('    CIM payment profile %s: originalNetworkTransId %s, originalAuthAmount %s (Cleat stored %s)', $customer->gateway_payment_id,
        $profile['originalNetworkTransId'] ?? '-', $profile['originalAuthAmount'] ?? '-', $original ?? 'NULL'));

    $subscription?->cancelNow(); // leave nothing for a runner to renew

    section('Card-on-file fields as sent, and what came back');
    foreach ($wire->transactions as $i => $entry) {
        out(sprintf('  %d. request %s', $i + 1, json_encode($entry['request'], JSON_UNESCAPED_SLASHES)));
        out(sprintf('     response %s', json_encode($entry['response'], JSON_UNESCAPED_SLASHES)));
    }

    section('Summary');
    $failed = 0;
    foreach ($results as [$id, $name, $pass]) {
        out(sprintf('  %-3s %-4s %s', $id, $pass ? 'PASS' : 'FAIL', $name));
        $failed += $pass ? 0 : 1;
    }
    out($failed === 0 ? "\nAll checks passed." : "\n$failed check(s) failed.");
    return $failed === 0 ? 0 : 1;
}

/** An open invoice on the subscription, as the runner makes for a renewal (own amount, so no duplicate filter). */
function subscription_invoice(Customer $customer, ?Subscription $subscription, string $label, int $cents): Invoice
{
    $invoice = Invoice::createDraft($customer, ['subscription_id' => $subscription?->id, 'due_at' => Cleat::now()]);
    $invoice->addLine($label, $cents);
    $invoice->finalize();
    out(sprintf('    invoice %s for %s on subscription %s', $invoice->number, money($cents), $subscription?->id ?? '-'));
    return $invoice;
}

/** Recheck for the next day: refund settled charges (partial, then the rest). */
function refund_settled(): int
{
    section('Refund a settled charge');
    $rows = Db::all("SELECT * FROM cleat_charges WHERE status = 'succeeded' AND gateway_transaction_id IS NOT NULL ORDER BY id");
    foreach ($rows as $row) {
        $charge = Charge::fromRow($row);
        $details = Cleat::gateway()->getTransaction((string) $charge->gateway_transaction_id);
        out(sprintf('  charge %d, tx %s, %s: %s', $charge->id, $charge->gateway_transaction_id, money($charge->amount), $details->get('status') ?? $details->reasonCode));
        if ($details->get('status') !== 'settledSuccessfully' || $charge->amount < 2) {
            continue;
        }
        try {
            $partial = $charge->refund(intdiv($charge->amount, 2));
            $rest = Charge::findOrFail($charge->id)->refund();
        } catch (RefundFailedException $e) {
            out('  [FAIL] ' . $e->getMessage());
            return 1;
        }
        $charge = Charge::findOrFail($charge->id);
        $pass = $partial->method->value === 'refund' && $rest->method->value === 'refund' && $partial->gateway_transaction_id !== null
            && $rest->gateway_transaction_id !== null && $charge->status === ChargeStatus::Refunded && $charge->refundedAmount() === $charge->amount;
        out(sprintf('  [%s] settled refund: partial %s as refund tx %s, rest %s as refund tx %s; charge %d now %s, refunded %s',
            $pass ? 'PASS' : 'FAIL', money($partial->amount), $partial->gateway_transaction_id, money($rest->amount), $rest->gateway_transaction_id,
            $charge->id, $charge->status->value, money($charge->refundedAmount())));
        out('  Check the refund.created webhooks arrive and change nothing: php examples/sandbox-check.php --notifications');
        return $pass ? 0 : 1;
    }
    out('  [INFO] No settled charge yet. Sandbox batches settle once a day; run this again after the next settlement.');
    return 2;
}

// --- Browser pass ----------------------------------------------------------------

/** Fresh invoices for the hosted pay page, and the seeded payment links, at CLEAT_BASE_URL. */
function browser_setup(): int
{
    $links = Db::all(
        "SELECT l.id, p.lookup_key FROM cleat_payment_links l JOIN cleat_prices p ON p.id = l.price_id
         WHERE p.lookup_key IN ('field-guide', 'team-monthly') AND l.is_active = 1 ORDER BY l.id DESC",
    );
    $urls = [];
    foreach ($links as $row) {
        $urls[$row['lookup_key']] ??= \Cleat\PaymentLink::findOrFail((int) $row['id'])->url();
    }
    if (count($urls) !== 2) {
        fail_hard('The seeded payment links are missing. Run php examples/seed.php first.');
    }
    $run = gmdate('His');
    $customer = Customer::create(['email' => "browser+$run@example.com", 'name' => 'Browser Check']);
    $invoices = [];
    foreach (['A' => ['new card, save it', 1234], 'B' => ['different card, do not save', 2345], 'C' => ['the saved card', 3456]] as $key => [$label, $cents]) {
        $invoice = $customer->newInvoice()->addItem("Browser pass $key: pay with $label", $cents)->dueIn(7)->send();
        $invoices[$key] = sprintf('%s %s  %s', $invoice->number, money($cents), $invoice->paymentUrl());
    }
    out("Customer {$customer->id} ({$customer->email}), CIM profile {$customer->gateway_customer_id}");
    out('');
    out('One-time checkout (The Field Guide, $19.00):      ' . $urls['field-guide']);
    out('Recurring checkout (Team plan, 14-day trial):    ' . $urls['team-monthly']);
    foreach ($invoices as $key => $line) {
        out("Invoice $key: $line");
    }
    return 0;
}

/** The latest rows Cleat wrote, as evidence for the browser pass. */
function report(): int
{
    $sections = [
        'Customers' => 'SELECT id, email, gateway_customer_id AS profile, gateway_payment_id AS payment_profile, card_brand, card_last_four, card_exp, original_network_transaction_id AS original_nti FROM cleat_customers ORDER BY id DESC LIMIT 8',
        'Invoices' => 'SELECT id, number, status, total, amount_paid, subscription_id AS sub, payment_link_id AS link FROM cleat_invoices ORDER BY id DESC LIMIT 12',
        'Charges' => 'SELECT c.id, i.number, c.status, c.amount, c.source, c.response_code AS resp, c.reason_code AS reason, c.gateway_transaction_id AS tx, c.network_transaction_id AS nti FROM cleat_charges c JOIN cleat_invoices i ON i.id = c.invoice_id ORDER BY c.id DESC LIMIT 12',
        'Subscriptions' => 'SELECT id, customer_id, status, quantity, trial_ends_at, current_period_end FROM cleat_subscriptions ORDER BY id DESC LIMIT 5',
        'Refunds' => 'SELECT id, charge_id, method, status, amount, gateway_transaction_id AS tx, reason_code FROM cleat_refunds ORDER BY id DESC LIMIT 5',
        'Webhook events' => "SELECT gateway_event_id, event_type, JSON_UNQUOTE(JSON_EXTRACT(payload, '$.payload.id')) AS id, processed_at FROM cleat_webhook_events ORDER BY id DESC LIMIT 15",
    ];
    foreach ($sections as $title => $sql) {
        out("\n$title");
        foreach (Db::all($sql) as $row) {
            out('  ' . implode('  ', array_map(static fn ($v) => $v ?? '-', $row)));
        }
    }
    return 0;
}

// --- Webhook administration (REST API) ------------------------------------------

function webhook_url(): string
{
    $base = rtrim(example_env('CLEAT_BASE_URL'), '/');
    if (!str_starts_with($base, 'https://')) {
        fail_hard('CLEAT_BASE_URL must be the https tunnel URL.');
    }
    return $base . WEBHOOK_PATH;
}

function webhook_register(): int
{
    $r = rest('POST', '/webhooks', ['name' => 'cleat_sandbox_' . gmdate('Ymd_His'), 'url' => webhook_url(), 'eventTypes' => WEBHOOK_EVENTS, 'status' => 'active']);
    out(sprintf('HTTP %d: webhook %s, %s, %s', $r['status'], $r['body']['webhookId'] ?? '-', $r['body']['status'] ?? '-', $r['body']['url'] ?? json_encode($r['body'])));
    foreach ((array) ($r['body']['eventTypes'] ?? []) as $type) {
        out('  ' . $type);
    }
    return $r['status'] === 200 ? 0 : 1;
}

/** Pings only go to an inactive webhook: deactivate, ping, reactivate. */
function webhook_ping(string $id): int
{
    $hook = rest('GET', '/webhooks/' . rawurlencode($id));
    if ($hook['status'] !== 200) {
        out("HTTP {$hook['status']}: " . json_encode($hook['body']));
        return 1;
    }
    $update = static fn (string $status) => rest('PUT', '/webhooks/' . rawurlencode($id), [
        'url' => $hook['body']['url'], 'eventTypes' => $hook['body']['eventTypes'], 'status' => $status,
    ]);
    $off = $update('inactive');
    $ping = rest('POST', '/webhooks/' . rawurlencode($id) . '/pings');
    $on = $update('active');
    out(sprintf('deactivate HTTP %d, ping HTTP %d %s, reactivate HTTP %d (%s)', $off['status'], $ping['status'], $ping['body'] === [] ? '' : json_encode($ping['body']), $on['status'], $on['body']['status'] ?? '-'));
    return $ping['status'] === 200 && $on['status'] === 200 ? 0 : 1;
}

function webhook_list(): int
{
    $r = rest('GET', '/webhooks');
    foreach ((array) $r['body'] as $hook) {
        out(sprintf('%s  %-8s %s  (%d events)', $hook['webhookId'] ?? '-', $hook['status'] ?? '-', $hook['url'] ?? '-', count((array) ($hook['eventTypes'] ?? []))));
    }
    out(sprintf('HTTP %d, %d webhook(s)', $r['status'], count((array) $r['body'])));
    return $r['status'] === 200 ? 0 : 1;
}

function webhook_delete(string $id): int
{
    $r = rest('DELETE', '/webhooks/' . rawurlencode($id));
    out(sprintf('DELETE webhook %s: HTTP %d', $id, $r['status']));
    return $r['status'] === 200 ? 0 : 1;
}

function notifications(): int
{
    $r = rest('GET', '/notifications?offset=0&limit=100');
    $list = (array) ($r['body']['notifications'] ?? []);
    foreach ($list as $n) {
        out(sprintf('%s  %-48s %s  %s', $n['notificationId'] ?? '-', $n['eventType'] ?? '-', $n['eventDate'] ?? '-', $n['deliveryStatus'] ?? '-'));
    }
    out(sprintf('HTTP %d, %d notification(s)', $r['status'], count($list)));
    return $r['status'] === 200 ? 0 : 1;
}

/** Which form of the signature key reproduces X-ANET-Signature on the captured notifications. */
function hmac_check(): int
{
    $key = example_env('AUTHORIZENET_SIGNATURE_KEY');
    $files = captured();
    $tally = ['hex2bin' => 0, 'raw hex string' => 0, 'neither' => 0];
    foreach ($files as $file) {
        $cap = json_decode((string) file_get_contents($file), true);
        if (!is_array($cap) || !is_string($cap['signature'] ?? null) || !is_string($cap['body'] ?? null)) {
            continue;
        }
        $given = strtoupper(preg_replace('/^sha512=/i', '', trim($cap['signature'])) ?? '');
        $binary = ctype_xdigit($key) && strlen($key) % 2 === 0 && hash_equals(strtoupper(hash_hmac('sha512', $cap['body'], (string) hex2bin($key))), $given);
        $raw = hash_equals(strtoupper(hash_hmac('sha512', $cap['body'], $key)), $given);
        $form = $binary ? 'hex2bin' : ($raw ? 'raw hex string' : 'neither');
        $tally[$form]++;
        $event = json_decode($cap['body'], true);
        out(sprintf('%s  %-46s HTTP %d  hex2bin(key): %-3s  raw key: %-3s', basename($file), $event['eventType'] ?? '?', $cap['status'] ?? 0, $binary ? 'yes' : 'no', $raw ? 'yes' : 'no'));
    }
    out(sprintf('%d notification(s): %s', array_sum($tally), json_encode($tally)));
    return $tally['neither'] === 0 && array_sum($tally) > 0 ? 0 : 1;
}

/** Deliver a captured notification again, through the tunnel, exactly as received. */
function replay(string $file): int
{
    $path = is_file($file) ? $file : rtrim(example_env('CLEAT_WEBHOOK_CAPTURE_DIR'), '/\\') . DIRECTORY_SEPARATOR . $file;
    $cap = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (!is_array($cap)) {
        fail_hard("No captured notification at $file.");
    }
    $event = json_decode((string) $cap['body'], true);
    $id = (string) ($event['notificationId'] ?? '');
    $before = (int) Db::value('SELECT COUNT(*) FROM cleat_webhook_events WHERE gateway_event_id = ?', [$id]);
    $ch = curl_init(webhook_url());
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cap['body'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-ANET-Signature: ' . $cap['signature']],
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $after = (int) Db::value('SELECT COUNT(*) FROM cleat_webhook_events WHERE gateway_event_id = ?', [$id]);
    out(sprintf('replayed notification %s (%s): HTTP %d "%s"; stored rows before %d, after %d', $id, $event['eventType'] ?? '?', $status, $body, $before, $after));
    return $status === 200 && $after === 1 ? 0 : 1;
}

/** @return list<string> */
function captured(): array
{
    $dir = example_env('CLEAT_WEBHOOK_CAPTURE_DIR');
    if ($dir === '' || !is_dir($dir)) {
        fail_hard('Set CLEAT_WEBHOOK_CAPTURE_DIR in examples/.env and receive some notifications first.');
    }
    $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [];
    sort($files);
    return $files;
}

/**
 * @param list<string> $transactionIds
 * @return array<string, array<string, array{id: string, processed_at: ?string}>>
 */
function webhook_events(array $transactionIds): array
{
    if ($transactionIds === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($transactionIds), '?'));
    $rows = Db::all(
        "SELECT gateway_event_id, event_type, processed_at, JSON_UNQUOTE(JSON_EXTRACT(payload, '$.payload.id')) AS tx
         FROM cleat_webhook_events WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.payload.id')) IN ($in) ORDER BY id",
        $transactionIds,
    );
    $events = [];
    foreach ($rows as $row) {
        $events[(string) $row['tx']][(string) $row['event_type']] = ['id' => (string) $row['gateway_event_id'], 'processed_at' => $row['processed_at']];
    }
    return $events;
}

// --- Test-only card tokenizing (never in src/) -----------------------------------

/**
 * Turn sandbox test card data into Accept.js opaque data with
 * securePaymentContainerRequest, authenticated by the public client key just
 * as Accept.js is. The card number is never printed.
 *
 * @return array{dataDescriptor: string, dataValue: string}
 */
function sandbox_token(string $cardNumber, string $month, string $year, string $cardCode, ?string $zip = null): array
{
    $lastFour = substr($cardNumber, -4);
    $token = ['cardNumber' => $cardNumber, 'expirationDate' => $month . $year, 'cardCode' => $cardCode];
    if ($zip !== null) {
        $token['zip'] = $zip;
    }
    $r = api('securePaymentContainerRequest', [
        'merchantAuthentication' => ['name' => example_env('AUTHORIZENET_LOGIN_ID'), 'clientKey' => example_env('AUTHORIZENET_CLIENT_KEY')],
        'data' => ['type' => 'TOKEN', 'id' => bin2hex(random_bytes(8)), 'token' => $token],
    ]);
    unset($token, $cardNumber, $cardCode);
    $opaque = $r['opaqueData'] ?? null;
    if (($r['messages']['resultCode'] ?? '') !== 'Ok' || !is_array($opaque)) {
        throw new CleatException(sprintf('Tokenizing failed: %s %s', $r['messages']['message'][0]['code'] ?? '?', $r['messages']['message'][0]['text'] ?? ''));
    }
    out(sprintf('    tokenized card ...%s exp %s/%s%s', $lastFour, $month, $year, $zip !== null ? " zip $zip" : ''));
    return ['dataDescriptor' => (string) $opaque['dataDescriptor'], 'dataValue' => (string) $opaque['dataValue']];
}

/** @return array<string, mixed> */
function api(string $root, array $body): array
{
    $ch = curl_init(SANDBOX_API);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([$root => $body], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    unset($body);
    $response = (string) curl_exec($ch);
    curl_close($ch);
    return AuthorizeNetGateway::decode($response) ?? throw new CleatException("Unreadable response to $root.");
}

/** @return array{status: int, body: array<mixed>} */
function rest(string $method, string $path, ?array $body = null): array
{
    $ch = curl_init(SANDBOX_REST . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => example_env('AUTHORIZENET_LOGIN_ID') . ':' . example_env('AUTHORIZENET_TRANSACTION_KEY'),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    }
    $response = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $decoded = json_decode(str_starts_with($response, "\xEF\xBB\xBF") ? substr($response, 3) : $response, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : ($response === '' ? [] : ['raw' => $response])];
}

// --- Helpers -------------------------------------------------------------------

function invoice_for(Customer $customer, string $label, int $cents): Invoice
{
    $invoice = $customer->newInvoice()->addItem($label, $cents)->dueIn(0)->finalize();
    out(sprintf('    invoice %s for %s', $invoice->number, money($cents)));
    return $invoice;
}

/**
 * Pay an invoice with a fresh token for this card and amount and report what came back.
 *
 * @return array{invoice: Invoice, charge: ?array<string, mixed>, result: ?GatewayResult, summary: string}
 */
function probe(Customer $customer, string $label, string $card, string $month, string $year, int $cents): array
{
    $invoice = invoice_for($customer, mb_substr($label, 0, 200), $cents);
    try {
        $opaque = sandbox_token($card, $month, $year, '123');
    } catch (CleatException $e) {
        $invoice->void();
        return ['invoice' => $invoice, 'charge' => null, 'result' => null, 'summary' => 'not chargeable: ' . $e->getMessage()];
    }
    [, $e] = attempt(fn () => $invoice->payWithToken($opaque, false, TEST_IP));
    $row = charge_row($invoice);
    $result = $e instanceof PaymentFailedException ? $e->result : null;
    return ['invoice' => $invoice->refresh(), 'charge' => $row, 'result' => $result, 'summary' => $row === null ? 'no charge row' : charge_evidence($row, $invoice)];
}

/** @return array{0: mixed, 1: ?Throwable} */
function attempt(callable $fn): array
{
    try {
        return [$fn(), null];
    } catch (PaymentFailedException $e) {
        out('    PaymentFailedException: ' . $e->getMessage());
        return [null, $e];
    }
}

/** @return array<string, mixed>|null */
function charge_row(Invoice $invoice): ?array
{
    return Db::one('SELECT * FROM cleat_charges WHERE invoice_id = ? ORDER BY id DESC LIMIT 1', [$invoice->id]);
}

function charge_evidence(array $c, Invoice $invoice): string
{
    return sprintf('charge %d %s, %s, response %s, reason %s, tx %s, source %s; invoice %s %s',
        $c['id'], $c['status'], money((int) $c['amount']), $c['response_code'] ?? '-', $c['reason_code'] ?? '-',
        $c['gateway_transaction_id'] ?? '-', $c['source'], $invoice->number, $invoice->refresh()->status->value);
}

function short_event(string $type): string
{
    return preg_replace('/^net\.authorize\./', '', $type) ?? $type;
}

function money(int $cents): string
{
    return '$' . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function to_cents(string $amount): int
{
    return AuthorizeNetGateway::cents(ltrim($amount, '$'));
}

/** @return array<string, string|true> */
function parse_args(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) === 1) {
            $args[$m[1]] = $m[2] ?? true;
        } else {
            fail_hard("Unknown argument $arg. See the comment at the top of examples/sandbox-check.php.");
        }
    }
    return $args;
}

function section(string $title): void
{
    out("\n$title");
}

function out(string $line): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

function fail_hard(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(2);
}

/**
 * Wraps the real gateway: prints each call's outcome (codes and ids, never
 * request data) and can discard one transaction response after the real call
 * has gone through, to reproduce a timeout.
 */
final class RecordingGateway implements GatewayInterface
{
    public bool $dropNextResponse = false;
    public ?string $droppedTransactionId = null;
    /** @var list<?StoredCredential> what each chargeProfile call was given */
    public array $credentials = [];
    /** @var list<array{call: string, result: GatewayResult}> */
    private array $calls = [];
    private int $mark = 0;

    public function __construct(private readonly GatewayInterface $inner)
    {
    }

    public function mark(): void
    {
        $this->mark = count($this->calls);
    }

    /** @return list<array{call: string, result: GatewayResult}> */
    public function since(): array
    {
        return array_slice($this->calls, $this->mark);
    }

    public function last(string $call): ?GatewayResult
    {
        foreach (array_reverse($this->calls) as $entry) {
            if ($entry['call'] === $call) {
                return $entry['result'];
            }
        }
        return null;
    }

    public function createCustomerProfile(string $merchantCustomerId, string $email, ?string $description = null): GatewayResult
    {
        return $this->record('createCustomerProfile', '', $this->inner->createCustomerProfile($merchantCustomerId, $email, $description));
    }

    public function createPaymentProfile(string $profileId, array $opaqueData): GatewayResult
    {
        return $this->record('createPaymentProfile', "profile $profileId", $this->inner->createPaymentProfile($profileId, $opaqueData));
    }

    public function deletePaymentProfile(string $profileId, string $paymentProfileId): GatewayResult
    {
        return $this->record('deletePaymentProfile', "profile $profileId/$paymentProfileId", $this->inner->deletePaymentProfile($profileId, $paymentProfileId));
    }

    public function chargeProfile(string $profileId, string $paymentProfileId, int $amountCents, string $invoiceNumber, string $idempotencyKey, ?StoredCredential $storedCredential = null): GatewayResult
    {
        $this->credentials[] = $storedCredential;
        $what = money($amountCents) . " $invoiceNumber" . ($storedCredential !== null ? ' [' . $storedCredential->label() . ']' : '');
        return $this->transaction('chargeProfile', $what, $this->inner->chargeProfile($profileId, $paymentProfileId, $amountCents, $invoiceNumber, $idempotencyKey, $storedCredential));
    }

    public function chargeToken(array $opaqueData, int $amountCents, string $invoiceNumber, string $idempotencyKey, ?string $email = null, ?string $ip = null): GatewayResult
    {
        return $this->transaction('chargeToken', money($amountCents) . " $invoiceNumber", $this->inner->chargeToken($opaqueData, $amountCents, $invoiceNumber, $idempotencyKey, $email, $ip));
    }

    public function refund(string $transactionId, int $amountCents, string $cardLastFour, string $idempotencyKey): GatewayResult
    {
        return $this->transaction('refund', money($amountCents) . " of $transactionId", $this->inner->refund($transactionId, $amountCents, $cardLastFour, $idempotencyKey));
    }

    public function void(string $transactionId, string $idempotencyKey): GatewayResult
    {
        return $this->transaction('void', $transactionId, $this->inner->void($transactionId, $idempotencyKey));
    }

    public function getTransaction(string $transactionId): GatewayResult
    {
        return $this->record('getTransaction', $transactionId, $this->inner->getTransaction($transactionId));
    }

    public function findTransaction(string $invoiceNumber, int $amountCents, \DateTimeImmutable $since): GatewayResult
    {
        return $this->record('findTransaction', "$invoiceNumber " . money($amountCents), $this->inner->findTransaction($invoiceNumber, $amountCents, $since));
    }

    private function transaction(string $call, string $what, GatewayResult $result): GatewayResult
    {
        $this->record($call, $what, $result);
        if ($this->dropNextResponse) {
            $this->dropNextResponse = false;
            $this->droppedTransactionId = $result->transactionId;
            out('      (response discarded: Cleat sees a timeout)');
            return GatewayResult::timeout();
        }
        return $result;
    }

    private function record(string $call, string $what, GatewayResult $result): GatewayResult
    {
        $this->calls[] = ['call' => $call, 'result' => $result];
        $extra = array_filter([
            'tx' => $result->transactionId,
            'response' => $result->responseCode,
            'reason' => $result->reasonCode,
        ], static fn ($v) => $v !== null);
        $data = array_intersect_key($result->data, array_flip(['customer_profile_id', 'payment_profile_id', 'card_brand', 'card_last_four', 'card_exp', 'transaction_id', 'status', 'account_number', 'duplicate']));
        out(sprintf('      gateway %s %s -> %s %s %s%s', $call, $what, $result->status->value,
            http_build_query($extra, '', ' '), $data === [] ? '' : json_encode($data, JSON_UNESCAPED_SLASHES),
            $result->reasonText !== null && !$result->isApproved() ? ' "' . $result->reasonText . '"' : ''));
        return $result;
    }
}

/**
 * A cURL transport for AuthorizeNetGateway that keeps, from each
 * createTransactionRequest, only its card-on-file fields (and the
 * transaction type), and from each response only the codes and ids. Request
 * bodies carry the transaction key and opaque card data; nothing else of
 * them is kept or printed.
 */
final class CofWire
{
    private const REQUEST_FIELDS = ['transactionType', 'transactionSettings', 'processingOptions', 'subsequentAuthInformation'];
    private const RESPONSE_FIELDS = ['responseCode', 'transId', 'networkTransId'];

    /** @var list<array{request: array<string, mixed>, response: array<string, mixed>}> */
    public array $transactions = [];

    /** @return array{status: int, body: string, errno: int, error: string} */
    public function send(string $url, string $body, int $timeout): array
    {
        $tx = json_decode($body, true)['createTransactionRequest']['transactionRequest'] ?? null;
        $request = is_array($tx) ? array_intersect_key($tx, array_flip(self::REQUEST_FIELDS)) : null;
        unset($tx);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        unset($body);
        $response = curl_exec($ch);
        $result = ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => is_string($response) ? $response : '', 'errno' => curl_errno($ch), 'error' => curl_error($ch)];
        curl_close($ch);

        if ($request !== null) {
            $tr = AuthorizeNetGateway::decode($result['body'])['transactionResponse'] ?? [];
            $this->transactions[] = ['request' => $request, 'response' => array_intersect_key(is_array($tr) ? $tr : [], array_flip(self::RESPONSE_FIELDS))];
            out(sprintf('      wire: request %s -> response %s', json_encode($request, JSON_UNESCAPED_SLASHES), json_encode(end($this->transactions)['response'], JSON_UNESCAPED_SLASHES)));
        }
        return $result;
    }

    /** @return array{request: array<string, mixed>, response: array<string, mixed>} */
    public function last(): array
    {
        return end($this->transactions) ?: ['request' => [], 'response' => []];
    }
}

// --- Dispatch ------------------------------------------------------------------

$args = parse_args($argv);
$recorder = new RecordingGateway(Cleat::gateway());
Cleat::setGateway($recorder);

exit(match (true) {
    isset($args['webhook-register']) => webhook_register(),
    isset($args['webhook-ping']) => webhook_ping((string) $args['webhook-ping']),
    isset($args['webhook-list']) => webhook_list(),
    isset($args['webhook-delete']) => webhook_delete((string) $args['webhook-delete']),
    isset($args['notifications']) => notifications(),
    isset($args['hmac-check']) => hmac_check(),
    isset($args['replay']) => replay((string) $args['replay']),
    isset($args['refund-settled']) => refund_settled(),
    isset($args['profile-deleted']) => profile_deleted($recorder, is_string($args['profile-deleted']) ? (int) $args['profile-deleted'] : 900),
    isset($args['timeout-webhook']) => timeout_webhook($recorder, is_string($args['timeout-webhook']) ? (int) $args['timeout-webhook'] : 900),
    isset($args['stored-credential']) => stored_credential(),
    isset($args['browser-setup']) => browser_setup(),
    isset($args['report']) => report(),
    default => run_checks($recorder, isset($args['held-amount']) ? to_cents((string) $args['held-amount']) : null),
});
