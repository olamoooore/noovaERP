<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Contact\src\Models\ContactMergeHistory;
use Modules\Contact\src\Services\ContactMasterService;

/**
 * Issue #435 (CU7): customer merge.
 *
 * Re-points the merged customer's documents and CRM relations at the surviving
 * customer, archives the merged customer (never deletes it), and delegates the
 * Contact Master side of the merge (contact relations + the
 * ContactMergeHistory audit row) to the shared ContactMasterService.
 *
 * Tables whose customer_id is part of a unique key (customer_category,
 * customer_loyalty_balances, customer_bonus_schemes, web_carts) are not moved
 * mechanically by this issue: moving or dropping the surviving row is a
 * product/data decision; only customer_category is de-duplicated because its
 * conflict carries no amount/state.
 */
class CustomerMergeService
{
    /**
     * Customer-owned tables without a unique customer key: every row may be
     * re-pointed safely. Unknown/absent module tables are skipped.
     */
    public const CUSTOMER_RELATIONS = [
        'invoices',
        'quotations',
        'sales_orders',
        'sales_blanket_orders',
        'sales_order_recurrences',
        'delivery_orders',
        'pos_orders',
        'pos_held_orders',
        'web_orders',
        'web_customer_sessions',
        'payments',
        'payment_transactions',
        'customer_credit_notes',
        'medical_cases',
        'customer_loyalty_transactions',
        'customer_contacts',
        'customer_addresses',
        'customer_statements',
        'customer_statement_deliveries',
        'unapplied_cash',
        'deferred_revenue_schedules',
        'ai_conversations',
        'field_service_work_orders',
        'storefront_orders',
    ];

    public function __construct(
        private readonly CustomerContactSyncService $contacts,
        private readonly ContactMasterService $contactMaster,
    ) {}

    public function isAlreadyMerged(Customer $customer): bool
    {
        if ($customer->is_active === false) {
            return true;
        }

        if ($customer->core_contact_uuid) {
            return ContactMergeHistory::query()
                ->where('merged_contact_id', $customer->core_contact_uuid)
                ->exists();
        }

        return false;
    }

    /**
     * @return array{primary_customer_id:int, merged_customer_id:int, moved:list<string>}
     */
    public function merge(Customer $primary, Customer $merged, int $mergedBy, ?string $reason): array
    {
        return DB::transaction(function () use ($primary, $merged, $mergedBy, $reason) {
            $primaryContact = $this->contacts->ensureOrganisationContact($primary);
            $mergedContact = $this->contacts->ensureOrganisationContact($merged);

            $moved = [];

            foreach (self::CUSTOMER_RELATIONS as $table) {
                if (! $this->tableHasCustomerId($table)) {
                    continue;
                }

                $count = DB::table($table)
                    ->where('customer_id', $merged->id)
                    ->update(['customer_id' => $primary->id]);

                if ($count > 0) {
                    $moved[] = $table;
                }
            }

            if ($this->tableHasCustomerId('customer_category', 'customer_category_id')) {
                $primaryCategoryIds = DB::table('customer_category')
                    ->where('customer_id', $primary->id)
                    ->pluck('customer_category_id')
                    ->all();

                if ($primaryCategoryIds !== []) {
                    DB::table('customer_category')
                        ->where('customer_id', $merged->id)
                        ->whereIn('customer_category_id', $primaryCategoryIds)
                        ->delete();
                }

                $count = DB::table('customer_category')
                    ->where('customer_id', $merged->id)
                    ->update(['customer_id' => $primary->id]);

                if ($count > 0) {
                    $moved[] = 'customer_category';
                }
            }

            $reparented = Customer::query()
                ->where('parent_id', $merged->id)
                ->update(['parent_id' => $primary->id]);

            if ($reparented > 0) {
                $moved[] = 'customer_children';
            }

            $merged->update(['is_active' => false]);

            if (! $this->contactMaster->merge(
                $primaryContact,
                $mergedContact,
                (string) tenant()->id,
                (string) $mergedBy,
                $reason,
                $moved,
            )) {
                throw new \RuntimeException(
                    "Contact Master merge failed for customers {$primary->id} -> {$merged->id}."
                );
            }

            return [
                'primary_customer_id' => (int) $primary->id,
                'merged_customer_id' => (int) $merged->id,
                'moved' => $moved,
            ];
        });
    }

    private function tableHasCustomerId(string $table, ?string $extraColumn = null): bool
    {
        return Schema::hasTable($table)
            && Schema::hasColumn($table, 'customer_id')
            && ($extraColumn === null || Schema::hasColumn($table, $extraColumn));
    }
}
