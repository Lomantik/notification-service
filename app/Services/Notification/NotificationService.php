<?php

namespace App\Services\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Exceptions\InvalidRecipientException;
use App\Exceptions\TemporaryGatewayException;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Notification\Messaging\NotificationPublisher;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class NotificationService
{
    public function __construct(
        protected NotificationPublisher $notificationPublisher,
        protected GatewayResolver $gatewayResolver,
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

    private function userNotificationsCacheKey(int $userId): string
    {
        return "notifications_for_{$userId}";
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
        string $text,
        array $userIds,
        int $priority = 1,
    ): Collection {
        $deliveriesToPublish = collect();

        $deliveries = DB::transaction(function () use ($userIds, $priority, $text, $channel, $idempotencyKey, &$deliveriesToPublish) {
            $notification = Notification::createOrFirst([
                'idempotency_key' => $idempotencyKey,
                'channel' => $channel,
                'text' => $text,
                'priority' => $priority,
            ]);

            if ($notification->wasRecentlyCreated) {
                $now = now();
                $deliveryData = array_map(
                    fn (int $userId) => [
                        'notification_id' => $notification->id,
                        'user_id' => $userId,
                        'status' => NotificationStatus::PROCESSING,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $userIds,
                );

                NotificationDelivery::insertOrIgnore($deliveryData);
            }

            $deliveriesToPublish = $notification->refresh()
                ->notificationDeliveries()
                ->where('status', NotificationStatus::PROCESSING)
                ->get();

            return $notification->notificationDeliveries()->with('notification')->get();
        }, 5);

        foreach ($deliveriesToPublish as $delivery) {
            Cache::forget($this->userNotificationsCacheKey($delivery->user_id));
            $this->notificationPublisher->publish($delivery->id, $priority);
        }

        return $deliveries->sortBy('user_id');
    }

    /**
     * @throws InvalidRecipientException
     * @throws TemporaryGatewayException
     * @throws Throwable
     */
    public function sendNotification(int $id): void
    {
        $lock = Cache::lock("delivery_lock:{$id}", 300);

        if (! $lock->get()) {
            return;
        }

        try {
            $delivery = NotificationDelivery::with(['notification', 'user'])->findOrFail($id);

            if (in_array($delivery->status, [NotificationStatus::DELIVERED, NotificationStatus::DROPPED, NotificationStatus::SENT], true)) {
                return;
            }

            $notification = $delivery->notification;

            if (! $notification instanceof Notification) {
                return;
            }

            try {
                $gateway = $this->gatewayResolver->resolve($notification->channel);
                $recipient = match ($notification->channel) {
                    NotificationChannel::SMS => $delivery->user->phone ?? '',
                    NotificationChannel::EMAIL => $delivery->user->email ?? '',
                };

                $providerId = $gateway->send($recipient, $notification->text);

                $delivery->update([
                    'status' => NotificationStatus::SENT,
                    'provider_id' => $providerId,
                ]);

                Cache::put("provider_delivery:{$providerId}", $delivery->id, now()->addDay());
                Cache::forget($this->userNotificationsCacheKey($delivery->user_id));
            } catch (TemporaryGatewayException $exception) {
                throw $exception;
            } catch (Throwable) {
                $delivery->update(['status' => NotificationStatus::DROPPED]);
                Cache::forget($this->userNotificationsCacheKey($delivery->user_id));
            }
        } finally {
            $lock->release();
        }
    }

    public function dropNotification(int $id): void
    {
        $delivery = NotificationDelivery::with('user')->findOrFail($id);
        $delivery->update(['status' => NotificationStatus::DROPPED]);
        Cache::forget($this->userNotificationsCacheKey($delivery->user_id));
    }
}
