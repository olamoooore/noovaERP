<?php

namespace Modules\Contact\src\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\AccountingCustomerProfile;
use Modules\Contact\src\Models\AccountingVendorProfile;
use Modules\Contact\src\Models\CrmContactProfile;
use Modules\Contact\src\Models\HrStaffProfile;
use Modules\Contact\src\Models\PosLoyaltyProfile;
use Modules\Contact\src\Models\CoreContact;
use Modules\Contact\src\Models\ProjectContactProfile;
use Modules\Contact\src\Models\PropertyContactProfile;
use Modules\Contact\src\Services\ContactMasterService;
use Modules\Contact\src\Services\ContactTaxonomyService;
use Modules\Contact\src\Support\LegacyContactTypeMapping;

class ContactMasterController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ContactMasterService $service,
        private readonly \App\Services\EntityAccessService $entityAccessService,
    ) {}

    // ─── Contact CRUD ─────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $tenantId = tenant()->id;

        // Issue #232: contacts must be filtered by the requester's accessible
        // legal entities, not only by a client-supplied entity_id.
        $user = auth()->user();
        $accessibleEntityIds = $user
            ? $this->entityAccessService->accessibleEntityIds((int) $user->id)
            : [];

        $contacts = $this->service->list($tenantId, $request->only([
            'entity_id', 'legal_entity_id', 'contact_type', 'is_active', 'role', 'search', 'per_page',
        ]), $accessibleEntityIds);

        return $this->success($contacts, 'Contacts retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => 'sometimes|string|in:PERSON,ORGANISATION,person,organisation',
            'contact_type' => 'sometimes|string|in:individual,company',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'display_name' => 'required_without_all:first_name,last_name,company_name|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:255',
            'notes' => 'nullable|string',
            'entity_id' => 'nullable|uuid',
            'legal_entity_id' => 'nullable|integer',
            'legacy_id' => 'nullable|string|max:255',
            // ADR-003A compatibility inputs (non-authoritative, §7.2)
            'contact_category' => 'nullable|string|max:100',
            'legacy_contact_type' => 'nullable|string|max:100',
        ]);

        try {
            $contact = $this->service->create($validated, tenant()->id);
            $this->applyLegacyCompatibilityWrites($contact, $validated);

            return $this->success($contact->fresh(), 'Contact created.', 201);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $contact = $this->service->find($id, tenant()->id);
        if (! $contact) {
            return $this->notFound('Contact not found.');
        }

        $classifications = $this->service->classificationCodes($id, tenant()->id);
        $persistentRoles = $contact->roles
            ->whereNotNull('relationship_id')
            ->pluck('role')
            ->all();

        return $this->success([
            'contact' => $contact,
            'entity_type' => ContactMasterService::canonicalEntityType($contact->contact_type),
            'classification_codes' => $classifications,
            'persistent_role_codes' => $persistentRoles,
            // Non-authoritative legacy projection (ADR-003A §7.2)
            'legacy' => [
                'contact_type' => LegacyContactTypeMapping::projectLegacyContactType($classifications, $persistentRoles),
            ],
        ], 'Contact retrieved.');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => 'sometimes|string|in:PERSON,ORGANISATION,person,organisation',
            'contact_type' => 'sometimes|string|in:individual,company',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'display_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:255',
            'notes' => 'nullable|string',
            'entity_id' => 'nullable|uuid',
            'legal_entity_id' => 'nullable|integer',
            'is_active' => 'sometimes|boolean',
            // ADR-003A compatibility inputs (non-authoritative, §7.2)
            'contact_category' => 'nullable|string|max:100',
            'legacy_contact_type' => 'nullable|string|max:100',
        ]);

        $contact = $this->service->update($id, $validated, tenant()->id);
        if (! $contact) {
            return $this->notFound('Contact not found.');
        }

        try {
            $this->applyLegacyCompatibilityWrites($contact, $validated);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($contact->fresh(), 'Contact updated.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $deleted = $this->service->delete($id, tenant()->id);
            if (! $deleted) {
                return $this->notFound('Contact not found.');
            }

            return $this->success(null, 'Contact deleted.');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ─── Search ───────────────────────────────────────────────────

    public function search(Request $request): JsonResponse
    {
        $results = $this->service->search(tenant()->id, $request->only([
            'q', 'role', 'entity_id', 'per_page',
        ]));

        return $this->success($results, 'Search results.');
    }

    // ─── 360 View ─────────────────────────────────────────────────

    public function show360(Request $request, string $id): JsonResponse
    {
        $view = $this->service->get360View($id, tenant()->id);
        if (! $view) {
            return $this->notFound('Contact not found.');
        }

        return $this->success($view, 'Contact 360 view.');
    }

    // ─── Roles ────────────────────────────────────────────────────

    public function listRoles(Request $request, string $contactId): JsonResponse
    {
        $contact = $this->service->find($contactId, tenant()->id);
        if (! $contact) {
            return $this->notFound('Contact not found.');
        }

        return $this->success($contact->roles, 'Contact roles.');
    }

    public function addRole(Request $request, string $contactId): JsonResponse
    {
        $validated = $request->validate([
            'role' => 'required|string|max:50',
            'role_label' => 'nullable|string|max:255',
            'entity_id' => 'nullable|uuid',
            'legal_entity_id' => 'nullable|integer',
            'is_primary' => 'sometimes|boolean',
        ]);

        $role = $this->service->addRole($contactId, $validated['role'], tenant()->id, $validated);
        if (! $role) {
            return $this->notFound('Contact not found.');
        }

        return $this->success($role, 'Role added.', 201);
    }

    public function removeRole(Request $request, string $contactId, string $role): JsonResponse
    {
        $removed = $this->service->removeRole($contactId, $role, tenant()->id);
        if (! $removed) {
            return $this->notFound('Role not found.');
        }

        return $this->success(null, 'Role removed.');
    }

    // ─── Identifiers ──────────────────────────────────────────────

    public function listIdentifiers(Request $request, string $contactId): JsonResponse
    {
        $contact = $this->service->find($contactId, tenant()->id);
        if (! $contact) {
            return $this->notFound('Contact not found.');
        }

        return $this->success($contact->identifiers, 'Contact identifiers.');
    }

    public function addIdentifier(Request $request, string $contactId): JsonResponse
    {
        $validated = $request->validate([
            'identifier_type' => 'required|string|max:50',
            'identifier_value' => 'required|string|max:255',
            'identifier_label' => 'nullable|string|max:255',
            'source_system' => 'nullable|string|max:100',
            'is_primary' => 'sometimes|boolean',
        ]);

        $identifier = $this->service->addIdentifier(
            $contactId,
            $validated['identifier_type'],
            $validated['identifier_value'],
            tenant()->id,
            $validated
        );
        if (! $identifier) {
            return $this->notFound('Contact not found.');
        }

        return $this->success($identifier, 'Identifier added.', 201);
    }

    // ─── Addresses ────────────────────────────────────────────────

    public function listAddresses(Request $request, string $contactId): JsonResponse
    {
        $contact = $this->service->find($contactId, tenant()->id);
        if (! $contact) {
            return $this->notFound('Contact not found.');
        }

        return $this->success($contact->addresses, 'Contact addresses.');
    }

    public function addAddress(Request $request, string $contactId): JsonResponse
    {
        $validated = $request->validate([
            'address_type' => 'required|string|max:50',
            'label' => 'nullable|string|max:255',
            'line_1' => 'nullable|string|max:255',
            'line_2' => 'nullable|string|max:255',
            'line_3' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|size:2',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'is_primary' => 'sometimes|boolean',
            'is_default_billing' => 'sometimes|boolean',
            'is_default_shipping' => 'sometimes|boolean',
        ]);

        $address = $this->service->addAddress($contactId, $validated, tenant()->id);
        if (! $address) {
            return $this->notFound('Contact not found.');
        }

        return $this->success($address, 'Address added.', 201);
    }

    // ─── Methods ──────────────────────────────────────────────────

    public function listMethods(Request $request, string $contactId): JsonResponse
    {
        $contact = $this->service->find($contactId, tenant()->id);
        if (! $contact) {
            return $this->notFound('Contact not found.');
        }

        return $this->success($contact->methods, 'Contact methods.');
    }

    public function addMethod(Request $request, string $contactId): JsonResponse
    {
        $validated = $request->validate([
            'method_type' => 'required|string|max:50',
            'method_value' => 'required|string|max:255',
            'method_label' => 'nullable|string|max:255',
            'is_primary' => 'sometimes|boolean',
            'is_verified' => 'sometimes|boolean',
            'is_for_notifications' => 'sometimes|boolean',
        ]);

        $method = $this->service->addMethod($contactId, $validated, tenant()->id);
        if (! $method) {
            return $this->notFound('Contact not found.');
        }

        return $this->success($method, 'Method added.', 201);
    }

    // ─── Relationships ────────────────────────────────────────────

    public function listRelationships(Request $request, string $contactId): JsonResponse
    {
        $contact = $this->service->find($contactId, tenant()->id);
        if (! $contact) {
            return $this->notFound('Contact not found.');
        }

        return $this->success($contact->relationships, 'Contact relationships.');
    }

    public function addRelationship(Request $request, string $contactId): JsonResponse
    {
        $validated = $request->validate([
            'related_contact_id' => 'required|uuid|exists:core_contacts,id',
            'relationship_type' => 'required|string|max:50',
            'relationship_label' => 'nullable|string|max:255',
            // ADR-003A relationship-scoped attributes (org-person association)
            'functional_category_code' => 'nullable|string|max:50',
            'department_name' => 'nullable|string|max:150',
            'department_id' => 'nullable|uuid',
            'persistent_role_codes' => 'sometimes|array',
            'persistent_role_codes.*' => 'string|max:50',
            'notes' => 'nullable|string',
            'is_primary' => 'sometimes|boolean',
        ]);

        try {
            $rel = $this->service->addRelationship(
                $contactId,
                $validated['related_contact_id'],
                $validated['relationship_type'],
                tenant()->id,
                $validated
            );
            if (! $rel) {
                return $this->notFound('Contact not found.');
            }

            return $this->success($rel, 'Relationship added.', 201);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * ADR-003A: update relationship-scoped attributes (functional category,
     * department) on an organisation-person association.
     */
    public function updateRelationship(Request $request, string $relationshipId): JsonResponse
    {
        $validated = $request->validate([
            'functional_category_code' => 'present|nullable|string|max:50',
            'department_name' => 'present|nullable|string|max:150',
            'department_id' => 'nullable|uuid',
        ]);

        try {
            $rel = $this->service->updateRelationship($relationshipId, tenant()->id, $validated);
            if (! $rel) {
                return $this->notFound('Relationship not found.');
            }

            return $this->success($rel, 'Relationship updated.');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * ADR-003A: replace persistent relationship roles on an organisation-
     * person association. Only reserved persistent role codes are accepted.
     */
    public function setRelationshipRoles(Request $request, string $relationshipId): JsonResponse
    {
        $validated = $request->validate([
            'persistent_role_codes' => 'present|array',
            'persistent_role_codes.*' => 'string|max:50',
        ]);

        try {
            $roles = $this->service->setRelationshipRoles(
                $relationshipId,
                $validated['persistent_role_codes'],
                tenant()->id
            );

            return $this->success($roles, 'Persistent roles updated.');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), $e->getMessage() === 'CONTACT_RELATIONSHIP_NOT_FOUND' ? 404 : 422);
        }
    }

    // ─── Classifications ──────────────────────────────────────────

    public function listClassifications(Request $request, string $contactId): JsonResponse
    {
        $contact = $this->service->find($contactId, tenant()->id);
        if (! $contact) {
            return $this->notFound('Contact not found.');
        }

        return $this->success(
            ['classification_codes' => $this->service->classificationCodes($contactId, tenant()->id)],
            'Contact classifications.'
        );
    }

    public function assignClassification(Request $request, string $contactId): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50',
        ]);

        try {
            $member = $this->service->assignClassification($contactId, $validated['code'], tenant()->id);

            return $this->success($member, 'Classification assigned.', 201);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), $e->getMessage() === 'CONTACT_NOT_FOUND' ? 404 : 422);
        }
    }

    public function removeClassification(Request $request, string $contactId, string $code): JsonResponse
    {
        try {
            $removed = $this->service->removeClassification($contactId, $code, tenant()->id);
            if (! $removed) {
                return $this->notFound('Classification not found.');
            }

            return $this->success(null, 'Classification removed.');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ─── Taxonomy ─────────────────────────────────────────────────

    /**
     * ADR-003A: controlled taxonomy register (functional categories,
     * classifications, persistent roles) with tenant display labels.
     */
    public function taxonomy(Request $request): JsonResponse
    {
        $type = $request->query('type');
        if ($type && ! in_array($type, ['functional_category', 'classification', 'persistent_role'], true)) {
            return $this->error('Invalid taxonomy type.', 422);
        }

        $entries = app(ContactTaxonomyService::class)->list(tenant()->id, $type);

        return $this->success($entries, 'Contact taxonomy.');
    }

    /**
     * ADR-003A CT-VEN-015: tenant admins may change display labels of
     * reserved system codes; creating/renaming/repurposing codes is rejected.
     */
    public function updateTaxonomyLabel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'taxonomy_type' => 'required|string|in:functional_category,classification,persistent_role',
            'code' => 'required|string|max:50',
            'display_label' => 'required|string|max:100',
        ]);

        try {
            app(ContactTaxonomyService::class)->updateLabel(
                tenant()->id,
                $validated['taxonomy_type'],
                $validated['code'],
                $validated['display_label']
            );

            return $this->success(null, 'Display label updated.');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ─── Duplicates ───────────────────────────────────────────────

    public function duplicates(Request $request): JsonResponse
    {
        $candidates = $this->service->findDuplicates(tenant()->id);

        return $this->success($candidates, 'Duplicate candidates.');
    }

    public function merge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'primary_contact_id' => 'required|uuid|exists:core_contacts,id',
            'merged_contact_id' => 'required|uuid|exists:core_contacts,id',
        ]);

        $merged = $this->service->merge(
            $validated['primary_contact_id'],
            $validated['merged_contact_id'],
            tenant()->id,
            $request->user()->id,
        );

        if (! $merged) {
            return $this->error('Merge failed.', 422);
        }

        return $this->success(null, 'Contacts merged.');
    }

    // ─── Module Profiles ──────────────────────────────────────────

    public function profiles(Request $request, string $contactId): JsonResponse
    {
        $contact = $this->service->find($contactId, tenant()->id);
        if (! $contact) {
            return $this->notFound('Contact not found.');
        }

        return $this->success([
            'accounting_customer' => $contact->accountingCustomerProfile,
            'accounting_vendor' => $contact->accountingVendorProfile,
            'crm' => $contact->crmProfile,
            'hr' => $contact->hrProfile,
            'pos_loyalty' => $contact->posLoyaltyProfile,
            'property' => $contact->propertyProfile,
            'project' => $contact->projectProfile,
        ], 'Contact profiles.');
    }

    public function upsertAccountingCustomer(Request $request, string $contactId): JsonResponse
    {
        $validated = $request->validate([
            'customer_code' => 'nullable|string|max:50',
            'payment_terms' => 'nullable|string|max:50',
            'credit_limit' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'price_list' => 'nullable|string|max:100',
            'payment_method' => 'nullable|string|max:50',
        ]);

        $existing = AccountingCustomerProfile::where('tenant_id', tenant()->id)->where('contact_id', $contactId)->first();
        if ($existing) {
            $existing->update($validated);
            $profile = $existing;
        } else {
            $validated['id'] = Str::uuid()->toString();
            $validated['tenant_id'] = tenant()->id;
            $validated['contact_id'] = $contactId;
            $validated['account_status'] = $validated['account_status'] ?? 'active';
            $validated['current_balance'] = $validated['current_balance'] ?? 0;
            $profile = AccountingCustomerProfile::create($validated);
        }

        return $this->success($profile, 'Accounting customer profile saved.', 201);
    }

    public function upsertAccountingVendor(Request $request, string $contactId): JsonResponse
    {
        $validated = $request->validate([
            'vendor_code' => 'nullable|string|max:50',
            'payment_terms' => 'nullable|string|max:50',
            'credit_limit' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'payment_method' => 'nullable|string|max:50',
        ]);

        $existing = AccountingVendorProfile::where('tenant_id', tenant()->id)->where('contact_id', $contactId)->first();
        if ($existing) {
            $existing->update($validated);
            $profile = $existing;
        } else {
            $validated['id'] = Str::uuid()->toString();
            $validated['tenant_id'] = tenant()->id;
            $validated['contact_id'] = $contactId;
            $validated['account_status'] = $validated['account_status'] ?? 'active';
            $validated['current_balance'] = $validated['current_balance'] ?? 0;
            $profile = AccountingVendorProfile::create($validated);
        }

        return $this->success($profile, 'Accounting vendor profile saved.', 201);
    }

    public function upsertCrm(Request $request, string $contactId): JsonResponse
    {
        $validated = $request->validate([
            'lead_source' => 'nullable|string|max:100',
            'lead_status' => 'nullable|string|max:50',
            'lead_stage' => 'nullable|string|max:50',
            'assigned_to' => 'nullable|uuid',
            'industry' => 'nullable|string|max:100',
            'company_size' => 'nullable|string|max:50',
            'estimated_value' => 'nullable|numeric|min:0',
        ]);

        $existing = CrmContactProfile::where('tenant_id', tenant()->id)->where('contact_id', $contactId)->first();
        if ($existing) {
            $existing->update($validated);
            $profile = $existing;
        } else {
            $validated['id'] = Str::uuid()->toString();
            $validated['tenant_id'] = tenant()->id;
            $validated['contact_id'] = $contactId;
            $profile = CrmContactProfile::create($validated);
        }

        return $this->success($profile, 'CRM profile saved.', 201);
    }

    public function upsertHr(Request $request, string $contactId): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'nullable|string|max:50',
            'employment_status' => 'nullable|string|max:50',
            'employment_type' => 'nullable|string|max:50',
            'hire_date' => 'nullable|date',
            'department_id' => 'nullable|uuid',
            'position_id' => 'nullable|uuid',
            'salary' => 'nullable|numeric|min:0',
            'salary_currency' => 'nullable|string|size:3',
        ]);

        $existing = HrStaffProfile::where('tenant_id', tenant()->id)->where('contact_id', $contactId)->first();
        if ($existing) {
            $existing->update($validated);
            $profile = $existing;
        } else {
            $validated['id'] = Str::uuid()->toString();
            $validated['tenant_id'] = tenant()->id;
            $validated['contact_id'] = $contactId;
            $profile = HrStaffProfile::create($validated);
        }

        return $this->success($profile, 'HR profile saved.', 201);
    }

    public function upsertPosLoyalty(Request $request, string $contactId): JsonResponse
    {
        $validated = $request->validate([
            'membership_id' => 'nullable|string|max:50',
            'membership_tier' => 'nullable|string|max:50',
            'loyalty_points' => 'nullable|integer|min:0',
        ]);

        $existing = PosLoyaltyProfile::where('tenant_id', tenant()->id)->where('contact_id', $contactId)->first();
        if ($existing) {
            $existing->update($validated);
            $profile = $existing;
        } else {
            $validated['id'] = Str::uuid()->toString();
            $validated['tenant_id'] = tenant()->id;
            $validated['contact_id'] = $contactId;
            $profile = PosLoyaltyProfile::create($validated);
        }

        return $this->success($profile, 'POS loyalty profile saved.', 201);
    }

    public function upsertProperty(Request $request, string $contactId): JsonResponse
    {
        $validated = $request->validate([
            'contact_role' => 'required|string|max:50',
            'property_id' => 'nullable|uuid',
            'unit_id' => 'nullable|uuid',
            'lease_start_date' => 'nullable|date',
            'lease_end_date' => 'nullable|date',
            'monthly_rent' => 'nullable|numeric|min:0',
        ]);

        $existing = PropertyContactProfile::where('tenant_id', tenant()->id)->where('contact_id', $contactId)->where('contact_role', $validated['contact_role'])->first();
        if ($existing) {
            $existing->update($validated);
            $profile = $existing;
        } else {
            $validated['id'] = Str::uuid()->toString();
            $validated['tenant_id'] = tenant()->id;
            $validated['contact_id'] = $contactId;
            $profile = PropertyContactProfile::create($validated);
        }

        return $this->success($profile, 'Property profile saved.', 201);
    }

    public function upsertProject(Request $request, string $contactId): JsonResponse
    {
        $validated = $request->validate([
            'contact_role' => 'required|string|max:50',
            'project_id' => 'nullable|uuid',
            'contract_number' => 'nullable|string|max:100',
            'contract_value' => 'nullable|numeric|min:0',
        ]);

        $existing = ProjectContactProfile::where('tenant_id', tenant()->id)->where('contact_id', $contactId)->where('contact_role', $validated['contact_role'])->first();
        if ($existing) {
            $existing->update($validated);
            $profile = $existing;
        } else {
            $validated['id'] = Str::uuid()->toString();
            $validated['tenant_id'] = tenant()->id;
            $validated['contact_id'] = $contactId;
            $profile = ProjectContactProfile::create($validated);
        }

        return $this->success($profile, 'Project profile saved.', 201);
    }

    // ─── Import ─────────────────────────────────────────────────

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
            'mapping' => 'nullable|array',
        ]);

        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $fileHash = md5_file($file->getRealPath());

        $handle = fopen($file->getRealPath(), 'r');
        $headers = fgetcsv($handle);
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($headers, $row);
        }
        fclose($handle);

        $batch = $this->service->importCreateBatch([
            'file_name' => $fileName,
            'file_hash' => $fileHash,
            'total_rows' => count($rows),
            'mapping' => $request->input('mapping'),
        ], tenant()->id, $request->user()->id);

        $result = $this->service->importStageRows($batch, $rows, tenant()->id);

        return $this->success([
            'batch_id' => $batch->id,
            'total_rows' => $batch->total_rows,
            'valid' => $result['valid'],
            'errors' => $result['errors'],
            'duplicates' => $result['duplicates'],
        ], 'Import staged.', 201);
    }

    public function importStatus(Request $request, string $batch): JsonResponse
    {
        $batchModel = $this->service->importGetStatus($batch, tenant()->id);
        if (! $batchModel) {
            return $this->notFound('Import batch not found.');
        }

        return $this->success([
            'batch_id' => $batchModel->id,
            'status' => $batchModel->status,
            'total_rows' => $batchModel->total_rows,
            'valid_rows' => $batchModel->valid_rows,
            'error_rows' => $batchModel->error_rows,
            'duplicate_rows' => $batchModel->duplicate_rows,
            'imported_at' => $batchModel->imported_at,
        ], 'Import status.');
    }

    public function importConfirm(Request $request, string $batch): JsonResponse
    {
        $batchModel = $this->service->importGetStatus($batch, tenant()->id);
        if (! $batchModel) {
            return $this->notFound('Import batch not found.');
        }

        if ($batchModel->status !== 'validated') {
            return $this->error('Import batch is not in validated status.', 422);
        }

        $result = $this->service->importConfirm($batchModel, tenant()->id);

        return $this->success($result, 'Import confirmed.');
    }

    // ─── ADR-003A Legacy compatibility (§7.2) ────────────────────

    /**
     * Deterministic legacy contact_category/contact_type write transform.
     *
     * On a bare Contact Master write (no organisation-person association):
     *  - profession values -> Classification (person-level);
     *  - Primary -> rejected (requires a CRM customer association);
     *  - persistent function values -> rejected (require an organisation-
     *    person relationship; they are never PERSON intrinsics);
     *  - transaction-only values -> preserved in source metadata only;
     *  - unknown values -> rejected (never invent canonical codes).
     */
    private function applyLegacyCompatibilityWrites(CoreContact $contact, array $validated): void
    {
        $tenantId = tenant()->id;

        if (! empty($validated['contact_category'])) {
            $category = LegacyContactTypeMapping::resolveFunctionalCategory($validated['contact_category']);
            if ($category === null) {
                throw new \DomainException('UNKNOWN_TAXONOMY_VALUE');
            }
            // Functional category is relationship-scoped; on a bare contact
            // write it is preserved as source metadata, not a PERSON attribute.
            $metadata = $contact->metadata ?? [];
            $metadata['legacy_functional_category'] = $category;
            $contact->update(['metadata' => $metadata]);
        }

        if (empty($validated['legacy_contact_type'])) {
            return;
        }

        $mapped = LegacyContactTypeMapping::transformContactType($validated['legacy_contact_type']);

        if ($mapped['unknown']) {
            throw new \DomainException('UNKNOWN_TAXONOMY_VALUE');
        }

        if ($mapped['is_primary']) {
            throw new \DomainException('PRIMARY_REQUIRES_CRM_ASSOCIATION');
        }

        if ($mapped['persistent_role'] !== null) {
            throw new \DomainException('PERSISTENT_ROLE_REQUIRES_RELATIONSHIP');
        }

        if ($mapped['classification'] !== null) {
            $this->service->assignClassification($contact->id, $mapped['classification'], $tenantId);
        }

        if ($mapped['transaction_role'] !== null) {
            // Transaction-party roles stay in source/transaction context only.
            $metadata = $contact->fresh()->metadata ?? [];
            $metadata['source_transaction_roles'] = array_values(array_unique(array_merge(
                $metadata['source_transaction_roles'] ?? [],
                [$mapped['transaction_role']]
            )));
            $contact->update(['metadata' => $metadata]);
        }
    }
}
