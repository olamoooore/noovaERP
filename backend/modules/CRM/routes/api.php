<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('crm')->group(function () {
    // CRM routes are added as controllers are implemented.
});
