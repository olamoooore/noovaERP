<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactRelationship;
use Modules\Contact\src\Models\CoreContact;
use Modules\Contact\src\Services\ContactMasterService;
use Modules\Contact\src\Support\ContactTaxonomyRegistry;
use Modules\Contact\src\Support\LegacyContactTypeMapping;

/**
 * ADR-003A: CRM customer/person alignment with the canonical Contact Master.
 *
 * - The `customers` row is the CRM customer profile; its canonical identity
 *   is an ORGANISATION core_contact (core_contact_uuid).
 * - `customer_contacts` rows are CRM customer-to-person associations; the
 *   canonical identity is a PERSON core_contact linked through an org-person
 *   contact_relationship carrying functional category / department, with
 *   classifications on the person and persistent roles on the relationship.
 * - is_primary is a CRM association attribute, transactionally enforced to
 *   at most one active primary per account (unique partial index backstop).
 */
class CustomerContactSyncService
{
    public function __construct(
        private readonly ContactMasterService $contacts,
        private readonly AuditLogger $audit,
    ) {}

    // ─── Organisation identity ────────────────────────────────────

    /**
     * Ensure the customer account has a canonical ORGANISATION contact and
     * return its core_contact_uuid (CT-VEN-003). Idempotent.
     */
    public function ensureOrganisationContact(Customer $customer): string
    {
        if ($customer->core_contact_uuid) {
            $existing = $this->contacts->find($customer->core_contact_uuid, tenant()->id);
            if ($existing) {
                return $existing->id;
            }
        }

        $contact = $this->contacts->create([
            'contact_type' => 'company',
            'company_name' => $customer->name,
            'display_name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'mobile' => $customer->mobile,
            'website' => $customer->website,
            'source' => 'crm_customer',
            'legacy_id' => (string) $customer->id,
        ], tenant()->id);

        $customer->forceFill(['core_contact_uuid' => $contact->id])->save();

        return $contact->id;
    }

    // ─── Person identity + org-person relationship ────────────────

    /**
     * Create/link the canonical PERSON contact for a CRM customer contact and
     * maintain the organisation-person relationship with relationship-scoped
     * functional category, department, classification and persistent roles.
     *
     * $canonical keys: functional_category_code, classification_code,
     * persistent_role_codes, department_name.
     */
    public function syncPersonForCustomerContact(
        Customer $customer,
        CustomerContact $contact,
        array $canonical = []
    ): ?string {
        $tenantId = tenant()->id;
        $orgUuid = $this->ensureOrganisationContact($customer);

        return DB::transaction(function () use ($customer, $contact, $canonical, $tenantId, $orgUuid) {
            $person = $this->resolvePerson($tenantId, $orgUuid, $contact);

            if ($contact->core_contact_uuid !== $person->id) {
                $contact->forceFill(['core_contact_uuid' => $person->id])->save();
            }

            if (! empty($canonical['classification_code'])) {
                $this->contacts->assignClassification(
                    $person->id,
                    (string) $canonical['classification_code'],
                    $tenantId
                );
            }

            $relationship = $this->orgPersonRelationship($tenantId, $orgUuid, $person->id);

            $updates = [];
            if (! empty($canonical['functional_category_code'])) {
                $updates['functional_category_code'] = $canonical['functional_category_code'];
            }
            if (array_key_exists('department_name', $canonical)) {
                $updates['department_name'] = $canonical['department_name'];
            }
            if ($updates) {
                $this->contacts->updateRelationship($relationship->id, $tenantId, $updates);
            }

            if (isset($canonical['persistent_role_codes'])) {
                $this->contacts->setRelationshipRoles(
                    $relationship->id,
                    (array) $canonical['persistent_role_codes'],
                    $tenantId
                );
            }

            return $person->id;
        });
    }

    /**
     * Deterministic duplicate matching tiers (ADR-003A §9).
     * Tier 1/2 auto-link; Tier 3 is candidate-only (no auto-merge).
     *
     * @return array{contact: ?CoreContact, tier: ?int}
     */
    public function matchPerson(string $tenantId, ?string $orgUuid, CustomerContact $source): array
    {
        return $this->matchPersonIdentity(
            $tenantId,
            $orgUuid,
            $source->name,
            $source->email,
            $source->mobile,
            $source->phone
        );
    }

