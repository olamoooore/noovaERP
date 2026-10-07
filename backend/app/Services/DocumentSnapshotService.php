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

        $addresses = app(CustomerAddressResolver::class);

        $invoice->forceFill([
            'attention_to' => $primaryContact?->name ?? $customer->name,
            'contact_email' => $primaryContact?->email ?? $customer->email,
            'contact_phone' => $primaryContact?->phone ?? $primaryContact?->mobile ?? $customer->phone,
            // ADR-003B R4: bill-to and ship-to come from the one resolver; the
            // invoice delivery address follows the ship-to rule.
            'billing_address_snapshot' => CustomerAddressResolver::toSnapshot($addresses->billing($customer, $primaryContact)),
            'delivery_address_snapshot' => CustomerAddressResolver::toSnapshot($addresses->shipping($customer, $primaryContact)),
            // A terms value chosen on the document (day count passed through
            // the create payload) is authoritative; the customer default is
            // only a fallback.
            'payment_terms_snapshot' => $invoice->payment_terms_snapshot ?: ($customer->payment_terms ?? null),
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

        $addresses = app(CustomerAddressResolver::class);

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
            'billing_address_snapshot' => CustomerAddressResolver::toSnapshot($addresses->billing($customer, $primaryContact)),
            'shipping_address_snapshot' => CustomerAddressResolver::toSnapshot($addresses->shipping($customer, $primaryContact)),
            'company_registration_number' => $customer->company_registration_number ?? null,
            'tax_registration_number' => $customer->tax_number ?? null,
        ])->save();
    }
}
