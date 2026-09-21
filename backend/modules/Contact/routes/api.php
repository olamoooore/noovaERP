<?php

use App\Http\Middleware\RequirePermission;
use Illuminate\Support\Facades\Route;
use Modules\Contact\src\Controllers\ContactMasterController;

$controller = ContactMasterController::class;

/*
|--------------------------------------------------------------------------
| Contact Master API Routes
|--------------------------------------------------------------------------
|
| Issue #116 (CRITICAL): every route now carries an explicit RequirePermission
| gate mapped to the permission strings declared in ContactModuleContract.
| Previously the only middleware was auth:sanctum + tenant resolution, so ANY
| authenticated user (any role) could view/edit/merge/delete any contact and
| read sensitive module-profile data (HR salary, accounting credit limits,
| bank details). Permissions are seeded in RolePermissionSeeder mirroring the
| customers.* sensitivity model.
|
*/
Route::middleware(['auth:sanctum', \App\Http\Middleware\SetTenantFromUserPreference::class, \App\Http\Middleware\EnsureTenantSearchPath::class, \App\Http\Middleware\SyncTenantUserFromCentralAuth::class])->group(function () use ($controller) {

    // ─── Contact CRUD ─────────────────────────────────────────
    Route::get('/contacts', [$controller, 'index'])
        ->middleware(RequirePermission::using('contacts.view'));
    Route::post('/contacts', [$controller, 'store'])
        ->middleware(RequirePermission::using('contacts.create'));
    Route::get('/contacts/search', [$controller, 'search'])
        ->middleware(RequirePermission::using('contacts.search'));
    Route::get('/contacts/duplicates', [$controller, 'duplicates'])
        ->middleware(RequirePermission::using('contacts.view'));
    Route::get('/contacts/taxonomy', [$controller, 'taxonomy'])
        ->middleware(RequirePermission::using('contacts.view'));
    Route::put('/contacts/taxonomy/label', [$controller, 'updateTaxonomyLabel'])
        ->middleware(RequirePermission::using('contacts.edit'));
    Route::post('/contacts/merge', [$controller, 'merge'])
        ->middleware(RequirePermission::using('contacts.merge'));
    // Relationship-scoped updates registered before /contacts/{id} so the
    // literal 'relationships' segment is not captured as a contact id.
    Route::put('/contacts/relationships/{relationshipId}', [$controller, 'updateRelationship'])
        ->middleware(RequirePermission::using('contacts.relationships.manage'));
    Route::put('/contacts/relationships/{relationshipId}/roles', [$controller, 'setRelationshipRoles'])
        ->middleware(RequirePermission::using('contacts.roles.manage'));
    Route::get('/contacts/{id}', [$controller, 'show'])
        ->middleware(RequirePermission::using('contacts.view'));
    Route::put('/contacts/{id}', [$controller, 'update'])
        ->middleware(RequirePermission::using('contacts.edit'));
    Route::delete('/contacts/{id}', [$controller, 'destroy'])
        ->middleware(RequirePermission::using('contacts.delete'));

    // ─── 360 View ─────────────────────────────────────────────
    Route::get('/contacts/{id}/360', [$controller, 'show360'])
        ->middleware(RequirePermission::using('contacts.view'));

    // ─── Roles ────────────────────────────────────────────────
    Route::get('/contacts/{contactId}/roles', [$controller, 'listRoles'])
        ->middleware(RequirePermission::using('contacts.view'));
    Route::post('/contacts/{contactId}/roles', [$controller, 'addRole'])
        ->middleware(RequirePermission::using('contacts.roles.manage'));
    Route::delete('/contacts/{contactId}/roles/{role}', [$controller, 'removeRole'])
        ->middleware(RequirePermission::using('contacts.roles.manage'));

    // ─── Identifiers ──────────────────────────────────────────
    Route::get('/contacts/{contactId}/identifiers', [$controller, 'listIdentifiers'])
        ->middleware(RequirePermission::using('contacts.view'));
    Route::post('/contacts/{contactId}/identifiers', [$controller, 'addIdentifier'])
        ->middleware(RequirePermission::using('contacts.identifiers.manage'));

    // ─── Addresses ────────────────────────────────────────────
    Route::get('/contacts/{contactId}/addresses', [$controller, 'listAddresses'])
        ->middleware(RequirePermission::using('contacts.view'));
    Route::post('/contacts/{contactId}/addresses', [$controller, 'addAddress'])
        ->middleware(RequirePermission::using('contacts.addresses.manage'));

    // ─── Methods ──────────────────────────────────────────────
    Route::get('/contacts/{contactId}/methods', [$controller, 'listMethods'])
        ->middleware(RequirePermission::using('contacts.view'));
    Route::post('/contacts/{contactId}/methods', [$controller, 'addMethod'])
        ->middleware(RequirePermission::using('contacts.methods.manage'));

    // ─── Relationships ────────────────────────────────────────
    Route::get('/contacts/{contactId}/relationships', [$controller, 'listRelationships'])
        ->middleware(RequirePermission::using('contacts.view'));
    Route::post('/contacts/{contactId}/relationships', [$controller, 'addRelationship'])
        ->middleware(RequirePermission::using('contacts.relationships.manage'));

    // ─── Classifications (profession/person class) ───────────
    Route::get('/contacts/{contactId}/classifications', [$controller, 'listClassifications'])
        ->middleware(RequirePermission::using('contacts.view'));
    Route::post('/contacts/{contactId}/classifications', [$controller, 'assignClassification'])
        ->middleware(RequirePermission::using('contacts.edit'));
    Route::delete('/contacts/{contactId}/classifications/{code}', [$controller, 'removeClassification'])
        ->middleware(RequirePermission::using('contacts.edit'));

    // ─── Module Profiles ──────────────────────────────────────
    // profiles.view for reads; profiles.manage for the module-profile upserts.
    // The accounting customer/vendor profiles carry credit-limit & bank data,
    // so they additionally require the bank-account permission.
    Route::get('/contacts/{contactId}/profiles', [$controller, 'profiles'])
        ->middleware(RequirePermission::using('contacts.profiles.view'));
    Route::post('/contacts/{contactId}/profiles/accounting/customer', [$controller, 'upsertAccountingCustomer'])
        ->middleware(RequirePermission::using('contacts.bank_accounts.edit'));
    Route::post('/contacts/{contactId}/profiles/accounting/vendor', [$controller, 'upsertAccountingVendor'])
        ->middleware(RequirePermission::using('contacts.bank_accounts.edit'));
    Route::post('/contacts/{contactId}/profiles/crm', [$controller, 'upsertCrm'])
        ->middleware(RequirePermission::using('contacts.profiles.manage'));
    Route::post('/contacts/{contactId}/profiles/hr', [$controller, 'upsertHr'])
        ->middleware(RequirePermission::using('contacts.profiles.manage'));
    Route::post('/contacts/{contactId}/profiles/pos', [$controller, 'upsertPosLoyalty'])
        ->middleware(RequirePermission::using('contacts.profiles.manage'));
    Route::post('/contacts/{contactId}/profiles/property', [$controller, 'upsertProperty'])
        ->middleware(RequirePermission::using('contacts.profiles.manage'));
    Route::post('/contacts/{contactId}/profiles/project', [$controller, 'upsertProject'])
        ->middleware(RequirePermission::using('contacts.profiles.manage'));

    // ─── Import ──────────────────────────────────────────────
    Route::post('/contacts/import', [$controller, 'import'])
        ->middleware(RequirePermission::using('contacts.import'));
    Route::get('/contacts/import/{batch}', [$controller, 'importStatus'])
        ->middleware(RequirePermission::using('contacts.import'));
    Route::post('/contacts/import/{batch}/confirm', [$controller, 'importConfirm'])
        ->middleware(RequirePermission::using('contacts.import'));
});
