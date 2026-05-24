<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\ConsumeNotificationDeliveries;
use App\Enums\NotificationStatus;
use App\Exceptions\TemporaryGatewayException;
use App\Services\Notification\Messaging\RabbitMqConnectionFactory;
use App\Services\Notification\NotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use RuntimeException;
use Tests\Support\CreatesNotificationFixtures;
use Tests\Support\FakesAmqpMessages;
use Tests\TestCase;

class ConsumeNotificationDeliveriesTest extends TestCase
{
    use CreatesNotificationFixtures;
    use FakesAmqpMessages;

    /** @var callable|null */
    private $consumerCallback;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_consumes_message_and_acknowledges_success(): void
    {
        $delivery = $this->createDeliveryWithUser($this->createUser());

        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('sendNotification')->once()->with($delivery->id);

        $this->runConsumer($service, function (AMQPChannel $channel) use ($delivery): void {
            $message = $this->fakeAmqpMessage(['notification_delivery_id' => $delivery->id], $channel);
            ($this->consumerCallback)($message);
        });
    }

    public function test_acknowledges_message_without_delivery_id(): void
    {
        $service = Mockery::mock(NotificationService::class);
        $service->shouldNotReceive('sendNotification');

        $this->runConsumer($service, function (AMQPChannel $channel): void {
            $message = $this->fakeAmqpMessage(['timestamp' => time()], $channel);
            ($this->consumerCallback)($message);
        });
    }

    public function test_accepts_numeric_string_delivery_id(): void
    {
        $delivery = $this->createDeliveryWithUser($this->createUser());

        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('sendNotification')->once()->with($delivery->id);

        $this->runConsumer($service, function (AMQPChannel $channel) use ($delivery): void {
            $message = $this->fakeAmqpMessage(['notification_delivery_id' => (string) $delivery->id], $channel);
            ($this->consumerCallback)($message);
        });
    }

    public function test_retries_on_temporary_gateway_exception(): void
    {
        $delivery = $this->createDeliveryWithUser($this->createUser());

        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('sendNotification')->once()->with($delivery->id)
            ->andThrow(new TemporaryGatewayException('temporary down'));

        $published = false;

        $this->runConsumer(
            $service,
            function (AMQPChannel $channel) use ($delivery): void {
                $message = $this->fakeAmqpMessage(['notification_delivery_id' => $delivery->id], $channel, priority: 5);
                ($this->consumerCallback)($message);
            },
            function (AMQPChannel $channel) use (&$published): void {
                $channel->shouldReceive('basic_publish')->once()->andReturnUsing(function () use (&$published): void {
                    $published = true;
                });
            },
        );

        $this->assertTrue($published);
    }

    public function test_retries_with_existing_retry_count_and_priority_header(): void
    {
        $delivery = $this->createDeliveryWithUser($this->createUser());

        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('sendNotification')->once()->andThrow(new TemporaryGatewayException('temporary down'));

        $this->runConsumer(
            $service,
            function (AMQPChannel $channel) use ($delivery): void {
                $message = $this->fakeAmqpMessage(
                    ['notification_delivery_id' => $delivery->id],
                    $channel,
                    priority: 8,
                    retryCount: 1,
                );
                ($this->consumerCallback)($message);
            },
            function (AMQPChannel $channel): void {
                $channel->shouldReceive('basic_publish')->once();
            },
        );
    }

    public function test_drops_delivery_when_retry_limit_reached(): void
    {
        $delivery = $this->createDeliveryWithUser($this->createUser());

        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('sendNotification')->once()->andThrow(new TemporaryGatewayException('temporary down'));
        $service->shouldReceive('dropNotification')->once()->with($delivery->id);

        $this->runConsumer($service, function (AMQPChannel $channel) use ($delivery): void {
            $message = $this->fakeAmqpMessage(
                ['notification_delivery_id' => $delivery->id],
                $channel,
                retryCount: config('rabbitmq.max_retries'),
            );
            ($this->consumerCallback)($message);
        });
    }

