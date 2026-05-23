<?php

namespace App\Http\Resources\Api;

use App\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

/**
 * @mixin NotificationDelivery
 */
class NotificationDeliveryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isModel = $this->resource instanceof Model;

        if ($isModel) {
            $notification = $this->whenLoaded('notification');
            $channel = $notification?->channel;
            $text = $notification?->text;
            $priority = $notification?->priority;
        } else {
            $notification = data_get($this->resource, 'notification');
            $channel = data_get($notification, 'channel');
            $text = data_get($notification, 'text');
            $priority = data_get($notification, 'priority');
        }

        return [
            'user_id' => data_get($this->resource, 'user_id'),
            'channel' => $channel ?? new MissingValue,
            'text' => $text ?? new MissingValue,
            'priority' => $priority ?? new MissingValue,
            'status' => data_get($this->resource, 'status'),
            'provider_id' => data_get($this->resource, 'provider_id'),
        ];
    }
}
