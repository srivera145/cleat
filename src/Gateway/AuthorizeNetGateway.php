<?php

declare(strict_types=1);

namespace Cleat\Gateway;

use Cleat\Enums\GatewayStatus;
use Cleat\Support\Log;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Authorize.net over its JSON API (api.authorize.net/xml/v1/request.api).
 *
 * Things this class is careful about:
 *   - The API is XML underneath, so element order in every request follows
 *     the XSD. PHP arrays keep insertion order; the builders below are
 *     written in schema order on purpose.
 *   - Responses start with a UTF-8 BOM, stripped before json_decode.
 *   - Amounts in responses are quoted before decoding, so they arrive as
 *     decimal strings and never pass through a float.
 *   - Request bodies carry the transaction key and opaque card data, so they
 *     are never logged or stored. Only response codes are logged.
 *   - 30 second timeout. A timeout returns GatewayResult::timeout() and is
 *     never retried here.
 */
final class AuthorizeNetGateway implements GatewayInterface
{
    public const SANDBOX_URL = 'https://apitest.authorize.net/xml/v1/request.api';
    public const PRODUCTION_URL = 'https://api.authorize.net/xml/v1/request.api';

    private const AMOUNT_KEYS = '/"(authAmount|settleAmount|amount|requestedAmount|balanceOnCard)"\s*:\s*(-?\d+(?:\.\d+)?)/';

    /** @var callable(string, string, int): array{status: int, body: string, errno: int, error: string} */
    private $transport;

    /**
     * @param (callable(string $url, string $jsonBody, int $timeout): array{status: int, body: string, errno: int, error: string})|null $transport
     *        Injectable for tests. Defaults to cURL.
     */
    public function __construct(
        private readonly string $loginId,
        #[SensitiveParameter] private readonly string $transactionKey,
        private readonly bool $sandbox = true,
        ?callable $transport = null,
        private readonly int $timeoutSeconds = 30,
    ) {
        $this->transport = $transport ?? self::curlTransport(...);
    }

    public function createCustomerProfile(string $merchantCustomerId, string $email, ?string $description = null): GatewayResult
    {
        $profile = ['merchantCustomerId' => self::clip($merchantCustomerId, 20)];
        if ($description !== null && trim($description) !== '') {
            $profile['description'] = self::clip($description, 255);
        }
        $profile['email'] = self::clip($email, 255);

        $r = $this->call('createCustomerProfileRequest', ['profile' => $profile]);
        if ($r instanceof GatewayResult) {
            return $r;
        }
        if (self::resultOk($r) && isset($r['customerProfileId'])) {
            return GatewayResult::ok(['customer_profile_id' => (string) $r['customerProfileId']], $r);
        }
        // E00039: a profile with these details already exists. Reuse it.
        if (self::messageCode($r) === 'E00039' && preg_match('/ID (\d+)/', self::messageText($r), $m) === 1) {
            return GatewayResult::ok(['customer_profile_id' => $m[1], 'duplicate' => true], $r);
        }
        return $this->failure('createCustomerProfileRequest', $r);
    }

    public function createPaymentProfile(string $profileId, array $opaqueData): GatewayResult
    {
        $r = $this->call('createCustomerPaymentProfileRequest', [
            'customerProfileId' => $profileId,
            'paymentProfile' => [
                'payment' => [
                    'opaqueData' => [
                        'dataDescriptor' => $opaqueData['dataDescriptor'],
                        'dataValue' => $opaqueData['dataValue'],
                    ],
                ],
                'defaultPaymentProfile' => true,
            ],
            'validationMode' => $this->sandbox ? 'testMode' : 'liveMode',
        ]);
        unset($opaqueData);
        if ($r instanceof GatewayResult) {
            return $r;
        }

        $paymentProfileId = isset($r['customerPaymentProfileId']) ? (string) $r['customerPaymentProfileId'] : null;
        $duplicate = self::messageCode($r) === 'E00039' && $paymentProfileId !== null;
        if (!(self::resultOk($r) && $paymentProfileId !== null) && !$duplicate) {
            return $this->failure('createCustomerPaymentProfileRequest', $r);
        }

        $data = ['payment_profile_id' => $paymentProfileId, 'duplicate' => $duplicate];
        $details = $this->call('getCustomerPaymentProfileRequest', [
            'customerProfileId' => $profileId,
            'customerPaymentProfileId' => $paymentProfileId,
            'unmaskExpirationDate' => true,
        ]);
        if (is_array($details) && self::resultOk($details)) {
            $card = $details['paymentProfile']['payment']['creditCard'] ?? [];
            $digits = preg_replace('/\D/', '', (string) ($card['cardNumber'] ?? '')) ?? '';
            $data['card_last_four'] = strlen($digits) >= 4 ? substr($digits, -4) : null;
            $data['card_brand'] = isset($card['cardType']) ? (string) $card['cardType'] : null;
            $exp = (string) ($card['expirationDate'] ?? '');
            $data['card_exp'] = preg_match('/^\d{4}-\d{2}$/D', $exp) === 1 ? $exp : null;
        } else {
            Log::warning('Authorize.net: payment profile created but card details could not be read', ['payment_profile_id' => $paymentProfileId]);
        }
        return GatewayResult::ok($data, $r);
    }

