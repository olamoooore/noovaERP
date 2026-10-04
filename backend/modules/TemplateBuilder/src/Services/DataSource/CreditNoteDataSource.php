<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use App\Models\CustomerCreditNote;
use Modules\TemplateBuilder\Contracts\DataSourceContract;
use Modules\TemplateBuilder\Support\AmountInWords;

/**
 * Issue #615 (TP5) — data source for customer credit notes (#531).
 *
 * Loads App\Models\CustomerCreditNote (the accounting entity) and maps it to
 * a merge-field payload. The `credit_note` namespace carries the statutory
 * facts (original invoice, reason, credit totals); the `invoice` namespace
 * mirrors the invoice-safe tokens so invoice-shaped blocks can be reused.
 *
 * The credit-note template type is registered canonically as `ar_credit_note`
 * — the single key map in CanonicalRegistrations binds it to this runtime key.
 */
final class CreditNoteDataSource implements DataSourceContract
{
    private ?CustomerCreditNote $creditNote = null;

    public static function documentType(): string
    {
        return 'credit_note';
    }

    public function permission(): string
    {
        return 'accounting.view';
    }

    public function legalEntityId(): ?int
    {
        $creditNote = $this->creditNote ?? throw new \RuntimeException('Credit note not loaded.');
        // #875: the credit note carries its own legal entity; the customer's
        // is only a fallback for rows created before that backfill.
        $entityId = $creditNote->legal_entity_id ?? $creditNote->customer?->legal_entity_id;

        return $entityId !== null ? (int) $entityId : null;
    }

    public function resolutionContext(string $documentId): array
    {
        $creditNote = $this->load($documentId)->creditNote;

        return DocumentResolutionContext::build([
            'entity_id' => $this->legalEntityId(),
            'currency' => $creditNote->currency_code,
            'language' => $creditNote->customer?->language,
            'customer_group' => $creditNote->customer?->customer_group,
        ]);
    }

    public function load(string $documentId): self
    {
        $this->creditNote = CustomerCreditNote::with([
            'customer.salesperson',
            'invoice',
            'salesOrder',
            'lines.product',
        ])->findOrFail($documentId);

        return $this;
    }

