<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/sales/health', function () {
        return ['module' => 'sales', 'ok' => true];
    });
});
