<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Approval request subject_type whitelist (issue #651 [SALES-APR] AP7)
|--------------------------------------------------------------------------
|
| POST /approvals used to validate `subject_type` as a bare `string`, so an
| approval request could be raised against an arbitrary value — which
| ApprovalController::index/show then handed to the polymorphic `subject()`
| relation. That is the same arbitrary-class-instantiation problem chatter
| fixed in issue #75, plus the ability to pollute the approval inbox with
| requests pointing at anything.
|
| This is the authoritative whitelist, shaped as
| `accepted subject_type value => class used to load the document` so the
| validation rule (ApprovalController::store) and the safe subject loader
| (ApprovalRequest::loadSubjects) can never drift apart.
|
| Provenance — every key is a value an ApprovalRequest writer actually
| produces today (grep of all ApprovalRequest::create call sites):
|   'PosOrder'  : POS void/refund/return — frontend ApprovalRequestModal
|                 and PosOrderController's createApprovalRequest calls
|                 (the column comment on approval_requests.subject_type
|                 documents 'PosOrder' as the canonical short name).
|   Invoice     : InvoiceApprovalService, SalesApprovalGate.
|   Quotation   : SalesApprovalGate.
|   SalesOrder  : SalesApprovalGate.
|   DeliveryOrder : DeliveryOrderService, SalesApprovalGate.
|
| To support a new subject type, add it here AND map its module view
| permission in `chatter.subject_permissions` (that map is what lets users
| who may read the document see its approval request — a subject type with
| no mapping stays visible only to its requester and to whoever may decide
| it, by design: fail closed).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed approval subject types (whitelist)
    |--------------------------------------------------------------------------
    |
    | Key   — the exact subject_type / source_type value POST /approvals
    |         accepts (strict comparison, no URL-decode or case bypass).
    | Value — the Eloquent class list/show uses to load the subject document.
    |         Must exist and extend Eloquent\Model (checked at load time as
    |         defence in depth, mirroring chatter's #75 guards).
    |
    */
    'subject_types' => [
        'PosOrder' => \Modules\POS\Models\PosOrder::class,
        \App\Models\Invoice::class => \App\Models\Invoice::class,
        \App\Models\Quotation::class => \App\Models\Quotation::class,
        \Modules\Sales\Models\SalesOrder::class => \Modules\Sales\Models\SalesOrder::class,
        \Modules\Sales\Models\DeliveryOrder::class => \Modules\Sales\Models\DeliveryOrder::class,
    ],

];
