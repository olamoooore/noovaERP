<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Contact\src\Models\ContactAddress;

/**
 * ADR-003B W6 §4 — REPORT ONLY. Plans moving every customer address into the
 * Contact Master (`contact_addresses`) and turning contact-person addresses
 * into company Sites, without writing anything. The report is reviewed
 * before any execution step exists (CLAUDE.md: no data change without a
 * reviewed backfill plan).
 *
 * Sources (see ADR-003B §1):
 *   B  customer_addresses           → contact_addresses on the customer's organisation
 *   A  customers.billing/shipping_address (legacy text)
 *   C  customer_contacts street…    → a company `site` + the person's relationship link
 *
 * Every row gets exactly one action. Rows that cannot be moved without a
 * human decision are `needs_review` with a reason — nothing is guessed.
 */
class ContactAddressBackfillPlanner
{
    public const ACTION_COPY = 'copy';

    public const ACTION_ALREADY_MIGRATED = 'already_migrated';

    public const ACTION_DUPLICATE_EXISTING = 'duplicate_of_existing';

    public const ACTION_SUPERSEDED = 'superseded_by_structured';

    public const ACTION_LINK_EXISTING_SITE = 'link_existing_site';

    public const ACTION_CREATE_SITE = 'create_site_and_link';

    public const ACTION_NEEDS_REVIEW = 'needs_review';

    private const TYPE_MAP = ['billing' => 'billing', 'shipping' => 'delivery'];

    /**
     * @return array<string, mixed>
     */
    public function plan(string $tenantId): array
    {
        $existing = ContactAddress::where('tenant_id', $tenantId)->get();
        $customers = Customer::query()->orderBy('id')->get();

        $customerAddresses = [];
        $legacyText = [];
        $personAddresses = [];
        $customersWithoutOrganisation = [];

        foreach ($customers as $customer) {
            $org = $customer->core_contact_uuid;
            if (! $org) {
                $customersWithoutOrganisation[] = $customer->id;
            }
            $orgAddresses = $org ? $existing->where('contact_id', $org) : collect();

            $structured = CustomerAddress::where('customer_id', $customer->id)->orderBy('id')->get();
            foreach ($structured as $address) {
                $customerAddresses[] = $this->planCustomerAddress($customer, $address, $orgAddresses);
            }

            foreach (['billing' => 'billing_address', 'shipping' => 'shipping_address'] as $type => $column) {
                $value = $customer->{$column};
                if (is_string($value) && trim($value) !== '') {
                    $legacyText[] = $this->planLegacyText($customer, $type, $value, $structured);
                }
            }

            $people = CustomerContact::where('customer_id', $customer->id)->orderBy('id')->get();
            foreach ($people as $person) {
                if ($this->hasAddress($person)) {
                    $personAddresses[] = $this->planPersonAddress($customer, $person, $orgAddresses, $structured);
                }
            }
        }

        return [
            'tenant_id' => $tenantId,
            'generated_at' => now()->toIso8601String(),
            'mode' => 'report_only',
            'summary' => [
                'customers' => $customers->count(),
                'customers_without_organisation' => count($customersWithoutOrganisation),
                'customer_addresses' => $this->countActions($customerAddresses),
                'legacy_text_addresses' => $this->countActions($legacyText),
                'person_addresses' => $this->countActions($personAddresses),
                'medical_case_references' => $this->medicalCaseReferences(),
            ],
            'customers_without_organisation' => $customersWithoutOrganisation,
            'customer_addresses' => $customerAddresses,
            'legacy_text_addresses' => $legacyText,
            'person_addresses' => $personAddresses,
        ];
    }

