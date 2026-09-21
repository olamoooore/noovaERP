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

];
