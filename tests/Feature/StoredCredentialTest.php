<?php

declare(strict_types=1);

namespace Cleat\Tests\Feature;

use Cleat\Charge;
use Cleat\Customer;
use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\SubscriptionStatus;
use Cleat\Exceptions\PaymentFailedException;
use Cleat\Gateway\StoredCredential;
use Cleat\Http\CheckoutPage;
use Cleat\Http\Csrf;
use Cleat\Http\InvoicePayPage;
use Cleat\Invoice;
use Cleat\PaymentLink;
use Cleat\Support\Db;
use Cleat\Tests\Support\TestCase;

/**
 * Card-on-file indicators on every charge, and the network transaction ids
 * Cleat keeps for later merchant-initiated charges. FakeGateway records the
 * StoredCredential each call was given, so these assert the exact flags.
 */
final class StoredCredentialTest extends TestCase
{
    private const SESSION = 'cof-session';
    private const IP = '198.51.100.30';
    private const NOTICE = 'Saved card has no original network transaction id yet; merchant-initiated charge sent without one';

    // --- First customer-initiated charge -------------------------------------

    public function test_new_subscription_with_a_card_is_the_first_cit_and_keeps_its_network_id(): void
    {
        $this->monthlyPrice();
        $customer = Customer::create(['email' => 'new@example.com', 'name' => 'New']);

        $subscription = $customer->newSubscription('premium-monthly')->create(self::opaque('tok_visa'));

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertEquals([StoredCredential::firstCustomerInitiated(recurring: true)], $this->credentialsSent());
        $charge = $subscription->latestInvoice()->charges()[0];
        $this->assertMatchesRegularExpression('/^NTI[0-9A-F]{16}$/', (string) $charge->network_transaction_id, 'stored on the charge');
        $this->assertSame($charge->network_transaction_id, $customer->refresh()->original_network_transaction_id, 'and on the saved card');
    }

    public function test_checkout_with_a_recurring_price_is_the_first_cit(): void
    {
        $this->monthlyPrice('solo-monthly', 1500);
        $link = PaymentLink::create('solo-monthly');

        $response = $this->checkout($link, ['email' => 'solo@example.com', 'name' => 'Sol'] + self::opaque('tok_visa'));

        $this->assertSame(200, $response->status);
        $this->assertEquals([StoredCredential::firstCustomerInitiated(recurring: true)], $this->credentialsSent());
        $customer = $this->customerByEmail('solo@example.com');
        $this->assertNotNull($customer->original_network_transaction_id);
        $this->assertSame($customer->invoices()[0]->charges()[0]->network_transaction_id, $customer->original_network_transaction_id);
    }

    public function test_pay_page_save_this_card_is_the_first_cit(): void
    {
        $customer = Customer::create(['email' => 'client@example.com', 'name' => 'Client']);
        $invoice = $customer->newInvoice()->addItem('Design work', 45000)->send();

        $this->payPage($invoice, ['payment_method' => 'new', 'save_card' => '1'] + self::opaque('tok_mastercard'));

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertEquals([StoredCredential::firstCustomerInitiated(recurring: false)], $this->credentialsSent());
        $this->assertSame($invoice->charges()[0]->network_transaction_id, $customer->refresh()->original_network_transaction_id);
    }

    public function test_pay_page_save_this_card_on_a_subscription_invoice_starts_the_recurring_series(): void
    {
        $this->monthlyPrice();
        $customer = $this->customer();
        $subscription = $customer->newSubscription('premium-monthly')->create();
        $this->gateway->setPaymentProfileToken($customer->gateway_customer_id, $customer->gateway_payment_id, 'tok_decline');
        $this->runAt('2026-02-15 12:00:00');
        $invoice = $subscription->refresh()->latestInvoice();
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->gateway->calls = [];

        $this->payPage($invoice, ['payment_method' => 'new', 'save_card' => '1'] + self::opaque('tok_visa'));

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertEquals([StoredCredential::firstCustomerInitiated(recurring: true)], $this->credentialsSent());
        $newCard = $customer->refresh();
        $this->assertSame($invoice->charges()[1]->network_transaction_id, $newCard->original_network_transaction_id, 'the new card has its own id');
    }

    // --- Later customer-initiated charge --------------------------------------

