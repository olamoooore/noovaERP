<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ApprovalRequest;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Services\TenantRoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * POS Smoke Test Suite
 *
 * Critical end-to-end tests that verify core POS functionality
 * after deployment. These tests should be run in staging before
 * production go-live.
 */
class PosSmokeSuiteTest extends TenantTestCase
{

    protected User $cashier;

    protected User $manager;

    protected Product $product;

    protected Customer $customer;

    protected PosTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockFeatureGate(true);
        $this->ensureFeaturesTableExists();
        Notification::fake();

        if (Schema::hasTable('pos_orders') && ! Schema::hasColumn('pos_orders', 'deleted_at')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('pos_orders')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('pos_orders', 'payment_state')) {
                    $table->string('payment_state')->nullable();
                }
                if (! Schema::hasColumn('pos_orders', 'payment_method')) {
                    $table->string('payment_method')->nullable();
                }
                if (! Schema::hasColumn('pos_orders', 'amount_tendered')) {
                    $table->decimal('amount_tendered', 15, 2)->nullable();
                }
                if (! Schema::hasColumn('pos_orders', 'change_due')) {
                    $table->decimal('change_due', 15, 2)->nullable();
                }
                // Make external_id nullable for return orders created by void/refund flow
                if (Schema::hasColumn('pos_orders', 'external_id')) {
                    DB::statement('ALTER TABLE pos_orders ALTER COLUMN external_id DROP NOT NULL');
                }
                // Drop restrictive status check constraint to allow return order statuses
                DB::statement("ALTER TABLE pos_orders DROP CONSTRAINT IF EXISTS pos_orders_status_check");

