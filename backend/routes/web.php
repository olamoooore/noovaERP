<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['message' => 'NoovaERP API is running'];
});

// Some middleware/exception paths redirect unauthenticated (non-JSON) requests to
// a named 'login' route. This app is SPA/API-first, so we provide a lightweight
// endpoint to avoid 500s.
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');
