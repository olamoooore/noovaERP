<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Contact\src\Models\ContactGroupMember;
use Modules\Contact\src\Models\ContactRelationship;
use Modules\Contact\src\Models\ContactRole;
use Modules\Contact\src\Models\CoreContact;
use Modules\Contact\src\Services\ContactTaxonomyService;

/**
 * REPORT ONLY. Until fix 9e389eb, CustomerContactSyncService::
 * matchPersonIdentity() linked a contact with no email and no mobile to an
 * arbitrary existing person (tier 1), and inside one company merged people
 * with no email/mobile/phone (tier 2). Both the contact dialog/API and the
 * customer import used it.
 *
 * This report lists every Contact Master person that is linked from more
 * than one customer contact and marks each link:
 *  - `ok`            the contact shares an email, mobile or phone with the
 *                    person, or is the contact the person was created from;
 *  - `suspect`       no shared identifier AND a different name — almost
 *                    certainly a wrong merge;
 *  - `unverifiable`  no shared identifier but the same name — may or may
 *                    not be the same human; needs a person to decide.
 * It writes nothing. Any repair needs its own reviewed plan (CLAUDE.md).
 *
 * W6 step 4 (merge repair plan) adds what a repair needs: people linked
 * from ONE wrong contact; `shared_identifier_different_name` (normal
 * matching on a shared identifier, #1012 — not this bug); per link the
 * organisation and whether it shares the org-person relationship with a
 * correct link; documents pointing at the person (printed live), issued vs
 * draft, by customer; and classifications no correct link explains.
 *
 * Second review of the repair plan adds: data the person OWNS (owned_data),
 * doctors inside invoices' case_information JSON, each company
 * relationship's values next to what each link implies, soft-deleted
 * damaged people, a fingerprint over all of it, and a size flag.
 *
 * Third review: the fingerprint covers only what a repair decision depends
 * on (person, links, relationships with roles, classifications) — owned
 * data and documents change with normal use and are listed instead;
 * `has_owned_data` and money-bearing `financial_owned_data` (POS loyalty
 * points, accounting balances); relationships with no linked contact at
 * their company are marked (`has_linked_contact`).
 *
 * Fourth review: the bug only fired for a contact with no email and no
 * mobile at link time. Today's values cannot prove that, so a `suspect` link
 * is `auto_split_eligible` only when its contact STILL has neither
 * (`bug_signature`); otherwise it may be a correct match whose details
 * changed later and goes to review. Person-level roles (`person_roles`) are
 * reported and fingerprinted. Contact Master loyalty points are manual
 * entries (`manual_loyalty_points`); the accounting profile balance is never
 * maintained and is not reported.
 *
 * Fifth review (reproduced on main: every identifier-less contact lands on
 * the same person, so damage concentrates on a few people with very many
 * links): decisions are checked per link. `identity_fingerprint` covers the
 * person's own identity, person roles and classifications;
 * `link_fingerprint` covers that identity, the link's contact row and its
 * company relationship — it does not change when another link changes.
 * `documents_by_customer` sums documents per customer.
 */
class PersonMergeDamagePlanner
{
    /** Severity order: bug damage first, then normal matching collisions. */
    private const SEVERITY = ['suspect', 'unverifiable', 'shared_identifier_different_name'];

    /** Document columns that hold a Contact Master person id (printed live). */
    private const DOCUMENT_COLUMNS = [
        'invoices' => ['doctor_contact_id', 'requestor_contact_id', 'buyer_contact_id', 'billing_contact_id', 'shipping_contact_id'],
        'quotations' => ['billing_contact_id', 'shipping_contact_id'],
    ];

    /** Tables keyed by contact_id that hold data a person owns. */
    private const OWNED_TABLES = [
        'contact_identifiers', 'contact_methods', 'contact_addresses', 'contact_bank_accounts',
        'contact_registrations', 'contact_tax_profiles', 'contact_related_record_registry', 'contact_visibility',
        'crm_contact_profiles', 'hr_staff_profiles', 'pos_loyalty_profiles', 'project_contact_profiles',
        'property_contact_profiles', 'accounting_customer_profiles', 'accounting_vendor_profiles',
    ];