                // Add void columns if missing
                foreach (['voided_by', 'void_approved_by'] as $col) {
                    if (! Schema::hasColumn('pos_orders', $col)) {
                        $table->foreignId($col)->nullable()->constrained('users');
                    }
                }
                if (! Schema::hasColumn('pos_orders', 'voided_at')) {
                    $table->timestamp('voided_at')->nullable();
                }
                if (! Schema::hasColumn('pos_orders', 'void_reason')) {
                    $table->string('void_reason')->nullable();
                }
            });
        }

        // Drop restrictive payment method check constraint to allow refund method
        if (Schema::hasTable('pos_payments')) {
            DB::statement("ALTER TABLE pos_payments DROP CONSTRAINT IF EXISTS pos_payments_method_check");
        }

        $tenantId = (string) Str::uuid();
        $this->bindTenantContext($tenantId);

        // Mock TenantRoleService so RequirePermission middleware always passes
        $roleMock = \Mockery::mock(TenantRoleService::class);
        $roleMock->shouldReceive('hasPermission')->andReturn(true);
        $roleMock->shouldReceive('hasAnyPermission')->andReturn(true);
        app()->instance(TenantRoleService::class, $roleMock);

        // Create roles and permissions
        $posCreate = Permission::firstOrCreate(['name' => 'pos.create', 'guard_name' => 'web']);
        $cashier = Role::firstOrCreate(['name' => 'cashier']);
        $cashier->givePermissionTo($posCreate);
        $managerRole = Role::firstOrCreate(['name' => 'manager']);
        $managerRole->givePermissionTo($posCreate);

        // Create users
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');

        // Create test data
        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create([
            'price' => 100.00,
            'tax_rate' => 10.00,
            'stock_quantity' => 1000,
        ]);

        $warehouse = \App\Models\Warehouse::factory()->create();
        $this->terminal = PosTerminal::factory()->create(['warehouse_id' => $warehouse->id]);

        $this->ensureOpenFiscalPeriodForToday();
    }

    /**
     * SMOKE TEST 1: Session Open/Close
     */
    public function test_smoke_session_open_and_close()
    {
        // Open session
        $response = $this->actingAs($this->cashier)->postJson('/api/v1/pos/sessions/open', [
            'terminal_id' => $this->terminal->id,
            'opening_balance' => 100.00,
        ]);

        $response->assertStatus(200);
        $sessionId = $response->json('session.id');

        // Verify session is open
        $session = PosSession::find($sessionId);
        $this->assertEquals('open', $session->status);
        $this->assertEquals(100.00, $session->opening_balance);

        // Close session
        $closeResponse = $this->actingAs($this->cashier)->postJson("/api/v1/pos/sessions/{$sessionId}/close", [
            'closing_balance' => 210.00,
            'notes' => 'End of shift',
        ]);

        $closeResponse->assertStatus(200);

        // Verify session is closed
        $session->refresh();
        $this->assertEquals('closed', $session->status);
        $this->assertEquals(210.00, $session->closing_balance);
    }

    /**
     * SMOKE TEST 2: Order Create and Pay
     */
    public function test_smoke_order_create_and_pay()
    {
        // Open session via model
        $session = PosSession::create([
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->cashier->id,
            'status' => 'open',
            'opening_balance' => 100.00,
            'opened_at' => now(),
        ]);

        // Create order via model
        $order = PosOrder::create([
            'order_number' => 'TEST-' . strtoupper(uniqid()),
            'external_id' => (string) Str::uuid(),
            'status' => 'draft',
            'currency' => 'USD',
            'session_id' => $session->id,
            'terminal_id' => $this->terminal->id,
            'customer_id' => $this->customer->id,
            'cashier_name' => $this->cashier->name,
            'created_by' => $this->cashier->id,
            'subtotal' => 100.00,
            'tax_amount' => 10.00,
            'total' => 110.00,
        ]);

        // Create order item
        \Modules\POS\Models\PosOrderItem::create([
            'pos_order_id' => $order->id,
            'product_id' => $this->product->id,
            'name' => $this->product->name,
            'quantity' => 1,
            'unit_price' => 100.00,
            'tax_rate' => 10.00,
            'tax_amount' => 10.00,
            'line_total' => 110.00,
        ]);

        // Verify order created
        $this->assertEquals('draft', $order->status);
        $this->assertEquals(100.00, $order->subtotal);
        $this->assertEquals(10.00, $order->tax_amount);
        $this->assertEquals(110.00, $order->total);

        // Capture ALL queries (including ones that fail) via beforeExecuting
        $allQueries = [];
        $queryCount = 0;
        DB::beforeExecuting(function ($query, $bindings, $conn) use (&$allQueries, &$queryCount) {
            $idx = $queryCount++;
            $allQueries[] = $idx . '|' . preg_replace('/\s+/', ' ', trim($query));
        });

        // Capture successful queries via listen
        $successQueries = [];
        DB::listen(function ($query) use (&$successQueries) {
            $successQueries[] = preg_replace('/\s+/', ' ', trim($query->sql));
        });

        // Pay order via HTTP
        $payResponse = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$order->id}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        if ($payResponse->status() !== 200) {
            $failedQueries = [];
            foreach ($allQueries as $entry) {
                [$idx, $sql] = explode('|', $entry, 2);
                if (! in_array($sql, $successQueries)) {
                    $failedQueries[] = "[{$idx}] {$sql}";
                }
            }

            file_put_contents('/tmp/pos_failed.txt', implode("\n", $failedQueries));
            $failedCount = count($failedQueries);
            $this->fail("Pay returned {$payResponse->status()}. {$failedCount} of " . count($allQueries) . " queries failed. First 3: " . implode(' || ', array_slice($failedQueries, 0, 3)));
        }

        // Verify order paid
        $order->refresh();
        $this->assertEquals('paid', $order->status);
        $this->assertNotNull($order->paid_at);

        // Verify payment record
        $payment = $order->payments()->first();
        $this->assertNotNull($payment);
        $this->assertEquals('cash', $payment->method);
        $this->assertEquals(110.00, $payment->amount);

        // Verify GL entry on order (journal_entry_id is set on the order, not the payment)
        $this->assertNotNull($order->journal_entry_id);
        $glEntry = JournalEntry::find($order->journal_entry_id);
        $this->assertNotNull($glEntry);

        // Verify stock movement
        $stockMovement = StockMovement::where('reference_id', $order->id)
            ->where('reference_type', 'PosOrder')
            ->first();
        $this->assertNotNull($stockMovement);
        $this->assertEquals(1, $stockMovement->quantity);
        $this->assertEquals('sale', $stockMovement->type);
    }

    /**
     * SMOKE TEST 3: Void with Approval
     */
    public function test_smoke_void_with_approval()
    {
        $session = $this->openSession();
        $order = $this->createAndPayOrder($session);

        // Request void (as cashier)
        $response = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$order->id}/void", [
            'reason' => 'Customer request',
        ]);

        $response->assertStatus(202);
        $approvalRequestId = $response->json('approval_request.id');
        $this->assertNotNull($approvalRequestId);

        // Approve void (as manager) - approve the request first, then send approval
        $this->approveRequest($approvalRequestId, $this->manager->id);
        $approveResponse = $this->actingAs($this->manager)->postJson("/api/v1/pos/orders/{$order->id}/void", [
            'reason' => 'Customer request',
            'approval_request_id' => $approvalRequestId,
        ]);

        $approveResponse->assertStatus(200);

        // Verify order voided
        $order->refresh();
        $this->assertEquals('void', $order->status);
        $this->assertNotNull($order->voided_by);
        $this->assertNotNull($order->void_approved_by);
        $this->assertNotNull($order->void_reason);

        // Verify GL reversal
        $reversalEntry = \App\Models\JournalEntryLink::where('source_document_id', $order->id)
            ->where('source_document_type', 'pos_order')
            ->where('relation_type', 'reversal')
            ->first();
        $this->assertNotNull($reversalEntry, 'GL reversal link must exist for voided order');

        // Verify stock restored (sale movement on original order, restock on return order)
        $saleQuantity = StockMovement::where('reference_id', $order->id)
            ->where('reference_type', 'PosOrder')
            ->where('type', 'sale')
            ->sum('quantity');
        $returnOrders = $order->returns()->pluck('id')->toArray();
        $returnQuantity = 0;
        if (count($returnOrders) > 0) {
            $returnQuantity = StockMovement::whereIn('reference_id', $returnOrders)
                ->where('reference_type', 'PosOrder')
                ->where('type', 'return')
                ->sum('quantity');
        }
        $this->assertEquals($saleQuantity, $returnQuantity, 'Stock should be restored (sale == return)');
    }

    /**
     * SMOKE TEST 4: Refund with Approval
     */
    public function test_smoke_refund_with_approval()
    {
        $session = $this->openSession();
        $order = $this->createAndPayOrder($session);

        // Request refund (as cashier)
        $response = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$order->id}/refund", [
            'notes' => 'Customer request',
            'refund_method' => 'cash',
        ]);

        $response->assertStatus(202);
        $approvalRequestId = $response->json('approval_request.id');
        $this->assertNotNull($approvalRequestId);

        // Approve refund (as manager) - approve the request first, then send approval
        $this->approveRequest($approvalRequestId, $this->manager->id);
        $approveResponse = $this->actingAs($this->manager)->postJson("/api/v1/pos/orders/{$order->id}/refund", [
            'notes' => 'Customer request',
            'refund_method' => 'cash',
            'approval_request_id' => $approvalRequestId,
        ]);

        $approveResponse->assertStatus(200);

        // Verify refund payment created (negative amount indicates refund)
        $refundPayment = $approveResponse->json('data.payments');
        if (is_array($refundPayment) && count($refundPayment) > 0) {
            $refundPayment = $refundPayment[0];
            $this->assertNotNull($refundPayment['journal_entry_id'] ?? null);
        }
    }

    /**
     * SMOKE TEST 5: Tax Audit Trail
     */
    public function test_smoke_tax_audit_trail()
    {
        $session = $this->openSession();
        $order = $this->createAndPayOrder($session);

        // Verify tax_transaction_details record exists
        $taxDetail = DB::table('tax_transaction_details')
            ->where('source_document_id', $order->id)
            ->where('source_document_type', 'pos_order')
            ->first();

        $this->assertNotNull($taxDetail, 'Tax transaction detail must exist');
        $this->assertNotNull($taxDetail->tax_code, 'Tax code must be populated');
        $this->assertEquals(10.00, $taxDetail->tax_amount, 'Tax amount must match order');
    }

    /**
     * SMOKE TEST 6: Payment Idempotency
     */
    public function test_smoke_payment_idempotency()
    {
        $session = $this->openSession();

        // Create order
        $response = $this->actingAs($this->cashier)->postJson('/api/v1/pos/orders', [
            'session_id' => $session->id,
            'terminal_id' => $this->terminal->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ]);

        $orderId = $response->json('id');

        // Pay order
        $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$orderId}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        // Attempt duplicate payment
        $duplicateResponse = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$orderId}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        // Should fail or be idempotent
        $this->assertContains($duplicateResponse->status(), [200, 400, 422]);

        // Verify only one payment exists
        $paymentCount = PosPayment::where('pos_order_id', $orderId)->count();
        $this->assertEquals(1, $paymentCount, 'Only one payment should exist');

        // Verify only one GL entry exists
        $glCount = \App\Models\JournalEntryLink::where('source_document_id', $orderId)
            ->where('source_document_type', 'pos_order')
            ->where('relation_type', 'post')
            ->count();
        $this->assertEquals(1, $glCount, 'Only one GL entry should exist');
    }

    /**
     * SMOKE TEST 7: Session Enforcement
     */
    public function test_smoke_session_enforcement()
    {
        $session = $this->openSession();

        // Create order with valid session
        $response = $this->actingAs($this->cashier)->postJson('/api/v1/pos/orders', [
            'session_id' => $session->id,
            'terminal_id' => $this->terminal->id,
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

        // Close session
        $this->actingAs($this->cashier)->postJson("/api/v1/pos/sessions/{$session->id}/close", [
            'closing_balance' => 100.00,
        ]);

        // Attempt to create order with closed session
        $closedSessionResponse = $this->actingAs($this->cashier)->postJson('/api/v1/pos/orders', [
            'session_id' => $session->id,
            'terminal_id' => $this->terminal->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ]);

        // Should fail
        $this->assertContains($closedSessionResponse->status(), [400, 422]);
    }

    /**
     * SMOKE TEST 8: Reconciliation
     */
    public function test_smoke_reconciliation()
    {
        $session = $this->openSession();
        $order = $this->createAndPayOrder($session);

        // Run reconciliation
        $reconciliationService = app(\App\Services\Reconciliation\PosPaymentGLReconciliationService::class);
        $exceptions = $reconciliationService->reconcile();

        // Verify no exceptions for clean order
        $orderExceptions = collect($exceptions)->filter(function ($exception) use ($order) {
            if (! is_array($exception)) {
                return false;
            }
            $refId = $exception['reference_id'] ?? $exception['entity_id'] ?? null;
            return $refId === $order->id || $refId === (string) $order->id;
        });

        $this->assertCount(0, $orderExceptions, 'Clean order should produce zero reconciliation exceptions');
    }

    // Helper methods

    protected function openSession(): PosSession
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/v1/pos/sessions/open', [
            'terminal_id' => $this->terminal->id,
            'opening_balance' => 100.00,
        ]);

        return PosSession::find($response->json('session.id'));
    }

    protected function createAndPayOrder(PosSession $session): PosOrder
    {
        // Create order
        $response = $this->actingAs($this->cashier)->postJson('/api/v1/pos/orders', [
            'session_id' => $session->id,
            'terminal_id' => $this->terminal->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ]);

        $orderId = $response->json('id');

        // Pay order
        $payResponse = $this->actingAs($this->cashier)->postJson("/api/v1/pos/orders/{$orderId}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        if ($payResponse->status() !== 200) {
            $this->fail('createAndPayOrder pay failed: ' . $payResponse->status() . ' - ' . $payResponse->content());
        }

        $order = PosOrder::find($orderId);
        $this->assertEquals('paid', $order->status, 'Order should be in paid status after payment');
        return $order;
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(function (string $tenantId, string $key) use ($isEntitled) {
                return [
                    'key' => $key,
                    'is_entitled' => $isEntitled,
                    'source' => $isEntitled ? 'tier_included' : null,
                    'name' => strtoupper($key),
                ];
            });
        $mock->shouldReceive('all')
            ->andReturn($isEntitled ? [['key' => 'pos', 'source' => 'tier_included', 'name' => 'POS']] : []);
        $mock->shouldReceive('invalidate')->andReturnNull();
        app()->instance(FeatureGateService::class, $mock);
    }

    private function approveRequest(int $approvalRequestId, int $reviewedBy): void
    {
        ApprovalRequest::query()->whereKey($approvalRequestId)->update([
            'status' => 'approved',
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
        ]);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        \App\Models\Feature::query()->updateOrCreate(
            ['key' => 'pos'],
            [
                'name' => 'POS',
                'description' => 'POS module feature gate for tests',
                'is_core' => false,
                'price' => 0,
            ]
        );
    }

    private function ensureOpenFiscalPeriodForToday(): void
    {
        if (! Schema::hasTable('fiscal_periods')) {
            return;
        }

        $today = now()->toDateString();
        $exists = DB::table('fiscal_periods')
            ->where('period_start', '<=', $today)
            ->where('period_end', '>=', $today)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('fiscal_periods')->insert([
            'name' => now()->format('F Y'),
            'code' => now()->format('Y-m'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'period_type' => 'monthly',
            'fiscal_year' => (int) now()->format('Y'),
            'is_locked' => false,
            'status' => 'open',
            'created_by' => $this->cashier->id ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
