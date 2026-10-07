<?php

namespace App\Services;

use App\Accounting\Services\ComplianceService;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
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
 *
 * Issue #344: a `fail` is a hard gate — InvoiceController refuses post (and
 * the legacy /send) with the failures from blockingFailures(); draft() builds
 * the unsaved invoice the create/edit form checks while it is being typed.
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

    /** Check keys whose `fail` is enforced by posting's own goods issue, not by the pre-flight gate. */
    public const ENFORCED_BY_POSTING = ['stock'];

    /**
     * @param  array{warehouse_id?: int|null}  $context  warehouse picked on the form (dry run)
     */
    public function run(Invoice $invoice, array $context = []): array
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
            $this->eInvoiceCheck($invoice),
            $this->stockCheck($invoice, $productLines, $context['warehouse_id'] ?? null),
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

    /**
     * Issue #344: the failed checks that refuse posting. Stock is left to the
     * posting's goods issue (InventoryService refuses a shortage inside the
     * posting transaction, with the warehouse posting itself resolves).
     *
     * @return list<array<string, string>>
     */
    public function blockingFailures(Invoice $invoice): array
    {
        $invoice->loadMissing(['customer', 'lines.product', 'salesOrder']);

        return array_values(array_filter(
            $this->run($invoice)['checks'],
            fn (array $check) => $check['status'] === 'fail' && ! in_array($check['key'], self::ENFORCED_BY_POSTING, true),
        ));
    }

    /**
     * Issue #344: an UNSAVED invoice built from the create/edit form payload —
     * on a copy of the saved invoice when editing (so approval state, the
     * duplicate self-exclusion and the "confirmed duplicate" flag are its
     * own). Nothing is persisted: the lines are in-memory models and the
     * totals use the same arithmetic as saving (Invoice::computeTotals).
     *
     * @param  array<string, mixed>  $payload
     */
    public function draft(array $payload, ?Invoice $existing = null): Invoice
    {
        $invoice = $existing ? clone $existing : new Invoice([
            'status' => 'draft',
            'paid_amount' => 0,
            'requires_approval' => false,
            'base_currency_code' => CompanySettings::current()?->base_currency,
        ]);
        $invoice->status = 'draft';

        foreach (['customer_id', 'invoice_date', 'po_number', 'customer_reference', 'tax_registration_number', 'discount_amount'] as $field) {
            if (array_key_exists($field, $payload)) {
                $invoice->{$field} = $payload[$field];
            }
        }

        $invoice->invoice_date ??= now()->toDateString();

        if (! empty($payload['currency_code']) && strtoupper((string) $payload['currency_code']) !== strtoupper((string) $invoice->currency_code)) {
            $invoice->currency_code = strtoupper((string) $payload['currency_code']);
            $invoice->exchange_rate = null;
        }

        $products = Product::query()
            ->whereIn('id', collect($payload['lines'] ?? [])->pluck('product_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        $lines = collect($payload['lines'] ?? [])->map(function (array $row) use ($products) {
            $line = new InvoiceLine([
                'line_type' => $row['line_type'] ?? 'product',
                'product_id' => $row['product_id'] ?? null,
                'description' => $row['description'] ?? null,
                'quantity' => $row['quantity'] ?? 0,
                'unit_price' => $row['unit_price'] ?? 0,
                'tax_rate' => $row['tax_rate'] ?? 0,
                'tax_inclusive' => (bool) ($row['tax_inclusive'] ?? false),
                'discount_amount' => $row['discount_amount'] ?? 0,
            ]);
            $line->calculateTotals();
            $line->setRelation('product', $line->product_id ? $products->get((int) $line->product_id) : null);

            return $line;
        });

        $invoice->setRelation('lines', $lines);
        $invoice->setRelation('customer', $invoice->customer_id ? Customer::find($invoice->customer_id) : null);
        $invoice->computeTotals();

        return $invoice;
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

    /**
     * Issue #344: when an e-invoicing country pack applies, the row is the
     * #671 readiness dry run (ComplianceService::invoiceReadiness) — the
     * pack's own rules, not just "a tax number exists". Its gaps block the
     * e-invoice submission, not the posting, so they warn. Without an
     * applicable pack the generic customer-TIN check stays.
     */
    private function eInvoiceCheck(Invoice $invoice): array
    {
        $readiness = $invoice->customer_id
            ? app(ComplianceService::class)->invoiceReadiness($invoice)
            : ['applicable' => false];

        if (! $readiness['applicable']) {
            return $this->tinCheck($invoice);
        }

        $label = 'E-invoice readiness ('.implode(', ', $readiness['countries']).')';

        if ($readiness['issues'] === []) {
            return $this->pass('e_invoice', $label, 'Ready for e-invoice submission.');
        }

        $detail = implode('; ', array_map(
            fn (array $issue) => ($issue['severity'] === 'blocking' ? 'Blocking: ' : '').$issue['message'],
            $readiness['issues'],
        ));

        if ($readiness['summary']['blocking'] > 0) {
            $detail .= ' — the e-invoice submission will be refused until fixed.';
        }

        return $this->warn('e_invoice', $label, $detail);
    }

    private function tinCheck(Invoice $invoice): array
    {
        $tin = trim((string) ($invoice->tax_registration_number ?? '')) ?: trim((string) ($invoice->customer?->tax_number ?? ''));

        return $tin !== ''
            ? $this->pass('e_invoice', 'Customer TIN', 'TIN on file: '.$tin)
            : $this->warn('e_invoice', 'Customer TIN', 'Customer TIN is missing — required for e-invoice (MyInvois).');
    }

    /**
     * Issue #344: the same arithmetic posting's goods issue applies — only
     * the quantity no document has issued yet (GoodsIssueLedger, so a line a
     * delivery order already shipped is not re-checked), summed per product,
     * against on hand minus reservations held by OTHER documents
     * (InventoryService::stockAvailability, #465/#686).
     */
    private function stockCheck(Invoice $invoice, Collection $productLines, ?int $warehouseOverride = null): array
    {
        $tracked = $productLines->filter(fn ($line) => $line->product && $line->product->track_inventory);

        if ($tracked->isEmpty()) {
            return $this->pass('stock', 'Stock availability', 'No tracked products on this invoice.');
        }

        $warehouseId = $warehouseOverride ?? $invoice->salesOrder?->warehouse_id ?? Warehouse::getDefault()?->id;

        if (! $warehouseId) {
            return $this->warn('stock', 'Stock availability', 'No warehouse context — stock cannot be checked.');
        }

        [$sourceType, $sourceId] = match (true) {
            (bool) $invoice->sales_order_id => ['SalesOrder', (int) $invoice->sales_order_id],
            $invoice->exists => ['Invoice', (int) $invoice->id],
            default => [null, null],
        };

        $ledger = app(GoodsIssueLedger::class);
        $demand = [];

        foreach ($tracked as $line) {
            $quantity = (float) $line->quantity;
            $toIssue = $line->exists ? $ledger->remainingForInvoiceLine($line, $quantity) : $quantity;

            if ($toIssue > 0) {
                $demand[(int) $line->product_id] = ($demand[(int) $line->product_id] ?? 0.0) + $toIssue;
            }
        }

        $inventory = app(InventoryService::class);
        $shortages = [];

        foreach ($demand as $productId => $quantity) {
            $available = (float) $inventory->stockAvailability($productId, (int) $warehouseId, $sourceType, $sourceId)['available'];

            if ($quantity > $available + 0.0000001) {
                $product = $tracked->first(fn ($line) => (int) $line->product_id === $productId)->product;
                $shortages[] = sprintf('%s: short by %s', $product->name, number_format($quantity - $available, 2));
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
