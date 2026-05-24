<?php

namespace Tests\Unit\Services\Notification\Gateways;

use App\Exceptions\InvalidRecipientException;
use App\Exceptions\TemporaryGatewayException;
use App\Services\Notification\Gateways\MockEmailGateway;
use App\Services\Notification\Gateways\MockSmsGateway;
use Tests\TestCase;

class MockSmsGatewayTest extends TestCase
{
    public function test_throws_for_blocked_phone_number(): void
    {
        $gateway = new MockSmsGateway;

        $this->expectException(InvalidRecipientException::class);
        $this->expectExceptionMessage('Number is blocked by provider.');

        $gateway->send('+79990000000', 'test');
    }

    public function test_returns_provider_id_for_valid_number(): void
    {
        $gateway = new MockSmsGateway;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                $providerId = $gateway->send('+79991112233', 'hello');
                $this->assertStringStartsWith('sms_', $providerId);

                return;
            } catch (TemporaryGatewayException) {
                continue;
            }
        }

        $this->fail('Expected successful sms gateway response.');
    }

    public function test_throws_temporary_gateway_exception(): void
    {
        $gateway = new MockSmsGateway;
        $temporaryExceptionThrown = false;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                $gateway->send('+79991112233', 'hello');
            } catch (TemporaryGatewayException) {
                $temporaryExceptionThrown = true;
                break;
            }
        }

        $this->assertTrue($temporaryExceptionThrown);
    }
}
