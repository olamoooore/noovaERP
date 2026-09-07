<?php

namespace App\Exports;

use App\Platform\Export\SalesDocumentExport;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class PaymentsExport extends SalesDocumentExport
{
    public const COLUMNS = [
        'payment_number' => ['heading' => 'Payment No', 'type' => 'text'],
        'payment_date' => ['heading' => 'Date', 'type' => 'date'],
        'customer_name' => ['heading' => 'Customer', 'type' => 'text'],
        'invoice_number' => ['heading' => 'Invoice No', 'type' => 'text'],
        'amount' => ['heading' => 'Amount', 'type' => 'amount'],
        'currency' => ['heading' => 'Currency', 'type' => 'text'],
        'method' => ['heading' => 'Method', 'type' => 'text'],
        'reference' => ['heading' => 'Reference', 'type' => 'text'],
        'payment_status' => ['heading' => 'Status', 'type' => 'text'],
        'journal_entry_number' => ['heading' => 'Journal Entry', 'type' => 'text'],
        'notes' => ['heading' => 'Notes', 'type' => 'text'],
    ];

    protected function relations(): array { return ['customer', 'invoice', 'journalEntry']; }
    protected function definitions(): array { return self::COLUMNS; }

    protected function value($payment, $line, string $column): mixed
    {
        return match ($column) {
            'payment_date' => $payment->payment_date ? Date::dateTimeToExcel($payment->payment_date) : null,
            'customer_name' => $payment->customer?->name,
            'invoice_number' => $payment->invoice?->invoice_number,
            'journal_entry_number' => $payment->journalEntry?->entry_number,
            'amount' => (float) $payment->amount,
            default => $payment->{$column},
        };
    }
}
