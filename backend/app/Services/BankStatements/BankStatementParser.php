<?php

namespace App\Services\BankStatements;

use PhpOffice\PhpSpreadsheet\IOFactory;

class BankStatementParser
{
    public function read(string $path, string $type, int $headerRow = 1, ?string $worksheet = null): array
    {
        if ($type === 'csv') {
            $handle = fopen($path, 'rb');
            if (! $handle) {
                throw new \RuntimeException('Statement file cannot be read.');
            }
            $rows = [];
            $line = 0;
            $headers = [];
            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $line++;
                if ($line < $headerRow) {
                    continue;
                }
                if ($line === $headerRow) {
                    $headers = $this->headers($values);

                    continue;
                }
                if (! array_filter($values, fn ($v) => trim((string) $v) !== '')) {
                    continue;
                }
                $rows[] = ['source_row' => $line, 'values' => array_combine($headers, array_pad(array_slice($values, 0, count($headers)), count($headers), null))];
            }
            fclose($handle);

            return ['worksheet' => null, 'headers' => $headers, 'rows' => $rows];
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $sheet = $worksheet ? $book->getSheetByName($worksheet) : $book->getActiveSheet();
        if (! $sheet) {
            throw new \InvalidArgumentException('Selected worksheet was not found.');
        }
        $matrix = $sheet->toArray(null, true, true, false);
        $headers = $this->headers($matrix[$headerRow - 1] ?? []);
        $rows = [];
        foreach (array_slice($matrix, $headerRow) as $offset => $values) {
            if (! array_filter($values, fn ($v) => trim((string) $v) !== '')) {
                continue;
            }
            $rows[] = ['source_row' => $headerRow + $offset + 1, 'values' => array_combine($headers, array_pad(array_slice($values, 0, count($headers)), count($headers), null))];
        }

        return ['worksheet' => $sheet->getTitle(), 'headers' => $headers, 'rows' => $rows];
    }

    private function headers(array $values): array
    {
        return array_map(function ($value, $i) {
            $header = trim((string) $value);

            return $header !== '' ? $header : '__column_'.($i + 1);
        }, $values, array_keys($values));
    }

    public function proposeMapping(array $headers): array
    {
        $aliases = [
            'account_number' => ['accountnumber', 'accountno'], 'value_date' => ['valuedate'], 'transaction_date' => ['date', 'transactiondate'],
            'transaction_time' => ['time', 'transactiontime'], 'description' => ['description', 'narrative', 'details'],
            'bank_reference' => ['ourreference', 'bankreference', 'reference'], 'customer_reference' => ['yourreference', 'customerreference'],
            'customer_code' => ['customercode'], 'debit' => ['debit', 'withdrawal'], 'credit' => ['credit', 'deposit'], 'amount' => ['amount'],
            'running_balance' => ['ledgerbalance', 'runningbalance', 'balance'], 'invoice_reference' => ['invoicenumbers', 'correctedinvoicenumber', 'invoicereference'],
            'allocation_amount' => ['amountdepositperinvoice', 'allocationamount'],
        ];
        $normalized = [];
        foreach ($headers as $header) {
            $normalized[preg_replace('/[^a-z0-9]/', '', strtolower($header))] = $header;
        }
        $mapping = [];
        foreach ($aliases as $field => $names) {
            foreach ($names as $name) {
                if (isset($normalized[$name])) {
                    $mapping[$field] = $normalized[$name];
                    break;
                }
            }
        }

        return $mapping;
    }
}
