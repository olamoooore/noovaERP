<?php

namespace App\Platform\BulkDelete;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Bulk Delete Service
 *
 * Reusable bulk delete across all modules.
 * Handles soft deletes, FK validation, audit logging.
 */
class BulkDeleteService
{
    /**
     * Bulk delete records by IDs.
     *
     * @param string $table The table to delete from
     * @param array $ids Array of record IDs to delete
     * @param string|null $tenantId Tenant ID for tenant-scoped tables
     * @param array $options Additional options
     * @return array{deleted: int, errors: array}
     */
    public function delete(string $table, array $ids, ?string $tenantId = null, array $options = []): array
    {
        if (empty($ids)) {
            return ['deleted' => 0, 'errors' => [['message' => 'No IDs provided']]];
        }

        $softDeletes = $options['soft_deletes'] ?? true;
        $auditLog = $options['audit_log'] ?? true;
        $userId = $options['user_id'] ?? null;
        $errors = [];

        try {
            $query = DB::table($table)->whereIn('id', $ids);

            if ($tenantId && $this->hasColumn($table, 'tenant_id')) {
                $query->where('tenant_id', $tenantId);
            }

            $found = $query->count();
            $notFound = count($ids) - $found;

            if ($notFound > 0) {
                $errors[] = ['message' => "{$notFound} record(s) not found or access denied"];
            }

            // Check for FK dependencies if configured
            $fkCheck = $options['check_fk'] ?? true;
            if ($fkCheck) {
                $blocked = $this->checkForeignKeyDependencies($table, $ids, $tenantId);
                if (!empty($blocked)) {
                    return [
                        'deleted' => 0,
                        'errors' => array_map(
                            fn($b) => ['message' => "Cannot delete: referenced by {$b['table']}.{$b['column']} ({$b['count']} reference(s))"],
                            $blocked
                        ),
                    ];
                }
            }

            // Perform delete
            $query = DB::table($table)->whereIn('id', $ids);
            if ($tenantId && $this->hasColumn($table, 'tenant_id')) {
                $query->where('tenant_id', $tenantId);
            }

            if ($softDeletes && $this->hasColumn($table, 'deleted_at')) {
                $deleted = $query->update(['deleted_at' => now()]);
            } else {
                $deleted = $query->delete();
            }

            // Audit log
            if ($auditLog && $deleted > 0) {
                $this->logBulkDelete($table, $ids, $deleted, $userId, $tenantId);
            }

            return ['deleted' => $deleted, 'errors' => $errors];
        } catch (\Exception $e) {
            Log::error('BULK_DELETE_ERROR', [
                'table' => $table,
                'ids' => $ids,
                'error' => $e->getMessage(),
            ]);
            return ['deleted' => 0, 'errors' => [['message' => 'Delete failed: ' . $e->getMessage()]]];
        }
    }

    /**
     * Check for foreign key dependencies before delete.
     */
    private function checkForeignKeyDependencies(string $table, array $ids, ?string $tenantId): array
    {
        $blocked = [];

        // Common FK relationships
        $fkMap = [
            'customers' => [
                ['table' => 'invoices', 'column' => 'customer_id'],
                ['table' => 'quotations', 'column' => 'customer_id'],
                ['table' => 'sales_orders', 'column' => 'customer_id'],
                ['table' => 'delivery_orders', 'column' => 'customer_id'],
                ['table' => 'bills', 'column' => 'vendor_id'],
            ],
            'products' => [
                ['table' => 'invoice_lines', 'column' => 'product_id'],
                ['table' => 'quotation_lines', 'column' => 'product_id'],
                ['table' => 'sales_order_lines', 'column' => 'product_id'],
                ['table' => 'delivery_order_lines', 'column' => 'product_id'],
                ['table' => 'stock_moves', 'column' => 'product_id'],
            ],
            'sales_orders' => [
                ['table' => 'sales_order_lines', 'column' => 'sales_order_id'],
                ['table' => 'delivery_orders', 'column' => 'sales_order_id'],
                ['table' => 'quotations', 'column' => 'sales_order_id'],
            ],
            'accounts' => [
                ['table' => 'journal_entry_lines', 'column' => 'account_id'],
                ['table' => 'invoices', 'column' => 'account_id'],
                ['table' => 'bills', 'column' => 'account_id'],
            ],
        ];

        $dependencies = $fkMap[$table] ?? [];

        foreach ($dependencies as $fk) {
            $count = DB::table($fk['table'])
                ->whereIn($fk['column'], $ids)
                ->when($tenantId && $this->hasColumn($fk['table'], 'tenant_id'), fn($q) => $q->where('tenant_id', $tenantId))
                ->count();

            if ($count > 0) {
                $blocked[] = [
                    'table' => $fk['table'],
                    'column' => $fk['column'],
                    'count' => $count,
                ];
            }
        }

        return $blocked;
    }

    /**
     * Log bulk delete action for audit trail.
     */
    private function logBulkDelete(string $table, array $ids, int $deleted, ?string $userId, ?string $tenantId): void
    {
        try {
            DB::table('audit_log')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'action' => 'bulk_delete',
                'entity_type' => $table,
                'entity_id' => null,
                'old_values' => json_encode(['ids' => $ids, 'count' => $deleted]),
                'new_values' => null,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Audit log failure is non-critical
        }
    }

    /**
     * Check if a column exists in a table.
     */
    private function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (\Exception $e) {
            return false;
        }
    }
}
