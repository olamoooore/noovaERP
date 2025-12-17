<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:ai'])->group(function () {
    Route::get('/ai/health', function () {
        return ['module' => 'ai', 'ok' => true];
    });
});
