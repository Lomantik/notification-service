<?php

namespace App\Services\Notification;

use App\Enums\NotificationChannel;
use App\Services\Notification\Gateways\MockEmailGateway;
use App\Services\Notification\Gateways\MockSmsGateway;
use App\Services\Notification\Gateways\NotificationGatewayInterface;

class GatewayResolver
{
    public function __construct(
        protected MockSmsGateway $smsGateway,
        protected MockEmailGateway $emailGateway,
    ) {}

    public function resolve(NotificationChannel $channel): NotificationGatewayInterface
    {
        return match ($channel) {
            NotificationChannel::SMS => $this->smsGateway,
            NotificationChannel::EMAIL => $this->emailGateway,
        };
    }
}
