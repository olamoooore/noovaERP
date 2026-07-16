<?php

use Illuminate\Support\Facades\Route;
use Modules\CRM\Http\Controllers\ActivityController;
use Modules\CRM\Http\Controllers\LeadController;

/*
|--------------------------------------------------------------------------
| CRM Module - Tenant API Routes
|--------------------------------------------------------------------------
| Standalone CRM - Lead pipeline: New → Contacted → Interested → Proposal → Won/Lost
*/

Route::middleware(['auth:sanctum', 'feature:crm'])->prefix('crm')->group(function () {
    // Lead stats (dashboard)
    Route::get('/stats', [LeadController::class, 'stats']);

    // Leads CRUD
    Route::apiResource('leads', LeadController::class);

    // Activities for leads
    Route::get('/leads/{lead}/activities', [ActivityController::class, 'index']);
    Route::post('/leads/{lead}/activities', [ActivityController::class, 'store']);
    Route::put('/activities/{activity}', [ActivityController::class, 'update']);
    Route::delete('/activities/{activity}', [ActivityController::class, 'destroy']);
});
