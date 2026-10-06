<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;

/**
 * ADR-003B rule R4: the one place that decides which customer address a
 * document uses. Backend snapshots and the invoice/quotation screens (via the
 * customer API's `resolved_addresses`) all read it, so they cannot disagree.
 *
 * Bill-to: default billing address → legacy `billing_address` text →
 *          primary contact person's address (last fallback only; kept for
 *          customers that have no address at all, VEN-AT-11c/11d).
 * Ship-to: default shipping address → legacy `shipping_address` text →
 *          the bill-to.
 *
 * Addresses are returned as label/street/street2/city/state/zip/country
 * records (label only for saved customer addresses), or null when nothing
 * resolves.
 */
class CustomerAddressResolver
{
    private const FIELDS = ['street', 'street2', 'city', 'state', 'zip', 'country'];

    /**
     * @return array{label: ?string, street: ?string, street2: ?string, city: ?string, state: ?string, zip: ?string, country: ?string}|null
     */
    public function billing(Customer $customer, ?CustomerContact $primaryContact = null): ?array
    {
        return $this->defaultAddress($customer, 'billing')
            ?? $this->legacyAddress($customer->billing_address)
            ?? $this->contactAddress($primaryContact ?? $this->primaryContact($customer));
    }

    /**
     * @return array{label: ?string, street: ?string, street2: ?string, city: ?string, state: ?string, zip: ?string, country: ?string}|null
     */
    public function shipping(Customer $customer, ?CustomerContact $primaryContact = null): ?array
    {
        return $this->defaultAddress($customer, 'shipping')
            ?? $this->legacyAddress($customer->shipping_address)
            ?? $this->billing($customer, $primaryContact);
    }

    /**
     * @return array{billing: ?array, shipping: ?array}
     */
    public function resolve(Customer $customer): array
    {
        $primaryContact = $this->primaryContact($customer);

        return [
            'billing' => $this->billing($customer, $primaryContact),
            'shipping' => $this->shipping($customer, $primaryContact),
        ];
    }

    /**
     * ADR-003B W2b: the bill-to or ship-to for a document that has no saved
     * address of its own, in the snapshot shape (null when nothing resolves
     * or the document has no customer).
     *
     * @return array{address: string, city: ?string, state: ?string, zip: ?string, country: ?string}|null
     */
    public function snapshotFor(?Customer $customer, string $type): ?array
    {
        if (! $customer) {
            return null;
        }

        return self::toSnapshot($type === 'shipping' ? $this->shipping($customer) : $this->billing($customer));
    }

    /**
     * The address as text lines (street, city/state/zip, country), for
     * screens and documents that hold a free-text address.
     */
    public static function toText(?array $snapshot, string $separator = "\n"): string
    {
        if ($snapshot === null) {
            return '';
        }

        return collect([
            $snapshot['address'] ?? null,
            collect([$snapshot['city'] ?? null, $snapshot['state'] ?? null, $snapshot['zip'] ?? null])->filter()->implode(' '),
            $snapshot['country'] ?? null,
        ])->filter()->implode($separator);
    }

    /**
     * The document snapshot shape stored on sales orders and invoices.
     *
     * @return array{address: string, city: ?string, state: ?string, zip: ?string, country: ?string}|null
     */
    public static function toSnapshot(?array $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'address' => collect([$address['street'], $address['street2']])->filter()->implode(', '),
            'city' => $address['city'],
            'state' => $address['state'],
            'zip' => $address['zip'],
            'country' => $address['country'],
        ];
    }

    private function defaultAddress(Customer $customer, string $type): ?array
    {
        $address = $customer->addresses()
            ->where('type', $type)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        return $address ? $this->fromRecord($address) : null;
    }

    private function contactAddress(?CustomerContact $contact): ?array
    {
        if (! $contact || (! $contact->street && ! $contact->city)) {
            return null;
        }

        return $this->fromRecord($contact);
    }

    private function primaryContact(Customer $customer): ?CustomerContact
    {
        return CustomerContact::where('customer_id', $customer->id)
            ->where('is_primary', true)
            ->first();
    }

    private function fromRecord(CustomerAddress|CustomerContact $record): ?array
    {
        $address = [];
        foreach (self::FIELDS as $field) {
            $address[$field] = $record->{$field} ?: null;
        }

        if (array_filter($address) === []) {
            return null;
        }

        return ['label' => $record instanceof CustomerAddress ? ($record->label ?: null) : null] + $address;
    }

    /**
     * Legacy columns hold either a JSON object (older imports/storefront) or
     * free text. Free text is kept whole in `street`: splitting it into
     * city/state/zip by line position would guess wrong whenever a line was
     * left empty.
     */
    private function legacyAddress(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }

            $decoded = json_decode($value, true);
            if (! is_array($decoded)) {
                return ['label' => null] + array_combine(self::FIELDS, [$value, null, null, null, null, null]);
            }
            $value = $decoded;
        }

        if (! is_array($value)) {
            return null;
        }

        $address = [
            'label' => null,
            'street' => $value['street'] ?? $value['address'] ?? $value['address_line_1'] ?? null,
            'street2' => $value['street2'] ?? $value['address_line_2'] ?? null,
            'city' => $value['city'] ?? null,
            'state' => $value['state'] ?? null,
            'zip' => $value['zip'] ?? $value['postal_code'] ?? null,
            'country' => $value['country'] ?? null,
        ];

        return array_filter($address) === [] ? null : $address;
    }
}
