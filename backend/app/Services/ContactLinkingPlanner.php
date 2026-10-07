<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Contact\src\Models\ContactAddress;
use Modules\Contact\src\Models\ContactRegistration;
use Modules\Contact\src\Models\ContactRelationship;
use Modules\Contact\src\Models\CoreContact;
use Modules\Contact\src\Services\ContactTaxonomyService;
use Modules\Contact\src\Support\ContactTaxonomyRegistry;

/**
 * ADR-003B W6 pre-step — REPORT ONLY. Predicts what linking every unlinked
 * customer and customer contact to the Contact Master would do, before any
 * bulk run exists (CLAUDE.md: no data change without a reviewed plan).
 *
 * It mirrors the live linking code without writing:
 *  - CustomerContactSyncService::ensureOrganisationContact() always CREATES
 *    a new organisation (it never matches), so the report lists existing
 *    Contact Master records that look like the same organisation.
 *  - Person linking uses matchPersonIdentity(): tier 1 (same email or
 *    mobile) matches across the WHOLE tenant and ignores names, so the
 *    report flags tier-1 links where the name differs or the person already
 *    belongs to another organisation, and unlinked contacts that share an
 *    email/mobile with each other (they would collapse into one person).
 *  - Legacy category/type values the sync would reject (unknown taxonomy)
 *    are flagged.
 *
 * W6 step 3 (linking execution plan) adds three cases the live code would
 * get silently wrong. A foreign key (2026_08_13_000002) rules out links to
 * records that do not exist, so these are the only "bad" links:
 *  - `linked_contact_deleted`: the link points at a SOFT-DELETED record;
 *    find() cannot see it, so the sync would create a new record and orphan
 *    the old one with its addresses, relationships and sites;
 *  - `linked_to_merged_duplicate`: the link points at a record merged into
 *    another (`merged_into_id`); the sync keeps writing to the loser;
 *  - `target_person_has_merge_damage`: an auto-link target already carries
 *    suspect/unverifiable links from the matcher bug fixed in 9e389eb
 *    (PersonMergeDamagePlanner) — step 4 repairs it first.
 *
 * Second hostile review of the linking plan (2026-10-06):
 *  - a customer merged into another (CustomerMergeService: inactive, still
 *    linked to the losing record whose survivor another customer links to)
 *    keeps its link by design: `keep_merged_customer_link`;
 *  - category/type codes are checked like the Contact Master checks them
 *    (ContactTaxonomyService::assertValidCode — seeded, or in the tenant's
 *    healthcare/generic vocabulary): `taxonomy_code_not_valid_for_tenant`;
 *  - the run is SEQUENTIAL: people the run would create earlier are match
 *    targets for later contacts, compared exactly as matchPersonIdentity()
 *    compares (lower-case email; mobile/phone digits against the stored
 *    value as typed): `person_id = batch:<customer_contact_id>`;
 *  - every row carries a `fingerprint` of its source data, so a review
 *    decision can be refused once the row has changed;
 *  - a contact of an INDIVIDUAL customer matching the customer's own person
 *    record: `would_link_to_customer_itself`;
 *  - company registration numbers: linked customers and Contact Master
 *    registrations with the same number are candidates; unlinked customers
 *    sharing one are flagged `same_registration_number_in_batch`.
 *    (Encrypted ContactIdentifier values are not compared.)
 *
 * Decision inputs (review of the L1–L10 recommendations):
 *  - `customer_vendor_parties` (L2): records shared by a customer and a
 *    vendor with the fields they disagree on, and customer/vendor pairs with
 *    the same registration number or name on separate records;
 *  - `dependents` on `linked_contact_deleted` rows (L3): addresses,
 *    relationships and sites still hanging off the deleted record.
 */
class ContactLinkingPlanner
{
    public const ACTION_CREATE_ORGANISATION = 'create_organisation';

    public const ACTION_AUTO_LINK = 'auto_link_existing_person';

    public const ACTION_CREATE_PERSON = 'create_person';

