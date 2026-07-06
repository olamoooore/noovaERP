<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Approvals\ApprovalPolicy;
use App\Platform\Approvals\ApprovalDelegation;
use Tests\TenantTestCase;

class ApprovalServiceTest extends TenantTestCase
{
    protected ApprovalService $service;
    protected User $userA;
    protected User $userB;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ApprovalService::class);
        $this->bindTenantContext(\Illuminate\Support\Str::uuid()->toString());

        $this->userA = User::create(['name' => 'User A', 'email' => 'a@test.com', 'password' => bcrypt('password')]);
        $this->userB = User::create(['name' => 'User B', 'email' => 'b@test.com', 'password' => bcrypt('password')]);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
    }

    public function test_create_request(): void
    {
        $result = $this->service->createRequest('refund', 'PosOrder', 1, 100.00, 'Customer complaint', null, $this->userA->id);

        $this->assertFalse($result->duplicate);
        $this->assertSame('pending', $result->status);

        $request = ApprovalRequest::find($result->id);
        $this->assertNotNull($request);
        $this->assertSame('refund', $request->request_type);
        $this->assertSame(100.00, (float) $request->amount);
    }

    public function test_create_request_duplicate_returns_existing(): void
    {
        $r1 = $this->service->createRequest('refund', 'PosOrder', 1, 100.00, 'Reason 1', null, $this->userA->id);
        $r2 = $this->service->createRequest('refund', 'PosOrder', 1, 200.00, 'Reason 2', null, $this->userA->id);

        $this->assertTrue($r2->duplicate);
        $this->assertSame($r1->id, $r2->id);
    }

    public function test_approve_request(): void
    {
        $createResult = $this->service->createRequest('refund', 'PosOrder', 1, 100.00, 'Reason', null, $this->userA->id);

        $result = $this->service->approve($createResult->id, $this->userB->id, 'Approved');

        $this->assertSame('approved', $result->status);

        $request = ApprovalRequest::find($createResult->id);
        $this->assertSame('approved', $request->status);
        $this->assertSame($this->userB->id, $request->reviewed_by);
    }

    public function test_approve_self_approval_blocked(): void
    {
        $createResult = $this->service->createRequest('refund', 'PosOrder', 1, 100.00, 'Reason', null, $this->userA->id);

        $this->expectException(\DomainException::class);
        $this->service->approve($createResult->id, $this->userA->id);
    }

    public function test_approve_expired_request_blocked(): void
    {
        $createResult = $this->service->createRequest('refund', 'PosOrder', 1, 100.00, 'Reason', null, $this->userA->id);
        ApprovalRequest::where('id', $createResult->id)->update(['expires_at' => now()->subHour()]);

        $this->expectException(\DomainException::class);
        $this->service->approve($createResult->id, $this->userB->id);
    }

    public function test_reject_request(): void
    {
        $createResult = $this->service->createRequest('refund', 'PosOrder', 1, 100.00, 'Reason', null, $this->userA->id);

        $result = $this->service->reject($createResult->id, $this->userB->id, 'Not valid');

        $this->assertSame('rejected', $result->status);

        $request = ApprovalRequest::find($createResult->id);
        $this->assertSame('rejected', $request->status);
    }

    public function test_escalate_request(): void
    {
        $createResult = $this->service->createRequest('refund', 'PosOrder', 1, 100.00, 'Reason', null, $this->userA->id);

        $result = $this->service->escalate($createResult->id);

        $this->assertSame('escalated', $result->status);
        $this->assertNotNull($result->escalation_role);

        $request = ApprovalRequest::find($createResult->id);
        $this->assertSame(1, $request->escalation_level);
    }

    public function test_delegate_approval(): void
    {
        $this->service->delegate($this->userA->id, $this->userB->id);

        $delegation = ApprovalDelegation::where('from_user_id', $this->userA->id)->first();
        $this->assertNotNull($delegation);
        $this->assertSame($this->userB->id, $delegation->to_user_id);
    }

    public function test_remove_delegation(): void
    {
        $this->service->delegate($this->userA->id, $this->userB->id);
        $this->service->removeDelegation($this->userA->id);

        $delegation = ApprovalDelegation::where('from_user_id', $this->userA->id)->first();
        $this->assertNull($delegation);
    }

    public function test_expire_old_requests(): void
    {
        $createResult = $this->service->createRequest('refund', 'PosOrder', 1, 100.00, 'Reason', null, $this->userA->id);
        ApprovalRequest::where('id', $createResult->id)->update(['expires_at' => now()->subHour()]);

        $expired = $this->service->expireOld();
        $this->assertSame(1, $expired);

        $request = ApprovalRequest::find($createResult->id);
        $this->assertSame('expired', $request->status);
    }

    public function test_get_pending(): void
    {
        $this->service->createRequest('refund', 'PosOrder', 1, 100.00, 'Reason', null, $this->userA->id);
        $this->service->createRequest('void', 'PosOrder', 2, 50.00, 'Reason', null, $this->userA->id);

        $all = $this->service->getPending();
        $this->assertCount(2, $all);

        $refunds = $this->service->getPending('refund');
        $this->assertCount(1, $refunds);
    }

    public function test_check_policy_below_threshold(): void
    {
        ApprovalPolicy::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => tenant()?->id,
            'request_type' => 'refund',
            'threshold_amount' => 500.00,
            'required_approvers' => 1,
            'is_active' => true,
        ]);

        $result = $this->service->checkPolicy('refund', 100.00, $this->userA->id);

        $this->assertFalse($result['required']);
    }

    public function test_check_policy_above_threshold(): void
    {
        ApprovalPolicy::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => tenant()?->id,
            'request_type' => 'refund',
            'threshold_amount' => 500.00,
            'required_approvers' => 2,
            'is_active' => true,
        ]);

        $result = $this->service->checkPolicy('refund', 1000.00, $this->userA->id);

        $this->assertTrue($result['required']);
        $this->assertSame(2, $result['required_approvers']);
    }
}
