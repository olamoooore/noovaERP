<?php

namespace App\Exports;

use App\Platform\Export\SalesDocumentExport;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class CustomersExport extends SalesDocumentExport
{
    public const COLUMNS = [
        'code' => ['heading' => 'Customer Code', 'type' => 'text'],
        'name' => ['heading' => 'Customer Name', 'type' => 'text'],
        'type' => ['heading' => 'Type', 'type' => 'text'],
        'company' => ['heading' => 'Company', 'type' => 'text'],
        'email' => ['heading' => 'Email', 'type' => 'text'],
        'phone' => ['heading' => 'Phone', 'type' => 'text'],
        'mobile' => ['heading' => 'Mobile', 'type' => 'text'],
        'tax_number' => ['heading' => 'Tax Number', 'type' => 'text'],
        'billing_address' => ['heading' => 'Billing Address', 'type' => 'text'],
        'shipping_address' => ['heading' => 'Shipping Address', 'type' => 'text'],
        'address_type' => ['heading' => 'Address Type', 'type' => 'text'],
        'address_label' => ['heading' => 'Address Label', 'type' => 'text'],
        'address_street' => ['heading' => 'Street', 'type' => 'text'],
        'address_street2' => ['heading' => 'Street 2', 'type' => 'text'],
        'address_city' => ['heading' => 'City', 'type' => 'text'],
        'address_state' => ['heading' => 'State', 'type' => 'text'],
        'address_zip' => ['heading' => 'ZIP Code', 'type' => 'text'],
        'address_country' => ['heading' => 'Country', 'type' => 'text'],
        'address_is_default' => ['heading' => 'Default Address', 'type' => 'text'],
        'credit_limit' => ['heading' => 'Credit Limit', 'type' => 'amount'],
        'payment_terms' => ['heading' => 'Payment Terms (Days)', 'type' => 'number'],
        'total_outstanding' => ['heading' => 'Outstanding', 'type' => 'amount'],
        'total_purchases' => ['heading' => 'Total Purchases', 'type' => 'amount'],
        'total_orders' => ['heading' => 'Total Orders', 'type' => 'number'],
        'categories' => ['heading' => 'Categories', 'type' => 'text'],
        'salesperson' => ['heading' => 'Salesperson', 'type' => 'text'],
        'customer_since' => ['heading' => 'Customer Since', 'type' => 'date'],
        'is_active' => ['heading' => 'Active', 'type' => 'text'],
        'notes' => ['heading' => 'Notes', 'type' => 'text'],
    ];

    protected function relations(): array { return ['categories', 'salesperson', 'addresses']; }
    protected function definitions(): array { return self::COLUMNS; }

    public function map($customer): array
    {
        $addressColumns = ['address_type', 'address_label', 'address_street', 'address_street2', 'address_city', 'address_state', 'address_zip', 'address_country', 'address_is_default'];
        $includeAddresses = collect($this->columns)->intersect($addressColumns)->isNotEmpty();
        if (! $includeAddresses || $customer->addresses->isEmpty()) {
            return $this->mapCustomerRow($customer, null);
        }

        return $customer->addresses->map(fn ($address) => $this->mapCustomerRow($customer, $address))->all();
    }

    protected function value($customer, $line, string $column): mixed
    {
        return match ($column) {
            'customer_since' => $customer->customer_since ? Date::dateTimeToExcel($customer->customer_since) : null,
            'credit_limit', 'total_outstanding', 'total_purchases' => (float) $customer->{$column},
            'categories' => $customer->categories->pluck('name')->join(', '),
            'salesperson' => $customer->salesperson?->name,
            'is_active' => $customer->is_active ? 'Yes' : 'No',
            'address_type' => $line?->type,
            'address_label' => $line?->label,
            'address_street' => $line?->street,
            'address_street2' => $line?->street2,
            'address_city' => $line?->city,
            'address_state' => $line?->state,
            'address_zip' => $line?->zip,
            'address_country' => $line?->country,
            'address_is_default' => $line ? ($line->is_default ? 'Yes' : 'No') : null,
            default => $customer->{$column},
        };
    }

    private function mapCustomerRow($customer, $address): array
    {
        return array_map(fn (string $column) => $this->value($customer, $address, $column), $this->columns);
    }
}