    /**
     * Tier matcher over raw identity values (used by UI sync and import).
     *
     * @return array{contact: ?CoreContact, tier: ?int}
     */
    public function matchPersonIdentity(
        string $tenantId,
        ?string $orgUuid,
        ?string $name,
        ?string $email,
        ?string $mobile,
        ?string $phone
    ): array {
        $email = $this->normalizeEmail($email);
        $mobile = $this->normalizePhone($mobile);
        $phone = $this->normalizePhone($phone);
        $name = $this->normalizeName($name);

        // Tier 1 — strong: normalized email OR mobile match.
        $query = CoreContact::where('tenant_id', $tenantId)
            ->where('contact_type', 'individual')
            ->where('is_duplicate', false);
        $tier1 = (clone $query)->where(function ($q) use ($email, $mobile) {
            if ($email !== null) {
                $q->orWhereRaw('LOWER(email) = ?', [$email]);
            }
            if ($mobile !== null) {
                $q->orWhere('mobile', $mobile);
            }
        })->first();
        if ($tier1) {
            return ['contact' => $tier1, 'tier' => 1];
        }

        if ($orgUuid) {
            $personIdsInOrg = ContactRelationship::where('tenant_id', $tenantId)
                ->where('contact_id', $orgUuid)
                ->where('relationship_type', 'org_person')
                ->pluck('related_contact_id');

            if ($personIdsInOrg->isNotEmpty()) {
                // Tier 2 — strong contextual: same parent organisation +
                // normalized email/mobile/phone combination.
                $tier2 = CoreContact::where('tenant_id', $tenantId)
                    ->whereIn('id', $personIdsInOrg)
                    ->where(function ($q) use ($email, $mobile, $phone) {
                        if ($email !== null) {
                            $q->orWhereRaw('LOWER(email) = ?', [$email]);
                        }
                        if ($mobile !== null) {
                            $q->orWhere('mobile', $mobile);
                        }
                        if ($phone !== null) {
                            $q->orWhere('phone', $phone);
                        }
                    })
                    ->first();
                if ($tier2) {
                    return ['contact' => $tier2, 'tier' => 2];
                }

                // Tier 3 — candidate only: same organisation + normalized
                // full name. Never auto-merged.
                if ($name !== null) {
                    $tier3 = CoreContact::where('tenant_id', $tenantId)
                        ->whereIn('id', $personIdsInOrg)
                        ->whereRaw('LOWER(display_name) = ?', [$name])
                        ->first();
                    if ($tier3) {
                        return ['contact' => $tier3, 'tier' => 3];
                    }
                }
            }
        }

        // Tier 4 — weak/cross-org: no automatic match.
        return ['contact' => null, 'tier' => null];
    }

    // ─── CRM Primary Contact (CT-VEN-007/028) ─────────────────────

    /**
     * Atomically set the CRM primary contact for a customer account: clears
     * the prior active primary and sets the new one in a single protected
     * operation with audit evidence. The prior contact remains associated.
     * Concurrency is serialized on the customer row lock and backstopped by
     * the unique partial index customer_contacts_one_primary.
     */
    public function setPrimaryContact(Customer $customer, int $contactId, ?int $actorId = null): CustomerContact
    {
        return DB::transaction(function () use ($customer, $contactId, $actorId) {
            // Serialize concurrent SetPrimaryContact requests per account.
            $locked = DB::table('customers')->where('id', $customer->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new \DomainException('CUSTOMER_NOT_FOUND');
            }

            $target = CustomerContact::where('customer_id', $customer->id)
                ->where('id', $contactId)
                ->first();
            if (! $target) {
                throw new \DomainException('CONTACT_NOT_FOUND');
            }

            $prior = CustomerContact::where('customer_id', $customer->id)
                ->where('is_primary', true)
                ->where('id', '!=', $contactId)
                ->first();

            if ($prior) {
                $prior->forceFill(['is_primary' => false])->save();
            }
            $target->forceFill(['is_primary' => true])->save();

            $this->audit->log(
                operation: 'customer_primary_contact_changed',
                referenceType: 'customer',
                referenceId: $customer->id,
                actorId: $actorId,
                beforeState: ['primary_contact_id' => $prior?->id],
                afterState: ['primary_contact_id' => $target->id],
                metadata: ['customer_code' => $customer->code]
            );

            return $target;
        });
    }

    // ─── Legacy compatibility transform for a contact payload ─────

