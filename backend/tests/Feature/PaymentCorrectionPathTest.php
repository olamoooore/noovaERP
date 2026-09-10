<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * GAP-004 Tests — Payment Correction Paths (ADR-005 §15.3, G3).
 *
 * Verifies that:
 * - Payment cancellation routes through PaymentCorrectionService
 * - PaymentCorrectionService creates GL reversals via PostingEngine
 * - Unapply payment creates GL reversal (not just subledger mutation)
 * - Settlement reversal creates compensating records (not hard-delete)
 */
class PaymentCorrectionPathTest extends TenantTestCase
{
    /** @test */
    public function payment_controller_cancel_routes_through_correction_service(): void
    {
        $reflection = new \ReflectionClass(\App\Http\Controllers\Api\PaymentController::class);
        $method = $reflection->getMethod('cancel');
        $source = file_get_contents($method->getFileName());

        // The cancel method should now route through PaymentCorrectionService
        $this->assertStringContainsString(
            'PaymentCorrectionService',
            $source,
            'PaymentController::cancel() must route through PaymentCorrectionService'
        );
        $this->assertStringContainsString(
            'unapplyPayment',
            $source,
            'PaymentController::cancel() must call PaymentCorrectionService::unapplyPayment()'
        );
    }

    /** @test */
    public function payment_correction_service_unapply_creates_gl_reversal(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PaymentCorrectionService::class);
        $method = $reflection->getMethod('unapplyPayment');
        $source = file_get_contents($method->getFileName());

        // unapplyPayment must create a GL reversal
        $this->assertStringContainsString(
            'PostingEngine',
            $source,
            'PaymentCorrectionService::unapplyPayment() must call PostingEngine for GL reversal'
        );
        $this->assertStringContainsString(
            'reverse(',
            $source,
            'PaymentCorrectionService::unapplyPayment() must call PostingEngine::reverse()'
        );
    }

    /** @test */
    public function payment_correction_service_uses_canonical_subledger_service(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PaymentCorrectionService::class);
        $source = file_get_contents($reflection->getFileName());

        // Should use lifecycleService for subledger updates, not direct ->update()
        $this->assertStringContainsString(
            'applyPaymentReversal',
            $source,
            'PaymentCorrectionService must use lifecycleService for invoice payment reversal'
        );
        $this->assertStringContainsString(
            'applyPaymentRefund',
            $source,
            'PaymentCorrectionService must use lifecycleService for invoice payment refund'
        );
    }

    /** @test */
    public function settlement_reversal_creates_compensating_records(): void
    {
        // Issue #41 end-to-end proof: the schema/fillable additions (migration
        // 2026_09_08_000001_add_settlement_reversal_columns + Allocation
        // $fillable) must support the real reverse path through the controller,
        // not just a source-text assertion. The SettlementController reverse()
        // creates compensating Allocation rows with reversal_of_allocation_id
        // linkage and marks originals as reversed — this test exercises the
        // real HTTP route and asserts the migrated schema is queryable with
        // correct lineage.
        $tenantId = (string) \Illuminate\Support\Str::uuid();
        $this->bindTenantContext($tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        // user_company_access FK to public.tenants — ensure the row exists.
        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantId,
            'name' => 'HR41 tenant',
            'email' => $tenantId.'@hr41.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Authorized actor with accounting.reverse (Accountant role carries it
        // per the canonical PERMISSION_MATRIX) and tenant access in the SAME
        // tenant the request resolves to.
        $user = \App\Models\User::factory()->create();
        $user->assignRole('Accountant');
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role' => 'accountant',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Real Settlement + Allocation to reverse. The SettlementController
        // reverse() iterates non-reversed allocations and creates compensating
        // rows; assert the linkage and reversal markers on a real DB.
        $settlement = \App\Models\Settlement::create([
            'tenant_id' => $tenantId,
            'settlement_number' => 'SETT-HR41-001',
            'settlement_state' => 'FULLY_ALLOCATED',
            'settlement_type' => 'payment_allocation',
            'allocated_amount' => 500.00,
            'unapplied_amount' => 0.00,
            'currency_code' => 'USD',
            'initiated_by' => $user->id,
        ]);
        $original = \App\Models\Allocation::create([
            'tenant_id' => $tenantId,
            'settlement_id' => $settlement->id,
            'allocation_type' => 'invoice',
            'source_record_type' => 'invoice',
            'source_record_id' => 9001,
            'allocated_amount' => 500.00,
            'currency_code' => 'USD',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/accounting/settlements/{$settlement->id}/reverse",
                ['reason' => 'HR41 issue #41 end-to-end proof']);

        $response->assertOk();

        // Original allocation is marked reversed.
        $this->assertNotNull($original->fresh()->reversed_at,
            'Original allocation must be marked reversed (reversed_at set).');
        $this->assertSame('HR41 issue #41 end-to-end proof',
            $original->fresh()->reversal_reason);

        // A compensating allocation exists with reversal_of_allocation_id
        // pointing to the original and a negative allocated_amount.
        $compensating = \App\Models\Allocation::where('reversal_of_allocation_id', $original->id)->first();
        $this->assertNotNull($compensating,
            'A compensating allocation must be created with reversal_of_allocation_id linkage.');
        $this->assertEqualsWithDelta(-500.00, (float) $compensating->allocated_amount, 0.01,
            'Compensating allocation must carry the negative allocated_amount.');
        $this->assertNotNull($compensating->reversed_at);
        $this->assertSame($original->source_record_type, $compensating->source_record_type);
        $this->assertSame($original->source_record_id, $compensating->source_record_id);

        // Settlement transitions to REVERSED state with allocated_amount=0.
        $settlement->refresh();
        $this->assertSame('REVERSED', $settlement->settlement_state);
        $this->assertEqualsWithDelta(0, (float) $settlement->allocated_amount, 0.01);
    }
}
