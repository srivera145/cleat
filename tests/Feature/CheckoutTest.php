<?php

declare(strict_types=1);

namespace Cleat\Tests\Feature;

use Cleat\Customer;
use Cleat\Enums\ChargeStatus;
use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\RefundMethod;
use Cleat\Enums\SubscriptionStatus;
use Cleat\Events\CheckoutCompleted;
use Cleat\Http\CheckoutPage;
use Cleat\Http\Csrf;
use Cleat\Http\Response;
use Cleat\Invoice;
use Cleat\PaymentLink;
use Cleat\Refund;
use Cleat\Tests\Support\TestCase;
use Cleat\Tests\Support\TestDatabase;

final class CheckoutTest extends TestCase
{
    private const SESSION = 'checkout-session';

    /** Self-check 11, one-time: a guest customer and a paid invoice. */
    public function test_one_time_price_creates_guest_and_paid_invoice(): void
    {
        $this->oneTimePrice('ebook', 1900, $this->product('The Field Guide'));
        $link = PaymentLink::create('ebook', ['collect_phone' => true]);
        $this->assertMatchesRegularExpression('#^https://billing\.test/checkout/[a-f0-9]{64}$#', $link->url());

        $page = (new CheckoutPage(self::SESSION))->show($link->public_token);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('The Field Guide', $page->body);
        $this->assertStringContainsString('$19.00', $page->body);
        $this->assertStringContainsString('name="phone"', $page->body);

        $response = $this->submit($link, ['email' => 'guest@example.com', 'name' => 'Gwen Guest', 'phone' => '+1 555 0100'] + self::opaque('tok_visa'));

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Payment received', $response->body);
        $guest = Customer::fromRow($this->pdo->query("SELECT * FROM cleat_customers WHERE email = 'guest@example.com'")->fetch(\PDO::FETCH_ASSOC));
        $this->assertNull($guest->user_id, 'guest customer');
        $this->assertSame('Gwen Guest', $guest->name);
        $this->assertSame('+1 555 0100', $guest->phone);
        $this->assertFalse($guest->hasPaymentMethod(), 'one-time checkout does not store the card');

        $invoice = $guest->invoices()[0];
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(1900, $invoice->amount_paid);
        $this->assertSame($link->id, $invoice->payment_link_id);
        $this->assertSame('one_time_token', $invoice->charges()[0]->source->value);
        $this->assertSame(1, $link->refresh()->use_count);
        $this->assertCount(1, $this->eventsOf(CheckoutCompleted::class));
        $this->assertCount(1, $this->mailer->to('guest@example.com'), 'exactly one receipt');
        $this->assertStringStartsWith('Receipt from Acme Tools', $this->mailer->last()['subject']);
    }

    /** Self-check 11, recurring with trial_days: a trialing subscription with a stored card. */
    public function test_recurring_price_with_trial_creates_trialing_subscription_with_stored_card(): void
    {
        $this->monthlyPrice('team-monthly', 2900, trialDays: 7, product: $this->product('Team'));
        $link = PaymentLink::create('team-monthly', ['allow_quantity_change' => true, 'max_quantity' => 20]);

        $page = (new CheckoutPage(self::SESSION))->show($link->public_token);
        $this->assertStringContainsString('$29.00', $page->body);
        $this->assertStringContainsString('/ month', $page->body);
        $this->assertStringContainsString('7-day free trial, then $29.00 / month', $page->body);
        $this->assertStringContainsString('Start free trial', $page->body);
        $this->assertStringContainsString('name="quantity"', $page->body);

        $response = $this->submit($link, ['email' => 'team@example.com', 'name' => 'Tess', 'quantity' => '3'] + self::opaque('tok_visa'));

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Your free trial has started', $response->body);
        $customer = Customer::fromRow($this->pdo->query("SELECT * FROM cleat_customers WHERE email = 'team@example.com'")->fetch(\PDO::FETCH_ASSOC));
        $this->assertTrue($customer->hasPaymentMethod(), 'card stored for renewals');
        $subscription = $customer->subscriptions()[0];
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertSame(3, $subscription->quantity);
        $this->assertSame('2026-01-22 12:00:00', $subscription->trial_ends_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $this->gateway->chargeCount(), 'nothing charged during the trial');
        $this->assertSame('Your Team trial has started', $this->mailer->last()['subject']);

        // And it converts at the end of the trial for 3 x $29.00.
        $this->runAt('2026-01-22 12:00:00');
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame(8700, $this->gateway->callsTo('chargeProfile')[0]['args']['amount']);
    }

