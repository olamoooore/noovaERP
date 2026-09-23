<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #235 (HIGH) — DashboardController::getOrdersToday() scoped the
 * SalesOrder count but added PosOrder/WebOrder counts tenant-wide, blending
 * other entities' order activity into the Master Dashboard figure.
 *
 * Requires migration 2026_09_22_000010_add_legal_entity_to_pos_and_web_orders.
 */
class HR235DashboardOrdersTodayEntityScopingTest extends TenantTestCase
{
    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private int $actorId;

    private int $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'HR235 Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'HR235 Group', 'code' => 'EG-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD', 'fiscal_year_start' => '01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity A', 'code' => 'A-'.Str::upper(Str::random(5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity B', 'code' => 'B-'.Str::upper(Str::random(5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $now = now();
        $this->actorId = DB::table('users')->insertGetId([
            'name' => 'HR235 Actor',
            'email' => 'hr235-actor-'.Str::random(6).'@example.com',
            'password' => bcrypt('password'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $terminalId = DB::table('pos_terminals')->insertGetId([
            'name' => 'HR235 Terminal', 'code' => 'T-'.Str::upper(Str::random(5)), 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->sessionId = DB::table('pos_sessions')->insertGetId([
            'terminal_id' => $terminalId,
            'user_id' => $this->actorId,
            'session_number' => 'S-'.Str::upper(Str::random(5)),
            'status' => 'open',
            'opening_balance' => 0,
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([[$this->entityA, 'POS-A'], [$this->entityB, 'POS-B']] as [$entityId, $number]) {
            DB::table('pos_orders')->insert([
                'order_number' => $number,
                'external_id' => (string) Str::uuid(),
                'legal_entity_id' => $entityId,
                'created_by' => $this->actorId,
                'session_id' => $this->sessionId,
                'discount_value' => 0,
                'discount_amount' => 0,
                'is_return' => false,
                'loyalty_points_earned' => 0,
                'loyalty_points_redeemed' => 0,
                'subtotal' => 10, 'tax_amount' => 0, 'total' => 10, 'currency' => 'USD',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ([[$this->entityA, 'WEB-A'], [$this->entityB, 'WEB-B']] as [$entityId, $number]) {
            DB::table('web_orders')->insert([
                'order_number' => $number,
                'email' => strtolower($number).'@example.com',
                'legal_entity_id' => $entityId,
                'subtotal' => 10, 'total' => 10, 'currency' => 'USD',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function actingAsViewer(?int $grantEntityId = null): User
    {
        $user = User::factory()->create();
        $user->assignRole('Viewer');

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

    public function test_orders_today_counts_only_the_scoped_users_entity(): void
    {
        $response = $this->actingAs($this->actingAsViewer($this->entityA))->getJson('/api/v1/dashboard');

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame(2, $response->json('orders_today'),
            'Entity A has one POS + one web order today; Entity B\'s two must not count');
        $this->assertSame(1, $response->json('pos_orders_today'),
            'pos_orders_today must exclude the other entity\'s POS order');
    }

    public function test_orders_today_unscoped_user_sees_the_whole_tenant(): void
    {
        $response = $this->actingAs($this->actingAsViewer(null))->getJson('/api/v1/dashboard');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(4, $response->json('orders_today'),
            'Users without entity grants keep the fail-open tenant-wide view');
    }
}
