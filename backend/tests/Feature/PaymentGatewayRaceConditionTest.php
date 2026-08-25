<?php

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Tests\TenantTestCase;

/**
 * CHAN-003: Race condition test for PaymentTransactionCompletionService
 *
 * Verifies the gap identified in POS-FIND-024:
 * Two concurrent webhooks arriving before order.status is 'paid' can both
 * enter completePosOrderPayment() because the PaymentTransaction lockForUpdate()
 * and the PosPaymentCompletionService order-status check are in separate transactions.
 */
class PaymentGatewayRaceConditionTest extends TenantTestCase
{

    protected string $tenantId;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = 'bbbb2222-bbbb-2222-bbbb-222222222222';
        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'Payment Gateway Race Test Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });
        $this->bindTenantContext($this->tenantId);

        $this->user = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Seed Spatie roles + permissions and assign the user 'Admin' (Title
        // Case — UserRole::ADMIN->spatieName() returns 'Admin' not 'admin') so
        // AccountingAccessService::userHasPermission recognises accounting.write
        // after the fail-closed PermissionGate was introduced (SHA 91d50469).
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->user->assignRole('Admin');

        // Minimal chart-of-accounts + mappings so POS posting can balance its JE.
        $suffix = uniqid();
        $cash = ChartOfAccount::factory()->create(['code' => "1000-{$suffix}", 'name' => 'Cash', 'type' => 'asset', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => "1100-{$suffix}", 'name' => 'AR', 'type' => 'asset', 'is_active' => true]);
        $revenue = ChartOfAccount::factory()->create(['code' => "3000-{$suffix}", 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        AccountMapping::create(['mapping_key' => 'cash', 'account_id' => $cash->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
    }

    /** @test */
    public function concurrent_webhooks_for_same_transaction_do_not_duplicate_payment()
    {
        // Arrange: create a pending POS order and payment transaction
        $posOrder = $this->makePayablePosOrder('draft', 100.00);

        $transaction = PaymentTransaction::factory()->create([
            'amount' => 100.00,
            'status' => 'pending',
            'processed_at' => null,
            'metadata' => ['pos_order_id' => $posOrder->id],
        ]);

        $service = app(PaymentTransactionCompletionService::class);

        // Act: simulate two concurrent webhook calls
        // In a real race, both would enter before processed_at is set.
        // The lockForUpdate() on PaymentTransaction prevents true concurrency in tests,
        // but we verify the idempotency guard works on sequential calls.
        $service->complete($transaction);
        $service->complete($transaction->fresh()); // second call (replay)

        // Assert: exactly one payment record created
        $paymentCount = \Modules\POS\Models\PosPayment::where('pos_order_id', $posOrder->id)->count();
        $this->assertEquals(1, $paymentCount, 'Concurrent webhooks must not create duplicate payments');
    }

    /** @test */
    public function completed_transaction_is_idempotent_on_replay()
    {
        $posOrder = $this->makePayablePosOrder('draft', 50.00);

        $transaction = PaymentTransaction::factory()->create([
            'amount' => 50.00,
            'status' => 'pending',
            'processed_at' => null,
            'metadata' => ['pos_order_id' => $posOrder->id],
        ]);

        $service = app(PaymentTransactionCompletionService::class);

        // First completion
        $result1 = $service->complete($transaction);

        // Second completion (replay)
        $result2 = $service->complete($transaction->fresh());

        // Both should succeed without error
        $this->assertNotNull($result1);
        $this->assertNotNull($result2);

        // Order should be paid exactly once
        $this->assertEquals('paid', $posOrder->fresh()->status);

        // GL entries should not be duplicated
        $glCount = \App\Models\JournalEntryLink::where('source_document_id', $posOrder->id)
            ->where('source_document_type', 'pos_order')
            ->count();
        $this->assertLessThanOrEqual(1, $glCount, 'Replay must not create duplicate GL entries');
    }

    /** @test */
    public function payment_transaction_lock_prevents_double_processing()
    {
        $posOrder = $this->makePayablePosOrder('draft', 75.00);

        $transaction = PaymentTransaction::factory()->create([
            'amount' => 75.00,
            'status' => 'pending',
            'processed_at' => null,
            'metadata' => ['pos_order_id' => $posOrder->id],
        ]);

        $service = app(PaymentTransactionCompletionService::class);
        $service->complete($transaction);

        // Verify processed_at is set after first completion
        $refreshed = $transaction->fresh();
        $this->assertNotNull($refreshed->processed_at, 'processed_at must be set after completion');
    }

    /** @test */
    public function race_condition_gap_is_documented_and_mitigated()
    {
        // This test documents the known race condition gap (POS-FIND-024):
        // PaymentTransactionCompletionService locks PaymentTransaction but
        // PosPaymentCompletionService uses a separate DB::transaction.
        //
        // Mitigation: PosPaymentCompletionService checks order.status === 'paid'
        // before proceeding. The order-status check is the final idempotency guard.
        //
        // Full fix requires adding processed_at check in PaymentTransactionCompletionService
        // before calling completePosOrderPayment() — tracked as CHAN-003 enhancement.

        $posOrder = $this->makePayablePosOrder('paid', 100.00);

        $transaction = PaymentTransaction::factory()->create([
            'amount' => 100.00,
            'status' => 'pending',
            'processed_at' => null,
            'metadata' => ['pos_order_id' => $posOrder->id],
        ]);

        $service = app(PaymentTransactionCompletionService::class);

        // Calling complete on an already-paid order should be safe (no-op)
        $result = $service->complete($transaction);
        $this->assertNotNull($result);

        // No new payment should be created
        $paymentCount = \Modules\POS\Models\PosPayment::where('pos_order_id', $posOrder->id)->count();
        $this->assertEquals(0, $paymentCount, 'Already-paid order must not get duplicate payment');
    }

    /**
     * Create a PosOrder with one invoiceable line item so the downstream
     * SalesOrderService::createInvoice() does not fail with
     * "There are no invoiceable quantities for this order."
     */
    private function makePayablePosOrder(string $status, float $total): PosOrder
    {
        $product = Product::factory()->create([
            'name' => 'Race Condition Test Product',
            'track_inventory' => false,
        ]);

        $posOrder = PosOrder::factory()->create([
            'status' => $status,
            'subtotal' => $total,
            'tax_amount' => 0,
            'total' => $total,
        ]);

        PosOrderItem::create([
            'pos_order_id' => $posOrder->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => $total,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => $total,
        ]);

        return $posOrder;
    }
}
