<?php

use App\Http\Middleware\RequirePermission;
use Illuminate\Support\Facades\Route;
use Modules\Ecommerce\Http\Controllers\WebOrderController;

/*
|--------------------------------------------------------------------------
| Ecommerce API Routes  —  DEPRECATED / ARCHIVED (frozen at Storefront P0)
|--------------------------------------------------------------------------
|
| @deprecated The entire legacy commerce stack (this module, web_* tables,
| the /store SPA and /shop WebShop page) is archived per MRD
| ecommerce-Enterprice Appendix A §A5 / FR-ARC-01..03. It receives NO new
| features. The replacement is the generic `storefront` module.
|
| P0 freeze applied here (FR-ARC-02/03):
|  - Admin reads are now permission-gated (sales.view) — they previously had
|    NO entitlement/permission gate at all (legacy defect L-02).
|  - The admin status-write endpoint returns 410 Gone: no new writes after
|    freeze. Data stays readable for history (FR-ARC-03) and read-only
|    export lands in P1 (FR-ARC-04).
|
| Public runtime routes (/web-shop/*) are registered directly in
| routes/tenant.php and stop serving at the P2 decommission step (Appendix A
| §A5) — they are intentionally NOT touched by this freeze commit.
|
*/

Route::prefix('admin/ecommerce')->group(function () {
    Route::get('/orders', [WebOrderController::class, 'adminIndex'])
        ->middleware(RequirePermission::using('sales.view'));

    Route::get('/orders/{id}', [WebOrderController::class, 'adminShow'])
        ->middleware(RequirePermission::using('sales.view'));

    // Frozen: legacy status mutations are retired read-only (FR-ARC-03).
    Route::put('/orders/{id}/status', function () {
        return response()->json([
            'message' => 'The legacy online shop is archived and frozen. Order status updates are disabled; the replacement Storefront module admin lands in P1.',
            'code' => 'legacy_commerce_frozen',
        ], 410);
    });
});