    public function test_acknowledges_when_delivery_not_found(): void
    {
        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('sendNotification')->once()->with(99999)
            ->andThrow(new ModelNotFoundException);

        $this->runConsumer($service, function (AMQPChannel $channel): void {
            $message = $this->fakeAmqpMessage(['notification_delivery_id' => 99999], $channel);
            ($this->consumerCallback)($message);
        });
    }

    public function test_drops_delivery_on_permanent_failure(): void
    {
        $delivery = $this->createDeliveryWithUser($this->createUser());

        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('sendNotification')->once()->with($delivery->id)
            ->andThrow(new RuntimeException('permanent failure'));
        $service->shouldReceive('dropNotification')->once()->with($delivery->id);

        $this->runConsumer($service, function (AMQPChannel $channel) use ($delivery): void {
            $message = $this->fakeAmqpMessage(['notification_delivery_id' => $delivery->id], $channel);
            ($this->consumerCallback)($message);
        });
    }

    public function test_waits_until_consuming_stops(): void
    {
        $service = Mockery::mock(NotificationService::class);

        $this->runConsumer(
            $service,
            function (AMQPChannel $channel): void {
                $this->assertNotNull($this->consumerCallback);
            },
            function (AMQPChannel $channel): void {
                $channel->shouldReceive('is_consuming')->andReturn(true, false);
                $channel->shouldReceive('wait')->once();
            },
        );

        $this->assertTrue(true);
    }

    public function test_drop_notification_through_consumer_updates_database(): void
    {
        $delivery = $this->createDeliveryWithUser($this->createUser());

        $notificationService = Mockery::mock(NotificationService::class)->makePartial();
        $notificationService->shouldReceive('sendNotification')->once()->andThrow(new TemporaryGatewayException('down'));
        $notificationService->shouldReceive('dropNotification')->once()->passthru();

        $this->runConsumer(
            $notificationService,
            function (AMQPChannel $channel) use ($delivery): void {
                $message = $this->fakeAmqpMessage(
                    ['notification_delivery_id' => $delivery->id],
                    $channel,
                    retryCount: config('rabbitmq.max_retries'),
                );
                ($this->consumerCallback)($message);
            },
            function (AMQPChannel $channel): void {
                $channel->shouldReceive('basic_publish')->never();
            },
        );

        $this->assertSame(NotificationStatus::DROPPED, $delivery->fresh()->status);
    }

    /**
     * @param  callable(AMQPChannel): void  $invokeCallback
     * @param  (callable(AMQPChannel): void)|null  $configureChannel
     */
    private function runConsumer(
        NotificationService $service,
        callable $invokeCallback,
        ?callable $configureChannel = null,
    ): void {
        $channel = $this->mockChannel($configureChannel);
        $connection = Mockery::mock(AMQPStreamConnection::class);
        $connection->shouldReceive('channel')->once()->andReturn($channel);
        $connection->shouldReceive('close')->once();

        $factory = Mockery::mock(RabbitMqConnectionFactory::class);
        $factory->shouldReceive('createConnection')->once()->andReturn($connection);

        $command = new ConsumeNotificationDeliveries;
        $command->setLaravel($this->app);
        $this->bindCommandOutput($command);
        $command->handle($factory, $service);

        $invokeCallback($channel);

        $this->assertNotNull($this->consumerCallback);
    }

    /**
     * @param  (callable(AMQPChannel): void)|null  $configure
     */
    private function mockChannel(?callable $configure = null): AMQPChannel
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('exchange_declare')->once();
        $channel->shouldReceive('queue_declare')->twice();
        $channel->shouldReceive('queue_bind')->once();
        $channel->shouldReceive('basic_qos')->once();
        $channel->shouldReceive('basic_consume')->once()->andReturnUsing(function (...$args): void {
            $this->consumerCallback = $args[6];
        });
        $channel->shouldReceive('is_consuming')->byDefault()->andReturn(false);
        $channel->shouldReceive('close')->once();

        if ($configure) {
            $configure($channel);
        }

        return $channel;
    }
}
