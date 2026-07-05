<?php

use Illuminate\Support\Facades\Route;
use Modules\Contact\src\Controllers\ContactMasterController;

$controller = ContactMasterController::class;

Route::middleware(['auth:sanctum', \App\Http\Middleware\SetTenantFromUserPreference::class, \App\Http\Middleware\EnsureTenantSearchPath::class, \App\Http\Middleware\SyncTenantUserFromCentralAuth::class])->group(function () use ($controller) {

    // ─── Contact CRUD ─────────────────────────────────────────
    Route::get('/contacts', [$controller, 'index']);
    Route::post('/contacts', [$controller, 'store']);
    Route::get('/contacts/search', [$controller, 'search']);
    Route::get('/contacts/duplicates', [$controller, 'duplicates']);
    Route::post('/contacts/merge', [$controller, 'merge']);
    Route::get('/contacts/{id}', [$controller, 'show']);
    Route::put('/contacts/{id}', [$controller, 'update']);
    Route::delete('/contacts/{id}', [$controller, 'destroy']);

    // ─── 360 View ─────────────────────────────────────────────
    Route::get('/contacts/{id}/360', [$controller, 'show360']);

    // ─── Roles ────────────────────────────────────────────────
    Route::get('/contacts/{contactId}/roles', [$controller, 'listRoles']);
    Route::post('/contacts/{contactId}/roles', [$controller, 'addRole']);
    Route::delete('/contacts/{contactId}/roles/{role}', [$controller, 'removeRole']);

    // ─── Identifiers ──────────────────────────────────────────
    Route::get('/contacts/{contactId}/identifiers', [$controller, 'listIdentifiers']);
    Route::post('/contacts/{contactId}/identifiers', [$controller, 'addIdentifier']);

    // ─── Addresses ────────────────────────────────────────────
    Route::get('/contacts/{contactId}/addresses', [$controller, 'listAddresses']);
    Route::post('/contacts/{contactId}/addresses', [$controller, 'addAddress']);

    // ─── Methods ──────────────────────────────────────────────
    Route::get('/contacts/{contactId}/methods', [$controller, 'listMethods']);
    Route::post('/contacts/{contactId}/methods', [$controller, 'addMethod']);

    // ─── Relationships ────────────────────────────────────────
    Route::get('/contacts/{contactId}/relationships', [$controller, 'listRelationships']);
    Route::post('/contacts/{contactId}/relationships', [$controller, 'addRelationship']);

    // ─── Module Profiles ──────────────────────────────────────
    Route::get('/contacts/{contactId}/profiles', [$controller, 'profiles']);
    Route::post('/contacts/{contactId}/profiles/accounting/customer', [$controller, 'upsertAccountingCustomer']);
    Route::post('/contacts/{contactId}/profiles/accounting/vendor', [$controller, 'upsertAccountingVendor']);
    Route::post('/contacts/{contactId}/profiles/crm', [$controller, 'upsertCrm']);
    Route::post('/contacts/{contactId}/profiles/hr', [$controller, 'upsertHr']);
    Route::post('/contacts/{contactId}/profiles/pos', [$controller, 'upsertPosLoyalty']);
    Route::post('/contacts/{contactId}/profiles/property', [$controller, 'upsertProperty']);
    Route::post('/contacts/{contactId}/profiles/project', [$controller, 'upsertProject']);

    // ─── Import ──────────────────────────────────────────────
    Route::post('/contacts/import', [$controller, 'import']);
    Route::get('/contacts/import/{batch}', [$controller, 'importStatus']);
    Route::post('/contacts/import/{batch}/confirm', [$controller, 'importConfirm']);
});
