<?php

namespace App\Services\Notification\Gateways;

use App\Exceptions\InvalidRecipientException;
use App\Exceptions\TemporaryGatewayException;
use Illuminate\Support\Str;

class MockEmailGateway implements NotificationGatewayInterface
{
    /**
     * @throws InvalidRecipientException
     * @throws TemporaryGatewayException
     */
    public function send(string $to, string $text): string
    {
        usleep(200000);

        if ($to === 'test@mail.com') {
            throw new InvalidRecipientException('Address is blocked by provider.');
        }

        if (rand(1, 10) === 7) {
            throw new TemporaryGatewayException('Gateway is temporary unavailable.');
        }

        $randomString = strtolower(Str::random(15));

        return "email_$randomString";
    }
}
