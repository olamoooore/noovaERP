<?php

namespace App\Exports;

use App\Platform\Export\SalesDocumentExport;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class InvoicesExport extends SalesDocumentExport
{
    protected function relations(): array
    {
        return [
            'customer.addresses',
            'buyer',
            'doctor',
            'requestor',
            'billingContact',
            'shippingContact',
            'lines.product',
        ];
    }

    public const COLUMNS = [
        'id' => ['heading' => 'Invoice ID', 'type' => 'number'],
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
        'case_number' => ['heading' => 'Case Number', 'type' => 'text'],
        'date_of_case' => ['heading' => 'Case Date', 'type' => 'date'],
        'case_location' => ['heading' => 'Case Location', 'type' => 'text'],
        'patient_name' => ['heading' => 'Patient Name', 'type' => 'text'],
        'cost_center_id' => ['heading' => 'Cost Center ID', 'type' => 'text'],
        'delivery_date' => ['heading' => 'Delivery Date', 'type' => 'date'],
        'po_number' => ['heading' => 'PO Number', 'type' => 'text'],
        'customer_reference' => ['heading' => 'Customer Reference', 'type' => 'text'],
        'internal_reference' => ['heading' => 'Internal Reference', 'type' => 'text'],
        'pr_no' => ['heading' => 'PR No', 'type' => 'text'], 'contract_no' => ['heading' => 'Contract No', 'type' => 'text'],
        'buyer_name' => ['heading' => 'Buyer', 'type' => 'text'], 'plant_code' => ['heading' => 'Plant Code', 'type' => 'text'], 'company_code' => ['heading' => 'Company Code', 'type' => 'text'],
        'doctor' => ['heading' => 'Doctor', 'type' => 'text'],
        'requestor' => ['heading' => 'Requestor', 'type' => 'text'],
        'billing_contact' => ['heading' => 'Billing Contact', 'type' => 'text'],
        'shipping_contact' => ['heading' => 'Shipping Contact', 'type' => 'text'],
        'billing_label' => ['heading' => 'Billing Label', 'type' => 'text'],
        'billing_street' => ['heading' => 'Billing Street', 'type' => 'text'],
        'billing_street2' => ['heading' => 'Billing Street 2', 'type' => 'text'],
        'billing_city' => ['heading' => 'Billing City', 'type' => 'text'],
        'billing_state' => ['heading' => 'Billing State', 'type' => 'text'],
        'billing_zip' => ['heading' => 'Billing ZIP', 'type' => 'text'],
        'billing_country' => ['heading' => 'Billing Country', 'type' => 'text'],
        'shipping_label' => ['heading' => 'Shipping Label', 'type' => 'text'],
        'shipping_street' => ['heading' => 'Shipping Street', 'type' => 'text'],
        'shipping_street2' => ['heading' => 'Shipping Street 2', 'type' => 'text'],
        'shipping_city' => ['heading' => 'Shipping City', 'type' => 'text'],
        'shipping_state' => ['heading' => 'Shipping State', 'type' => 'text'],
        'shipping_zip' => ['heading' => 'Shipping ZIP', 'type' => 'text'],
        'shipping_country' => ['heading' => 'Shipping Country', 'type' => 'text'],
        'attention_to' => ['heading' => 'Attention To', 'type' => 'text'],
        'contact_email' => ['heading' => 'Contact Email', 'type' => 'text'],
        'contact_phone' => ['heading' => 'Contact Phone', 'type' => 'text'],
        'payment_terms_snapshot' => ['heading' => 'Payment Terms', 'type' => 'text'],
        'company_registration_number' => ['heading' => 'Company Registration Number', 'type' => 'text'],
        'tax_registration_number' => ['heading' => 'Tax Registration Number', 'type' => 'text'],
        'tax_jurisdiction' => ['heading' => 'Tax Jurisdiction', 'type' => 'text'],
        'tax_regime' => ['heading' => 'Tax Regime', 'type' => 'text'],
        'tax_treatment' => ['heading' => 'Tax Treatment', 'type' => 'text'],
        'discount_amount' => ['heading' => 'Discount Amount', 'type' => 'amount'],
        'shipping_cost' => ['heading' => 'Shipping Cost', 'type' => 'amount'],
        'shipping_method' => ['heading' => 'Shipping Method', 'type' => 'text'],
        'notes' => ['heading' => 'Notes', 'type' => 'text'],
        'terms' => ['heading' => 'Terms', 'type' => 'text'],
        'product_code' => ['heading' => 'Product Code', 'type' => 'text', 'line' => true],
        'product_name' => ['heading' => 'Product', 'type' => 'text', 'line' => true],
        'line_description' => ['heading' => 'Line Description', 'type' => 'text', 'line' => true],
        'line_type' => ['heading' => 'Line Type', 'type' => 'text', 'line' => true],
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

    protected function definitions(): array
    {
        return self::COLUMNS;
    }

    protected function value($invoice, $line, string $column): mixed
    {
        return match ($column) {
            'customer_name' => $invoice->customer?->name, 'buyer_name' => $invoice->buyer?->full_name ?? $invoice->buyer?->display_name,
            'customer_code' => $invoice->customer?->code,
            'doctor' => $this->contactName($invoice->doctor),
            'requestor' => $this->contactName($invoice->requestor),
            'billing_contact' => $this->contactName($invoice->billingContact),
            'shipping_contact' => $this->contactName($invoice->shippingContact),
            'billing_label', 'billing_street', 'billing_street2', 'billing_city',
            'billing_state', 'billing_zip', 'billing_country' => $this->addressValue($invoice, 'billing', substr($column, 8)),
            'shipping_label', 'shipping_street', 'shipping_street2', 'shipping_city',
            'shipping_state', 'shipping_zip', 'shipping_country' => $this->addressValue($invoice, 'shipping', substr($column, 9)),
            'invoice_date', 'due_date', 'date_of_case', 'delivery_date' => $invoice->{$column} ? Date::dateTimeToExcel($invoice->{$column}) : null,
            'subtotal', 'tax_amount', 'discount_amount', 'shipping_cost', 'total', 'paid_amount', 'balance' => (float) $invoice->{$column},
            'id' => $invoice->{$column} === null ? null : (float) $invoice->{$column},
            'product_code' => $line?->product?->sku,
            'product_name' => $line?->product?->name,
            'line_description' => $line?->description,
            'line_type' => $line?->line_type ?? 'product',
            'model_number', 'lot_number' => $line?->{$column},
            'expiry_date' => $line?->expiry_date ? Date::dateTimeToExcel($line->expiry_date) : null,
            'quantity', 'unit_price', 'tax_rate', 'line_total' => $line && ($line->line_type ?? 'product') === 'product' ? (float) $line->{$column} : null,
            'line_discount' => $line && ($line->line_type ?? 'product') === 'product' ? (float) $line->discount_amount : null,
            'line_tax_amount' => $line && ($line->line_type ?? 'product') === 'product' ? (float) $line->tax_amount : null,
            default => $invoice->{$column},
        };
    }

    private function contactName($contact): ?string
    {
        return $contact?->full_name
            ?? $contact?->display_name
            ?? $contact?->company_name
            ?? $contact?->name;
    }

    private function addressValue($invoice, string $type, string $field): ?string
    {
        $snapshot = $type === 'billing'
            ? $invoice->billing_address_snapshot
            : ($invoice->delivery_address_snapshot ?? $invoice->shipping_address_snapshot);
        $address = $this->parseAddress($snapshot);

        if (! $address) {
            $model = $invoice->customer?->addresses
                ?->first(fn ($candidate) => $candidate->type === $type && $candidate->is_default)
                ?? $invoice->customer?->addresses?->firstWhere('type', $type);
            $address = $model?->toArray() ?? [];
        }

        $aliases = [
            'street' => ['street', 'address', 'line1'],
            'street2' => ['street2', 'address2', 'line2'],
            'zip' => ['zip', 'postal_code', 'postcode'],
        ];

        foreach ($aliases[$field] ?? [$field] as $key) {
            if (isset($address[$key]) && trim((string) $address[$key]) !== '') {
                return (string) $address[$key];
            }
        }

        return null;
    }

    private function parseAddress(mixed $snapshot): array
    {
        if (is_array($snapshot)) {
            return $snapshot;
        }
        if (! is_string($snapshot) || trim($snapshot) === '') {
            return [];
        }

        $decoded = json_decode($snapshot, true);

        return is_array($decoded) ? $decoded : ['street' => $snapshot];
    }
}
