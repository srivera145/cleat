<?php

declare(strict_types=1);

namespace Cleat\Tests\Unit;

use Cleat\Gateway\StoredCredential;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StoredCredentialTest extends TestCase
{
    public function test_factories(): void
    {
        $first = StoredCredential::firstCustomerInitiated(recurring: true);
        $this->assertSame([StoredCredential::CUSTOMER, StoredCredential::RECURRING, true, null, null],
            [$first->initiator, $first->type, $first->isFirst, $first->originalNetworkTransId, $first->reason]);
        $this->assertSame('cit first recurring', $first->label());

        $this->assertSame('cit first unscheduled', StoredCredential::firstCustomerInitiated(recurring: false)->label());
        $this->assertSame('cit unscheduled', StoredCredential::customerInitiated()->label());
        $this->assertSame('cit recurring', StoredCredential::customerInitiated(recurring: true)->label());
        $this->assertSame('mit recurring', StoredCredential::recurring()->label());
        $this->assertSame('mit unscheduled', StoredCredential::unscheduled()->label());

        $retry = StoredCredential::resubmission()->withOriginalNetworkTransId('123456789NNNH');
        $this->assertTrue($retry->isMerchantInitiated());
        $this->assertFalse($retry->isRecurring());
        $this->assertSame('resubmission', $retry->reason);
        $this->assertSame('123456789NNNH', $retry->originalNetworkTransId);
        $this->assertSame('mit unscheduled resubmission', $retry->label());
        $this->assertNull(StoredCredential::recurring()->withOriginalNetworkTransId(null)->originalNetworkTransId);
    }

    public function test_original_id_belongs_to_merchant_initiated_charges_only(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StoredCredential::customerInitiated()->withOriginalNetworkTransId('123456789NNNH');
    }

    public function test_network_ids_must_have_the_documented_shape(): void
    {
        $this->assertTrue(StoredCredential::isNetworkTransId('0TN1VE648DFCJSHQ81GZH9F'));
        $this->assertTrue(StoredCredential::isNetworkTransId(str_repeat('A', 255)));
        $this->assertFalse(StoredCredential::isNetworkTransId(''));
        $this->assertFalse(StoredCredential::isNetworkTransId(str_repeat('A', 256)));
        $this->assertFalse(StoredCredential::isNetworkTransId('abc-123'));
        $this->expectException(InvalidArgumentException::class);
        StoredCredential::recurring()->withOriginalNetworkTransId("abc\n123");
    }
}
