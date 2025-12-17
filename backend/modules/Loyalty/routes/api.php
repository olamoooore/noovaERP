<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:loyalty'])->group(function () {
    Route::get('/loyalty/health', function () {
        return ['module' => 'loyalty', 'ok' => true];
    });
});
