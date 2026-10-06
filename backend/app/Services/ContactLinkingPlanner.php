<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Contact\src\Models\ContactRelationship;
use Modules\Contact\src\Models\CoreContact;

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
 */
class ContactLinkingPlanner
{
    public const ACTION_CREATE_ORGANISATION = 'create_organisation';

    public const ACTION_AUTO_LINK = 'auto_link_existing_person';

    public const ACTION_CREATE_PERSON = 'create_person';

    public const ACTION_CREATE_PERSON_WITH_CANDIDATE = 'create_person_with_candidate';

    public const ACTION_NEEDS_REVIEW = 'needs_review';

    public function __construct(private readonly CustomerContactSyncService $sync) {}

    /**
     * @return array<string, mixed>
     */
    public function plan(string $tenantId): array
    {
        $links = $this->organisationLinks();

        // Step 0 (performance): the Contact Master is read ONCE and indexed,
        // instead of reloading every record for each unlinked customer.
        $existingIds = array_flip(CoreContact::where('tenant_id', $tenantId)->pluck('id')->all());
        $index = $this->organisationIndex($tenantId);
        $this->personOrganisations = ContactRelationship::where('tenant_id', $tenantId)
            ->where('relationship_type', 'org_person')
            ->get(['contact_id', 'related_contact_id'])
            ->groupBy('related_contact_id')
            ->map(fn ($rows) => $rows->pluck('contact_id')->unique()->values()->all())
            ->all();

        // Step 0: soft-deleted customers and contacts are planned too, marked.
        $organisations = [];
        Customer::withTrashed()->orderBy('id')->chunkById(500, function ($customers) use (&$organisations, $existingIds, $index, $links) {
            foreach ($customers as $customer) {
                if ($customer->core_contact_uuid && isset($existingIds[$customer->core_contact_uuid])) {
                    continue;
                }
                $organisations[] = $this->planOrganisation($customer, $index, $links);
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
        foreach (['name' => $this->normalizeName($customer->name), 'email' => $this->normalizeEmail($customer->email), 'phone' => $this->normalizePhone($customer->phone)] as $field => $value) {
            foreach ($value !== null ? ($index[$type][$field][$value] ?? []) : [] as $contact) {
                $found[$contact->id] = $contact;
            }
        }

        $candidates = array_values(array_map(fn (CoreContact $contact) => [
            'contact_id' => $contact->id,
            'display_name' => $contact->display_name,
            'linked_to' => $links[$contact->id] ?? [],
        ], $found));

        $row = [
            'customer_id' => $customer->id,
            'customer_deleted' => $customer->trashed(),
            'customer_name' => $customer->name,
            'contact_type' => $type,
            'dangling_link' => $customer->core_contact_uuid !== null,
        ];

        return $candidates === []
            ? $row + ['action' => self::ACTION_CREATE_ORGANISATION]
            : $row + ['action' => self::ACTION_NEEDS_REVIEW, 'reason' => 'possible_existing_organisation', 'candidates' => $candidates];
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
        ];

        $legacy = $this->sync->transformLegacyPayload($contact->contact_category, $contact->contact_type);
        $flags = [];
        if (! empty($legacy['unknown_values'])) {
            $flags['unknown_taxonomy_values'] = array_values($legacy['unknown_values']);
        }

        foreach (['email' => $this->normalizeEmail($contact->email), 'mobile' => $this->normalizePhone($contact->mobile)] as $key => $value) {
            if ($value !== null && isset($sharedInBatch[$key][$value])) {
                $flags['shared_'.$key.'_in_batch'] = $sharedInBatch[$key][$value];
            }
        }

        $match = $this->sync->matchPersonIdentity($tenantId, $orgUuid, $contact->name, $contact->email, $contact->mobile, $contact->phone);
        $person = $match['contact'];

        if ($person && in_array($match['tier'], [1, 2], true)) {
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
            $otherOrganisations = array_values(array_diff($this->personOrganisations[$person->id] ?? [], array_filter([$orgUuid])));
            if ($otherOrganisations !== []) {
                $flags['person_belongs_to_other_organisations'] = array_map(
                    fn ($org) => ['organisation_id' => $org, 'linked_to' => $links[$org] ?? []],
                    $otherOrganisations
                );
            }
        } elseif ($person && $match['tier'] === 3) {
            $row += ['action' => self::ACTION_CREATE_PERSON_WITH_CANDIDATE, 'candidate_person_id' => $person->id];
        } else {
            $row += ['action' => self::ACTION_CREATE_PERSON];
        }

        if ($flags !== []) {
            $row['predicted_action'] = $row['action'];
            $row['action'] = self::ACTION_NEEDS_REVIEW;
            $row['reason'] = implode(',', array_keys($flags));
            $row['flags'] = $flags;
        }

        return $row;
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
