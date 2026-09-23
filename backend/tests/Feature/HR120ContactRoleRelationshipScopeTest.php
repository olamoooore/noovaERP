<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactRole;
use Modules\Contact\src\Services\ContactMasterService;
use Tests\TenantTestCase;

/**
 * Issue #120 — the same person holding the same persistent role under two
 * different relationships (a realistic multi-organisation case) must be
 * representable; genuine duplicates must surface as a handled domain error,
 * not an uncaught 500 from the unique index.
 */
class HR120ContactRoleRelationshipScopeTest extends TenantTestCase
{
    private string $tenantId;

    private string $personId;

    private string $otherId;

    private string $orgBId;

    private ContactMasterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->service = app(ContactMasterService::class);

        $mkContact = fn (string $name) => DB::table('core_contacts')->insertGetId([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'display_name' => $name,
            'contact_type' => 'individual',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');

        $this->personId = $mkContact('Shared Person');
        $this->otherId = $mkContact('Client Contact');
        $this->orgBId = $mkContact('Second Organisation');
    }

    public function test_same_role_is_allowed_under_two_different_relationships(): void
    {
        $relationshipA = $this->service->addRelationship(
            $this->personId, $this->otherId, 'billing_contact', $this->tenantId
        );
        $relationshipB = $this->service->addRelationship(
            $this->personId, $this->orgBId, 'billing_contact', $this->tenantId, ['notes' => 'second org']
        );

        $this->assertNotNull($relationshipA);
        $this->assertNotSame($relationshipA->id, $relationshipB?->id);

        $roleA = $this->service->addRole($this->personId, 'BILLING_CONTACT', $this->tenantId, [
            'relationship_id' => $relationshipA->id,
        ]);
        $roleB = $this->service->addRole($this->personId, 'BILLING_CONTACT', $this->tenantId, [
            'relationship_id' => $relationshipB->id,
        ]);

        $this->assertNotNull($roleA);
        $this->assertNotNull($roleB, 'The same role under a second relationship must not 500');

        $this->assertSame(2, ContactRole::where('contact_id', $this->personId)
            ->where('role', 'BILLING_CONTACT')
            ->count());
    }

    public function test_exact_duplicate_role_is_idempotent(): void
    {
        // contact_roles.entity_id is a loose uuid (no FK to legal_entities).
        $entityId = (string) Str::uuid();

        $this->service->addRole($this->personId, 'BILLING_CONTACT', $this->tenantId, [
            'entity_id' => $entityId,
        ]);

        $first = ContactRole::where('contact_id', $this->personId)->first();

        $again = $this->service->addRole($this->personId, 'BILLING_CONTACT', $this->tenantId, [
            'entity_id' => $entityId,
        ]);

        $this->assertSame($first->id, $again->id, 'Exact duplicate add-role must return the existing row (idempotent)');
        $this->assertSame(1, ContactRole::where('contact_id', $this->personId)->count());
    }
}
