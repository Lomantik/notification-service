<?php

namespace App\Services\Notification\Messaging;

use PhpAmqpLib\Connection\AMQPStreamConnection;

class RabbitMqConnectionFactory
{
    public function createConnection(): AMQPStreamConnection
    {
        $config = config('rabbitmq');

        return new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
        );
    }
}
