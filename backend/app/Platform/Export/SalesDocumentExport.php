<?php

namespace App\Platform\Export;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

abstract class SalesDocumentExport extends DefaultValueBinder implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithCustomChunkSize, WithCustomValueBinder, WithHeadings, WithMapping, WithStyles
{
    public function __construct(protected Builder $documentQuery, protected array $columns) {}

    abstract protected function definitions(): array;

    abstract protected function value($document, $line, string $column): mixed;

    protected function relations(): array
    {
        return ['customer', 'lines.product'];
    }

    public function query(): Builder
    {
        return $this->documentQuery->with($this->relations());
    }

    public function headings(): array
    {
        $definitions = $this->definitions();

        return array_map(fn (string $column) => $definitions[$column]['heading'], $this->columns);
    }

    public function map($document): array
    {
        if (! $this->includesLineColumns()) {
            return $this->mapRow($document, null);
        }
        if ($document->lines->isEmpty()) {
            return [$this->mapRow($document, null)];
        }

        return $document->lines->map(fn ($line) => $this->mapRow($document, $line))->all();
    }

    public function columnFormats(): array
    {
        $definitions = $this->definitions();
        $formats = [];
        foreach ($this->columns as $index => $column) {
            $formats[$this->columnLetter($index + 1)] = match ($definitions[$column]['type']) {
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
        return [1 => [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
        ]];
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

    private function mapRow($document, $line): array
    {
        return array_map(fn (string $column) => $this->value($document, $line, $column), $this->columns);
    }

    private function includesLineColumns(): bool
    {
        $definitions = $this->definitions();

        return collect($this->columns)->contains(fn (string $column) => $definitions[$column]['line'] ?? false);
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