    public const ACTION_CREATE_PERSON_WITH_CANDIDATE = 'create_person_with_candidate';

    public const ACTION_NEEDS_REVIEW = 'needs_review';

    public const ACTION_KEEP_MERGED_CUSTOMER = 'keep_merged_customer_link';

    public function __construct(
        private readonly CustomerContactSyncService $sync,
        private readonly PersonMergeDamagePlanner $damage,
        private readonly ContactTaxonomyService $taxonomy,
    ) {}

    /** @var array<string, true> soft-deleted Contact Master ids */
    private array $deletedIds = [];

    /** @var array<string, string|null> merged duplicate id → merged_into_id */
    private array $mergedIds = [];

    /** @var array<string, string> person id → merge-damage severity */
    private array $damagedPeople = [];

    /** @var array<string, list<string>> registration number → Contact Master ids */
    private array $registrationIndex = [];

    /** @var array<string, list<int>> registration number → unlinked customer ids */
    private array $unlinkedRegistrations = [];

    /** @var list<array<string, mixed>> people the run would create, in run order */
    private array $batchPeople = [];

    /** @var array<int, Customer> */
    private array $customersById = [];

    /**
     * @return array<string, mixed>
     */
    public function plan(string $tenantId): array
    {
        $links = $this->organisationLinks();

        // Step 0 (performance): the Contact Master is read ONCE and indexed,
        // instead of reloading every record for each unlinked customer.
        $existingIds = array_flip(CoreContact::where('tenant_id', $tenantId)->where('is_duplicate', false)->pluck('id')->all());
        $this->deletedIds = array_flip(CoreContact::onlyTrashed()->where('tenant_id', $tenantId)->pluck('id')->all());
        $this->mergedIds = CoreContact::where('tenant_id', $tenantId)->where('is_duplicate', true)->pluck('merged_into_id', 'id')->all();
        $this->damagedPeople = collect($this->damage->plan($tenantId)['people'])->pluck('severity', 'person_id')->all();
        $this->batchPeople = [];
        $this->buildRegistrationIndexes($tenantId, $existingIds);
        $survivorCustomers = Customer::whereNotNull('core_contact_uuid')->pluck('id', 'core_contact_uuid')->all();
        $index = $this->organisationIndex($tenantId);
        $this->personOrganisations = ContactRelationship::where('tenant_id', $tenantId)
            ->where('relationship_type', 'org_person')
            ->get(['contact_id', 'related_contact_id'])
            ->groupBy('related_contact_id')
            ->map(fn ($rows) => $rows->pluck('contact_id')->unique()->values()->all())
            ->all();

        // Step 0: soft-deleted customers and contacts are planned too, marked.
        $organisations = [];
        Customer::withTrashed()->orderBy('id')->chunkById(500, function ($customers) use (&$organisations, $existingIds, $index, $links, $survivorCustomers) {
            foreach ($customers as $customer) {
                $this->customersById[$customer->id] = $customer;
                if ($customer->core_contact_uuid && isset($existingIds[$customer->core_contact_uuid])) {
                    continue;
                }
                $base = [
                    'customer_id' => $customer->id,
                    'customer_deleted' => $customer->trashed(),
                    'customer_name' => $customer->name,
                    'fingerprint' => $this->customerFingerprint($customer),
                ];
                $organisations[] = $this->mergedCustomer($customer, $base, $survivorCustomers)
                    ?? $this->badLink($customer->core_contact_uuid, $base)
                    ?? $this->planOrganisation($customer, $index, $links) + ['fingerprint' => $base['fingerprint']];
            }
        });

        $unlinkedPeople = collect();
        CustomerContact::withTrashed()
            ->with(['customer' => fn ($query) => $query->withTrashed()])
            ->orderBy('id')
            ->chunkById(500, function ($contacts) use (&$unlinkedPeople, $existingIds) {
                $unlinkedPeople = $unlinkedPeople->merge($contacts->filter(
                    fn (CustomerContact $contact) => ! $contact->core_contact_uuid || ! isset($existingIds[$contact->core_contact_uuid])
                ));
            });

        $sharedInBatch = $this->sharedIdentifiers($unlinkedPeople);

        $people = [];
        foreach ($unlinkedPeople as $contact) {
            $people[] = $this->planPerson($tenantId, $contact, $links, $sharedInBatch);
        }

        return [
            'tenant_id' => $tenantId,
            'generated_at' => now()->toIso8601String(),
            'mode' => 'report_only',
            'summary' => [
                'organisations' => $this->countActions($organisations),
                'people' => $this->countActions($people),
                'deleted_customers_included' => count(array_filter($organisations, fn ($row) => $row['customer_deleted'])),
                'deleted_contacts_included' => count(array_filter($people, fn ($row) => $row['contact_deleted'])),
            ],
            'organisations' => $organisations,
            'people' => $people,
            'customer_vendor_parties' => $this->customerVendorParties(),
        ];
    }

