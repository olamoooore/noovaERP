<?php

/**
 * HR39-02 / HR39-03 — Financial Close readiness acceptance + real HTTP
 * ASSURANCE_BLOCKED negative state.
 *
 * HR39-02 true-positive ready_to_close acceptance:
 *   migrated DB; valid tenant + close cycle; all close tasks completed/
 *   cancelled; no open escalation; required signoff state satisfied;
 *   only resolved/dismissed findings (no unresolved blocking); an active
 *   ACC-010 AssuranceBlockingPolicy is present. The actual readiness path
 *   (FinancialCloseCycleService::evaluateReadiness) must report
 *   ready_to_close = true with blocking_findings = 0 and transition the
 *   cycle to ready_to_close.
 *
 * HR39-03 real HTTP close-attempt ASSURANCE_BLOCKED negative state:
 *   same valid non-assurance prerequisites; create the canonical finding;
 *   resolve the exact applicable policy through the ACC-010 authority
 *   (AssuranceGate -> AssuranceBlockingPolicy) and confirm the governed
 *   decision is BLOCK; invoke the actual close HTTP endpoint through the
 *   real router/middleware/controller stack. Required: HTTP 422 with
 *   reason_code = ASSURANCE_BLOCKED; cycle remains opened; fiscal period
 *   unchanged; lock-date unchanged; no cycle_closed audit; paired control
 *   that resolves ONLY the assurance condition makes the same path proceed.
 */

namespace Tests\Feature;

use App\Models\Accounting\AccountingFinding;
use App\Models\AssuranceBlockingPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FinancialClose\Models\AuditLog;
use Modules\FinancialClose\Models\CloseCycle;
use Modules\FinancialClose\Models\CloseTask;
use Tests\TenantTestCase;

class HR39FinancialCloseAssuranceAcceptanceTest extends TenantTestCase
{
    private string $tenantUuid;

    private $owner; // OWNER central role (super — passes RequirePermission for close.admin)

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'HR39 acceptance tenant',
            'email' => $this->tenantUuid.'@hr39.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindTenantContext($this->tenantUuid);

        // Canonical ACC-010 policy (HR39-01): wildcard category, HIGH threshold,
        // BLOCK mode, action_scope covers FINANCIAL_CLOSE_ACTION (PERIOD_CLOSE).
        // The gate resolves this policy; no local severity/status rule exists.
        AssuranceBlockingPolicy::create([
            'tenant_id' => $this->tenantUuid,
            'policy_name' => 'HR39 acceptance policy',
            'policy_version' => '1.0',
            'finding_category' => '*',
            'assertion_level' => 'STANDARD',
            'severity_threshold' => 'HIGH',
            'action_scope' => ['PERIOD_CLOSE'],
            'block_mode' => 'BLOCK',
            'status' => 'active',
        ]);

