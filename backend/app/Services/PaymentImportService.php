<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PaymentImportService
{
    private const COLUMN_ALIASES = [
        'payment_no' => 'payment_number',
        'payment_number' => 'payment_number',
        'payment_no.' => 'payment_number',
        'receipt_no' => 'payment_number',
        'receipt_no.' => 'payment_number',
        'receipt_number' => 'payment_number',
        'voucher_no' => 'payment_number',
        'voucher_no.' => 'payment_number',
        'voucher_number' => 'payment_number',
        'customer_id' => 'customer_id',
        'customer_code' => 'customer_code',
        'customer_name' => 'customer_name',
        'customer' => 'customer_name',
        'invoice_no' => 'invoice_number',
        'invoice_number' => 'invoice_number',
        'invoice_no.' => 'invoice_number',
        'invoice' => 'invoice_number',
        'payment_date' => 'payment_date',
        'date' => 'payment_date',
        'paid_date' => 'payment_date',
        'received_date' => 'payment_date',
        'amount' => 'amount',
        'payment_amount' => 'amount',
        'amount_paid' => 'amount',
        'payment_method' => 'method',
        'method' => 'method',
        'reference' => 'reference',
        'ref' => 'reference',
        'transaction_ref' => 'reference',
        'txn_ref' => 'reference',
        'bank_ref' => 'reference',
        'bank_reference' => 'reference',
        'cheque_no' => 'reference',
        'cheque_no.' => 'reference',
        'cheque_number' => 'reference',
        'check_no' => 'reference',
        'check_no.' => 'reference',
        'check_number' => 'reference',
        'currency' => 'currency',
        'notes' => 'notes',
        'note' => 'notes',
        'remarks' => 'notes',
    ];

    /**
     * Import payments from a CSV file.
     *
     * @return array{created: int, errors: array[], warnings: array[]}
     */
    public function importFromFile(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new \RuntimeException('Unable to read file');
        }

        $rawHeader = fgetcsv($handle);
        if (! is_array($rawHeader) || count($rawHeader) === 0) {
            fclose($handle);
            throw new \RuntimeException('CSV header missing');
        }

        $header = array_map(function ($h) {
            $h = strtolower(trim((string) $h));
            $h = preg_replace('/[^a-z0-9]+/', '_', $h);

            return trim($h, '_');
        }, $rawHeader);

        $header = array_map(fn ($key) => self::COLUMN_ALIASES[$key] ?? $key, $header);

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (! is_array($data) || count(array_filter($data, fn ($v) => (string) $v !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($header as $idx => $key) {
                if ($key !== '' && isset($data[$idx])) {
                    $val = $data[$idx];
                    if (! mb_check_encoding($val, 'UTF-8')) {
                        $val = mb_convert_encoding($val, 'UTF-8', 'UTF-8');
                    }
                    $row[$key] = $val;
                }
            }
            if (empty($row)) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        if (count($rows) === 0) {
            throw new \RuntimeException('No rows found');
        }

        $created = 0;
        $errors = [];
        $warnings = [];
        $customerCache = [];

        foreach ($rows as $idx => $row) {
            $rowNum = $idx + 2;
            try {
                $this->ensureTenantSearchPath();

                $rowErrors = $this->validateRow($row, $rowNum);
                if (! empty($rowErrors)) {
                    $errors = array_merge($errors, $rowErrors);

                    continue;
                }

                $customer = $this->resolveCustomer($row, $customerCache);
                if (! $customer) {
                    $parts = [];
                    foreach (['customer_id', 'customer_code', 'customer_name'] as $key) {
                        if (! empty($row[$key])) {
                            $parts[] = "{$key}={$row[$key]}";
                        }
                    }
                    $errors[] = ['row' => $rowNum, 'message' => 'Customer not found'.($parts ? ' ('.implode(', ', $parts).')' : '')];

                    continue;
                }

                // Resolve invoice strictly: when an invoice_number is supplied it must exist.
                // An empty invoice_number keeps the row as a standalone payment (§4).
                $invoice = $this->resolveInvoice($row, $rowNum, $errors);
                if ($errors) {
                    // resolveInvoice pushed an error for a missing invoice — abort this row.
                    continue;
                }

                $paymentNumber = $this->resolvePaymentNumber($row, $rowNum, $errors);
                if ($paymentNumber === null) {
                    continue;
                }

                $amount = $this->parseNumericValue($row['amount']);
                if ($invoice && $amount > (float) $invoice->balance) {
                    $errors[] = ['row' => $rowNum, 'message' => 'Payment amount ('.$amount.') exceeds invoice balance ('.((float) $invoice->balance).') for invoice '.$invoice->invoice_number];

                    continue;
                }

                $paymentDate = $this->parseDate($row['payment_date'] ?? null) ?? now()->toDateString();

                $duplicate = $this->checkDuplicate([
                    'reference' => $row['reference'] ?? null,
                    'payment_date' => $paymentDate,
                    'amount' => $amount,
                ], $customer->id, $invoice?->id);
                if ($duplicate) {
                    $warnings[] = ['row' => $rowNum, 'message' => 'Skipped: duplicate payment (matches '.$duplicate->payment_number.')'];

                    continue;
                }

                $payment = $this->createPayment([
                    'payment_number' => $paymentNumber,
                    'customer_id' => $customer->id,
                    'invoice_id' => $invoice?->id,
                    'payment_date' => $paymentDate,
                    'amount' => $amount,
                    'method' => $this->resolveMethod($row['method'] ?? null),
                    'reference' => $row['reference'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'currency' => $row['currency'] ?? null,
                ]);

                if ($invoice) {
                    $this->allocateToInvoice($invoice, $amount);
                }

                $created++;
            } catch (\Throwable $e) {
                Log::warning('Payment import row failed', [
                    'row' => $rowNum,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = ['row' => $rowNum, 'message' => $e->getMessage()];
            }
        }

        return compact('created', 'errors', 'warnings');
    }

    private function ensureTenantSearchPath(): void
    {
        try {
            $schema = tenant()->getSchemaName();
            if ($schema) {
                DB::statement("SET search_path TO \"{$schema}\", public");
            }
        } catch (\Throwable $e) {
            // Outside tenancy (tests / console without tenant binding) — keep going.
        }
    }

    /**
     * Validate a single import row.
     */
    private function validateRow(array $row, int $rowNum): array
    {
        $errors = [];

        $amount = $this->parseNumericValue($row['amount'] ?? null);
        if ($amount <= 0) {
            $errors[] = ['row' => $rowNum, 'message' => 'Amount must be greater than zero'];
        }

        $paymentDate = $row['payment_date'] ?? null;
        if ($paymentDate !== null && $paymentDate !== '' && $this->parseDate($paymentDate) === null) {
            $errors[] = ['row' => $rowNum, 'message' => 'Invalid payment date: '.$paymentDate];
        }

        $hasCustomer = ! empty($row['customer_id']) || ! empty($row['customer_code']) || ! empty($row['customer_name']);
        if (! $hasCustomer) {
            $errors[] = ['row' => $rowNum, 'message' => 'Customer is required (customer_id, customer_code, or customer_name)'];
        }

        return $errors;
    }

    /**
     * Resolve customer from row data.
     */
    private function resolveCustomer(array $row, array &$cache): ?Customer
    {
        $code = trim((string) ($row['customer_code'] ?? ''));
        $name = trim((string) ($row['customer_name'] ?? ''));
        $id = trim((string) ($row['customer_id'] ?? ''));

        $cacheKey = $code.'|'.$name.'|'.$id;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $customer = null;
        if ($id !== '' && is_numeric($id)) {
            $customer = Customer::find((int) $id);
        }
        if (! $customer && $code !== '') {
            $customer = Customer::where('code', $code)->first();
        }
        if (! $customer && $name !== '') {
            $customer = Customer::where('name', 'ILIKE', $name)->first();
        }

        $cache[$cacheKey] = $customer;

        return $customer;
    }

    /**
     * Resolve invoice from row data.
     *
     * Empty invoice_number → null (standalone payment, §4 / §5 of the spec).
     * Non-empty invoice_number → must exist; otherwise we push an error and
     * the caller bails on the row.
     */
    private function resolveInvoice(array $row, int $rowNum, array &$errors): ?Invoice
    {
        $invoiceNumber = trim((string) ($row['invoice_number'] ?? ''));
        if ($invoiceNumber === '') {
            return null;
        }

        $invoice = Invoice::where('invoice_number', $invoiceNumber)->first();
        if (! $invoice) {
            $errors[] = ['row' => $rowNum, 'message' => "Invoice not found: {$invoiceNumber}"];

            return null;
        }

        return $invoice;
    }

    /**
     * Resolve or generate payment number.
     * Returns null if the imported number collides with a LIVE payment
     * (errors[] is populated). Numbers belonging to soft-deleted payments
     * are reusable — clients routinely delete draft rows and re-import.
     */
    private function resolvePaymentNumber(array $row, int $rowNum, array &$errors): ?string
    {
        $imported = trim((string) ($row['payment_number'] ?? ''));
        if ($imported === '') {
            return Payment::generatePaymentNumber();
        }

        $exists = Payment::where('payment_number', $imported)->exists();
        if ($exists) {
            $errors[] = ['row' => $rowNum, 'message' => 'Payment number already exists: '.$imported];

            return null;
        }

        return $imported;
    }

    /**
     * Check for duplicate payment.
     *
     * Per §11 we deduplicate on payment_number (handled in resolvePaymentNumber),
     * reference, invoice_number (FK), payment date, and amount. When the
     * reference is empty we still catch duplicates by invoice + date + amount
     * so that re-imports of the same file are silently skipped.
     *
     * Only LIVE (non-deleted) payments count as duplicates. Soft-deleted rows
     * are ignored so that clients who clean up bad imports can re-import the
     * same data without hitting "Skipped: duplicate payment" warnings.
     */
    private function checkDuplicate(array $row, int $customerId, ?int $invoiceId): ?Payment
    {
        $reference = trim((string) ($row['reference'] ?? ''));
        $paymentDate = $row['payment_date'] ?? null;
        $amount = (float) ($row['amount'] ?? 0);

        if ($amount <= 0 || $paymentDate === null) {
            return null;
        }

        $query = Payment::query()
            ->where('customer_id', $customerId)
            ->whereDate('payment_date', $paymentDate)
            ->where('amount', $amount);

        if ($invoiceId) {
            $query->where('invoice_id', $invoiceId);
        } else {
            $query->whereNull('invoice_id');
        }

        if ($reference !== '') {
            $query->where('reference', $reference);
        }

        return $query->first();
    }

    /**
     * Create a payment record.
     */
    private function createPayment(array $data): Payment
    {
        $userId = TenantUserHelper::resolveTenantUserId() ?? Auth::id();

        return Payment::create([
            'payment_number' => $data['payment_number'],
            'customer_id' => $data['customer_id'],
            'invoice_id' => $data['invoice_id'],
            'payment_date' => $data['payment_date'],
            'amount' => $data['amount'],
            'method' => $data['method'],
            'reference' => $data['reference'],
            'notes' => $data['notes'],
            'currency' => $data['currency'],
            'received_by' => $userId,
            'payment_status' => 'recorded',
        ]);
    }

    /**
     * Allocate payment amount to invoice and update invoice status.
     *
     * Status is derived from the canonical balance math so the "Partially Paid"
     * vs "Paid" values are always computed from the actual allocations, never
     * from manual field edits (§5).
     */
    private function allocateToInvoice(Invoice $invoice, float $amount): void
    {
        Invoice::where('id', $invoice->id)
            ->increment('paid_amount', $amount);

        $invoice->refresh();

        $newBalance = (float) $invoice->total - (float) $invoice->paid_amount;

        $newStatus = match (true) {
            $newBalance <= 0.005 => 'paid',
            (float) $invoice->paid_amount > 0.005 => 'partial',
            default => $invoice->status,
        };

        Invoice::where('id', $invoice->id)->update([
            'balance' => max(0, $newBalance),
            'status' => $newStatus,
            'settlement_status' => $newBalance <= 0.005 ? 'paid' : ((float) $invoice->paid_amount > 0.005 ? 'partially_paid' : 'unpaid'),
        ]);

        if ($invoice->customer) {
            $invoice->customer->updateOutstanding();
        }
    }

    /**
     * Resolve payment method from import value.
     */
    private function resolveMethod(?string $method): string
    {
        $method = strtolower(trim((string) $method));

        return match ($method) {
            'cash' => 'cash',
            'bank', 'bank_transfer', 'bank transfer', 'transfer' => 'bank_transfer',
            'card', 'credit card', 'debit card', 'credit/debit card' => 'card',
            'check', 'cheque' => 'check',
            default => 'other',
        };
    }

    /**
     * Parse a numeric value from import (handles commas, currency symbols, etc.).
     */
    private function parseNumericValue(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return (float) $cleaned;
    }

    /**
     * Parse a date value from import.
     */
    private function parseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim($value);

        // Try YYYY-MM-DD first
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $dt = \DateTime::createFromFormat('Y-m-d', $value);

            return $dt && $dt->format('Y-m-d') === $value ? $value : null;
        }

        // Try DD/MM/YYYY or M/D/YYYY
        foreach (['d/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y'] as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt && $dt->format($format) === $value) {
                return $dt->format('Y-m-d');
            }
        }

        // Try strtotime as last resort
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }
}
