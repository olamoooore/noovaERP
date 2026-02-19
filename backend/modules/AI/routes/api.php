<?php

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\ChatbotController;
use Modules\AI\Http\Controllers\AIStatusController;
use Modules\AI\Http\Controllers\ModuleController;

/*
|--------------------------------------------------------------------------
| AI Module API Routes
|--------------------------------------------------------------------------
|
| All routes require authentication (auth:sanctum) and AI feature enabled
|
*/

Route::middleware(['auth:sanctum', 'feature:ai'])->prefix('ai')->group(function () {
    
    // AI Service Status
    Route::get('/health', [AIStatusController::class, 'health']);
    Route::get('/info', [AIStatusController::class, 'info']);
    
    // Module Management
    Route::get('/modules', [ModuleController::class, 'index']);
    Route::post('/conversations/{id}/select-module', [ModuleController::class, 'selectModule']);
    Route::get('/conversations/{id}/module', [ModuleController::class, 'getCurrentModule']);
    
    // Chatbot Endpoints
    Route::post('/chat', [ChatbotController::class, 'sendMessage']);
    Route::get('/conversations', [ChatbotController::class, 'getConversations']);
    Route::get('/conversations/{id}', [ChatbotController::class, 'getConversation']);
    Route::post('/conversations/{id}/close', [ChatbotController::class, 'closeConversation']);
    Route::delete('/conversations/{id}', [ChatbotController::class, 'deleteConversation']);
});
