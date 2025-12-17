<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:whatsapp'])->group(function () {
    Route::get('/whatsapp/health', function () {
        return ['module' => 'whatsapp', 'ok' => true];
    });
});
