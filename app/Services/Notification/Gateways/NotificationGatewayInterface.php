<?php

namespace App\Services\Notification\Gateways;

use App\Exceptions\InvalidRecipientException;
use App\Exceptions\TemporaryGatewayException;

interface NotificationGatewayInterface
{
    /**
     * @throws TemporaryGatewayException
     * @throws InvalidRecipientException
     */
    public function send(string $to, string $text): string;
}
