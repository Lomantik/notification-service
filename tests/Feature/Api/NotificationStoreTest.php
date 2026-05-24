<?php

namespace Tests\Feature\Api;

use Tests\Support\CreatesNotificationFixtures;
use Tests\TestCase;

class NotificationStoreTest extends TestCase
{
    use CreatesNotificationFixtures;

    public function test_stores_bulk_notification_and_returns_deliveries(): void
    {
        $this->mockPublisher();

        $users = [$this->createUser(), $this->createUser()];
        $key = $this->idempotencyKey();

        $response = $this->postJson('/api/notification', [
            'channel' => 'sms',
            'text' => 'Promo message',
            'user_ids' => [$users[0]->id, $users[1]->id],
            'priority' => 7,
        ], [
            'Idempotency-Key' => $key,
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'processing')
            ->assertJsonPath('data.0.channel', 'sms')
            ->assertJsonPath('data.0.text', 'Promo message')
            ->assertJsonPath('data.0.priority', 7);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_deliveries', 2);
    }

    public function test_requires_idempotency_key_header(): void
    {
        $response = $this->postJson('/api/notification', [
            'channel' => 'sms',
            'text' => 'Hello',
            'user_ids' => [1],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['uuid']);
    }

    public function test_validates_invalid_channel(): void
    {
        $response = $this->postJson('/api/notification', [
            'channel' => 'push',
            'text' => 'Hello',
            'user_ids' => [1],
        ], [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['channel']);
    }

    public function test_validates_required_text(): void
    {
        $user = $this->createUser();

        $response = $this->postJson('/api/notification', [
            'channel' => 'sms',
            'text' => '',
            'user_ids' => [$user->id],
        ], [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['text']);
    }

    public function test_validates_user_ids(): void
    {
        $response = $this->postJson('/api/notification', [
            'channel' => 'sms',
            'text' => 'Hello',
            'user_ids' => [99999],
        ], [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['user_ids.0']);
    }

    public function test_rejects_unknown_fields(): void
    {
        $user = $this->createUser();

        $response = $this->postJson('/api/notification', [
            'channel' => 'email',
            'text' => 'Hello',
            'user_ids' => [$user->id],
            'unexpected' => true,
        ], [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]);

        $response->assertUnprocessable();
    }

    public function test_duplicate_request_with_same_idempotency_key_is_idempotent(): void
    {
        $this->mockPublisher(exactly: 2);

        $user = $this->createUser();
        $key = $this->idempotencyKey();

        $payload = [
            'channel' => 'email',
            'text' => 'Same message',
            'user_ids' => [$user->id],
        ];

        $first = $this->postJson('/api/notification', $payload, ['Idempotency-Key' => $key]);
        $second = $this->postJson('/api/notification', $payload, ['Idempotency-Key' => $key]);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_deliveries', 1);
    }

    private function mockPublisher(?int $exactly = null): void
    {
        $publisher = \Mockery::mock(\App\Services\Notification\Messaging\NotificationPublisher::class);
        $expectation = $publisher->shouldReceive('publish');

        if ($exactly !== null) {
            $expectation->times($exactly);
        } else {
            $expectation->atLeast()->once();
        }

        $this->app->instance(\App\Services\Notification\Messaging\NotificationPublisher::class, $publisher);
    }
}
