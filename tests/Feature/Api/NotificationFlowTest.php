<?php

namespace Tests\Feature\Api;

use App\Enums\NotificationStatus;
use App\Services\Notification\Gateways\NotificationGatewayInterface;
use App\Services\Notification\GatewayResolver;
use App\Services\Notification\Messaging\NotificationPublisher;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\Support\CreatesNotificationFixtures;
use Tests\TestCase;

class NotificationFlowTest extends TestCase
{
    use CreatesNotificationFixtures;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_full_chain_from_api_to_worker_to_webhook(): void
    {
        $user = $this->createUser(['phone' => '+79991112233', 'email' => 'user@example.com']);
        $key = $this->idempotencyKey();

        $publisher = Mockery::mock(NotificationPublisher::class);
        $publisher->shouldReceive('publish')->once()->andReturnUsing(function (int $deliveryId): void {
            app(NotificationService::class)->sendNotification($deliveryId);
        });
        $this->app->instance(NotificationPublisher::class, $publisher);

        $gateway = Mockery::mock(NotificationGatewayInterface::class);
        $gateway->shouldReceive('send')->once()->andReturn('sms_flow123');
        $this->mockGatewayResolver($gateway);

        $storeResponse = $this->postJson('/api/notification', [
            'channel' => 'sms',
            'text' => 'Verification code 1234',
            'user_ids' => [$user->id],
            'priority' => 10,
        ], [
            'Idempotency-Key' => $key,
        ]);

        $storeResponse->assertOk()
            ->assertJsonPath('data.0.status', NotificationStatus::PROCESSING->value);

        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id,
            'status' => NotificationStatus::SENT->value,
            'provider_id' => 'sms_flow123',
        ]);

        $this->postJson('/api/webhooks/gateway/callback', [
            'provider_id' => 'sms_flow123',
            'status' => 'delivered',
        ])->assertOk()->assertJson(['status' => 'delivered']);

        $this->getJson("/api/user/{$user->id}/notifications")
            ->assertOk()
            ->assertJsonPath('data.0.status', NotificationStatus::DELIVERED->value);
    }

    public function test_retry_exhaustion_marks_delivery_as_dropped(): void
    {
        $user = $this->createUser(['phone' => '+79991112233']);
        $delivery = $this->createDeliveryWithUser($user);

        app(NotificationService::class)->dropNotification($delivery->id);

        $this->assertSame(NotificationStatus::DROPPED, $delivery->fresh()->status);
        $this->assertNull(Cache::get("notifications_for_{$user->id}"));
    }

    private function mockGatewayResolver(NotificationGatewayInterface $gateway): void
    {
        $resolver = Mockery::mock(GatewayResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($gateway);
        $this->app->instance(GatewayResolver::class, $resolver);
    }
}