    public function test_paying_later_with_the_saved_card_is_a_cit_with_stored_credentials(): void
    {
        $customer = $this->customer('saved@example.com');
        $first = $customer->newInvoice()->addItem('First', 1000)->send();
        $this->payPage($first, ['payment_method' => 'saved']);
        $original = $customer->refresh()->original_network_transaction_id;
        $this->assertNotNull($original);

        $second = $customer->newInvoice()->addItem('Second', 2000)->send();
        $this->payPage($second, ['payment_method' => 'saved']);

        $this->assertSame(InvoiceStatus::Paid, $second->refresh()->status);
        $this->assertEquals([StoredCredential::customerInitiated(), StoredCredential::customerInitiated()], $this->credentialsSent(),
            'no original id and no reason on a CIT');
        $this->assertNotSame($original, $second->charges()[0]->network_transaction_id);
        $this->assertSame($original, $customer->refresh()->original_network_transaction_id, 'the first id is kept');
    }

    // --- Merchant-initiated: renewals, trial conversions, retries ---------------

    public function test_renewal_is_a_recurring_mit_with_the_original_network_id(): void
    {
        $this->monthlyPrice();
        $customer = Customer::create(['email' => 'renew@example.com', 'name' => 'Renew']);
        $subscription = $customer->newSubscription('premium-monthly')->create(self::opaque('tok_visa'));
        $original = $customer->refresh()->original_network_transaction_id;

        $this->runAt('2026-02-15 12:00:00');
        $this->runAt('2026-03-15 12:00:00');

        $this->assertEquals([
            StoredCredential::firstCustomerInitiated(recurring: true),
            StoredCredential::recurring()->withOriginalNetworkTransId($original),
            StoredCredential::recurring()->withOriginalNetworkTransId($original),
        ], $this->credentialsSent());
        $this->assertSame(3, count($subscription->refresh()->invoices()));
        $this->assertSame($original, $customer->refresh()->original_network_transaction_id);
        $this->assertNoNotice();
    }

    public function test_trial_conversion_is_a_recurring_mit_with_the_id_from_a_live_validation(): void
    {
        $this->monthlyPrice(trialDays: 7);
        $this->gateway->liveValidation = true; // production: saving the card runs a liveMode validation
        $customer = Customer::create(['email' => 'trial@example.com', 'name' => 'Trial']);
        $subscription = $customer->newSubscription('premium-monthly')->create(self::opaque('tok_visa'));
        $validation = $customer->refresh()->original_network_transaction_id;
        $this->assertNotNull($validation, 'kept from the validation');
        $this->assertSame(0, $this->gateway->chargeCount());

        $this->runAt('2026-01-22 12:00:00');

        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertEquals([StoredCredential::recurring()->withOriginalNetworkTransId($validation)], $this->credentialsSent());
        $this->assertSame($validation, $customer->refresh()->original_network_transaction_id);
        $this->assertNoNotice();
    }

    public function test_dunning_retry_is_a_resubmission_mit_with_the_original_network_id(): void
    {
        $this->monthlyPrice();
        $customer = $this->customer();
        $customer->newSubscription('premium-monthly')->create();
        $original = $customer->refresh()->original_network_transaction_id;
        $this->gateway->setPaymentProfileToken($customer->gateway_customer_id, $customer->gateway_payment_id, 'tok_decline');

        $this->runAt('2026-02-15 12:00:00'); // renewal declines
        $this->runAt('2026-02-16 12:00:00'); // retry 1 declines
        $this->gateway->setPaymentProfileToken($customer->gateway_customer_id, $customer->gateway_payment_id, 'tok_visa');
        $this->runAt('2026-02-18 12:00:00'); // retry 2 approves

        $this->assertEquals([
            StoredCredential::firstCustomerInitiated(recurring: true),
            StoredCredential::recurring()->withOriginalNetworkTransId($original),
            StoredCredential::resubmission()->withOriginalNetworkTransId($original),
            StoredCredential::resubmission()->withOriginalNetworkTransId($original),
        ], $this->credentialsSent());
        $keys = array_map(static fn (array $c) => $c['args']['idempotency_key'], $this->gateway->callsTo('chargeProfile'));
        $this->assertMatchesRegularExpression('/_attempt_3$/', $keys[3]);
        $this->assertSame(SubscriptionStatus::Active, $customer->subscription()->status);
    }

    public function test_a_renewal_picked_up_from_its_lease_is_still_a_recurring_mit(): void
    {
        $this->monthlyPrice();
        $customer = $this->customer();
        $subscription = $customer->newSubscription('premium-monthly')->create();
        $original = $customer->refresh()->original_network_transaction_id;
        // The runner claimed the next period and died before charging: an open
        // invoice with its one-hour lease and no attempt yet.
        $invoice = Db::transaction(fn () => $subscription->invoicePeriod($this->at('2026-02-15 12:00:00'), $this->at('2026-03-15 12:00:00')));
        Db::update('cleat_invoices', ['next_attempt_at' => $this->at('2026-02-15 13:00:00')], ['id' => $invoice->id]);
        $this->assertSame(0, $invoice->refresh()->attempt_count);

        $this->runAt('2026-02-15 13:00:00');

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertEquals(StoredCredential::recurring()->withOriginalNetworkTransId($original), $this->credentialsSent()[1]);
    }

