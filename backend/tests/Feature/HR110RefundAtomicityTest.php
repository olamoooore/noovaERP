<?php

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingResult;
use App\Accounting\PostingEngine;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentRefundService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Tests\TenantTestCase;

/**
 * Issue #110 (MEDIUM) — gateway refund and GL reversal were not atomic: the
 * gateway call happens outside any DB transaction, so a failure after gateway
 * success left the customer refunded with no GL trace and no retry path.
 *
 * The refund flow now writes a durable intent BEFORE the gateway call, records
 * the gateway outcome, and exposes a retry command for the ledger side that
 * never calls the gateway again.
 */
class HR110RefundAtomicityTest extends TenantTestCase
{
    private string $tenantId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = 'bbbb2222-bbbb-2222-bbbb-222222222222';
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'HR110 Refund Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

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

        $suffix = uniqid();
        $cash = ChartOfAccount::factory()->create(['code' => "1000-{$suffix}", 'type' => 'asset', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => "1100-{$suffix}", 'type' => 'asset', 'is_active' => true]);
        $revenue = ChartOfAccount::factory()->create(['code' => "3000-{$suffix}", 'type' => 'revenue', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'cash', 'account_id' => $cash->id]);
        AccountMapping::create(['mapping_key' => 'revenue', 'account_id' => $revenue->id]);
    }

    private function makeTransaction(): PaymentTransaction
    {
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'total' => 1000,
            'balance' => 0,
            'paid_amount' => 1000,
            'status' => 'paid',
        ]);

        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => 1000,
        ]);

        $gateway = PaymentGateway::factory()->create(['gateway_name' => 'hitpay']);

        return PaymentTransaction::factory()->create([
            'payment_gateway_id' => $gateway->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => 1000,
            'status' => 'completed',
            'transaction_id' => 'TXN-'.uniqid(),
            'refunded_amount' => 0,
        ]);
    }

    private function failLedgerPosting(): void
    {
        $mock = Mockery::mock(PostingEngineInterface::class);
        $mock->shouldReceive('reverse')->andReturn(PostingResult::failure('simulated GL outage'));
        $mock->shouldReceive('post')->andReturn(PostingResult::failure('simulated GL outage'));
        $this->app->instance(PostingEngineInterface::class, $mock);
    }

    private function restoreRealLedgerPosting(): void
    {
        $this->app->instance(PostingEngineInterface::class, $this->app->make(PostingEngine::class));
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_write_ahead_record_is_created_before_the_gateway_call(): void
    {
        $transaction = $this->makeTransaction();
        $service = app(PaymentRefundService::class);

        $refund = $service->beginGatewayRefund($transaction, 400, 'Customer request', $this->user->id);

        $this->assertSame('pending', $refund->status);
        $this->assertSame('gateway_pending', $refund->metadata['stage']);
        $this->assertSame(400.0, (float) $refund->metadata['target_refunded_amount']);
        $this->assertDatabaseHas('refund_transactions', ['id' => $refund->id, 'status' => 'pending']);
    }

    public function test_gateway_success_then_ledger_failure_is_durably_recorded_and_retryable(): void
    {
        $transaction = $this->makeTransaction();

        $refund = app(PaymentRefundService::class)->beginGatewayRefund($transaction, 500, 'Customer request', $this->user->id);
        app(PaymentRefundService::class)->markGatewayRefunded($refund, ['success' => true, 'refund_id' => 'GW-REF-1']);

        // Ledger posting fails (simulated outage). Bind the mock BEFORE
        // resolving the service so it receives the failing engine.
        $this->failLedgerPosting();
        $failingService = app(PaymentRefundService::class);

        try {
            $failingService->completeGatewayRefundLedger($refund, $this->user->id);
            $this->fail('Ledger failure must propagate');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('simulated GL outage', $e->getMessage());
        }

        $refund->refresh();
        $this->assertSame('gateway_refunded_gl_pending', $refund->metadata['stage'], 'Durable marker must survive the failure');
        $this->assertSame('GW-REF-1', $refund->metadata['gateway_refund_id']);
        $this->assertSame(0.0, (float) $transaction->fresh()->refunded_amount, 'Ledger was not updated');

        // Retry with the real posting engine: GL now posts, no gateway re-call.
        $this->restoreRealLedgerPosting();
        $result = app(PaymentRefundService::class)->retryPendingRefundLedger();

        $this->assertContains($refund->id, $result['repaired']);
        $this->assertSame([], $result['failed']);

        $refund->refresh();
        $this->assertSame('completed', $refund->status);
        $this->assertSame('completed', $refund->metadata['stage']);
        $this->assertSame(500.0, (float) $transaction->fresh()->refunded_amount);
    }

    public function test_retry_is_idempotent_and_does_not_double_post(): void
    {
        $transaction = $this->makeTransaction();
        $service = app(PaymentRefundService::class);

        $refund = $service->beginGatewayRefund($transaction, 300, 'Customer request', $this->user->id);
        $service->markGatewayRefunded($refund, ['success' => true, 'refund_id' => 'GW-REF-2']);

        // First repair posts the ledger.
        $service->completeGatewayRefundLedger($refund, $this->user->id);
        $this->assertSame(300.0, (float) $transaction->fresh()->refunded_amount);

        // Force the record back to the pending stage to simulate a crash after
        // the ledger committed but before the status update persisted.
        $refund->update(['status' => 'pending', 'metadata' => array_merge($refund->metadata ?? [], ['stage' => 'gateway_refunded_gl_pending'])]);

        $result = $service->retryPendingRefundLedger();

        $this->assertContains($refund->id, $result['repaired']);
        $this->assertSame(300.0, (float) $transaction->fresh()->refunded_amount, 'Retry must not double-refund');
        $this->assertTrue((bool) ($refund->fresh()->metadata['repaired_without_posting'] ?? false));
    }

    public function test_retry_command_reports_clean_state(): void
    {
        $this->restoreRealLedgerPosting();

        Artisan::call('payments:retry-pending-refund-gl');

        $this->assertStringContainsString('No gateway-accepted refunds are pending', Artisan::output());
    }

    public function test_gateway_failure_marks_the_record_rejected(): void
    {
        $transaction = $this->makeTransaction();
        $service = app(PaymentRefundService::class);

        $refund = $service->beginGatewayRefund($transaction, 250, 'Customer request', $this->user->id);
        $service->markGatewayFailed($refund, 'Card declined');

        $refund->refresh();
        $this->assertSame('rejected', $refund->status);
        $this->assertSame('gateway_failed', $refund->metadata['stage']);
        $this->assertSame(0.0, (float) $transaction->fresh()->refunded_amount);
    }
}
