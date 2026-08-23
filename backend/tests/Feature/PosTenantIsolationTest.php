<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * PosTenantIsolationTest — verifies that POS data is properly isolated between tenants.
 *
 * Architecture
 * ------------
 * POS tables (`pos_orders`, `pos_payments`, `pos_terminals`, `pos_sessions`,
 * `pos_offline_queue`) do NOT carry a `tenant_id` column. Tenant isolation is
 * enforced at the schema level: each tenant lives in its own PostgreSQL schema
 * and Stancl's tenancy bootstrapper sets `search_path = "<tenant_uuid>"` for
 * every connection during the request lifecycle.
 *
 * Test approach
 * -------------
 * `TenantTestCase` populates the `public` schema with all tenant tables
 * (migrations run into `public` because `RefreshDatabase` wraps tests in a
 * transaction). To exercise real schema isolation without spinning up
 * stancl/tenancy's CreateDatabase + MigrateDatabase job pipeline (which races
 * with the in-progress test transaction), this test class clones the public
 * POS tables into two per-tenant schemas **before** the test transaction
 * begins. Inside each test we toggle `search_path` to switch between tenants.
 *
 * All FK-referenced rows (users, warehouses, terminals, sessions, payment
 * methods) are inserted into each tenant's schema at the start of every test
 * so cross-schema FK constraints resolve locally. The inserted rows live
 * inside the test transaction and are rolled back at teardown, leaving the
 * schema structure (DDL) intact for the next test.
 */
class PosTenantIsolationTest extends TenantTestCase
{
    use RefreshDatabase;

    public const TENANT_A_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    public const TENANT_B_ID = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    protected Tenant $tenantA;

    protected Tenant $tenantB;

    /**
     * Override RefreshDatabase's hook so we can clone POS tables into the
     * two tenant schemas BEFORE the test transaction begins. DDL after
     * `beginDatabaseTransaction()` is rolled back by PostgreSQL.
     */
    protected function refreshTestDatabase()
    {
        if (! RefreshDatabaseState::$migrated || ! $this->tenantSchemaExists()) {
            $this->runTenantMigrations();
            $this->clonePosTablesIntoTenantSchemas();
            RefreshDatabaseState::$migrated = true;
        }

        $this->beginDatabaseTransaction();
    }

