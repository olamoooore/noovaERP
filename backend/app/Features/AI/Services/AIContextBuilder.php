<?php

namespace App\Features\AI\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI Context Builder
 *
 * Builds filtered context for AI consumption. Excludes restricted HR/private data.
 * Returns context summaries, not raw tables.
 *
 * NEG-017: AI context includes restricted HR/private data → blocked
 */
class AIContextBuilder
{
    private AIRawAccessGuard $rawAccessGuard;

    private AIReasoningRecordService $reasoningRecord;

    public function __construct(
        AIRawAccessGuard $rawAccessGuard,
        AIReasoningRecordService $reasoningRecord
    ) {
        $this->rawAccessGuard = $rawAccessGuard;
        $this->reasoningRecord = $reasoningRecord;
    }

    /**
     * Build safe context for AI from a given module/entity.
     *
     * @param  string  $module  The module (e.g., 'accounting', 'crm', 'inventory')
     * @param  string  $entityType  The entity type (e.g., 'contact', 'product', 'invoice')
     * @param  int|null  $entityId  Optional specific entity ID
     * @param  string  $userId  The user requesting context
     * @param  array  $options  Additional options (fields, filters, etc.)
     * @return array{success: bool, context: array|null, reason: string|null}
     */
    public function buildContext(
        string $module,
        string $entityType,
        ?int $entityId,
        string $userId,
        array $options = []
    ): array {
        // 1. Determine the table for this entity
        $tableName = $this->resolveTableName($entityType);
        if (! $tableName) {
            return [
                'success' => false,
                'context' => null,
                'reason' => "Unknown entity type: {$entityType}",
            ];
        }

        // 2. Check raw access is denied
        $accessCheck = $this->rawAccessGuard->checkRawAccess($tableName, $userId, [
            'module' => $module,
            'action' => 'buildContext',
        ]);
        if (! $accessCheck['allowed']) {
            return [
                'success' => false,
                'context' => null,
                'reason' => $accessCheck['reason'],
            ];
        }

        // 3. Query via AI-safe view/summary, NOT raw table
        $context = $this->fetchAISafeData($module, $entityType, $entityId, $options);
        if ($context === null) {
            return [
                'success' => false,
                'context' => null,
                'reason' => "Failed to fetch AI-safe data for {$entityType}",
            ];
        }

        // 4. Filter out restricted fields
        $filteredContext = $this->filterContext($context);

        // 5. Record the reasoning event
        $this->reasoningRecord->record([
            'user_id' => $userId,
            'module' => $module,
            'action' => 'buildContext',
            'input_prompt' => json_encode([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'options' => $options,
            ]),
            'data_accessed' => json_encode([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'fields_returned' => array_keys($filteredContext),
                'fields_filtered' => $this->getFilteredFieldNames($context, $filteredContext),
            ]),
            'reasoning_steps' => json_encode([
                '1. Resolved table name',
                '2. Checked raw access (denied)',
                '3. Fetched AI-safe data',
                '4. Filtered restricted fields',
                '5. Recorded reasoning event',
            ]),
        ]);

        return [
            'success' => true,
            'context' => $filteredContext,
            'reason' => null,
        ];
    }

    /**
     * Build context for multiple entities.
     *
     * @return array{success: bool, contexts: array, summary: string}
     */
    public function buildBatchContext(
        string $module,
        string $entityType,
        array $entityIds,
        string $userId,
        array $options = []
    ): array {
        $contexts = [];
        $failed = [];

        foreach ($entityIds as $entityId) {
            $result = $this->buildContext($module, $entityType, $entityId, $userId, $options);
            if ($result['success']) {
                $contexts[] = $result['context'];
            } else {
                $failed[] = ['id' => $entityId, 'reason' => $result['reason']];
            }
        }

        $summary = 'Fetched '.count($contexts)." {$entityType} records for module {$module}.";
        if (! empty($failed)) {
            $summary .= ' '.count($failed).' records failed access check.';
        }

        return [
            'success' => true,
            'contexts' => $contexts,
            'summary' => $summary,
            'failed' => $failed,
        ];
    }

    /**
     * Get safe summary for a specific entity (no raw data).
     *
     * @return array{success: bool, summary: array|null, reason: string|null}
     */
    public function getEntitySummary(string $entityType, int $entityId, string $userId): array
    {
        $result = $this->buildContext('summary', $entityType, $entityId, $userId);

        if (! $result['success']) {
            return $result;
        }

        // Build a human-readable summary
        $summary = $this->buildSummaryText($entityType, $result['context']);

        return [
            'success' => true,
            'summary' => $summary,
            'reason' => null,
        ];
    }

    /**
     * Resolve entity type to database table name.
     */
    private function resolveTableName(string $entityType): ?string
    {
        $mapping = [
            'contact' => 'contacts',
            'product' => 'products',
            'invoice' => 'invoices',
            'bill' => 'bills',
            'payment' => 'payments',
            'journal_entry' => 'journal_entries',
            'account' => 'accounts',
            'tax' => 'taxes',
            'warehouse' => 'warehouses',
            'stock_move' => 'stock_moves',
            'purchase_order' => 'purchase_orders',
            'sales_order' => 'sales_orders',
            'delivery_order' => 'delivery_orders',
            'quotation' => 'quotations',
            'credit_note' => 'credit_notes',
            'debit_note' => 'debit_notes',
            'bank_account' => 'bank_accounts',
            'bank_transaction' => 'bank_transactions',
            'fiscal_year' => 'fiscal_years',
            'employee' => 'hr_employees',
        ];

        return $mapping[$entityType] ?? null;
    }

