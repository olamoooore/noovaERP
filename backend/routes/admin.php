<?php

use App\SuperAdmin\Http\Controllers\AdminAnalyticsController;
use App\SuperAdmin\Http\Controllers\AdminAuditLogController;
use App\SuperAdmin\Http\Controllers\AdminAuthController;
use App\SuperAdmin\Http\Controllers\AdminBillingController;
use App\SuperAdmin\Http\Controllers\AdminCatalogAnalyticsController;
use App\SuperAdmin\Http\Controllers\AdminCatalogController;
use App\SuperAdmin\Http\Controllers\AdminCollectionsController;
use App\SuperAdmin\Http\Controllers\AdminDashboardController;
use App\SuperAdmin\Http\Controllers\AdminModuleCatalogController;
use App\SuperAdmin\Http\Controllers\AdminOperationsController;
use App\SuperAdmin\Http\Controllers\AdminPackagingController;
use App\SuperAdmin\Http\Controllers\AdminPlanChangeController;
use App\SuperAdmin\Http\Controllers\AdminPlanController;
use App\SuperAdmin\Http\Controllers\AdminPricingController;
use App\SuperAdmin\Http\Controllers\AdminSubscriptionController;
use App\SuperAdmin\Http\Controllers\AdminTaxProvenanceController;
use App\SuperAdmin\Http\Controllers\AdminTenantApiKeyController;
use App\SuperAdmin\Http\Controllers\AdminTenantConfigurationController;
use App\SuperAdmin\Http\Controllers\AdminTenantController;
use App\SuperAdmin\Http\Controllers\AdminTenantNoteController;
use App\SuperAdmin\Http\Controllers\AdminTenantOperationController;
use App\SuperAdmin\Http\Controllers\AdminTenantTimelineController;
use App\SuperAdmin\Http\Controllers\AdminUserController;
use App\SuperAdmin\Http\Controllers\ImpersonationController;
use App\SuperAdmin\Http\Middleware\DenyReadonlyRole;
use App\SuperAdmin\Http\Middleware\EnsureAdminRole;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Superadmin Control Plane Routes
|--------------------------------------------------------------------------
|
| All routes here are prefixed with /api/v1/admin (set in api.php).
| The EnsurePublicSchemaForAuth middleware is applied at the group level
| in api.php, ensuring all queries run against the public schema.
|
*/

// Public — no auth required (rate-limited to prevent brute-force)
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/auth/login', [AdminAuthController::class, 'login']);
    Route::post('/users/accept-invite', [AdminUserController::class, 'acceptInvite']);
});

