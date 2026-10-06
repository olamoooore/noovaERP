<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\LegalEntity;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #434 (P1, CU6) — the customer master was never entity-scoped.
 *
 * `customers.legal_entity_id` exists (Sales Orders inherit it from the
 * customer) but CustomerController index/show/update/delete/search/export
 * applied no EntityAccessService scoping: any user with customers.view could
 * list and edit every legal entity's customers.
 *
 * Policy decided with this issue (mirrors SalesOrderController #140/#237):
 *   - grants control the visible rows;
 *   - customers with legal_entity_id = NULL are shared and stay visible to
 *     every scoped user (the canonical creation path does not stamp an
 *     entity, so NULL is the normal "shared" case);
 *   - out-of-scope single-record reads/writes answer 404, exactly like
 *     SalesOrderController::findScopedOrder();
 *   - users with no entity grants keep the fail-open unscoped view.
 *
 * All checks are router-level HTTP requests.
 */
class HR434CustomerEntityScopingTest extends TenantTestCase
{
    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private Customer $customerA;

    private Customer $customerB;

    private Customer $sharedCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => $this->tenantId,
            'name' => 'HR434 Customer Entity Tenant',
        ]));

        $this->bindTenantContextWithPermissions($this->tenantId);

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'HR434 Group',
            'code' => 'HR434-'.Str::upper(Str::random(4)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityA = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Entity A 434',
            'code' => 'A434-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ])->id;

        $this->entityB = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Entity B 434',
            'code' => 'B434-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ])->id;

        $this->customerA = Customer::factory()->create([
            'name' => 'Entity A Customer',
            'legal_entity_id' => $this->entityA,
            'is_active' => true,
        ]);

        $this->customerB = Customer::factory()->create([
            'name' => 'Entity B Customer',
            'legal_entity_id' => $this->entityB,
            'is_active' => true,
        ]);

        $this->sharedCustomer = Customer::factory()->create([
            'name' => 'Shared Customer',
            'legal_entity_id' => null,
            'is_active' => true,
        ]);
    }

    /**
     * Manager carries customers.view + customers.write + customers.delete and
     * is NOT one of the implicit super roles — the permission gate is real.
     */
    private function makeUser(string $role = 'manager', int ...$entityIds): User
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

        $user->assignRole(UserRole::tryFrom($role)->spatieName());

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

    // ── List / search / export ────────────────────────────────────────────

    public function test_index_lists_only_in_scope_and_shared_customers(): void
    {
        $user = $this->makeUser('manager', $this->entityA);

        $response = $this->actingAs($user)->getJson('/api/v1/customers');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id);

        $this->assertTrue($ids->contains((int) $this->customerA->id), 'In-scope customer must be listed.');
        $this->assertTrue($ids->contains((int) $this->sharedCustomer->id), 'Shared (NULL entity) customer must stay visible.');
        $this->assertFalse($ids->contains((int) $this->customerB->id), 'Another entity\'s customer must not leak into the list.');
    }

    public function test_search_excludes_out_of_scope_customers(): void
    {
        $user = $this->makeUser('manager', $this->entityA);

        $response = $this->actingAs($user)->getJson('/api/v1/customers/search?q=Customer');
        $response->assertOk();

        $ids = collect($response->json('customers'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $this->customerA->id, $ids);
        $this->assertContains((int) $this->sharedCustomer->id, $ids);
        $this->assertNotContains((int) $this->customerB->id, $ids, 'Search must not reveal another entity\'s customer.');
    }

    public function test_export_excludes_out_of_scope_customers(): void
    {
        $user = $this->makeUser('manager', $this->entityA);

        $response = $this->actingAs($user)->get('/api/v1/customers/export?'.http_build_query([
            'columns' => ['name'],
            'format' => 'csv',
        ]));
        $response->assertOk();

        $content = (string) file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertStringContainsString('Entity A Customer', $content);
        $this->assertStringContainsString('Shared Customer', $content);
        $this->assertStringNotContainsString('Entity B Customer', $content, 'Export must not include another entity\'s customer.');
    }

    // ── Single record reads / writes ──────────────────────────────────────

    public function test_show_returns_404_for_out_of_scope_customer(): void
    {
        $user = $this->makeUser('manager', $this->entityA);

        $this->actingAs($user)->getJson("/api/v1/customers/{$this->customerA->id}")->assertOk();
        $this->actingAs($user)->getJson("/api/v1/customers/{$this->sharedCustomer->id}")->assertOk();
        $this->actingAs($user)->getJson("/api/v1/customers/{$this->customerB->id}")->assertNotFound();
    }

    public function test_update_returns_404_for_out_of_scope_customer_and_leaves_it_unchanged(): void
    {
        $user = $this->makeUser('manager', $this->entityA);

        $response = $this->actingAs($user)->putJson("/api/v1/customers/{$this->customerB->id}", [
            'name' => 'Hijacked By Entity A',
        ]);

        $response->assertNotFound();
        $this->assertSame('Entity B Customer', $this->customerB->fresh()->name, 'Out-of-scope customer must not be writable.');
    }

    public function test_destroy_returns_404_for_out_of_scope_customer_and_keeps_it(): void
    {
        $user = $this->makeUser('manager', $this->entityA);

        $response = $this->actingAs($user)->deleteJson("/api/v1/customers/{$this->customerB->id}");

        $response->assertNotFound();
        $this->assertTrue(
            DB::table('customers')->where('id', $this->customerB->id)->whereNull('deleted_at')->exists(),
            'Out-of-scope customer must survive a scoped delete.'
        );
    }

    public function test_bulk_delete_skips_out_of_scope_customers(): void
    {
        $user = $this->makeUser('manager', $this->entityA);

        $response = $this->actingAs($user)->postJson('/api/v1/customers/bulk-delete', [
            'ids' => [$this->customerA->id, $this->customerB->id],
        ]);
        $response->assertOk();

        $this->assertContains((int) $this->customerA->id, $response->json('deleted'));
        $this->assertNotContains((int) $this->customerB->id, $response->json('deleted'));
        $this->assertTrue(
            DB::table('customers')->where('id', $this->customerB->id)->whereNull('deleted_at')->exists(),
            'Bulk delete must not delete an out-of-scope customer.'
        );
    }

    public function test_related_single_customer_endpoints_are_scoped(): void
    {
        $user = $this->makeUser('manager', $this->entityA);
        $b = $this->customerB->id;

        $this->actingAs($user)->getJson("/api/v1/customers/{$b}/invoices")->assertNotFound();
        $this->actingAs($user)->getJson("/api/v1/customers/{$b}/context")->assertNotFound();
        $this->actingAs($user)->getJson("/api/v1/customers/{$b}/frequent-products")->assertNotFound();
        $this->actingAs($user)->getJson("/api/v1/customers/{$b}/statistics")->assertNotFound();
        $this->actingAs($user)->getJson("/api/v1/customers/{$b}/activity")->assertNotFound();
        $this->actingAs($user)->getJson("/api/v1/customers/{$b}/loyalty")->assertNotFound();

        // Mutations through the address sub-resource must be blocked too.
        $this->actingAs($user)->postJson("/api/v1/customers/{$b}/addresses", [
            'type' => 'billing',
            'street' => 'Should not be written',
        ])->assertNotFound();
        $this->assertFalse(
            CustomerAddress::where('customer_id', $b)->exists(),
            'No address may be written on an out-of-scope customer.'
        );
    }

    // ── Grant combinations ────────────────────────────────────────────────

    public function test_multi_entity_user_sees_the_customers_of_both_entities(): void
    {
        $user = $this->makeUser('manager', $this->entityA, $this->entityB);

        $response = $this->actingAs($user)->getJson('/api/v1/customers');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $this->customerA->id, $ids);
        $this->assertContains((int) $this->customerB->id, $ids);
        $this->assertContains((int) $this->sharedCustomer->id, $ids);

        $this->actingAs($user)->getJson("/api/v1/customers/{$this->customerB->id}")->assertOk();
    }

    public function test_user_without_entity_grants_keeps_the_fail_open_default(): void
    {
        $user = $this->makeUser('manager');

        $response = $this->actingAs($user)->getJson('/api/v1/customers');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $this->customerA->id, $ids);
        $this->assertContains((int) $this->customerB->id, $ids);
        $this->assertContains((int) $this->sharedCustomer->id, $ids);

        $this->actingAs($user)->getJson("/api/v1/customers/{$this->customerB->id}")->assertOk();
    }
}
