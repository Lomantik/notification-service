<?php

use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('throttle:api')->group(function () {
    Route::post('/notification', [NotificationController::class, 'store']);
    Route::get('/user/{user}/notifications', [NotificationController::class, 'index'])
        ->missing(function () {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        });
    Route::post('/webhooks/gateway/callback', [WebhookController::class, 'handleProviderCallback']);
});
