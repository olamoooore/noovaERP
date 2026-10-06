<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerContact;
use Modules\Contact\src\Models\ContactRelationship;
use Modules\Contact\src\Models\ContactRole;
use Modules\Contact\src\Models\CoreContact;

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
 */
class PersonMergeDamagePlanner
{
    /**
     * @return array<string, mixed>
     */
    public function plan(string $tenantId): array
    {
        // Step 0: soft-deleted contacts and customers are included, marked.
        $groups = CustomerContact::withTrashed()
            ->whereNotNull('core_contact_uuid')
            ->orderBy('id')
            ->get()
            ->groupBy('core_contact_uuid')
            ->filter(fn ($contacts) => $contacts->count() > 1);

        $customers = Customer::withTrashed()->whereIn('id', $groups->flatten()->pluck('customer_id')->unique())->pluck('name', 'id');

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
                'customer_name' => $customers[$contact->customer_id] ?? null,
                'name' => $contact->name,
                'email' => $contact->email,
                'mobile' => $contact->mobile,
                'phone' => $contact->phone,
                'status' => $this->linkStatus($person, $contact),
            ])->values()->all();

            $statuses = array_count_values(array_column($links, 'status'));
            if (($statuses['suspect'] ?? 0) === 0 && ($statuses['unverifiable'] ?? 0) === 0) {
                continue;
            }

            $people[] = [
                'person_id' => $person->id,
                'person_name' => $person->display_name,
                'person_email' => $person->email,
                'person_mobile' => $person->mobile,
                'created_from_customer_contact_id' => $person->source === 'crm_customer_contact' ? (int) $person->legacy_id : null,
                'severity' => ($statuses['suspect'] ?? 0) > 0 ? 'suspect' : 'unverifiable',
                'links' => $links,
                'impact' => $this->impact($tenantId, $person->id),
            ];
        }

        return [
            'tenant_id' => $tenantId,
            'generated_at' => now()->toIso8601String(),
            'mode' => 'report_only',
            'summary' => [
                'people_linked_from_several_contacts' => $groups->count(),
                'people_with_suspect_links' => count(array_filter($people, fn ($p) => $p['severity'] === 'suspect')),
                'people_with_unverifiable_links_only' => count(array_filter($people, fn ($p) => $p['severity'] === 'unverifiable')),
                'suspect_links' => array_sum(array_map(fn ($p) => count(array_filter($p['links'], fn ($l) => $l['status'] === 'suspect')), $people)),
                'unverifiable_links' => array_sum(array_map(fn ($p) => count(array_filter($p['links'], fn ($l) => $l['status'] === 'unverifiable')), $people)),
            ],
            'people' => $people,
        ];
    }

    private function linkStatus(CoreContact $person, CustomerContact $contact): string
    {
        $createdFrom = $person->source === 'crm_customer_contact' && (string) $person->legacy_id === (string) $contact->id;
        $sharesIdentifier = $this->same($this->email($person->email), $this->email($contact->email))
            || $this->same($this->digits($person->mobile), $this->digits($contact->mobile))
            || $this->same($this->digits($person->phone), $this->digits($contact->phone));

        if ($createdFrom || $sharesIdentifier) {
            return 'ok';
        }

        return $this->name($person->display_name) === $this->name($contact->name) ? 'unverifiable' : 'suspect';
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
