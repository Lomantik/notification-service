<?php

namespace Tests\Support;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use Database\Factories\NotificationDeliveryFactory;
use Database\Factories\NotificationFactory;
use Illuminate\Support\Str;

trait CreatesNotificationFixtures
{
    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    protected function createNotification(array $attributes = []): Notification
    {
        return NotificationFactory::new()->create($attributes);
    }

    protected function createDelivery(array $attributes = []): NotificationDelivery
    {
        return NotificationDeliveryFactory::new()->create($attributes);
    }

    protected function createDeliveryWithUser(
        User $user,
        NotificationChannel $channel = NotificationChannel::SMS,
        NotificationStatus $status = NotificationStatus::PROCESSING,
        ?string $providerId = null,
    ): NotificationDelivery {
        $notification = $this->createNotification([
            'channel' => $channel,
        ]);

        return $this->createDelivery([
            'notification_id' => $notification->id,
            'user_id' => $user->id,
            'status' => $status,
            'provider_id' => $providerId,
        ]);
    }

    protected function idempotencyKey(): string
    {
        return (string) Str::uuid();
    }
}
