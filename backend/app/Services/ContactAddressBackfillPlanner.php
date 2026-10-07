<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Contact\src\Models\ContactAddress;
use Modules\Contact\src\Models\ContactRelationship;

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
 *
 * Hostile review 2026-10-06 (step 0): soft-deleted customers and contacts
 * are planned too (marked `deleted`), "no site" applies only to the
 * company's MAIN address, a site a user already chose is never replaced,
 * several defaults of one type and JSON keys that would be dropped are
 * flagged, non-ISO country values are summarised for the mapping decision,
 * and customers are read in chunks with their rows preloaded.
 */
class ContactAddressBackfillPlanner
{
    public const ACTION_COPY = 'copy';

    public const ACTION_ALREADY_MIGRATED = 'already_migrated';

    public const ACTION_DUPLICATE_EXISTING = 'duplicate_of_existing';

    public const ACTION_SUPERSEDED = 'superseded_by_structured';

    public const ACTION_LINK_EXISTING_SITE = 'link_existing_site';

    public const ACTION_CREATE_SITE = 'create_site_and_link';

    public const ACTION_NO_SITE_AT_COMPANY_ADDRESS = 'no_site_at_company_address';

    public const ACTION_KEEP_EXISTING_SITE = 'keep_existing_site';

    public const ACTION_COPY_TEXT_AS_LINES = 'copy_text_as_lines';

    public const ACTION_NEEDS_REVIEW = 'needs_review';

    /** contact_addresses.line_1..line_3 are varchar(255). */
    private const MAX_LINE_LENGTH = 255;

    private const CHUNK = 500;

    private const TYPE_MAP = ['billing' => 'billing', 'shipping' => 'delivery'];

    /** Keys of an older JSON address that map onto contact_addresses columns. */
    private const JSON_MAPPED_KEYS = ['street', 'address', 'address_line_1', 'street2', 'address_line_2', 'city', 'state', 'zip', 'postal_code', 'country'];

    /** @var array<string, int> non-ISO country value → rows using it */
    private array $countryValues = [];

    /**
     * @return array<string, mixed>
     */
    public function plan(string $tenantId): array
    {
        $this->countryValues = [];

        $existingByOrg = ContactAddress::where('tenant_id', $tenantId)->get()->groupBy('contact_id');
        $chosenSites = $this->chosenSites($tenantId);

        $customerAddresses = [];
        $legacyText = [];
        $personAddresses = [];
        $customersWithoutOrganisation = [];
        $customerCount = 0;
        $deletedCustomers = 0;

        Customer::withTrashed()->orderBy('id')->chunkById(self::CHUNK, function (Collection $customers) use (
            $existingByOrg, $chosenSites, &$customerAddresses, &$legacyText, &$personAddresses, &$customersWithoutOrganisation, &$customerCount, &$deletedCustomers
        ) {
            $ids = $customers->pluck('id');
            $addresses = CustomerAddress::whereIn('customer_id', $ids)->orderBy('id')->get()->groupBy('customer_id');
            $people = CustomerContact::withTrashed()->whereIn('customer_id', $ids)->orderBy('id')->get()->groupBy('customer_id');

            foreach ($customers as $customer) {
                $customerCount++;
                if ($customer->trashed()) {
                    $deletedCustomers++;
                }
                $org = $customer->core_contact_uuid;
                if (! $org) {
                    $customersWithoutOrganisation[] = $customer->id;
                }
                $orgAddresses = $org ? ($existingByOrg[$org] ?? collect()) : collect();
                $structured = $addresses[$customer->id] ?? collect();
                $defaultConflicts = $this->defaultConflicts($structured);

                foreach ($structured as $address) {
                    $customerAddresses[] = $this->planCustomerAddress($customer, $address, $orgAddresses, $defaultConflicts);
                }

                foreach (['billing' => 'billing_address', 'shipping' => 'shipping_address'] as $type => $column) {
                    $value = $customer->{$column};
                    if (is_string($value) && trim($value) !== '') {
                        $legacyText[] = $this->planLegacyText($customer, $type, $value, $structured);
                    }
                }

                $customerPeople = [];
                foreach ($people[$customer->id] ?? [] as $person) {
                    if ($this->hasAddress($person)) {
                        $customerPeople[] = $this->planPersonAddress($customer, $person, $orgAddresses, $structured, $chosenSites);
                    }
                }
                array_push($personAddresses, ...$this->shareNewSites($customerPeople));
            }
        });

        arsort($this->countryValues);

        return [
            'tenant_id' => $tenantId,
            'generated_at' => now()->toIso8601String(),
            'mode' => 'report_only',
            'summary' => [
                'customers' => $customerCount,
                'deleted_customers_included' => $deletedCustomers,
                'customers_without_organisation' => count($customersWithoutOrganisation),
                'customer_addresses' => $this->countActions($customerAddresses),
                'legacy_text_addresses' => $this->countActions($legacyText),
                'person_addresses' => $this->countActions($personAddresses),
                'medical_case_references' => $this->medicalCaseReferences(),
                // Input for the country mapping decision (#1): every value
                // that is not a 2-letter ISO code, with how often it occurs.
                'non_iso_country_values' => $this->countryValues,
            ],
            'customers_without_organisation' => $customersWithoutOrganisation,
            'customer_addresses' => $customerAddresses,
            'legacy_text_addresses' => $legacyText,
            'person_addresses' => $personAddresses,
        ];
    }

