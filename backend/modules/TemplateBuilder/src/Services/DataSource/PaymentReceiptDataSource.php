<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use App\Models\Payment;
use Modules\TemplateBuilder\Contracts\DataSourceContract;

final class PaymentReceiptDataSource implements DataSourceContract
{
    private ?Payment $payment = null;

    public static function documentType(): string
    {
        return 'receipt';
    }

    public function permission(): string
    {
        return 'accounting.view';
    }

    public function legalEntityId(): ?int
    {
        $payment = $this->payment ?? throw new \RuntimeException('Payment not loaded.');
        // Issue #875 stamps the payment's own legal entity; customer links
        // remain the fallback for older rows.
        $entityId = $payment->getAttribute('legal_entity_id')
            ?? $payment->customer?->legal_entity_id
            ?? $payment->invoice?->customer?->legal_entity_id;

        return $entityId !== null ? (int) $entityId : null;
    }

    public function resolutionContext(string $documentId): array
    {
        $payment = $this->load($documentId)->payment;

        return DocumentResolutionContext::build([
            'entity_id' => $this->legalEntityId(),
            'branch_id' => $payment->getAttribute('branch_id'),
            'currency' => $payment->currency ?? $payment->getAttribute('currency_code') ?? $payment->base_currency_code,
            'language' => $payment->customer?->language ?? $payment->invoice?->customer?->language,
            'customer_group' => $payment->customer?->customer_group ?? $payment->invoice?->customer?->customer_group,
            'channel' => $payment->getAttribute('channel'),
        ]);
    }

    public function load(string $documentId): self
    {
        $this->payment = Payment::with(['customer', 'invoice', 'applications.invoice'])->findOrFail($documentId);

        return $this;
    }

    public function toArray(): array
    {
        $payment = $this->payment ?? throw new \RuntimeException('Payment not loaded.');
        $company = CompanyDataSource::forDocument($payment);
        $currencyCode = $company['currency_code'] ?? 'MYR';
        [$allocations, $applied, $unapplied] = $this->allocations($payment);

        return [
            'receipt' => [
                'number' => $payment->payment_number,
                'date' => optional($payment->payment_date)->format('Y-m-d'),
                'method' => $payment->method,
                'reference' => $payment->reference,
                'amount' => (float) $payment->amount,
                'invoice_no' => $allocations !== []
                    ? implode(', ', array_column($allocations, 'invoice_number'))
                    : ($payment->invoice?->invoice_number ?? ''),
            ],
            'customer' => [
                'name' => $payment->customer?->name ?? '',
                'email' => $payment->customer?->email ?? '',
            ],
            'company' => $company,
            // Issue #500: one line per invoice this payment settled.
            'allocations' => $allocations,
            'totals' => [
                'amount' => (float) $payment->amount,
                'applied' => $applied,
                'unapplied' => $unapplied,
                'total' => (float) $payment->amount,
            ],
            'meta' => [
                'currency_code' => $currencyCode,
                'currency_symbol' => InvoiceDataSource::currencySymbol($currencyCode),
                'number_format' => '1,234.56',
                'locale' => 'en',
            ],
        ];
    }

    /**
     * Issue #500: the receipt's allocation lines and its unapplied credit.
     *
     * Live #155 applications are authoritative; the unapplied amount reuses
     * PaymentAllocationService so the receipt agrees with the allocations
     * screen. A legacy payment recorded against one invoice before
     * applications existed (no application rows at all) prints that invoice
     * for the full amount.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: float, 2: float}
     */
    private function allocations(Payment $payment): array
    {
        $live = $payment->applications->whereNull('reversed_at')->values();

        if ($live->isEmpty() && $payment->applications->isEmpty() && $payment->invoice !== null) {
            return [[[
                'index' => 1,
                'invoice_number' => (string) $payment->invoice->invoice_number,
                'invoice_date' => optional($payment->invoice->invoice_date)->format('Y-m-d') ?? '',
                'invoice_total' => (float) $payment->invoice->total,
                'applied_amount' => (float) $payment->amount,
            ]], (float) $payment->amount, 0.0];
        }

        $lines = $live->map(fn ($application, $i) => [
            'index' => $i + 1,
            'invoice_number' => (string) ($application->invoice?->invoice_number ?? ''),
            'invoice_date' => optional($application->invoice?->invoice_date)->format('Y-m-d') ?? '',
            'invoice_total' => (float) ($application->invoice?->total ?? 0),
            'applied_amount' => (float) $application->applied_amount,
        ])->all();

        $applied = round((float) $live->sum('applied_amount'), 2);
        $unapplied = max(0.0, app(\App\Services\PaymentAllocationService::class)->unappliedAmount($payment));

        return [$lines, $applied, $unapplied];
    }

    public function fieldCatalog(): array
    {
        return [
            ['group' => 'Receipt', 'fields' => [
                ['token' => 'receipt.number', 'label' => 'Receipt Number'],
                ['token' => 'receipt.date', 'label' => 'Date'],
                ['token' => 'receipt.method', 'label' => 'Payment Method'],
                ['token' => 'receipt.reference', 'label' => 'Reference'],
                ['token' => 'receipt.amount', 'label' => 'Amount'],
                ['token' => 'receipt.invoice_no', 'label' => 'Invoice No.'],
            ]],
            ['group' => 'Allocations', 'fields' => [
                ['token' => 'allocations', 'label' => 'Invoices paid (table source)'],
                ['token' => 'totals.applied', 'label' => 'Applied to Invoices'],
                ['token' => 'totals.unapplied', 'label' => 'Unapplied Credit'],
            ]],
            ['group' => 'Customer', 'fields' => [
                ['token' => 'customer.name', 'label' => 'Name'],
                ['token' => 'customer.email', 'label' => 'Email'],
            ]],
            ['group' => 'Company', 'fields' => [
                ['token' => 'company.name', 'label' => 'Company Name'],
                ['token' => 'company.address', 'label' => 'Address'],
                ['token' => 'company.phone', 'label' => 'Phone'],
            ]],
        ];
    }

    public function sample(): array
    {
        return [
            'receipt' => [
                'number' => 'PAY-2026-0001',
                'date' => '2026-07-08',
                'method' => 'bank_transfer',
                'reference' => 'TXN-992011',
                'amount' => 8056.00,
                'invoice_no' => 'INV-2026-0001',
            ],
            'customer' => ['name' => 'Globex Corporation', 'email' => 'ap@globex.example'],
            'company' => CompanyDataSource::sample(),
            'allocations' => [
                ['index' => 1, 'invoice_number' => 'INV-2026-0001', 'invoice_date' => '2026-06-30', 'invoice_total' => 8000.00, 'applied_amount' => 8000.00],
            ],
            'totals' => ['amount' => 8056.00, 'applied' => 8000.00, 'unapplied' => 56.00, 'total' => 8056.00],
            'meta' => ['currency_code' => 'SGD', 'currency_symbol' => 'S$', 'number_format' => '1,234.56', 'locale' => 'en'],
        ];
    }
}