    /**
     * Fetch data through AI-safe views instead of raw tables.
     * This uses read-only summary views that exclude sensitive fields.
     */
    private function fetchAISafeData(
        string $module,
        string $entityType,
        ?int $entityId,
        array $options
    ): ?array {
        // Use AI-safe summary views (defined in migrations)
        $viewName = "ai_safe_{$entityType}_summary";

        try {
            // Check if the AI-safe view exists
            $viewExists = DB::select(
                'SELECT 1 FROM information_schema.views WHERE table_name = ?',
                [$viewName]
            );

            if (! empty($viewExists)) {
                // Query the safe view instead of raw table
                $query = DB::table($viewName)
                    ->where('tenant_id', tenant()->id);

                if ($entityId) {
                    $query->where('id', $entityId);
                }

                if (! empty($options['limit'])) {
                    $query->limit($options['limit']);
                }

                return $query->get()->toArray();
            }

            // Fallback: Build safe summary from entity-specific logic
            return $this->buildFallbackSummary($module, $entityType, $entityId, $options);
        } catch (\Exception $e) {
            Log::error('AI_CONTEXT_BUILDER_ERROR', [
                'module' => $module,
                'entity_type' => $entityType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Fallback summary builder when AI-safe views don't exist.
     * Returns only non-restricted fields.
     */
    private function buildFallbackSummary(
        string $module,
        string $entityType,
        ?int $entityId,
        array $options
    ): ?array {
        // Define safe fields per entity type
        $safeFields = $this->getSafeFields($entityType);

        if (empty($safeFields)) {
            return null;
        }

        $tableName = $this->resolveTableName($entityType);
        if (! $tableName) {
            return null;
        }

        try {
            $query = DB::table($tableName)
                ->where('tenant_id', tenant()->id)
                ->select($safeFields);

            if ($entityId) {
                $query->where('id', $entityId);
            }

            if (! empty($options['limit'])) {
                $query->limit($options['limit']);
            }

            return $query->get()->toArray();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get safe (non-restricted) fields for each entity type.
     */
    private function getSafeFields(string $entityType): array
    {
        $fields = [
            'contact' => ['id', 'name', 'code', 'type', 'email', 'phone', 'category', 'status', 'created_at'],
            'product' => ['id', 'name', 'sku', 'type', 'category', 'unit', 'price', 'cost', 'status', 'created_at'],
            'invoice' => ['id', 'invoice_number', 'customer_id', 'date', 'due_date', 'status', 'total', 'currency', 'created_at'],
            'bill' => ['id', 'bill_number', 'vendor_id', 'date', 'due_date', 'status', 'total', 'currency', 'created_at'],
            'payment' => ['id', 'payment_number', 'type', 'date', 'amount', 'currency', 'status', 'created_at'],
            'journal_entry' => ['id', 'entry_number', 'date', 'description', 'status', 'total_debit', 'total_credit', 'created_at'],
            'account' => ['id', 'code', 'name', 'type', 'sub_type', 'status', 'created_at'],
            'sales_order' => ['id', 'order_number', 'customer_id', 'date', 'status', 'total', 'created_at'],
            'quotation' => ['id', 'quotation_number', 'customer_id', 'date', 'status', 'total', 'created_at'],
            'delivery_order' => ['id', 'delivery_number', 'sales_order_id', 'date', 'status', 'created_at'],
        ];

        return $fields[$entityType] ?? [];
    }

    /**
     * Filter context to remove restricted fields.
     */
    private function filterContext(array $context): array
    {
        if (is_array($context) && ! empty($context) && isset($context[0])) {
            // Array of records
            return array_map(fn ($record) => $this->rawAccessGuard->filterRestrictedFields((array) $record), $context);
        }

        // Single record
        return $this->rawAccessGuard->filterRestrictedFields($context);
    }

    /**
     * Get names of fields that were filtered out.
     */
    private function getFilteredFieldNames(array $original, array $filtered): array
    {
        $originalKeys = is_array($original) && ! empty($original) && isset($original[0])
            ? array_keys((array) $original[0])
            : array_keys($original);

        $filteredKeys = is_array($filtered) && ! empty($filtered) && isset($filtered[0])
            ? array_keys((array) $filtered[0])
            : array_keys($filtered);

        return array_diff($originalKeys, $filteredKeys);
    }

    /**
     * Build human-readable summary text.
     */
    private function buildSummaryText(string $entityType, array $context): array
    {
        if (empty($context)) {
            return ['text' => "No data found for {$entityType}."];
        }

        $record = is_array($context) && isset($context[0]) ? $context[0] : $context;

        $summary = "Entity: {$entityType}";
        if (isset($record['id'])) {
            $summary .= " (ID: {$record['id']})";
        }
        if (isset($record['name'])) {
            $summary .= " - {$record['name']}";
        }
        if (isset($record['status'])) {
            $summary .= " [{$record['status']}]";
        }

        return [
            'text' => $summary,
            'fields' => $record,
        ];
    }
}
