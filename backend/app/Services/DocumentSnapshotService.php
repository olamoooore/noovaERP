<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Invoice;
use Modules\Sales\Models\SalesOrder;

/**
 * Populates document snapshot fields from authoritative customer and contact records.
 *
 * Snapshot fields capture customer/contact state at document creation time
 * and are immutable once set. This ensures issued documents retain the
 * original party data even if the source contact is later edited or deleted.
 */
class DocumentSnapshotService
{
    /**
     * Check if snapshot fields are already populated (document is finalized).
     */
    private function isSnapshotPopulated($document): bool
    {
        return ! empty($document->attention_to) || ! empty($document->contact_email);
    }

    /**
     * Populate snapshot fields on an Invoice from the customer's primary contact.
     */
    public function populateInvoiceSnapshot(Invoice $invoice, ?int $customerId = null): void
    {
        if ($this->isSnapshotPopulated($invoice)) {
            return; // Snapshot already set — immutable, do not overwrite
        }

        $customerId = $customerId ?? $invoice->customer_id;
        $customer = Customer::find($customerId);
        if (! $customer) {
            return;
        }

        $primaryContact = CustomerContact::where('customer_id', $customer->id)
            ->where('is_primary', true)
            ->first();

        $billingAddress = $this->resolveBillingAddress($customer, $primaryContact);

        $invoice->forceFill([
            'attention_to' => $primaryContact?->name ?? $customer->name,
            'contact_email' => $primaryContact?->email ?? $customer->email,
            'contact_phone' => $primaryContact?->phone ?? $primaryContact?->mobile ?? $customer->phone,
            'billing_address_snapshot' => $billingAddress,
            'delivery_address_snapshot' => $this->resolveDeliveryAddress($customer, $primaryContact),
            'payment_terms_snapshot' => $customer->payment_terms ?? null,
            'company_registration_number' => $customer->company_registration_number ?? null,
            'tax_registration_number' => $customer->tax_number ?? null,
        ])->save();
    }

    /**
     * Populate snapshot fields on a SalesOrder from the customer's primary contact.
     */
    public function populateSalesOrderSnapshot(SalesOrder $order, ?int $customerId = null): void
    {
        if ($this->isSnapshotPopulated($order)) {
            return; // Snapshot already set — immutable, do not overwrite
        }

        $customerId = $customerId ?? $order->customer_id;
        $customer = Customer::find($customerId);
        if (! $customer) {
            return;
        }

        $primaryContact = CustomerContact::where('customer_id', $customer->id)
            ->where('is_primary', true)
            ->first();

        $billingAddress = $this->resolveBillingAddress($customer, $primaryContact);

        $order->forceFill([
            'attention_to' => $primaryContact?->name ?? $customer->name,
            'contact_email' => $primaryContact?->email ?? $customer->email,
            'contact_phone' => $primaryContact?->phone ?? $primaryContact?->mobile ?? $customer->phone,
            'customer_name_snapshot' => [
                'name' => $customer->name,
                'code' => $customer->code,
                'company_registration_number' => $customer->company_registration_number,
                'tax_number' => $customer->tax_number,
            ],
            'billing_address_snapshot' => $billingAddress,
            'shipping_address_snapshot' => $this->resolveShippingAddress($customer, $primaryContact),
            'company_registration_number' => $customer->company_registration_number ?? null,
            'tax_registration_number' => $customer->tax_number ?? null,
        ])->save();
    }

    private function resolveBillingAddress(Customer $customer, ?CustomerContact $contact): ?array
    {
        if ($contact && ($contact->street || $contact->city)) {
            return [
                'address' => collect([$contact->street, $contact->street2])->filter()->implode(', '),
                'city' => $contact->city,
                'state' => $contact->state,
                'zip' => $contact->zip,
                'country' => $contact->country,
            ];
        }

        if ($customer->billing_address) {
            return is_array($customer->billing_address)
                ? $customer->billing_address
                : json_decode($customer->billing_address, true);
        }

        return null;
    }

    private function resolveDeliveryAddress(Customer $customer, ?CustomerContact $contact): ?array
    {
        return $this->resolveBillingAddress($customer, $contact);
    }

    private function resolveShippingAddress(Customer $customer, ?CustomerContact $contact): ?array
    {
        if ($customer->shipping_address) {
            return is_array($customer->shipping_address)
                ? $customer->shipping_address
                : json_decode($customer->shipping_address, true);
        }

        return $this->resolveBillingAddress($customer, $contact);
    }
}