    /** More links than this on one person: split into its own batch. */
    public const OVERSIZED_LINKS = 50;

    public function __construct(private readonly CustomerContactSyncService $sync) {}

    /**
     * @return array<string, mixed>
     */
    public function plan(string $tenantId): array
    {
        // Step 0: soft-deleted contacts and customers are included, marked.
        // W6 step 4: every linked person is checked — damage on a person
        // linked from ONE contact (made in the Contact Master or by import)
        // is still damage.
        $groups = CustomerContact::withTrashed()
            ->whereNotNull('core_contact_uuid')
            ->orderBy('id')
            ->get()
            ->groupBy('core_contact_uuid');

        $customers = Customer::withTrashed()->whereIn('id', $groups->flatten()->pluck('customer_id')->unique())->get(['id', 'name', 'core_contact_uuid'])->keyBy('id');

        $people = [];
        foreach ($groups as $personId => $contacts) {
            $person = CoreContact::withTrashed()->where('tenant_id', $tenantId)->whereKey($personId)->first();
            if (! $person) {
                continue;
            }

            $links = $contacts->map(function (CustomerContact $contact) use ($customers, $person) {
                $legacy = $this->sync->transformLegacyPayload($contact->contact_category, $contact->contact_type);

                return [
                    'customer_contact_id' => $contact->id,
                    'contact_deleted' => $contact->trashed(),
                    'customer_id' => $contact->customer_id,
                    'customer_name' => $customers[$contact->customer_id]->name ?? null,
                    'organisation_id' => $customers[$contact->customer_id]->core_contact_uuid ?? null,
                    'name' => $contact->name,
                    'email' => $contact->email,
                    'mobile' => $contact->mobile,
                    'phone' => $contact->phone,
                    'status' => $this->linkStatus($person, $contact),
                    'implied_classification' => $legacy['classification_code'],
                    'implied_functional_category' => $legacy['functional_category_code'],
                    'implied_roles' => $legacy['persistent_role_codes'],
                    'department_name' => $contact->department_name,
                    'bug_signature' => $this->email($contact->email) === null && $this->digits($contact->mobile) === null,
                ];
            })->values()->all();

            $statuses = array_count_values(array_column($links, 'status'));
            $severity = null;
            foreach (self::SEVERITY as $level) {
                if (($statuses[$level] ?? 0) > 0) {
                    $severity = $level;
                    break;
                }
            }
            if ($severity === null) {
                continue;
            }

            // C: a wrong link at the same organisation as a correct one shares
            // that org-person relationship (category, roles, site).
            $okOrganisations = array_filter(array_column(array_filter($links, fn ($l) => $l['status'] === 'ok'), 'organisation_id'));
            foreach ($links as &$link) {
                $link['auto_split_eligible'] = $link['status'] === 'suspect' && $link['bug_signature'];
                $link['shares_relationship_with_ok_link'] = $link['status'] !== 'ok'
                    && $link['organisation_id'] !== null
                    && in_array($link['organisation_id'], $okOrganisations, true);
            }
            unset($link);

            $classifications = $this->classifications($tenantId, $person->id);
            $contactsById = $contacts->keyBy('id');
            $documents = $this->documents($person->id, $links);
            $owned = $this->ownedData($person->id);
            $relationships = $this->relationships($tenantId, $person->id);
            $personRoles = ContactRole::where('tenant_id', $tenantId)
                ->where('contact_id', $person->id)
                ->whereNull('relationship_id')
                ->orderBy('role')
                ->pluck('role')
                ->all();

            // S: per-link fingerprints for review decisions.
            $identityFingerprint = $this->identityFingerprint($person, $personRoles, $classifications);
            $relationshipsByOrganisation = collect($relationships)->keyBy('organisation_id');
            foreach ($links as &$link) {
                $link['link_fingerprint'] = hash('sha256', json_encode([
                    $identityFingerprint,
                    $this->contactPrint($contactsById[$link['customer_contact_id']]),
                    $link['organisation_id'] !== null ? ($relationshipsByOrganisation[$link['organisation_id']] ?? null) : null,
                ]));
            }
            unset($link);
            $linkedOrganisations = array_filter(array_column($links, 'organisation_id'));
            foreach ($relationships as &$relationship) {
                $relationship['has_linked_contact'] = in_array($relationship['organisation_id'], $linkedOrganisations, true);
            }
            unset($relationship);
            $explained = array_filter(array_column(array_filter($links, fn ($l) => $l['status'] === 'ok'), 'implied_classification'));

            $people[] = [
                'person_id' => $person->id,
                'person_name' => $person->display_name,
                'person_email' => $person->email,
                'person_mobile' => $person->mobile,
                'person_source' => $person->source,
                'person_deleted' => $person->trashed(),
                'link_count' => count($links),
                'oversized' => count($links) > self::OVERSIZED_LINKS,
                'created_from_customer_contact_id' => $person->source === 'crm_customer_contact' ? (int) $person->legacy_id : null,
                'severity' => $severity,
                'links' => $links,
                'impact' => $this->impact($tenantId, $person->id),
                'classifications' => $classifications,
                'classifications_not_explained_by_ok_links' => array_values(array_diff($classifications, $explained)),
                'relationships' => $relationships,
                'owned_data' => $owned,
                'has_owned_data' => array_sum($owned) > 0,
                'manual_loyalty_points' => $this->manualLoyaltyPoints($person->id),
                'person_roles' => $personRoles,
                'documents' => $documents,
                'documents_by_customer' => $this->documentsByCustomer($documents),
                'identity_fingerprint' => $identityFingerprint,
                'fingerprint' => $this->fingerprint($person, $contacts->all(), $classifications, $relationships, $personRoles),
            ];
        }

        $linkCount = fn (string $status) => array_sum(array_map(fn ($p) => count(array_filter($p['links'], fn ($l) => $l['status'] === $status)), $people));

        return [
            'tenant_id' => $tenantId,
            'generated_at' => now()->toIso8601String(),
            'mode' => 'report_only',
            'summary' => [
                'people_linked_from_several_contacts' => $groups->filter(fn ($contacts) => $contacts->count() > 1)->count(),
                'people_with_suspect_links' => count(array_filter($people, fn ($p) => $p['severity'] === 'suspect')),
                'people_with_unverifiable_links_only' => count(array_filter($people, fn ($p) => $p['severity'] === 'unverifiable')),
                'suspect_links' => $linkCount('suspect'),
                'unverifiable_links' => $linkCount('unverifiable'),
                'shared_identifier_links' => $linkCount('shared_identifier_different_name'),
                'auto_split_eligible_links' => array_sum(array_map(fn ($p) => count(array_filter($p['links'], fn ($l) => $l['auto_split_eligible'])), $people)),
                'suspect_links_for_review' => array_sum(array_map(fn ($p) => count(array_filter($p['links'], fn ($l) => $l['status'] === 'suspect' && ! $l['auto_split_eligible'])), $people)),
                'issued_documents' => array_sum(array_map(fn ($p) => array_sum(array_column($p['documents'], 'issued')), $people)),
                'draft_documents' => array_sum(array_map(fn ($p) => array_sum(array_column($p['documents'], 'draft')), $people)),
            ],
            'people' => $people,
        ];
    }

