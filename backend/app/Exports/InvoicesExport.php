<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\DefaultValueBinder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InvoicesExport extends DefaultValueBinder implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithCustomChunkSize, WithCustomValueBinder, WithHeadings, WithMapping, WithStyles
{
    public const COLUMNS = [
        'invoice_number' => ['heading' => 'Invoice No', 'type' => 'text'],
        'invoice_date' => ['heading' => 'Date', 'type' => 'date'],
        'customer_name' => ['heading' => 'Customer', 'type' => 'text'],
        'customer_code' => ['heading' => 'Customer Code', 'type' => 'text'],
        'subtotal' => ['heading' => 'Subtotal', 'type' => 'amount'],
        'tax_amount' => ['heading' => 'Tax', 'type' => 'amount'],
        'total' => ['heading' => 'Amount', 'type' => 'amount'],
        'paid_amount' => ['heading' => 'Paid Amount', 'type' => 'amount'],
        'balance' => ['heading' => 'Balance', 'type' => 'amount'],
        'status' => ['heading' => 'Status', 'type' => 'text'],
        'due_date' => ['heading' => 'Due Date', 'type' => 'date'],
        'product_code' => ['heading' => 'Product Code', 'type' => 'text', 'line' => true],
        'product_name' => ['heading' => 'Product', 'type' => 'text', 'line' => true],
        'line_description' => ['heading' => 'Line Description', 'type' => 'text', 'line' => true],
        'model_number' => ['heading' => 'Model Number', 'type' => 'text', 'line' => true],
        'lot_number' => ['heading' => 'Lot Number', 'type' => 'text', 'line' => true],
        'expiry_date' => ['heading' => 'Expiry Date', 'type' => 'date', 'line' => true],
        'quantity' => ['heading' => 'Quantity', 'type' => 'number', 'line' => true],
        'unit_price' => ['heading' => 'Unit Price', 'type' => 'amount', 'line' => true],
        'line_discount' => ['heading' => 'Line Discount', 'type' => 'amount', 'line' => true],
        'tax_rate' => ['heading' => 'Tax Rate (%)', 'type' => 'number', 'line' => true],
        'line_tax_amount' => ['heading' => 'Line Tax', 'type' => 'amount', 'line' => true],
        'line_total' => ['heading' => 'Line Total', 'type' => 'amount', 'line' => true],
    ];

    public function __construct(private Builder $invoiceQuery, private array $columns) {}

    public function query(): Builder
    {
        return $this->invoiceQuery->with(['customer', 'lines.product']);
    }

    public function headings(): array
    {
        return array_map(fn (string $column) => self::COLUMNS[$column]['heading'], $this->columns);
    }

    public function map($invoice): array
    {
        if (! $this->includesLineColumns()) {
            return $this->mapRow($invoice, null);
        }

        if ($invoice->lines->isEmpty()) {
            return [$this->mapRow($invoice, null)];
        }

        return $invoice->lines
            ->map(fn ($line) => $this->mapRow($invoice, $line))
            ->all();
    }

    private function mapRow($invoice, $line): array
    {
        return array_map(function (string $column) use ($invoice, $line) {
            return match ($column) {
                'customer_name' => $invoice->customer?->name,
                'customer_code' => $invoice->customer?->code,
                'invoice_date', 'due_date' => $invoice->{$column}
                    ? Date::dateTimeToExcel($invoice->{$column})
                    : null,
                'subtotal', 'tax_amount', 'total', 'paid_amount', 'balance' => (float) $invoice->{$column},
                'product_code' => $line?->product?->sku,
                'product_name' => $line?->product?->name,
                'line_description' => $line?->description,
                'model_number', 'lot_number' => $line?->{$column},
                'expiry_date' => $line?->expiry_date ? Date::dateTimeToExcel($line->expiry_date) : null,
                'quantity', 'unit_price', 'tax_rate', 'line_total' => $line ? (float) $line->{$column} : null,
                'line_discount' => $line ? (float) $line->discount_amount : null,
                'line_tax_amount' => $line ? (float) $line->tax_amount : null,
                default => $invoice->{$column},
            };
        }, $this->columns);
    }

    private function includesLineColumns(): bool
    {
        return collect($this->columns)->contains(
            fn (string $column) => self::COLUMNS[$column]['line'] ?? false
        );
    }

    public function columnFormats(): array
    {
        $formats = [];
        foreach ($this->columns as $index => $column) {
            $letter = $this->columnLetter($index + 1);
            $formats[$letter] = match (self::COLUMNS[$column]['type']) {
                'date' => 'yyyy-mm-dd',
                'amount' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2,
                'number' => NumberFormat::FORMAT_NUMBER_00,
                default => NumberFormat::FORMAT_TEXT,
            };
        }

        return $formats;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
            ],
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    private function columnLetter(int $number): string
    {
        $letter = '';
        while ($number > 0) {
            $number--;
            $letter = chr(65 + ($number % 26)).$letter;
            $number = intdiv($number, 26);
        }

        return $letter;
    }
}
