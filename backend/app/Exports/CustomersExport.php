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
        'contact_name' => ['heading' => 'Contact Name', 'type' => 'text'],
        'contact_category' => ['heading' => 'Contact Category', 'type' => 'text'],
        'contact_type' => ['heading' => 'Contact Type', 'type' => 'text'],
        'contact_email' => ['heading' => 'Contact Email', 'type' => 'text'],
        'contact_phone' => ['heading' => 'Contact Phone', 'type' => 'text'],
        'contact_mobile' => ['heading' => 'Contact Mobile', 'type' => 'text'],
        'contact_street' => ['heading' => 'Contact Street', 'type' => 'text'],
        'contact_street2' => ['heading' => 'Contact Street 2', 'type' => 'text'],
        'contact_city' => ['heading' => 'Contact City', 'type' => 'text'],
        'contact_state' => ['heading' => 'Contact State', 'type' => 'text'],
        'contact_zip' => ['heading' => 'Contact ZIP Code', 'type' => 'text'],
        'contact_country' => ['heading' => 'Contact Country', 'type' => 'text'],
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

    protected function relations(): array { return ['categories', 'salesperson', 'addresses', 'customerContacts']; }
    protected function definitions(): array { return self::COLUMNS; }

    public function map($customer): array
    {
        $addressColumns = ['address_type', 'address_label', 'address_street', 'address_street2', 'address_city', 'address_state', 'address_zip', 'address_country', 'address_is_default'];
        $contactColumns = ['contact_name', 'contact_category', 'contact_type', 'contact_email', 'contact_phone', 'contact_mobile', 'contact_street', 'contact_street2', 'contact_city', 'contact_state', 'contact_zip', 'contact_country'];
        $includeAddresses = collect($this->columns)->intersect($addressColumns)->isNotEmpty();
        $includeContacts = collect($this->columns)->intersect($contactColumns)->isNotEmpty();
        $addresses = $includeAddresses ? $customer->addresses->values() : collect();
        $contacts = $includeContacts ? $customer->customerContacts->values() : collect();
        $rowCount = max(1, $addresses->count(), $contacts->count());

        if ($rowCount === 1 && $addresses->isEmpty() && $contacts->isEmpty()) {
            return $this->mapCustomerRow($customer, ['address' => null, 'contact' => null]);
        }

        return collect(range(0, $rowCount - 1))->map(fn (int $index) => $this->mapCustomerRow($customer, [
            'address' => $addresses->get($index),
            'contact' => $contacts->get($index),
        ]))->all();
    }

    protected function value($customer, $line, string $column): mixed
    {
        $address = is_array($line) ? ($line['address'] ?? null) : $line;
        $contact = is_array($line) ? ($line['contact'] ?? null) : null;

        return match ($column) {
            'customer_since' => $customer->customer_since ? Date::dateTimeToExcel($customer->customer_since) : null,
            'credit_limit', 'total_outstanding', 'total_purchases' => (float) $customer->{$column},
            'categories' => $customer->categories->pluck('name')->join(', '),
            'salesperson' => $customer->salesperson?->name,
            'is_active' => $customer->is_active ? 'Yes' : 'No',
            'address_type' => $address?->type,
            'address_label' => $address?->label,
            'address_street' => $address?->street,
            'address_street2' => $address?->street2,
            'address_city' => $address?->city,
            'address_state' => $address?->state,
            'address_zip' => $address?->zip,
            'address_country' => $address?->country,
            'address_is_default' => $address ? ($address->is_default ? 'Yes' : 'No') : null,
            'contact_name' => $contact?->name,
            'contact_category' => $contact?->contact_category,
            'contact_type' => $contact?->contact_type,
            'contact_email' => $contact?->email,
            'contact_phone' => $contact?->phone,
            'contact_mobile' => $contact?->mobile,
            'contact_street' => $contact?->street,
            'contact_street2' => $contact?->street2,
            'contact_city' => $contact?->city,
            'contact_state' => $contact?->state,
            'contact_zip' => $contact?->zip,
            'contact_country' => $contact?->country,
            default => $customer->{$column},
        };
    }

    private function mapCustomerRow($customer, $address): array
    {
        return array_map(fn (string $column) => $this->value($customer, $address, $column), $this->columns);
    }
}
