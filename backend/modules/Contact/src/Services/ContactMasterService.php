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
use Modules\Contact\src\Models\ContactAddress;
use Modules\Contact\src\Models\ContactIdentifier;
use Modules\Contact\src\Models\ContactImportBatch;
use Modules\Contact\src\Models\ContactImportStaging;
use Modules\Contact\src\Models\ContactMergeHistory;
use Modules\Contact\src\Models\ContactMethod;
use Modules\Contact\src\Models\ContactRelatedRecord;
use Modules\Contact\src\Models\ContactRelationship;
use Modules\Contact\src\Models\ContactRole;
use Modules\Contact\src\Models\ContactSearchIndex;
use Modules\Contact\src\Models\CoreContact;

/**
 * ContactMasterService — shared contact identity service (ADR 000 Pillar 3).
 *
 * Owns: contact identity, roles, identifiers, addresses, methods,
 * relationships, visibility, hierarchy, duplicate control, search index.
 *
 * Does NOT own: accounting invoices, HR payroll, property units, inventory items.
 */
class ContactMasterService
{
    use ApiResponse;

    private ?DomainEventDispatcher $eventDispatcher = null;

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
                'contact_type' => $data['contact_type'] ?? 'individual',
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
            'contact_type' => $data['contact_type'] ?? null,
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

    public function list(string $tenantId, array $filters = [])
    {
        $query = CoreContact::where('tenant_id', $tenantId);

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

        $contactRole = ContactRole::create([
            'id' => Str::uuid(),
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'role' => $role,
            'role_label' => $meta['role_label'] ?? null,
            'entity_id' => $meta['entity_id'] ?? null,
            'is_primary' => $meta['is_primary'] ?? false,
            'metadata' => $meta['metadata'] ?? null,
        ]);

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
    }

    // ─── Methods ──────────────────────────────────────────────────

    public function addMethod(string $contactId, array $data, string $tenantId): ?ContactMethod
    {
        $contact = $this->find($contactId, $tenantId);
        if (! $contact) {
            return null;
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
    }

    // ─── Relationships ────────────────────────────────────────────

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

        return ContactRelationship::create([
            'id' => Str::uuid(),
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'related_contact_id' => $relatedId,
            'relationship_type' => $type,
            'relationship_label' => $meta['relationship_label'] ?? null,
            'notes' => $meta['notes'] ?? null,
            'is_primary' => $meta['is_primary'] ?? false,
            'metadata' => $meta['metadata'] ?? null,
        ]);
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

        return DB::transaction(function () use ($primary, $merged, $tenantId, $userId) {
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

            // Mark merged contact
            $merged->update(['is_duplicate' => true, 'merged_into_id' => $primaryId, 'is_active' => false]);

            // Record merge history
            ContactMergeHistory::create([
                'id' => Str::uuid(),
                'tenant_id' => $tenantId,
                'primary_contact_id' => $primaryId,
                'merged_contact_id' => $mergedId,
                'merge_details' => ['roles', 'identifiers', 'addresses', 'methods', 'relationships', 'related_records'],
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
        $identifiers = $contact->identifiers->pluck('identifier_value')->implode(', ');
        $address = $contact->addresses->first();
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
            'primary_identifier' => $contact->identifiers->where('is_primary', true)->first()?->identifier_value,
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