    public function deletePaymentProfile(string $profileId, string $paymentProfileId): GatewayResult
    {
        $r = $this->call('deleteCustomerPaymentProfileRequest', [
            'customerProfileId' => $profileId,
            'customerPaymentProfileId' => $paymentProfileId,
        ]);
        if ($r instanceof GatewayResult) {
            return $r;
        }
        return self::resultOk($r) ? GatewayResult::ok([], $r) : $this->failure('deleteCustomerPaymentProfileRequest', $r);
    }

    public function chargeProfile(
        string $profileId,
        string $paymentProfileId,
        int $amountCents,
        string $invoiceNumber,
        string $idempotencyKey,
    ): GatewayResult {
        return $this->transaction($idempotencyKey, [
            'transactionType' => 'authCaptureTransaction',
            'amount' => self::amount($amountCents),
            'profile' => [
                'customerProfileId' => $profileId,
                'paymentProfile' => ['paymentProfileId' => $paymentProfileId],
            ],
            'order' => ['invoiceNumber' => self::clip($invoiceNumber, 20)],
        ]);
    }

    public function chargeToken(
        array $opaqueData,
        int $amountCents,
        string $invoiceNumber,
        string $idempotencyKey,
        ?string $email = null,
        ?string $ip = null,
    ): GatewayResult {
        $tx = [
            'transactionType' => 'authCaptureTransaction',
            'amount' => self::amount($amountCents),
            'payment' => [
                'opaqueData' => [
                    'dataDescriptor' => $opaqueData['dataDescriptor'],
                    'dataValue' => $opaqueData['dataValue'],
                ],
            ],
            'order' => ['invoiceNumber' => self::clip($invoiceNumber, 20)],
        ];
        unset($opaqueData);
        if ($email !== null && $email !== '') {
            $tx['customer'] = ['email' => self::clip($email, 255)];
        }
        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $tx['customerIP'] = $ip;
        }
        return $this->transaction($idempotencyKey, $tx);
    }

    public function refund(string $transactionId, int $amountCents, string $cardLastFour, string $idempotencyKey): GatewayResult
    {
        return $this->transaction($idempotencyKey, [
            'transactionType' => 'refundTransaction',
            'amount' => self::amount($amountCents),
            'payment' => [
                'creditCard' => ['cardNumber' => $cardLastFour, 'expirationDate' => 'XXXX'],
            ],
            'refTransId' => $transactionId,
        ]);
    }

    public function void(string $transactionId, string $idempotencyKey): GatewayResult
    {
        return $this->transaction($idempotencyKey, [
            'transactionType' => 'voidTransaction',
            'refTransId' => $transactionId,
        ]);
    }

    public function getTransaction(string $transactionId): GatewayResult
    {
        $r = $this->call('getTransactionDetailsRequest', ['transId' => $transactionId]);
        if ($r instanceof GatewayResult) {
            return $r;
        }
        if (!self::resultOk($r) || !isset($r['transaction']) || !is_array($r['transaction'])) {
            return $this->failure('getTransactionDetailsRequest', $r);
        }
        $t = $r['transaction'];
        $digits = preg_replace('/\D/', '', (string) ($t['payment']['creditCard']['cardNumber'] ?? '')) ?? '';
        $amount = $t['settleAmount'] ?? $t['authAmount'] ?? null;
        return new GatewayResult(true, GatewayStatus::Ok, (string) ($t['transId'] ?? $transactionId), isset($t['responseCode']) ? (int) $t['responseCode'] : null, null, null, $r, [
            'status' => (string) ($t['transactionStatus'] ?? ''),
            'amount' => $amount !== null ? self::cents((string) $amount) : null,
            'card_last_four' => strlen($digits) >= 4 ? substr($digits, -4) : null,
            'invoice_number' => isset($t['order']['invoiceNumber']) ? (string) $t['order']['invoiceNumber'] : null,
            'ref_transaction_id' => isset($t['refTransId']) && $t['refTransId'] !== '' ? (string) $t['refTransId'] : null,
        ]);
    }

    /**
     * Searches the unsettled transaction list (newest first, up to 1000).
     * Transactions settle roughly daily, so this answers reliably for an
     * attempt made earlier the same day, which is when reconciliation runs:
     * right before the next attempt on the same invoice.
     */
    public function findTransaction(string $invoiceNumber, int $amountCents, \DateTimeImmutable $since): GatewayResult
    {
        $r = $this->call('getUnsettledTransactionListRequest', [
            'sorting' => ['orderBy' => 'submitTimeUTC', 'orderDescending' => true],
            'paging' => ['limit' => 1000, 'offset' => 1],
        ]);
        if ($r instanceof GatewayResult) {
            return $r;
        }
        if (!self::resultOk($r)) {
            return $this->failure('getUnsettledTransactionListRequest', $r);
        }
        $wanted = self::clip($invoiceNumber, 20);
        foreach ((array) ($r['transactions'] ?? []) as $t) {
            if (!is_array($t) || (string) ($t['invoiceNumber'] ?? '') !== $wanted || !isset($t['settleAmount'])) {
                continue;
            }
            if (self::cents((string) $t['settleAmount']) !== $amountCents) {
                continue;
            }
            $submitted = isset($t['submitTimeUTC']) ? new \DateTimeImmutable((string) $t['submitTimeUTC']) : null;
            if ($submitted !== null && $submitted < $since) {
                continue;
            }
            $status = match ((string) ($t['transactionStatus'] ?? '')) {
                'capturedPendingSettlement', 'authorizedPendingCapture' => 'approved',
                'FDSPendingReview', 'FDSAuthorizedPendingReview' => 'held',
                default => 'not_captured',
            };
            return GatewayResult::ok(['transaction_id' => (string) $t['transId'], 'status' => $status], $r);
        }
        return GatewayResult::ok(['transaction_id' => null, 'status' => 'not_captured'], $r);
    }

    /**
     * Strip the BOM, quote amounts, decode. Public so the webhook handler and
     * tests can share it.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $body): ?array
    {
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }
        $body = preg_replace(self::AMOUNT_KEYS, '"$1":"$2"', $body) ?? $body;
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    /** Decimal string ("45.00") to cents, without a float. */
    public static function cents(string $decimal): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', trim($decimal), $m) !== 1) {
            throw new InvalidArgumentException(sprintf('Unexpected amount "%s" from Authorize.net.', $decimal));
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['loginId' => $this->loginId, 'transactionKey' => '[redacted]', 'sandbox' => $this->sandbox];
    }

    /** @param array<string, mixed> $tx */
    private function transaction(string $idempotencyKey, array $tx): GatewayResult
    {
        $type = (string) $tx['transactionType'];
        $r = $this->call('createTransactionRequest', [
            'refId' => self::clip($idempotencyKey, 20),
            'transactionRequest' => $tx,
        ]);
        unset($tx);
        if ($r instanceof GatewayResult) {
            return $r;
        }

        $tr = $r['transactionResponse'] ?? null;
        if (!is_array($tr) || !isset($tr['responseCode'])) {
            // Rejected before it became a transaction: bad credentials, schema error.
            return $this->failure('createTransactionRequest:' . $type, $r);
        }

        $code = (int) $tr['responseCode'];
        $error = $tr['errors'][0] ?? null;
        $message = $tr['messages'][0] ?? null;
        $reasonCode = $error['errorCode'] ?? $message['code'] ?? self::messageCode($r);
        $reasonText = $error['errorText'] ?? $message['description'] ?? self::messageText($r);
        $transId = isset($tr['transId']) && $tr['transId'] !== '' && $tr['transId'] !== '0' ? (string) $tr['transId'] : null;
        $status = match ($code) {
            1 => GatewayStatus::Approved,
            2 => GatewayStatus::Declined,
            4 => GatewayStatus::Held,
            default => GatewayStatus::Error,
        };

        if ($status !== GatewayStatus::Approved) {
            Log::info('Authorize.net transaction not approved', [
                'type' => $type,
                'response_code' => $code,
                'reason_code' => $reasonCode,
                'transaction_id' => $transId,
            ]);
        }

        return new GatewayResult(
            $code === 1,
            $status,
            $transId,
            $code,
            $reasonCode !== null ? (string) $reasonCode : null,
            $reasonText !== null ? (string) $reasonText : null,
            $r,
            [
                'account_number' => $tr['accountNumber'] ?? null,
                'account_type' => $tr['accountType'] ?? null,
                'auth_code' => $tr['authCode'] ?? null,
            ],
        );
    }

    /**
     * @param array<string, mixed> $body request fields after merchantAuthentication, in schema order
     * @return array<string, mixed>|GatewayResult decoded response, or a failure result for transport problems
     */
    private function call(string $root, array $body): array|GatewayResult
    {
        $payload = [$root => ['merchantAuthentication' => ['name' => $this->loginId, 'transactionKey' => $this->transactionKey]] + $body];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        unset($payload, $body);

        $response = ($this->transport)($this->sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL, $json, $this->timeoutSeconds);
        unset($json);

        if ($response['errno'] === 28) { // CURLE_OPERATION_TIMEDOUT
            Log::warning('Authorize.net request timed out', ['request' => $root, 'timeout_seconds' => $this->timeoutSeconds]);
            return GatewayResult::timeout();
        }
        if ($response['errno'] !== 0) {
            Log::error('Authorize.net transport error', ['request' => $root, 'curl_errno' => $response['errno'], 'curl_error' => $response['error']]);
            return GatewayResult::error('network', 'Could not reach the payment gateway.');
        }
        if ($response['status'] !== 200) {
            Log::error('Authorize.net HTTP error', ['request' => $root, 'http_status' => $response['status']]);
            return GatewayResult::error('http_' . $response['status'], 'The payment gateway returned an HTTP error.');
        }
        $decoded = self::decode($response['body']);
        if ($decoded === null) {
            Log::error('Authorize.net returned a response that is not JSON', ['request' => $root]);
            return GatewayResult::error('invalid_response', 'The payment gateway returned an unreadable response.');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $r */
    private function failure(string $request, array $r): GatewayResult
    {
        $code = self::messageCode($r);
        Log::info('Authorize.net request failed', ['request' => $request, 'message_code' => $code]);
        // E00027 is "the transaction was unsuccessful": a decline during card validation.
        $status = $code === 'E00027' ? GatewayStatus::Declined : GatewayStatus::Error;
        return new GatewayResult(false, $status, null, null, $code, self::messageText($r), $r);
    }

    /** @param array<string, mixed> $r */
    private static function resultOk(array $r): bool
    {
        return ($r['messages']['resultCode'] ?? null) === 'Ok';
    }

    /** @param array<string, mixed> $r */
    private static function messageCode(array $r): ?string
    {
        $code = $r['messages']['message'][0]['code'] ?? null;
        return $code !== null ? (string) $code : null;
    }

    /** @param array<string, mixed> $r */
    private static function messageText(array $r): string
    {
        return (string) ($r['messages']['message'][0]['text'] ?? '');
    }

    private static function amount(int $cents): string
    {
        if ($cents <= 0) {
            throw new InvalidArgumentException('Gateway amounts must be positive.');
        }
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function clip(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }

    /** @return array{status: int, body: string, errno: int, error: string} */
    private static function curlTransport(string $url, string $body, int $timeout): array
    {
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
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($ch);
        $result = [
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'body' => is_string($response) ? $response : '',
            'errno' => curl_errno($ch),
            'error' => curl_error($ch),
        ];
        curl_close($ch);
        return $result;
    }
}