    private function planCustomerAddress(Customer $customer, CustomerAddress $address, $orgAddresses): array
    {
        $row = [
            'customer_id' => $customer->id,
            'customer_address_id' => $address->id,
            'source_type' => $address->type,
            'target_type' => self::TYPE_MAP[$address->type] ?? null,
            'is_default' => (bool) $address->is_default,
            'country' => $address->country,
            'summary' => $this->summary($address->street, $address->city, $address->zip, $address->country),
        ];

        $migrated = $orgAddresses->first(
            fn ($existing) => (int) (($existing->metadata ?? [])['legacy_customer_address_id'] ?? 0) === (int) $address->id
        );

        return match (true) {
            $migrated !== null => $row + ['action' => self::ACTION_ALREADY_MIGRATED, 'contact_address_id' => $migrated->id],
            ! $customer->core_contact_uuid => $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'customer_not_linked_to_organisation'],
            $row['target_type'] === null => $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'type_other_has_no_contact_master_equivalent'],
            ! $this->isIsoCountry($address->country) => $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'country_not_iso_alpha2'],
            ($dup = $this->findSame($orgAddresses, $row['target_type'], $address->street, $address->street2, $address->city, $address->zip)) !== null => $row + ['action' => self::ACTION_DUPLICATE_EXISTING, 'contact_address_id' => $dup->id],
            default => $row + ['action' => self::ACTION_COPY],
        };
    }

    private function planLegacyText(Customer $customer, string $type, string $value, $structured): array
    {
        $row = [
            'customer_id' => $customer->id,
            'source_type' => $type,
            'target_type' => self::TYPE_MAP[$type],
            'value' => $value,
        ];

        if ($structured->firstWhere('type', $type) !== null) {
            return $row + ['action' => self::ACTION_SUPERSEDED];
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'free_text_cannot_be_split_reliably'];
        }

        $country = $decoded['country'] ?? null;
        if (! $customer->core_contact_uuid) {
            return $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'customer_not_linked_to_organisation'];
        }
        if (! $this->isIsoCountry($country)) {
            return $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'country_not_iso_alpha2'];
        }

        return $row + ['action' => self::ACTION_COPY];
    }

    private function planPersonAddress(Customer $customer, CustomerContact $person, $orgAddresses, $structured): array
    {
        $row = [
            'customer_id' => $customer->id,
            'customer_contact_id' => $person->id,
            'person' => $person->name,
            'country' => $person->country,
            'summary' => $this->summary($person->street, $person->city, $person->zip, $person->country),
        ];

        if (! $customer->core_contact_uuid) {
            return $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'customer_not_linked_to_organisation'];
        }
        if (! $person->core_contact_uuid) {
            return $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'person_not_linked_to_contact_master'];
        }

        $site = $this->findSame($orgAddresses, 'site', $person->street, $person->street2, $person->city, $person->zip);
        if ($site !== null) {
            return $row + ['action' => self::ACTION_LINK_EXISTING_SITE, 'site_address_id' => $site->id];
        }

        if (! $this->isIsoCountry($person->country) && $person->country !== null && $person->country !== '') {
            return $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'country_not_iso_alpha2'];
        }

        $matchesCompanyAddress = $structured->first(
            fn ($address) => $this->key($address->street, $address->street2, $address->city, $address->zip)
                === $this->key($person->street, $person->street2, $person->city, $person->zip)
        );

        return $row + [
            'action' => self::ACTION_CREATE_SITE,
            'site_label' => $matchesCompanyAddress?->label ?: $person->name,
            'matches_company_address_id' => $matchesCompanyAddress?->id,
        ];
    }

    private function findSame($orgAddresses, string $type, ?string $street, ?string $street2, ?string $city, ?string $zip)
    {
        $key = $this->key($street, $street2, $city, $zip);

        return $orgAddresses->first(
            fn ($existing) => $existing->address_type === $type
                && $this->key($existing->line_1, $existing->line_2, $existing->city, $existing->postal_code) === $key
        );
    }

    /** Normalised comparison key: letters and digits only, lower case. */
    private function key(?string ...$parts): string
    {
        return implode('|', array_map(
            fn ($part) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $part)),
            $parts
        ));
    }

    private function hasAddress(CustomerContact $person): bool
    {
        return collect([$person->street, $person->street2, $person->city, $person->state, $person->zip, $person->country])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->isNotEmpty();
    }

    private function isIsoCountry(?string $country): bool
    {
        return is_string($country) && preg_match('/^[A-Za-z]{2}$/', trim($country)) === 1;
    }

    private function summary(?string ...$parts): string
    {
        return collect($parts)->filter()->implode(', ');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function countActions(array $rows): array
    {
        $counts = ['total' => count($rows)];
        foreach ($rows as $row) {
            $key = $row['action'] === self::ACTION_NEEDS_REVIEW ? 'needs_review:'.$row['reason'] : $row['action'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /** medical_cases.delivery_address_id points at customer_addresses and needs a remap plan. */
    private function medicalCaseReferences(): int
    {
        if (! Schema::hasTable('medical_cases') || ! Schema::hasColumn('medical_cases', 'delivery_address_id')) {
            return 0;
        }

        return DB::table('medical_cases')->whereNotNull('delivery_address_id')->count();
    }
}
