<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'channel' => NotificationChannel::SMS,
            'text' => fake()->sentence(),
            'priority' => fake()->numberBetween(1, 10),
        ];
    }
}
