<?php

declare(strict_types=1);

namespace Cleat;

use Cleat\Support\Db;
use InvalidArgumentException;

/**
 * Fluent invoice builder:
 *
 *   $customer->newInvoice()
 *       ->addItem('Consulting, March', 150000)
 *       ->addPrice('setup-fee', 1)
 *       ->memo('Thanks for your business.')
 *       ->dueIn(14)
 *       ->send();        // finalize + email the pay link. Never charges.
 *
 * ->finalize() opens the invoice without emailing it; ->draft() only saves it.
 */
final class InvoiceBuilder
{
    /** @var list<array{description: string, unit: int, quantity: int, price_id: ?int}> */
    private array $lines = [];
    private ?string $memo = null;
    private int $dueDays = 30;
    private int $tax = 0;
    private ?int $connectedAccountId = null;
    private ?string $applicationFeePercent = null;
    private ?int $paymentLinkId = null;
    private ?string $currency = null;

    public function __construct(private readonly Customer $customer)
    {
    }

    /** A free-form line. $unitCents may be negative for a discount line. */
    public function addItem(string $description, int $unitCents, int $quantity = 1): self
    {
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 500) {
            throw new InvalidArgumentException('A line needs a description of 1-500 characters.');
        }
        if ($quantity < 1) {
            throw new InvalidArgumentException('Line quantity must be at least 1.');
        }
        $this->lines[] = ['description' => $description, 'unit' => $unitCents, 'quantity' => $quantity, 'price_id' => null];
        return $this;
    }

    /** A line for a price, by lookup key or id. */
    public function addPrice(string|int $price, int $quantity = 1): self
    {
        $price = Price::resolve($price);
        if (!$price->is_active) {
            throw new InvalidArgumentException(sprintf('Price "%s" is not active.', $price->lookup_key));
        }
        if ($quantity < 1) {
            throw new InvalidArgumentException('Line quantity must be at least 1.');
        }
        $this->useCurrency($price->currency);
        $this->lines[] = ['description' => $price->label(), 'unit' => $price->amount, 'quantity' => $quantity, 'price_id' => $price->id];
        return $this;
    }

    public function memo(string $text): self
    {
        $this->memo = trim($text) === '' ? null : mb_substr(trim($text), 0, 5000);
        return $this;
    }

    public function dueIn(int $days): self
    {
        if ($days < 0 || $days > 365) {
            throw new InvalidArgumentException('dueIn() takes 0-365 days.');
        }
        $this->dueDays = $days;
        return $this;
    }

    /** Tax in cents, computed by the host. Cleat does not calculate tax. */
    public function tax(int $cents): self
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Tax cannot be negative.');
        }
        $this->tax = $cents;
        return $this;
    }

    /** Bill on behalf of a connected account. Requires a Connect driver. */
    public function onBehalfOf(int $connectedAccountId, ?string $applicationFeePercent = null): self
    {
        Cleat::assertConnectAvailable($connectedAccountId);
        $this->connectedAccountId = $connectedAccountId;
        $this->applicationFeePercent = $applicationFeePercent;
        return $this;
    }

    /** @internal Link the invoice to the payment link that created it. */
    public function forPaymentLink(PaymentLink $link): self
    {
        $this->paymentLinkId = $link->id;
        return $this;
    }

    /** Save as a draft. */
    public function draft(): Invoice
    {
        if ($this->lines === []) {
            throw new InvalidArgumentException('An invoice needs at least one line.');
        }
        Cleat::assertConnectAvailable($this->connectedAccountId);

        return Db::transaction(function (): Invoice {
            $invoice = Invoice::createDraft($this->customer, [
                'payment_link_id' => $this->paymentLinkId,
                'currency' => $this->currency ?? Cleat::config('currency'),
                'tax' => $this->tax,
                'memo' => $this->memo,
                'due_at' => Cleat::now()->modify(sprintf('+%d days', $this->dueDays)),
                'connected_account_id' => $this->connectedAccountId,
            ]);
            foreach ($this->lines as $line) {
                $invoice->addLine($line['description'], $line['unit'], $line['quantity'], $line['price_id']);
            }
            $invoice->recalculate();
            $invoice->setApplicationFeeFromPercent($this->applicationFeePercent);
            return $invoice;
        });
    }

    /** Save and open (assigns the number). Does not email or charge. */
    public function finalize(): Invoice
    {
        return Db::transaction(fn (): Invoice => $this->draft()->finalize());
    }

    /** Save, open, and email the invoice with its pay link. Never charges. */
    public function send(): Invoice
    {
        return $this->finalize()->send();
    }

    private function useCurrency(string $currency): void
    {
        $currency = strtoupper($currency);
        if ($this->currency !== null && $this->currency !== $currency) {
            throw new InvalidArgumentException(sprintf('Cannot mix %s and %s on one invoice.', $this->currency, $currency));
        }
        $this->currency = $currency;
    }
}