    public function test_recurring_price_without_trial_charges_now(): void
    {
        $this->monthlyPrice('solo-monthly', 1500);
        $link = PaymentLink::create('solo-monthly', ['success_url' => 'https://acme.test/welcome']);
        $formPage = (new CheckoutPage(self::SESSION))->show($link->public_token);
        $this->assertStringContainsString("form-action 'self' https://acme.test", (string) $formPage->header('Content-Security-Policy'), 'the page holding the form allows the post-submit redirect');
        $response = $this->submit($link, ['email' => 'solo@example.com', 'name' => 'Sol'] + self::opaque('tok_visa'));

        $this->assertSame(303, $response->status);
        $this->assertSame('https://acme.test/welcome', $response->header('Location'));
        $this->assertStringContainsString('https://acme.test', (string) $response->header('Content-Security-Policy'), 'form-action allows the success_url origin');
        $customer = Customer::fromRow($this->pdo->query("SELECT * FROM cleat_customers WHERE email = 'solo@example.com'")->fetch(\PDO::FETCH_ASSOC));
        $subscription = $customer->subscriptions()[0];
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame($link->id, $subscription->latestInvoice()->payment_link_id);
        $this->assertSame(1, $this->gateway->chargeCount());
    }

    public function test_recurring_checkout_never_replaces_another_guests_saved_card(): void
    {
        $this->monthlyPrice('solo-monthly', 1500);
        $link = PaymentLink::create('solo-monthly');
        $this->submit($link, ['email' => 'shared@example.com', 'name' => 'First'] + self::opaque('tok_visa'));
        $first = Customer::fromRow($this->pdo->query("SELECT * FROM cleat_customers WHERE email = 'shared@example.com' ORDER BY id LIMIT 1")->fetch(\PDO::FETCH_ASSOC));

        $this->submit($link, ['email' => 'shared@example.com', 'name' => 'Stranger'] + self::opaque('tok_mastercard'));

        $this->assertSame('Visa ending 4242', $first->refresh()->paymentMethodLabel(), 'first guest keeps their card');
        $this->assertSame(2, $this->rows('cleat_customers', 'email = ?', ['shared@example.com']));
    }

    public function test_decline_rerenders_with_a_generic_message_and_voids_the_attempt(): void
    {
        $this->oneTimePrice('ebook', 1900);
        $link = PaymentLink::create('ebook');
        $response = $this->submit($link, ['email' => 'poor@example.com', 'name' => 'P'] + self::opaque('tok_insufficient'));

        $this->assertSame(402, $response->status);
        $this->assertStringContainsString('Your card was declined.', $response->body);
        $this->assertStringNotContainsString('insufficient funds', $response->body);
        $this->assertStringContainsString('value="poor@example.com"', $response->body, 'sticky form values');
        $this->assertSame(0, $link->refresh()->use_count);
        $this->assertSame(1, $this->rows('cleat_invoices', "status = 'void'"));
        $this->assertSame(0, $this->rows('cleat_invoices', "status = 'open'"), 'declined checkouts do not leave open invoices');
    }

    public function test_validation_errors(): void
    {
        $this->oneTimePrice('ebook', 1900);
        $link = PaymentLink::create('ebook', ['allow_quantity_change' => true, 'max_quantity' => 5]);
        $response = $this->submit($link, ['email' => 'not-an-email', 'name' => '', 'quantity' => '9']);
        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('Enter a valid email address.', $response->body);
        $this->assertStringContainsString('Enter your name.', $response->body);
        $this->assertStringContainsString('Quantity must be between 1 and 5.', $response->body);
        $this->assertStringContainsString('Enter your card details.', $response->body);
        $this->assertSame(0, $this->rows('cleat_customers'));
    }

    public function test_link_states(): void
    {
        $this->oneTimePrice('ebook', 1900);
        $page = new CheckoutPage(self::SESSION);

        $this->assertSame(404, $page->show(str_repeat('b', 64))->status);
        $inactive = PaymentLink::create('ebook')->deactivate();
        $this->assertSame(404, $page->show($inactive->public_token)->status);

        $expiring = PaymentLink::create('ebook', ['expires_at' => '2026-01-20 00:00:00']);
        $this->assertSame(200, $page->show($expiring->public_token)->status);
        $this->at('2026-01-20 00:00:00');
        $this->assertSame(410, $page->show($expiring->public_token)->status);
        $this->assertSame(1, $this->runAt('2026-01-20 00:15:00')->linksDeactivated);
        $this->assertSame(410, $page->show($expiring->public_token)->status, 'still 410 after the Runner deactivates it');

        $once = PaymentLink::create('ebook', ['max_uses' => 1]);
        $this->submit($once, ['email' => 'one@example.com', 'name' => 'One'] + self::opaque('tok_visa'));
        $this->assertSame(410, $page->show($once->public_token)->status);
        $this->assertSame(410, $this->submit($once, ['email' => 'two@example.com', 'name' => 'Two'] + self::opaque('tok_visa'), csrfFromShow: false)->status);
        $this->assertSame(1, $this->gateway->chargeCount(), 'the second buyer is stopped before any charge');
    }