    public function toArray(): array
    {
        $creditNote = $this->creditNote ?? throw new \RuntimeException('Credit note not loaded.');
        $company = CompanyDataSource::forDocument($creditNote);
        $companyCurrency = $company['currency_code'] ?? 'MYR';
        $currencyCode = $creditNote->currency_code ?: $companyCurrency;
        $baseCurrencyCode = $creditNote->base_currency_code ?: $companyCurrency;
        $exchangeRate = $creditNote->exchange_rate !== null ? (float) $creditNote->exchange_rate : 1.0;
        $billingAddress = $this->formatAddress($creditNote->customer?->billing_address);

        $lines = [];
        $productIndex = 0;
        foreach ($creditNote->lines as $line) {
            $isProduct = ($line->line_type ?? 'product') === 'product';
            if ($isProduct) {
                $productIndex++;
            }
            $lines[] = [
                'line_type' => $line->line_type ?? 'product',
                'index' => $isProduct ? $productIndex : null,
                'sku' => $line->product?->sku ?? '',
                'description' => $line->description ?? '',
                'quantity' => $isProduct ? (float) $line->quantity : null,
                'unit_price' => $isProduct ? (float) $line->unit_price : null,
                'tax_rate' => (float) $line->tax_rate,
                'discount' => (float) ($line->discount_amount ?? 0),
                'line_total' => $isProduct ? (float) $line->line_total : null,
            ];
        }

        return [
            'credit_note' => [
                'number' => $creditNote->credit_note_number,
                'issue_date' => optional($creditNote->credit_note_date)->format('Y-m-d'),
                'due_date' => optional($creditNote->due_date)->format('Y-m-d'),
                'status' => $creditNote->status,
                'reason' => $creditNote->reason ?? '',
                'notes' => $creditNote->notes ?? '',
                'invoice_number' => $creditNote->invoice?->invoice_number ?? '',
                'invoice_date' => optional($creditNote->invoice?->invoice_date)->format('Y-m-d') ?? '',
                'invoice_total' => (float) ($creditNote->invoice?->total ?? 0),
                'sales_order_number' => $creditNote->salesOrder?->sales_order_number ?? '',
                'billing_address' => $billingAddress,
                'posted_at' => optional($creditNote->posted_at)->format('Y-m-d') ?? '',
            ],
            // Compatibility namespace for invoice-shaped templates.
            'invoice' => [
                'number' => $creditNote->credit_note_number,
                'issue_date' => optional($creditNote->credit_note_date)->format('Y-m-d'),
                'due_date' => optional($creditNote->due_date)->format('Y-m-d'),
                'status' => $creditNote->status,
                'reference' => $creditNote->invoice?->invoice_number ?? '',
                'billing_address' => $billingAddress,
                'shipping_address' => '',
                'notes' => $creditNote->notes ?? '',
                'terms' => '',
            ],
            'customer' => [
                'code' => $creditNote->customer?->code ?? '',
                'name' => $creditNote->customer?->name ?? '',
                'email' => $creditNote->customer?->email ?? '',
                'phone' => $creditNote->customer?->phone ?? '',
                'tax_id' => $creditNote->customer?->tax_number ?? '',
                'billing_address' => $billingAddress,
            ],
            'company' => $company,
            'lines' => $lines,
            'totals' => [
                'subtotal' => (float) $creditNote->subtotal,
                'tax' => (float) $creditNote->tax_amount,
                'discount' => (float) ($creditNote->discount_amount ?? 0),
                'shipping' => 0.0,
                // The credited amount is what the customer sees as the total.
                'total' => (float) $creditNote->total_amount,
                'paid' => 0.0,
                'balance' => (float) $creditNote->total_amount,
                'amount_in_words' => AmountInWords::format(
                    (float) $creditNote->total_amount,
                    $currencyCode,
                    $creditNote->customer?->language,
                ),
                'base_total' => round((float) $creditNote->total_amount * $exchangeRate, 2),
                'exchange_rate' => $exchangeRate,
            ],
            'meta' => [
                'currency_code' => $currencyCode,
                'base_currency_code' => $baseCurrencyCode,
                'exchange_rate' => $exchangeRate,
                'currency_symbol' => InvoiceDataSource::currencySymbol($currencyCode),
                'number_format' => '1,234.56',
                'locale' => 'en',
                'language' => strtolower((string) ($creditNote->customer?->language ?: 'en')),
            ],
        ];
    }

    public function fieldCatalog(): array
    {
        return [
            ['group' => 'Credit Note', 'fields' => [
                ['token' => 'credit_note.number', 'label' => 'Credit Note Number'],
                ['token' => 'credit_note.issue_date', 'label' => 'Credit Note Date'],
                ['token' => 'credit_note.due_date', 'label' => 'Due Date'],
                ['token' => 'credit_note.status', 'label' => 'Status'],
                ['token' => 'credit_note.reason', 'label' => 'Reason'],
                ['token' => 'credit_note.notes', 'label' => 'Notes'],
                ['token' => 'credit_note.invoice_number', 'label' => 'Original Invoice Number'],
                ['token' => 'credit_note.invoice_date', 'label' => 'Original Invoice Date'],
                ['token' => 'credit_note.invoice_total', 'label' => 'Original Invoice Total'],
                ['token' => 'credit_note.sales_order_number', 'label' => 'Source Sales Order'],
                ['token' => 'credit_note.billing_address', 'label' => 'Credit-To Address'],
                ['token' => 'credit_note.posted_at', 'label' => 'Posted Date'],
            ]],
            ['group' => 'Customer', 'fields' => [
                ['token' => 'customer.code', 'label' => 'Customer Code'],
                ['token' => 'customer.name', 'label' => 'Name'],
                ['token' => 'customer.email', 'label' => 'Email'],
                ['token' => 'customer.phone', 'label' => 'Phone'],
                ['token' => 'customer.tax_id', 'label' => 'Tax / GST Registration No.'],
            ]],
            ['group' => 'Company', 'fields' => [
                ['token' => 'company.name', 'label' => 'Company Name'],
                ['token' => 'company.address', 'label' => 'Address'],
                ['token' => 'company.email', 'label' => 'Email'],
                ['token' => 'company.phone', 'label' => 'Phone'],
            ]],
            ['group' => 'Totals', 'fields' => [
                ['token' => 'totals.subtotal', 'label' => 'Subtotal'],
                ['token' => 'totals.tax', 'label' => 'Tax'],
                ['token' => 'totals.discount', 'label' => 'Discount'],
                ['token' => 'totals.total', 'label' => 'Credit Amount'],
                ['token' => 'totals.amount_in_words', 'label' => 'Amount in Words'],
            ]],
            ['group' => 'Line Items', 'fields' => [
                ['token' => 'lines', 'label' => 'Line items table (binds a Table block)'],
            ]],
        ];
    }