    public function test_charges_the_host_makes_are_unscheduled_mits(): void
    {
        $this->monthlyPrice();
        $this->monthlyPrice('premium-plus', 4900, product: $this->product('Plus'));
        $this->oneTimePrice('setup-fee', 5000);
        $customer = $this->customer();
        $subscription = $customer->newSubscription('premium-monthly')->create();
        $original = $customer->refresh()->original_network_transaction_id;

        $customer->charge('setup-fee');
        $customer->invoiceFor('Rush fee', 2500);
        $subscription->swap('premium-plus');

        $mit = StoredCredential::unscheduled()->withOriginalNetworkTransId($original);
        $this->assertEquals([StoredCredential::firstCustomerInitiated(recurring: true), $mit, $mit, $mit], $this->credentialsSent());
    }

    // --- No flags --------------------------------------------------------------

    public function test_one_time_token_charge_with_save_off_sends_no_stored_credential_flags(): void
    {
        $customer = $this->customer();
        $original = $customer->original_network_transaction_id;
        $invoice = $customer->newInvoice()->addItem('One-off', 1900)->send();

        $this->payPage($invoice, ['payment_method' => 'new'] + self::opaque('tok_mastercard'));

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame([], $this->gateway->callsTo('chargeProfile'));
        $this->assertCount(1, $this->gateway->callsTo('chargeToken'));
        $this->assertNull($this->gateway->callsTo('chargeToken')[0]['args']['stored_credential']);
        $this->assertNotNull($invoice->charges()[0]->network_transaction_id, 'every charge keeps its network id');
        $this->assertSame($original, $customer->refresh()->original_network_transaction_id, 'a card not saved never touches the saved card');
    }

    public function test_one_time_checkout_sends_no_stored_credential_flags(): void
    {
        $this->oneTimePrice('ebook', 1900);
        $link = PaymentLink::create('ebook');

        $this->checkout($link, ['email' => 'once@example.com', 'name' => 'Once'] + self::opaque('tok_visa'));

        $this->assertSame([], $this->gateway->callsTo('chargeProfile'));
        $this->assertNull($this->gateway->callsTo('chargeToken')[0]['args']['stored_credential']);
        $this->assertNull($this->customerByEmail('once@example.com')->original_network_transaction_id);
    }

    // --- Missing original id -----------------------------------------------------

    public function test_card_saved_before_0_1_2_gets_mit_flags_without_the_id_and_one_notice(): void
    {
        $this->monthlyPrice();
        $customer = $this->customer();
        $customer->newSubscription('premium-monthly')->create();
        // A card saved and first charged by 0.1.1: no original id on file.
        Db::update('cleat_customers', ['original_network_transaction_id' => null], ['id' => $customer->id]);
        $this->gateway->setPaymentProfileToken($customer->gateway_customer_id, $customer->gateway_payment_id, 'tok_decline');

        $this->runAt('2026-02-15 12:00:00'); // renewal declines
        $this->runAt('2026-02-16 12:00:00'); // retry 1 declines
        $this->assertCount(1, $this->notices(), 'logged once per customer, not once per charge');
        $this->assertSame(['customer_id' => $customer->id, 'payment_profile_id' => $customer->gateway_payment_id], $this->notices()[0]['context']);

        $this->gateway->setPaymentProfileToken($customer->gateway_customer_id, $customer->gateway_payment_id, 'tok_visa');
        $this->runAt('2026-02-18 12:00:00'); // retry 2 approves: its id becomes the card's
        $healed = $customer->refresh()->original_network_transaction_id;
        $this->assertNotNull($healed);
        $this->runAt('2026-03-15 12:00:00'); // next renewal carries it

        $this->assertEquals([
            StoredCredential::firstCustomerInitiated(recurring: true),
            StoredCredential::recurring(),
            StoredCredential::resubmission(),
            StoredCredential::resubmission(),
            StoredCredential::recurring()->withOriginalNetworkTransId($healed),
        ], $this->credentialsSent());
        $this->assertCount(1, $this->notices());
        $this->assertSame(InvoiceStatus::Paid, $customer->subscription()->latestInvoice()->status);
    }

