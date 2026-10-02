<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\OnboardingStateMachine;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Regression + behaviour tests for the Accounting Onboarding "back" action.
 *
 * Covers ADC Section 6 (Onboarding Framework) and the specific regression:
 *   api/v1/accounting/onboarding/sessions/{id}/back  -> previously "could not be found".
 *
 * Positive paths:
 *  - Back from Review & Validate returns to Account Roles.
 *  - Step is reopened so the user can amend.
 *
 * Negative paths:
 *  - Back from the first step is blocked.
 *  - Back on a completed session is blocked.
 *  - Back on a non-existent session is blocked.
 *
 * Route wiring:
 *  - POST .../sessions/{id}/back returns 200 with session state.
 */
class OnboardingBackTest extends TenantTestCase
{
    private OnboardingStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext('test-tenant');
        $this->machine = app(OnboardingStateMachine::class);
    }

    /**
     * Advance a session N times, returning the resulting state.
     */
    private function advanceTo(int $sessionId, string $targetStep): array
    {
        $guard = 0;
        $state = $this->machine->getSession($sessionId);
        while (($state['session']->current_step ?? null) !== $targetStep) {
            if ($guard++ > 20) {
                throw new \RuntimeException("Did not reach step '{$targetStep}' within 20 advances.");
            }
            $state = $this->machine->advance($sessionId);
        }

        return $state;
    }

    public function test_back_from_review_and_validate_returns_to_account_roles(): void
    {
        $sessionId = (int) $this->machine->createSession('test-tenant', 'guided', '1')->id;

        // Walk forward to the Review & Validate step ('validation').
        $this->advanceTo($sessionId, 'validation');

        $this->assertSame('validation', $this->machine->getSession($sessionId)['session']->current_step);

        // ACT: go back.
        $state = $this->machine->back($sessionId);

        // ASSERT: current step is now Account Roles ('role_assignment').
        $this->assertSame('role_assignment', $state['session']->current_step);
        $this->assertSame('in_progress', $state['session']->status);
    }

    public function test_back_reopens_previous_step_so_it_can_be_amended(): void
    {
        $sessionId = (int) $this->machine->createSession('test-tenant', 'guided', '1')->id;
        $this->advanceTo($sessionId, 'validation');

        $this->machine->back($sessionId);

        $roleStep = DB::table('onboarding_step_states')
            ->where('session_id', $sessionId)
            ->where('step_code', 'role_assignment')
            ->first();

        $this->assertSame('in_progress', $roleStep->status);
        $this->assertSame('in_progress', DB::table('onboarding_sessions')->where('id', $sessionId)->value('status'));
    }

    public function test_back_can_be_followed_by_advance_again(): void
    {
        $sessionId = (int) $this->machine->createSession('test-tenant', 'guided', '1')->id;
        $this->advanceTo($sessionId, 'validation');

        $this->machine->back($sessionId);            // back to role_assignment
        $state = $this->machine->advance($sessionId); // re-advance to validation

        $this->assertSame('validation', $state['session']->current_step);
    }

    public function test_negative_back_from_first_step_is_blocked(): void
    {
        $sessionId = (int) $this->machine->createSession('test-tenant', 'guided', '1')->id;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/first step/i');

        $this->machine->back($sessionId);
    }

    public function test_negative_back_on_completed_session_is_blocked(): void
    {
        $sessionId = (int) $this->machine->createSession('test-tenant', 'guided', '1')->id;

        // Advance through every step until the session is completed.
        $guard = 0;
        do {
            $state = $this->machine->advance($sessionId);
        } while (($state['session']->status ?? '') === 'in_progress' && $guard++ < 20);

        $this->assertSame('completed', DB::table('onboarding_sessions')->where('id', $sessionId)->value('status'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot go back/i');

        $this->machine->back($sessionId);
    }

    public function test_negative_back_on_unknown_session_is_blocked(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $this->machine->back(999999);
    }

    /**
     * Reviewer negative path: wrong tenant cannot back another tenant's session.
     */
    public function test_negative_back_on_other_tenant_session_is_blocked(): void
    {
        // Create + advance a session as tenant-a (must match the bound context).
        $this->bindTenantContext('tenant-a');
        $sessionId = (int) $this->machine->createSession('tenant-a', 'guided', '1')->id;
        $this->advanceTo($sessionId, 'validation');

        // Switch the active tenant context to tenant-b and attempt back.
        $this->bindTenantContext('tenant-b');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $this->machine->back($sessionId);
    }

    /**
     * Reviewer negative path: repeated back action walks back one step at a time
     * and is blocked once the first step is reached.
     */
    public function test_repeated_back_walks_step_by_step_then_blocks_at_first(): void
    {
        $sessionId = (int) $this->machine->createSession('test-tenant', 'guided', '1')->id;
        $this->advanceTo($sessionId, 'validation');   // current = validation

        // 1st back: validation -> role_assignment
        $this->assertSame('role_assignment', $this->machine->back($sessionId)['session']->current_step);
        // 2nd back: role_assignment -> coa_import
        $this->assertSame('coa_import', $this->machine->back($sessionId)['session']->current_step);

        // #748 TU2 (test-semantic change, disclosed): the flow gained the
        // 'tax_registration' step between country_compliance and coa_import.
        $this->assertSame('tax_registration', $this->machine->back($sessionId)['session']->current_step);

        // Walk back to the first step (group_setup), then a further back must be blocked.
        $this->assertSame('country_compliance', $this->machine->back($sessionId)['session']->current_step);
        $this->assertSame('entity_selection', $this->machine->back($sessionId)['session']->current_step);
        $this->assertSame('group_setup', $this->machine->back($sessionId)['session']->current_step);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/first step/i');

        $this->machine->back($sessionId);
    }

    /**
     * Reviewer negative path: unauthenticated request to the back route is rejected.
     */
    public function test_negative_back_route_rejects_unauthenticated_request(): void
    {
        $sessionId = (int) $this->machine->createSession('test-tenant', 'guided', '1')->id;
        $this->advanceTo($sessionId, 'validation');

        // No actingAs / no token: auth:sanctum (first middleware) must reject.
        $response = $this->postJson('/api/v1/accounting/onboarding/sessions/'.$sessionId.'/back');

        $response->assertUnauthorized();
    }

    public function test_back_route_is_registered_and_returns_json_state(): void
    {
        $sessionId = (int) $this->machine->createSession('test-tenant', 'guided', '1')->id;
        $this->advanceTo($sessionId, 'validation');

        $user = User::factory()->create();

        // Bypass the heavy accounting/entity middleware to isolate route wiring;
        // auth is satisfied via actingAs(). Tenant scoping is exercised in the
        // state-machine tests above.
        $response = $this->actingAs($user, 'sanctum')
            ->withoutMiddleware()
            ->postJson('/api/v1/accounting/onboarding/sessions/'.$sessionId.'/back');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['session' => ['current_step', 'status']]]);
        $response->assertJsonPath('data.session.current_step', 'role_assignment');
    }
}