    /**
     * Self-check 12 (deterministic): the second buyer submits while the first
     * buyer's charge is in flight, so both pass the "sold out?" pre-check.
     * The second finishes first and takes the only use; the first is refunded
     * and gets 410.
     */
    public function test_max_uses_one_with_two_overlapping_submits(): void
    {
        $this->oneTimePrice('ticket', 4500, $this->product('Workshop seat'));
        $link = PaymentLink::create('ticket', ['max_uses' => 1]);
        $csrfB = (new Csrf(self::APP_KEY))->issue($link->public_token, 'session-b', $this->clock->now());

        $secondResponse = null;
        $this->gateway->whileCharging = function () use ($link, $csrfB, &$secondResponse): void {
            $secondResponse = (new CheckoutPage('session-b'))->submit($link->public_token, [
                '_csrf' => $csrfB, 'email' => 'b@example.com', 'name' => 'B',
            ] + self::opaque('tok_visa'), '192.0.2.20');
        };

        $firstResponse = $this->submit($link, ['email' => 'a@example.com', 'name' => 'A'] + self::opaque('tok_visa'), ip: '192.0.2.10');

        $this->assertNotNull($secondResponse, 'the competing submit ran while the first charge was in flight');
        $this->assertSame(200, $secondResponse->status, 'the request that finished first wins');
        $this->assertSame(410, $firstResponse->status);
        $this->assertStringContainsString('refunded in full', $firstResponse->body);
        $this->assertSame(1, $link->refresh()->use_count);

        $loser = Invoice::fromRow($this->pdo->query("SELECT i.* FROM cleat_invoices i JOIN cleat_customers c ON c.id = i.customer_id WHERE c.email = 'a@example.com'")->fetch(\PDO::FETCH_ASSOC));
        $this->assertSame(InvoiceStatus::Void, $loser->status);
        $charge = $loser->charges()[0];
        $this->assertSame(ChargeStatus::Voided, $charge->status, 'unsettled, so the refund became a void');
        $refund = Refund::forCharge($charge->id)[0];
        $this->assertSame(RefundMethod::Void, $refund->method);
        $this->assertSame(4500, $refund->amount);
        $this->assertCount(1, $this->eventsOf(CheckoutCompleted::class));

        $winner = Invoice::fromRow($this->pdo->query("SELECT i.* FROM cleat_invoices i JOIN cleat_customers c ON c.id = i.customer_id WHERE c.email = 'b@example.com'")->fetch(\PDO::FETCH_ASSOC));
        $this->assertSame(InvoiceStatus::Paid, $winner->status);
    }

