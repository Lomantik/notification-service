<?php

namespace Database\Factories;

use App\Enums\NotificationStatus;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDelivery>
 */
class NotificationDeliveryFactory extends Factory
{
    protected $model = NotificationDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_id' => fn () => NotificationFactory::new()->createOne()->id,
            'user_id' => User::factory(),
            'status' => NotificationStatus::PROCESSING,
            'provider_id' => null,
        ];
    }
}