    /**
     * D: documents pointing at the person, by customer, issued vs draft.
     *
     * @param  list<array<string, mixed>>  $links
     * @return list<array<string, mixed>>
     */
    private function documents(string $personId, array $links): array
    {
        $okCustomers = array_column(array_filter($links, fn ($l) => $l['status'] === 'ok'), 'customer_id');
        $rows = [];
        foreach (self::DOCUMENT_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                $counts = DB::table($table)
                    ->where($column, $personId)
                    ->selectRaw("customer_id, sum(case when status = 'draft' then 1 else 0 end) as draft, sum(case when status = 'draft' then 0 else 1 end) as issued")
                    ->groupBy('customer_id')
                    ->get();
                foreach ($counts as $count) {
                    $rows[] = [
                        'table' => $table,
                        'column' => $column,
                        'customer_id' => (int) $count->customer_id,
                        'issued' => (int) $count->issued,
                        'draft' => (int) $count->draft,
                        'customer_has_ok_link' => in_array((int) $count->customer_id, array_map('intval', $okCustomers), true),
                    ];
                }
            }
        }

        // H: doctors inside the case_information JSON of invoices.
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'case_information')) {
            $counts = DB::table('invoices')
                ->whereRaw('case_information::text LIKE ?', ['%'.$personId.'%'])
                ->selectRaw("customer_id, sum(case when status = 'draft' then 1 else 0 end) as draft, sum(case when status = 'draft' then 0 else 1 end) as issued")
                ->groupBy('customer_id')
                ->get();
            foreach ($counts as $count) {
                $rows[] = [
                    'table' => 'invoices',
                    'column' => 'case_information', // any person reference inside the cases (doctor today)
                    'customer_id' => (int) $count->customer_id,
                    'issued' => (int) $count->issued,
                    'draft' => (int) $count->draft,
                    'customer_has_ok_link' => in_array((int) $count->customer_id, array_map('intval', $okCustomers), true),
                ];
            }
        }

        return $rows;
    }

    /**
     * F/M: person, every linked contact, its relationships (with roles) and
     * classifications — what a repair decision depends on.
     *
     * @param  list<CustomerContact>  $contacts
     * @param  list<string>  $classifications
     */
    private function fingerprint(CoreContact $person, array $contacts, array $classifications, array $relationships, array $personRoles): string
    {
        return hash('sha256', json_encode([
            $person->id, $person->display_name, $person->email, $person->mobile, $person->phone,
            $person->is_duplicate, $person->updated_at?->toIso8601String(), $person->deleted_at?->toIso8601String(),
            array_map(fn (CustomerContact $c) => $this->contactPrint($c), $contacts),
            $relationships,
            $classifications,
            $personRoles,
        ]));
    }

    /** @return list<mixed> the contact fields a decision depends on */
    private function contactPrint(CustomerContact $c): array
    {
        return [
            $c->id, $c->customer_id, $c->name, $c->email, $c->mobile, $c->phone,
            $c->contact_category, $c->contact_type, $c->department_name, $c->core_contact_uuid, $c->deleted_at?->toIso8601String(),
        ];
    }

    /** S: the person's own identity, person roles and classifications. */
    private function identityFingerprint(CoreContact $person, array $personRoles, array $classifications): string
    {
        return hash('sha256', json_encode([
            $person->id, $person->display_name, $person->email, $person->mobile, $person->phone,
            $person->is_duplicate, $person->deleted_at?->toIso8601String(), $personRoles, $classifications,
        ]));
    }

    /**
     * U: issued and draft documents per customer.
     *
     * @param  list<array<string, mixed>>  $documents
     * @return list<array{customer_id: int, issued: int, draft: int}>
     */
    private function documentsByCustomer(array $documents): array
    {
        $totals = [];
        foreach ($documents as $document) {
            $totals[$document['customer_id']] ??= ['customer_id' => $document['customer_id'], 'issued' => 0, 'draft' => 0];
            $totals[$document['customer_id']]['issued'] += $document['issued'];
            $totals[$document['customer_id']]['draft'] += $document['draft'];
        }
        ksort($totals);

        return array_values($totals);
    }

    /**
     * I: every company relationship of the person with its current values.
     *
     * @return list<array<string, mixed>>
     */
    private function relationships(string $tenantId, string $personId): array
    {
        return ContactRelationship::where('tenant_id', $tenantId)
            ->where('related_contact_id', $personId)
            ->where('relationship_type', 'org_person')
            ->orderBy('id')
            ->get()
            ->map(fn (ContactRelationship $r) => [
                'relationship_id' => $r->id,
                'organisation_id' => $r->contact_id,
                'functional_category_code' => $r->functional_category_code,
                'department_name' => $r->department_name,
                'site_address_id' => $r->site_address_id,
                'roles' => ContactRole::where('tenant_id', $tenantId)->where('relationship_id', $r->id)->orderBy('role')->pluck('role')->all(),
            ])
            ->all();
    }

    /**
     * N: points typed into the Contact Master loyalty profile (no POS code
     * writes this table; POS loyalty runs per customer).
     */
    private function manualLoyaltyPoints(string $personId): int
    {
        return Schema::hasTable('pos_loyalty_profiles')
            ? (int) DB::table('pos_loyalty_profiles')->where('contact_id', $personId)->sum('loyalty_points')
            : 0;
    }

    /**
     * G: rows the person owns, per table (live rows only).
     *
     * @return array<string, int>
     */
    private function ownedData(string $personId): array
    {
        $counts = [];
        foreach (self::OWNED_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'contact_id')) {
                continue;
            }
            $query = DB::table($table)->where('contact_id', $personId);
            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            $counts[$table] = $query->count();
        }

        return $counts;
    }

    /** @return list<string> classification codes held by the person */
    private function classifications(string $tenantId, string $personId): array
    {
        return ContactGroupMember::query()
            ->join('contact_groups', 'contact_groups.id', '=', 'contact_group_members.group_id')
            ->where('contact_group_members.tenant_id', $tenantId)
            ->where('contact_group_members.contact_id', $personId)
            ->where('contact_groups.group_type', ContactTaxonomyService::CLASSIFICATION_GROUP_TYPE)
            ->orderBy('contact_groups.group_name')
            ->pluck('contact_groups.group_name')
            ->values()
            ->all();
    }

    private function linkStatus(CoreContact $person, CustomerContact $contact): string
    {
        $createdFrom = $person->source === 'crm_customer_contact' && (string) $person->legacy_id === (string) $contact->id;
        $sharesIdentifier = $this->same($this->email($person->email), $this->email($contact->email))
            || $this->same($this->digits($person->mobile), $this->digits($contact->mobile))
            || $this->same($this->digits($person->phone), $this->digits($contact->phone));

        $sameName = $this->name($person->display_name) === $this->name($contact->name);
        if ($createdFrom || ($sharesIdentifier && $sameName)) {
            return 'ok';
        }
        if ($sharesIdentifier) {
            // Normal tier-1/2 matching on a shared identifier (#1012), not the
            // empty-condition bug: reported, but not bug damage.
            return 'shared_identifier_different_name';
        }

        return $sameName ? 'unverifiable' : 'suspect';
    }

    /**
     * What hangs off the person and could be wrong: company relationships
     * (with category, department, site) and relationship roles.
     *
     * @return array<string, mixed>
     */
    private function impact(string $tenantId, string $personId): array
    {
        $relationships = ContactRelationship::where('tenant_id', $tenantId)
            ->where('related_contact_id', $personId)
            ->where('relationship_type', 'org_person')
            ->get();

        return [
            'organisations' => $relationships->pluck('contact_id')->unique()->values()->all(),
            'relationships_with_site' => $relationships->whereNotNull('site_address_id')->count(),
            'roles' => ContactRole::where('tenant_id', $tenantId)->where('contact_id', $personId)->count(),
        ];
    }

    private function same(?string $a, ?string $b): bool
    {
        return $a !== null && $a === $b;
    }

    private function email(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    private function digits(?string $value): ?string
    {
        $value = preg_replace('/\D+/', '', (string) $value);

        return $value === '' ? null : $value;
    }

    private function name(?string $value): string
    {
        return (string) preg_replace('/\s+/', ' ', strtolower(trim((string) $value)));
    }
}
