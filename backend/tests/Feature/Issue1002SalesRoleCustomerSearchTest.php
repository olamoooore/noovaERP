<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\LegalEntity;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #1002 (VC-003): on Create invoice, a Sales user typing "Elisa Koh" in
 * the Customer field saw "No customers found".
 *
 * The customer picker calls GET /api/v1/customers?search=... . These checks go
 * through the real router + middleware chain as a Sales-role user (central
 * user_company_access.role = 'sales', Spatie role "Sales" with the seeded
 * permission matrix) and assert the business rule: a customer is found by its
 * company name AND by the name of one of its contact persons, partial and
 * case-insensitive, within the user's legal-entity scope; a role without
 * customers.view is refused (403) rather than shown an empty list.
 */
class Issue1002SalesRoleCustomerSearchTest extends TenantTestCase
{
    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private Customer $clinic;

    private Customer $otherCompany;

    private Customer $outOfScope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => $this->tenantId,
            'name' => 'Issue1002 Customer Search Tenant',
        ]));

        $this->bindTenantContextWithPermissions($this->tenantId, null, 'Admin', false);

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'I1002 Group',
            'code' => 'I1002-'.Str::upper(Str::random(4)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityA = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Entity A 1002',
            'code' => 'A1002-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ])->id;

        $this->entityB = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Entity B 1002',
            'code' => 'B1002-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ])->id;

        $this->clinic = Customer::factory()->create([
            'name' => 'Elisa Koh Clinic and Surgery Pte Ltd',
            'company' => 'Elisa Koh Clinic and Surgery Pte Ltd',
            'legal_entity_id' => $this->entityA,
            'is_active' => true,
        ]);
        CustomerContact::create(['customer_id' => $this->clinic->id, 'name' => 'Dr Elisa Koh']);

        // A second company whose only link to the search is its contact person.
        $this->otherCompany = Customer::factory()->create([
            'name' => 'Northpoint Medical Group Pte Ltd',
            'company' => 'Northpoint Medical Group Pte Ltd',
            'legal_entity_id' => $this->entityA,
            'is_active' => true,
        ]);
        CustomerContact::create(['customer_id' => $this->otherCompany->id, 'name' => 'Dr Elisa Koh']);

        // Same contact name, but the customer belongs to an entity the user
        // has no access to.
        $this->outOfScope = Customer::factory()->create([
            'name' => 'Entity B Hospital Pte Ltd',
            'company' => 'Entity B Hospital Pte Ltd',
            'legal_entity_id' => $this->entityB,
            'is_active' => true,
        ]);
        CustomerContact::create(['customer_id' => $this->outOfScope->id, 'name' => 'Dr Elisa Koh']);
    }

    private function makeUser(string $role, int ...$entityIds): User
    {
        $user = User::factory()->create();

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $role, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $user->assignRole(UserRole::from($role)->spatieName());

        foreach ($entityIds as $index => $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id,
                'legal_entity_id' => $entityId,
                'role' => 'viewer',
                'is_default' => $index === 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    /** @return array<int, int> */
    private function searchIds(User $user, string $search): array
    {
        $response = $this->actingAs($user)->getJson('/api/v1/customers?'.http_build_query([
            'search' => $search,
            'per_page' => 50,
        ]));
        $response->assertOk();

        return collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_sales_user_finds_the_company_and_the_contact_person_match_by_full_name(): void
    {
        $sales = $this->makeUser('sales', $this->entityA);

        $response = $this->actingAs($sales)->getJson('/api/v1/customers?search=Elisa%20Koh&per_page=50');
        $response->assertOk();

        $rows = collect($response->json('data'))->keyBy(fn ($row) => (int) $row['id']);

        $this->assertTrue($rows->has((int) $this->clinic->id), 'The company named "Elisa Koh Clinic..." must be found.');
        $this->assertTrue($rows->has((int) $this->otherCompany->id), 'A company whose contact person is "Dr Elisa Koh" must be found.');
        $this->assertContains('Dr Elisa Koh', $rows[(int) $this->otherCompany->id]['matched_contacts'], 'The picker must be told the match came from the contact person.');
    }

    public function test_partial_and_case_insensitive_searches_find_both_customers(): void
    {
        $sales = $this->makeUser('sales', $this->entityA);

        foreach (['koh', 'elisa', 'ELISA KOH'] as $term) {
            $ids = $this->searchIds($sales, $term);
            $this->assertContains((int) $this->clinic->id, $ids, "Search '{$term}' must find the clinic.");
            $this->assertContains((int) $this->otherCompany->id, $ids, "Search '{$term}' must find the contact-person match.");
        }
    }

    public function test_customer_of_an_inaccessible_legal_entity_is_not_returned(): void
    {
        $sales = $this->makeUser('sales', $this->entityA);

        $ids = $this->searchIds($sales, 'Elisa Koh');

        $this->assertNotContains((int) $this->outOfScope->id, $ids, 'A customer of another legal entity must not be found.');
    }

    public function test_role_without_customers_view_is_refused_not_given_an_empty_list(): void
    {
        // Cashier is not granted customers.view in RolePermissionSeeder.
        $cashier = $this->makeUser('cashier', $this->entityA);

        $this->actingAs($cashier)
            ->getJson('/api/v1/customers?search=Elisa%20Koh')
            ->assertForbidden();
    }
}