    /** Self-check 12 (real concurrency): two PHP processes against the same database. */
    public function test_max_uses_one_with_two_concurrent_processes(): void
    {
        $this->oneTimePrice('ticket', 4500, $this->product('Workshop seat'));
        $link = PaymentLink::create('ticket', ['max_uses' => 1]);
        $worker = dirname(__DIR__) . '/fixtures/checkout_worker.php';
        $env = getenv() + ['CLEAT_TEST_DB_NAME' => TestDatabase::settings()['name']];

        $procs = [];
        foreach ([['p1@example.com', '192.0.2.31'], ['p2@example.com', '192.0.2.32']] as [$email, $ip]) {
            $pipes = [];
            $proc = proc_open([PHP_BINARY, $worker, $link->public_token, $email, $ip, self::APP_KEY], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        $results = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            proc_close($proc);
            $decoded = json_decode(trim((string) $out), true);
            $this->assertIsArray($decoded, "worker output: $out $err");
            $results[] = $decoded;
        }

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 410], $statuses, 'exactly one buyer gets the last use');
        $loser = $results[array_search(410, array_column($results, 'status'), true)];
        $this->assertStringContainsString('refunded in full', $loser['body']);
        $this->assertSame(1, $link->refresh()->use_count);
        $this->assertSame(1, $this->rows('cleat_invoices', "status = 'paid'"));
        $this->assertSame(1, $this->rows('cleat_invoices', "status = 'void'"));
        $this->assertSame(1, $this->rows('cleat_refunds', "status = 'succeeded'"));
    }

    /** Self-check 13: six failed card attempts from one IP within an hour -> 429; bad CSRF -> 403. */
    public function test_failed_attempts_rate_limit_and_csrf(): void
    {
        $this->oneTimePrice('ebook', 1900);
        $link = PaymentLink::create('ebook');
        $ip = '203.0.113.50';

        for ($i = 1; $i <= 5; $i++) {
            $this->at(sprintf('2026-01-15 12:%02d:00', $i * 5));
            $response = $this->submit($link, ['email' => "try$i@example.com", 'name' => 'T'] + self::opaque('tok_decline'), ip: $ip);
            $this->assertSame(402, $response->status, "attempt $i is declined normally");
        }
        $this->at('2026-01-15 12:40:00');
        $sixth = $this->submit($link, ['email' => 'try6@example.com', 'name' => 'T'] + self::opaque('tok_visa'), ip: $ip);
        $this->assertSame(429, $sixth->status);
        $this->assertSame('text/plain; charset=utf-8', $sixth->header('Content-Type'));
        $this->assertSame('Too many attempts. Please wait a few minutes and try again.', $sixth->body);
        $this->assertNotNull($sixth->header('Retry-After'));
        $this->assertSame(5, $this->gateway->chargeCount(), 'the sixth attempt never reaches the gateway');

        // Another IP is unaffected; the blocked IP recovers in the next window.
        $this->assertSame(200, $this->submit($link, ['email' => 'ok@example.com', 'name' => 'O'] + self::opaque('tok_visa'), ip: '203.0.113.51')->status);
        $this->at('2026-01-15 13:00:00');
        $this->assertSame(200, $this->submit($link, ['email' => 'later@example.com', 'name' => 'L'] + self::opaque('tok_visa'), ip: $ip)->status);

        // CSRF.
        $page = new CheckoutPage(self::SESSION);
        $base = ['email' => 'csrf@example.com', 'name' => 'C'] + self::opaque('tok_visa');
        $this->assertSame(403, $page->submit($link->public_token, $base + ['_csrf' => 'forged.' . str_repeat('0', 64)], '203.0.113.60')->status);
        $this->assertSame(403, $page->submit($link->public_token, $base, '203.0.113.60')->status, 'missing token');
        $otherSession = (new Csrf(self::APP_KEY))->issue($link->public_token, 'someone-else', $this->clock->now());
        $this->assertSame(403, $page->submit($link->public_token, $base + ['_csrf' => $otherSession], '203.0.113.60')->status, 'token from another session');
        $stale = (new Csrf(self::APP_KEY))->issue($link->public_token, self::SESSION, $this->clock->now()->modify('-3 hours'));
        $this->assertSame(403, $page->submit($link->public_token, $base + ['_csrf' => $stale], '203.0.113.60')->status, 'expired token');
        $this->assertSame(0, $this->rows('cleat_customers', 'email = ?', ['csrf@example.com']));
    }

    public function test_per_ip_and_per_link_limits(): void
    {
        $this->reconfigure(['rate_limits' => ['per_ip' => [3, 600], 'per_link' => [4, 600]]]);
        $this->oneTimePrice('ebook', 1900);
        $link = PaymentLink::create('ebook');
        $statuses = [];
        foreach (['10.0.0.1', '10.0.0.1', '10.0.0.1', '10.0.0.1'] as $ip) {
            $statuses[] = $this->submit($link, ['email' => 'x@example.com'], ip: $ip)->status;
        }
        $this->assertSame([422, 422, 422, 429], $statuses, 'per IP: 3 per window');
        // The IP-blocked request never counted against the link, so the link has 3 of 4 used.
        $this->assertSame(422, $this->submit($link, ['email' => 'x@example.com'], ip: '10.0.0.2')->status);
        $this->assertSame(429, $this->submit($link, ['email' => 'x@example.com'], ip: '10.0.0.3')->status, 'per link: 4 per window');
    }

    private function submit(PaymentLink $link, array $post, bool $csrfFromShow = true, string $ip = '198.51.100.20'): Response
    {
        if ($csrfFromShow && preg_match('/name="_csrf" value="([^"]+)"/', (new CheckoutPage(self::SESSION))->show($link->public_token)->body, $m) === 1) {
            $post['_csrf'] = $m[1];
        } else {
            $post['_csrf'] = (new Csrf(self::APP_KEY))->issue($link->public_token, self::SESSION, $this->clock->now());
        }
        return (new CheckoutPage(self::SESSION))->submit($link->public_token, $post, $ip);
    }
}