        // OWNER central role → super role bypass on fine-grained checks,
        // passes accounting.close.admin on the close route. Permission is
        // therefore NOT the reason for any denial (HR39-03 attribution).
        $this->owner = \App\Models\User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->owner->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'owner',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeCycle(string $entityId = 'entity-hr39', array $metadata = []): CloseCycle
    {
        return CloseCycle::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantUuid,
            'entity_id' => $entityId,
            'period_id' => 'period-hr39',
            'name' => 'HR39 acceptance cycle',
            'status' => 'opened',
            'created_by' => 'system',
            'metadata' => $metadata,
        ]);
    }

    private function seedAuthorisedSignoff(CloseCycle $cycle): void
    {
        $meta = is_array($cycle->metadata) ? $cycle->metadata : [];
        $meta['cycle_signoffs'] = [[
            'role' => 'Admin',
            'user_id' => $this->owner->id,
            'signed_at' => now()->toIso8601String(),
            'notes' => 'HR39 acceptance signoff',
        ]];
        $cycle->update(['metadata' => $meta]);
    }

    private function seedCompletedAndCancelledTasks(CloseCycle $cycle): void
    {
        CloseTask::create([
            'tenant_id' => $this->tenantUuid,
            'cycle_id' => $cycle->id,
            'title' => 'Completed task',
            'status' => 'completed',
            'priority' => 'normal',
        ]);
        CloseTask::create([
            'tenant_id' => $this->tenantUuid,
            'cycle_id' => $cycle->id,
            'title' => 'Cancelled task',
            'status' => 'cancelled',
            'priority' => 'normal',
        ]);
    }

    private function makeFinding(CloseCycle $cycle, string $severity, string $status): AccountingFinding
    {
        return AccountingFinding::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantUuid,
            'entity_id' => $cycle->entity_id,
            'finding_type' => 'control_weakness',
            'severity' => $severity,
            'status' => $status,
            'source_type' => 'close_cycle',
            'source_id' => $cycle->id,
            'title' => 'HR39 acceptance finding',
            'description' => 'Synthetic finding for HR39 acceptance.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // HR39-02 — true positive ready_to_close acceptance
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function hr39_02_all_prerequisites_satisfied_yields_ready_to_close(): void
    {
        $cycle = $this->makeCycle();
        $this->seedCompletedAndCancelledTasks($cycle);
        $this->seedAuthorisedSignoff($cycle);

        // Only a RESOLVED finding (not in canonical unresolved candidates).
        $resolved = $this->makeFinding($cycle, 'critical', 'resolved');
        $resolved->update(['resolved_at' => now()]);

        $service = app(\Modules\FinancialClose\Services\FinancialCloseCycleService::class);
        $evaluation = $service->evaluateReadiness($this->tenantUuid, $cycle->id);

        // True positive: readiness is true with zero blocking findings, and
        // the cycle auto-transitioned to ready_to_close (no other prerequisite
        // is the blocker — tasks/escalations/signoffs all satisfied, and the
        // resolved finding is not in the canonical unresolved candidate set).
        $this->assertTrue($evaluation['ready_to_close'],
            'Complete readiness fixture must yield ready_to_close=true: '.json_encode($evaluation));
        $this->assertSame(0, $evaluation['blocking_findings']);
        $this->assertSame(0, $evaluation['incomplete_tasks']);
        $this->assertSame(0, $evaluation['open_escalations']);
        $this->assertGreaterThanOrEqual($evaluation['required_signoffs'], $evaluation['authorised_signoffs']);
        $this->assertSame('ready_to_close', $cycle->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // HR39-03 — real HTTP close-attempt ASSURANCE_BLOCKED + paired control
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function hr39_03_close_attempt_with_governed_blocking_finding_is_assurance_blocked_and_paired_resolve_proceeds(): void
    {
        $cycle = $this->makeCycle();
        $this->seedCompletedAndCancelledTasks($cycle);
        $this->seedAuthorisedSignoff($cycle);

        // OPEN critical finding tied to the cycle — must be a candidate and
        // must trigger AssuranceGate BLOCK under the seeded policy.
        $finding = $this->makeFinding($cycle, 'critical', 'open');

        // Attribution precondition: every non-assurance prerequisite is
        // satisfied (tasks done, no escalations, signoff present, OWNER role
        // bypasses fine-grained permission checks, tenant/entity context bound).
        $service = app(\Modules\FinancialClose\Services\FinancialCloseCycleService::class);
        $pre = $service->evaluateReadiness($this->tenantUuid, $cycle->id);
        $this->assertSame(1, $pre['blocking_findings'],
            'Sanity: the open critical finding must produce one governed blocking finding.');
        $this->assertFalse($pre['ready_to_close']);
        $this->assertSame('ASSURANCE_BLOCKED', $pre['blocking_policy']['decision_reason']);
        $this->assertSame('ACC-010', $pre['blocking_policy']['authority']);

        $cycleId = $cycle->id;
        $periodCode = $cycle->period_id;
        $lockDateCountBefore = DB::table('lock_dates')
            ->where('lock_type', 'all')
            ->where('reason', 'like', "%Financial Close cycle {$cycleId}%")
            ->count();
        $cycleClosedAuditBefore = AuditLog::where('action', 'cycle_closed')
            ->where('entity_id', $cycleId)->count();

        // ── Negative state: real HTTP close attempt ──
        $denied = $this->actingAs($this->owner)
            ->postJson("/api/v1/close/cycles/{$cycleId}/close");

        $denied->assertStatus(422);
        $body = $denied->json();
        $this->assertSame('ASSURANCE_BLOCKED', $body['reason_code'] ?? null,
            'Close must be denied with the canonical ASSURANCE_BLOCKED reason code.');
        $this->assertSame('ACC-010', $body['evaluation']['blocking_policy']['authority'] ?? null);

        // Cycle remains unclosed; no period lock side effect; no cycle_closed audit.
        $this->assertSame('opened', $cycle->fresh()->status);
        $lockDateCountAfter = DB::table('lock_dates')
            ->where('lock_type', 'all')
            ->where('reason', 'like', "%Financial Close cycle {$cycleId}%")
            ->count();
        $this->assertSame($lockDateCountBefore, $lockDateCountAfter,
            'No lock-date side effect may occur on an assurance-blocked close.');
        $this->assertSame($cycleClosedAuditBefore, AuditLog::where('action', 'cycle_closed')
            ->where('entity_id', $cycleId)->count(),
            'No cycle_closed audit may be written on an assurance-blocked close.');

        // ── Paired control: resolve ONLY the assurance blocking condition ──
        $finding->update(['status' => 'resolved', 'resolved_at' => now()]);

        $post = $service->evaluateReadiness($this->tenantUuid, $cycle->id);
        $this->assertSame(0, $post['blocking_findings'],
            'Resolving the finding must clear the canonical blocking decision.');
        $this->assertTrue($post['ready_to_close']);

        // Same HTTP path now proceeds.
        $allowed = $this->actingAs($this->owner)
            ->postJson("/api/v1/close/cycles/{$cycleId}/close");

        $allowed->assertOk();
        $this->assertSame('closed', $cycle->fresh()->status);
        $this->assertGreaterThan(0, AuditLog::where('action', 'cycle_closed')
            ->where('entity_id', $cycleId)->count(),
            'Successful close must write a cycle_closed audit row.');
    }
}
