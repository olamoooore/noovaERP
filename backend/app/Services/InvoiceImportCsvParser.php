<?php

namespace App\Services;

/** Converts invoice CSV rows into normalized associative arrays. */
class InvoiceImportCsvParser
{
    /** @return array{rows?: array<int, array<string, string>>, error?: string, status?: int} */
    public function parse(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ['error' => 'Unable to read file', 'status' => 400];
        }

        $rawHeader = fgetcsv($handle);
        if (! is_array($rawHeader) || $rawHeader === []) {
            fclose($handle);

            return ['error' => 'CSV header missing', 'status' => 422];
        }

        $aliases = [
            'invoice_no' => 'invoice_number', 'quote_number' => 'invoice_number', 'quote_no' => 'invoice_number',
            'number' => 'invoice_number', 'customer' => 'customer_name', 'date' => 'invoice_date',
            'issue_date' => 'invoice_date', 'valid_until' => 'due_date', 'invoice_expiry_date' => 'due_date',
            'reference' => 'customer_reference', 'sku' => 'product_sku', 'item' => 'description',
            'item_description' => 'description', 'lot' => 'lot_number', 'batch' => 'lot_number',
            'batch_number' => 'lot_number', 'qty' => 'quantity', 'price' => 'unit_price', 'rate' => 'unit_price',
            'tax' => 'tax_rate', 'line_discount' => 'line_discount_amount', 'discount' => 'discount_amount',
            'billing_address' => 'billing_address_snapshot', 'shipping_address' => 'shipping_address_snapshot',
            'company_reg_no' => 'company_registration_number', 'tax_reg_no' => 'tax_registration_number',
            'email' => 'contact_email', 'phone' => 'contact_phone', 'case_date' => 'date_of_case',
            'case_no' => 'medical_case_no', 'location' => 'case_location', 'patient' => 'patient_name',
            'cost_center' => 'cost_center_id', 'doctor' => 'doctor_name', 'requestor' => 'requestor_name',
            'buyer' => 'buyer_name', 'billing_contact' => 'billing_contact_name',
            'shipping_contact' => 'shipping_contact_name', 'delivery_contact' => 'shipping_contact_name',
            'case_tag' => 'case_tag', 'delivered_to' => 'delivered_to', 'other_note' => 'other_note',
        ];
        $header = array_map(static function ($value) use ($aliases) {
            $key = strtolower(trim((string) $value));
            $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', $key), '_');

            return $aliases[$key] ?? $key;
        }, $rawHeader);

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (! is_array($data) || count(array_filter($data, fn ($value) => (string) $value !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($header as $index => $key) {
                if ($key !== '' && isset($data[$index])) {
                    $value = (string) $data[$index];
                    if (! mb_check_encoding($value, 'UTF-8')) {
                        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                    }
                    $row[$key] = $value;
                }
            }
            if ($row !== []) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $rows === []
            ? ['error' => 'No rows found', 'status' => 422]
            : ['rows' => $rows];
    }
}
