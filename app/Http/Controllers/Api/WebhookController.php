<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\WebhookRequest;
use App\Models\NotificationDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class WebhookController extends Controller
{
    //
    public function handleProviderCallback(WebhookRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $providerId = $validated['provider_id'];
        $providerStatus = $validated['status'];

        $deliveryId = Redis::get("provider_delivery:$providerId");

        if (! $deliveryId) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        $delivery = NotificationDelivery::find($deliveryId);

        if (! $delivery instanceof NotificationDelivery) {
            return response()->json(['message' => 'Transaction not found or invalid'], 404);
        }

        $finalStatus = ($providerStatus === 'delivered')
            ? NotificationStatus::DELIVERED
            : NotificationStatus::DROPPED;

        $delivery->update(['status' => $finalStatus]);
        Redis::del("provider_delivery:$providerId");
        Cache::forget("notifications_for_{$delivery->user?->id}");

        return response()->json(['status' => $finalStatus]);
    }
}
