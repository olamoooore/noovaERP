<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'feature:ecommerce'])->group(function () {
    Route::get('/ecommerce/health', function () {
        return ['module' => 'ecommerce', 'ok' => true];
    });
});