    /** @var array<string, list<string>> person id → organisation ids (org_person) */
    private array $personOrganisations = [];

    /**
     * Existing Contact Master organisations/people indexed by entity type and
     * normalised name, email and phone.
     *
     * @return array<string, array<string, array<string, list<CoreContact>>>>
     */
    private function organisationIndex(string $tenantId): array
    {
        $index = [];
        foreach (CoreContact::where('tenant_id', $tenantId)->where('is_duplicate', false)->cursor() as $contact) {
            foreach (['name' => $this->normalizeName($contact->display_name), 'email' => $this->normalizeEmail($contact->email), 'phone' => $this->normalizePhone($contact->phone)] as $field => $value) {
                if ($value !== null) {
                    $index[$contact->contact_type][$field][$value][] = $contact;
                }
            }
        }

        return $index;
    }

    private function planOrganisation(Customer $customer, array $index, array $links): array
    {
        $type = \Modules\Contact\src\Services\ContactMasterService::normalizeEntityType($customer->type) === 'individual'
            ? 'individual' : 'company';

        $found = [];
        $matchedOn = [];
        foreach (['name' => $this->normalizeName($customer->name), 'email' => $this->normalizeEmail($customer->email), 'phone' => $this->normalizePhone($customer->phone)] as $field => $value) {
            foreach ($value !== null ? ($index[$type][$field][$value] ?? []) : [] as $contact) {
                $found[$contact->id] = $contact->display_name;
                $matchedOn[$contact->id][] = $field;
            }
        }

        $registration = $this->normalizeRegistration($customer->company_registration_number);
        if ($registration !== null) {
            foreach ($this->registrationIndex[$registration] ?? [] as $contactId => $displayName) {
                $found[$contactId] ??= $displayName;
                $matchedOn[$contactId][] = 'registration_number';
            }
        }

        $candidates = [];
        foreach ($found as $contactId => $displayName) {
            $candidates[] = [
                'contact_id' => $contactId,
                'display_name' => $displayName,
                'matched_on' => array_values(array_unique($matchedOn[$contactId])),
                'linked_to' => $links[$contactId] ?? [],
            ];
        }

        $flags = [];
        $sameInBatch = $registration !== null ? ($this->unlinkedRegistrations[$registration] ?? []) : [];
        if (count($sameInBatch) > 1) {
            $flags['same_registration_number_in_batch'] = $sameInBatch;
        }

        $row = [
            'customer_id' => $customer->id,
            'customer_deleted' => $customer->trashed(),
            'customer_name' => $customer->name,
            'contact_type' => $type,
        ];

        if ($candidates !== []) {
            return $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'possible_existing_organisation', 'candidates' => $candidates]
                + ($flags !== [] ? ['flags' => $flags] : []);
        }

