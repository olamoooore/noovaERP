<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Tenant;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Modules\POS\Models\PosOfflineQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class PosTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected User $userA;
    protected User $userB;
    protected Product $productA;
    protected Product $productB;
    protected PosTerminal $terminalA;
    protected PosTerminal $terminalB;
    protected PosSession $sessionA;
    protected PosSession $sessionB;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two tenants
        $this->tenantA = Tenant::factory()->create(['id' => 'tenant-a']);
        $this->tenantB = Tenant::factory()->create(['id' => 'tenant-b']);

        // Create users for each tenant
        $this->tenantA->run(function () {
            $this->userA = User::factory()->create();
            $this->productA = Product::factory()->create(['price' => 100.00]);
            $this->terminalA = PosTerminal::factory()->create();
            $this->sessionA = PosSession::factory()->create([
                'terminal_id' => $this->terminalA->id,
                'user_id' => $this->userA->id,
                'status' => 'open',
            ]);
        });

        $this->tenantB->run(function () {
            $this->userB = User::factory()->create();
            $this->productB = Product::factory()->create(['price' => 200.00]);
            $this->terminalB = PosTerminal::factory()->create();
            $this->sessionB = PosSession::factory()->create([
                'terminal_id' => $this->terminalB->id,
                'user_id' => $this->userB->id,
                'status' => 'open',
            ]);
        });
    }

    /**
     * Test TENANT-001: Tenant A cannot read Tenant B's offline queue
     */
    public function test_tenant_a_cannot_read_tenant_b_offline_queue()
    {
        // Create offline queue item for Tenant B
        $this->tenantB->run(function () {
            PosOfflineQueue::create([
                'operation' => 'create_order',
                'payload' => ['test' => 'data'],
                'status' => 'pending',
            ]);
        });

        // Tenant A tries to read Tenant B's queue
        $this->tenantA->run(function () {
            $response = $this->actingAs($this->userA)->getJson('/api/pos/sync/pending');
            
            // Should return empty or only Tenant A's items
            $items = $response->json();
            $this->assertIsArray($items);
            
            // Verify no Tenant B data is returned
            foreach ($items as $item) {
                $this->assertNotEquals('tenant-b', $item['tenant_id'] ?? null);
            }
        });
    }

    /**
     * Test TENANT-001: Tenant A cannot sync Tenant B's order
     */
    public function test_tenant_a_cannot_sync_tenant_b_order()
    {
        // Create order for Tenant B
        $orderB = null;
        $this->tenantB->run(function () use (&$orderB) {
            $response = $this->actingAs($this->userB)->postJson('/api/pos/orders', [
                'session_id' => $this->sessionB->id,
                'items' => [
                    [
                        'product_id' => $this->productB->id,
                        'quantity' => 1,
                        'price' => 200.00,
                    ],
                ],
            ]);
            $orderB = $response->json('id');
        });

        // Tenant A tries to sync Tenant B's order
        $this->tenantA->run(function () use ($orderB) {
            $response = $this->actingAs($this->userA)->postJson('/api/pos/sync/queue', [
                'operation' => 'pay_order',
                'payload' => [
                    'order_id' => $orderB,
                    'method' => 'cash',
                    'amount' => 200.00,
                ],
            ]);

            // Should fail with 403 or 404
            $this->assertContains($response->status(), [403, 404]);
        });
    }

    /**
     * Test TENANT-002: Tenant A cannot access Tenant B's POS orders
     */
    public function test_tenant_a_cannot_access_tenant_b_orders()
    {
        // Create order for Tenant B
        $orderB = null;
        $this->tenantB->run(function () use (&$orderB) {
            $response = $this->actingAs($this->userB)->postJson('/api/pos/orders', [
                'session_id' => $this->sessionB->id,
                'items' => [
                    [
                        'product_id' => $this->productB->id,
                        'quantity' => 1,
                        'price' => 200.00,
                    ],
                ],
            ]);
            $orderB = $response->json('id');
        });

        // Tenant A tries to access Tenant B's order
        $this->tenantA->run(function () use ($orderB) {
            $response = $this->actingAs($this->userA)->getJson("/api/pos/orders/{$orderB}");
            
            // Should return 403 or 404
            $this->assertContains($response->status(), [403, 404]);
        });
    }

    /**
     * Test TENANT-002: Tenant A cannot access Tenant B's payments
     */
    public function test_tenant_a_cannot_access_tenant_b_payments()
    {
        // Create and pay order for Tenant B
        $orderB = null;
        $this->tenantB->run(function () use (&$orderB) {
            $response = $this->actingAs($this->userB)->postJson('/api/pos/orders', [
                'session_id' => $this->sessionB->id,
                'items' => [
                    [
                        'product_id' => $this->productB->id,
                        'quantity' => 1,
                        'price' => 200.00,
                    ],
                ],
            ]);
            $orderB = $response->json('id');

            $this->actingAs($this->userB)->postJson("/api/pos/orders/{$orderB}/pay", [
                'method' => 'cash',
                'amount' => 200.00,
            ]);
        });

        // Tenant A tries to access Tenant B's order (which has payments)
        $this->tenantA->run(function () use ($orderB) {
            $response = $this->actingAs($this->userA)->getJson("/api/pos/orders/{$orderB}");
            
            // Should return 403 or 404
            $this->assertContains($response->status(), [403, 404]);
        });
    }

    /**
     * Test TENANT-002: Tenant A cannot access Tenant B's sessions
     */
    public function test_tenant_a_cannot_access_tenant_b_sessions()
    {
        // Tenant A tries to access Tenant B's session
        $this->tenantA->run(function () {
            $response = $this->actingAs($this->userA)->getJson("/api/pos/sessions/{$this->sessionB->id}");
            
            // Should return 403 or 404
            $this->assertContains($response->status(), [403, 404]);
        });
    }

    /**
     * Test TENANT-002: Tenant A cannot access Tenant B's display projection
     */
    public function test_tenant_a_cannot_access_tenant_b_display()
    {
        // Tenant A tries to access Tenant B's display
        $this->tenantA->run(function () {
            $response = $this->actingAs($this->userA)->getJson("/api/pos/display/{$this->terminalB->id}");
            
            // Should return 403 or 404
            $this->assertContains($response->status(), [403, 404]);
        });
    }

    /**
     * Test TENANT-002: Tenant A's order list does not include Tenant B's orders
     */
    public function test_tenant_a_order_list_excludes_tenant_b_orders()
    {
        // Create orders for both tenants
        $orderA = null;
        $orderB = null;

        $this->tenantA->run(function () use (&$orderA) {
            $response = $this->actingAs($this->userA)->postJson('/api/pos/orders', [
                'session_id' => $this->sessionA->id,
                'items' => [
                    [
                        'product_id' => $this->productA->id,
                        'quantity' => 1,
                        'price' => 100.00,
                    ],
                ],
            ]);
            $orderA = $response->json('id');
        });

        $this->tenantB->run(function () use (&$orderB) {
            $response = $this->actingAs($this->userB)->postJson('/api/pos/orders', [
                'session_id' => $this->sessionB->id,
                'items' => [
                    [
                        'product_id' => $this->productB->id,
                        'quantity' => 1,
                        'price' => 200.00,
                    ],
                ],
            ]);
            $orderB = $response->json('id');
        });

        // Tenant A fetches order list
        $this->tenantA->run(function () use ($orderA, $orderB) {
            $response = $this->actingAs($this->userA)->getJson('/api/pos/orders');
            
            $response->assertStatus(200);
            $orders = $response->json('data') ?? $response->json();
            
            // Verify Tenant A's order is in the list
            $orderIds = collect($orders)->pluck('id')->toArray();
            $this->assertContains($orderA, $orderIds);
            
            // Verify Tenant B's order is NOT in the list
            $this->assertNotContains($orderB, $orderIds);
        });
    }

    /**
     * Test TENANT-002: Tenant A cannot void Tenant B's order
     */
    public function test_tenant_a_cannot_void_tenant_b_order()
    {
        // Create and pay order for Tenant B
        $orderB = null;
        $this->tenantB->run(function () use (&$orderB) {
            $response = $this->actingAs($this->userB)->postJson('/api/pos/orders', [
                'session_id' => $this->sessionB->id,
                'items' => [
                    [
                        'product_id' => $this->productB->id,
                        'quantity' => 1,
                        'price' => 200.00,
                    ],
                ],
            ]);
            $orderB = $response->json('id');

            $this->actingAs($this->userB)->postJson("/api/pos/orders/{$orderB}/pay", [
                'method' => 'cash',
                'amount' => 200.00,
            ]);
        });

        // Tenant A tries to void Tenant B's order
        $this->tenantA->run(function () use ($orderB) {
            $response = $this->actingAs($this->userA)->postJson("/api/pos/orders/{$orderB}/void", [
                'void_reason' => 'Test void',
            ]);
            
            // Should return 403 or 404
            $this->assertContains($response->status(), [403, 404]);
        });
    }

    /**
     * Test TENANT-002: Tenant A cannot refund Tenant B's order
     */
    public function test_tenant_a_cannot_refund_tenant_b_order()
    {
        // Create and pay order for Tenant B
        $orderB = null;
        $this->tenantB->run(function () use (&$orderB) {
            $response = $this->actingAs($this->userB)->postJson('/api/pos/orders', [
                'session_id' => $this->sessionB->id,
                'items' => [
                    [
                        'product_id' => $this->productB->id,
                        'quantity' => 1,
                        'price' => 200.00,
                    ],
                ],
            ]);
            $orderB = $response->json('id');

            $this->actingAs($this->userB)->postJson("/api/pos/orders/{$orderB}/pay", [
                'method' => 'cash',
                'amount' => 200.00,
            ]);
        });

        // Tenant A tries to refund Tenant B's order
        $this->tenantA->run(function () use ($orderB) {
            $response = $this->actingAs($this->userA)->postJson("/api/pos/orders/{$orderB}/refund", [
                'notes' => 'Test refund',
                'refund_method' => 'cash',
            ]);
            
            // Should return 403 or 404
            $this->assertContains($response->status(), [403, 404]);
        });
    }

    /**
     * Test TENANT-001: Verify sync routes have tenant middleware
     */
    public function test_sync_routes_have_tenant_middleware()
    {
        // This test verifies route configuration
        $routes = \Route::getRoutes();
        
        $syncRoutes = collect($routes)->filter(function ($route) {
            return str_contains($route->uri(), 'pos/sync');
        });

        foreach ($syncRoutes as $route) {
            $middleware = $route->middleware();
            
            // Verify auth middleware
            $this->assertContains('auth:sanctum', $middleware, 
                "Route {$route->uri()} must have auth:sanctum middleware");
            
            // Verify tenant middleware
            $this->assertTrue(
                in_array('ensure.tenant.search_path', $middleware) || 
                in_array('tenant', $middleware),
                "Route {$route->uri()} must have tenant middleware"
            );
            
            // Verify entitlement middleware
            $hasEntitlement = collect($middleware)->contains(function ($m) {
                return str_contains($m, 'entitlement');
            });
            $this->assertTrue($hasEntitlement, 
                "Route {$route->uri()} must have entitlement middleware");
        }
    }
}
