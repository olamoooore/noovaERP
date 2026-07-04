<?php

use App\Http\Controllers\Api\AIApprovalController;
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

Route::middleware(['auth:sanctum', 'entitlement:ai'])->prefix('ai')->group(function () {
    
    // AI Service Status
    Route::get('/health', [AIStatusController::class, 'health']);
    Route::get('/info', [AIStatusController::class, 'info']);
    
    // Module Management
    Route::get('/modules', [ModuleController::class, 'index']);
    Route::post('/conversations/{id}/select-module', [ModuleController::class, 'selectModule']);
    Route::get('/conversations/{id}/module', [ModuleController::class, 'getCurrentModule']);
    
    // Chatbot Endpoints
    Route::post('/chat', [ChatbotController::class, 'sendMessage']);
    Route::get('/conversations/{id}/poll', [ChatbotController::class, 'pollMessage']);
    Route::get('/conversations', [ChatbotController::class, 'getConversations']);
    Route::get('/conversations/{id}', [ChatbotController::class, 'getConversation']);
    Route::post('/conversations/{id}/close', [ChatbotController::class, 'closeConversation']);
    Route::delete('/conversations/{id}', [ChatbotController::class, 'deleteConversation']);
});

// AI governance — approval workflow (ADC §7). Authenticated only; governance
// must be reachable by approvers regardless of the AI chat entitlement.
Route::middleware(['auth:sanctum'])->prefix('ai/approvals')->group(function () {
    Route::get('/', [AIApprovalController::class, 'index']);
    Route::post('/', [AIApprovalController::class, 'store']);
    Route::get('/{id}', [AIApprovalController::class, 'show']);
    Route::post('/{id}/approve', [AIApprovalController::class, 'approve']);
    Route::post('/{id}/reject', [AIApprovalController::class, 'reject']);
});
