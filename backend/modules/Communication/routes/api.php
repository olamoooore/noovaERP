<?php

use Illuminate\Support\Facades\Route;
use Modules\Communication\Http\Controllers\CommunicationController;
use Modules\Communication\Http\Controllers\PreferenceController;
use Modules\Communication\Http\Controllers\TemplateController;

/*
|--------------------------------------------------------------------------
| Communication Module API Routes
|--------------------------------------------------------------------------
|
| Central communication bus API endpoints.
| All routes are prefixed with /api/communication and require auth.
|
*/

Route::middleware(['api', 'auth:sanctum', 'entitlement:communication'])->prefix('api/communication')->group(function () {

    // Messages / Thread (Chatter)
    Route::get('/messages', [CommunicationController::class, 'getMessages']);
    Route::post('/messages', [CommunicationController::class, 'postMessage']);

    // Subscribers (Followers)
    Route::get('/subscribers', [CommunicationController::class, 'getSubscribers']);
    Route::post('/subscribers', [CommunicationController::class, 'subscribe']);
    Route::delete('/subscribers', [CommunicationController::class, 'unsubscribe']);

    // Send messages
    Route::post('/send', [CommunicationController::class, 'send']);
    Route::post('/notify', [CommunicationController::class, 'notify']);

    // Outbox status
    Route::get('/outbox/{messageId}', [CommunicationController::class, 'getOutboxStatus']);

    // User Preferences
    Route::get('/preferences', [PreferenceController::class, 'show']);
    Route::put('/preferences', [PreferenceController::class, 'update']);

    // Templates (admin)
    Route::get('/templates', [TemplateController::class, 'index']);
    Route::get('/templates/{id}', [TemplateController::class, 'show']);
    Route::post('/templates', [TemplateController::class, 'store']);
    Route::put('/templates/{id}', [TemplateController::class, 'update']);
    Route::post('/templates/{id}/preview', [TemplateController::class, 'preview']);
});
