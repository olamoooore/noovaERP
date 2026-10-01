<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Warehouse;
use Modules\Inventory\Models\InventorySettings;
use Modules\Inventory\Services\InventoryService;

/**
 * D7 (Oct-2026 invoice review): the pre-flight checklist behind the badge on
 * Post. Composes the checks that already exist across services — approval,
 * evidence, period lock, credit exposure (AgedReceivablesService), stock —
 * plus two data-quality checks (possible duplicate, customer TIN) that only
 * surface here, and reports pass/warn/fail without blocking anything by
 * itself (posting keeps its own hard gates).
 */
class InvoicePreflightService
{
    public function __construct(
        private InvoiceApprovalService $approvalService,
        private InvoiceEvidenceService $evidenceService,
        private AgedReceivablesService $agedReceivables,
        private LockDateValidationService $lockDateValidator,
    ) {}

    public function run(Invoice $invoice): array
    {
        $productLines = $invoice->lines
            ->filter(fn ($line) => ($line->line_type ?? 'product') === 'product');

        $checks = [
            $productLines->isNotEmpty()
                ? $this->pass('lines', 'Has line items', $productLines->count().' line(s) to post.')
                : $this->fail('lines', 'Has line items', 'The invoice has no line items.'),

            $this->approvalCheck($invoice),
            $this->evidenceCheck($invoice),
            $this->periodCheck($invoice),
            $this->creditCheck($invoice),
            $this->duplicateCheck($invoice),
            $this->tinCheck($invoice),
            $this->stockCheck($invoice, $productLines),
        ];

        $passed = count(array_filter($checks, fn ($check) => $check['status'] === 'pass'));
        $failed = count(array_filter($checks, fn ($check) => $check['status'] === 'fail'));

        return [
            'ready' => $failed === 0,
            'passed' => $passed,
            'total' => count($checks),
            'checks' => $checks,
        ];
    }

    private function approvalCheck(Invoice $invoice): array
    {
        $guard = $this->approvalService->checkGuard($invoice);

        if ($guard['blocked']) {
            return $this->fail('approval', 'Approval', $guard['message'] ?? 'Approval is required before posting.');
        }

        return $this->pass(
            'approval',
            'Approval',
            $invoice->requires_approval ? 'Approved.' : 'No approval required.'
        );
    }

    private function evidenceCheck(Invoice $invoice): array
    {
        $guard = $this->evidenceService->checkGuard($invoice);

        if ($guard['blocked']) {
            return $this->fail('evidence', 'Evidence', $guard['message'] ?? 'Evidence is required before posting.');
        }

        return $this->pass('evidence', 'Evidence', 'No outstanding evidence requirement.');
    }

    private function periodCheck(Invoice $invoice): array
    {
        $result = $this->lockDateValidator->validateTransactionDate('sales', $invoice->invoice_date);

        if ($result['is_locked']) {
            return $this->fail('period', 'Accounting period', $result['message'] ?? 'The accounting period is locked.');
        }

        return $this->pass(
            'period',
            'Accounting period',
            'Open for '.($invoice->invoice_date ? $invoice->invoice_date->format('d M Y') : 'this date').'.'
        );
    }

    private function creditCheck(Invoice $invoice): array
    {
        $customer = $invoice->customer;

        if (! $customer) {
            return $this->warn('credit', 'Credit limit', 'No customer on this invoice.');
        }

        $context = $this->agedReceivables->getCustomerContext((int) $customer->id);
        $limit = $context['credit_limit'];

        if ($limit === null) {
            return $this->pass('credit', 'Credit limit', 'No credit limit set for this customer.');
        }

        $exposure = (float) $context['outstanding'];
        if ($invoice->status === 'draft') {
            $exposure += (float) $invoice->total;
        }

        if ($exposure > $limit) {
            return $this->warn('credit', 'Credit limit', sprintf(
                'Exposure would reach %s of the %s limit (%s over).',
                number_format($exposure, 2),
                number_format($limit, 2),
                number_format($exposure - $limit, 2),
            ));
        }

        return $this->pass('credit', 'Credit limit', sprintf(
            'Exposure %s of %s limit.',
            number_format($exposure, 2),
            number_format($limit, 2),
        ));
    }

