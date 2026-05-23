<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NotificationStoreRequest;
use App\Http\Resources\Api\NotificationDeliveryResource;
use App\Models\User;
use App\Services\NotificationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationsService $notificationsService
    ) {}

    public function index(User $user): JsonResponse|JsonResource
    {
        try {
            return NotificationDeliveryResource::collection($this->notificationsService->getUserNotifications($user));
        } catch (Throwable $exception) {
            $code = (int) $exception->getCode();
            if ($code < 100 || $code >= 600) {
                $code = 500;
            }

            return response()->json(['message' => $exception->getMessage()], $code);
        }
    }

    public function store(NotificationStoreRequest $request): JsonResponse|JsonResource
    {
        try {
            $validated = $request->validated();
            $idempotencyKey = $request->header('Idempotency-Key') ?? '';
            $priority = $validated['priority'] ?? 1;

            return NotificationDeliveryResource::collection(
                $this->notificationsService->processNotification(
                    $idempotencyKey,
                    NotificationChannel::from($validated['channel']),
                    $validated['text'],
                    $validated['user_ids'],
                    $priority
                ));
        } catch (Throwable $exception) {
            $code = (int) $exception->getCode();
            if ($code < 100 || $code >= 600) {
                $code = 500;
            }

            return response()->json(['message' => $exception->getMessage()], $code);
        }
    }
}
