<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationStatus;
use App\Enums\ProviderCallbackStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\WebhookRequest;
use App\Models\NotificationDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class WebhookController extends Controller
{
    public function handleProviderCallback(WebhookRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $providerId = $validated['provider_id'];
        $providerStatus = $request->enum('status', ProviderCallbackStatus::class);

        if (! $providerStatus instanceof ProviderCallbackStatus) {
            return response()->json(['message' => 'Invalid status'], 422);
        }

        $deliveryId = Cache::get("provider_delivery:{$providerId}");

        if (! $deliveryId) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        $delivery = NotificationDelivery::find($deliveryId);

        if (! $delivery instanceof NotificationDelivery) {
            return response()->json(['message' => 'Transaction not found or invalid'], 404);
        }

        $finalStatus = $providerStatus === ProviderCallbackStatus::DELIVERED
            ? NotificationStatus::DELIVERED
            : NotificationStatus::DROPPED;

        $delivery->update(['status' => $finalStatus]);
        Cache::forget("provider_delivery:{$providerId}");
        Cache::forget("notifications_for_{$delivery->user_id}");

        return response()->json(['status' => $finalStatus->value]);
    }
}
