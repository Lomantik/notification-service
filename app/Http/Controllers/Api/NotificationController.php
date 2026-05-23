<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NotificationStoreRequest;
use App\Http\Resources\Api\NotificationDeliveryResource;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService,
    ) {}

    public function index(User $user): JsonResponse|JsonResource
    {
        try {
            return NotificationDeliveryResource::collection(
                $this->notificationService->getUserNotifications($user),
            );
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function store(NotificationStoreRequest $request): JsonResponse|JsonResource
    {
        try {
            $validated = $request->validated();

            return NotificationDeliveryResource::collection(
                $this->notificationService->processNotification(
                    $validated['uuid'],
                    NotificationChannel::from($validated['channel']),
                    $validated['text'],
                    $validated['user_ids'],
                    $validated['priority'] ?? 1,
                ),
            );
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    private function errorResponse(Throwable $exception): JsonResponse
    {
        $code = (int) $exception->getCode();

        if ($code < 100 || $code >= 600) {
            $code = 500;
        }

        return response()->json(['message' => $exception->getMessage()], $code);
    }
}
