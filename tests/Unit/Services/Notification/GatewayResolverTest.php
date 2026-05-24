<?php

namespace Tests\Unit\Services\Notification;

use App\Enums\NotificationChannel;
use App\Services\Notification\GatewayResolver;
use App\Services\Notification\Gateways\MockEmailGateway;
use App\Services\Notification\Gateways\MockSmsGateway;
use Tests\TestCase;

class GatewayResolverTest extends TestCase
{
    public function test_resolves_sms_gateway(): void
    {
        $resolver = new GatewayResolver(new MockSmsGateway, new MockEmailGateway);

        $gateway = $resolver->resolve(NotificationChannel::SMS);

        $this->assertInstanceOf(MockSmsGateway::class, $gateway);
    }

    public function test_resolves_email_gateway(): void
    {
        $resolver = new GatewayResolver(new MockSmsGateway, new MockEmailGateway);

        $gateway = $resolver->resolve(NotificationChannel::EMAIL);

        $this->assertInstanceOf(MockEmailGateway::class, $gateway);
    }
}
