<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuthorityEvaluationSnapshot;
use App\Models\User;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue #48 — Stop fabricating actor/tenant UUIDs in audit records.
 *
 * Verifies:
 *  - Real numeric actor IDs are mapped to a stable UUID v5 (no random fabrication).
 *  - Missing identity blocks posting and never silently fabricates a UUID.
 */
class Issue48NoFabricatedUuidsTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
    }

    public function test_numeric_actor_id_maps_to_deterministic_uuid_v5(): void
    {
        $numericActorId = 12345;
        $expected = Uuid::uuid5(Uuid::NAMESPACE_URL, 'actor:'.$numericActorId)->toString();

        // Same input → same output (deterministic)
        $second = Uuid::uuid5(Uuid::NAMESPACE_URL, 'actor:'.$numericActorId)->toString();
        $this->assertSame($expected, $second);

        // Different input → different output
        $different = Uuid::uuid5(Uuid::NAMESPACE_URL, 'actor:67890')->toString();
        $this->assertNotSame($expected, $different);
    }

    public function test_missing_actor_in_evaluation_returns_block_not_silent_pass(): void
    {
        $evaluator = app(\App\Accounting\Services\ActionEligibilityEvaluator::class);

        // Invoke with no tenant/actor — must return BLOCK, not ALLOW.
        $result = $evaluator->evaluate(
            action: 'POST_JOURNAL',
            recordType: 'JournalEntry',
            recordId: 'test-1',
            context: [
                'tenant_id' => null,
                'actor_id' => null,
                'required_permission' => 'accounting.post',
            ],
            requireSnapshot: false,
        );

        $this->assertTrue($result->isBlocked(), 'Missing identity must BLOCK, not silently pass.');
        $this->assertSame(
            'MISSING_PERMISSION_CONTEXT',
            $result->blockingReason?->value
        );
    }

    /**
     * AC1 (real record, not just uuid math): a posting-evaluation performed by
     * a real numeric actor persists a snapshot whose actor_id is the STABLE
     * uuid5 of that real ID — re-running with the same actor yields the SAME
     * value (proving it is derived from the real identity, not a random UUID),
     * and it round-trips back to the numeric actor.
     */
    public function test_real_snapshot_actor_id_is_deterministic_and_traces_to_real_actor(): void
    {
        $evaluator = app(\App\Accounting\Services\ActionEligibilityEvaluator::class);
        $numericActorId = 777;

        $make = function () use ($evaluator, $numericActorId) {
            return $evaluator->evaluate(
                action: 'POST_JOURNAL',
                recordType: 'JournalEntry',
                recordId: 'trace-' . uniqid(),
                context: [
                    'tenant_id' => $this->tenantUuid,
                    'entity_id' => null,
                    'actor_id' => (string) $numericActorId,
                    'document_type' => 'invoice',
                    'posting_date' => now()->toDateString(),
                    'total_amount' => '100.00',
                    'correlation_id' => 'corr-' . uniqid(),
                    'required_permission' => null, // not material to identity assertion
                ],
                requireSnapshot: true,
            );
        };

        $first = $make();
        $second = $make();

        $snap1 = AuthorityEvaluationSnapshot::where('action_eligibility_evaluation_id', $first->snapshotId)->first();
        $snap2 = AuthorityEvaluationSnapshot::where('action_eligibility_evaluation_id', $second->snapshotId)->first();

        $this->assertNotNull($snap1, 'Snapshot must persist on requireSnapshot:true');
        $this->assertSame(
            $snap1->actor_id,
            $snap2->actor_id,
            'Same numeric actor must map to the SAME actor_id across calls (not random).'
        );
        $this->assertSame(
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'actor:' . $numericActorId)->toString(),
            $snap1->actor_id,
            'Snapshot actor_id must be the deterministic uuid5 of the real numeric actor.'
        );
        $this->assertSame($this->tenantUuid, $snap1->tenant_id, 'Tenant id must be recorded verbatim.');
    }

    /**
     * AC3: a successful posting writes an outbox event whose tenant_id is the
     * REAL bound tenant (never a randomly fabricated UUID).
     */
    public function test_outbox_event_tenant_id_is_real_on_successful_posting(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo('accounting.write');

        $debit = \App\Models\ChartOfAccount::create(['code' => '48-D-'.uniqid(), 'name' => 'Debit', 'type' => 'asset', 'account_type' => 'asset', 'is_active' => true]);
        $credit = \App\Models\ChartOfAccount::create(['code' => '48-C-'.uniqid(), 'name' => 'Credit', 'type' => 'liability', 'account_type' => 'liability', 'is_active' => true]);

        $request = new \App\Accounting\DTOs\PostingRequest(
            documentType: 'invoice',
            documentId: 480001,
            description: 'Outbox identity test',
            postingDate: now()->toDateString(),
            lines: [
                new \App\Accounting\DTOs\PostingRequestLine('cash', $debit->id, 100.0, 0),
                new \App\Accounting\DTOs\PostingRequestLine('accounts_receivable', $credit->id, 0, 100.0),
            ],
            userId: $user->id,
        );

        $result = app(\App\Accounting\PostingEngine::class)->post($request);
        $this->assertTrue($result->success, 'Posting failed: ' . ($result->error ?? ''));

        $event = \App\Models\AccountingOutboxEvent::where('aggregate_type', 'JournalEntry')
            ->where('aggregate_id', (string) $result->journalEntry->id)
            ->first();

        $this->assertNotNull($event, 'Successful posting must emit an outbox event');
        $this->assertSame(
            $this->tenantUuid,
            (string) $event->tenant_id,
            'Outbox tenant_id must be the real bound tenant, not a fabricated UUID'
        );
    }

    /**
     * AC2 (fail-closed posting path): with no tenant context bound, posting
     * must fail with MISSING_TENANT_CONTEXT and must NOT emit any outbox
     * event (no fabricated tenant identity).
     */
    public function test_posting_fails_closed_without_tenant_context_no_outbox_fabricated(): void
    {
        // Unbind tenancy to simulate a tenant-less posting call.
        $tenancy = app(\Stancl\Tenancy\Tenancy::class);
        $tenancy->end();
        $this->app->forgetInstance(\Stancl\Tenancy\Contracts\Tenant::class);

        $debit = \App\Models\ChartOfAccount::create(['code' => '48F-D-'.uniqid(), 'name' => 'D', 'type' => 'asset', 'account_type' => 'asset', 'is_active' => true]);
        $credit = \App\Models\ChartOfAccount::create(['code' => '48F-C-'.uniqid(), 'name' => 'C', 'type' => 'liability', 'account_type' => 'liability', 'is_active' => true]);

        $before = \App\Models\AccountingOutboxEvent::count();

        $request = new \App\Accounting\DTOs\PostingRequest(
            documentType: 'invoice',
            documentId: 480002,
            description: 'No-tenant posting test',
            postingDate: now()->toDateString(),
            lines: [
                new \App\Accounting\DTOs\PostingRequestLine('cash', $debit->id, 50.0, 0),
                new \App\Accounting\DTOs\PostingRequestLine('accounts_receivable', $credit->id, 0, 50.0),
            ],
            userId: 1,
        );

        $result = app(\App\Accounting\PostingEngine::class)->post($request);

        $this->assertFalse($result->success, 'Posting without tenant context must fail closed.');
        $this->assertStringContainsString(
            \App\Accounting\Enums\ReasonCode::MISSING_TENANT_CONTEXT->value,
            $result->error ?? ''
        );
        $this->assertSame(
            $before,
            \App\Models\AccountingOutboxEvent::count(),
            'No outbox event may be fabricated for a failed tenant-less posting.'
        );
    }

    /**
     * HR48-02 (hostile re-review): the required case — tenant present, actor
     * missing, requireSnapshot=true — must BLOCK and persist no snapshot
     * (no fabricated actor UUID).
     */
    public function test_missing_actor_with_tenant_present_blocks_and_persists_no_snapshot(): void
    {
        $evaluator = app(\App\Accounting\Services\ActionEligibilityEvaluator::class);

        $snapshotsBefore = AuthorityEvaluationSnapshot::count();

        $result = $evaluator->evaluate(
            action: 'POST_JOURNAL',
            recordType: 'JournalEntry',
            recordId: 'test-hr48-02',
            context: [
                'tenant_id' => $this->tenantUuid,
                'actor_id' => null,
                'required_permission' => 'accounting.post',
            ],
            requireSnapshot: true,
        );

        $this->assertTrue($result->isBlocked(), 'A missing actor must BLOCK even when the tenant is present.');
        $this->assertSame('MISSING_PERMISSION_CONTEXT', $result->blockingReason?->value);
        $this->assertNull($result->snapshotId, 'No snapshot may be persisted without an actor identity.');
        $this->assertSame($snapshotsBefore, AuthorityEvaluationSnapshot::count(),
            'No fabricated-actor snapshot may survive.');
    }
}
