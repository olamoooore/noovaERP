<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:iot'])->group(function () {
    Route::get('/iot/health', function () {
        return ['module' => 'iot', 'ok' => true];
    });
});
