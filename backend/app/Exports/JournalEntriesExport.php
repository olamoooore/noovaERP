<?php

namespace App\Exports;

use App\Platform\Export\SalesDocumentExport;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class JournalEntriesExport extends SalesDocumentExport
{
    public const COLUMNS = [
        'entry_number' => ['heading' => 'Entry No', 'type' => 'text'],
        'entry_date' => ['heading' => 'Date', 'type' => 'date'],
        'journal_name' => ['heading' => 'Journal', 'type' => 'text'],
        'reference' => ['heading' => 'Reference', 'type' => 'text'],
        'description' => ['heading' => 'Description', 'type' => 'text'],
        'remark' => ['heading' => 'Remark', 'type' => 'text'],
        'type' => ['heading' => 'Type', 'type' => 'text'],
        'status' => ['heading' => 'Status', 'type' => 'text'],
        'account_code' => ['heading' => 'Account Code', 'type' => 'text', 'line' => true],
        'account_name' => ['heading' => 'Account Name', 'type' => 'text', 'line' => true],
        'line_description' => ['heading' => 'Line Description', 'type' => 'text', 'line' => true],
        'debit' => ['heading' => 'Debit', 'type' => 'amount', 'line' => true],
        'credit' => ['heading' => 'Credit', 'type' => 'amount', 'line' => true],
        'currency_code' => ['heading' => 'Currency', 'type' => 'text', 'line' => true],
    ];

    protected function relations(): array { return ['journal', 'lines.account']; }
    protected function definitions(): array { return self::COLUMNS; }

    protected function value($entry, $line, string $column): mixed
    {
        return match ($column) {
            'entry_date' => $entry->entry_date ? Date::dateTimeToExcel($entry->entry_date) : null,
            'journal_name' => $entry->journal?->name,
            'account_code' => $line?->account?->code,
            'account_name' => $line?->account?->name,
            'line_description' => $line?->description,
            'debit', 'credit' => $line ? (float) $line->{$column} : null,
            'currency_code' => $line?->currency_code,
            default => $entry->{$column},
        };
    }
}
