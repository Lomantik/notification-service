<?php

namespace Tests\Feature\Api;

use App\Enums\NotificationStatus;
use Tests\Support\CreatesNotificationFixtures;
use Tests\TestCase;

class NotificationIndexTest extends TestCase
{
    use CreatesNotificationFixtures;

    public function test_returns_user_notification_history(): void
    {
        $user = $this->createUser();
        $delivery = $this->createDeliveryWithUser(
            $user,
            status: NotificationStatus::SENT,
            providerId: 'sms_history',
        );

        $response = $this->getJson("/api/user/{$user->id}/notifications");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $user->id)
            ->assertJsonPath('data.0.status', NotificationStatus::SENT->value)
            ->assertJsonPath('data.0.provider_id', 'sms_history');
    }

    public function test_returns_cached_response_on_subsequent_requests(): void
    {
        $user = $this->createUser();
        $this->createDeliveryWithUser($user);

        $first = $this->getJson("/api/user/{$user->id}/notifications");
        $second = $this->getJson("/api/user/{$user->id}/notifications");

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('data'), $second->json('data'));
    }

    public function test_returns_not_found_for_missing_user(): void
    {
        $response = $this->getJson('/api/user/99999/notifications');

        $response->assertNotFound()
            ->assertJson(['message' => 'User not found.']);
    }
}
