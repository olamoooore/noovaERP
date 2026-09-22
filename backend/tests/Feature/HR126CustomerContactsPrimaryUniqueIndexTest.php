<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\CoreContact;
use Tests\TenantTestCase;

/**
 * Issue #126 (MEDIUM) — customer_contacts.is_primary must be self-enforcing
 * at the database layer, not only via a single service method.
 */
class HR126CustomerContactsPrimaryUniqueIndexTest extends TenantTestCase
{
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContext((string) Str::uuid());
        $this->assertTenantSchemaReady(['customer_contacts', 'customers']);

        $this->customer = Customer::factory()->create([
            'legal_entity_id' => null,
            'is_active' => true,
        ]);
    }

    public function test_partial_unique_index_is_present(): void
    {
        $index = DB::selectOne(
            "SELECT indexdef AS def FROM pg_indexes WHERE schemaname='public' AND indexname='customer_contacts_one_primary_per_customer'"
        );

        $this->assertNotNull($index, 'Partial unique index must exist');
        $this->assertStringContainsString('UNIQUE', (string) $index->def);
        $this->assertStringContainsString('WHERE', (string) $index->def, 'Partial index must carry a WHERE predicate');
    }

    public function test_a_second_primary_contact_for_the_same_customer_is_rejected_by_the_database(): void
    {
        $this->makeContact(isPrimary: true);
        $this->makeContact(isPrimary: false);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->makeContact(isPrimary: true);
    }

    public function test_primary_for_one_customer_does_not_block_another_customer(): void
    {
        $secondCustomer = Customer::factory()->create(['is_active' => true]);

        $this->makeContactFor($this->customer, isPrimary: true);

        // Different customer — primary allowed.
        $second = $this->makeContactFor($secondCustomer, isPrimary: true);

        $this->assertTrue((bool) $second->fresh()->is_primary);
    }

    public function test_soft_deleted_primary_frees_the_slot(): void
    {
        $first = $this->makeContact(isPrimary: true);
        $replacement = $this->makeContact(isPrimary: false);

        // Soft-deleting the first primary frees the slot for a new primary
        // on the same customer (the partial index excludes deleted rows).
        $first->delete();

        $replacement->update(['is_primary' => true]);

        $this->assertTrue((bool) $replacement->fresh()->is_primary);
    }

    private function makeContact(bool $isPrimary): CustomerContact
    {
        return $this->makeContactFor($this->customer, $isPrimary);
    }

    private function makeContactFor(Customer $customer, bool $isPrimary): CustomerContact
    {
        // Contact Master requires a core_contact row before a tenant-level
        // CustomerContact can reference it (FK on core_contact_uuid).
        $coreContact = CoreContact::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) Str::uuid(),
            'display_name' => 'HR126 Contact '.Str::random(4),
        ]);

        return CustomerContact::create([
            'customer_id' => $customer->id,
            'core_contact_uuid' => $coreContact->id,
            'name' => 'Contact '.Str::random(4),
            'is_primary' => $isPrimary,
        ]);
    }
}
