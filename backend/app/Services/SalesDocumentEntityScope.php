<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;

/**
 * Legal-entity row scope for sales documents, shared by the Sales cockpit
 * action queues (#365) and the assigned-activity surfaces (#410: "My
 * activities", the cockpit activities tile and the due-activity reminders).
 *
 * Extracted verbatim from SalesActionQueueService::scopeEntities (#365) so
 * both callers apply ONE rule:
 *   - orders and deliveries carry their own legal entity;
 *   - invoices (and credit notes, which carry the same column) by their own
 *     entity (#875), falling back to the customer's for rows not yet stamped;
 *   - anything else (quotations) only through its customer.
 * A user with no entity grants is unscoped (the EnsureEntityAccess fail-open
 * default).
 */
class SalesDocumentEntityScope
{
    public function __construct(
        private readonly EntityAccessService $entityAccess,
    ) {}

    public function apply(Builder $query, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        $entityIds = $this->entityAccess->accessibleEntityIds((int) $user->id);

        if ($entityIds === []) {
            return;
        }

        $model = $query->getModel();
        $table = $model->getTable();
        $customerInScope = fn ($customers) => $customers->select('id')->from('customers')
            ->where(fn ($q) => $q->whereIn('legal_entity_id', $entityIds)->orWhereNull('legal_entity_id'));

        if ($model instanceof SalesOrder || $model instanceof DeliveryOrder) {
            $query->whereIn("{$table}.legal_entity_id", $entityIds);
        } elseif ($model instanceof Invoice || $model instanceof CustomerCreditNote) {
            $query->where(function (Builder $scope) use ($table, $entityIds, $customerInScope) {
                $scope->whereIn("{$table}.legal_entity_id", $entityIds)
                    ->orWhere(fn (Builder $unstamped) => $unstamped
                        ->whereNull("{$table}.legal_entity_id")
                        ->whereIn("{$table}.customer_id", $customerInScope));
            });
        } else {
            $query->whereIn("{$table}.customer_id", $customerInScope);
        }
    }
}