    private function duplicateCheck(Invoice $invoice): array
    {
        if (! $invoice->customer_id || (float) $invoice->total <= 0) {
            return $this->pass('duplicate', 'Possible duplicate', 'Nothing to compare.');
        }

        $base = $invoice->invoice_date ? $invoice->invoice_date->copy() : now();

        $duplicate = Invoice::query()
            ->where('customer_id', $invoice->customer_id)
            ->whereKeyNot($invoice->id)
            ->whereIn('status', ['draft', 'posted', 'sent', 'partial', 'overdue', 'paid'])
            ->whereNotIn('lifecycle_status', ['superseded', 'voided', 'reversed'])
            ->whereBetween('invoice_date', [
                $base->copy()->subDays(7)->toDateString(),
                $base->copy()->addDays(7)->toDateString(),
            ])
            ->whereRaw('ABS(total - ?) <= GREATEST(0.01, ABS(total) * 0.005)', [(float) $invoice->total])
            ->orderByDesc('invoice_date')
            ->first();

        if (! $duplicate) {
            return $this->pass('duplicate', 'Possible duplicate', 'No similar invoice within 7 days.');
        }

        return $this->warn('duplicate', 'Possible duplicate', sprintf(
            '%s already exists for this customer (%s, %s) — check before posting.',
            $duplicate->invoice_number,
            $duplicate->invoice_date ? $duplicate->invoice_date->format('d M Y') : 'no date',
            number_format((float) $duplicate->total, 2),
        ));
    }

    private function tinCheck(Invoice $invoice): array
    {
        $tin = trim((string) ($invoice->tax_registration_number ?? '')) ?: trim((string) ($invoice->customer?->tax_number ?? ''));

        return $tin !== ''
            ? $this->pass('e_invoice', 'Customer TIN', 'TIN on file: '.$tin)
            : $this->warn('e_invoice', 'Customer TIN', 'Customer TIN is missing — required for e-invoice (MyInvois).');
    }

    private function stockCheck(Invoice $invoice, $productLines): array
    {
        $tracked = $productLines->filter(fn ($line) => $line->product && $line->product->track_inventory);

        if ($tracked->isEmpty()) {
            return $this->pass('stock', 'Stock availability', 'No tracked products on this invoice.');
        }

        $warehouseId = $invoice->salesOrder?->warehouse_id ?? Warehouse::getDefault()?->id;

        if (! $warehouseId) {
            return $this->warn('stock', 'Stock availability', 'No warehouse context — stock cannot be checked.');
        }

        $inventory = app(InventoryService::class);
        $shortages = [];

        foreach ($tracked as $line) {
            $result = $inventory->checkAvailability((int) $line->product_id, (float) $line->quantity, (int) $warehouseId);

            if (! ($result['is_available'] ?? true)) {
                $shortages[] = sprintf('%s: short by %s', $line->product->name, number_format((float) ($result['shortage'] ?? 0), 2));
            }
        }

        if ($shortages === []) {
            return $this->pass('stock', 'Stock availability', 'All tracked lines are available.');
        }

        $allowNegative = (bool) InventorySettings::current()->allow_negative_stock;

        return $allowNegative
            ? $this->warn('stock', 'Stock availability', implode('; ', $shortages).' (negative stock allowed).')
            : $this->fail('stock', 'Stock availability', implode('; ', $shortages).'.');
    }

    private function pass(string $key, string $label, string $detail): array
    {
        return compact('key', 'label', 'detail') + ['status' => 'pass'];
    }

    private function warn(string $key, string $label, string $detail): array
    {
        return compact('key', 'label', 'detail') + ['status' => 'warn'];
    }

    private function fail(string $key, string $label, string $detail): array
    {
        return compact('key', 'label', 'detail') + ['status' => 'fail'];
    }
}
