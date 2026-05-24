<?php

namespace Tests\Unit\Http\Resources\Api;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Http\Resources\Api\NotificationDeliveryResource;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use Illuminate\Http\Request;
use Tests\Support\CreatesNotificationFixtures;
use Tests\TestCase;

class NotificationDeliveryResourceTest extends TestCase
{
    use CreatesNotificationFixtures;

    public function test_transforms_eloquent_model_with_loaded_notification(): void
    {
        $user = $this->createUser();
        $notification = $this->createNotification([
            'channel' => NotificationChannel::EMAIL,
            'text' => 'Hello',
            'priority' => 8,
        ]);
        $delivery = $this->createDelivery([
            'notification_id' => $notification->id,
            'user_id' => $user->id,
            'status' => NotificationStatus::SENT,
            'provider_id' => 'email_abc',
        ]);
        $delivery->load('notification');

        $resource = (new NotificationDeliveryResource($delivery))->toArray(Request::create('/'));

        $this->assertSame($user->id, $resource['user_id']);
        $this->assertSame(NotificationChannel::EMAIL, $resource['channel']);
        $this->assertSame('Hello', $resource['text']);
        $this->assertSame(8, $resource['priority']);
        $this->assertSame(NotificationStatus::SENT, $resource['status']);
        $this->assertSame('email_abc', $resource['provider_id']);
    }

    public function test_transforms_cached_array_payload(): void
    {
        $payload = [
            'user_id' => 5,
            'status' => NotificationStatus::DELIVERED->value,
            'provider_id' => 'sms_cached',
            'notification' => [
                'channel' => NotificationChannel::SMS->value,
                'text' => 'Cached text',
                'priority' => 3,
            ],
        ];

        $resource = (new NotificationDeliveryResource($payload))->toArray(Request::create('/'));

        $this->assertSame(5, $resource['user_id']);
        $this->assertSame(NotificationChannel::SMS->value, $resource['channel']);
        $this->assertSame('Cached text', $resource['text']);
        $this->assertSame(3, $resource['priority']);
        $this->assertSame(NotificationStatus::DELIVERED->value, $resource['status']);
        $this->assertSame('sms_cached', $resource['provider_id']);
    }

    public function test_omits_notification_fields_when_missing_in_array_payload(): void
    {
        $payload = [
            'user_id' => 1,
            'status' => NotificationStatus::PROCESSING->value,
            'provider_id' => null,
        ];

        $resource = (new NotificationDeliveryResource($payload))->resolve(Request::create('/'));

        $this->assertArrayNotHasKey('channel', $resource);
        $this->assertArrayNotHasKey('text', $resource);
        $this->assertArrayNotHasKey('priority', $resource);
    }

    public function test_omits_notification_fields_when_relation_not_loaded_on_model(): void
    {
        $delivery = $this->createDeliveryWithUser($this->createUser());

        $this->expectException(\ErrorException::class);

        (new NotificationDeliveryResource($delivery))->toArray(Request::create('/'));
    }
}
