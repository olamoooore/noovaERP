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

    /**
     * Issue #441: customer master fields a merge may take from either record
     * (`field_choices` {field: survivor|merged}). Codes (unique per entity),
     * balances/statistics, hold state, status, the parent link and the
     * Contact Master link are deliberately not selectable.
     */
    public const FIELD_CHOICE_FIELDS = [
        'name',
        'type',
        'buyer_type',
        'email',
        'phone',
        'mobile',
        'company',
        'tax_number',
        'company_registration_number',
        'website',
        'language',
        'billing_address',
        'shipping_address',
        'credit_limit',
        'customer_group',
        'payment_terms',
        'payment_term_id',
        'price_list_id',
        'salesperson_id',
        'date_of_birth',
        'gender',
        'preferred_contact',
        'marketing_opt_in',
        'notes',
    ];

    public const FIELD_SOURCE_SURVIVOR = 'survivor';

    public const FIELD_SOURCE_MERGED = 'merged';

    /**
     * Issue #441: tables that link to a customer by record type + id (not by
     * customer_id), with the column pairs that, together with type + id, form
     * a unique key. A merged-customer row that would collide with an
     * equivalent survivor row is dropped (the survivor already carries it);
     * every other row is re-pointed.
     *
     * activity_log is not moved: it is the audit trail of the merged record.
     *
     * @var array<string, array{type:string, id:string, string_id:bool, unique:list<list<string>>}>
     */
    private const RECORD_LINKS = [
        'taggables' => ['type' => 'taggable_type', 'id' => 'taggable_id', 'string_id' => false, 'unique' => [['tag_id']]],
        'communication_messages' => ['type' => 'model', 'id' => 'record_id', 'string_id' => false, 'unique' => []],
        'communication_subscribers' => ['type' => 'model', 'id' => 'record_id', 'string_id' => false, 'unique' => [['user_id', 'tenant_id'], ['customer_id', 'tenant_id']]],
        'chatter_messages' => ['type' => 'subject_type', 'id' => 'subject_id', 'string_id' => true, 'unique' => []],
        'chatter_activities' => ['type' => 'subject_type', 'id' => 'subject_id', 'string_id' => true, 'unique' => []],
        'chatter_attachments' => ['type' => 'subject_type', 'id' => 'subject_id', 'string_id' => true, 'unique' => []],
        'document_followers' => ['type' => 'document_type', 'id' => 'document_id', 'string_id' => false, 'unique' => [['user_id'], ['contact_id']]],
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
     * @param  array<string, string>  $fieldChoices  field => survivor|merged (Issue #441)
     * @return array{primary_customer_id:int, merged_customer_id:int, moved:list<string>, field_choices:array<string, string>}
     */
    public function merge(Customer $primary, Customer $merged, int $mergedBy, ?string $reason, array $fieldChoices = []): array
    {
        return DB::transaction(function () use ($primary, $merged, $mergedBy, $reason, $fieldChoices) {
            // Issue #441: apply the per-field choices before the Contact
            // Master sync below, so the canonical contact mirrors them.
            $recordedChoices = $this->applyFieldChoices($primary, $merged, $fieldChoices);

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

            foreach ($this->repointRecordLinks((int) $merged->id, (int) $primary->id) as $table) {
                $moved[] = $table;
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

            if ($recordedChoices !== []) {
                $this->recordFieldChoices($primaryContact, $mergedContact, $recordedChoices);
            }

            return [
                'primary_customer_id' => (int) $primary->id,
                'merged_customer_id' => (int) $merged->id,
                'moved' => $moved,
                'field_choices' => array_map(fn (array $choice) => $choice['source'], $recordedChoices),
            ];
        });
    }

    /**
     * Copy every field chosen from the merged record onto the survivor.
     *
     * @param  array<string, string>  $fieldChoices
     * @return array<string, array{source:string, previous:mixed, value:mixed}>
     */
    private function applyFieldChoices(Customer $primary, Customer $merged, array $fieldChoices): array
    {
        $recorded = [];

        foreach ($fieldChoices as $field => $source) {
            if (! in_array($field, self::FIELD_CHOICE_FIELDS, true)) {
                throw new \InvalidArgumentException("Field '{$field}' cannot be chosen in a customer merge.");
            }

            $previous = $primary->getRawOriginal($field);
            $value = $source === self::FIELD_SOURCE_MERGED ? $merged->getRawOriginal($field) : $previous;

            if ($source === self::FIELD_SOURCE_MERGED) {
                $primary->setAttribute($field, $merged->getAttribute($field));
            }

            $recorded[$field] = ['source' => $source, 'previous' => $previous, 'value' => $value];
        }

        if ($primary->isDirty()) {
            $primary->save();
        }

        return $recorded;
    }

    /**
     * Add the field choices to the ContactMergeHistory row the Contact Master
     * merge just wrote (its moved-group list stays as-is).
     *
     * @param  array<string, array{source:string, previous:mixed, value:mixed}>  $choices
     */
    private function recordFieldChoices(string $primaryContact, string $mergedContact, array $choices): void
    {
        $history = ContactMergeHistory::query()
            ->where('primary_contact_id', $primaryContact)
            ->where('merged_contact_id', $mergedContact)
            ->latest('merged_at')
            ->first();

        if (! $history) {
            throw new \RuntimeException('Merge history row missing; cannot record field choices.');
        }

        $details = (array) $history->merge_details;
        $details[] = ['field_choices' => $choices];

        $history->update(['merge_details' => $details]);
    }

    /**
     * Re-point the rows that link to the merged customer by record type + id.
     *
     * @return list<string> tables with at least one row moved
     */
    private function repointRecordLinks(int $from, int $to): array
    {
        $types = array_values(array_unique([
            Customer::class,
            (new Customer)->getMorphClass(),
            'customer',
        ]));
        $moved = [];

        foreach (self::RECORD_LINKS as $table => $spec) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $typeColumn = $spec['type'];
            $idColumn = $spec['id'];
            $fromId = $spec['string_id'] ? (string) $from : $from;
            $toId = $spec['string_id'] ? (string) $to : $to;

            foreach ($spec['unique'] as $columns) {
                DB::table("{$table} as m")
                    ->whereIn("m.{$typeColumn}", $types)
                    ->where("m.{$idColumn}", $fromId)
                    ->whereExists(function ($query) use ($table, $typeColumn, $idColumn, $toId, $columns) {
                        $query->selectRaw('1')
                            ->from("{$table} as p")
                            ->whereColumn("p.{$typeColumn}", "m.{$typeColumn}")
                            ->where("p.{$idColumn}", $toId);

                        foreach ($columns as $column) {
                            $query->whereColumn("p.{$column}", "m.{$column}");
                        }
                    })
                    ->delete();
            }

            $count = DB::table($table)
                ->whereIn($typeColumn, $types)
                ->where($idColumn, $fromId)
                ->update([$idColumn => $toId]);

            if ($count > 0) {
                $moved[] = $table;
            }
        }

        return $moved;
    }

    private function tableHasCustomerId(string $table, ?string $extraColumn = null): bool
    {
        return Schema::hasTable($table)
            && Schema::hasColumn($table, 'customer_id')
            && ($extraColumn === null || Schema::hasColumn($table, $extraColumn));
    }
}
