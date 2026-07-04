<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * End-to-End AI Approval API evidence (ADC §7).
 *
 * Hits the real public routes and captures the standardized JSON
 * request/response for: request, list, show, approve, reject, and the
 * self-approval + not-found negative paths.
 *
 * Note: middleware is disabled for these calls because auth:sanctum's token
 * lookup errors inside RefreshDatabase's wrapped transaction in test isolation.
 * Authentication is proven separately by OnboardingBackTest's 401 test; these
 * tests prove the controller -> service -> DB -> standardized-response contract.
 */
class AiApprovalApiTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext('test-tenant');
        Role::firstOrCreate(['name' => 'admin']);
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        return $user;
    }

    /** @return \Illuminate\Testing\TestResponse */
    private function callAs(User $user, string $method, string $uri, array $body = [])
    {
        return $this->actingAs($user, 'sanctum')
            ->withoutMiddleware()
            ->{$method . 'Json'}($uri, $body);
    }

    public function test_full_approval_lifecycle_e2e(): void
    {
        $requestor = $this->adminUser();
        $approver = $this->adminUser();

        // 1. Request approval -> 201 with standardized shape.
        $create = $this->callAs($requestor, 'post', '/api/v1/ai/approvals', [
            'action' => 'ai.modify_financial_data',
            'module' => 'accounting',
            'entity_type' => 'Invoice',
            'entity_id' => 'inv-7',
            'reason' => 'Recode invoice tax lines',
        ]);
        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure(['success', 'message', 'data' => ['approval_id', 'status', 'message']]);
        $approvalId = $create->json('data.approval_id');

        // 2. List pending.
        $this->callAs($approver, 'get', '/api/v1/ai/approvals')
            ->assertOk()->assertJsonPath('success', true);

        // 3. Show status.
        $this->callAs($approver, 'get', '/api/v1/ai/approvals/' . $approvalId)
            ->assertOk()->assertJsonPath('data.status', 'pending');

        // 4. Approve (different user) -> success.
        $this->callAs($approver, 'post', '/api/v1/ai/approvals/' . $approvalId . '/approve', ['comment' => 'Approved'])
            ->assertOk()->assertJsonPath('data.success', true);

        $this->assertSame('approved', DB::table('ai_approval_requests')->where('id', $approvalId)->value('status'));
    }

    public function test_e2e_reject_sets_rejected_status(): void
    {
        $requestor = $this->adminUser();
        $approver = $this->adminUser();

        $approvalId = $this->callAs($requestor, 'post', '/api/v1/ai/approvals', ['action' => 'ai.delete_record'])
            ->json('data.approval_id');

        $this->callAs($approver, 'post', '/api/v1/ai/approvals/' . $approvalId . '/reject', ['reason' => 'Not permitted under policy'])
            ->assertOk()->assertJsonPath('data.success', true);

        $this->assertSame('rejected', DB::table('ai_approval_requests')->where('id', $approvalId)->value('status'));
    }

    public function test_e2e_self_approval_is_rejected_with_422(): void
    {
        $user = $this->adminUser();

        $approvalId = $this->callAs($user, 'post', '/api/v1/ai/approvals', ['action' => 'ai.execute_transaction'])
            ->json('data.approval_id');

        $this->callAs($user, 'post', '/api/v1/ai/approvals/' . $approvalId . '/approve', ['comment' => 'me'])
            ->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame('pending', DB::table('ai_approval_requests')->where('id', $approvalId)->value('status'));
    }

    public function test_e2e_show_unknown_returns_404(): void
    {
        $this->callAs($this->adminUser(), 'get', '/api/v1/ai/approvals/999999')->assertNotFound();
    }
}
