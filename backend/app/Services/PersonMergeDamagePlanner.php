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
            $person = CoreContact::where('tenant_id', $tenantId)->whereKey($personId)->first();
            if (! $person) {
                continue;
            }

            $links = $contacts->map(fn (CustomerContact $contact) => [
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
                'implied_classification' => $this->sync->transformLegacyPayload($contact->contact_category, $contact->contact_type)['classification_code'],
            ])->values()->all();

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
                $link['shares_relationship_with_ok_link'] = $link['status'] !== 'ok'
                    && $link['organisation_id'] !== null
                    && in_array($link['organisation_id'], $okOrganisations, true);
            }
            unset($link);

            $classifications = $this->classifications($tenantId, $person->id);
            $explained = array_filter(array_column(array_filter($links, fn ($l) => $l['status'] === 'ok'), 'implied_classification'));

            $people[] = [
                'person_id' => $person->id,
                'person_name' => $person->display_name,
                'person_email' => $person->email,
                'person_mobile' => $person->mobile,
                'person_source' => $person->source,
                'created_from_customer_contact_id' => $person->source === 'crm_customer_contact' ? (int) $person->legacy_id : null,
                'severity' => $severity,
                'links' => $links,
                'impact' => $this->impact($tenantId, $person->id),
                'classifications' => $classifications,
                'classifications_not_explained_by_ok_links' => array_values(array_diff($classifications, $explained)),
                'documents' => $this->documents($person->id, $links),
                'fingerprint' => $this->fingerprint($tenantId, $person, $contacts->all(), $classifications),
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

        return $rows;
    }

    /**
     * F: person, every linked contact, its relationships and classifications.
     *
     * @param  list<CustomerContact>  $contacts
     * @param  list<string>  $classifications
     */
    private function fingerprint(string $tenantId, CoreContact $person, array $contacts, array $classifications): string
    {
        $relationships = ContactRelationship::where('tenant_id', $tenantId)
            ->where('related_contact_id', $person->id)
            ->orderBy('id')
            ->get(['id', 'contact_id', 'functional_category_code', 'department_name', 'site_address_id'])
            ->toArray();

        return hash('sha256', json_encode([
            $person->id, $person->display_name, $person->email, $person->mobile, $person->phone,
            $person->is_duplicate, $person->updated_at?->toIso8601String(),
            array_map(fn (CustomerContact $c) => [
                $c->id, $c->customer_id, $c->name, $c->email, $c->mobile, $c->phone,
                $c->contact_category, $c->contact_type, $c->core_contact_uuid, $c->deleted_at?->toIso8601String(),
            ], $contacts),
            $relationships,
            $classifications,
        ]));
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
