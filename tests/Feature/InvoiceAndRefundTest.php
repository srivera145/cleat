<?php

declare(strict_types=1);

namespace Cleat\Tests\Feature;

use Cleat\Cleat;
use Cleat\Customer;
use Cleat\Enums\ChargeStatus;
use Cleat\Enums\InvoiceStatus;
use Cleat\Enums\RefundMethod;
use Cleat\Events\ChargeRefunded;
use Cleat\Exceptions\ChargeInProgressException;
use Cleat\Exceptions\CleatException;
use Cleat\Exceptions\PaymentFailedException;
use Cleat\Exceptions\RefundFailedException;
use Cleat\Invoice;
use Cleat\Support\Db;
use Cleat\Tests\Support\TestCase;
use InvalidArgumentException;

final class InvoiceAndRefundTest extends TestCase
{
    public function test_numbers_are_sequential_and_drafts_do_not_consume_them(): void
    {
        $customer = Customer::create(['email' => 'n@example.com']);
        $a = $customer->newInvoice()->addItem('A', 100)->finalize();
        $customer->newInvoice()->addItem('Draft', 100)->draft();
        $b = $customer->newInvoice()->addItem('B', 100)->finalize();

        // A finalize that rolls back gives its number back.
        try {
            Db::transaction(function () use ($customer): void {
                $customer->newInvoice()->addItem('Rolled back', 100)->finalize();
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $c = $customer->newInvoice()->addItem('C', 100)->finalize();

        $this->assertSame(['CLT-000001', 'CLT-000002', 'CLT-000003'], [$a->number, $b->number, $c->number]);
    }

    public function test_finalized_lines_are_immutable(): void
    {
        $invoice = Customer::create(['email' => 'i@example.com'])->newInvoice()->addItem('Line', 500)->finalize();
        $this->expectException(CleatException::class);
        $invoice->addLine('Sneaky extra', 999);
    }

    public function test_charge_and_invoice_for_charge_the_stored_card(): void
    {
        $this->oneTimePrice('setup-fee', 5000);
        $customer = $this->customer();
        $one = $customer->charge('setup-fee', 2);
        $this->assertSame(InvoiceStatus::Paid, $one->status);
        $this->assertSame(10000, $one->amount_paid);
        $this->assertSame('inv_' . $one->id . '_attempt_1', $one->charges()[0]->idempotency_key);
        $this->assertSame($one->number, $this->gateway->callsTo('chargeProfile')[0]['args']['invoice_number']);

        $two = $customer->invoiceFor('Rush fee', 2500);
        $this->assertSame(InvoiceStatus::Paid, $two->status);
        $this->assertSame('Rush fee', $two->items()[0]->description);
    }

    public function test_one_off_decline_throws_with_pay_url_and_does_not_schedule_retries(): void
    {
        $customer = $this->customer('d@example.com', 'tok_decline');
        try {
            $customer->invoiceFor('Thing', 1000);
            $this->fail('expected PaymentFailedException');
        } catch (PaymentFailedException $e) {
            $this->assertSame(InvoiceStatus::Open, $e->invoice->status);
            $this->assertSame($e->invoice->paymentUrl(), $e->payUrl);
            $this->assertNull($e->invoice->next_attempt_at);
        }
    }

    public function test_pending_charge_blocks_another_attempt(): void
    {
        $invoice = $this->customer()->newInvoice()->addItem('Line', 1000)->finalize();
        // Simulate a crash after the pending row was committed but before the result was recorded.
        Db::insert('cleat_charges', [
            'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'amount' => 1000, 'status' => 'pending',
            'source' => 'stored_profile', 'idempotency_key' => 'inv_' . $invoice->id . '_attempt_1',
            'created_at' => Cleat::now(), 'updated_at' => Cleat::now(),
        ]);
        $this->expectException(ChargeInProgressException::class);
        try {
            $invoice->pay();
        } finally {
            $this->assertSame(0, $this->gateway->chargeCount(), 'never charged twice');
        }
    }

    public function test_pay_refuses_to_run_inside_an_open_transaction(): void
    {
        $invoice = $this->customer()->newInvoice()->addItem('Line', 1000)->finalize();
        $this->pdo->beginTransaction();
        try {
            $invoice->pay();
            $this->fail('expected CleatException');
        } catch (CleatException $e) {
            $this->assertStringContainsString('must not run inside an open database transaction', $e->getMessage());
        } finally {
            $this->pdo->rollBack();
        }
        $this->assertSame(0, $this->gateway->chargeCount());
    }

    public function test_render_escapes_everything(): void
    {
        $customer = Customer::create(['email' => 'x@example.com', 'name' => '<b>Bold</b> & Co']);
        $invoice = $customer->newInvoice()
            ->addItem('<script>alert(1)</script>', 1000)
            ->memo('"><img src=x onerror=alert(2)>')
            ->finalize();
        $html = $invoice->render();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt; &amp; Co', $html);
        $this->assertStringContainsString($invoice->number, $html);
        $this->assertStringContainsString('@page { size: auto;', $html);
        $this->assertStringContainsString($invoice->paymentUrl(), $html);
    }

    public function test_refund_of_an_unsettled_charge_falls_back_to_void(): void
    {
        $invoice = $this->customer()->newInvoice()->addItem('Line', 7500)->finalize();
        $charge = $invoice->pay();

        $refund = $charge->refund();

        $this->assertTrue($refund->succeeded());
        $this->assertSame(RefundMethod::Void, $refund->method, 'error 54 -> void, and the result says so');
        $this->assertTrue($refund->wasVoid());
        $this->assertSame(ChargeStatus::Voided, $charge->status);
        $this->assertCount(1, $this->gateway->callsTo('refund'));
        $this->assertCount(1, $this->gateway->callsTo('void'));
        $this->assertCount(1, $this->eventsOf(ChargeRefunded::class));
    }

    public function test_refund_of_a_settled_charge_partial_then_full(): void
    {
        $invoice = $this->customer()->newInvoice()->addItem('Line', 7500)->finalize();
        $charge = $invoice->pay();
        $this->gateway->settle();

        $first = $charge->refund(2500);
        $this->assertSame(RefundMethod::Refund, $first->method);
        $this->assertSame(ChargeStatus::Succeeded, $charge->status, 'partially refunded');
        $this->assertSame(2500, $charge->refundedAmount());
        $this->assertSame(2500, $this->gateway->callsTo('refund')[0]['args']['amount']);

        $second = $charge->refund();
        $this->assertSame(5000, $second->amount);
        $this->assertSame(ChargeStatus::Refunded, $charge->status);
        $this->assertSame([], $this->gateway->callsTo('void'));

        $this->expectException(CleatException::class);
        $charge->refund(1);
    }

    public function test_partial_refund_of_an_unsettled_charge_explains_itself(): void
    {
        $charge = $this->customer()->newInvoice()->addItem('Line', 7500)->finalize()->pay();
        try {
            $charge->refund(1000);
            $this->fail('expected RefundFailedException');
        } catch (RefundFailedException $e) {
            $this->assertStringContainsString('has not settled yet', $e->getMessage());
            $this->assertFalse($e->refund->succeeded());
        }
        $this->assertSame(ChargeStatus::Succeeded, $charge->status);
        $this->assertSame(0, $charge->refundedAmount());
    }

    public function test_refund_amount_bounds(): void
    {
        $charge = $this->customer()->newInvoice()->addItem('Line', 1000)->finalize()->pay();
        $this->gateway->settle();
        $this->expectException(InvalidArgumentException::class);
        $charge->refund(1001);
    }

    public function test_void_and_uncollectible_transitions(): void
    {
        $customer = Customer::create(['email' => 'v@example.com']);
        $open = $customer->newInvoice()->addItem('Line', 1000)->finalize();
        $open->void();
        $this->assertSame(InvoiceStatus::Void, $open->status);
        $this->assertNull($open->paymentUrl());

        $paid = $this->customer()->newInvoice()->addItem('Line', 1000)->finalize();
        $paid->pay();
        $this->expectException(CleatException::class);
        $paid->void();
    }

    public function test_send_extends_the_link_and_resend_works(): void
    {
        $invoice = Customer::create(['email' => 'r@example.com'])->newInvoice()->addItem('Line', 1000)->send();
        $first = $invoice->link_expires_at;
        $this->at('2026-02-01 12:00:00');
        $invoice->send();
        $this->assertGreaterThan($first, $invoice->link_expires_at);
        $this->assertCount(2, $this->mailer->sent);
    }

    public function test_customers_guests_and_claiming(): void
    {
        $guest = Customer::findOrCreateGuest('guest@example.com', 'Guest');
        $again = Customer::findOrCreateGuest('GUEST@example.com');
        $this->assertSame($guest->id, $again->id, 'emails match case-insensitively');
        $this->assertNull($guest->user_id);
        $this->assertNotNull($guest->gateway_customer_id, 'CIM profile created');

        $this->assertNull(Customer::forUser(77));
        $claimed = Customer::forUser(77, 'guest@example.com');
        $this->assertSame($guest->id, $claimed->id);
        $this->assertSame(77, $claimed->user_id);
        $this->assertSame($guest->id, Customer::forUser(77)->id);
    }

    public function test_update_payment_method_replaces_and_deletes_the_old_profile(): void
    {
        $customer = $this->customer('swap@example.com', 'tok_visa');
        $old = $customer->gateway_payment_id;
        $customer->updatePaymentMethod(self::opaque('tok_amex'));
        $this->assertNotSame($old, $customer->gateway_payment_id);
        $this->assertSame('American Express ending 0005', $customer->paymentMethodLabel());
        $this->assertSame($old, $this->gateway->callsTo('deletePaymentProfile')[0]['args']['payment_profile_id']);

        $this->expectException(PaymentFailedException::class);
        $customer->updatePaymentMethod(self::opaque('tok_invalid'));
    }

    public function test_raw_card_numbers_are_rejected_everywhere(): void
    {
        $customer = Customer::create(['email' => 'pan@example.com']);
        foreach ([
            ['dataDescriptor' => 'x', 'dataValue' => '4111111111111111'],
            ['dataDescriptor' => 'x', 'dataValue' => '4111 1111 1111 1111'],
            ['dataDescriptor' => 'x', 'dataValue' => 'tok', 'cardNumber' => '4111111111111111'],
        ] as $bad) {
            try {
                $customer->updatePaymentMethod($bad);
                $this->fail('raw PAN accepted');
            } catch (InvalidArgumentException) {
            }
        }
        $invoice = $customer->newInvoice()->addItem('Line', 100)->finalize();
        try {
            $invoice->payWithToken(['dataDescriptor' => 'x', 'dataValue' => '5555555555554444'], false, '1.2.3.4');
            $this->fail('raw PAN accepted');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame([], $this->gateway->callsTo('createPaymentProfile'));
        $this->assertSame(0, $this->gateway->chargeCount());
    }

    public function test_zero_total_invoice_is_paid_without_a_charge(): void
    {
        $invoice = Customer::create(['email' => 'z@example.com'])->newInvoice()->addItem('Free', 0)->finalize();
        $this->assertNull($invoice->pay());
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(0, $this->gateway->chargeCount());
        $this->assertInstanceOf(Invoice::class, $invoice);
    }
}
