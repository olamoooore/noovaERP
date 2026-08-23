<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class PosSessionEnforcementTest extends TenantTestCase
{

    protected string $tenantId;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        // Tenant migrations are run by RefreshDatabase trait via
        // TenantTestCase::refreshTestDatabase; do NOT call migrate:fresh
        // because it would drop the tenant tables this test depends on.

        // Bind a tenant context so middleware / feature gates resolve.
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->tenantId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'Session Enforcement Test Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });
        $this->bindTenantContext($this->tenantId);

        // Create a cashier user + user_company_access row so the
        // RequirePermission middleware passes for pos.create.
        $this->cashier = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->cashier->id,
            'tenant_id' => $this->tenantId,
            'role' => 'cashier',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTerminalWithWarehouse(): PosTerminal
    {
        $warehouse = Warehouse::create([
            'name' => 'POS Warehouse',
            'code' => 'POS-WH-001',
            'is_active' => true,
        ]);

        return PosTerminal::create([
            'name' => 'POS Terminal 1',
            'terminal_number' => 'TERM-001',
            'warehouse_id' => $warehouse->id,
            'is_active' => true,
        ]);
    }

    private function createProduct(): \App\Models\Product
    {
        return \App\Models\Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-PROD-'.uniqid(),
            'price' => 50.00,
            'cost' => 25.00,
            'track_inventory' => true,
            'is_active' => true,
        ]);
    }

    private function createOpenSession(PosTerminal $terminal): PosSession
    {
        return PosSession::create([
            'terminal_id' => $terminal->id,
            'user_id' => $this->cashier->id,
            'status' => 'open',
            'opened_at' => now(),
            'opened_by' => $this->cashier->id,
        ]);
    }

    private function createClosedSession(PosTerminal $terminal): PosSession
    {
        return PosSession::create([
            'terminal_id' => $terminal->id,
            'user_id' => $this->cashier->id,
            'status' => 'closed',
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
            'opened_by' => $this->cashier->id,
        ]);
    }

    private function createDraftOrder(PosTerminal $terminal, ?PosSession $session = null): PosOrder
    {
        return PosOrder::create([
            'external_id' => (string) \Illuminate\Support\Str::uuid(),
            'order_number' => 'POS-TEST-'.uniqid(),
            'status' => 'draft',
            'terminal_id' => $terminal->id,
            'session_id' => $session?->id,
            'subtotal' => 100.00,
            'tax_amount' => 0,
            'total' => 100.00,
            'created_by' => $this->cashier->id,
        ]);
    }

    /** @test */
    public function test_order_creation_requires_session(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $product = $this->createProduct();

        $response = $this->actingAs($this->cashier)->postJson('/api/v1/pos/orders', [
            'terminal_id' => $terminal->id,
            'session_id' => $session->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
    }

    /** @test */
    public function test_order_creation_rejects_missing_session(): void
    {
        $terminal = $this->createTerminalWithWarehouse();

        $response = $this->actingAs($this->cashier)->postJson('/api/v1/pos/orders', [
            'terminal_id' => $terminal->id,
            'session_id' => 99999,
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_order_creation_rejects_closed_session(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $closedSession = $this->createClosedSession($terminal);

        $response = $this->actingAs($this->cashier)->postJson('/api/v1/pos/orders', [
            'terminal_id' => $terminal->id,
            'session_id' => $closedSession->id,
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Session is closed, not open. Cannot process orders.');
    }

    /** @test */
    public function test_order_creation_requires_terminal(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);

        $differentTerminal = PosTerminal::create([
            'name' => 'POS Terminal 2',
            'terminal_number' => 'TERM-002',
            'warehouse_id' => $terminal->warehouse_id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->cashier)->postJson('/api/v1/pos/orders', [
            'terminal_id' => $differentTerminal->id,
            'session_id' => $session->id,
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_payment_blocked_on_closed_session(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createDraftOrder($terminal, $session);

        $session->update(['status' => 'closed', 'closed_at' => now()]);

        $response = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$order->id}/pay", [
            'method' => 'cash',
            'amount' => 100.00,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Session is closed, not open. Cannot process orders.');
    }
}