        return $flags === []
            ? $row + ['action' => self::ACTION_CREATE_ORGANISATION]
            : $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => implode(',', array_keys($flags)), 'flags' => $flags];
    }

    private function planPerson(string $tenantId, CustomerContact $contact, array $links, array $sharedInBatch): array
    {
        $orgUuid = $contact->customer?->core_contact_uuid;
        $row = [
            'customer_contact_id' => $contact->id,
            'contact_deleted' => $contact->trashed(),
            'customer_id' => $contact->customer_id,
            'name' => $contact->name,
            'customer_needs_organisation' => ! $orgUuid,
            'fingerprint' => $this->contactFingerprint($contact),
        ];

        if ($bad = $this->badLink($contact->core_contact_uuid, $row)) {
            return $bad;
        }

        $legacy = $this->sync->transformLegacyPayload($contact->contact_category, $contact->contact_type);
        $flags = [];
        if (! empty($legacy['unknown_values'])) {
            $flags['unknown_taxonomy_values'] = array_values($legacy['unknown_values']);
        }
        if ($invalid = $this->codesNotValidForTenant($legacy)) {
            $flags['taxonomy_code_not_valid_for_tenant'] = $invalid;
        }

        foreach (['email' => $this->normalizeEmail($contact->email), 'mobile' => $this->normalizePhone($contact->mobile)] as $key => $value) {
            if ($value !== null && isset($sharedInBatch[$key][$value])) {
                $flags['shared_'.$key.'_in_batch'] = $sharedInBatch[$key][$value];
            }
        }

        $match = $this->sync->matchPersonIdentity($tenantId, $orgUuid, $contact->name, $contact->email, $contact->mobile, $contact->phone);
        $person = $match['contact'];

        // The run is sequential: people it creates earlier are match targets
        // too, in the live matcher's tier order (DB first within a tier).
        $orgKey = $orgUuid ?? 'new:'.$contact->customer_id;
        $batch = null;
        if ($match['tier'] !== 1) {
            $batch = $this->matchBatchPerson($contact, $orgKey, 1)
                ?? ($match['tier'] !== 2 ? $this->matchBatchPerson($contact, $orgKey, 2) : null)
                ?? ($match['tier'] === null ? $this->matchBatchPerson($contact, $orgKey, 3) : null);
        }

        if ($batch !== null && in_array($batch['tier'], [1, 2], true)) {
            $row += [
                'action' => self::ACTION_AUTO_LINK,
                'tier' => $batch['tier'],
                'person_id' => 'batch:'.$batch['person']['source_id'],
                'person_name' => $batch['person']['name'],
            ];
            if ($this->normalizeName($batch['person']['name']) !== $this->normalizeName($contact->name)) {
                $flags['matches_person_created_earlier_in_batch'] = $batch['person']['source_id'];
                $flags['name_differs'] = true;
            }
        } elseif ($person && in_array($match['tier'], [1, 2], true)) {
            $row += [
                'action' => self::ACTION_AUTO_LINK,
                'tier' => $match['tier'],
                'person_id' => $person->id,
                'person_name' => $person->display_name,
                'matched_on' => $this->matchedOn($contact, $person),
            ];
            if ($row['matched_on'] === []) {
                // matchPersonIdentity() builds an empty condition group when
                // the contact has no email/mobile (tier 1) or no
                // email/mobile/phone (tier 2) and then matches ANY person.
                $flags['matched_without_shared_identifier'] = true;
            }
            if ($this->normalizeName($person->display_name) !== $this->normalizeName($contact->name)) {
                $flags['name_differs'] = true;
            }
            $customer = $this->customersById[$contact->customer_id] ?? null;
            if ($customer && $customer->core_contact_uuid === $person->id) {
                $flags['would_link_to_customer_itself'] = true;
            }
            if (isset($this->damagedPeople[$person->id])) {
                $flags['target_person_has_merge_damage'] = $this->damagedPeople[$person->id];
            }
            $otherOrganisations = array_values(array_diff($this->personOrganisations[$person->id] ?? [], array_filter([$orgUuid])));
            if ($otherOrganisations !== []) {
                $flags['person_belongs_to_other_organisations'] = array_map(
                    fn ($org) => ['organisation_id' => $org, 'linked_to' => $links[$org] ?? []],
                    $otherOrganisations
                );
            }
        } elseif ($person && $match['tier'] === 3) {
            $row += ['action' => self::ACTION_CREATE_PERSON_WITH_CANDIDATE, 'candidate_person_id' => $person->id];
        } elseif ($batch !== null) {
            $row += ['action' => self::ACTION_CREATE_PERSON_WITH_CANDIDATE, 'candidate_person_id' => 'batch:'.$batch['person']['source_id']];
        } else {
            $row += ['action' => self::ACTION_CREATE_PERSON];
        }

        if ($flags !== []) {
            $row['predicted_action'] = $row['action'];
            $row['action'] = self::ACTION_NEEDS_REVIEW;
            $row['reason'] = implode(',', array_keys($flags));
            $row['flags'] = $flags;
        }

        // Only rows the run would actually execute create a person.
        if (in_array($row['action'], [self::ACTION_CREATE_PERSON, self::ACTION_CREATE_PERSON_WITH_CANDIDATE], true)) {
            $this->batchPeople[] = [
                'source_id' => $contact->id,
                'org_key' => $orgKey,
                'name' => $contact->name,
                'email' => $contact->email,
                'mobile' => $contact->mobile,
                'phone' => $contact->phone,
            ];
        }

        return $row;
    }

    /**
     * matchPersonIdentity() against the people the run would create earlier:
     * tier 1 email/mobile anywhere, tier 2 email/mobile/phone in the same
     * organisation, tier 3 same name in the same organisation. Stored values
     * are compared as the database compares them (LOWER(email) = ?,
     * mobile/phone = digits), so "019-888 7777" stored does not match.
     *
     * @return array{person: array<string, mixed>, tier: int}|null
     */
    private function matchBatchPerson(CustomerContact $contact, string $orgKey, int $tier): ?array
    {
        $email = $this->normalizeEmail($contact->email);
        $mobile = $this->normalizePhone($contact->mobile);
        $phone = $this->normalizePhone($contact->phone);
        $name = $this->normalizeName($contact->name);

        foreach ($this->batchPeople as $person) {
            $sameEmail = $email !== null && strtolower((string) $person['email']) === $email;
            $sameMobile = $mobile !== null && (string) $person['mobile'] === $mobile;
            $samePhone = $phone !== null && (string) $person['phone'] === $phone;
            $sameOrg = $person['org_key'] === $orgKey;

            $hit = match ($tier) {
                1 => $sameEmail || $sameMobile,
                2 => $sameOrg && ($sameEmail || $sameMobile || $samePhone),
                3 => $sameOrg && $name !== null && $this->normalizeName($person['name']) === $name,
                default => false,
            };
            if ($hit) {
                return ['person' => $person, 'tier' => $tier];
            }
        }

        return null;
    }

    /**
     * Codes the Contact Master would reject for this tenant (the same check
     * assignClassification / updateRelationship / setRelationshipRoles run).
     *
     * @param  array<string, mixed>  $legacy
     * @return list<string>
     */
    private function codesNotValidForTenant(array $legacy): array
    {
        $checks = [];
        if (! empty($legacy['functional_category_code'])) {
            $checks[] = [ContactTaxonomyRegistry::TYPE_FUNCTIONAL_CATEGORY, $legacy['functional_category_code']];
        }
        if (! empty($legacy['classification_code'])) {
            $checks[] = [ContactTaxonomyRegistry::TYPE_CLASSIFICATION, $legacy['classification_code']];
        }
        foreach ((array) ($legacy['persistent_role_codes'] ?? []) as $role) {
            $checks[] = [ContactTaxonomyRegistry::TYPE_PERSISTENT_ROLE, $role];
        }

        $invalid = [];
        foreach ($checks as [$type, $code]) {
            try {
                $this->taxonomy->assertValidCode($type, (string) $code);
            } catch (\DomainException) {
                $invalid[] = (string) $code;
            }
        }

        return array_values(array_unique($invalid));
    }

    /**
     * A customer merged into another by CustomerMergeService: inactive, linked
     * to the losing Contact Master record, whose survivor another customer
     * links to. The link is kept by design — moving it to the survivor would
     * make two customers overwrite one organisation.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, int>  $survivorCustomers  org id → customer id
     * @return array<string, mixed>|null
     */
    private function mergedCustomer(Customer $customer, array $base, array $survivorCustomers): ?array
    {
        $uuid = $customer->core_contact_uuid;
        if ($uuid === null || ! array_key_exists($uuid, $this->mergedIds) || $customer->is_active) {
            return null;
        }

        $survivor = $this->mergedIds[$uuid];
        $seen = [];
        while ($survivor !== null && array_key_exists($survivor, $this->mergedIds) && ! isset($seen[$survivor])) {
            $seen[$survivor] = true;
            $survivor = $this->mergedIds[$survivor];
        }

        $survivorCustomer = $survivor !== null ? ($survivorCustomers[$survivor] ?? null) : null;
        if ($survivorCustomer === null || (int) $survivorCustomer === (int) $customer->id) {
            return null;
        }

        return $base + [
            'action' => self::ACTION_KEEP_MERGED_CUSTOMER,
            'linked_contact_id' => $uuid,
            'merged_into_id' => $survivor,
            'merged_into_customer_id' => (int) $survivorCustomer,
        ];
    }

    /**
     * @param  array<string, true>  $existingIds
     */
    private function buildRegistrationIndexes(string $tenantId, array $existingIds): void
    {
        $this->registrationIndex = [];
        $this->unlinkedRegistrations = [];

        $names = CoreContact::where('tenant_id', $tenantId)->where('is_duplicate', false)->pluck('display_name', 'id')->all();

        Customer::withTrashed()->whereNotNull('company_registration_number')->orderBy('id')
            ->get(['id', 'company_registration_number', 'core_contact_uuid'])
            ->each(function (Customer $customer) use ($existingIds, $names) {
                $number = $this->normalizeRegistration($customer->company_registration_number);
                if ($number === null) {
                    return;
                }
                if ($customer->core_contact_uuid && isset($existingIds[$customer->core_contact_uuid])) {
                    $this->registrationIndex[$number][$customer->core_contact_uuid] = $names[$customer->core_contact_uuid] ?? null;
                } else {
                    $this->unlinkedRegistrations[$number][] = (int) $customer->id;
                }
            });

        if (Schema::hasTable('contact_registrations')) {
            ContactRegistration::where('tenant_id', $tenantId)->get(['contact_id', 'registration_value'])
                ->each(function (ContactRegistration $registration) use ($existingIds, $names) {
                    $number = $this->normalizeRegistration($registration->registration_value);
                    if ($number !== null && isset($existingIds[$registration->contact_id])) {
                        $this->registrationIndex[$number][$registration->contact_id] = $names[$registration->contact_id] ?? null;
                    }
                });
        }
    }

    private function normalizeRegistration(?string $value): ?string
    {
        $value = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $value));

        return $value === '' ? null : $value;
    }

    private function customerFingerprint(Customer $customer): string
    {
        return hash('sha256', json_encode([
            $customer->id, $customer->name, $customer->type, $customer->email, $customer->phone, $customer->mobile,
            $customer->company_registration_number, $customer->core_contact_uuid, (bool) $customer->is_active,
            $customer->deleted_at?->toIso8601String(),
        ]));
    }

    private function contactFingerprint(CustomerContact $contact): string
    {
        return hash('sha256', json_encode([
            $contact->id, $contact->customer_id, $contact->name, $contact->email, $contact->mobile, $contact->phone,
            $contact->contact_category, $contact->contact_type, $contact->department_name, $contact->core_contact_uuid,
            $contact->deleted_at?->toIso8601String(),
        ]));
    }

    /**
     * A link to a soft-deleted or merged-duplicate record: never relinked or
     * replaced automatically.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function badLink(?string $uuid, array $row): ?array
    {
        if ($uuid === null) {
            return null;
        }
        if (array_key_exists($uuid, $this->mergedIds)) {
            return $row + [
                'action' => self::ACTION_NEEDS_REVIEW,
                'reason' => 'linked_to_merged_duplicate',
                'linked_contact_id' => $uuid,
                'merged_into_id' => $this->mergedIds[$uuid],
            ];
        }
        if (isset($this->deletedIds[$uuid])) {
            return $row + [
                'action' => self::ACTION_NEEDS_REVIEW,
                'reason' => 'linked_contact_deleted',
                'linked_contact_id' => $uuid,
                'dependents' => $this->dependents($uuid),
            ];
        }

        return null;
    }

    /**
     * L3: what still hangs off a soft-deleted record (live rows only).
     *
     * @return array{addresses: int, relationships: int, sites: int}
     */
    private function dependents(string $contactId): array
    {
        $addresses = ContactAddress::where('contact_id', $contactId);

        return [
            'addresses' => (clone $addresses)->where('address_type', '!=', 'site')->count(),
            'relationships' => ContactRelationship::where(fn ($q) => $q->where('contact_id', $contactId)->orWhere('related_contact_id', $contactId))->count(),
            'sites' => (clone $addresses)->where('address_type', 'site')->count(),
        ];
    }

    /**
     * L2: companies that are both a customer and a vendor (live rows).
     *
     * @return array<string, mixed>
     */
    private function customerVendorParties(): array
    {
        if (! Schema::hasTable('vendors') || ! Schema::hasColumn('vendors', 'core_contact_uuid')) {
            return ['summary' => ['already_shared' => 0, 'separate_records' => 0], 'already_shared' => [], 'separate_records' => []];
        }

        $columns = ['id', 'name', 'email', 'phone', 'website', 'company_registration_number', 'core_contact_uuid'];
        $customers = Customer::query()->orderBy('id')->get($columns);
        $vendors = Vendor::query()->orderBy('id')->get($columns);

        $shared = [];
        $customersByOrg = $customers->whereNotNull('core_contact_uuid')->groupBy('core_contact_uuid');
        foreach ($vendors->whereNotNull('core_contact_uuid')->groupBy('core_contact_uuid') as $orgId => $orgVendors) {
            if (! isset($customersByOrg[$orgId])) {
                continue;
            }
            $differing = [];
            foreach ($customersByOrg[$orgId] as $customer) {
                foreach ($orgVendors as $vendor) {
                    $differing = array_merge($differing, $this->differingIdentity($customer, $vendor));
                }
            }
            $shared[] = [
                'contact_id' => $orgId,
                'customer_ids' => $customersByOrg[$orgId]->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'vendor_ids' => $orgVendors->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'differing_fields' => array_values(array_unique($differing)),
            ];
        }

        $vendorsByRegistration = [];
        $vendorsByName = [];
        foreach ($vendors as $vendor) {
            if ($number = $this->normalizeRegistration($vendor->company_registration_number)) {
                $vendorsByRegistration[$number][] = $vendor;
            }
            if ($name = $this->normalizeName($vendor->name)) {
                $vendorsByName[$name][] = $vendor;
            }
        }

        $separate = [];
        foreach ($customers as $customer) {
            $matches = [];
            foreach ($vendorsByRegistration[$this->normalizeRegistration($customer->company_registration_number) ?? ''] ?? [] as $vendor) {
                $matches[$vendor->id]['vendor'] = $vendor;
                $matches[$vendor->id]['on'][] = 'registration_number';
            }
            foreach ($vendorsByName[$this->normalizeName($customer->name) ?? ''] ?? [] as $vendor) {
                $matches[$vendor->id]['vendor'] = $vendor;
                $matches[$vendor->id]['on'][] = 'name';
            }
            foreach ($matches as $match) {
                $vendor = $match['vendor'];
                if ($customer->core_contact_uuid !== null && $customer->core_contact_uuid === $vendor->core_contact_uuid) {
                    continue;
                }
                $separate[] = [
                    'customer_id' => (int) $customer->id,
                    'customer_name' => $customer->name,
                    'customer_contact_id' => $customer->core_contact_uuid,
                    'vendor_id' => (int) $vendor->id,
                    'vendor_name' => $vendor->name,
                    'vendor_contact_id' => $vendor->core_contact_uuid,
                    'matched_on' => $match['on'],
                ];
            }
        }

        return [
            'summary' => ['already_shared' => count($shared), 'separate_records' => count($separate)],
            'already_shared' => $shared,
            'separate_records' => $separate,
        ];
    }

    /** @return list<string> identity fields on which a customer and a vendor disagree */
    private function differingIdentity(Customer $customer, Vendor $vendor): array
    {
        $differing = [];
        foreach ([
            'name' => fn ($v) => $this->normalizeName($v),
            'email' => fn ($v) => $this->normalizeEmail($v),
            'phone' => fn ($v) => $this->normalizePhone($v),
            'website' => fn ($v) => ($w = strtolower(trim((string) $v))) === '' ? null : $w,
            'company_registration_number' => fn ($v) => $this->normalizeRegistration($v),
        ] as $field => $normalize) {
            if ($normalize($customer->{$field}) !== $normalize($vendor->{$field})) {
                $differing[] = $field;
            }
        }

        return $differing;
    }

    /**
     * Unlinked contacts that share an email/mobile but have different names:
     * a bulk run would merge them into one person (the first creates it, the
     * rest match it at tier 1).
     *
     * @return array{email: array<string, list<int>>, mobile: array<string, list<int>>}
     */
    private function sharedIdentifiers($contacts): array
    {
        $shared = ['email' => [], 'mobile' => []];
        foreach (['email' => fn ($c) => $this->normalizeEmail($c->email), 'mobile' => fn ($c) => $this->normalizePhone($c->mobile)] as $key => $value) {
            foreach ($contacts->groupBy($value) as $identifier => $group) {
                if ($identifier === '' || $group->count() < 2) {
                    continue;
                }
                if ($group->map(fn ($c) => $this->normalizeName($c->name))->unique()->count() > 1) {
                    $shared[$key][$identifier] = $group->pluck('id')->values()->all();
                }
            }
        }

        return $shared;
    }

    private function matchedOn(CustomerContact $contact, CoreContact $person): array
    {
        $on = [];
        if ($this->normalizeEmail($contact->email) !== null && $this->normalizeEmail($contact->email) === $this->normalizeEmail($person->email)) {
            $on[] = 'email';
        }
        if ($this->normalizePhone($contact->mobile) !== null && $this->normalizePhone($contact->mobile) === $this->normalizePhone($person->mobile)) {
            $on[] = 'mobile';
        }
        if ($this->normalizePhone($contact->phone) !== null && $this->normalizePhone($contact->phone) === $this->normalizePhone($person->phone)) {
            $on[] = 'phone';
        }

        return $on;
    }

    /**
     * Contact Master id → which customers / vendors / suppliers point at it.
     *
     * @return array<string, list<string>>
     */
    private function organisationLinks(): array
    {
        $links = [];
        foreach (['customers' => 'customer', 'vendors' => 'vendor', 'suppliers' => 'supplier'] as $table => $label) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'core_contact_uuid')) {
                continue;
            }
            foreach (DB::table($table)->whereNotNull('core_contact_uuid')->get(['id', 'core_contact_uuid']) as $row) {
                $links[$row->core_contact_uuid][] = $label.':'.$row->id;
            }
        }

        return $links;
    }

    private function normalizeName(?string $name): ?string
    {
        $name = preg_replace('/\s+/', ' ', strtolower(trim((string) $name)));

        return $name === '' ? null : $name;
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    private function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return $digits === '' ? null : $digits;
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
}
