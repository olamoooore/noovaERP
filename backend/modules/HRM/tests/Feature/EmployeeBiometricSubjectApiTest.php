<?php

namespace Modules\HRM\Tests\Feature;

use App\Models\User;
use Modules\HRM\Models\Employee;
use Modules\HRM\Models\EmployeeBiometricSubject;
use Modules\HRM\Tests\HrmTestCase;

class EmployeeBiometricSubjectApiTest extends HrmTestCase
{
    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user     = User::factory()->create();
        $this->employee = Employee::factory()->create(['status' => 'active']);
        $this->actingAs($this->user, 'sanctum');
    }

    public function test_can_list_biometric_subjects_for_employee(): void
    {
        EmployeeBiometricSubject::create([
            'employee_id'  => $this->employee->id,
            'subject_code' => 'PV-001',
            'type'         => 'palm_vein',
            'status'       => 'active',
        ]);

        $response = $this->getJson("/api/v1/hrm/employees/{$this->employee->id}/biometric-subjects");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.type', 'palm_vein');
        $response->assertJsonPath('data.0.subject_code', 'PV-001');
    }

    public function test_can_create_biometric_subject(): void
    {
        $response = $this->postJson("/api/v1/hrm/employees/{$this->employee->id}/biometric-subjects", [
            'subject_code' => 'PV-XYZ-001',
            'type'         => 'palm_vein',
            'status'       => 'pending',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.subject_code', 'PV-XYZ-001');
        $response->assertJsonPath('data.type', 'palm_vein');
        $response->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('hrm_employee_biometric_subjects', [
            'employee_id'  => $this->employee->id,
            'subject_code' => 'PV-XYZ-001',
            'type'         => 'palm_vein',
        ]);
    }

    public function test_cannot_create_duplicate_type_for_same_employee(): void
    {
        // Create first palm_vein subject
        EmployeeBiometricSubject::create([
            'employee_id'  => $this->employee->id,
            'subject_code' => 'PV-001',
            'type'         => 'palm_vein',
            'status'       => 'active',
        ]);

        // Try to create another palm_vein for same employee
        $response = $this->postJson("/api/v1/hrm/employees/{$this->employee->id}/biometric-subjects", [
            'subject_code' => 'PV-002',
            'type'         => 'palm_vein',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);
    }

    public function test_different_modalities_are_allowed_for_same_employee(): void
    {
        EmployeeBiometricSubject::create([
            'employee_id'  => $this->employee->id,
            'subject_code' => 'PV-001',
            'type'         => 'palm_vein',
            'status'       => 'active',
        ]);

        // Adding a different modality (face) should succeed
        $response = $this->postJson("/api/v1/hrm/employees/{$this->employee->id}/biometric-subjects", [
            'subject_code' => 'FACE-001',
            'type'         => 'face',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'face');
    }

    public function test_can_deactivate_biometric_subject(): void
    {
        $subject = EmployeeBiometricSubject::create([
            'employee_id'  => $this->employee->id,
            'subject_code' => 'PV-001',
            'type'         => 'palm_vein',
            'status'       => 'active',
        ]);

        $response = $this->deleteJson(
            "/api/v1/hrm/employees/{$this->employee->id}/biometric-subjects/{$subject->id}"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Biometric subject deactivated.');

        // Soft-deleted: should not appear in normal queries
        $this->assertSoftDeleted('hrm_employee_biometric_subjects', ['id' => $subject->id]);
    }
}