    public function test_trial_conversion_of_a_card_never_charged_goes_without_the_id_then_keeps_its_own(): void
    {
        $this->monthlyPrice(trialDays: 7); // sandbox: testMode validation, so no id at save
        $customer = Customer::create(['email' => 'trial@example.com', 'name' => 'Trial']);
        $customer->newSubscription('premium-monthly')->create(self::opaque('tok_visa'));
        $this->assertNull($customer->refresh()->original_network_transaction_id);

        $this->runAt('2026-01-22 12:00:00');
        $converted = $customer->refresh()->original_network_transaction_id;
        $this->runAt('2026-02-22 12:00:00');

        $this->assertEquals([
            StoredCredential::recurring(),
            StoredCredential::recurring()->withOriginalNetworkTransId($converted),
        ], $this->credentialsSent());
        $this->assertCount(1, $this->notices());
    }

    public function test_notice_is_not_repeated_when_the_gateway_returns_no_network_id(): void
    {
        $this->gateway->returnNetworkTransIds = false;
        $customer = $this->customer();

        $customer->invoiceFor('One', 1000);
        $customer->invoiceFor('Two', 1000);

        $this->assertEquals([StoredCredential::unscheduled(), StoredCredential::unscheduled()], $this->credentialsSent());
        $this->assertCount(1, $this->notices());
        $this->assertNull($customer->refresh()->original_network_transaction_id);
    }

    // --- The id belongs to the card ------------------------------------------------

    public function test_a_new_card_starts_without_the_old_cards_id(): void
    {
        $customer = $this->customer();
        $customer->invoiceFor('Order', 1000);
        $this->assertNotNull($customer->refresh()->original_network_transaction_id);

        $customer->updatePaymentMethod(self::opaque('tok_mastercard'));
        $this->assertNull($customer->original_network_transaction_id);
        $this->assertNull($customer->refresh()->original_network_transaction_id);

        $this->gateway->liveValidation = true;
        $customer->updatePaymentMethod(self::opaque('tok_visa'));
        $validated = $customer->refresh()->original_network_transaction_id;
        $this->assertNotNull($validated, 'a live validation provides it at once');

        $customer->clearPaymentMethod();
        $this->assertNull($customer->refresh()->original_network_transaction_id);
    }

    public function test_a_card_replaced_while_a_charge_is_in_flight_does_not_inherit_its_id(): void
    {
        $customer = $this->customer();
        $invoice = $customer->newInvoice()->addItem('Order', 1000)->finalize();
        $this->gateway->whileCharging = function () use ($customer): void {
            Customer::findOrFail($customer->id)->updatePaymentMethod(self::opaque('tok_mastercard'));
        };

        $charge = $invoice->pay();

        $this->assertNotNull($charge->network_transaction_id);
        $replaced = $customer->refresh();
        $this->assertSame('4444', $replaced->card_last_four);
        $this->assertNull($replaced->original_network_transaction_id, 'the old card\'s id is not the new card\'s');
    }

    public function test_a_declined_first_charge_stores_nothing_on_the_card(): void
    {
        $this->monthlyPrice();
        $customer = Customer::create(['email' => 'declined@example.com', 'name' => 'D']);

        try {
            $customer->newSubscription('premium-monthly')->create(self::opaque('tok_decline'));
            $this->fail('expected a decline');
        } catch (PaymentFailedException $e) {
            $this->assertNotNull($e->charge);
        }

        $this->assertNull(Charge::findOrFail($e->charge->id)->network_transaction_id);
        $this->assertNull($customer->refresh()->original_network_transaction_id);
    }

    // --- Helpers -----------------------------------------------------------------

    /** @return list<?StoredCredential> */
    private function credentialsSent(): array
    {
        return array_map(static fn (array $c) => $c['args']['stored_credential'], $this->gateway->callsTo('chargeProfile'));
    }

    /** @return list<array{level: string, message: string, context: array}> */
    private function notices(): array
    {
        return array_values(array_filter($this->logs, static fn (array $l) => $l['message'] === self::NOTICE));
    }

    private function assertNoNotice(): void
    {
        $this->assertSame([], $this->notices());
    }

    private function customerByEmail(string $email): Customer
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cleat_customers WHERE email = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$email]);
        return Customer::fromRow($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    private function payPage(Invoice $invoice, array $post): \Cleat\Http\Response
    {
        $post['_csrf'] = (new Csrf(self::APP_KEY))->issue($invoice->public_token, self::SESSION, $this->clock->now());
        return (new InvoicePayPage(self::SESSION))->submit($invoice->public_token, $post, self::IP);
    }

    private function checkout(PaymentLink $link, array $post): \Cleat\Http\Response
    {
        $post['_csrf'] = (new Csrf(self::APP_KEY))->issue($link->public_token, self::SESSION, $this->clock->now());
        return (new CheckoutPage(self::SESSION))->submit($link->public_token, $post, self::IP);
    }
}
