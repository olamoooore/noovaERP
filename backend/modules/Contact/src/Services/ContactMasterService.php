<?php

namespace Modules\Contact\src\Services;

use App\Platform\EventBus\DomainEventDispatcher;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Events\ContactCreated;
use Modules\Contact\src\Events\ContactDeleted;
use Modules\Contact\src\Events\ContactImportCompleted;
use Modules\Contact\src\Events\ContactMerged;
use Modules\Contact\src\Events\ContactRoleChanged;
use Modules\Contact\src\Events\ContactUpdated;
use Modules\Contact\src\Models\AccountingCustomerProfile;
use Modules\Contact\src\Models\AccountingVendorProfile;
use Modules\Contact\src\Models\ContactAddress;
use Modules\Contact\src\Models\ContactBankAccount;
use Modules\Contact\src\Models\ContactGroup;
use Modules\Contact\src\Models\ContactGroupMember;
use Modules\Contact\src\Models\ContactIdentifier;
use Modules\Contact\src\Models\ContactImportBatch;
use Modules\Contact\src\Models\ContactImportStaging;
use Modules\Contact\src\Models\ContactMergeHistory;
use Modules\Contact\src\Models\ContactMethod;
use Modules\Contact\src\Models\ContactRelatedRecord;
use Modules\Contact\src\Models\ContactRelationship;
use Modules\Contact\src\Models\ContactRegistration;
use Modules\Contact\src\Models\ContactRole;
use Modules\Contact\src\Models\ContactTaxProfile;
use Modules\Contact\src\Models\ContactSearchIndex;
use Modules\Contact\src\Models\CoreContact;
use Modules\Contact\src\Models\CrmContactProfile;
use Modules\Contact\src\Models\HrStaffProfile;
use Modules\Contact\src\Models\PosLoyaltyProfile;
use Modules\Contact\src\Models\ProjectContactProfile;
use Modules\Contact\src\Models\PropertyContactProfile;
use Modules\Contact\src\Support\ContactTaxonomyRegistry;

/**
 * ContactMasterService — shared contact identity service (ADR 000 Pillar 3).
 *
 * Owns: contact identity, roles, identifiers, addresses, methods,
 * relationships, visibility, hierarchy, duplicate control, search index.
 *
 * Does NOT own: accounting invoices, HR payroll, property units, inventory items.
 *
 * ADR-003A extensions: profession classifications, relationship-scoped
 * functional category/department, relationship-scoped persistent roles.
 */
class ContactMasterService
{
    use ApiResponse;

    private ?DomainEventDispatcher $eventDispatcher = null;

    public function __construct(
        private readonly ContactTaxonomyService $taxonomyService = new ContactTaxonomyService,
    ) {}

    public function setEventDispatcher(DomainEventDispatcher $dispatcher): void
    {
        $this->eventDispatcher = $dispatcher;
    }

    // ─── Contact CRUD ─────────────────────────────────────────────

    public function create(array $data, string $tenantId): CoreContact
    {
        return DB::transaction(function () use ($data, $tenantId) {
            $contact = CoreContact::create([
                'id' => Str::uuid(),
                'tenant_id' => $tenantId,
                'entity_id' => $data['entity_id'] ?? null,
                'legal_entity_id' => $data['legal_entity_id'] ?? null,
                'contact_type' => self::normalizeEntityType($data['contact_type'] ?? $data['entity_type'] ?? 'individual'),
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'company_name' => $data['company_name'] ?? null,
                'display_name' => $data['display_name'] ?? trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'mobile' => $data['mobile'] ?? null,
                'website' => $data['website'] ?? null,
                'notes' => $data['notes'] ?? null,
                'source' => $data['source'] ?? 'manual',
                'legacy_id' => $data['legacy_id'] ?? null,
                'is_active' => true,
                'metadata' => $data['metadata'] ?? null,
            ]);

            // Create search index
            $this->rebuildSearchIndex($contact);

            ContactCreated::dispatch($tenantId, $contact->id, null, null, $contact->toArray());

            if ($this->eventDispatcher) {
                $event = new ContactCreated($tenantId, $contact->id, null, null, $contact->toArray());
                $this->eventDispatcher->dispatch($event);
            }

            return $contact;
        });
    }

