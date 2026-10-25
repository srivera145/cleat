<?php

declare(strict_types=1);

namespace Cleat;

use Cleat\Enums\SubscriptionStatus;
use Cleat\Exceptions\CleatException;
use Cleat\Exceptions\NotFoundException;
use Cleat\Exceptions\PaymentFailedException;
use Cleat\Gateway\StoredCredential;
use Cleat\Support\Db;
use Cleat\Support\Log;
use Cleat\Support\OpaqueData;
use Cleat\Support\Schema;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * A billable customer, bound to an Authorize.net CIM customer profile.
 *
 * user_id is the host app's user. A guest customer (user_id null) is created
 * by a payment link checkout; forUser() can attach a guest to a user later.
 *
 * original_network_transaction_id belongs to the saved card: the card
 * network's id for its first stored-credential transaction, sent with every
 * merchant-initiated charge. It is cleared whenever the card changes.
 */
final class Customer
{
    /** @var array<int, true> customers whose missing original network transaction id was already logged */
    private static array $missingNetworkTransIdLogged = [];

    public function __construct(
        public readonly int $id,
        public ?int $user_id,
        public string $email,
        public ?string $name,
        public ?string $phone,
        public ?string $gateway_customer_id,
        public ?string $gateway_payment_id,
        public ?string $card_brand,
        public ?string $card_last_four,
        public ?string $card_exp,
        public readonly DateTimeImmutable $created_at,
        public DateTimeImmutable $updated_at,
        public ?string $original_network_transaction_id = null,
    ) {
    }

