<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use App\Models\CustomerStatement;
use Carbon\Carbon;
use Modules\TemplateBuilder\Contracts\DataSourceContract;

final class CustomerStatementDataSource implements DataSourceContract
{
    private ?CustomerStatement $statement = null;

    public static function documentType(): string
    {
        return 'customer_statement';
    }

    public function permission(): string
    {
        return 'customers.view';
    }

    public function legalEntityId(): ?int
    {
        $statement = $this->statement ?? throw new \RuntimeException('Customer statement not loaded.');
        $entityId = $statement->customer?->legal_entity_id;

        return $entityId !== null ? (int) $entityId : null;
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

        $payload = $statement->payload ?? [];

        // MRD pricelist-multicurrency FR-8.2: the statement's reporting
        // currency is the payload's base; rows may be denominated in their
        // own document currency.
        $currencyCode = $payload['currency'] ?? ($company['currency_code'] ?? 'MYR');

        $transactions = [];
        foreach (($payload['transactions'] ?? []) as $i => $txn) {
            $transactions[] = [
                'index' => $i + 1,
                'date' => $txn['date'] ?? '',
                'type' => $txn['type'] ?? '',
                'document_number' => $txn['document_number'] ?? '',
                'description' => $txn['description'] ?? '',
                'reference' => $txn['reference'] ?? '',
                'due_date' => $txn['due_date'] ?? '',
                'inv_amount' => (float) ($txn['inv_amount'] ?? $txn['debit'] ?? 0),
                'debit' => (float) ($txn['debit'] ?? 0),
                'credit' => (float) ($txn['credit'] ?? 0),
                'balance' => (float) ($txn['balance'] ?? 0),
                'total' => (float) ($txn['total'] ?? 0),
                'status' => $txn['status'] ?? '',
                'currency_code' => $txn['currency_code'] ?? $currencyCode,
                'exchange_rate' => (float) ($txn['exchange_rate'] ?? 1.0),
                'base_debit' => (float) ($txn['base_debit'] ?? $txn['debit'] ?? 0),
                'base_credit' => (float) ($txn['base_credit'] ?? $txn['credit'] ?? 0),
            ];
        }

        $currencyTotals = array_map(fn (array $bucket) => [
            'currency_code' => $bucket['currency_code'] ?? '',
            'debit' => (float) ($bucket['debit'] ?? 0),
            'credit' => (float) ($bucket['credit'] ?? 0),
            'base_debit' => (float) ($bucket['base_debit'] ?? 0),
            'base_credit' => (float) ($bucket['base_credit'] ?? 0),
            'document_count' => (int) ($bucket['document_count'] ?? 0),
        ], $payload['currency_totals'] ?? []);

        return [
            'statement' => [
                'number' => 'STMT-'.$statement->id,
                'generated_at' => optional($statement->created_at)->format('Y-m-d'),
                'period_start' => $payload['period']['start_date'] ?? optional($statement->start_date)->format('Y-m-d'),
                'period_end' => $payload['period']['end_date'] ?? optional($statement->end_date)->format('Y-m-d'),
                'period_type' => $payload['period']['type'] ?? $statement->period_type,
                'include_paid' => $statement->include_paid,
                'as_of_date' => $payload['as_of_date'] ?? optional($statement->end_date)->format('Y-m-d'),
            ],
            'customer' => [
                'code' => $payload['customer']['code'] ?? $statement->customer?->code ?? '',
                'name' => $payload['customer']['name'] ?? $statement->customer?->name ?? '',
                'email' => $payload['customer']['email'] ?? $statement->customer?->email ?? '',
                'phone' => $statement->customer?->phone ?? '',
                'attention_to' => $payload['customer']['attention_to'] ?? '',
                'billing_address' => $payload['customer']['billing_address'] ?? '',
                'payment_terms' => $payload['customer']['payment_terms'] ?? '',
            ],
            'company' => $company,
            'transactions' => $transactions,
            'currency_totals' => $currencyTotals,
            'totals' => [
                'opening_balance' => (float) ($payload['opening_balance'] ?? 0),
                'total_amount_due' => (float) ($payload['total_amount_due'] ?? $payload['closing_balance'] ?? 0),
                'total_debits' => (float) ($payload['total_debits'] ?? 0),
                'total_credits' => (float) ($payload['total_credits'] ?? 0),
                'closing_balance' => (float) ($payload['closing_balance'] ?? 0),
            ],
            'bank' => [
                'bank_name' => $payload['bank']['bank_name'] ?? '',
                'account_name' => $payload['bank']['account_name'] ?? '',
                'account_number' => $payload['bank']['account_number'] ?? '',
                'swift_code' => $payload['bank']['swift_code'] ?? '',
                'bank_code' => $payload['bank']['bank_code'] ?? '',
            ],
            'aging' => $this->computeAging($statement),
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
                ['token' => 'statement.as_of_date', 'label' => 'As Of Date'],
            ]],
            ['group' => 'Customer', 'fields' => [
                ['token' => 'customer.code', 'label' => 'Customer Code'],
                ['token' => 'customer.name', 'label' => 'Name'],
                ['token' => 'customer.email', 'label' => 'Email'],
                ['token' => 'customer.phone', 'label' => 'Phone'],
                ['token' => 'customer.attention_to', 'label' => 'Attention To'],
                ['token' => 'customer.billing_address', 'label' => 'Billing Address'],
                ['token' => 'customer.payment_terms', 'label' => 'Payment Terms'],
            ]],
            ['group' => 'Company', 'fields' => [
                ['token' => 'company.name', 'label' => 'Company Name'],
                ['token' => 'company.address', 'label' => 'Address'],
                ['token' => 'company.email', 'label' => 'Email'],
                ['token' => 'company.phone', 'label' => 'Phone'],
            ]],
            ['group' => 'Totals', 'fields' => [
                ['token' => 'totals.opening_balance', 'label' => 'Opening Balance'],
                ['token' => 'totals.total_amount_due', 'label' => 'Total Amount Due'],
                ['token' => 'totals.total_debits', 'label' => 'Total Debits'],
                ['token' => 'totals.total_credits', 'label' => 'Total Credits'],
                ['token' => 'totals.closing_balance', 'label' => 'Closing Balance'],
            ]],
            ['group' => 'Bank Details', 'fields' => [
                ['token' => 'bank.bank_name', 'label' => 'Bank Name'],
                ['token' => 'bank.account_name', 'label' => 'Account Name'],
                ['token' => 'bank.account_number', 'label' => 'Account Number'],
                ['token' => 'bank.swift_code', 'label' => 'Swift Code'],
                ['token' => 'bank.bank_code', 'label' => 'Bank Code'],
            ]],
            ['group' => 'Aging Summary', 'fields' => [
                ['token' => 'aging.current', 'label' => 'Current'],
                ['token' => 'aging.days_1_30', 'label' => '1-30 Days'],
                ['token' => 'aging.days_31_60', 'label' => '31-60 Days'],
                ['token' => 'aging.days_61_90', 'label' => '61-90 Days'],
                ['token' => 'aging.days_90_plus', 'label' => '90+ Days'],
                ['token' => 'aging.total', 'label' => 'Total Outstanding'],
            ]],
            ['group' => 'Transactions', 'fields' => [
                ['token' => 'transactions', 'label' => 'Transactions table (binds a Table block)'],
                ['token' => 'transactions.currency_code', 'label' => 'Document currency (per row)'],
                ['token' => 'transactions.exchange_rate', 'label' => 'Snapshot rate (per row)'],
                ['token' => 'currency_totals', 'label' => 'Per-currency subtotals table (binds a Table block)'],
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
                'as_of_date' => '2026-07-27',
            ],
            'customer' => [
                'code' => 'CUST-0001',
                'name' => 'Globex Corporation',
                'email' => 'ap@globex.example',
                'phone' => '+1 415 555 0100',
                'attention_to' => 'Accounts Payable',
                'billing_address' => '1 Infinite Loop, San Francisco, CA 94102',
                'payment_terms' => '30 Days from Invoice Date',
            ],
            'company' => CompanyDataSource::sample(),
            'transactions' => [
                ['index' => 1, 'date' => '2026-06-01', 'type' => 'Opening Balance', 'document_number' => '', 'description' => 'Balance brought forward', 'reference' => '', 'due_date' => '', 'debit' => 5000.00, 'credit' => 0.00, 'balance' => 5000.00, 'status' => 'open'],
                ['index' => 2, 'date' => '2026-06-05', 'type' => 'Invoice',         'document_number' => 'INV-2026-0042', 'description' => 'Enterprise licence renewal', 'reference' => 'PO-0042', 'due_date' => '2026-07-05', 'debit' => 8056.00, 'credit' => 0.00, 'balance' => 13056.00, 'status' => 'sent'],
                ['index' => 3, 'date' => '2026-06-12', 'type' => 'Payment',         'document_number' => 'PMT-2026-0188', 'description' => 'Bank transfer', 'reference' => '', 'due_date' => '', 'debit' => 0.00, 'credit' => 3000.00, 'balance' => 10056.00, 'status' => 'received'],
                ['index' => 4, 'date' => '2026-06-20', 'type' => 'Credit Note',     'document_number' => 'CN-2026-0007', 'description' => 'Damaged goods adjustment', 'reference' => '', 'due_date' => '', 'debit' => 0.00, 'credit' => 500.00, 'balance' => 9556.00, 'status' => 'applied'],
            ],
            'totals' => [
                'opening_balance' => 5000.00,
                'total_debits' => 8056.00,
                'total_credits' => 3500.00,
                'closing_balance' => 9556.00,
            ],
            'aging' => [
                'current' => 3000.00,
                'days_1_30' => 4556.00,
                'days_31_60' => 2000.00,
                'days_61_90' => 0.00,
                'days_90_plus' => 0.00,
                'total' => 9556.00,
            ],
            'meta' => ['currency_code' => 'SGD', 'currency_symbol' => 'S$', 'number_format' => '1,234.56', 'locale' => 'en'],
        ];
    }

    private function computeAging(CustomerStatement $statement): array
    {
        $payload = $statement->payload ?? [];

        // Frozen snapshot computed by CustomerStatementService at build time
        // (#197/#198): aging and the transaction list always describe the same
        // document set, because neither re-queries live data.
        if (isset($payload['aging']) && is_array($payload['aging'])) {
            return array_map(
                'floatval',
                array_merge([
                    'current' => 0,
                    'days_1_30' => 0,
                    'days_31_60' => 0,
                    'days_61_90' => 0,
                    'days_90_plus' => 0,
                    'total' => 0,
                ], $payload['aging'])
            );
        }

        // Legacy statements (generated before snapshots existed): derive from
        // the frozen invoice rows in the payload. Null due dates bucket as
        // current (#203) — never Carbon::parse(null).
        $endDate = Carbon::parse($statement->end_date ?? now());

        $buckets = [
            'current' => 0,
            'days_1_30' => 0,
            'days_31_60' => 0,
            'days_61_90' => 0,
            'days_90_plus' => 0,
            'total' => 0,
        ];

        foreach (($payload['transactions'] ?? []) as $transaction) {
            if (($transaction['type'] ?? '') !== 'Invoice') {
                continue;
            }

            $amount = (float) ($transaction['outstanding'] ?? $transaction['balance'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $dueDate = $transaction['due_date'] ?? null;
            if (! $dueDate) {
                $buckets['current'] += $amount;
                $buckets['total'] += $amount;

                continue;
            }

            $due = Carbon::parse($dueDate);
            $bucket = 'current';
            if ($due->lt($endDate)) {
                $overdueDays = (int) $due->diffInDays($endDate);
                if ($overdueDays <= 30) {
                    $bucket = 'days_1_30';
                } elseif ($overdueDays <= 60) {
                    $bucket = 'days_31_60';
                } elseif ($overdueDays <= 90) {
                    $bucket = 'days_61_90';
                } else {
                    $bucket = 'days_90_plus';
                }
            }

            $buckets[$bucket] += $amount;
            $buckets['total'] += $amount;
        }

        return array_map(fn ($value) => round((float) $value, 2), $buckets);
    }
}
