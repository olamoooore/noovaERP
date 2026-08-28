<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Services\Gates\AssuranceGate;
use App\Models\Accounting\AccountingFinding;
use App\Models\AssuranceBlockingPolicy;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue P1-7 — AssuranceGate must consult the canonical
 * AssuranceBlockingPolicy::shouldBlock() method, not the denormalized
 * blocking_policy string from the finding row.
 */
class IssueP17AssuranceGatePolicyTest extends TenantTestCase
{
    private string $tenantUuid;

    private string $sourceUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        $this->sourceUuid = (string) Str::uuid();
    }

    private function makeFinding(string $category = 'control_weakness', string $severity = 'high'): AccountingFinding
    {
        return AccountingFinding::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantUuid,
            'entity_id' => (string) Str::uuid(),
            'finding_type' => $category,
            'severity' => $severity,
            'status' => 'open',
            'source_type' => 'journal_entry',
            'source_id' => $this->sourceUuid,
            'title' => 'Test finding',
            'description' => 'Test description',
            'amount' => 0,
            'confidence' => 0.9,
        ]);
    }

    public function test_no_policy_means_no_block_per_adr005_391(): void
    {
        $this->makeFinding('critical', 'critical');

        $gate = app(AssuranceGate::class);
        $result = $gate->evaluate([
            'tenant_id' => $this->tenantUuid,
            'requested_action' => 'POST_JOURNAL',
            'record_type' => 'journal_entry',
            'record_id' => $this->sourceUuid,
        ]);

        $this->assertSame('PASS', $result->result->value);
    }

    public function test_policy_with_severity_threshold_blocks_when_finding_meets_threshold(): void
    {
        $this->makeFinding('control_weakness', 'high');

        AssuranceBlockingPolicy::create([
            'tenant_id' => $this->tenantUuid,
            'policy_version' => '1.0',
            'policy_name' => 'Block control_weakness above HIGH',
            'finding_category' => 'control_weakness',
            'assertion_level' => 'STANDARD',
            'severity_threshold' => 'HIGH',
            'action_scope' => ['POST_JOURNAL'],
            'block_mode' => 'BLOCK',
            'effective_from' => now()->subDay(),
            'status' => 'active',
        ]);

        $gate = app(AssuranceGate::class);
        $result = $gate->evaluate([
            'tenant_id' => $this->tenantUuid,
            'requested_action' => 'POST_JOURNAL',
            'record_type' => 'journal_entry',
            'record_id' => $this->sourceUuid,
        ]);

        $this->assertSame('BLOCK', $result->result->value);
        $this->assertSame('ASSURANCE_BLOCKED', $result->reasonCode);
    }

    public function test_low_severity_finding_does_not_block_when_threshold_is_high(): void
    {
        $this->makeFinding('control_weakness', 'low');

        AssuranceBlockingPolicy::create([
            'tenant_id' => $this->tenantUuid,
            'policy_version' => '1.0',
            'policy_name' => 'Block control_weakness above HIGH',
            'finding_category' => 'control_weakness',
            'assertion_level' => 'STANDARD',
            'severity_threshold' => 'HIGH',
            'action_scope' => ['POST_JOURNAL'],
            'block_mode' => 'BLOCK',
            'effective_from' => now()->subDay(),
            'status' => 'active',
        ]);

        $gate = app(AssuranceGate::class);
        $result = $gate->evaluate([
            'tenant_id' => $this->tenantUuid,
            'requested_action' => 'POST_JOURNAL',
            'record_type' => 'journal_entry',
            'record_id' => $this->sourceUuid,
        ]);

        $this->assertSame('PASS', $result->result->value);
    }

    public function test_policy_does_not_apply_to_unmatched_action(): void
    {
        $this->makeFinding('control_weakness', 'critical');

        AssuranceBlockingPolicy::create([
            'tenant_id' => $this->tenantUuid,
            'policy_version' => '1.0',
            'policy_name' => 'Block control_weakness for reverse only',
            'finding_category' => 'control_weakness',
            'assertion_level' => 'STANDARD',
            'severity_threshold' => 'CRITICAL',
            'action_scope' => ['REVERSE_ENTRY'],
            'block_mode' => 'BLOCK',
            'effective_from' => now()->subDay(),
            'status' => 'active',
        ]);

        $gate = app(AssuranceGate::class);
        $result = $gate->evaluate([
            'tenant_id' => $this->tenantUuid,
            'requested_action' => 'POST_JOURNAL',
            'record_type' => 'journal_entry',
            'record_id' => $this->sourceUuid,
        ]);

        $this->assertSame('PASS', $result->result->value);
    }

    public function test_wildcard_category_blocks_all_categories(): void
    {
        $this->makeFinding('anomaly', 'critical');

        AssuranceBlockingPolicy::create([
            'tenant_id' => $this->tenantUuid,
            'policy_version' => '1.0',
            'policy_name' => 'Block anything critical',
            'finding_category' => '*',
            'assertion_level' => 'STANDARD',
            'severity_threshold' => 'CRITICAL',
            'action_scope' => null,
            'block_mode' => 'BLOCK',
            'effective_from' => now()->subDay(),
            'status' => 'active',
        ]);

        $gate = app(AssuranceGate::class);
        $result = $gate->evaluate([
            'tenant_id' => $this->tenantUuid,
            'requested_action' => 'POST_JOURNAL',
            'record_type' => 'journal_entry',
            'record_id' => $this->sourceUuid,
        ]);

        $this->assertSame('BLOCK', $result->result->value);
    }

    public function test_resolved_finding_does_not_trigger_gate_evaluation(): void
    {
        $finding = $this->makeFinding('control_weakness', 'critical');
        $finding->update(['status' => 'resolved']);

        AssuranceBlockingPolicy::create([
            'tenant_id' => $this->tenantUuid,
            'policy_version' => '1.0',
            'policy_name' => 'Block control_weakness above CRITICAL',
            'finding_category' => 'control_weakness',
            'assertion_level' => 'STANDARD',
            'severity_threshold' => 'CRITICAL',
            'action_scope' => null,
            'block_mode' => 'BLOCK',
            'effective_from' => now()->subDay(),
            'status' => 'active',
        ]);

        $gate = app(AssuranceGate::class);
        $result = $gate->evaluate([
            'tenant_id' => $this->tenantUuid,
            'requested_action' => 'POST_JOURNAL',
            'record_type' => 'journal_entry',
            'record_id' => $this->sourceUuid,
        ]);

        $this->assertSame('PASS', $result->result->value);
    }
}
