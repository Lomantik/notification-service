<?php

namespace Tests\Unit\Services\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Exceptions\InvalidRecipientException;
use App\Exceptions\TemporaryGatewayException;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Services\Notification\GatewayResolver;
use App\Services\Notification\Gateways\NotificationGatewayInterface;
use App\Services\Notification\Messaging\NotificationPublisher;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\CreatesNotificationFixtures;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use CreatesNotificationFixtures;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_user_notifications_returns_cached_array(): void
    {
        $user = $this->createUser();
        $this->createDeliveryWithUser($user);

        $service = app(NotificationService::class);

        $first = $service->getUserNotifications($user);
        $second = $service->getUserNotifications($user);

        $this->assertIsArray($first);
        $this->assertCount(1, $first);
        $this->assertSame($first, $second);
    }

    public function test_process_notification_creates_deliveries_and_publishes(): void
    {
        $publisher = Mockery::mock(NotificationPublisher::class);
        $publisher->shouldReceive('publish')->twice();

        $service = $this->makeService($publisher);

        $userOne = $this->createUser();
        $userTwo = $this->createUser();

        $deliveries = $service->processNotification(
            $this->idempotencyKey(),
            NotificationChannel::SMS,
            'Bulk message',
            [$userTwo->id, $userOne->id],
            9,
        );

        $this->assertCount(2, $deliveries);
        $this->assertSame([$userOne->id, $userTwo->id], $deliveries->pluck('user_id')->values()->all());
        $this->assertDatabaseHas('notifications', [
            'channel' => NotificationChannel::SMS->value,
            'text' => 'Bulk message',
            'priority' => 9,
        ]);
        $this->assertDatabaseCount('notification_deliveries', 2);
    }

    public function test_process_notification_is_idempotent_and_republishes_processing_deliveries(): void
    {
        $publisher = Mockery::mock(NotificationPublisher::class);
        $publisher->shouldReceive('publish')->twice();

        $service = $this->makeService($publisher);
        $key = $this->idempotencyKey();
        $user = $this->createUser();

        $service->processNotification($key, NotificationChannel::EMAIL, 'Once', [$user->id]);
        $service->processNotification($key, NotificationChannel::EMAIL, 'Once', [$user->id]);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_deliveries', 1);
    }

    public function test_send_notification_marks_delivery_as_sent(): void
    {
        $user = $this->createUser(['phone' => '+79991112233']);
        $delivery = $this->createDeliveryWithUser($user, NotificationChannel::SMS);

        $gateway = Mockery::mock(NotificationGatewayInterface::class);
        $gateway->shouldReceive('send')->once()->with('+79991112233', $delivery->notification->text)->andReturn('sms_test123');

        $service = $this->makeService(gatewayResolver: $this->mockGatewayResolver($gateway, NotificationChannel::SMS));
        $service->sendNotification($delivery->id);

        $delivery->refresh();
        $this->assertSame(NotificationStatus::SENT, $delivery->status);
        $this->assertSame('sms_test123', $delivery->provider_id);
        $this->assertSame($delivery->id, (int) Cache::get('provider_delivery:sms_test123'));
    }

    public function test_send_notification_uses_email_gateway(): void
    {
        $user = $this->createUser(['email' => 'user@example.com']);
        $delivery = $this->createDeliveryWithUser($user, NotificationChannel::EMAIL);

        $gateway = Mockery::mock(NotificationGatewayInterface::class);
        $gateway->shouldReceive('send')->once()->with('user@example.com', $delivery->notification->text)->andReturn('email_test123');

        $service = $this->makeService(gatewayResolver: $this->mockGatewayResolver($gateway, NotificationChannel::EMAIL));
        $service->sendNotification($delivery->id);

        $delivery->refresh();
        $this->assertSame(NotificationStatus::SENT, $delivery->status);
    }

    public function test_send_notification_skips_when_lock_not_acquired(): void
    {
        $delivery = $this->createDeliveryWithUser($this->createUser());

        Cache::lock("delivery_lock:{$delivery->id}", 300)->get();

        $gateway = Mockery::mock(NotificationGatewayInterface::class);
        $gateway->shouldNotReceive('send');

        $service = $this->makeService(gatewayResolver: $this->mockGatewayResolver($gateway));
        $service->sendNotification($delivery->id);

        $this->assertSame(NotificationStatus::PROCESSING, $delivery->fresh()->status);
    }

    public function test_send_notification_skips_terminal_statuses(): void
    {
        $delivery = $this->createDeliveryWithUser(
            $this->createUser(),
            NotificationChannel::SMS,
            NotificationStatus::SENT,
            'sms_done',
        );

        $gateway = Mockery::mock(NotificationGatewayInterface::class);
        $gateway->shouldNotReceive('send');

        $service = $this->makeService(gatewayResolver: $this->mockGatewayResolver($gateway));
        $service->sendNotification($delivery->id);

        $this->assertSame(NotificationStatus::SENT, $delivery->fresh()->status);
    }

    public function test_send_notification_skips_delivered_and_dropped_statuses(): void
    {
        foreach ([NotificationStatus::DELIVERED, NotificationStatus::DROPPED] as $status) {
            $delivery = $this->createDeliveryWithUser(
                $this->createUser(),
                NotificationChannel::SMS,
                $status,
                'sms_terminal',
            );

            $gateway = Mockery::mock(NotificationGatewayInterface::class);
            $gateway->shouldNotReceive('send');

            $service = $this->makeService(gatewayResolver: $this->mockGatewayResolver($gateway));
            $service->sendNotification($delivery->id);

            $this->assertSame($status, $delivery->fresh()->status);
        }
    }

    public function test_send_notification_returns_when_notification_missing(): void
    {
        $delivery = $this->createDeliveryWithUser($this->createUser());

        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_notification_id_foreign');
        $delivery->update(['notification_id' => 99999]);

        $gateway = Mockery::mock(NotificationGatewayInterface::class);
        $gateway->shouldNotReceive('send');

        $service = $this->makeService(gatewayResolver: $this->mockGatewayResolver($gateway));
        $service->sendNotification($delivery->id);

        $this->assertSame(NotificationStatus::PROCESSING, $delivery->fresh()->status);
    }

    public function test_process_notification_does_not_publish_completed_deliveries_on_replay(): void
    {
        $publisher = Mockery::mock(NotificationPublisher::class);
        $publisher->shouldReceive('publish')->once();

        $service = $this->makeService($publisher);
        $key = $this->idempotencyKey();
        $user = $this->createUser();

        $service->processNotification($key, NotificationChannel::SMS, 'Replay', [$user->id]);
        NotificationDelivery::query()->first()?->update(['status' => NotificationStatus::SENT]);
        $service->processNotification($key, NotificationChannel::SMS, 'Replay', [$user->id]);

        $this->assertSame(NotificationStatus::SENT, NotificationDelivery::query()->first()?->status);
    }

    public function test_process_notification_uses_default_priority(): void
    {
        $publisher = Mockery::mock(NotificationPublisher::class);
        $publisher->shouldReceive('publish')->once()->with(Mockery::any(), 1);

        $service = $this->makeService($publisher);
        $user = $this->createUser();

        $service->processNotification(
            $this->idempotencyKey(),
            NotificationChannel::SMS,
            'Default priority',
            [$user->id],
        );

        $this->assertDatabaseHas('notifications', [
            'text' => 'Default priority',
            'priority' => 1,
        ]);
    }

    public function test_send_notification_rethrows_temporary_gateway_exception(): void
    {
        $delivery = $this->createDeliveryWithUser($this->createUser(['phone' => '+79991112233']));

        $gateway = Mockery::mock(NotificationGatewayInterface::class);
        $gateway->shouldReceive('send')->once()->andThrow(new TemporaryGatewayException('temporary down'));

        $service = $this->makeService(gatewayResolver: $this->mockGatewayResolver($gateway));

        $this->expectException(TemporaryGatewayException::class);
        $service->sendNotification($delivery->id);
    }

    public function test_send_notification_marks_delivery_as_dropped_for_invalid_recipient(): void
    {
        $delivery = $this->createDeliveryWithUser($this->createUser(['phone' => '+79991112233']));

        $gateway = Mockery::mock(NotificationGatewayInterface::class);
        $gateway->shouldReceive('send')->once()->andThrow(new InvalidRecipientException('blocked'));

        $service = $this->makeService(gatewayResolver: $this->mockGatewayResolver($gateway));
        $service->sendNotification($delivery->id);

        $this->assertSame(NotificationStatus::DROPPED, $delivery->fresh()->status);
    }

    public function test_drop_notification_updates_status_and_clears_cache(): void
    {
        $user = $this->createUser();
        $delivery = $this->createDeliveryWithUser($user);
        Cache::put("notifications_for_{$user->id}", ['cached'], 60);

        $service = $this->makeService();
        $service->dropNotification($delivery->id);

        $this->assertSame(NotificationStatus::DROPPED, $delivery->fresh()->status);
        $this->assertNull(Cache::get("notifications_for_{$user->id}"));
    }

    private function makeService(
        ?NotificationPublisher $publisher = null,
        ?GatewayResolver $gatewayResolver = null,
    ): NotificationService {
        return new NotificationService(
            $publisher ?? Mockery::mock(NotificationPublisher::class),
            $gatewayResolver ?? app(GatewayResolver::class),
        );
    }

    private function mockGatewayResolver(
        ?NotificationGatewayInterface $gateway = null,
        NotificationChannel $channel = NotificationChannel::SMS,
    ): GatewayResolver {
        $gateway ??= Mockery::mock(NotificationGatewayInterface::class);

        $resolver = Mockery::mock(GatewayResolver::class);
        $resolver->shouldReceive('resolve')->with($channel)->andReturn($gateway);

        return $resolver;
    }
}
