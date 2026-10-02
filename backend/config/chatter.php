<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Chatter Configuration
|--------------------------------------------------------------------------
|
| Issue #75 (CRITICAL, CWE-94 code injection): the Chatter controller used to
| accept ANY user-supplied `subject_type` string that passed `class_exists()`,
| which every autoloadable class (including framework/vendor classes with
| constructor side effects) satisfies. That allowed an attacker to attempt to
| instantiate arbitrary classes via the polymorphic subject_type.
|
| `allowed_models` is the authoritative whitelist of Eloquent models that may
| be used as a chatter subject. ChatterController::validateSubject() rejects
| anything not in this list (strict comparison), with a defence-in-depth
| Model-subclass + framework-namespace check on top.
|
| To enable chatter on a new model, add its fully-qualified class name here.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed chatter subject models (whitelist)
    |--------------------------------------------------------------------------
    |
    | Only these fully-qualified Eloquent model classes may be passed as a
    | chatter `subject_type`. Verified to exist and extend Eloquent\Model.
    | NOTE: use the canonical module namespace (Modules\Sales\Models\...),
    | NOT the broken Modules\Sales\src\Models\... path (tracked separately
    | in issue #157).
    |
    */
    'allowed_models' => [
        // Core accounting / sales documents
        \App\Models\Invoice::class,
        \App\Models\Quotation::class,
        \App\Models\Bill::class,
        \App\Models\Payment::class,
        \App\Models\JournalEntry::class,
        // Parties
        \App\Models\Customer::class,
        \App\Models\Vendor::class,
        // Sales module documents
        \Modules\Sales\Models\SalesOrder::class,
        \Modules\Sales\Models\DeliveryOrder::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Subject record-level access map (issue #74)
    |--------------------------------------------------------------------------
    |
    | Reading chatter about a record requires the SAME module view-permission
    | that gates reading the record itself. ChatterController resolves the
    | subject record (404 if it does not exist) and then checks the mapped
    | permission via TenantRoleService (403 if denied). A subject with no
    | mapping fails closed (403).
    |
    | NOTE: this is record-TYPE-level access control. True row-level entity
    | scoping (legal_entity_id) is not possible on these models because the
    | columns do not exist yet — that is the separate gap tracked in #97/#140.
    |
    */
    'subject_permissions' => [
        // Must mirror the permission that actually gates reading the record:
        // invoices are gated by `invoices.view` (not `accounting.view`), so a
        // Sales user who can open an invoice can read its chatter too.
        \App\Models\Invoice::class => 'invoices.view',
        \App\Models\Bill::class => 'accounting.view',
        \App\Models\Payment::class => 'accounting.view',
        \App\Models\JournalEntry::class => 'accounting.view',
        \App\Models\Vendor::class => 'accounting.view',
        \App\Models\Customer::class => 'customers.view',
        \App\Models\Quotation::class => 'sales.view',
        \Modules\Sales\Models\SalesOrder::class => 'sales.view',
        \Modules\Sales\Models\DeliveryOrder::class => 'sales.view',
    ],

    /*
    |--------------------------------------------------------------------------
    | Subject write-permission map (issue #629, CH1)
    |--------------------------------------------------------------------------
    |
    | Posting a message, scheduling/completing an activity, uploading an
    | attachment and deleting an attachment all MUTATE the record's chatter.
    | They require the module WRITE permission below — the same permission the
    | record's own mutating routes use — not the view permission above.
    |
    | Kept as a separate map so `subject_permissions` keeps its existing shape
    | (view permission per subject) for backward compatibility.
    |
    | Every subject in `allowed_models` must appear here: a missing write
    | mapping fails closed (writes are refused), by design.
    |
    | Only real, seeded permissions are used (RolePermissionSeeder
    | PERMISSION_MATRIX). There is no vendor-/bill-/payment-/journal-specific
    | write permission, so those accounting records use `accounting.write`.
    |
    */
    'subject_write_permissions' => [
        \App\Models\Invoice::class => 'invoices.write',
        \App\Models\Bill::class => 'accounting.write',
        \App\Models\Payment::class => 'accounting.write',
        \App\Models\JournalEntry::class => 'accounting.write',
        \App\Models\Vendor::class => 'accounting.write',
        \App\Models\Customer::class => 'customers.write',
        // Quotations' own routes are gated by quotations.write (see
        // modules/Core/routes/api.php), so mirror that for chatter writes.
        \App\Models\Quotation::class => 'quotations.write',
        \Modules\Sales\Models\SalesOrder::class => 'sales.write',
        \Modules\Sales\Models\DeliveryOrder::class => 'sales.write',
    ],

];