    public function update(string $id, array $data, string $tenantId): ?CoreContact
    {
        $contact = CoreContact::where('tenant_id', $tenantId)->where('id', $id)->first();
        if (! $contact) {
            return null;
        }

        $old = $contact->toArray();

        $contact->update(array_filter([
            'entity_id' => $data['entity_id'] ?? null,
            'legal_entity_id' => $data['legal_entity_id'] ?? null,
            'contact_type' => isset($data['contact_type']) || isset($data['entity_type'])
                ? self::normalizeEntityType($data['contact_type'] ?? $data['entity_type'])
                : null,
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'company_name' => $data['company_name'] ?? null,
            'display_name' => $data['display_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'website' => $data['website'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => $data['is_active'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ], fn ($v) => $v !== null));

        $this->rebuildSearchIndex($contact);

        ContactUpdated::dispatch($tenantId, $id, null, $old, $contact->fresh()->toArray());

        if ($this->eventDispatcher) {
            $event = new ContactUpdated($tenantId, $id, null, $old, $contact->fresh()->toArray());
            $this->eventDispatcher->dispatch($event);
        }

        return $contact->fresh();
    }

    /**
     * ADR-003A mirror sync: apply the complete profile held by an
     * authoritative source row (e.g. a CRM customer) onto the contact.
     *
     * `update()` is PATCH-shaped — null values are ignored so a partial API
     * payload never erases fields. A mirror sync is the opposite: every key
     * present in $data is applied, and an explicit null clears a value the
     * source no longer holds (e.g. a removed customer email/website), so the
     * Contact Master can never keep stale source data.
     */
    public function syncProfile(string $id, array $data, string $tenantId): ?CoreContact
    {
        $contact = CoreContact::where('tenant_id', $tenantId)->where('id', $id)->first();
        if (! $contact) {
            return null;
        }

        $old = $contact->toArray();

        $updates = [];
        foreach ([
            'entity_id', 'legal_entity_id', 'first_name', 'last_name',
            'company_name', 'display_name', 'email', 'phone', 'mobile',
            'website', 'notes', 'is_active', 'metadata',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (array_key_exists('contact_type', $data) || array_key_exists('entity_type', $data)) {
            $updates['contact_type'] = self::normalizeEntityType(
                $data['contact_type'] ?? $data['entity_type']
            );
        }

        if ($updates !== []) {
            $contact->update($updates);
        }

        $this->rebuildSearchIndex($contact);

        ContactUpdated::dispatch($tenantId, $id, null, $old, $contact->fresh()->toArray());

        if ($this->eventDispatcher) {
            $event = new ContactUpdated($tenantId, $id, null, $old, $contact->fresh()->toArray());
            $this->eventDispatcher->dispatch($event);
        }

        return $contact->fresh();
    }

    public function delete(string $id, string $tenantId): bool
    {
        $contact = CoreContact::where('tenant_id', $tenantId)->where('id', $id)->first();
        if (! $contact) {
            return false;
        }

        // Check for transactions (invoices, orders, etc.)
        $hasTransactions = ContactRelatedRecord::where('tenant_id', $tenantId)
            ->where('contact_id', $id)
            ->exists();

        if ($hasTransactions) {
            throw new \DomainException('CONTACT_DELETE_HAS_TRANSACTIONS');
        }

        $contact->delete();

        ContactDeleted::dispatch($tenantId, $id);

        if ($this->eventDispatcher) {
            $event = new ContactDeleted($tenantId, $id, null, null, null);
            $this->eventDispatcher->dispatch($event);
        }

        return true;
    }

    public function find(string $id, string $tenantId): ?CoreContact
    {
        return CoreContact::where('tenant_id', $tenantId)->where('id', $id)->first();
    }

    public function list(string $tenantId, array $filters = [], array $accessibleEntityIds = [])
    {
        $query = CoreContact::where('tenant_id', $tenantId)->with('addresses');

        // Issue #232: entity scoping. Applied only when the user has entity
        // grants (platform convention shared with JournalEntry/FixedAsset
        // #97/#163: no grants keeps the legacy fail-open visibility).
        if (! empty($accessibleEntityIds)) {
            $query->accessibleBy($accessibleEntityIds);
        }

        if (! empty($filters['legal_entity_id'])) {
            $query->where('legal_entity_id', $filters['legal_entity_id']);
        }

        if (! empty($filters['entity_id'])) {
            $query->where('entity_id', $filters['entity_id']);
        }
        if (! empty($filters['contact_type'])) {
            $query->where('contact_type', $filters['contact_type']);
        }
        if (! empty($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }
        if (! empty($filters['role'])) {
            $query->whereHas('roles', fn ($q) => $q->where('role', $filters['role']));
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('display_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('company_name', 'ilike', "%{$search}%");
            });
        }

        $perPage = min($filters['per_page'] ?? 25, 100);

        return $query->orderBy('display_name')->paginate($perPage);
    }

    // ─── Roles ────────────────────────────────────────────────────

    public function addRole(string $contactId, string $role, string $tenantId, array $meta = []): ?ContactRole
    {
        $contact = $this->find($contactId, $tenantId);
        if (! $contact) {
            return null;
        }

        // ADR-003A: relationship-scoped roles are persistent relationship
        // roles and must use reserved taxonomy codes (CT-VEN-005/016).
        if (! empty($meta['relationship_id'])) {
            $role = $this->taxonomyService->assertValidCode(
                ContactTaxonomyRegistry::TYPE_PERSISTENT_ROLE,
                $role
            );
        }

        try {
            // Nested transaction => SAVEPOINT, so a unique violation can be
            // caught without aborting the caller's transaction (Postgres).
            $contactRole = DB::transaction(fn () => ContactRole::create([
                'id' => Str::uuid(),
                'tenant_id' => $tenantId,
                'contact_id' => $contactId,
                'relationship_id' => $meta['relationship_id'] ?? null,
                'role' => $role,
                'role_label' => $meta['role_label'] ?? null,
                'entity_id' => $meta['entity_id'] ?? null,
                'is_primary' => $meta['is_primary'] ?? false,
                'metadata' => $meta['metadata'] ?? null,
            ]));
        } catch (\Illuminate\Database\QueryException $e) {
            // Issue #120: an exact duplicate is idempotent — return the existing
            // row instead of leaking a 500 from the unique index.
            if (str_contains($e->getMessage(), 'contact_role_unique')) {
                $existing = ContactRole::where('tenant_id', $tenantId)
                    ->where('contact_id', $contactId)
                    ->where('role', $role)
                    ->where('entity_id', $meta['entity_id'] ?? null)
                    ->where('relationship_id', $meta['relationship_id'] ?? null)
                    ->first();

                if ($existing) {
                    return $existing;
                }

                throw new \DomainException('CONTACT_ROLE_ALREADY_EXISTS');
            }

            throw $e;
        }

        ContactRoleChanged::dispatch($tenantId, $contactId, $role, 'added');

        if ($this->eventDispatcher) {
            $event = new ContactRoleChanged($tenantId, $contactId, $role, 'added');
            $this->eventDispatcher->dispatch($event);
        }

        return $contactRole;
    }

    public function removeRole(string $contactId, string $role, string $tenantId): bool
    {
        $deleted = ContactRole::where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->where('role', $role)
            ->delete() > 0;

        if ($deleted) {
            ContactRoleChanged::dispatch($tenantId, $contactId, $role, 'removed');

            if ($this->eventDispatcher) {
                $event = new ContactRoleChanged($tenantId, $contactId, $role, 'removed');
                $this->eventDispatcher->dispatch($event);
            }
        }

        return $deleted;
    }

    // ─── Identifiers ──────────────────────────────────────────────

    public function addIdentifier(string $contactId, string $type, string $value, string $tenantId, array $meta = []): ?ContactIdentifier
    {
        $contact = $this->find($contactId, $tenantId);
        if (! $contact) {
            return null;
        }

        return ContactIdentifier::create([
            'id' => Str::uuid(),
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'identifier_type' => $type,
            'identifier_value' => $value,
            'identifier_label' => $meta['identifier_label'] ?? null,
            'source_system' => $meta['source_system'] ?? null,
            'is_primary' => $meta['is_primary'] ?? false,
            'metadata' => $meta['metadata'] ?? null,
        ]);
    }

    // ─── Addresses ────────────────────────────────────────────────

    public function addAddress(string $contactId, array $data, string $tenantId): ?ContactAddress
    {
        $contact = $this->find($contactId, $tenantId);
        if (! $contact) {
            return null;
        }

        // Issue #121: only one primary / default-billing / default-shipping
        // address per contact — unset the flag on any existing row first.
        // The contact row is locked so concurrent addAddress calls serialize
        // (the partial unique indexes back this up at the DB level).
        return DB::transaction(function () use ($contactId, $data, $tenantId) {
            CoreContact::whereKey($contactId)->lockForUpdate()->first();

            if (! empty($data['is_primary'])) {
                ContactAddress::where('contact_id', $contactId)->update(['is_primary' => false]);
            }
            if (! empty($data['is_default_billing'])) {
                ContactAddress::where('contact_id', $contactId)->update(['is_default_billing' => false]);
            }
            if (! empty($data['is_default_shipping'])) {
                ContactAddress::where('contact_id', $contactId)->update(['is_default_shipping' => false]);
            }

            return ContactAddress::create([
            'id' => Str::uuid(),
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'address_type' => $data['address_type'],
            'label' => $data['label'] ?? null,
            'line_1' => $data['line_1'] ?? null,
            'line_2' => $data['line_2'] ?? null,
            'line_3' => $data['line_3'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $data['country'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'is_primary' => $data['is_primary'] ?? false,
            'is_default_billing' => $data['is_default_billing'] ?? false,
            'is_default_shipping' => $data['is_default_shipping'] ?? false,
            'metadata' => $data['metadata'] ?? null,
            ]);
        });
    }

    // ─── Methods ──────────────────────────────────────────────────

    public function addMethod(string $contactId, array $data, string $tenantId): ?ContactMethod
    {
        $contact = $this->find($contactId, $tenantId);
        if (! $contact) {
            return null;
        }

        return DB::transaction(function () use ($contactId, $data, $tenantId) {
            // Issue #121: only one primary method per contact. Lock the
            // contact row so concurrent addMethod calls serialize; the
            // partial unique index is the DB-level backstop.
            CoreContact::whereKey($contactId)->lockForUpdate()->first();

            if (! empty($data['is_primary'])) {
                ContactMethod::where('contact_id', $contactId)->update(['is_primary' => false]);
            }

            return ContactMethod::create([
                'id' => Str::uuid(),
                'tenant_id' => $tenantId,
                'contact_id' => $contactId,
                'method_type' => $data['method_type'],
                'method_value' => $data['method_value'],
                'method_label' => $data['method_label'] ?? null,
                'is_primary' => $data['is_primary'] ?? false,
                'is_verified' => $data['is_verified'] ?? false,
                'is_for_notifications' => $data['is_for_notifications'] ?? false,
                'metadata' => $data['metadata'] ?? null,
            ]);
        });
    }

    // ─── Relationships ────────────────────────────────────────────

    /**
     * Create a contact relationship.
     *
     * ADR-003A: for organisation-person associations (relationship_type
     * 'org_person') the meta may carry functional_category_code,
     * department_name/department_id and persistent_role_codes[]. Category and
     * roles are relationship-scoped attributes, never PERSON intrinsics.
     */
    public function addRelationship(string $contactId, string $relatedId, string $type, string $tenantId, array $meta = []): ?ContactRelationship
    {
        $contact = $this->find($contactId, $tenantId);
        if (! $contact) {
            return null;
        }

        // Prevent circular relationships
        if ($contactId === $relatedId) {
            throw new \DomainException('CONTACT_RELATIONSHIP_CIRCULAR');
        }

        $categoryCode = null;
        if (! empty($meta['functional_category_code'])) {
            $categoryCode = $this->taxonomyService->assertValidCode(
                ContactTaxonomyRegistry::TYPE_FUNCTIONAL_CATEGORY,
                $meta['functional_category_code']
            );
        }

        $relationship = ContactRelationship::create([
            'id' => Str::uuid(),
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'related_contact_id' => $relatedId,
            'relationship_type' => $type,
            'relationship_label' => $meta['relationship_label'] ?? null,
            'functional_category_code' => $categoryCode,
            'department_name' => $meta['department_name'] ?? null,
            'department_id' => $meta['department_id'] ?? null,
            'notes' => $meta['notes'] ?? null,
            'is_primary' => $meta['is_primary'] ?? false,
            'metadata' => $meta['metadata'] ?? null,
        ]);

        // ADR-003A: persistent relationship roles are scoped to this
        // organisation-person association (relationship_id), never PERSON
        // intrinsics. Codes must be reserved taxonomy codes.
        foreach ((array) ($meta['persistent_role_codes'] ?? []) as $roleCode) {
            $this->addRole($relatedId, (string) $roleCode, $tenantId, [
                'relationship_id' => $relationship->id,
            ]);
        }

        return $relationship;
    }

    /**
     * ADR-003A: update relationship-scoped attributes (functional category,
     * department). Category must be a reserved code.
     */
    public function updateRelationship(string $relationshipId, string $tenantId, array $data): ?ContactRelationship
    {
        $relationship = ContactRelationship::where('tenant_id', $tenantId)
            ->where('id', $relationshipId)
            ->first();
        if (! $relationship) {
            return null;
        }

        $updates = [];

        if (array_key_exists('functional_category_code', $data)) {
            $updates['functional_category_code'] = $data['functional_category_code']
                ? $this->taxonomyService->assertValidCode(
                    ContactTaxonomyRegistry::TYPE_FUNCTIONAL_CATEGORY,
                    $data['functional_category_code']
                )
                : null;
        }
        if (array_key_exists('department_name', $data)) {
            $updates['department_name'] = $data['department_name'];
        }
        if (array_key_exists('department_id', $data)) {
            $updates['department_id'] = $data['department_id'];
        }

        if ($updates) {
            $relationship->update($updates);
        }

        return $relationship->fresh();
    }

    /**
     * Replace the persistent relationship roles on an organisation-person
     * association. Unknown codes are rejected (no canonical code invention).
     */
    public function setRelationshipRoles(string $relationshipId, array $roleCodes, string $tenantId): array
    {
        $relationship = ContactRelationship::where('tenant_id', $tenantId)
            ->where('id', $relationshipId)
            ->first();
        if (! $relationship) {
            throw new \DomainException('CONTACT_RELATIONSHIP_NOT_FOUND');
        }

        $personId = $relationship->related_contact_id;

        return DB::transaction(function () use ($relationship, $personId, $roleCodes, $tenantId) {
            ContactRole::where('tenant_id', $tenantId)
                ->where('relationship_id', $relationship->id)
                ->delete();

            $roles = [];
            foreach ((array) $roleCodes as $code) {
                $roles[] = $this->addRole($personId, (string) $code, $tenantId, [
                    'relationship_id' => $relationship->id,
                ]);
            }

            return $roles;
        });
    }

    // ─── Classifications (profession/person class) ────────────────

    /**
     * ADR-003A §4.3: classifications are person-level (identity) attributes,
     * stored via contact_groups with group_type='classification'. P0 codes:
     * DOCTOR, NURSE, OTHER only. FINANCE_STAFF/PROCUREMENT_STAFF/etc. are
     * functional categories, not classifications, and are rejected here.
     */
    public function assignClassification(string $contactId, string $code, string $tenantId): ContactGroupMember
    {
        $contact = $this->find($contactId, $tenantId);
        if (! $contact) {
            throw new \DomainException('CONTACT_NOT_FOUND');
        }

        $resolved = $this->taxonomyService->assertValidCode(
            ContactTaxonomyRegistry::TYPE_CLASSIFICATION,
            $code
        );

        $this->taxonomyService->ensureSystemTaxonomy($tenantId);

        $group = ContactGroup::where('tenant_id', $tenantId)
            ->where('group_type', ContactTaxonomyService::CLASSIFICATION_GROUP_TYPE)
            ->where('group_name', $resolved)
            ->firstOrFail();

        return ContactGroupMember::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'contact_id' => $contactId,
                'group_id' => $group->id,
            ],
            ['id' => Str::uuid()]
        );
    }

    public function removeClassification(string $contactId, string $code, string $tenantId): bool
    {
        $resolved = $this->taxonomyService->assertValidCode(
            ContactTaxonomyRegistry::TYPE_CLASSIFICATION,
            $code
        );

        $group = ContactGroup::where('tenant_id', $tenantId)
            ->where('group_type', ContactTaxonomyService::CLASSIFICATION_GROUP_TYPE)
            ->where('group_name', $resolved)
            ->first();
        if (! $group) {
            return false;
        }

        return ContactGroupMember::where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->where('group_id', $group->id)
            ->delete() > 0;
    }

    /** @return string[] canonical classification codes assigned to the person */
    public function classificationCodes(string $contactId, string $tenantId): array
    {
        return ContactGroupMember::where('contact_group_members.tenant_id', $tenantId)
            ->where('contact_group_members.contact_id', $contactId)
            ->join('contact_groups', 'contact_groups.id', '=', 'contact_group_members.group_id')
            ->where('contact_groups.group_type', ContactTaxonomyService::CLASSIFICATION_GROUP_TYPE)
            ->pluck('contact_groups.group_name')
            ->all();
    }

    // ─── Entity type normalization ────────────────────────────────

    /**
     * ADR-003A §4.1: canonical entity types are PERSON / ORGANISATION.
     * The legacy storage values are individual/company; this maps both
     * vocabularies onto storage without persisting profession/role as type.
     */
    public static function normalizeEntityType(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'person', 'individual', 'human' => 'individual',
            'organisation', 'organization', 'company', 'org' => 'company',
            default => 'individual',
        };
    }

    public static function canonicalEntityType(string $storageType): string
    {
        return $storageType === 'company' ? 'ORGANISATION' : 'PERSON';
    }

    // ─── Search ───────────────────────────────────────────────────

    public function search(string $tenantId, array $criteria)
    {
        $query = ContactSearchIndex::where('tenant_id', $tenantId)->where('is_active', true);

        if (! empty($criteria['q'])) {
            $q = $criteria['q'];
            $query->where(function ($sq) use ($q) {
                $sq->where('search_name', 'ilike', "%{$q}%")
                    ->orWhere('email', 'ilike', "%{$q}%")
                    ->orWhere('phone', 'ilike', "%{$q}%")
                    ->orWhere('mobile', 'ilike', "%{$q}%")
                    ->orWhere('company_name', 'ilike', "%{$q}%")
                    ->orWhere('identifiers_text', 'ilike', "%{$q}%");
            });
        }
        if (! empty($criteria['role'])) {
            $query->where('primary_role', $criteria['role']);
        }
        if (! empty($criteria['entity_id'])) {
            $query->whereHas('contact', fn ($q) => $q->where('entity_id', $criteria['entity_id']));
        }

        $perPage = min($criteria['per_page'] ?? 25, 100);

        return $query->orderBy('display_name')->paginate($perPage);
    }

    // ─── Duplicate Detection ──────────────────────────────────────

    public function findDuplicates(string $tenantId): array
    {
        $contacts = CoreContact::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('is_duplicate', false)
            ->get();

        $candidates = [];
        $checked = [];

        foreach ($contacts as $contact) {
            foreach ($contacts as $other) {
                if ($contact->id === $other->id) {
                    continue;
                }
                $pairKey = collect([$contact->id, $other->id])->sort()->implode('-');
                if (in_array($pairKey, $checked)) {
                    continue;
                }
                $checked[] = $pairKey;

                $score = 0;
                $matchType = [];

                // Exact email match
                if ($contact->email && $other->email && strtolower($contact->email) === strtolower($other->email)) {
                    $score += 50;
                    $matchType[] = 'exact_email';
                }

                // Exact phone match
                if ($contact->phone && $other->phone && $contact->phone === $other->phone) {
                    $score += 30;
                    $matchType[] = 'exact_phone';
                }

                // Fuzzy name match
                if ($contact->display_name && $other->display_name) {
                    similar_text(strtolower($contact->display_name), strtolower($other->display_name), $percent);
                    if ($percent > 80) {
                        $score += $percent / 2;
                        $matchType[] = 'fuzzy_name';
                    }
                }

                if ($score >= 40) {
                    $candidates[] = [
                        'contact_id_1' => $contact->id,
                        'contact_id_2' => $other->id,
                        'contact_1_name' => $contact->display_name,
                        'contact_2_name' => $other->display_name,
                        'match_type' => implode(', ', $matchType),
                        'match_score' => min($score, 100),
                    ];
                }
            }
        }

        return $candidates;
    }

    // ─── Merge ────────────────────────────────────────────────────

    public function merge(string $primaryId, string $mergedId, string $tenantId, string $userId): bool
    {
        $primary = $this->find($primaryId, $tenantId);
        $merged = $this->find($mergedId, $tenantId);
        if (! $primary || ! $merged) {
            return false;
        }

        return DB::transaction(function () use ($primary, $merged, $primaryId, $mergedId, $tenantId, $userId) {
            // Move roles
            ContactRole::where('tenant_id', $tenantId)->where('contact_id', $merged->id)
                ->update(['contact_id' => $primary->id]);

            // Move identifiers
            ContactIdentifier::where('tenant_id', $tenantId)->where('contact_id', $merged->id)
                ->update(['contact_id' => $primary->id]);

            // Move addresses
            ContactAddress::where('tenant_id', $tenantId)->where('contact_id', $merged->id)
                ->update(['contact_id' => $primary->id]);

            // Move methods
            ContactMethod::where('tenant_id', $tenantId)->where('contact_id', $merged->id)
                ->update(['contact_id' => $primary->id]);

            // Move relationships
            ContactRelationship::where('tenant_id', $tenantId)->where('contact_id', $merged->id)
                ->update(['contact_id' => $primary->id]);
            ContactRelationship::where('tenant_id', $tenantId)->where('related_contact_id', $merged->id)
                ->update(['related_contact_id' => $primary->id]);

            // Move related records
            ContactRelatedRecord::where('tenant_id', $tenantId)->where('contact_id', $merged->id)
                ->update(['contact_id' => $primary->id]);

            // Issue #221: move the remaining owned HasMany relations too —
            // group memberships, registrations, tax profiles and bank accounts
            // were previously left pointing at the deactivated contact.
            $movedGroups = ['roles', 'identifiers', 'addresses', 'methods', 'relationships', 'related_records'];

            foreach ([
                'groups' => ContactGroupMember::class,
                'registrations' => ContactRegistration::class,
                'tax_profiles' => ContactTaxProfile::class,
                'bank_accounts' => ContactBankAccount::class,
            ] as $label => $modelClass) {
                $modelClass::where('tenant_id', $tenantId)->where('contact_id', $merged->id)
                    ->update(['contact_id' => $primary->id]);
                $movedGroups[] = $label;
            }

            // Module profiles are HasOne: only move a profile when the primary
            // does not already have one of that type, otherwise the two rows
            // would collide / the primary's own profile would be ambiguous.
            foreach ([
                'accounting_customer_profile' => AccountingCustomerProfile::class,
                'accounting_vendor_profile' => AccountingVendorProfile::class,
                'crm_profile' => CrmContactProfile::class,
                'hr_profile' => HrStaffProfile::class,
                'pos_loyalty_profile' => PosLoyaltyProfile::class,
                'property_profile' => PropertyContactProfile::class,
                'project_profile' => ProjectContactProfile::class,
            ] as $label => $modelClass) {
                $primaryHasProfile = $modelClass::where('tenant_id', $tenantId)
                    ->where('contact_id', $primary->id)
                    ->exists();

                if (! $primaryHasProfile) {
                    $modelClass::where('tenant_id', $tenantId)->where('contact_id', $merged->id)
                        ->update(['contact_id' => $primary->id]);
                    $movedGroups[] = $label;
                }
            }

            // Mark merged contact
            $merged->update(['is_duplicate' => true, 'merged_into_id' => $primaryId, 'is_active' => false]);

            // Record merge history
            ContactMergeHistory::create([
                'id' => Str::uuid(),
                'tenant_id' => $tenantId,
                'primary_contact_id' => $primaryId,
                'merged_contact_id' => $mergedId,
                'merge_details' => $movedGroups,
                'merged_by' => $userId,
                'merged_at' => now(),
            ]);

            // Rebuild search index
            $this->rebuildSearchIndex($primary);

            ContactMerged::dispatch($tenantId, $primaryId, $mergedId, $userId);

            if ($this->eventDispatcher) {
                $event = new ContactMerged($tenantId, $primaryId, $mergedId, $userId);
                $this->eventDispatcher->dispatch($event);
            }

            return true;
        });
    }

    // ─── 360 View ─────────────────────────────────────────────────

    public function get360View(string $id, string $tenantId): ?array
    {
        $contact = $this->find($id, $tenantId);
        if (! $contact) {
            return null;
        }

        return [
            'contact' => $contact,
            'entity_type' => self::canonicalEntityType($contact->contact_type),
            'classifications' => $this->classificationCodes($id, $tenantId),
            'roles' => $contact->roles,
            'identifiers' => $contact->identifiers,
            'addresses' => $contact->addresses,
            'methods' => $contact->methods,
            'relationships' => $contact->relationships->map(fn ($r) => [
                'relationship' => $r,
                'related_contact' => CoreContact::find($r->related_contact_id),
            ]),
            'groups' => $contact->groups->map(fn ($gm) => $gm->group),
            'registrations' => $contact->registrations,
            'tax_profiles' => $contact->taxProfiles,
            'bank_accounts' => $contact->bankAccounts,
            'related_records' => $contact->relatedRecords->groupBy('module_key'),
            'profiles' => [
                'accounting_customer' => $contact->accountingCustomerProfile,
                'accounting_vendor' => $contact->accountingVendorProfile,
                'crm' => $contact->crmProfile,
                'hr' => $contact->hrProfile,
                'pos_loyalty' => $contact->posLoyaltyProfile,
                'property' => $contact->propertyProfile,
                'project' => $contact->projectProfile,
            ],
        ];
    }

    // ─── Search Index ─────────────────────────────────────────────

    public function rebuildSearchIndex(CoreContact $contact): void
    {
        $roles = $contact->roles->pluck('role')->implode(', ');
        // Hostile re-review (#118): identifier values are encrypted at rest
        // (tax IDs, registration numbers) — copying them into the search index
        // would store the plaintext in a second table. Index the identifier
        // TYPES only; values stay in the encrypted source column.
        $identifiers = $contact->identifiers->pluck('identifier_type')->implode(', ');
        // Issue #121: the search index must use the PRIMARY address, not the
        // first row in insertion order.
        $address = $contact->addresses->firstWhere('is_primary', true)
            ?? $contact->addresses->first();
        $addressText = $address ? $address->full_address : null;

        $data = [
            'id' => Str::uuid(),
            'tenant_id' => $contact->tenant_id,
            'contact_id' => $contact->id,
            'display_name' => $contact->display_name,
            'search_name' => strtolower($contact->display_name),
            'email' => $contact->email,
            'phone' => $contact->phone,
            'mobile' => $contact->mobile,
            'company_name' => $contact->company_name,
            'roles_text' => $roles,
            'identifiers_text' => $identifiers,
            'address_text' => $addressText,
            'primary_role' => $contact->roles->where('is_primary', true)->first()?->role,
            'primary_identifier' => $contact->identifiers->where('is_primary', true)->first()?->identifier_type,
            'is_active' => $contact->is_active,
        ];

        ContactSearchIndex::updateOrCreate(
            ['contact_id' => $contact->id],
            $data
        );
    }

    // ─── Import ─────────────────────────────────────────────────

    public function importCreateBatch(array $fileData, string $tenantId, string $userId): ContactImportBatch
    {
        $batch = ContactImportBatch::create([
            'id' => Str::uuid(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'file_name' => $fileData['file_name'],
            'file_hash' => $fileData['file_hash'],
            'total_rows' => $fileData['total_rows'],
            'valid_rows' => 0,
            'error_rows' => 0,
            'duplicate_rows' => 0,
            'status' => 'staging',
            'mapping_config' => $fileData['mapping'] ?? null,
        ]);

        return $batch;
    }

    public function importStageRows(ContactImportBatch $batch, array $rows, string $tenantId): array
    {
        $valid = 0;
        $errors = 0;
        $duplicates = 0;

        foreach ($rows as $i => $row) {
            $rowNumber = $i + 1;
            $mappedData = $this->mapImportRow($row, $batch->mapping_config);
            $validationErrors = $this->validateImportRow($mappedData);

            // Check for duplicate by email or phone
            $matchType = null;
            $matchedId = null;
            if (empty($validationErrors)) {
                $existing = $this->findDuplicateForImport($mappedData, $tenantId);
                if ($existing) {
                    $matchType = 'duplicate';
                    $duplicates++;
                } else {
                    $matchType = 'new';
                    $valid++;
                }
            } else {
                $matchType = 'error';
                $errors++;
            }

            ContactImportStaging::create([
                'id' => Str::uuid(),
                'tenant_id' => $tenantId,
                'batch_id' => $batch->id,
                'row_number' => $rowNumber,
                'raw_data' => $row,
                'mapped_data' => $mappedData,
                'status' => $matchType,
                'errors' => $validationErrors ?: null,
                'matched_contact_id' => $matchedId,
                'match_type' => $matchType,
            ]);
        }

        $batch->update([
            'valid_rows' => $valid,
            'error_rows' => $errors,
            'duplicate_rows' => $duplicates,
            'status' => 'validated',
        ]);

        return ['valid' => $valid, 'errors' => $errors, 'duplicates' => $duplicates];
    }

    public function importConfirm(ContactImportBatch $batch, string $tenantId): array
    {
        $staging = ContactImportStaging::where('batch_id', $batch->id)
            ->where('status', 'new')
            ->get();

        $imported = 0;

        DB::transaction(function () use ($staging, $tenantId, &$imported) {
            foreach ($staging as $row) {
                $data = $row->mapped_data;
                $this->create($data, $tenantId);
                $row->update(['status' => 'imported']);
                $imported++;
            }
        });

        $batch->update([
            'status' => 'completed',
            'imported_at' => now(),
        ]);

        ContactImportCompleted::dispatch($tenantId, $batch->id, $imported, $batch->error_rows);

        if ($this->eventDispatcher) {
            $event = new ContactImportCompleted($tenantId, $batch->id, $imported, $batch->error_rows);
            $this->eventDispatcher->dispatch($event);
        }

        return ['imported' => $imported, 'batch_id' => $batch->id];
    }

    public function importGetStatus(string $batchId, string $tenantId): ?ContactImportBatch
    {
        return ContactImportBatch::where('tenant_id', $tenantId)->where('id', $batchId)->first();
    }

    private function mapImportRow(array $row, ?array $mapping): array
    {
        if (! $mapping) {
            return [
                'display_name' => $row['display_name'] ?? $row['name'] ?? null,
                'first_name' => $row['first_name'] ?? null,
                'last_name' => $row['last_name'] ?? null,
                'email' => $row['email'] ?? null,
                'phone' => $row['phone'] ?? null,
                'mobile' => $row['mobile'] ?? null,
                'company_name' => $row['company_name'] ?? $row['company'] ?? null,
                'contact_type' => $row['contact_type'] ?? 'individual',
            ];
        }

        $mapped = [];
        foreach ($mapping as $targetField => $sourceColumn) {
            $mapped[$targetField] = $row[$sourceColumn] ?? null;
        }

        return $mapped;
    }

    private function validateImportRow(array $data): array
    {
        $errors = [];

        if (empty($data['display_name']) && empty($data['first_name']) && empty($data['last_name'])) {
            $errors[] = 'At least one of display_name, first_name, or last_name is required.';
        }

        if (! empty($data['email']) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        }

        return $errors;
    }

    private function findDuplicateForImport(array $data, string $tenantId): ?CoreContact
    {
        if (! empty($data['email'])) {
            $existing = CoreContact::where('tenant_id', $tenantId)
                ->where('email', $data['email'])
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        if (! empty($data['phone'])) {
            $existing = CoreContact::where('tenant_id', $tenantId)
                ->where('phone', $data['phone'])
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        return null;
    }
}
