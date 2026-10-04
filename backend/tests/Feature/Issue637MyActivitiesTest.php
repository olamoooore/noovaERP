<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatterActivity;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #637 — cross-document "My Activities".
 *
 * Activities exist per document (type, due date, assignee) but there is no
 * cross-document list, so a follow-up is only found by opening each record.
 * This suite exercises the live router → middleware → controller chain for
 * GET /api/v1/activities/mine, POST /api/v1/activities/{id}/complete and
 * POST /api/v1/activities/{id}/snooze.
 *
 * Guiding rules asserted here:
 *  - only the rows assigned to the caller are ever returned or mutated;
 *  - only documents the caller may view (config/chatter.php view map) are
 *    included, so the list cannot leak a document the caller cannot open;
 *  - open activities are bucketed overdue / today / upcoming;
 *  - completing removes the row from the open buckets; snoozing re-dates it.
 */
class Issue637MyActivitiesTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            [
                'name' => 'Issue 637 Tenant',
                'email' => 'issue637-'.Str::random(6).'@example.com',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->admin = $this->userWithRole('admin', 'Admin');
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

    private function makeActivity(
        User $assignee,
        string $subjectType,
        int $subjectId,
        ?Carbon $due,
        string $summary = 'Follow up',
        string $status = 'planned',
    ): ChatterActivity {
        return ChatterActivity::create([
            'tenant_id' => $this->tenantId,
            'subject_type' => $subjectType,
            'subject_id' => (string) $subjectId,
            'assigned_to_id' => $assignee->id,
            'created_by_id' => $this->admin->id,
            'activity_type' => 'follow_up',
            'summary' => $summary,
            'due_date' => $due,
            'status' => $status,
        ]);
    }

    public function test_mine_groups_open_activities_by_due_bucket_and_excludes_others(): void
    {
        $sales = $this->userWithRole('sales', 'Sales');

        $overdue = $this->makeActivity($this->admin, Invoice::class, 1, now()->subDay(), 'Overdue call');
        $today = $this->makeActivity($this->admin, SalesOrder::class, 2, now()->startOfDay()->addHours(10), 'Today visit');
        $upcoming = $this->makeActivity($this->admin, Quotation::class, 3, now()->addDays(2), 'Upcoming quote');
        $unscheduled = $this->makeActivity($this->admin, DeliveryOrder::class, 4, null, 'No due date');
        $someoneElse = $this->makeActivity($sales, Invoice::class, 5, now()->subDay(), 'Not mine');
        $alreadyDone = $this->makeActivity($this->admin, Invoice::class, 6, now()->subDay(), 'Done already', 'done');

        $response = $this->actingAs($this->admin)->getJson('/api/v1/activities/mine');

        $response->assertOk();
        $response->assertJsonPath('data.overdue.0.id', $overdue->id);
        $response->assertJsonPath('data.today.0.id', $today->id);
        $response->assertJsonPath('data.upcoming.0.id', $upcoming->id);
        $response->assertJsonPath('data.upcoming.1.id', $unscheduled->id);
        $response->assertJsonPath('data.total', 4);

        $ids = collect($response->json('data.overdue'))
            ->pluck('id')
            ->merge(collect($response->json('data.today'))->pluck('id'))
            ->merge(collect($response->json('data.upcoming'))->pluck('id'))
            ->all();

        $this->assertNotContains($someoneElse->id, $ids, 'Another user\'s activity must not be listed.');
        $this->assertNotContains($alreadyDone->id, $ids, 'Completed activities are not open work.');
    }

    public function test_mine_returns_subject_link_metadata_and_document_number(): void
    {
        $invoice = Invoice::factory()->create(['invoice_number' => 'INV-637-0001']);

        $activity = $this->makeActivity($this->admin, Invoice::class, $invoice->id, now()->startOfDay()->addHours(9));

        $response = $this->actingAs($this->admin)->getJson('/api/v1/activities/mine');

        $response->assertOk();
        $response->assertJsonPath('data.today.0.id', $activity->id);
        $response->assertJsonPath('data.today.0.subject.type', Invoice::class);
        $response->assertJsonPath('data.today.0.subject.id', $invoice->id);
        $response->assertJsonPath('data.today.0.subject.label', 'INV-637-0001');
    }

    public function test_mine_filters_out_documents_the_caller_cannot_view(): void
    {
        $sales = $this->userWithRole('sales', 'Sales');

        $orderActivity = $this->makeActivity($sales, SalesOrder::class, 10, now()->addDay(), 'Order follow up');
        // The Sales role holds sales.view but not accounting.view, so a credit
        // note activity assigned to the same user must stay invisible.
        $creditNoteActivity = $this->makeActivity($sales, CustomerCreditNote::class, 11, now()->addDay(), 'Credit note follow up');

        $response = $this->actingAs($sales)->getJson('/api/v1/activities/mine');

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.overdue', []);
        $response->assertJsonPath('data.today', []);
        $this->assertSame(
            [$orderActivity->id],
            collect($response->json('data.upcoming'))->pluck('id')->all(),
            'A subject the caller cannot view must not leak through My Activities.'
        );
        $this->assertNotContains($creditNoteActivity->id, collect($response->json('data.upcoming'))->pluck('id')->all());
    }

    public function test_complete_marks_own_activity_done_through_route(): void
    {
        $activity = $this->makeActivity($this->admin, Invoice::class, 20, now()->subDay());

        $response = $this->actingAs($this->admin)->postJson("/api/v1/activities/{$activity->id}/complete");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'done');

        $this->assertDatabaseHas('chatter_activities', [
            'id' => $activity->id,
            'status' => 'done',
        ], 'pgsql');
    }

    public function test_complete_refuses_activity_assigned_to_another_user(): void
    {
        $sales = $this->userWithRole('sales', 'Sales');
        $activity = $this->makeActivity($sales, Invoice::class, 21, now()->subDay());

        $response = $this->actingAs($this->admin)->postJson("/api/v1/activities/{$activity->id}/complete");

        $response->assertNotFound();
        $this->assertDatabaseHas('chatter_activities', [
            'id' => $activity->id,
            'status' => 'planned',
        ], 'pgsql');
    }

    public function test_complete_refuses_document_the_caller_cannot_view(): void
    {
        $sales = $this->userWithRole('sales', 'Sales');
        $activity = $this->makeActivity($sales, CustomerCreditNote::class, 22, now()->subDay());

        $response = $this->actingAs($sales)->postJson("/api/v1/activities/{$activity->id}/complete");

        $response->assertForbidden();
        $this->assertDatabaseHas('chatter_activities', [
            'id' => $activity->id,
            'status' => 'planned',
        ], 'pgsql');
    }

    public function test_snooze_re_dates_own_activity_through_route(): void
    {
        $activity = $this->makeActivity($this->admin, Invoice::class, 23, now()->subDay());
        $newDue = now()->addDays(3)->setTime(9, 0);

        $response = $this->actingAs($this->admin)->postJson("/api/v1/activities/{$activity->id}/snooze", [
            'due_date' => $newDue->toIso8601String(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'planned');

        $fresh = $activity->fresh();
        $this->assertSame('planned', $fresh->status);
        $this->assertTrue($fresh->due_date->isSameDay($newDue), 'Snooze must move the due date.');
    }

    public function test_snooze_refuses_activity_assigned_to_another_user(): void
    {
        $sales = $this->userWithRole('sales', 'Sales');
        $activity = $this->makeActivity($sales, Invoice::class, 24, now()->subDay());

        $response = $this->actingAs($this->admin)->postJson("/api/v1/activities/{$activity->id}/snooze", [
            'due_date' => now()->addDay()->toIso8601String(),
        ]);

        $response->assertNotFound();
    }

    public function test_snooze_rejects_a_past_due_date(): void
    {
        $activity = $this->makeActivity($this->admin, Invoice::class, 25, now()->subDays(2));

        $response = $this->actingAs($this->admin)->postJson("/api/v1/activities/{$activity->id}/snooze", [
            'due_date' => now()->subDay()->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['due_date']);
    }

    public function test_mine_requires_authentication(): void
    {
        $this->getJson('/api/v1/activities/mine')->assertUnauthorized();
    }
}
