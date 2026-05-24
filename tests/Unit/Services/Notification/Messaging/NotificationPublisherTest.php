<?php

namespace Tests\Unit\Services\Notification\Messaging;

use App\Services\Notification\Messaging\NotificationPublisher;
use App\Services\Notification\Messaging\RabbitMqConnectionFactory;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Tests\TestCase;

class NotificationPublisherTest extends TestCase
{
    public function test_publishes_message_to_rabbitmq(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('exchange_declare')->once();
        $channel->shouldReceive('queue_declare')->twice();
        $channel->shouldReceive('queue_bind')->once();
        $channel->shouldReceive('basic_publish')->once();
        $channel->shouldReceive('close')->once();

        $connection = Mockery::mock(AMQPStreamConnection::class);
        $connection->shouldReceive('channel')->once()->andReturn($channel);
        $connection->shouldReceive('close')->once();

        $factory = Mockery::mock(RabbitMqConnectionFactory::class);
        $factory->shouldReceive('createConnection')->once()->andReturn($connection);

        $publisher = new NotificationPublisher($factory);
        $publisher->publish(42, 10);

        $this->addToAssertionCount(1);
    }
}
