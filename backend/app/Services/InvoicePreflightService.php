<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Warehouse;
use Modules\Inventory\Models\InventorySettings;
use Modules\Inventory\Services\InventoryService;
use Modules\Sales\Services\CreditCheckService;

/**
 * D7 (Oct-2026 invoice review): the pre-flight checklist behind the badge on
 * Post. Composes the checks that already exist across services — approval,
 * evidence, period lock, credit (CreditCheckService, #326), stock —
 * plus two data-quality checks (possible duplicate, customer TIN) that only
 * surface here, and reports pass/warn/fail without blocking anything by
 * itself (posting keeps its own hard gates).
 */
class InvoicePreflightService
{
    public function __construct(
        private InvoiceApprovalService $approvalService,
        private InvoiceEvidenceService $evidenceService,
        private LockDateValidationService $lockDateValidator,
        private CreditCheckService $creditCheckService,
        private InvoiceDuplicateCheck $duplicateCheck,
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

    /**
     * Issue #326: the same verdict invoice create/post enforce
     * (CreditCheckService, base-currency exposure) — not a separate rule.
     * A failed check fails the checklist under the `block` policy (posting
     * will be refused) and warns under `warn`.
     */
    private function creditCheck(Invoice $invoice): array
    {
        $result = $invoice->customer_id ? $this->creditCheckService->checkInvoice($invoice) : null;

        if ($result === null) {
            return $this->warn('credit', 'Credit limit', 'No customer on this invoice.');
        }

        $verdict = $this->creditCheckService->invoiceVerdict($result);

        if ($result->approved) {
            return $this->pass('credit', 'Credit limit', $result->availableCredit === null
                ? 'No credit limit set for this customer.'
                : sprintf(
                    'Exposure %s of %s limit.',
                    number_format($result->creditLimit - $result->availableCredit + $result->orderTotal, 2),
                    number_format($result->creditLimit, 2),
                ));
        }

        $detail = $result->reason.($verdict['blocked'] ? ' — posting is blocked by the credit policy.' : ' — warning only (credit policy: warn).');

        return $verdict['blocked']
            ? $this->fail('credit', 'Credit limit', $detail)
            : $this->warn('credit', 'Credit limit', $detail);
    }

    /**
     * Issue #327: the shared duplicate rule (InvoiceDuplicateCheck) that
     * invoice create also applies. Likely duplicates (same customer
     * reference / PO) fail unless confirmed at creation; possible ones (same
     * total within 7 days) warn.
     */
    private function duplicateCheck(Invoice $invoice): array
    {
        $result = $this->duplicateCheck->check($invoice);

        if ($result['level'] === InvoiceDuplicateCheck::LEVEL_NONE) {
            return $this->pass('duplicate', 'Possible duplicate', $invoice->customer_id
                ? 'No invoice with the same reference or PO, and no similar total within '.InvoiceDuplicateCheck::POSSIBLE_WINDOW_DAYS.' days.'
                : 'Nothing to compare.');
        }

        $matches = $this->duplicateCheck->describe($result);

        if ($result['level'] === InvoiceDuplicateCheck::LEVEL_LIKELY) {
            return $result['confirmed']
                ? $this->warn('duplicate', 'Likely duplicate', "Likely duplicate of {$matches} — confirmed as intended when the invoice was created.")
                : $this->fail('duplicate', 'Likely duplicate', "Likely duplicate of {$matches} — check before posting.");
        }

        return $this->warn('duplicate', 'Possible duplicate', "{$matches} already exists for this customer — check before posting.");
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
