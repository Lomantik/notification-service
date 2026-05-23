<?php

namespace App\Services\Notification\Gateways;

use App\Exceptions\GatewayTimeoutException;
use App\Exceptions\InvalidRecipientException;

interface NotificationGatewayInterface
{
    /**
     * @throws GatewayTimeoutException (для эмуляции ретраев)
     * @throws InvalidRecipientException (для статуса dropped)
     */
    public function send(string $to, string $text): string;
}