    private function planCustomerAddress(Customer $customer, CustomerAddress $address, Collection $orgAddresses, array $defaultConflicts): array
    {
        $row = [
            'customer_id' => $customer->id,
            'customer_deleted' => $customer->trashed(),
            'customer_address_id' => $address->id,
            'source_type' => $address->type,
            'target_type' => self::TYPE_MAP[$address->type] ?? null,
            'is_default' => (bool) $address->is_default,
            'country' => $address->country,
            'summary' => $this->summary($address->street, $address->city, $address->zip, $address->country),
        ];
        $this->tallyCountry($address->country);

        $migrated = $orgAddresses->first(
            fn ($existing) => (int) (($existing->metadata ?? [])['legacy_customer_address_id'] ?? 0) === (int) $address->id
        );

        return match (true) {
            $migrated !== null => $row + ['action' => self::ACTION_ALREADY_MIGRATED, 'contact_address_id' => $migrated->id],
            ! $customer->core_contact_uuid => $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'customer_not_linked_to_organisation'],
            $row['target_type'] === null => $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'type_other_has_no_contact_master_equivalent'],
            ! $this->isIsoCountry($address->country) => $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'country_not_iso_alpha2'],
            in_array($address->id, $defaultConflicts, true) => $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'several_default_addresses'],
            ($dup = $this->findSame($orgAddresses, $row['target_type'], $address->street, $address->street2, $address->city, $address->zip, $address->country)) !== null => $row + ['action' => self::ACTION_DUPLICATE_EXISTING, 'contact_address_id' => $dup->id],
            default => $row + ['action' => self::ACTION_COPY],
        };
    }

    private function planLegacyText(Customer $customer, string $type, string $value, Collection $structured): array
    {
        $row = [
            'customer_id' => $customer->id,
            'customer_deleted' => $customer->trashed(),
            'source_type' => $type,
            'target_type' => self::TYPE_MAP[$type],
            'value' => $value,
        ];

        if ($structured->firstWhere('type', $type) !== null) {
            return $row + ['action' => self::ACTION_SUPERSEDED];
        }

        if (! $customer->core_contact_uuid) {
            return $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'customer_not_linked_to_organisation'];
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            // Revised recommendation #3: free text is split on line breaks
            // into line_1..line_3 without interpreting it (no guessing which
            // line is the city or postcode); the country stays empty.
            $lines = array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: []),
                fn ($line) => $line !== ''
            ));
            if (count($lines) > 3) {
                return $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'more_than_three_lines', 'lines' => $lines];
            }
            if ($this->anyTooLong($lines)) {
                return $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'line_too_long', 'lines' => $lines];
            }

            return $row + ['action' => self::ACTION_COPY_TEXT_AS_LINES, 'lines' => $lines];
        }

        $country = $decoded['country'] ?? null;
        $this->tallyCountry(is_string($country) ? $country : null);

        // An older JSON address (e.g. storefront-shaped) may carry a name,
        // phone or company; copying would silently drop them.
        $dropped = array_values(array_filter(
            array_keys($decoded),
            fn ($key) => ! in_array($key, self::JSON_MAPPED_KEYS, true)
                && is_scalar($decoded[$key]) && trim((string) $decoded[$key]) !== ''
        ));

        return match (true) {
            ! $this->isIsoCountry(is_string($country) ? $country : null) => $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'country_not_iso_alpha2'],
            $dropped !== [] => $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'json_keys_would_be_dropped', 'dropped_keys' => $dropped],
            $this->anyTooLong(array_filter([$decoded['street'] ?? $decoded['address'] ?? $decoded['address_line_1'] ?? null, $decoded['street2'] ?? $decoded['address_line_2'] ?? null], 'is_string')) => $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'line_too_long'],
            default => $row + ['action' => self::ACTION_COPY],
        };
    }

    private function planPersonAddress(Customer $customer, CustomerContact $person, Collection $orgAddresses, Collection $structured, array $chosenSites): array
    {
        $row = [
            'customer_id' => $customer->id,
            'customer_deleted' => $customer->trashed(),
            'customer_contact_id' => $person->id,
            'contact_deleted' => $person->trashed(),
            'person' => $person->name,
            'country' => $person->country,
            'summary' => $this->summary($person->street, $person->city, $person->zip, $person->country),
        ];
        $this->tallyCountry($person->country);

        $org = $customer->core_contact_uuid;
        if (! $org) {
            return $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'customer_not_linked_to_organisation'];
        }
        if (! $person->core_contact_uuid) {
            return $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'person_not_linked_to_contact_master'];
        }

        // A site the user already chose (W3/W4 contact dialog) is never replaced.
        $chosen = $chosenSites[$org.'|'.$person->core_contact_uuid] ?? null;
        if ($chosen !== null) {
            return $row + ['action' => self::ACTION_KEEP_EXISTING_SITE, 'site_address_id' => $chosen];
        }

        $site = $this->findSame($orgAddresses, 'site', $person->street, $person->street2, $person->city, $person->zip, $person->country);
        if ($site !== null) {
            return $row + ['action' => self::ACTION_LINK_EXISTING_SITE, 'site_address_id' => $site->id];
        }

        // "No site" means the company's MAIN address (default billing), so
        // only a person there needs no site.
        $main = $structured->first(fn ($a) => $a->type === 'billing' && $a->is_default) ?? $structured->firstWhere('type', 'billing');
        if ($main !== null && $this->sameAddress($main, $person)) {
            return $row + ['action' => self::ACTION_NO_SITE_AT_COMPANY_ADDRESS, 'matches_company_address_id' => $main->id];
        }
        if ($main === null && is_string($customer->billing_address) && trim($customer->billing_address) !== '') {
            // The main address exists only as older text: cannot be compared
            // without guessing, so a human decides.
            return $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'main_address_is_text_only'];
        }

        // Only a NEW site needs a valid country code.
        if (! $this->isIsoCountry($person->country) && $person->country !== null && $person->country !== '') {
            return $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'country_not_iso_alpha2'];
        }

        // A person at a company delivery/other address works there: that place
        // becomes a site; the match is reported so it can be reviewed.
        $otherCompanyAddress = $structured->first(fn ($a) => $a->id !== $main?->id && $this->sameAddress($a, $person));

        return $row + array_filter([
            'action' => self::ACTION_CREATE_SITE,
            // Named after the address, never after one person: the site may
            // be shared by several people (see shareNewSites()).
            'site_label' => $otherCompanyAddress?->label ?: $this->summary($person->street, $person->city),
            'new_site_key' => $this->key($person->street, $person->street2, $person->city, $person->zip),
            'matches_company_address_id' => $otherCompanyAddress?->id,
            'matches_company_address_type' => $otherCompanyAddress?->type,
        ], fn ($value) => $value !== null);
    }

    /**
     * Revised recommendation #6: people of one company at the same new
     * address share ONE new site, not one copy each.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function shareNewSites(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            if ($row['action'] === self::ACTION_CREATE_SITE) {
                $byKey[$row['new_site_key']][] = $row['customer_contact_id'];
            }
        }

        return array_map(function (array $row) use ($byKey) {
            if ($row['action'] === self::ACTION_CREATE_SITE) {
                $row['people_sharing_site'] = $byKey[$row['new_site_key']];
            }

            return $row;
        }, $rows);
    }

    /**
     * Ids of default addresses in a type that has more than one default
     * (older data: the one-default rule only runs when saved via the model).
     *
     * @return list<int>
     */
    private function defaultConflicts(Collection $structured): array
    {
        return $structured->where('is_default', true)
            ->groupBy('type')
            ->filter(fn ($group) => $group->count() > 1)
            ->flatten()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return array<string, string> "org|person" → site_address_id already set */
    private function chosenSites(string $tenantId): array
    {
        return ContactRelationship::where('tenant_id', $tenantId)
            ->where('relationship_type', 'org_person')
            ->whereNotNull('site_address_id')
            ->get(['contact_id', 'related_contact_id', 'site_address_id'])
            ->mapWithKeys(fn ($r) => [$r->contact_id.'|'.$r->related_contact_id => $r->site_address_id])
            ->all();
    }

    private function findSame(Collection $orgAddresses, string $type, ?string $street, ?string $street2, ?string $city, ?string $zip, ?string $country)
    {
        $key = $this->key($street, $street2, $city, $zip);

        return $orgAddresses->first(
            fn ($existing) => $existing->address_type === $type
                && $this->key($existing->line_1, $existing->line_2, $existing->city, $existing->postal_code) === $key
                && $this->countriesCompatible($existing->country, $country)
        );
    }

    private function sameAddress(CustomerAddress $address, CustomerContact $person): bool
    {
        return $this->key($address->street, $address->street2, $address->city, $address->zip)
                === $this->key($person->street, $person->street2, $person->city, $person->zip)
            && $this->countriesCompatible($address->country, $person->country);
    }

    /** Same street/city/postcode in two different countries is not the same place. */
    private function countriesCompatible(?string $a, ?string $b): bool
    {
        $a = strtolower(trim((string) $a));
        $b = strtolower(trim((string) $b));

        return $a === '' || $b === '' || $a === $b;
    }

    /** Normalised comparison key: letters and digits only, lower case. */
    private function key(?string ...$parts): string
    {
        return implode('|', array_map(
            fn ($part) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $part)),
            $parts
        ));
    }

    /** @param  array<int, string>  $lines */
    private function anyTooLong(array $lines): bool
    {
        foreach ($lines as $line) {
            if (mb_strlen($line) > self::MAX_LINE_LENGTH) {
                return true;
            }
        }

        return false;
    }

    private function tallyCountry(?string $country): void
    {
        $country = trim((string) $country);
        if ($country !== '' && ! $this->isIsoCountry($country)) {
            $this->countryValues[$country] = ($this->countryValues[$country] ?? 0) + 1;
        }
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
