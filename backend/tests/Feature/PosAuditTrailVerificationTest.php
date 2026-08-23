<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Tests\TenantTestCase;

/**
 * AUDIT-001: Audit trail verification test
 * Verifies void/refund/return audit trail is complete and durable
 */
class PosAuditTrailVerificationTest extends TenantTestCase
{

    private AuditLogger $auditLogger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAuditLogsTableExists();
        $this->auditLogger = app(AuditLogger::class);
        $this->seedTerminalAndSessionFixtures();
    }

    private array $auditContext = [];

    private function seedTerminalAndSessionFixtures(): void
    {
        $user = User::factory()->create(['name' => 'Audit Test User', 'email' => 'audit-user@audit-trail.test']);

        $terminal1 = PosTerminal::create(['name' => 'Terminal 1', 'code' => 'AUDIT-T-001', 'is_active' => true]);
        $terminal2 = PosTerminal::create(['name' => 'Terminal 2', 'code' => 'AUDIT-T-002', 'is_active' => true]);
        $terminal3 = PosTerminal::create(['name' => 'Terminal 3', 'code' => 'AUDIT-T-003', 'is_active' => true]);

        $session1 = PosSession::create([
            'terminal_id' => $terminal1->id, 'user_id' => $user->id,
            'session_number' => 'AUDIT-S-001', 'opening_balance' => 0,
            'status' => 'open', 'opened_at' => now()->subHour(),
        ]);
        $session2 = PosSession::create([
            'terminal_id' => $terminal2->id, 'user_id' => $user->id,
            'session_number' => 'AUDIT-S-002', 'opening_balance' => 0,
            'status' => 'open', 'opened_at' => now()->subHour(),
        ]);
        $session3 = PosSession::create([
            'terminal_id' => $terminal3->id, 'user_id' => $user->id,
            'session_number' => 'AUDIT-S-003', 'opening_balance' => 0,
            'status' => 'open', 'opened_at' => now()->subHour(),
        ]);

        $this->auditContext = [
            'terminal1_id' => $terminal1->id,
            'terminal2_id' => $terminal2->id,
            'terminal3_id' => $terminal3->id,
            'session1_id' => $session1->id,
            'session2_id' => $session2->id,
            'session3_id' => $session3->id,
        ];
    }

    private function ensureAuditLogsTableExists(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('operation', 50)->index();
            $table->string('reference_type', 100)->index();
            $table->unsignedBigInteger('reference_id')->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->string('source_route')->nullable();
            $table->string('idempotency_key')->nullable()->index();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id', 'operation']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['operation', 'created_at']);
        });
    }

    /** @test */
    public function payment_creates_durable_audit_record()
    {
        $this->auditLogger->logPayment(
            orderId: 100,
            paymentId: 50,
            method: 'cash',
            amount: 99.99,
            terminalId: $this->auditContext['terminal1_id'],
            sessionId: $this->auditContext['session1_id']
        );

        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'pos_payment',
            'reference_type' => 'pos_order',
            'reference_id' => 100,
        ]);

        $log = AuditLog::where('operation', 'pos_payment')
            ->where('reference_id', 100)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->auditContext['terminal1_id'], $log->terminal_id);
        $this->assertEquals($this->auditContext['session1_id'], $log->session_id);
        $this->assertEquals('payment_100_50', $log->idempotency_key);
    }

    /** @test */
    public function void_creates_durable_audit_record_with_required_fields()
    {
        $this->auditLogger->logVoid(
            orderId: 200,
            voidReason: 'Customer changed mind',
            approvedBy: 5,
            terminalId: $this->auditContext['terminal2_id'],
            sessionId: $this->auditContext['session3_id']
        );

        $log = AuditLog::where('operation', 'pos_void')
            ->where('reference_id', 200)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->auditContext['terminal2_id'], $log->terminal_id);
        $this->assertEquals($this->auditContext['session3_id'], $log->session_id);
        $this->assertNotNull($log->after_state);
        $this->assertEquals('Customer changed mind', $log->after_state['void_reason']);
        $this->assertEquals(5, $log->after_state['approved_by']);
    }

    /** @test */
    public function refund_creates_durable_audit_record_with_required_fields()
    {
        $this->auditLogger->logRefund(
            orderId: 300,
            refundPaymentId: 75,
            refundAmount: 45.00,
            notes: 'Defective product',
            approvedBy: 6,
            terminalId: $this->auditContext['terminal1_id'],
            sessionId: $this->auditContext['session2_id']
        );

        $log = AuditLog::where('operation', 'pos_refund')
            ->where('reference_id', 300)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(45.00, $log->after_state['refund_amount']);
        $this->assertEquals('Defective product', $log->after_state['notes']);
        $this->assertEquals(6, $log->after_state['approved_by']);
    }

    /** @test */
    public function return_creates_durable_audit_record_with_items()
    {
        $items = [
            ['product_id' => 10, 'quantity' => 2, 'unit_price' => 25.00],
        ];

        $this->auditLogger->logReturn(
            orderId: 400,
            returnOrderId: 401,
            items: $items,
            approvedBy: 7,
            terminalId: $this->auditContext['terminal1_id'],
            sessionId: $this->auditContext['session1_id']
        );

        $log = AuditLog::where('operation', 'pos_return')
            ->where('reference_id', 400)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(401, $log->after_state['return_order_id']);
        $this->assertCount(1, $log->after_state['items']);
    }

    /** @test */
    public function approval_grant_creates_durable_audit_record()
    {
        $this->auditLogger->logApprovalGrant(
            approvalRequestId: 10,
            operation: 'pos_void',
            referenceId: 200,
            terminalId: $this->auditContext['terminal1_id'],
            sessionId: $this->auditContext['session1_id']
        );

        $log = AuditLog::where('operation', 'approval_grant')
            ->where('reference_id', 10)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('pos_void', $log->metadata['approved_operation']);
        $this->assertEquals(200, $log->metadata['approved_reference_id']);
    }

    /** @test */
    public function approval_reject_creates_durable_audit_record_with_reason()
    {
        $this->auditLogger->logApprovalReject(
            approvalRequestId: 11,
            operation: 'pos_refund',
            referenceId: 300,
            reason: 'Amount exceeds limit',
            terminalId: $this->auditContext['terminal1_id'],
            sessionId: $this->auditContext['session1_id']
        );

        $log = AuditLog::where('operation', 'approval_reject')
            ->where('reference_id', 11)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('Amount exceeds limit', $log->metadata['reason']);
    }

    /** @test */
    public function audit_records_are_idempotent_on_replay()
    {
        // Log same payment twice
        $this->auditLogger->logPayment(100, 50, 'cash', 99.99, $this->auditContext['terminal1_id'], $this->auditContext['session1_id']);
        $this->auditLogger->logPayment(100, 50, 'cash', 99.99, $this->auditContext['terminal1_id'], $this->auditContext['session1_id']);

        $count = AuditLog::where('operation', 'pos_payment')
            ->where('reference_id', 100)
            ->count();

        $this->assertEquals(1, $count, 'Replay must not create duplicate audit records');
    }

    /** @test */
    public function audit_records_cannot_be_modified()
    {
        $this->auditLogger->logPayment(100, 50, 'cash', 99.99, $this->auditContext['terminal1_id'], $this->auditContext['session1_id']);

        $log = AuditLog::where('operation', 'pos_payment')->first();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $log->update(['operation' => 'tampered']);
    }

    /** @test */
    public function audit_records_cannot_be_deleted()
    {
        $this->auditLogger->logPayment(100, 50, 'cash', 99.99, $this->auditContext['terminal1_id'], $this->auditContext['session1_id']);

        $log = AuditLog::where('operation', 'pos_payment')->first();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $log->delete();
    }

    /** @test */
    public function audit_trail_covers_full_void_lifecycle()
    {
        $orderId = 500;

        // 1. Payment
        $this->auditLogger->logPayment($orderId, 60, 'card', 150.00, $this->auditContext['terminal1_id'], $this->auditContext['session1_id']);

        // 2. Approval granted
        $this->auditLogger->logApprovalGrant(20, 'pos_void', $orderId, $this->auditContext['terminal1_id'], $this->auditContext['session1_id']);

        // 3. Void
        $this->auditLogger->logVoid($orderId, 'Wrong item', 5, $this->auditContext['terminal1_id'], $this->auditContext['session1_id']);

        $trail = AuditLog::where('reference_id', $orderId)
            ->orWhere(function ($q) use ($orderId) {
                $q->where('operation', 'approval_grant')
                    ->whereJsonContains('metadata->approved_reference_id', $orderId);
            })
            ->orderBy('created_at')
            ->get();

        $operations = $trail->pluck('operation')->toArray();
        $this->assertContains('pos_payment', $operations);
        $this->assertContains('pos_void', $operations);
    }
}
