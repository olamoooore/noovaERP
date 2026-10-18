<?php

namespace Modules\HRM\Tests\Feature;

use App\Models\User;
use Modules\HRM\Models\Employee;
use Modules\HRM\Models\EmployeeUserLink;
use Modules\HRM\Tests\HrmTestCase;

class EmployeeUserLinkApiTest extends HrmTestCase
{
    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->employee = Employee::factory()->create(['status' => 'active']);
        $this->actingAs($this->user, 'sanctum');
    }

    public function test_can_list_user_links_for_employee(): void
    {
        $linkedUser = User::factory()->create();
        EmployeeUserLink::create([
            'employee_id' => $this->employee->id,
            'user_id' => $linkedUser->id,
            'type' => 'primary',
        ]);

        $response = $this->getJson("/api/v1/hrm/employees/{$this->employee->id}/user-links");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.user_id', $linkedUser->id);
        $response->assertJsonPath('data.0.type', 'primary');
    }

    public function test_can_attach_user_to_employee(): void
    {
        $linkedUser = User::factory()->create();

        $response = $this->postJson("/api/v1/hrm/employees/{$this->employee->id}/user-links", [
            'user_id' => $linkedUser->id,
            'type' => 'primary',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.user_id', $linkedUser->id);
        $response->assertJsonPath('data.type', 'primary');
        $this->assertDatabaseHas('hrm_employee_user_links', [
            'employee_id' => $this->employee->id,
            'user_id' => $linkedUser->id,
        ]);
    }

    public function test_cannot_attach_same_user_twice(): void
    {
        $linkedUser = User::factory()->create();

        EmployeeUserLink::create([
            'employee_id' => $this->employee->id,
            'user_id' => $linkedUser->id,
            'type' => 'primary',
        ]);

        $response = $this->postJson("/api/v1/hrm/employees/{$this->employee->id}/user-links", [
            'user_id' => $linkedUser->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'This user is already linked to this employee.');
    }

    public function test_can_detach_user_from_employee(): void
    {
        $linkedUser = User::factory()->create();
        $link = EmployeeUserLink::create([
            'employee_id' => $this->employee->id,
            'user_id' => $linkedUser->id,
            'type' => 'primary',
        ]);

        $response = $this->deleteJson("/api/v1/hrm/employees/{$this->employee->id}/user-links/{$link->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('hrm_employee_user_links', ['id' => $link->id]);
    }
}