    public function sample(): array
    {
        return [
            'credit_note' => [
                'number' => 'CN-2026-0001',
                'issue_date' => '2026-07-10',
                'due_date' => '2026-07-10',
                'status' => 'posted',
                'reason' => 'Damaged goods returned',
                'notes' => 'Credit applied against invoice INV-2026-0001.',
                'invoice_number' => 'INV-2026-0001',
                'invoice_date' => '2026-07-08',
                'invoice_total' => 8056.00,
                'sales_order_number' => 'SO-2026-0042',
                'billing_address' => "Globex Corporation\n1 Infinite Loop\nSan Francisco, CA 94102",
                'posted_at' => '2026-07-10',
            ],
            'invoice' => [
                'number' => 'CN-2026-0001',
                'issue_date' => '2026-07-10',
                'due_date' => '2026-07-10',
                'status' => 'posted',
                'reference' => 'INV-2026-0001',
                'billing_address' => "Globex Corporation\n1 Infinite Loop\nSan Francisco, CA 94102",
                'shipping_address' => '',
                'notes' => 'Credit applied against invoice INV-2026-0001.',
                'terms' => '',
            ],
            'customer' => [
                'code' => 'CUST-0001',
                'name' => 'Globex Corporation',
                'email' => 'ap@globex.example',
                'phone' => '+1 415 555 0100',
                'tax_id' => 'US-123456789',
                'billing_address' => "Globex Corporation\n1 Infinite Loop\nSan Francisco, CA 94102",
            ],
            'company' => CompanyDataSource::sample(),
            'lines' => [
                ['index' => 1, 'sku' => 'SKU-1001', 'description' => 'Damaged enterprise licence', 'quantity' => 1, 'unit_price' => 500.00, 'tax_rate' => 6.0, 'discount' => 0, 'line_total' => 530.00],
                ['index' => 2, 'sku' => 'SKU-3300', 'description' => 'Premium support — partial credit', 'quantity' => 1, 'unit_price' => 100.00, 'tax_rate' => 6.0, 'discount' => 0, 'line_total' => 106.00],
            ],
            'totals' => [
                'subtotal' => 600.00, 'tax' => 36.00, 'discount' => 0.00,
                'total' => 636.00, 'paid' => 0.00, 'balance' => 636.00,
                'amount_in_words' => AmountInWords::format(636.00, 'SGD', 'en'),
            ],
            'meta' => ['currency_code' => 'SGD', 'currency_symbol' => 'S$', 'number_format' => '1,234.56', 'locale' => 'en', 'language' => 'en'],
        ];
    }

    private function formatAddress(mixed $address): string
    {
        if (empty($address)) {
            return '';
        }
        if (is_string($address)) {
            $decoded = json_decode($address, true);
            if (is_array($decoded)) {
                return $this->formatAddress($decoded);
            }

            return trim($address);
        }
        if (is_array($address)) {
            $parts = array_values(array_filter(array_map('trim', array_map('strval', $address)), fn ($v) => $v !== ''));

            return implode("\n", $parts);
        }

        return (string) $address;
    }
}
