<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use App\Models\CustomerStatement;
use Modules\TemplateBuilder\Contracts\DataSourceContract;

final class CustomerStatementDataSource implements DataSourceContract
{
    private ?CustomerStatement $statement = null;

    public static function documentType(): string
    {
        return 'customer_statement';
    }

    public function load(string $documentId): self
    {
        $this->statement = CustomerStatement::with(['customer'])->findOrFail($documentId);

        return $this;
    }

    public function toArray(): array
    {
        $statement = $this->statement ?? throw new \RuntimeException('Customer statement not loaded.');
        $company = CompanyDataSource::forCurrentTenant();
        $currencyCode = $company['currency_code'] ?? 'MYR';

        $payload = $statement->payload ?? [];

        $transactions = [];
        foreach (($payload['transactions'] ?? []) as $i => $txn) {
            $transactions[] = [
                'index' => $i + 1,
                'date' => $txn['date'] ?? '',
                'type' => $txn['type'] ?? '',
                'document_number' => $txn['document_number'] ?? '',
                'description' => $txn['description'] ?? '',
                'debit' => (float) ($txn['debit'] ?? 0),
                'credit' => (float) ($txn['credit'] ?? 0),
                'balance' => (float) ($txn['balance'] ?? 0),
                'status' => $txn['status'] ?? '',
            ];
        }

        return [
            'statement' => [
                'number' => 'STMT-'.$statement->id,
                'generated_at' => optional($statement->created_at)->format('Y-m-d'),
                'period_start' => $payload['period']['start_date'] ?? optional($statement->start_date)->format('Y-m-d'),
                'period_end' => $payload['period']['end_date'] ?? optional($statement->end_date)->format('Y-m-d'),
                'period_type' => $payload['period']['type'] ?? $statement->period_type,
                'include_paid' => $statement->include_paid,
            ],
            'customer' => [
                'code' => $payload['customer']['code'] ?? $statement->customer?->code ?? '',
                'name' => $payload['customer']['name'] ?? $statement->customer?->name ?? '',
                'email' => $payload['customer']['email'] ?? $statement->customer?->email ?? '',
                'phone' => $statement->customer?->phone ?? '',
            ],
            'company' => $company,
            'transactions' => $transactions,
            'totals' => [
                'opening_balance' => (float) ($payload['opening_balance'] ?? 0),
                'total_debits' => (float) ($payload['total_debits'] ?? 0),
                'total_credits' => (float) ($payload['total_credits'] ?? 0),
                'closing_balance' => (float) ($payload['closing_balance'] ?? 0),
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
            ['group' => 'Statement', 'fields' => [
                ['token' => 'statement.number', 'label' => 'Statement Number'],
                ['token' => 'statement.generated_at', 'label' => 'Generated Date'],
                ['token' => 'statement.period_start', 'label' => 'Period Start'],
                ['token' => 'statement.period_end', 'label' => 'Period End'],
                ['token' => 'statement.period_type', 'label' => 'Period Type'],
            ]],
            ['group' => 'Customer', 'fields' => [
                ['token' => 'customer.code', 'label' => 'Customer Code'],
                ['token' => 'customer.name', 'label' => 'Name'],
                ['token' => 'customer.email', 'label' => 'Email'],
                ['token' => 'customer.phone', 'label' => 'Phone'],
            ]],
            ['group' => 'Company', 'fields' => [
                ['token' => 'company.name', 'label' => 'Company Name'],
                ['token' => 'company.address', 'label' => 'Address'],
                ['token' => 'company.email', 'label' => 'Email'],
                ['token' => 'company.phone', 'label' => 'Phone'],
            ]],
            ['group' => 'Totals', 'fields' => [
                ['token' => 'totals.opening_balance', 'label' => 'Opening Balance'],
                ['token' => 'totals.total_debits', 'label' => 'Total Debits'],
                ['token' => 'totals.total_credits', 'label' => 'Total Credits'],
                ['token' => 'totals.closing_balance', 'label' => 'Closing Balance'],
            ]],
            ['group' => 'Transactions', 'fields' => [
                ['token' => 'transactions', 'label' => 'Transactions table (binds a Table block)'],
            ]],
        ];
    }

    public function sample(): array
    {
        return [
            'statement' => [
                'number' => 'STMT-0001',
                'generated_at' => '2026-07-22',
                'period_start' => '2026-06-01',
                'period_end' => '2026-06-30',
                'period_type' => 'monthly',
                'include_paid' => false,
            ],
            'customer' => [
                'code' => 'CUST-0001',
                'name' => 'Globex Corporation',
                'email' => 'ap@globex.example',
                'phone' => '+1 415 555 0100',
            ],
            'company' => CompanyDataSource::sample(),
            'transactions' => [
                ['index' => 1, 'date' => '2026-06-01', 'type' => 'Opening Balance', 'document_number' => '', 'description' => 'Balance brought forward', 'debit' => 5000.00, 'credit' => 0.00, 'balance' => 5000.00, 'status' => 'open'],
                ['index' => 2, 'date' => '2026-06-05', 'type' => 'Invoice',         'document_number' => 'INV-2026-0042', 'description' => 'Enterprise licence renewal', 'debit' => 8056.00, 'credit' => 0.00, 'balance' => 13056.00, 'status' => 'sent'],
                ['index' => 3, 'date' => '2026-06-12', 'type' => 'Payment',         'document_number' => 'PMT-2026-0188', 'description' => 'Bank transfer', 'debit' => 0.00, 'credit' => 3000.00, 'balance' => 10056.00, 'status' => 'received'],
                ['index' => 4, 'date' => '2026-06-20', 'type' => 'Credit Note',     'document_number' => 'CN-2026-0007', 'description' => 'Damaged goods adjustment', 'debit' => 0.00, 'credit' => 500.00, 'balance' => 9556.00, 'status' => 'applied'],
            ],
            'totals' => [
                'opening_balance' => 5000.00,
                'total_debits' => 8056.00,
                'total_credits' => 3500.00,
                'closing_balance' => 9556.00,
            ],
            'meta' => ['currency_code' => 'MYR', 'currency_symbol' => 'RM ', 'number_format' => '1,234.56', 'locale' => 'en'],
        ];
    }
}
