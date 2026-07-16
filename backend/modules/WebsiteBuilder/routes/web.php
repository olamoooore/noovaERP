<?php

use Illuminate\Support\Facades\Route;
use Modules\WebsiteBuilder\Http\Controllers\EditorController;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

Route::middleware(['web', 'auth', InitializeTenancyByDomain::class])->prefix('website-builder')->name('website-builder.')->group(function () {
    Route::get('/editor/{website}/{page?}', [EditorController::class, 'edit'])->name('editor');
});
