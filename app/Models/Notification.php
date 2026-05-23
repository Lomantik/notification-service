<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property NotificationChannel $channel
 */
class Notification extends Model
{
    protected $fillable = [
        'idempotency_key',
        'channel',
        'text',
        'priority',
    ];

    /**
     * @return HasMany<NotificationDelivery, $this>
     */
    public function notificationDeliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
        ];
    }
}
