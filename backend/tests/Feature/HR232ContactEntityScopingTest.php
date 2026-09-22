<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\CoreContact;
use Tests\TenantTestCase;

/**
 * Issue #232 (HIGH) — GET /api/v1/contacts was not filtered by the requester's
 * accessible legal entities: #119's fix rewrote the contract doc to claim
 * EntityAccessService owned contact access control, but never wired a call site.
 *
 * A user granted only Entity A could list every contact in the tenant
 * (including Entity B's) by omitting entity_id.
 */
class HR232ContactEntityScopingTest extends TenantTestCase
{
    private string $tenantId;

    private int $entityA;

    private int $entityB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR232 Tenant', 'email' => 'hr232-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'HR232 Group', 'code' => 'HG-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD', 'fiscal_year_start' => '01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mkEntity = fn (string $name, string $code) => DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => $name, 'code' => $code,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityA = $mkEntity('Entity A', 'A-'.Str::upper(Str::random(5)));
        $this->entityB = $mkEntity('Entity B', 'B-'.Str::upper(Str::random(5)));

        $mkContact = fn (string $name, int $entityId) => CoreContact::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'display_name' => $name,
            'legal_entity_id' => $entityId,
            'contact_type' => 'individual',
            'is_active' => true,
        ]);
        $mkContact('Contact A', $this->entityA);
        $mkContact('Contact B', $this->entityB);
    }

    private function actingAsViewer(?int $grantEntityId = null): User
    {
        $user = User::factory()->create();
        $user->assignRole('Viewer');

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'viewer', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        if ($grantEntityId !== null) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id,
                'legal_entity_id' => $grantEntityId,
                'role' => 'viewer',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    public function test_scoped_user_sees_only_their_entity_contacts(): void
    {
        $user = $this->actingAsViewer($this->entityA);

        $response = $this->actingAs($user)->getJson('/api/v1/contacts');

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $names = collect($response->json('data.data'))->pluck('display_name')->all();
        $this->assertContains('Contact A', $names);
        $this->assertNotContains('Contact B', $names,
            'Entity-scoped user must not see another entity\'s contact');
    }

    public function test_scoped_user_cannot_widen_scope_with_foreign_entity_filter(): void
    {
        $user = $this->actingAsViewer($this->entityA);

        $response = $this->actingAs($user)->getJson('/api/v1/contacts?legal_entity_id='.$this->entityB);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $response->json('data.data'),
            'Passing Entity B\'s id must not widen the scope beyond the user\'s grants');
    }

    public function test_scoped_user_can_filter_within_their_entity(): void
    {
        $user = $this->actingAsViewer($this->entityA);

        $response = $this->actingAs($user)->getJson('/api/v1/contacts?legal_entity_id='.$this->entityA);

        $this->assertSame(200, $response->getStatusCode());
        $names = collect($response->json('data.data'))->pluck('display_name')->all();
        $this->assertSame(['Contact A'], $names);
    }

    public function test_user_without_entity_grants_keeps_legacy_full_visibility(): void
    {
        // Platform convention shared with JournalEntry/FixedAsset (#97/#163):
        // users with zero entity grants keep the fail-open default.
        $user = $this->actingAsViewer(null);

        $response = $this->actingAs($user)->getJson('/api/v1/contacts');

        $this->assertSame(200, $response->getStatusCode());
        $names = collect($response->json('data.data'))->pluck('display_name')->all();
        $this->assertContains('Contact A', $names);
        $this->assertContains('Contact B', $names);
    }
}