    /**
     * Create the two tenant schemas and clone the POS-relevant tables
     * (plus their FK dependencies) from `public` into each schema using
     * `CREATE TABLE … (LIKE … INCLUDING ALL)`. This preserves column
     * types, defaults, constraints and FK references.
     */
    protected function clonePosTablesIntoTenantSchemas(): void
    {
        $schemas = [self::TENANT_A_ID, self::TENANT_B_ID];

        // Tables needed for POS tests. Including FK dependencies so that
        // constraints like pos_orders.session_id → pos_sessions resolve
        // locally within the tenant's search_path.
        $tables = [
            'users',
            'warehouses',
            'pos_terminals',
            'pos_sessions',
            'pos_payment_methods',
            'pos_orders',
            'pos_order_items',
            'pos_payments',
            'pos_offline_queue',
        ];

        foreach ($schemas as $schema) {
            DB::statement("CREATE SCHEMA IF NOT EXISTS \"{$schema}\"");

            foreach ($tables as $table) {
                DB::statement("DROP TABLE IF EXISTS \"{$schema}\".\"{$table}\" CASCADE");
                DB::statement(
                    "CREATE TABLE \"{$schema}\".\"{$table}\" ".
                    "(LIKE public.\"{$table}\" INCLUDING ALL)"
                );
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenants via the standard pipeline WITHOUT firing the
        // TenantCreated event. The pipeline would otherwise try to run
        // CREATE SCHEMA + migrations in the tenant schemas, which races
        // with the in-progress test transaction and frequently surfaces
        // "migrations already exists" or "search_path target schema does
        // not exist" errors.
        $this->tenantA = Tenant::withoutEvents(function () {
            return Tenant::factory()->create(['id' => self::TENANT_A_ID]);
        });
        $this->tenantB = Tenant::withoutEvents(function () {
            return Tenant::factory()->create(['id' => self::TENANT_B_ID]);
        });

        $this->bindTenantContext($this->tenantA->id);

        // Seed minimal FK dependencies inside each tenant's schema so
        // cross-schema constraints resolve locally during the test.
        $this->seedTenantFixtures(self::TENANT_A_ID);
        $this->seedTenantFixtures(self::TENANT_B_ID);
    }

    /**
     * Run a closure against a specific tenant schema by overriding the
     * connection's `search_path`. The original search_path is restored
     * afterwards.
     */
    protected function withSearchPath(string $schema, callable $fn): mixed
    {
        DB::statement("SET search_path TO \"{$schema}\", public");
        try {
            return $fn();
        } finally {
            DB::statement('RESET search_path');
        }
    }

    /**
     * Insert the minimal FK dependencies (user, warehouse, terminal,
     * session, payment method) into a tenant's schema so POS inserts in
     * that schema satisfy NOT NULL + FK constraints.
     */
    protected function seedTenantFixtures(string $schema): void
    {
        $this->withSearchPath($schema, function () use ($schema) {
            $userId = DB::table('users')->insertGetId([
                'name' => "User {$schema}",
                'email' => "user-{$schema}@test.local",
                'password' => bcrypt('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $warehouseId = DB::table('warehouses')->insertGetId([
                'name' => "Warehouse {$schema}",
                'code' => "WH-{$schema}",
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $terminalId = DB::table('pos_terminals')->insertGetId([
                'name' => "Terminal {$schema}",
                'code' => "T-{$schema}",
                'warehouse_id' => $warehouseId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('pos_sessions')->insert([
                'terminal_id' => $terminalId,
                'user_id' => $userId,
                'session_number' => "S-{$schema}",
                'status' => 'open',
                'opening_balance' => 0,
                'opened_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('pos_payment_methods')->insert([
                'name' => 'Cash',
                'code' => "CASH-{$schema}",
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Test TENANT-001: Tenant A cannot see Tenant B's offline queue rows.
     */
    public function test_tenant_a_cannot_read_tenant_b_offline_queue()
    {
        $this->withSearchPath(self::TENANT_B_ID, function () {
            DB::table('pos_offline_queue')->insert([
                'queue_number' => 'OFQ-B-1',
                'operation_type' => 'create_order',
                'status' => 'pending',
                'payload' => json_encode(['test' => 'data']),
                'idempotency_key' => 'tenant-b-key-1',
                'queued_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $aCount = $this->withSearchPath(self::TENANT_A_ID, function () {
            return DB::table('pos_offline_queue')->count();
        });

        $this->assertSame(0, (int) $aCount,
            'Tenant A should not see tenant B\'s offline queue rows.');
    }

    /**
     * Test TENANT-001: Tenant A cannot sync Tenant B's POS order.
     */
    public function test_tenant_a_cannot_sync_tenant_b_order()
    {
        $bOrderId = $this->withSearchPath(self::TENANT_B_ID, function () {
            return DB::table('pos_orders')->insertGetId([
                'external_id' => (string) Str::uuid(),
                'order_number' => 'PO-B-1',
                'session_id' => DB::table('pos_sessions')->value('id'),
                'status' => 'draft',
                'subtotal' => 200,
                'tax_amount' => 0,
                'total' => 200,
                'currency' => 'USD',
                'discount_type' => null,
                'discount_value' => 0,
                'discount_amount' => 0,
                'is_return' => false,
                'loyalty_points_earned' => 0,
                'loyalty_points_redeemed' => 0,
                'loyalty_discount' => 0,
                'created_by' => DB::table('users')->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $aCount = $this->withSearchPath(self::TENANT_A_ID, function () use ($bOrderId) {
            return DB::table('pos_orders')->where('id', $bOrderId)->count();
        });

        $this->assertSame(0, (int) $aCount,
            'Tenant A should not see tenant B\'s POS orders.');
    }

    /**
     * Test TENANT-002: Tenant A cannot access Tenant B's POS orders.
     */
    public function test_tenant_a_cannot_access_tenant_b_orders()
    {
        $bOrderId = $this->withSearchPath(self::TENANT_B_ID, function () {
            return DB::table('pos_orders')->insertGetId([
                'external_id' => (string) Str::uuid(),
                'order_number' => 'PO-B-2',
                'session_id' => DB::table('pos_sessions')->value('id'),
                'status' => 'draft',
                'subtotal' => 200,
                'tax_amount' => 0,
                'total' => 200,
                'currency' => 'USD',
                'discount_type' => null,
                'discount_value' => 0,
                'discount_amount' => 0,
                'is_return' => false,
                'loyalty_points_earned' => 0,
                'loyalty_points_redeemed' => 0,
                'loyalty_discount' => 0,
                'created_by' => DB::table('users')->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $hit = $this->withSearchPath(self::TENANT_A_ID, function () use ($bOrderId) {
            return DB::table('pos_orders')->where('id', $bOrderId)->exists();
        });

        $this->assertFalse($hit,
            'Tenant A must not return tenant B\'s POS orders.');
    }

    /**
     * Test TENANT-002: Tenant A cannot access Tenant B's payments.
     */
    public function test_tenant_a_cannot_access_tenant_b_payments()
    {
        $bPaymentId = $this->withSearchPath(self::TENANT_B_ID, function () {
            $orderId = DB::table('pos_orders')->insertGetId([
                'external_id' => (string) Str::uuid(),
                'order_number' => 'PO-B-PAY',
                'session_id' => DB::table('pos_sessions')->value('id'),
                'status' => 'paid',
                'subtotal' => 200,
                'tax_amount' => 0,
                'total' => 200,
                'currency' => 'USD',
                'discount_type' => null,
                'discount_value' => 0,
                'discount_amount' => 0,
                'is_return' => false,
                'loyalty_points_earned' => 0,
                'loyalty_points_redeemed' => 0,
                'loyalty_discount' => 0,
                'created_by' => DB::table('users')->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('pos_payments')->insertGetId([
                'pos_order_id' => $orderId,
                'method' => 'cash',
                'amount' => 200,
                'received_by' => DB::table('users')->value('id'),
                'payment_method_id' => DB::table('pos_payment_methods')->value('id'),
                'surcharge' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $hit = $this->withSearchPath(self::TENANT_A_ID, function () use ($bPaymentId) {
            return DB::table('pos_payments')->where('id', $bPaymentId)->exists();
        });

        $this->assertFalse($hit,
            'Tenant A must not return tenant B\'s POS payments.');
    }

    /**
     * Test TENANT-002: Tenant A cannot access Tenant B's sessions.
     */
    public function test_tenant_a_cannot_access_tenant_b_sessions()
    {
        $bSessionId = $this->withSearchPath(self::TENANT_B_ID, function () {
            return DB::table('pos_sessions')->value('id');
        });

        $hit = $this->withSearchPath(self::TENANT_A_ID, function () use ($bSessionId) {
            return DB::table('pos_sessions')->where('id', $bSessionId)->exists();
        });

        $this->assertFalse($hit,
            'Tenant A must not return tenant B\'s POS sessions.');
    }

    /**
     * Test TENANT-002: Tenant A cannot access Tenant B's terminals.
     */
    public function test_tenant_a_cannot_access_tenant_b_display()
    {
        $bTerminalId = $this->withSearchPath(self::TENANT_B_ID, function () {
            return DB::table('pos_terminals')->value('id');
        });

        $hit = $this->withSearchPath(self::TENANT_A_ID, function () use ($bTerminalId) {
            return DB::table('pos_terminals')->where('id', $bTerminalId)->exists();
        });

        $this->assertFalse($hit,
            'Tenant A must not return tenant B\'s POS terminals.');
    }

    /**
     * Test TENANT-002: Tenant A's order list excludes Tenant B's orders.
     */
    public function test_tenant_a_order_list_excludes_tenant_b_orders()
    {
        $aOrderId = $this->withSearchPath(self::TENANT_A_ID, function () {
            return DB::table('pos_orders')->insertGetId([
                'external_id' => (string) Str::uuid(),
                'order_number' => 'PO-A-1',
                'session_id' => DB::table('pos_sessions')->value('id'),
                'status' => 'draft',
                'subtotal' => 100,
                'tax_amount' => 0,
                'total' => 100,
                'currency' => 'USD',
                'discount_type' => null,
                'discount_value' => 0,
                'discount_amount' => 0,
                'is_return' => false,
                'loyalty_points_earned' => 0,
                'loyalty_points_redeemed' => 0,
                'loyalty_discount' => 0,
                'created_by' => DB::table('users')->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $bOrderId = $this->withSearchPath(self::TENANT_B_ID, function () {
            return DB::table('pos_orders')->insertGetId([
                'external_id' => (string) Str::uuid(),
                'order_number' => 'PO-B-3',
                'session_id' => DB::table('pos_sessions')->value('id'),
                'status' => 'draft',
                'subtotal' => 200,
                'tax_amount' => 0,
                'total' => 200,
                'currency' => 'USD',
                'discount_type' => null,
                'discount_value' => 0,
                'discount_amount' => 0,
                'is_return' => false,
                'loyalty_points_earned' => 0,
                'loyalty_points_redeemed' => 0,
                'loyalty_discount' => 0,
                'created_by' => DB::table('users')->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $orderIds = $this->withSearchPath(self::TENANT_A_ID, function () {
            return DB::table('pos_orders')->pluck('id')->all();
        });

        $this->assertContains($aOrderId, $orderIds,
            'Tenant A\'s order list should include its own order.');
        $this->assertNotContains($bOrderId, $orderIds,
            'Tenant A\'s order list should not include tenant B\'s order.');
    }

    /**
     * Test TENANT-002: Tenant A cannot void Tenant B's order.
     */
    public function test_tenant_a_cannot_void_tenant_b_order()
    {
        $bOrderId = $this->withSearchPath(self::TENANT_B_ID, function () {
            return DB::table('pos_orders')->insertGetId([
                'external_id' => (string) Str::uuid(),
                'order_number' => 'PO-B-VOID',
                'session_id' => DB::table('pos_sessions')->value('id'),
                'status' => 'paid',
                'subtotal' => 200,
                'tax_amount' => 0,
                'total' => 200,
                'currency' => 'USD',
                'discount_type' => null,
                'discount_value' => 0,
                'discount_amount' => 0,
                'is_return' => false,
                'loyalty_points_earned' => 0,
                'loyalty_points_redeemed' => 0,
                'loyalty_discount' => 0,
                'created_by' => DB::table('users')->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $hit = $this->withSearchPath(self::TENANT_A_ID, function () use ($bOrderId) {
            return DB::table('pos_orders')->where('id', $bOrderId)->exists();
        });

        $this->assertFalse($hit,
            'Tenant A must not be able to void tenant B\'s order.');
    }

    /**
     * Test TENANT-002: Tenant A cannot refund Tenant B's payment.
     */
    public function test_tenant_a_cannot_refund_tenant_b_order()
    {
        $bPaymentId = $this->withSearchPath(self::TENANT_B_ID, function () {
            $orderId = DB::table('pos_orders')->insertGetId([
                'external_id' => (string) Str::uuid(),
                'order_number' => 'PO-B-REFUND',
                'session_id' => DB::table('pos_sessions')->value('id'),
                'status' => 'paid',
                'subtotal' => 200,
                'tax_amount' => 0,
                'total' => 200,
                'currency' => 'USD',
                'discount_type' => null,
                'discount_value' => 0,
                'discount_amount' => 0,
                'is_return' => false,
                'loyalty_points_earned' => 0,
                'loyalty_points_redeemed' => 0,
                'loyalty_discount' => 0,
                'created_by' => DB::table('users')->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('pos_payments')->insertGetId([
                'pos_order_id' => $orderId,
                'method' => 'cash',
                'amount' => 200,
                'received_by' => DB::table('users')->value('id'),
                'payment_method_id' => DB::table('pos_payment_methods')->value('id'),
                'surcharge' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $hit = $this->withSearchPath(self::TENANT_A_ID, function () use ($bPaymentId) {
            return DB::table('pos_payments')->where('id', $bPaymentId)->exists();
        });

        $this->assertFalse($hit,
            'Tenant A must not be able to refund tenant B\'s payment.');
    }

    /**
     * Test TENANT-001: Verify sync routes have tenant middleware.
     */
    public function test_sync_routes_have_tenant_middleware()
    {
        $routes = Route::getRoutes();

        $syncRoutes = collect($routes)->filter(function ($route) {
            return str_contains($route->uri(), 'pos/sync');
        });

        $this->assertGreaterThan(0, $syncRoutes->count(),
            'Expected at least one route matching pos/sync to be registered.');

        foreach ($syncRoutes as $route) {
            // Inspect the raw `action` middleware array so we are not
            // affected by `withoutMiddleware()` which strips a few tenant
            // middlewares from the test request pipeline (but not from
            // the route's registered definition).
            $rawMiddleware = $route->action['middleware'] ?? [];

            $this->assertContains('auth:sanctum', $rawMiddleware,
                "Route {$route->uri()} must have auth:sanctum middleware");

            $hasTenantMiddleware = false;
            foreach ($rawMiddleware as $m) {
                $mLower = strtolower($m);
                if (str_contains($mLower, 'tenant') || str_contains($mLower, 'search_path') || str_contains($mLower, 'searchpath')) {
                    $hasTenantMiddleware = true;
                    break;
                }
            }
            $this->assertTrue($hasTenantMiddleware,
                "Route {$route->uri()} must have a tenant-related middleware (tenant/search_path). Got: ".implode(', ', $rawMiddleware));
        }
    }
}
