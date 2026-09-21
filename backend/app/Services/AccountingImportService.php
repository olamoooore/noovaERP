<?php

namespace App\Services;

use App\Models\Accounting\AccountingImportAudit;
use App\Models\Accounting\AccountingImportBatch;
use App\Models\Accounting\AccountingImportStaging;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AccountingImportService
{
    /**
     * Create a new import batch and stage rows for validation.
     */
    public function stageBatch(
        array $rows,
        string $importType,
        string $fileName,
        string $fileHash,
        int $userId,
        ?array $mapping = null
    ): AccountingImportBatch {
        return DB::transaction(function () use ($rows, $importType, $fileName, $fileHash, $userId, $mapping) {
            $batch = AccountingImportBatch::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => tenant()->id,
                'user_id' => $userId,
                'import_type' => $importType,
                'file_name' => $fileName,
                'file_hash' => $fileHash,
                'total_rows' => count($rows),
                'status' => 'pending',
                'mapping_config' => $mapping,
            ]);

            $counts = ['valid' => 0, 'warning' => 0, 'error' => 0];

            foreach ($rows as $index => $row) {
                $mapped = $this->mapRow($row, $mapping);
                $errors = $this->validateRow($mapped, $importType);

                $status = empty($errors) ? 'valid' : 'error';
                if (! empty($errors)) {
                    $counts['error']++;
                } else {
                    $counts['valid']++;
                }

                AccountingImportStaging::create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => tenant()->id,
                    'batch_id' => $batch->id,
                    'row_number' => $index + 1,
                    'raw_data' => $row,
                    'mapped_data' => $mapped,
                    'validation_errors' => ! empty($errors) ? $errors : null,
                    'status' => $status,
                    'source_system' => 'accounting_import',
                    'source_payload_hash' => hash('sha256', json_encode($row)),
                ]);
            }

            $batch->update([
                'valid_rows' => $counts['valid'],
                'error_rows' => $counts['error'],
                'status' => $counts['error'] === 0 ? 'validated' : 'pending',
                'validated_at' => $counts['error'] === 0 ? now() : null,
            ]);

            $this->logAudit($batch->id, null, 'staged', [
                'total_rows' => count($rows),
                'valid' => $counts['valid'],
                'error' => $counts['error'],
            ], $userId);

            return $batch;
        });
    }

    /**
     * Commit a validated batch - create actual accounting records.
     */
    public function commitBatch(string $batchId, int $userId): array
    {
        $batch = AccountingImportBatch::where('tenant_id', tenant()->id)
            ->findOrFail($batchId);

        if ($batch->status === 'completed') {
            throw new \DomainException('IMPORT_BATCH_ALREADY_COMPLETED');
        }

        if ($batch->status !== 'validated') {
            throw new \DomainException('IMPORT_BATCH_NOT_VALIDATED');
        }

        return DB::transaction(function () use ($batch, $userId) {
            $batch->markImporting();

            $stagingRows = AccountingImportStaging::where('batch_id', $batch->id)
                ->whereIn('status', ['valid', 'warning'])
                ->orderBy('row_number')
                ->get();

            $imported = 0;
            $rejected = 0;

            foreach ($stagingRows as $staging) {
                try {
                    $entity = $this->createEntity($batch->import_type, $staging->mapped_data, $userId);
                    $staging->markImported(get_class($entity), $entity->id);
                    $imported++;
                } catch (Throwable $e) {
                    Log::warning('Import row rejected', [
                        'batch_id' => $batch->id,
                        'row_number' => $staging->row_number,
                        'import_type' => $batch->import_type,
                        'error' => $e->getMessage(),
                    ]);
                    $staging->markError(['import_error' => $e->getMessage()]);
                    $rejected++;
                }
            }

            $batch->markCompleted($imported);

            $this->logAudit($batch->id, null, 'committed', [
                'imported' => $imported,
                'rejected' => $rejected,
            ], $userId);

            return [
                'batch_id' => $batch->id,
                'imported' => $imported,
                'rejected' => $rejected,
            ];
        });
    }

    /**
     * Get batch status and statistics.
     */
    public function getBatchStatus(string $batchId): AccountingImportBatch
    {
        return AccountingImportBatch::where('tenant_id', tenant()->id)
            ->with(['stagingRows' => function ($query) {
                $query->select('batch_id', 'status', DB::raw('count(*) as count'))
                    ->groupBy('batch_id', 'status');
            }])
            ->findOrFail($batchId);
    }

    /**
     * Map raw row data to canonical format.
     */
    private function mapRow(array $row, ?array $mapping): array
    {
        if ($mapping) {
            $mapped = [];
            foreach ($mapping as $target => $sourceColumn) {
                $mapped[$target] = $row[$sourceColumn] ?? null;
            }

            return $mapped;
        }

        return $row;
    }

    /**
     * Validate a mapped row based on import type.
     *
     * Field names here match the canonical Bill/Invoice/Payment model columns
     * per #51: subtotal/total (not total_amount/net_amount), customer/vendor
     * names, currency, etc.
     */
    private function validateRow(array $mapped, string $importType): array
    {
        $errors = [];

        switch ($importType) {
            case 'bill':
                if (empty($mapped['vendor_name']) && empty($mapped['vendor_id'])) {
                    $errors[] = 'vendor_name or vendor_id is required';
                }
                if (empty($mapped['bill_number'])) {
                    $errors[] = 'bill_number is required';
                }
                $total = $mapped['total'] ?? $mapped['total_amount'] ?? null;
                if (empty($total) || $total <= 0) {
                    $errors[] = 'total (or total_amount) must be greater than 0';
                }
                break;

            case 'invoice':
                if (empty($mapped['customer_name']) && empty($mapped['customer_id'])) {
                    $errors[] = 'customer_name or customer_id is required';
                }
                if (empty($mapped['invoice_number'])) {
                    $errors[] = 'invoice_number is required';
                }
                $total = $mapped['total'] ?? $mapped['total_amount'] ?? null;
                if (empty($total) || $total <= 0) {
                    $errors[] = 'total (or total_amount) must be greater than 0';
                }
                break;

            case 'journal_entry':
                if (empty($mapped['lines']) || ! is_array($mapped['lines'])) {
                    $errors[] = 'lines array is required';
                } else {
                    $totalDebit = 0;
                    $totalCredit = 0;
                    foreach ($mapped['lines'] as $line) {
                        $totalDebit += $line['debit'] ?? 0;
                        $totalCredit += $line['credit'] ?? 0;
                    }
                    if (abs($totalDebit - $totalCredit) > 0.01) {
                        $errors[] = 'Debit and credit totals must balance';
                    }
                }
                break;

            case 'payment':
                if (empty($mapped['amount']) || $mapped['amount'] <= 0) {
                    $errors[] = 'amount must be greater than 0';
                }
                if (empty($mapped['method']) && empty($mapped['payment_method'])) {
                    $errors[] = 'method (or payment_method) is required';
                }
                break;
        }

        return $errors;
    }

    /**
     * Create an accounting entity from staged data via the owning domain service.
     * This signature uses an actual `Model` instance as the return type, so any
     * production failure surfaces as a TypeError caught by the row handler.
     */
    private function createEntity(string $importType, array $data, int $userId): Model
    {
        return match ($importType) {
            'bill' => $this->createBill($data, $userId),
            'invoice' => $this->createInvoice($data, $userId),
            'journal_entry' => $this->createJournalEntry($data),
            'payment' => $this->createPayment($data, $userId),
            default => throw new \DomainException("Unknown import type: {$importType}"),
        };
    }

    private function createBill(array $data, int $userId): \App\Models\Bill
    {
        // Resolve vendor: by id if present, otherwise look up by name.
        $vendorId = $data['vendor_id'] ?? null;
        if (! $vendorId && ! empty($data['vendor_name'])) {
            $vendor = \App\Models\Vendor::firstOrCreate(
                ['name' => $data['vendor_name']],
                ['name' => $data['vendor_name']]
            );
            $vendorId = $vendor->id;
        }

        if (! $vendorId) {
            throw new \DomainException('Cannot resolve vendor for bill import row');
        }

        $subtotal = $data['subtotal']
            ?? ($data['total'] ?? $data['total_amount'] ?? 0)
                - ($data['tax_amount'] ?? 0);
        $taxAmount = $data['tax_amount'] ?? 0;
        $total = $data['total'] ?? $data['total_amount'] ?? ($subtotal + $taxAmount);

        // Route through BillService (owner domain service) when available.
        if (app()->bound(\App\Services\BillService::class)) {
            return app(\App\Services\BillService::class)->createBill([
                'vendor_id' => $vendorId,
                'bill_number' => $data['bill_number'],
                'bill_date' => $data['bill_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'currency_code' => $data['currency_code'] ?? null,
                'vendor_name' => $data['vendor_name'] ?? null,
            ], $userId);
        }

        // Fallback: direct model creation (still typed correctly so failures
        // surface as TypeError, not as a 500 with a null model).
        return \App\Models\Bill::create([
            'vendor_id' => $vendorId,
            'bill_number' => $data['bill_number'],
            'bill_date' => $data['bill_date'] ?? now()->toDateString(),
            'due_date' => $data['due_date'] ?? null,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'status' => 'draft',
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ]);
    }

    private function createInvoice(array $data, int $userId): \App\Models\Invoice
    {
        $customerId = $data['customer_id'] ?? null;
        if (! $customerId && ! empty($data['customer_name'])) {
            // #51 + CI architecture guard: never bypass CanonicalCustomerService
            // in production code paths. The canonical service creates the
            // customer + Contact Master link in one transaction.
            $customer = app(CanonicalCustomerService::class)->getOrCreateByName($data['customer_name']);
            $customerId = $customer->id;
        }

        if (! $customerId) {
            throw new \DomainException('Cannot resolve customer for invoice import row');
        }

        $subtotal = $data['subtotal']
            ?? ($data['total'] ?? $data['total_amount'] ?? 0)
                - ($data['tax_amount'] ?? 0);
        $taxAmount = $data['tax_amount'] ?? 0;
        $total = $data['total'] ?? $data['total_amount'] ?? ($subtotal + $taxAmount);

        $invoiceDate = $data['invoice_date'] ?? now()->toDateString();

        return \App\Models\Invoice::create([
            'customer_id' => $customerId,
            'invoice_number' => $data['invoice_number'],
            'invoice_date' => $invoiceDate,
            'due_date' => $data['due_date'] ?? $invoiceDate,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'status' => 'draft',
            'notes' => $data['notes'] ?? null,
            'currency_code' => $data['currency_code'] ?? null,
            'customer_name' => $data['customer_name'] ?? null,
            'created_by' => $userId,
        ]);
    }

    /**
     * Create a journal entry from an import row.
     *
     * Issue noovaerp/NoovaERP#65 audit (2026-09-04): this direct
     * JournalEntry::create() does NOT bypass PostingEngine's gate
     * pipeline. The import path only ever produces status='draft' rows
     * (never 'posted'); the sole transition from draft to posted for
     * manual entries is JournalEntriesController::post() ->
     * ManualJournalPostingService::post(), which evaluates the full
     * ActionEligibilityEvaluator gate pipeline (TaxGate,
     * AssuranceGate, ControlAccountGate, PermissionGate, …) before the
     * status transition and fails-closed on any BLOCK. Posted ledger
     * rows therefore still originate only from gate-checked paths;
     * creating draft bookkeeping rows is not a ledger write.
     */
    private function createJournalEntry(array $data): \App\Models\JournalEntry
    {
        $entry = \App\Models\JournalEntry::create([
            'entry_number' => $data['entry_number'] ?? 'JE-'.Str::random(8),
            'entry_date' => $data['entry_date'] ?? now()->toDateString(),
            'description' => $data['description'] ?? 'Imported journal entry',
            'status' => 'draft',
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
        ]);

        foreach ($data['lines'] as $line) {
            $entry->lines()->create([
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? null,
                'debit' => $line['debit'] ?? 0,
                'credit' => $line['credit'] ?? 0,
            ]);
        }

        return $entry;
    }

    private function createPayment(array $data, int $userId): \App\Models\Payment
    {
        $method = $data['method'] ?? $data['payment_method'] ?? null;
        if (! $method) {
            throw new \DomainException('Payment method is required');
        }

        $allowedMethods = ['bank_transfer', 'card', 'check', 'cash', 'gateway'];
        if (! in_array($method, $allowedMethods, true)) {
            throw new \DomainException("Unsupported payment method: '{$method}'");
        }

        return \App\Models\Payment::create([
            'payment_number' => $data['payment_number'] ?? 'PAY-'.Str::random(8),
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? null,
            'method' => $method,
            'payment_date' => $data['payment_date'] ?? now()->toDateString(),
            'payment_status' => 'recorded',
            'notes' => $data['notes'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'received_by' => $userId,
        ]);
    }

    /**
     * Log an import audit event.
     */
    private function logAudit(?string $batchId, ?string $stagingId, string $operation, array $details, int $userId): void
    {
        AccountingImportAudit::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => tenant()->id,
            'batch_id' => $batchId,
            'staging_id' => $stagingId,
            'operation' => $operation,
            'details' => $details,
            'user_id' => $userId,
            'created_at' => now(),
        ]);
    }
}
