<?php

namespace Modules\HRM\Tests\Feature;

use App\Models\User;
use Modules\HRM\Models\Contract;
use Modules\HRM\Models\Employee;
use Modules\HRM\Tests\HrmTestCase;

class ContractApiTest extends HrmTestCase
{

    protected User $user;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user     = User::factory()->create();
        $this->employee = Employee::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    }

    public function test_can_list_contracts(): void
    {
        Contract::factory()->count(2)->create(['employee_id' => $this->employee->id]);

        $response = $this->getJson('/api/v1/hrm/contracts');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    public function test_can_create_contract(): void
    {
        $payload = [
            'employee_id'         => $this->employee->id,
            'employment_type'     => 'full_time',
            'base_monthly_salary' => 5000,
            'start_date'          => '2026-01-01',
        ];

        $response = $this->postJson('/api/v1/hrm/contracts', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.employment_type', 'full_time');
        $response->assertJsonPath('data.status', 'draft');
    }

    public function test_create_contract_requires_employee_and_type(): void
    {
        $response = $this->postJson('/api/v1/hrm/contracts', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['employee_id', 'employment_type', 'base_monthly_salary']);
    }

    public function test_valid_status_transition_draft_to_onboarding(): void
    {
        $contract = Contract::factory()->create([
            'employee_id' => $this->employee->id,
            'status'      => 'draft',
        ]);

        $response = $this->putJson("/api/v1/hrm/contracts/{$contract->id}", [
            'status' => 'onboarding',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'onboarding');
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        $contract = Contract::factory()->create([
            'employee_id' => $this->employee->id,
            'status'      => 'draft',
        ]);

        // Cannot jump from draft directly to terminated
        $response = $this->putJson("/api/v1/hrm/contracts/{$contract->id}", [
            'status' => 'offboarding',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', "Invalid status transition: cannot move from 'draft' to 'offboarding'.");
    }

    public function test_terminated_contract_cannot_change_status(): void
    {
        $contract = Contract::factory()->create([
            'employee_id' => $this->employee->id,
            'status'      => 'terminated',
        ]);

        $response = $this->putJson("/api/v1/hrm/contracts/{$contract->id}", [
            'status' => 'active',
        ]);

        $response->assertStatus(422);
    }

    public function test_can_soft_delete_contract(): void
    {
        $contract = Contract::factory()->create(['employee_id' => $this->employee->id]);

        $response = $this->deleteJson("/api/v1/hrm/contracts/{$contract->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('hrm_contracts', ['id' => $contract->id]);
    }
}
