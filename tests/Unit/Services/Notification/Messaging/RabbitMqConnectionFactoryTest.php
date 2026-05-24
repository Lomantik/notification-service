<?php

namespace Tests\Unit\Services\Notification\Messaging;

use App\Services\Notification\Messaging\RabbitMqConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Tests\TestCase;

class RabbitMqConnectionFactoryTest extends TestCase
{
    public function test_creates_amqp_connection(): void
    {
        $factory = new RabbitMqConnectionFactory;

        $connection = $factory->createConnection();

        $this->assertInstanceOf(AMQPStreamConnection::class, $connection);
        $connection->close();
    }
}