    /**
     * Insert the customer and create its CIM profile. If the CIM call fails
     * nothing is left behind and the exception propagates.
     *
     * @param array{user_id?: ?int, email: string, name?: ?string, phone?: ?string} $attributes
     */
    public static function create(array $attributes): self
    {
        $email = self::normalizeEmail($attributes['email'] ?? null);
        $now = Cleat::now();
        $id = Db::insert('cleat_customers', [
            'user_id' => isset($attributes['user_id']) ? (int) $attributes['user_id'] : null,
            'email' => $email,
            'name' => self::clean($attributes['name'] ?? null, 255),
            'phone' => self::clean($attributes['phone'] ?? null, 32),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $customer = self::findOrFail($id);
        try {
            $customer->ensureGatewayProfile();
        } catch (Throwable $e) {
            Db::run('DELETE FROM cleat_customers WHERE id = ?', [$id]);
            throw $e;
        }
        return $customer;
    }

    public static function find(int $id): ?self
    {
        $row = Db::one('SELECT * FROM cleat_customers WHERE id = ?', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findOrFail(int $id): self
    {
        return self::find($id) ?? throw new NotFoundException("Customer $id not found.");
    }

    /**
     * The customer for a host user. When none exists and the host passes the
     * user's (verified) email, the newest guest customer with that email is
     * attached to the user and returned. Cleat never attaches on its own.
     */
    public static function forUser(int $userId, ?string $claimGuestWithEmail = null): ?self
    {
        $row = Db::one('SELECT * FROM cleat_customers WHERE user_id = ?', [$userId]);
        if ($row !== null) {
            return self::fromRow($row);
        }
        if ($claimGuestWithEmail === null) {
            return null;
        }
        $guest = Db::one(
            'SELECT id FROM cleat_customers WHERE email = ? AND user_id IS NULL ORDER BY id DESC LIMIT 1',
            [self::normalizeEmail($claimGuestWithEmail)],
        );
        if ($guest === null) {
            return null;
        }
        $claimed = Db::run(
            'UPDATE cleat_customers SET user_id = ?, updated_at = ? WHERE id = ? AND user_id IS NULL',
            [$userId, Cleat::now(), (int) $guest['id']],
        )->rowCount();
        return $claimed === 1 ? self::find((int) $guest['id']) : self::forUser($userId);
    }

    /**
     * The existing guest customer with this email, or a new one. Serialized
     * with a named lock so two simultaneous checkouts cannot create two guests.
     */
    public static function findOrCreateGuest(string $email, ?string $name = null, ?string $phone = null): self
    {
        $email = self::normalizeEmail($email);
        $lock = 'cleat:guest:' . sha1(strtolower($email));
        if (!Db::lock($lock, 10)) {
            throw new CleatException('Could not acquire the guest customer lock. Try again.');
        }
        try {
            $row = Db::one('SELECT * FROM cleat_customers WHERE email = ? AND user_id IS NULL ORDER BY id DESC LIMIT 1', [$email]);
            if ($row !== null) {
                $guest = self::fromRow($row);
                $guest->fillContact($name, $phone);
                return $guest;
            }
            return self::create(['email' => $email, 'name' => $name, 'phone' => $phone]);
        } finally {
            Db::unlock($lock);
        }
    }

    /** Always a new guest record, even if one exists for the email. */
    public static function createGuest(string $email, ?string $name = null, ?string $phone = null): self
    {
        return self::create(['email' => $email, 'name' => $name, 'phone' => $phone]);
    }

    /** The CIM customer profile id, created on first use. */
    public function ensureGatewayProfile(): string
    {
        if ($this->gateway_customer_id !== null) {
            return $this->gateway_customer_id;
        }
        $result = Cleat::gateway()->createCustomerProfile('cleat_' . $this->id, $this->email, $this->name);
        $profileId = $result->get('customer_profile_id');
        if (!$result->success || !is_string($profileId) || $profileId === '') {
            throw new CleatException(sprintf(
                'Could not create the gateway customer profile for customer %d (%s).',
                $this->id,
                $result->reasonCode ?? 'unknown',
            ));
        }
        Db::update('cleat_customers', ['gateway_customer_id' => $profileId, 'updated_at' => Cleat::now()], ['id' => $this->id]);
        $this->gateway_customer_id = $profileId;
        return $profileId;
    }

    /**
     * Save a card from Accept.js as the customer's default payment method.
     * The previous payment profile, if any, is deleted from CIM afterwards so
     * the profile never fills up with orphans.
     *
     * @param array{dataDescriptor: string, dataValue: string} $opaqueData
     * @throws PaymentFailedException when the card cannot be saved
     */
    public function updatePaymentMethod(array $opaqueData): self
    {
        $opaque = OpaqueData::from($opaqueData);
        Schema::assertMigrated(Cleat::pdo());
        $profileId = $this->ensureGatewayProfile();
        $result = Cleat::gateway()->createPaymentProfile($profileId, $opaque);
        unset($opaque, $opaqueData);

        $paymentProfileId = $result->get('payment_profile_id');
        if (!$result->success || !is_string($paymentProfileId) || $paymentProfileId === '') {
            throw new PaymentFailedException($result, message: sprintf(
                'Card could not be saved for customer %d (%s).',
                $this->id,
                $result->reasonCode ?? $result->status->value,
            ));
        }

        $previous = $this->gateway_payment_id;
        // A new card starts a new stored-credential history. Its original id is
        // known now only if saving it ran a live validation; otherwise the
        // first approved charge provides it. The same card again keeps its id.
        $networkTransId = $result->get('original_network_transaction_id');
        $networkTransId = is_string($networkTransId) && StoredCredential::isNetworkTransId($networkTransId) ? $networkTransId : null;
        $fields = [
            'gateway_payment_id' => $paymentProfileId,
            'card_brand' => self::clean($result->get('card_brand'), 32),
            'card_last_four' => self::clean($result->get('card_last_four'), 4),
            'card_exp' => self::clean($result->get('card_exp'), 7),
            'original_network_transaction_id' => $paymentProfileId === $previous ? ($this->original_network_transaction_id ?? $networkTransId) : $networkTransId,
            'updated_at' => Cleat::now(),
        ];
        Db::transaction(fn () => Db::update('cleat_customers', $fields, ['id' => $this->id]));
        $this->gateway_payment_id = $fields['gateway_payment_id'];
        $this->card_brand = $fields['card_brand'];
        $this->card_last_four = $fields['card_last_four'];
        $this->card_exp = $fields['card_exp'];
        $this->original_network_transaction_id = $fields['original_network_transaction_id'];
        $this->updated_at = $fields['updated_at'];

        if ($previous !== null && $previous !== $paymentProfileId) {
            $deleted = Cleat::gateway()->deletePaymentProfile($profileId, $previous);
            if (!$deleted->success) {
                Log::warning('Old CIM payment profile could not be deleted', ['customer_id' => $this->id, 'payment_profile_id' => $previous]);
            }
        }
        return $this;
    }

    /** Forget the stored card locally (e.g. it was deleted in CIM). */
    public function clearPaymentMethod(): self
    {
        Db::update('cleat_customers', [
            'gateway_payment_id' => null, 'card_brand' => null, 'card_last_four' => null, 'card_exp' => null,
            'original_network_transaction_id' => null, 'updated_at' => Cleat::now(),
        ], ['id' => $this->id]);
        $this->gateway_payment_id = $this->card_brand = $this->card_last_four = $this->card_exp = null;
        $this->original_network_transaction_id = null;
        return $this;
    }

    /**
     * @internal Keep the network transaction id of an approved charge of the
     * saved card as the card's original id, unless it already has one. Only
     * while $paymentProfileId (the card that was charged) is still the saved
     * card: a card replaced mid-charge never inherits the old card's id.
     */
    public function rememberNetworkTransactionId(string $paymentProfileId, string $networkTransactionId): void
    {
        if (!StoredCredential::isNetworkTransId($networkTransactionId)) {
            return;
        }
        $updated = Db::run(
            'UPDATE cleat_customers SET original_network_transaction_id = ?, updated_at = ?
             WHERE id = ? AND gateway_payment_id = ? AND original_network_transaction_id IS NULL',
            [$networkTransactionId, Cleat::now(), $this->id, $paymentProfileId],
        )->rowCount();
        if ($updated === 1) {
            $this->original_network_transaction_id = $networkTransactionId;
        }
    }

    /**
     * @internal A merchant-initiated charge is about to go out without the
     * card's original network transaction id (a card saved before 0.1.2, or
     * one never charged since it was saved, e.g. during a trial). Logged once
     * per customer per process; the charge's own id is kept once it is
     * approved, so the notice stops by itself.
     */
    public function noticeMissingNetworkTransactionId(): void
    {
        if (isset(self::$missingNetworkTransIdLogged[$this->id])) {
            return;
        }
        self::$missingNetworkTransIdLogged[$this->id] = true;
        Log::info('Saved card has no original network transaction id yet; merchant-initiated charge sent without one', [
            'customer_id' => $this->id,
            'payment_profile_id' => $this->gateway_payment_id,
        ]);
    }

    /** @internal Forget which notices were logged (Cleat::reset()). */
    public static function resetNotices(): void
    {
        self::$missingNetworkTransIdLogged = [];
    }

    public function hasPaymentMethod(): bool
    {
        return $this->gateway_customer_id !== null && $this->gateway_payment_id !== null;
    }

    /** "Visa ending 4242", or null without a saved card. */
    public function paymentMethodLabel(): ?string
    {
        if (!$this->hasPaymentMethod()) {
            return null;
        }
        $brand = match ($this->card_brand) {
            'AmericanExpress' => 'American Express',
            'MasterCard' => 'Mastercard',
            'DinersClub' => 'Diners Club',
            null, '' => 'Card',
            default => $this->card_brand,
        };
        return $this->card_last_four !== null ? sprintf('%s ending %s', $brand, $this->card_last_four) : $brand;
    }

    public function newSubscription(string $lookupKey): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $lookupKey);
    }

    public function newInvoice(): InvoiceBuilder
    {
        return new InvoiceBuilder($this);
    }

    /**
     * One-time charge for a price on the stored card: invoice, then charge.
     *
     * @param array{connected_account_id?: int, application_fee_percent?: string, memo?: string} $options
     * @throws PaymentFailedException with the open invoice's pay URL
     */
    public function charge(string|int $price, int $quantity = 1, array $options = []): Invoice
    {
        $builder = $this->newInvoice()->addPrice($price, $quantity)->dueIn(0);
        return self::chargeBuilt($builder, $options);
    }

    /**
     * Ad-hoc invoice for an amount, charged to the stored card.
     *
     * @param array{connected_account_id?: int, application_fee_percent?: string, memo?: string} $options
     * @throws PaymentFailedException with the open invoice's pay URL
     */
    public function invoiceFor(string $description, int $amountCents, array $options = []): Invoice
    {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('invoiceFor() needs a positive amount in cents.');
        }
        $builder = $this->newInvoice()->addItem($description, $amountCents)->dueIn(0);
        return self::chargeBuilt($builder, $options);
    }

    /** @return list<Subscription> newest first */
    public function subscriptions(): array
    {
        return array_map(
            [Subscription::class, 'fromRow'],
            Db::all('SELECT * FROM cleat_subscriptions WHERE customer_id = ? ORDER BY id DESC', [$this->id]),
        );
    }

    /** The newest subscription that is not canceled, optionally for one price. */
    public function subscription(?string $lookupKey = null): ?Subscription
    {
        foreach ($this->subscriptions() as $subscription) {
            if ($subscription->status === SubscriptionStatus::Canceled) {
                continue;
            }
            if ($lookupKey === null || $subscription->price()->lookup_key === $lookupKey) {
                return $subscription;
            }
        }
        return null;
    }

    public function subscribed(?string $lookupKey = null): bool
    {
        return $this->subscription($lookupKey)?->active() ?? false;
    }

    /** @return list<Invoice> newest first */
    public function invoices(): array
    {
        return array_map(
            [Invoice::class, 'fromRow'],
            Db::all('SELECT * FROM cleat_invoices WHERE customer_id = ? ORDER BY id DESC', [$this->id]),
        );
    }

    public function refresh(): self
    {
        return self::findOrFail($this->id);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            $row['user_id'] !== null ? (int) $row['user_id'] : null,
            (string) $row['email'],
            $row['name'] !== null ? (string) $row['name'] : null,
            $row['phone'] !== null ? (string) $row['phone'] : null,
            $row['gateway_customer_id'] !== null ? (string) $row['gateway_customer_id'] : null,
            $row['gateway_payment_id'] !== null ? (string) $row['gateway_payment_id'] : null,
            $row['card_brand'] !== null ? (string) $row['card_brand'] : null,
            $row['card_last_four'] !== null ? (string) $row['card_last_four'] : null,
            $row['card_exp'] !== null ? (string) $row['card_exp'] : null,
            Db::date($row['created_at']),
            Db::date($row['updated_at']),
            isset($row['original_network_transaction_id']) ? (string) $row['original_network_transaction_id'] : null,
        );
    }

    /** @param array<string, mixed> $options */
    private static function chargeBuilt(InvoiceBuilder $builder, array $options): Invoice
    {
        if (isset($options['memo'])) {
            $builder->memo((string) $options['memo']);
        }
        if (isset($options['connected_account_id'])) {
            $builder->onBehalfOf((int) $options['connected_account_id'], $options['application_fee_percent'] ?? null);
        }
        $invoice = $builder->finalize();
        $invoice->pay();
        return $invoice->refresh();
    }

    private function fillContact(?string $name, ?string $phone): void
    {
        $fields = [];
        if ($this->name === null && ($n = self::clean($name, 255)) !== null) {
            $fields['name'] = $this->name = $n;
        }
        if (($p = self::clean($phone, 32)) !== null && $p !== $this->phone) {
            $fields['phone'] = $this->phone = $p;
        }
        if ($fields !== []) {
            Db::update('cleat_customers', $fields + ['updated_at' => Cleat::now()], ['id' => $this->id]);
        }
    }

    private static function normalizeEmail(mixed $email): string
    {
        $email = trim((string) $email);
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid email address is required.');
        }
        return $email;
    }

    private static function clean(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
