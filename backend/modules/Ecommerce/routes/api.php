<?php

use Illuminate\Support\Facades\Route;
use Modules\Ecommerce\Http\Controllers\WebOrderController;

/*
|--------------------------------------------------------------------------
| Ecommerce API Routes
|--------------------------------------------------------------------------
|
| NOTE: Public routes (guest checkout, auth, guest orders) and
| customer-authenticated routes (WebCustomerAuth middleware) are
| registered directly in tenant.php to avoid being wrapped by auth:sanctum.
|
| Only admin routes remain here (loaded inside auth:sanctum group).
|
*/

// Admin routes (requires auth:sanctum from tenant.php authenticated group)
Route::prefix('admin/ecommerce')->group(function () {
    Route::get('/orders', [WebOrderController::class, 'adminIndex']);
    Route::get('/orders/{id}', [WebOrderController::class, 'adminShow']);
    Route::put('/orders/{id}/status', [WebOrderController::class, 'updateStatus']);
});
