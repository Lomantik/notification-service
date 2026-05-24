<?php

namespace Tests\Unit\Services\Notification\Gateways;

use App\Exceptions\InvalidRecipientException;
use App\Exceptions\TemporaryGatewayException;
use App\Services\Notification\Gateways\MockEmailGateway;
use Tests\TestCase;

class MockEmailGatewayTest extends TestCase
{
    public function test_throws_for_blocked_email(): void
    {
        $gateway = new MockEmailGateway;

        $this->expectException(InvalidRecipientException::class);
        $this->expectExceptionMessage('Address is blocked by provider.');

        $gateway->send('test@mail.com', 'test');
    }

    public function test_returns_provider_id_for_valid_email(): void
    {
        $gateway = new MockEmailGateway;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                $providerId = $gateway->send('user@example.com', 'hello');
                $this->assertStringStartsWith('email_', $providerId);

                return;
            } catch (TemporaryGatewayException) {
                continue;
            }
        }

        $this->fail('Expected successful email gateway response.');
    }

    public function test_throws_temporary_gateway_exception(): void
    {
        $gateway = new MockEmailGateway;
        $temporaryExceptionThrown = false;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                $gateway->send('user@example.com', 'hello');
            } catch (TemporaryGatewayException) {
                $temporaryExceptionThrown = true;
                break;
            }
        }

        $this->assertTrue($temporaryExceptionThrown);
    }
}
