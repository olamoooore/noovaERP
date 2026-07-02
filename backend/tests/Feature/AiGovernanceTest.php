<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Features\AI\Services\AIApprovalControlService;
use App\Features\AI\Services\AISafetyRulesService;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * AI Governance evidence tests — ADC Section 7 & 8.
 *
 * Proves the AI governance services enforce their contracts at runtime:
 *  - Approval request is recorded.
 *  - Approval by an authorised different user succeeds.
 *  - Self-approval is blocked.
 *  - Rejection sets status.
 *  - High-risk AI action requires approval.
 *  - Daily cost limit is enforced (action blocked when exceeded).
 *  - Tenant context is required (no tenant => blocked).
 */
class AiGovernanceTest extends TenantTestCase
{
    private AIApprovalControlService $approvals;
    private AISafetyRulesService $safety;

    protected function setUp(): void
    {
        parent::setUp();
        // Isolate tests from each other: the array cache is NOT transactional.
        Cache::flush();
        Role::firstOrCreate(['name' => 'admin']);
        $this->approvals = app(AIApprovalControlService::class);
        $this->safety = app(AISafetyRulesService::class);
    }

    private function bindTestTenant(): void
    {
        $this->bindTenantContext('test-tenant');
    }

    public function test_approval_request_is_recorded_and_pending(): void
    {
        $this->bindTestTenant();
        $requestor = $this->adminUser();

        $result = $this->approvals->requestApproval('ai.modify_financial_data', $requestor, [
            'module' => 'accounting',
            'entity_type' => 'Invoice',
            'entity_id' => 'inv-1',
        ], 'Adjust invoice totals');

        $this->assertSame('pending', $result['status']);
        $this->assertNotEmpty($result['approval_id']);

        $row = DB::table('ai_approval_requests')->where('id', $result['approval_id'])->first();
        $this->assertNotNull($row);
        $this->assertSame('pending', $row->status);
        $this->assertSame('test-tenant', $row->tenant_id);
        $this->assertEquals($requestor->id, $row->requestor_id);
    }

    public function test_approval_by_authorised_different_user_succeeds(): void
    {
        $this->bindTestTenant();
        $requestor = $this->adminUser();
        $approver = $this->adminUser();

        $request = $this->approvals->requestApproval('ai.execute_transaction', $requestor);
        $result = $this->approvals->approve($request['approval_id'], $approver, 'Looks correct');

        $this->assertTrue($result['success']);
        $this->assertSame('approved', DB::table('ai_approval_requests')->where('id', $request['approval_id'])->value('status'));
    }

    public function test_negative_self_approval_is_blocked(): void
    {
        $this->bindTestTenant();
        $requestor = $this->adminUser();

        $request = $this->approvals->requestApproval('ai.execute_transaction', $requestor);
        $result = $this->approvals->approve($request['approval_id'], $requestor);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Self-approval', $result['message']);
        $this->assertSame('pending', DB::table('ai_approval_requests')->where('id', $request['approval_id'])->value('status'));
    }

    public function test_rejection_sets_status_to_rejected(): void
    {
        $this->bindTestTenant();
        $requestor = $this->adminUser();
        $approver = $this->adminUser();

        $request = $this->approvals->requestApproval('ai.delete_record', $requestor);
        $result = $this->approvals->reject($request['approval_id'], $approver, 'Not permitted');

        $this->assertTrue($result['success']);
        $this->assertSame('rejected', DB::table('ai_approval_requests')->where('id', $request['approval_id'])->value('status'));
    }

    public function test_high_risk_action_requires_approval(): void
    {
        $this->bindTestTenant();
        $user = $this->adminUser();

        $check = $this->safety->checkActionAllowed('ai.delete_record', $user, [
            'tenant_id' => 'test-tenant',
        ]);

        $this->assertTrue($check['allowed']);
        $this->assertTrue($check['requires_approval']);
    }

    public function test_normal_action_is_allowed_without_approval(): void
    {
        $this->bindTestTenant();
        $user = $this->adminUser();

        $check = $this->safety->checkActionAllowed('ai.read', $user, [
            'tenant_id' => 'test-tenant',
        ]);

        $this->assertTrue($check['allowed']);
        $this->assertFalse($check['requires_approval']);
    }

    public function test_negative_daily_cost_limit_blocks_action(): void
    {
        $this->bindTestTenant();
        $user = $this->adminUser();
        $dailyKey = 'ai_usage:test-tenant:' . date('Y-m-d');
        Cache::put($dailyKey, 150.0); // default daily limit is 100

        $check = $this->safety->checkActionAllowed('ai.read', $user, [
            'tenant_id' => 'test-tenant',
        ]);

        $this->assertFalse($check['allowed']);
        $this->assertStringContainsString('Daily AI cost limit', (string) $check['reason']);
    }

    public function test_negative_no_tenant_context_blocks_action(): void
    {
        // NOTE: bindTestTenant() is intentionally NOT called.
        $user = $this->adminUser();

        // Ensure no tenant is resolved anywhere.
        app(\Stancl\Tenancy\Tenancy::class)->end();
        app()->forgetInstance(\Stancl\Tenancy\Contracts\Tenant::class);

        $check = $this->safety->checkActionAllowed('ai.read', $user);

        $this->assertFalse($check['allowed']);
        $this->assertStringContainsString('tenant', (string) $check['reason']);
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        return $user;
    }
}
