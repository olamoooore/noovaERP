<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Notifications\NotificationLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #410 [SALES-ODOO] — scheduled activities / follow-up reminders on
 * quotations and orders.
 *
 * Acceptance, through the real HTTP routes (router → middleware → controller)
 * and the scheduled command:
 *   • an activity (type, due date, assignee, note) is scheduled on a quotation
 *     and a sales order through the chatter endpoint, appears in the
 *     assignee's "My activities" list and can be marked done;
 *   • when an activity falls due, the reminder command notifies its assignee
 *     — once — through the platform NotificationService (in-app log + mail);
 *     a done activity, or one not yet due, is never reminded;
 *   • access follows the document: the assignee is only reminded about, and
 *     only lists, documents they may view (the document's own view
 *     permission) inside their legal entities;
 *   • the activities due feed the Sales cockpit action queue (#365).
 */
class Issue410SalesActivityRemindersTest extends TenantTestCase
{
    private const EVENT_DUE = 'chatter.activity.due';

    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private User $manager;

    private Customer $customerA;

    private Customer $customerB;

    private Quotation $quotationA;

    private SalesOrder $orderB;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->tenantId = (string) Str::uuid();
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => $this->tenantId]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        CompanySettings::query()->create(['base_currency' => 'USD']);

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue 410 Group',
            'code' => 'G410-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity A', 'code' => 'E410A-'.Str::upper(Str::random(4)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity B', 'code' => 'E410B-'.Str::upper(Str::random(4)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->manager = $this->makeUser('manager');

        $this->customerA = Customer::factory()->create(['name' => '410 Customer A', 'legal_entity_id' => $this->entityA]);
        $this->customerB = Customer::factory()->create(['name' => '410 Customer B', 'legal_entity_id' => $this->entityB]);

        $this->quotationA = Quotation::create([
            'quotation_number' => 'QT-410-A',
            'customer_id' => $this->customerA->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 100,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'created_by' => $this->manager->id,
        ]);

        $this->orderB = SalesOrder::create([
            'sales_order_number' => 'SO-410-B',
            'customer_id' => $this->customerB->id,
            'legal_entity_id' => $this->entityB,
            'channel' => 'admin',
            'status' => 'confirmed',
            'accounting_status' => 'not_posted',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 1000,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'order_date' => now()->toDateString(),
            'created_by' => $this->manager->id,
        ]);
    }

    // ── Fixtures / helpers ────────────────────────────────────────────────

    /** @param  int[]  $entityIds */
    private function makeUser(string $role, array $entityIds = []): User
    {
        $user = User::create([
            'name' => "Issue410 {$role}",
            'email' => "issue410-{$role}-".uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole(\App\Enums\UserRole::from($role)->spatieName());

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $role,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($entityIds as $index => $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id,
                'legal_entity_id' => $entityId,
                'role' => 'editor',
                'is_default' => $index === 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    /** Schedule through the real chatter endpoint as the manager. */
    private function schedule(string $subjectType, int $subjectId, User $assignee, string $dueDate, string $summary): int
    {
        $response = $this->actingAs($this->manager)->postJson(
            '/api/v1/chatter/'.rawurlencode($subjectType)."/{$subjectId}/activities",
            [
                'activity_type' => 'call',
                'summary' => $summary,
                'note' => 'Discuss the open points',
                'due_date' => $dueDate,
                'assigned_to_id' => $assignee->id,
            ],
        );
        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    /** @return int[] ids of every open activity in the caller's "My activities" */
    private function myActivityIds(User $user): array
    {
        $response = $this->actingAs($user)->getJson('/api/v1/activities/mine');
        $response->assertOk();

        return collect(['overdue', 'today', 'upcoming'])
            ->flatMap(fn ($bucket) => collect($response->json("data.{$bucket}"))->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    private function runReminders(): void
    {
        $this->artisan('sales:send-activity-reminders', ['--tenant' => $this->tenantId])->assertSuccessful();
    }

    private function dueReminders(User $user): \Illuminate\Support\Collection
    {
        return NotificationLog::query()
            ->where('user_id', (int) $user->id)
            ->where('event', self::EVENT_DUE)
            ->get();
    }

    // ── Tests ─────────────────────────────────────────────────────────────

    public function test_activity_scheduled_on_quotation_and_order_is_listed_for_the_assignee_and_can_be_done(): void
    {
        $rep = $this->makeUser('sales');

        $onQuote = $this->schedule(Quotation::class, $this->quotationA->id, $rep, now()->addDay()->toIso8601String(), 'Call about the quote');
        $onOrder = $this->schedule(SalesOrder::class, $this->orderB->id, $rep, now()->addDays(2)->toIso8601String(), 'Confirm delivery slot');

        $this->assertSame([$onQuote, $onOrder], $this->myActivityIds($rep));
        $this->assertSame([], $this->myActivityIds($this->manager), 'only the assignee lists the activity');

        $this->actingAs($rep)->postJson("/api/v1/activities/{$onQuote}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        $this->assertSame([$onOrder], $this->myActivityIds($rep));
    }

    public function test_reminder_command_notifies_the_assignee_once_when_the_activity_falls_due(): void
    {
        $rep = $this->makeUser('sales');

        $dueSoon = $this->schedule(Quotation::class, $this->quotationA->id, $rep, now()->addHour()->toIso8601String(), 'Call about the quote');
        $doneBeforeDue = $this->schedule(SalesOrder::class, $this->orderB->id, $rep, now()->addHour()->toIso8601String(), 'Already handled');
        $this->actingAs($rep)->postJson("/api/v1/activities/{$doneBeforeDue}/complete")->assertOk();

        // Not due yet: nothing is sent.
        $this->runReminders();
        $this->assertCount(0, $this->dueReminders($rep), 'an activity that is not yet due is not reminded');

        $this->travel(2)->hours();
        $this->runReminders();

        $reminders = $this->dueReminders($rep);
        $this->assertCount(1, $reminders, 'the due activity is reminded; the done one is not');
        $log = $reminders->first();
        $this->assertSame('sent', $log->status, 'the mail leg goes through NotificationService (Mail is faked)');
        $this->assertSame(Quotation::class, $log->data['document_type']);
        $this->assertSame((int) $this->quotationA->id, (int) $log->data['document_id']);
        $this->assertSame($dueSoon, (int) $log->data['activity_id']);
        $this->assertStringContainsString('QT-410-A', (string) $log->subject.' '.(string) $log->body);

        // A second run does not remind again.
        $this->runReminders();
        $this->assertCount(1, $this->dueReminders($rep), 'an activity is reminded only once');

        // The manager who scheduled it is not the assignee: no reminder.
        $this->assertCount(0, $this->dueReminders($this->manager));
    }

    public function test_snoozed_activity_is_reminded_again_at_its_new_due_date(): void
    {
        $rep = $this->makeUser('sales');
        $id = $this->schedule(Quotation::class, $this->quotationA->id, $rep, now()->addHour()->toIso8601String(), 'Call about the quote');

        $this->travel(2)->hours();
        $this->runReminders();
        $this->assertCount(1, $this->dueReminders($rep));

        $this->actingAs($rep)->postJson("/api/v1/activities/{$id}/snooze", [
            'due_date' => now()->addDay()->toIso8601String(),
        ])->assertOk();

        $this->runReminders();
        $this->assertCount(1, $this->dueReminders($rep), 'not reminded before the new due date');

        $this->travel(2)->days();
        $this->runReminders();
        $this->assertCount(2, $this->dueReminders($rep), 'reminded again once the snoozed date is reached');
    }

    public function test_reminders_and_my_activities_follow_the_documents_view_permission_and_legal_entity(): void
    {
        // Scoped to entity A: may not see the entity-B order.
        $repA = $this->makeUser('sales', [$this->entityA]);
        // Cashier: quotations.view but no sales.view — may not open orders.
        $cashier = $this->makeUser('cashier');

        $quoteForRepA = $this->schedule(Quotation::class, $this->quotationA->id, $repA, now()->addHour()->toIso8601String(), 'Quote follow-up');
        $orderForRepA = $this->schedule(SalesOrder::class, $this->orderB->id, $repA, now()->addHour()->toIso8601String(), 'Order in another entity');
        $orderForCashier = $this->schedule(SalesOrder::class, $this->orderB->id, $cashier, now()->addHour()->toIso8601String(), 'Order the cashier cannot open');

        $this->assertSame([$quoteForRepA], $this->myActivityIds($repA), 'an activity on a document outside the caller\'s legal entities is not listed');
        $this->assertSame([], $this->myActivityIds($cashier));
        $this->actingAs($repA)->postJson("/api/v1/activities/{$orderForRepA}/complete")->assertNotFound();

        $this->travel(2)->hours();
        $this->runReminders();

        $repAReminders = $this->dueReminders($repA);
        $this->assertCount(1, $repAReminders);
        $this->assertSame($quoteForRepA, (int) $repAReminders->first()->data['activity_id']);
        $this->assertCount(0, $this->dueReminders($cashier), 'no reminder about a document the assignee may not view');

        $this->assertDatabaseHas('chatter_activities', ['id' => $orderForCashier, 'status' => 'planned']);
    }

    public function test_activities_due_feed_the_sales_cockpit_action_queue(): void
    {
        $repA = $this->makeUser('sales', [$this->entityA]);

        $overdue = $this->schedule(Quotation::class, $this->quotationA->id, $repA, now()->subDay()->toIso8601String(), 'Overdue quote call');
        $dueToday = $this->schedule(Quotation::class, $this->quotationA->id, $repA, now()->toIso8601String(), 'Quote call today');
        $this->schedule(Quotation::class, $this->quotationA->id, $repA, now()->addDays(3)->toIso8601String(), 'Next week');
        $this->schedule(SalesOrder::class, $this->orderB->id, $repA, now()->subDay()->toIso8601String(), 'Other entity');

        $response = $this->actingAs($repA)->getJson('/api/v1/sales/dashboard/action-queues');
        $response->assertOk();

        $response->assertJsonPath('data.activities.count', 2);
        $response->assertJsonPath('data.activities.overdue', 1);
        $response->assertJsonPath('data.activities.link', '/activities');
        $items = collect($response->json('data.activities.items'));
        $this->assertSame([$overdue, $dueToday], $items->pluck('id')->map(fn ($id) => (int) $id)->all(), 'most overdue first');
        $this->assertSame('QT-410-A', $items->first()['number']);
        $this->assertSame("/sales/quotes/{$this->quotationA->id}", $items->first()['link']);
        $this->assertSame('410 Customer A', $items->first()['customer']);
    }
}