    /**
     * Transform a customer-contact payload carrying legacy contact_category/
     * contact_type into canonical fields per ADR-003A §7.2/§8.1.
     *
     * @return array{
     *     functional_category_code: ?string,
     *     classification_code: ?string,
     *     persistent_role_codes: string[],
     *     is_primary: bool,
     *     transaction_roles: string[],
     *     unknown_values: string[]
     * }
     */
    public function transformLegacyPayload(?string $contactCategory, ?string $contactType): array
    {
        $result = [
            'functional_category_code' => LegacyContactTypeMapping::resolveFunctionalCategory($contactCategory),
            'classification_code' => null,
            'persistent_role_codes' => [],
            'is_primary' => false,
            'transaction_roles' => [],
            'unknown_values' => [],
        ];

        if ($contactCategory && $result['functional_category_code'] === null) {
            $result['unknown_values'][] = (string) $contactCategory;
        }

        if ($contactType) {
            $mapped = LegacyContactTypeMapping::transformContactType($contactType);
            if ($mapped['unknown']) {
                $result['unknown_values'][] = (string) $contactType;
            }
            $result['is_primary'] = $mapped['is_primary'];
            $result['classification_code'] = $mapped['classification'];
            if ($mapped['persistent_role'] !== null) {
                $result['persistent_role_codes'][] = $mapped['persistent_role'];
            }
            if ($mapped['transaction_role'] !== null) {
                $result['transaction_roles'][] = $mapped['transaction_role'];
            }
            // Category implied by profession/role when not explicitly given.
            if ($result['functional_category_code'] === null && $mapped['functional_category'] !== null) {
                $result['functional_category_code'] = $mapped['functional_category'];
            }
        }

        return $result;
    }

    /**
     * Project the legacy scalar contact_type for a CRM association from
     * canonical semantics (ADR-003A §7.2 read projection).
     */
    public function projectLegacyContactType(CustomerContact $contact): string
    {
        $classifications = [];
        $persistentRoles = [];

        if ($contact->core_contact_uuid) {
            $tenantId = tenant()->id;
            $classifications = $this->contacts->classificationCodes($contact->core_contact_uuid, $tenantId);
            $persistentRoles = \Modules\Contact\src\Models\ContactRole::where('tenant_id', $tenantId)
                ->where('contact_id', $contact->core_contact_uuid)
                ->whereNotNull('relationship_id')
                ->pluck('role')
                ->all();
        } else {
            // Pre-canonical fallback: derive from stored legacy values.
            $mapped = LegacyContactTypeMapping::transformContactType($contact->contact_type);
            if ($mapped['classification']) {
                $classifications = [$mapped['classification']];
            } elseif ($mapped['persistent_role']) {
                $persistentRoles = [$mapped['persistent_role']];
            }
        }

        return LegacyContactTypeMapping::projectLegacyContactType($classifications, $persistentRoles);
    }

    // ─── Internals ────────────────────────────────────────────────

    private function resolvePerson(string $tenantId, string $orgUuid, CustomerContact $source): CoreContact
    {
        if ($source->core_contact_uuid) {
            $existing = $this->contacts->find($source->core_contact_uuid, $tenantId);
            if ($existing) {
                return $existing;
            }
        }

        $match = $this->matchPerson($tenantId, $orgUuid, $source);
        // Tier 1/2: deterministic strong evidence — reuse the person.
        if ($match['contact'] && in_array($match['tier'], [1, 2], true)) {
            return $match['contact'];
        }
        // Tier 3/4: create a new person; candidate duplicates are surfaced
        // via the duplicate review flow, never auto-merged.

        $name = trim((string) $source->name);

        return $this->contacts->create([
            'contact_type' => 'individual',
            'display_name' => $name !== '' ? $name : 'Unknown',
            'first_name' => $name !== '' ? $name : null,
            'email' => $source->email,
            'phone' => $source->phone,
            'mobile' => $source->mobile,
            'notes' => $source->notes,
            'source' => 'crm_customer_contact',
            'legacy_id' => (string) $source->id,
        ], $tenantId);
    }

    private function orgPersonRelationship(string $tenantId, string $orgUuid, string $personUuid): ContactRelationship
    {
        $existing = ContactRelationship::where('tenant_id', $tenantId)
            ->where('contact_id', $orgUuid)
            ->where('related_contact_id', $personUuid)
            ->where('relationship_type', 'org_person')
            ->first();

        if ($existing) {
            return $existing;
        }

        return ContactRelationship::create([
            'id' => Str::uuid(),
            'tenant_id' => $tenantId,
            'contact_id' => $orgUuid,
            'related_contact_id' => $personUuid,
            'relationship_type' => 'org_person',
        ]);
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

    private function normalizeName(?string $name): ?string
    {
        $name = strtolower(trim(preg_replace('/\s+/', ' ', (string) $name)));

        return $name === '' ? null : $name;
    }
}
