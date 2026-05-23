<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Exceptions\GatewayTimeoutException;
use App\Exceptions\InvalidRecipientException;
use App\Exceptions\TemporaryGatewayException;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Notification\Gateways\MockEmailGateway;
use App\Services\Notification\Gateways\MockSmsGateway;
use App\Services\Notification\NotificationPublisher;
use Exception;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class NotificationsService
{
    public function __construct(
        protected NotificationPublisher $notificationPublisher,
        protected MockSmsGateway $mockSmsGateway,
        protected MockEmailGateway $mockEmailGateway,
    ) {}

    /**
     * @return array<NotificationDelivery>
     *
     * @throws LockTimeoutException
     */
    public function getUserNotifications(User $user): array
    {
        return Cache::remember(
            "notifications_for_$user->id",
            10,
            fn () => $user->load('notificationDeliveries')->notificationDeliveries()->with('notification')->get()->toArray()
        );
    }

    /**
     * @param  array<int>  $userIds
     * @return Collection<int, NotificationDelivery>
     *
     * @throws Throwable
     */
    public function processNotification(
        string $idempotencyKey,
        NotificationChannel $channel,
        string $text, array $userIds,
        int $priority = 1
    ): Collection {
        $deliveriesToPublish = collect();

        $notifications = DB::transaction(function () use ($userIds, $priority, $text, $channel, $idempotencyKey, &$deliveriesToPublish) {
            $notification = Notification::createOrFirst([
                'idempotency_key' => $idempotencyKey,
                'channel' => $channel,
                'text' => $text,
                'priority' => $priority,
            ]);

            if ($notification->wasRecentlyCreated) {
                $now = now();
                $deliveryData = array_map(function ($userId) use ($now, $notification) {
                    return [
                        'notification_id' => $notification->id,
                        'user_id' => $userId,
                        'status' => NotificationStatus::PROCESSING,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }, $userIds);
                NotificationDelivery::insertOrIgnore($deliveryData);

                $deliveriesToPublish = $notification->refresh()->notificationDeliveries;
            }

            return $notification->refresh()->notificationDeliveries()->with('notification')->get();
        }, 5);

        foreach ($deliveriesToPublish as $notificationDelivery) {
            Cache::forget("notifications_for_{$notificationDelivery->user->id}");
            $this->notificationPublisher->publish($notificationDelivery->id, $priority);
        }

        return $notifications->sortBy('user_id');
    }

    /**
     * @throws InvalidRecipientException
     * @throws GatewayTimeoutException
     * @throws Exception
     * @throws Throwable
     */
    public function sendNotification(int $id): void
    {
        $lockKey = "delivery_lock:$id";
        $redis = app('redis')->connection();

        $lockCreated = $redis->setnx($lockKey, 'processing');

        if (! $lockCreated) {
            return;
        }

        $redis->expire($lockKey, 300);

        try {
            $delivery = NotificationDelivery::with(['notification', 'user'])->findOrFail($id);

            if (in_array($delivery->status, [NotificationStatus::DELIVERED, NotificationStatus::DROPPED])) {
                $redis->del($lockKey);

                return;
            }

            $notification = $delivery->notification;
            if ($notification) {
                try {
                    $providerId = match ($notification->channel) {
                        NotificationChannel::SMS => $this->mockSmsGateway->send($delivery->user->phone ?? '', $notification->text),
                        NotificationChannel::EMAIL => $this->mockEmailGateway->send($delivery->user->email ?? '', $notification->text)
                    };
                    $delivery->update(['status' => NotificationStatus::SENT, 'provider_id' => $providerId]);
                    $redis->setex("provider_delivery:$providerId", 86400, $delivery->id);
                    Cache::forget("notifications_for_{$delivery->user?->id}");
                } catch (TemporaryGatewayException $exception) {
                    $redis->del($lockKey);
                    throw $exception;
                } catch (Throwable) {
                    $delivery->update(['status' => NotificationStatus::DROPPED]);
                    Cache::forget("notifications_for_{$delivery->user?->id}");
                }
            }
        } catch (Throwable $exception) {
            $redis->del($lockKey);
            throw $exception;
        }
    }

    public function dropNotification(int $id): void
    {
        $delivery = NotificationDelivery::with(['notification', 'user'])->findOrFail($id);
        $delivery->update(['status' => NotificationStatus::DROPPED]);
        Cache::forget("notifications_for_{$delivery->user?->id}");
    }
}
