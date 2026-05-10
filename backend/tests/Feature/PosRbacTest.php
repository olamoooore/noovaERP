<?php

namespace Tests\Feature;

use Tests\TenantTestCase;
use App\Models\User;
use App\Models\Product;
use App\Models\Customer;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Spatie\Permission\Models\Role;

class PosRbacTest extends TenantTestCase
{
    protected User $cashier;
    protected User $manager;
    protected User $supervisor;
    protected Product $product;
    protected Customer $customer;
    protected PosTerminal $terminal;
    protected PosSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        Role::create(['name' => 'cashier']);
        Role::create(['name' => 'manager']);
        Role::create(['name' => 'supervisor']);

        // Create users with roles
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('supervisor');

        // Create test data
        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create([
            'price' => 100.00,
            'tax_rate' => 10.00,
            'stock_quantity' => 1000,
        ]);

        $this->terminal = PosTerminal::create([
            'name' => 'RBAC Test Terminal',
            'code' => 'RBAC-TERM-001',
            'is_active' => true,
        ]);

        $this->session = PosSession::create([
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->cashier->id,
            'status' => 'open',
            'opening_balance' => 0,
            'opened_at' => now(),
        ]);
    }

    /**
     * Test RBAC-002: Cashier cannot approve void
     */
    public function test_cashier_cannot_approve_void()
    {
        // Create and pay order
        $order = $this->createAndPayOrder($this->cashier);

        // Cashier requests void (creates approval request)
        $response = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$order->id}/void", [
            'void_reason' => 'Customer request',
        ]);

        // Should return 202 with approval_request
        $response->assertStatus(202);
        $approvalRequestId = $response->json('approval_request.id');

        // Cashier tries to approve their own void
        $approveResponse = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$order->id}/void", [
            'void_reason' => 'Customer request',
            'approval_request_id' => $approvalRequestId,
        ]);

        // Should return 403 (forbidden)
        $approveResponse->assertStatus(403);
    }

    /**
     * Test RBAC-002: Cashier cannot approve refund
     */
    public function test_cashier_cannot_approve_refund()
    {
        // Create and pay order
        $order = $this->createAndPayOrder($this->cashier);

        // Cashier requests refund (creates approval request)
        $response = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$order->id}/refund", [
            'notes' => 'Customer request',
            'refund_method' => 'cash',
        ]);

        // Should return 202 with approval_request
        $response->assertStatus(202);
        $approvalRequestId = $response->json('approval_request.id');

        // Cashier tries to approve their own refund
        $approveResponse = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$order->id}/refund", [
            'notes' => 'Customer request',
            'refund_method' => 'cash',
            'approval_request_id' => $approvalRequestId,
        ]);

        // Should return 403 (forbidden)
        $approveResponse->assertStatus(403);
    }

    /**
     * Test RBAC-002: Manager can approve void
     */
    public function test_manager_can_approve_void()
    {
        // Create and pay order
        $order = $this->createAndPayOrder($this->cashier);

        // Cashier requests void
        $response = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$order->id}/void", [
            'void_reason' => 'Customer request',
        ]);

        $response->assertStatus(202);
        $approvalRequestId = $response->json('approval_request.id');

        // Manager approves void
        $approveResponse = $this->actingAs($this->manager)->postJson("/api/v1/pos/orders/{$order->id}/void", [
            'void_reason' => 'Customer request',
            'approval_request_id' => $approvalRequestId,
        ]);

        // Should return 200 (success)
        $approveResponse->assertStatus(200);
    }

    /**
     * Test RBAC-002: Manager can approve refund
     */
    public function test_manager_can_approve_refund()
    {
        // Create and pay order
        $order = $this->createAndPayOrder($this->cashier);

        // Cashier requests refund
        $response = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$order->id}/refund", [
            'notes' => 'Customer request',
            'refund_method' => 'cash',
        ]);

        $response->assertStatus(202);
        $approvalRequestId = $response->json('approval_request.id');

        // Manager approves refund
        $approveResponse = $this->actingAs($this->manager)->postJson("/api/v1/pos/orders/{$order->id}/refund", [
            'notes' => 'Customer request',
            'refund_method' => 'cash',
            'approval_request_id' => $approvalRequestId,
        ]);

        // Should return 200 (success)
        $approveResponse->assertStatus(200);
    }

    /**
     * Test RBAC-002: Supervisor can approve void
     */
    public function test_supervisor_can_approve_void()
    {
        // Create and pay order
        $order = $this->createAndPayOrder($this->cashier);

        // Cashier requests void
        $response = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$order->id}/void", [
            'void_reason' => 'Customer request',
        ]);

        $response->assertStatus(202);
        $approvalRequestId = $response->json('approval_request.id');

        // Supervisor approves void
        $approveResponse = $this->actingAs($this->supervisor)->postJson("/api/v1/pos/orders/{$order->id}/void", [
            'void_reason' => 'Customer request',
            'approval_request_id' => $approvalRequestId,
        ]);

        // Should return 200 (success)
        $approveResponse->assertStatus(200);
    }

    /**
     * Test RBAC-001: All POS routes require auth
     */
    public function test_all_pos_routes_require_auth()
    {
        // Test unauthenticated access to various POS routes
        $routes = [
            ['GET', '/api/v1/pos/orders'],
            ['POST', '/api/v1/pos/orders'],
            ['GET', '/api/v1/pos/sessions'],
            ['POST', '/api/v1/pos/sessions/open'],
            ['GET', '/api/v1/pos/sync/pending'],
            ['POST', '/api/v1/pos/sync/queue'],
        ];

        foreach ($routes as [$method, $uri]) {
            $response = $this->json($method, $uri);
            
            // Should return 401 (unauthorized)
            $this->assertEquals(401, $response->status(), 
                "Route {$method} {$uri} should require authentication");
        }
    }

    /**
     * Test RBAC-001: All POS routes require entitlement
     */
    public function test_all_pos_routes_require_entitlement()
    {
        // Create user without POS entitlement
        $userWithoutPos = User::factory()->create();

        // Test access to various POS routes
        $routes = [
            ['GET', '/api/v1/pos/orders'],
            ['POST', '/api/v1/pos/orders'],
            ['GET', '/api/v1/pos/sessions'],
        ];

        foreach ($routes as [$method, $uri]) {
            $response = $this->actingAs($userWithoutPos)->json($method, $uri);
            
            // Should return 403 (forbidden) if entitlement middleware is working
            // Note: This assumes entitlement middleware is configured
            $this->assertContains($response->status(), [403, 422], 
                "Route {$method} {$uri} should require POS entitlement");
        }
    }

    /**
     * Test RBAC-001: Verify route middleware configuration
     */
    public function test_pos_routes_have_correct_middleware()
    {
        $routes = \Route::getRoutes();
        
        $posRoutes = collect($routes)->filter(function ($route) {
            return str_contains($route->uri(), 'api/v1/pos/');
        });

        foreach ($posRoutes as $route) {
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

    /**
     * Test RBAC-002: Cashier can create orders
     */
    public function test_cashier_can_create_orders()
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/v1/pos/orders', [
            'session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ]);

        $response->assertStatus(201);
    }

    /**
     * Test RBAC-002: Cashier can pay orders
     */
    public function test_cashier_can_pay_orders()
    {
        $order = $this->createDraftOrder($this->cashier);

        $response = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$order->id}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        $response->assertStatus(200);
    }

    /**
     * Test RBAC-002: Cashier can request void (but not approve)
     */
    public function test_cashier_can_request_void()
    {
        $order = $this->createAndPayOrder($this->cashier);

        $response = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$order->id}/void", [
            'void_reason' => 'Customer request',
        ]);

        // Should return 202 (approval required)
        $response->assertStatus(202);
        $response->assertJsonStructure(['approval_request']);
    }

    /**
     * Test RBAC-002: Cashier can request refund (but not approve)
     */
    public function test_cashier_can_request_refund()
    {
        $order = $this->createAndPayOrder($this->cashier);

        $response = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$order->id}/refund", [
            'notes' => 'Customer request',
            'refund_method' => 'cash',
        ]);

        // Should return 202 (approval required)
        $response->assertStatus(202);
        $response->assertJsonStructure(['approval_request']);
    }

    // Helper methods

    protected function createDraftOrder(User $user): PosOrder
    {
        $response = $this->actingAs($user)->postJson('/api/v1/pos/orders', [
            'session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ]);

        $response->assertStatus(201);
        return PosOrder::find($response->json('id'));
    }

    protected function createAndPayOrder(User $user): PosOrder
    {
        $order = $this->createDraftOrder($user);

        $this->actingAs($user)->postJson("/api/v1/pos/orders/{$order->id}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        return $order->fresh();
    }
}