// Protected — requires valid Sanctum token + admin role
Route::middleware(['auth:sanctum', EnsureAdminRole::class])->group(function () {
    Route::get('/auth/me', [AdminAuthController::class, 'me']);
    Route::post('/auth/logout', [AdminAuthController::class, 'logout']);

    // --- Impersonation ---
    Route::prefix('impersonation')->group(function () {
        Route::post('/start', [ImpersonationController::class, 'start']);
        Route::post('/end', [ImpersonationController::class, 'end']);
        Route::get('/active', [ImpersonationController::class, 'active']);
    });

    Route::get('/dashboard', [AdminDashboardController::class, 'summary']);

    // Tax data-provenance honesty report (read-only, all admin roles).
    Route::get('/tax-provenance', [AdminTaxProvenanceController::class, 'index']);

    // --- Plan Catalog ---
    Route::prefix('plans')->group(function () {
        Route::get('/', [AdminPlanController::class, 'index']);
        Route::post('/', [AdminPlanController::class, 'store']);
        Route::get('/{family}', [AdminPlanController::class, 'show']);
        Route::put('/{family}', [AdminPlanController::class, 'update']);
        Route::delete('/{family}', [AdminPlanController::class, 'destroy']);

        Route::prefix('{family}/versions')->group(function () {
            Route::post('/', [AdminPlanController::class, 'createVersion']);
            Route::put('/{version}', [AdminPlanController::class, 'updateVersion']);
            Route::post('/{version}/prices', [AdminPlanController::class, 'setPrices']);
            Route::post('/{version}/modules/{module}', [AdminPlanController::class, 'attachModule']);
            Route::delete('/{version}/modules/{module}', [AdminPlanController::class, 'detachModule']);
        });
    });

    // --- Module Catalog ---
    Route::prefix('modules')->group(function () {
        Route::get('/', [AdminModuleCatalogController::class, 'index']);
        Route::post('/sync', [AdminModuleCatalogController::class, 'sync']);
        Route::get('/{module}', [AdminModuleCatalogController::class, 'show']);
        Route::put('/{module}', [AdminModuleCatalogController::class, 'update']);
    });

    // --- Tenant 360 ---
    Route::prefix('tenants')->group(function () {
        // Read — all admin roles
        Route::get('/', [AdminTenantController::class, 'index']);
        Route::get('/{tenantId}', [AdminTenantController::class, 'show']);
        Route::get('/{tenantId}/operations', [AdminTenantOperationController::class, 'index']);
        Route::get('/{tenantId}/notes', [AdminTenantNoteController::class, 'index']);
        Route::get('/{tenantId}/health', [AdminTenantController::class, 'latestHealth']);
        Route::get('/{tenantId}/timeline', [AdminTenantTimelineController::class, 'index']);

        // Write — denied for support_readonly
        Route::middleware(DenyReadonlyRole::class)->group(function () {
            Route::patch('/{tenantId}/status', [AdminTenantController::class, 'updateStatus']);
            Route::post('/{tenantId}/operations', [AdminTenantOperationController::class, 'store']);
            Route::post('/{tenantId}/notes', [AdminTenantNoteController::class, 'store']);
            Route::delete('/{tenantId}/notes/{note}', [AdminTenantNoteController::class, 'destroy']);
            Route::post('/{tenantId}/health/ping', [AdminTenantController::class, 'pingHealth']);
            Route::post('/{tenantId}/subscription', [AdminSubscriptionController::class, 'create']);
        });

        Route::get('/{tenantId}/subscription', [AdminSubscriptionController::class, 'forTenant']);
    });

    // --- Phase 3: Subscriptions ---
    Route::prefix('subscriptions')->group(function () {
        Route::get('/', [AdminSubscriptionController::class, 'index']);
        Route::get('/{subscription}', [AdminSubscriptionController::class, 'show']);

        Route::middleware(DenyReadonlyRole::class)->group(function () {
            Route::patch('/{subscription}/cancel', [AdminSubscriptionController::class, 'cancel']);
            Route::patch('/{subscription}/reactivate', [AdminSubscriptionController::class, 'reactivate']);
        });
    });

    // --- Phase 3: Billing ---
    Route::prefix('billing/invoices')->group(function () {
        Route::get('/', [AdminBillingController::class, 'index']);
        Route::get('/{invoice}', [AdminBillingController::class, 'show']);

        Route::middleware(DenyReadonlyRole::class)->group(function () {
            Route::patch('/{invoice}/mark-paid', [AdminBillingController::class, 'markPaid']);
            Route::patch('/{invoice}/void', [AdminBillingController::class, 'voidInvoice']);
            Route::post('/generate', [AdminBillingController::class, 'generate']);
        });
    });

    // --- Phase 4: Audit Log + Observability ---
    // NOTE: static sub-routes (stats, export) MUST be registered before /{log}
    Route::prefix('audit-logs')->group(function () {
        Route::get('/stats', [AdminAuditLogController::class, 'stats']);
        Route::get('/export', [AdminAuditLogController::class, 'export']);
        Route::get('/', [AdminAuditLogController::class, 'index']);
        Route::get('/{log}', [AdminAuditLogController::class, 'show']);
    });

    // --- Phase 5: Admin User Management (throttled, gate-enforced per method) ---
    Route::prefix('users')->middleware('throttle:30,1')->group(function () {
        Route::get('/', [AdminUserController::class, 'index']);
        Route::post('/invite', [AdminUserController::class, 'invite'])->middleware(\App\Http\Middleware\RequirePermission::class.':users.write');
        Route::put('/{user}/role', [AdminUserController::class, 'updateRole'])->middleware(\App\Http\Middleware\RequirePermission::class.':users.write');
        Route::delete('/{user}', [AdminUserController::class, 'deactivate'])->middleware(\App\Http\Middleware\RequirePermission::class.':users.delete');
        Route::post('/{user}/reactivate', [AdminUserController::class, 'reactivate'])->middleware(\App\Http\Middleware\RequirePermission::class.':users.write');
    });

    // --- Phase 5: Collections — tracked at invoice level (throttled, gate-enforced per method) ---
    Route::prefix('collections')->middleware(['throttle:30,1', DenyReadonlyRole::class])->group(function () {
        Route::get('/', [AdminCollectionsController::class, 'index']);
        Route::post('/{invoice}/advance', [AdminCollectionsController::class, 'advance']);
        Route::post('/{invoice}/clear', [AdminCollectionsController::class, 'clear']);
    });

    // --- Phase 5: Bulk Operations ---
    Route::prefix('operations')->middleware('throttle:10,1')->group(function () {
        Route::post('/bulk-health', [AdminOperationsController::class, 'bulkHealth']);
        Route::get('/batch/{batchId}', [AdminOperationsController::class, 'batchStatus']);
    });

    // --- Phase 5: Revenue Analytics ---
    Route::prefix('analytics')->group(function () {
        Route::get('/revenue', [AdminAnalyticsController::class, 'revenue']);
        Route::get('/plans', [AdminAnalyticsController::class, 'plans']);

        // --- Phase 6F: Catalog Intelligence Analytics ---
        Route::prefix('catalog')->group(function () {
            Route::get('/overview', [AdminCatalogAnalyticsController::class, 'overview']);
            Route::get('/entitlement-heatmap', [AdminCatalogAnalyticsController::class, 'entitlementHeatmap']);
            Route::get('/addon-adoption', [AdminCatalogAnalyticsController::class, 'addonAdoption']);
            Route::get('/revenue', [AdminCatalogAnalyticsController::class, 'revenueByItem']);
            Route::get('/plan-distribution', [AdminCatalogAnalyticsController::class, 'planDistribution']);
            Route::get('/plan-changes', [AdminCatalogAnalyticsController::class, 'planChangeActivity']);
            Route::get('/pricing-rules', [AdminCatalogAnalyticsController::class, 'pricingRuleImpact']);
        });
    });

    // --- Phase 6A: Product Catalog ---
    Route::prefix('catalog')->middleware(DenyReadonlyRole::class)->group(function () {
        // Item types — dynamic registry (no hardcoded enums)
        Route::get('/types', [AdminCatalogController::class, 'listTypes']);
        Route::post('/types', [AdminCatalogController::class, 'createType']);

        // Item CRUD
        Route::get('/', [AdminCatalogController::class, 'index']);   // tree
        Route::get('/flat', [AdminCatalogController::class, 'flat']);    // flat list for dropdowns
        Route::post('/', [AdminCatalogController::class, 'store']);
        Route::get('/{item}', [AdminCatalogController::class, 'show']);
        Route::put('/{item}', [AdminCatalogController::class, 'update']);
        Route::delete('/{item}', [AdminCatalogController::class, 'deactivate']); // soft only

        // Dependencies
        Route::post('/{item}/dependencies', [AdminCatalogController::class, 'addDependency']);
        Route::delete('/dependencies/{dependency}', [AdminCatalogController::class, 'removeDependency']);

        // Incompatibilities
        Route::post('/{item}/incompatibilities', [AdminCatalogController::class, 'addIncompatibility']);
        Route::delete('/incompatibilities/{incompatibility}', [AdminCatalogController::class, 'removeIncompatibility']);

        // Visibility rules
        Route::post('/{item}/visibility-rules', [AdminCatalogController::class, 'addVisibilityRule']);
        Route::delete('/visibility-rules/{rule}', [AdminCatalogController::class, 'removeVisibilityRule']);

        // Legacy migration — idempotent, safe to run multiple times
        Route::post('/sync', [AdminCatalogController::class, 'syncFromLegacy']);
    });

    // --- Phase 6C: Tenant Selection + Entitlements ---
    Route::prefix('tenants/{tenantId}')->group(function () {
        // Configuration view (full: tier + defaults + selections + entitlements)
        Route::get('/configuration', [AdminTenantConfigurationController::class, 'show']);

        // Selections (add-ons + overrides)
        Route::middleware(DenyReadonlyRole::class)->group(function () {
            Route::post('/configuration/selections', [AdminTenantConfigurationController::class, 'addSelection']);
            Route::delete('/configuration/selections/{item}', [AdminTenantConfigurationController::class, 'removeSelection']);
            Route::post('/configuration/reset', [AdminTenantConfigurationController::class, 'reset']);
        });

        // Entitlements
        Route::get('/entitlements', [AdminTenantConfigurationController::class, 'entitlements']);
        Route::get('/entitlements/check', [AdminTenantConfigurationController::class, 'checkEntitlement']);
        Route::get('/entitlements/jobs', [AdminTenantConfigurationController::class, 'rebuildJobs']);
        Route::post('/entitlements/rebuild', [AdminTenantConfigurationController::class, 'rebuild'])->middleware(DenyReadonlyRole::class);
    });

    // --- Phase 6D: Pricing Engine ---
    Route::prefix('pricing')->group(function () {
        // Prices (read — all roles; write — deny readonly)
        Route::get('/prices', [AdminPricingController::class, 'listPrices']);
        Route::middleware(DenyReadonlyRole::class)->group(function () {
            Route::put('/prices', [AdminPricingController::class, 'upsertPrice']);
            Route::delete('/prices/{priceId}', [AdminPricingController::class, 'deletePrice']);
        });

        // Rules
        Route::get('/rules', [AdminPricingController::class, 'listRules']);
        Route::middleware(DenyReadonlyRole::class)->group(function () {
            Route::post('/rules', [AdminPricingController::class, 'createRule']);
            Route::put('/rules/{rule}', [AdminPricingController::class, 'updateRule']);
            Route::delete('/rules/{ruleId}', [AdminPricingController::class, 'deleteRule']);
        });

        // Quotes — static sub-routes BEFORE parameterised
        Route::get('/quotes/{tenantId}/latest', [AdminPricingController::class, 'latestQuote']);
        Route::get('/quotes/{tenantId}', [AdminPricingController::class, 'quoteHistory']);
        Route::middleware(DenyReadonlyRole::class)->group(function () {
            Route::post('/quotes/{tenantId}', [AdminPricingController::class, 'generateQuote']);
        });
    });

    // --- Phase 7: Tenant API Keys (Feature Gate) ---
    Route::prefix('tenants/{tenantId}/api-keys')->group(function () {
        Route::get('/', [AdminTenantApiKeyController::class, 'index']);
        Route::middleware(DenyReadonlyRole::class)->group(function () {
            Route::post('/', [AdminTenantApiKeyController::class, 'store']);
            Route::patch('/{key}', [AdminTenantApiKeyController::class, 'update']);
            Route::delete('/{key}', [AdminTenantApiKeyController::class, 'destroy']);
        });
    });

    // --- Phase 6E: Plan Change Engine ---
    // Tenant-scoped — history and preview (read all roles; write deny readonly)
    Route::prefix('tenants/{tenantId}/plan-changes')->group(function () {
        Route::get('/', [AdminPlanChangeController::class, 'history']);
        Route::middleware(DenyReadonlyRole::class)->group(function () {
            Route::post('/', [AdminPlanChangeController::class, 'store']);
            Route::post('/preview', [AdminPlanChangeController::class, 'preview']);
        });
    });

    // Global pending queue + apply/cancel actions
    Route::prefix('plan-changes')->group(function () {
        Route::get('/', [AdminPlanChangeController::class, 'pending']);
        Route::middleware(DenyReadonlyRole::class)->group(function () {
            Route::post('/{change}/apply', [AdminPlanChangeController::class, 'apply']);
            Route::post('/{change}/cancel', [AdminPlanChangeController::class, 'cancel']);
        });
    });

    // --- Phase 6B: Tier Matrix (Packaging Layer) ---
    Route::prefix('packaging')->middleware(DenyReadonlyRole::class)->group(function () {
        // Full matrix — tiers × catalog items
        Route::get('/matrix', [AdminPackagingController::class, 'matrix']);

        // Single cell — set or clear
        Route::put('/matrix/{family}/{item}', [AdminPackagingController::class, 'setDefault']);
        Route::delete('/matrix/{family}/{item}', [AdminPackagingController::class, 'clearDefault']);

        // Tier-level views and bulk operations
        Route::get('/tiers/{family}', [AdminPackagingController::class, 'tierDefaults']);
        Route::post('/tiers/{family}/bulk', [AdminPackagingController::class, 'bulkSetTier']);

        // Item-level view
        Route::get('/items/{item}', [AdminPackagingController::class, 'itemDefaults']);
    });
});
