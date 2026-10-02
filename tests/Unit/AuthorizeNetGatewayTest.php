<?php

declare(strict_types=1);

namespace Cleat\Tests\Unit;

use Cleat\Enums\GatewayStatus;
use Cleat\Gateway\AuthorizeNetGateway;
use Cleat\Support\Log;
use PHPUnit\Framework\TestCase;

/**
 * The Authorize.net driver against a recorded transport: request shape and
 * element order, BOM handling, response mapping, timeouts, and that no
 * secret or card token ever reaches a log. This does not talk to the real
 * sandbox (see README: unverified items).
 */
final class AuthorizeNetGatewayTest extends TestCase
{
    private const BOM = "\xEF\xBB\xBF";
    private const KEY = 'SECRET-transaction-key-9z';

    /** @var list<array{url: string, body: array}> */
    private array $requests = [];
    /** @var list<array{status: int, body: string, errno: int, error: string}> */
    private array $responses = [];
    /** @var list<array> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->requests = $this->responses = $this->logs = [];
        Log::setLogger(function (string $level, string $message, array $context): void {
            $this->logs[] = [$level, $message, $context];
        });
    }

    protected function tearDown(): void
    {
        Log::setLogger(null);
    }

    public function test_charge_profile_request_shape_and_approved_mapping(): void
    {
        $this->respond(['transactionResponse' => [
            'responseCode' => '1', 'authCode' => 'ABC123', 'transId' => '60012345678', 'accountNumber' => 'XXXX4242', 'accountType' => 'Visa',
            'messages' => [['code' => '1', 'description' => 'This transaction has been approved.']],
        ], 'refId' => 'inv_12_attempt_1', 'messages' => ['resultCode' => 'Ok', 'message' => [['code' => 'I00001', 'text' => 'Successful.']]]]);

        $result = $this->gateway()->chargeProfile('900001', '800001', 2900, 'CLT-000012-EXTRA-LONG-NUMBER', 'inv_12_attempt_1_and_more_text');

        $this->assertTrue($result->success);
        $this->assertSame(GatewayStatus::Approved, $result->status);
        $this->assertSame('60012345678', $result->transactionId);
        $this->assertSame(1, $result->responseCode);
        $this->assertSame('1', $result->reasonCode);

        $req = $this->requests[0];
        $this->assertSame(AuthorizeNetGateway::SANDBOX_URL, $req['url']);
        $root = $req['body']['createTransactionRequest'];
        $this->assertSame(['merchantAuthentication', 'refId', 'transactionRequest'], array_keys($root), 'XSD element order');
        $this->assertSame(['name' => 'login-id', 'transactionKey' => self::KEY], $root['merchantAuthentication']);
        $this->assertSame('inv_12_attempt_1_and', $root['refId'], 'refId is the first 20 chars of the idempotency key');
        $tx = $root['transactionRequest'];
        $this->assertSame(['transactionType', 'amount', 'profile', 'order'], array_keys($tx));
        $this->assertSame('authCaptureTransaction', $tx['transactionType']);
        $this->assertSame('29.00', $tx['amount']);
        $this->assertSame(['customerProfileId' => '900001', 'paymentProfile' => ['paymentProfileId' => '800001']], $tx['profile']);
        $this->assertSame('CLT-000012-EXTRA-LON', $tx['order']['invoiceNumber'], 'invoiceNumber max 20 chars');
    }

    public function test_charge_token_passes_email_and_ip_in_order(): void
    {
        $this->respond($this->tx('1', '60000000001'));
        $this->gateway()->chargeToken(['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'eyJjb2RlIjoiNTBfMl8w-secret'], 1050, 'CLT-000001', 'inv_1_customer_1', 'buyer@example.com', '203.0.113.5');
        $tx = $this->requests[0]['body']['createTransactionRequest']['transactionRequest'];
        $this->assertSame(['transactionType', 'amount', 'payment', 'order', 'customer', 'customerIP'], array_keys($tx));
        $this->assertSame('10.50', $tx['amount']);
        $this->assertSame(['email' => 'buyer@example.com'], $tx['customer']);
        $this->assertSame('203.0.113.5', $tx['customerIP']);
    }

    public function test_response_codes_map_to_statuses(): void
    {
        $this->respond($this->tx('2', '60000000002', error: ['2', 'This transaction has been declined.']));
        $this->respond($this->tx('3', '0', error: ['6', 'The credit card number is invalid.']));
        $this->respond($this->tx('4', '60000000004', message: ['252', 'Your order has been received.']));
        $g = $this->gateway();

        $declined = $g->chargeProfile('1', '2', 100, 'CLT-1', 'k1');
        $this->assertFalse($declined->success);
        $this->assertTrue($declined->isDeclined());
        $this->assertSame('2', $declined->reasonCode);

        $error = $g->chargeProfile('1', '2', 100, 'CLT-1', 'k2');
        $this->assertTrue($error->isError());
        $this->assertSame(3, $error->responseCode);
        $this->assertNull($error->transactionId, 'transId "0" means none');

        $held = $g->chargeProfile('1', '2', 100, 'CLT-1', 'k3');
        $this->assertFalse($held->success, 'held is not paid');
        $this->assertTrue($held->isHeld());
        $this->assertSame('60000000004', $held->transactionId);
    }

    public function test_request_level_failure_maps_to_error(): void
    {
        $this->respond(['messages' => ['resultCode' => 'Error', 'message' => [['code' => 'E00007', 'text' => 'User authentication failed due to invalid authentication values.']]]]);
        $result = $this->gateway()->chargeProfile('1', '2', 100, 'CLT-1', 'k');
        $this->assertTrue($result->isError());
        $this->assertSame('E00007', $result->reasonCode);
    }

    public function test_bom_is_stripped_and_amounts_never_become_floats(): void
    {
        $raw = self::BOM . '{"transaction":{"transId":"60011","transactionStatus":"settledSuccessfully","responseCode":1,"settleAmount":45.5,"authAmount":45.50,'
            . '"payment":{"creditCard":{"cardNumber":"XXXX1111","cardType":"Visa"}},"order":{"invoiceNumber":"CLT-000009"},"refTransId":"60010"},'
            . '"messages":{"resultCode":"Ok","message":[{"code":"I00001","text":"Successful."}]}}';
        $this->responses[] = ['status' => 200, 'body' => $raw, 'errno' => 0, 'error' => ''];

        $result = $this->gateway()->getTransaction('60011');

        $this->assertTrue($result->success);
        $this->assertSame(4550, $result->get('amount'));
        $this->assertSame('1111', $result->get('card_last_four'));
        $this->assertSame('CLT-000009', $result->get('invoice_number'));
        $this->assertSame('60010', $result->get('ref_transaction_id'));
        $this->assertSame('45.5', $result->raw['transaction']['settleAmount'], 'kept as a decimal string');
        $this->assertNull(json_decode($raw, true), 'raw JSON with BOM does not decode without stripping');
    }

    public function test_timeout_returns_timeout_and_logs_no_secrets(): void
    {
        $this->responses[] = ['status' => 0, 'body' => '', 'errno' => 28, 'error' => 'Operation timed out after 30001 milliseconds'];
        $result = $this->gateway()->chargeToken(['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'OPAQUE-VALUE-123'], 100, 'CLT-1', 'k');
        $this->assertTrue($result->isTimeout());
        $this->assertSame('timeout', $result->reasonCode);
        $this->assertFalse($result->success);
        $this->assertNotEmpty($this->logs);
        $this->assertNoSecretsLogged();
    }

    public function test_duplicate_customer_profile_is_reused(): void
    {
        $this->respond(['messages' => ['resultCode' => 'Error', 'message' => [['code' => 'E00039', 'text' => 'A duplicate record with ID 1505583123 already exists.']]]]);
        $result = $this->gateway()->createCustomerProfile('cleat_15', 'a@example.com', 'Ann');
        $this->assertTrue($result->success);
        $this->assertSame('1505583123', $result->get('customer_profile_id'));
        $profile = $this->requests[0]['body']['createCustomerProfileRequest']['profile'];
        $this->assertSame(['merchantCustomerId', 'description', 'email'], array_keys($profile));
    }

    public function test_payment_profile_then_card_details(): void
    {
        $this->respond(['customerProfileId' => '1505', 'customerPaymentProfileId' => '1504', 'validationDirectResponse' => '1,1,1,This transaction has been approved.', 'messages' => ['resultCode' => 'Ok', 'message' => [['code' => 'I00001', 'text' => 'Successful.']]]]);
        $this->respond(['paymentProfile' => ['customerPaymentProfileId' => '1504', 'payment' => ['creditCard' => ['cardNumber' => 'XXXX4242', 'expirationDate' => '2029-07', 'cardType' => 'Visa']]], 'messages' => ['resultCode' => 'Ok', 'message' => [['code' => 'I00001', 'text' => 'Successful.']]]]);

        $result = $this->gateway(sandbox: false)->createPaymentProfile('1505', ['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'OPAQUE-VALUE-456']);

        $this->assertSame('1504', $result->get('payment_profile_id'));
        $this->assertSame('Visa', $result->get('card_brand'));
        $this->assertSame('4242', $result->get('card_last_four'));
        $this->assertSame('2029-07', $result->get('card_exp'));
        $create = $this->requests[0]['body']['createCustomerPaymentProfileRequest'];
        $this->assertSame(AuthorizeNetGateway::PRODUCTION_URL, $this->requests[0]['url']);
        $this->assertSame(['merchantAuthentication', 'customerProfileId', 'paymentProfile', 'validationMode'], array_keys($create));
        $this->assertSame('liveMode', $create['validationMode'], 'liveMode outside the sandbox');
        $this->assertTrue($create['paymentProfile']['defaultPaymentProfile']);
        $this->assertTrue($this->requests[1]['body']['getCustomerPaymentProfileRequest']['unmaskExpirationDate']);
        $this->assertNoSecretsLogged();

        $this->respond(['customerPaymentProfileId' => '1', 'messages' => ['resultCode' => 'Ok', 'message' => [['code' => 'I00001', 'text' => 'ok']]]]);
        $this->respond(['messages' => ['resultCode' => 'Error', 'message' => [['code' => 'E00040', 'text' => 'not found']]]]);
        $this->gateway()->createPaymentProfile('1', ['dataDescriptor' => 'd', 'dataValue' => 'v']);
        $this->assertSame('testMode', $this->requests[2]['body']['createCustomerPaymentProfileRequest']['validationMode'], 'testMode in the sandbox');
    }

    public function test_refund_and_void_requests(): void
    {
        $this->respond($this->tx('3', '0', error: ['54', 'The referenced transaction does not meet the criteria for issuing a credit.']));
        $this->respond($this->tx('1', '60000000009'));
        $g = $this->gateway();
        $refund = $g->refund('60000000001', 2500, '4242', 'ch_1_refund_1');
        $this->assertSame('54', $refund->reasonCode);
        $tx = $this->requests[0]['body']['createTransactionRequest']['transactionRequest'];
        $this->assertSame(['transactionType', 'amount', 'payment', 'refTransId'], array_keys($tx));
        $this->assertSame(['cardNumber' => '4242', 'expirationDate' => 'XXXX'], $tx['payment']['creditCard']);

        $void = $g->void('60000000001', 'ch_1_refund_1');
        $this->assertTrue($void->success);
        $this->assertSame(['transactionType' => 'voidTransaction', 'refTransId' => '60000000001'], $this->requests[1]['body']['createTransactionRequest']['transactionRequest']);
    }

    public function test_find_transaction_searches_the_unsettled_list(): void
    {
        $list = ['transactions' => [
            ['transId' => '600003', 'submitTimeUTC' => '2026-01-15T12:01:00Z', 'transactionStatus' => 'capturedPendingSettlement', 'invoiceNumber' => 'CLT-000002', 'settleAmount' => 10.00],
            ['transId' => '600002', 'submitTimeUTC' => '2026-01-15T12:00:30Z', 'transactionStatus' => 'FDSPendingReview', 'invoiceNumber' => 'CLT-000001', 'settleAmount' => 42.00],
            ['transId' => '600001', 'submitTimeUTC' => '2026-01-15T12:00:10Z', 'transactionStatus' => 'capturedPendingSettlement', 'invoiceNumber' => 'CLT-000001', 'settleAmount' => 99.00],
        ], 'messages' => ['resultCode' => 'Ok', 'message' => [['code' => 'I00001', 'text' => 'Successful.']]]];
        $since = new \DateTimeImmutable('2026-01-15 11:50:00', new \DateTimeZone('UTC'));
        $this->respond($list);
        $this->respond($list);
        $this->respond($list);
        $this->respond(['messages' => ['resultCode' => 'Error', 'message' => [['code' => 'E00001', 'text' => 'An error occurred during processing.']]]]);
        $g = $this->gateway();

        $held = $g->findTransaction('CLT-000001', 4200, $since);
        $this->assertTrue($held->success);
        $this->assertSame(['transaction_id' => '600002', 'status' => 'held'], $held->data);

        $approved = $g->findTransaction('CLT-000001', 9900, $since);
        $this->assertSame('600001', $approved->get('transaction_id'));
        $this->assertSame('approved', $approved->get('status'));

        $none = $g->findTransaction('CLT-000009', 4200, $since);
        $this->assertTrue($none->success);
        $this->assertNull($none->get('transaction_id'));

        $this->assertFalse($g->findTransaction('CLT-000001', 4200, $since)->success, 'a failed lookup is not "not found"');
        $req = $this->requests[0]['body']['getUnsettledTransactionListRequest'];
        $this->assertSame(['merchantAuthentication', 'sorting', 'paging'], array_keys($req));
    }

    public function test_debug_output_hides_the_transaction_key(): void
    {
        $this->assertStringNotContainsString(self::KEY, print_r($this->gateway(), true));
        $this->assertStringNotContainsString(self::KEY, var_export($this->gateway()->__debugInfo(), true));
    }

    private function gateway(bool $sandbox = true): AuthorizeNetGateway
    {
        return new AuthorizeNetGateway('login-id', self::KEY, $sandbox, function (string $url, string $body, int $timeout): array {
            $this->assertSame(30, $timeout);
            $this->requests[] = ['url' => $url, 'body' => json_decode($body, true)];
            return array_shift($this->responses) ?? ['status' => 500, 'body' => '', 'errno' => 0, 'error' => ''];
        });
    }

    private function respond(array $json): void
    {
        $this->responses[] = ['status' => 200, 'body' => self::BOM . json_encode($json), 'errno' => 0, 'error' => ''];
    }

    private function tx(string $code, string $transId, ?array $error = null, ?array $message = null): array
    {
        $tr = ['responseCode' => $code, 'transId' => $transId];
        if ($error !== null) {
            $tr['errors'] = [['errorCode' => $error[0], 'errorText' => $error[1]]];
        }
        $tr['messages'] = [['code' => $message[0] ?? '1', 'description' => $message[1] ?? 'This transaction has been approved.']];
        return ['transactionResponse' => $tr, 'messages' => ['resultCode' => $code === '1' ? 'Ok' : 'Error', 'message' => [['code' => $code === '1' ? 'I00001' : 'E00027', 'text' => 'x']]]];
    }

    private function assertNoSecretsLogged(): void
    {
        $dump = json_encode($this->logs);
        foreach ([self::KEY, 'OPAQUE-VALUE', 'eyJjb2RlIjoiNTBfMl8w'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $dump);
        }
    }
}
