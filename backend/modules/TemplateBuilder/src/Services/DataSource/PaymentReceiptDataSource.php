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

    public function load(string $documentId): self
    {
        $this->payment = Payment::with(['customer', 'invoice'])->findOrFail($documentId);

        return $this;
    }

    public function toArray(): array
    {
        $payment = $this->payment ?? throw new \RuntimeException('Payment not loaded.');
        $company = CompanyDataSource::forCurrentTenant();
        $currencyCode = $company['currency_code'] ?? 'MYR';

        return [
            'receipt' => [
                'number' => $payment->payment_number,
                'date' => optional($payment->payment_date)->format('Y-m-d'),
                'method' => $payment->method,
                'reference' => $payment->reference,
                'amount' => (float) $payment->amount,
                'invoice_no' => $payment->invoice?->invoice_number ?? '',
            ],
            'customer' => [
                'name' => $payment->customer?->name ?? '',
                'email' => $payment->customer?->email ?? '',
            ],
            'company' => $company,
            'totals' => [
                'amount' => (float) $payment->amount,
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
            'totals' => ['amount' => 8056.00, 'total' => 8056.00],
            'meta' => ['currency_code' => 'SGD', 'currency_symbol' => 'S$', 'number_format' => '1,234.56', 'locale' => 'en'],
        ];
    }
}
