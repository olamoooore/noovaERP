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

];
