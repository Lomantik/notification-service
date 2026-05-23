<?php

namespace App\Models;

use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property NotificationStatus $status
 */
class NotificationDelivery extends Model
{
    protected $fillable = [
        'notification_id',
        'user_id',
        'status',
        'provider_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    protected function casts(): array
    {
        return [
            'status' => NotificationStatus::class,
        ];
    }
}
