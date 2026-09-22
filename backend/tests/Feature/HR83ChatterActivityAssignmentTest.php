<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #83 — chatter activity assignment accepted any user id from anyone.
 * Now: the assignee must be an active user, assigning to someone else requires
 * a supervisory role (owner/admin/manager), and the assignment is audited.
 */
class HR83ChatterActivityAssignmentTest extends TenantTestCase
{
    private string $tenantId;

    private Customer $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR83 Tenant', 'email' => 'hr83-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );

        $this->subject = Customer::factory()->create(['name' => 'HR83 Customer']);
    }

    private function userWithRole(string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole);

        return $user;
    }

    private function activityPayload(int $assignedToId): array
    {
        return [
            'activity_type' => 'call',
            'summary' => 'Follow up',
            'assigned_to_id' => $assignedToId,
        ];
    }

    public function test_non_supervisory_role_cannot_assign_to_another_user(): void
    {
        $sales = $this->userWithRole('sales', 'Sales');
        $other = $this->userWithRole('sales', 'Sales');

        $response = $this->actingAs($sales)->postJson(
            '/api/v1/chatter/'.urlencode(Customer::class)."/{$this->subject->id}/activities",
            $this->activityPayload($other->id)
        );

        $this->assertSame(403, $response->getStatusCode(),
            'Sales must not be able to assign activities to other users');
    }

    public function test_any_role_can_self_assign(): void
    {
        $sales = $this->userWithRole('sales', 'Sales');

        $response = $this->actingAs($sales)->postJson(
            '/api/v1/chatter/'.urlencode(Customer::class)."/{$this->subject->id}/activities",
            $this->activityPayload($sales->id)
        );

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
    }

    public function test_supervising_role_can_assign_and_the_action_is_audited(): void
    {
        $manager = $this->userWithRole('manager', 'Manager');
        $sales = $this->userWithRole('sales', 'Sales');

        $response = $this->actingAs($manager)->postJson(
            '/api/v1/chatter/'.urlencode(Customer::class)."/{$this->subject->id}/activities",
            $this->activityPayload($sales->id)
        );

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $this->assertDatabaseHas('audit_logs', [
            'operation' => 'activity_assigned',
            'reference_type' => 'ChatterActivity',
        ], 'pgsql');
    }

}
