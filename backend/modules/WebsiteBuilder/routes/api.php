<?php

use Illuminate\Support\Facades\Route;
use Modules\WebsiteBuilder\Http\Controllers\WebsiteController;
use Modules\WebsiteBuilder\Http\Controllers\PageController;
use Modules\WebsiteBuilder\Http\Controllers\TemplateController;
use Modules\WebsiteBuilder\Http\Controllers\AssetController;
use Modules\WebsiteBuilder\Http\Controllers\PublishController;
use Modules\WebsiteBuilder\Http\Controllers\UploadController;
use Modules\WebsiteBuilder\Http\Controllers\ImportController;
use Modules\WebsiteBuilder\Http\Controllers\EditorApiController;
use Modules\WebsiteBuilder\Http\Controllers\WebsiteProductController;
use Modules\WebsiteBuilder\Http\Controllers\PublicSiteController;

// NOTE: Public API routes (website-public/*) are registered in routes/tenant.php
// inside the PUBLIC middleware group (no auth). Do NOT duplicate them here,
// as this file is loaded inside the AUTHENTICATED middleware group which
// would override the public routes and require auth:sanctum.

Route::middleware(['auth:sanctum', 'feature:website-builder'])->prefix('website-builder')->group(function () {
    // Health check
    Route::get('/health', function () {
        return ['module' => 'website-builder', 'ok' => true];
    });

    // Editor API
    Route::post('/editor/save', [EditorApiController::class, 'saveContent']);
    Route::get('/editor/page/{page}', [EditorApiController::class, 'getContent']);
    Route::post('/editor/upload', [EditorApiController::class, 'upload']);

    // Websites
    Route::apiResource('websites', WebsiteController::class);
    Route::post('/websites/{website}/publish', [PublishController::class, 'publish']);
    Route::post('/websites/{website}/rollback/{version}', [PublishController::class, 'rollback']);

    // Pages
    Route::get('/websites/{website}/pages', [PageController::class, 'index']);
    Route::post('/websites/{website}/pages', [PageController::class, 'store']);
    Route::get('/pages/{page}', [PageController::class, 'show']);
    Route::put('/pages/{page}', [PageController::class, 'update']);
    Route::delete('/pages/{page}', [PageController::class, 'destroy']);
    Route::get('/pages/{page}/versions', [PageController::class, 'versions']);
    Route::post('/pages/{page}/restore/{version}', [PageController::class, 'restore']);

    // Templates
    Route::get('/templates', [TemplateController::class, 'index']);
    Route::get('/templates/{template}', [TemplateController::class, 'show']);
    Route::post('/templates/{template}/clone', [TemplateController::class, 'cloneToWebsite']);

    // Assets
    Route::post('/assets', [AssetController::class, 'store']);
    Route::get('/assets/{asset}', [AssetController::class, 'show']);
    Route::delete('/assets/{asset}', [AssetController::class, 'destroy']);

    // Upload (ZIP/HTML)
    Route::post('/upload', [UploadController::class, 'upload']);
    Route::post('/import', [ImportController::class, 'import']);
});
