<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\SendScheduledReportViews;
use App\Models\LegalEntity;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\FinancialReporting\Mail\ScheduledSavedViewMail;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #598 (RPT-UX3) — saved report views and scheduled email delivery.
 *
 * Everything below exercises the real HTTP routes (router → middleware →
 * controller) and the real console command. The schedule command renders the
 * saved view's data through the analytics services (no HTTP), writes a CSV or
 * PDF, and emails each permitted recipient individually; recipients without
 * reports.view / entity scope are skipped and a skipped row is logged (never
 * silently dropped).
 */
class Issue598SavedReportViewsTest extends TenantTestCase
{
    private const BASE = '/api/v1/reports/saved-views';

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));

        $this->tenantId = (string) Str::uuid();

        Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => $this->tenantId,
            'name' => 'Issue 598 Tenant',
        ]));

        $this->bindTenantContextWithPermissions($this->tenantId);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            [
                'name' => $user->name,
                'password' => $user->password,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            [
                'role' => $role,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return $user;
    }

    private function createView(array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'My monthly sales by salesperson',
            'report_key' => 'sales-analytics',
            'tab' => 'pivot',
            'filters' => [
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-05',
                'salesperson_id' => 5,
            ],
            'grouping' => [
                'row_dims' => ['salesperson'],
                'col_dims' => ['month'],
                'measures' => ['net_revenue', 'paid_qty'],
            ],
            'visibility' => 'personal',
            'is_default' => true,
        ], $overrides);

        $response = $this->postJson(self::BASE, $payload);

        $response->assertStatus(201);

        return $response->json('data');
    }

    private function createSchedule(int $viewId, array $overrides = []): array
    {
        $payload = array_merge([
            'frequency' => 'daily',
            'time_of_day' => '08:00',
            'recipients' => [],
            'format' => 'csv',
            'is_active' => true,
        ], $overrides);

        $response = $this->postJson(self::BASE."/{$viewId}/schedules", $payload);

        $response->assertStatus(201);

        return $response->json('data');
    }

    private function makeSale(User $seller, string $customerName, float $total, ?int $entityId = null): void
    {
        $product = Product::factory()->create(['name' => 'Issue 598 Product', 'price' => 1, 'cost' => 0]);

        $customer = \App\Models\Customer::factory()->create([
            'name' => $customerName,
            'salesperson_id' => $seller->id,
            'legal_entity_id' => $entityId,
        ]);

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-598-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'legal_entity_id' => $entityId,
            'channel' => 'admin',
            'status' => 'confirmed',
            'accounting_status' => 'not_posted',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'order_date' => '2026-10-03',
            'created_by' => $seller->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'description' => 'Issue 598 line',
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);
    }

    private function runCommand(): void
    {
        $this->artisan('reports:send-scheduled-views', ['--tenant' => $this->tenantId])
            ->assertExitCode(0);
    }

    // ── Saved views CRUD ──────────────────────────────────────────────────

    public function test_saved_view_round_trip_keeps_tab_filters_and_grouping(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);

        $created = $this->createView(['visibility' => 'shared', 'is_default' => true]);

        $this->assertSame('pivot', $created['tab']);
        $this->assertSame('shared', $created['visibility']);
        $this->assertTrue($created['is_default']);
        $this->assertEquals(['row_dims' => ['salesperson'], 'col_dims' => ['month'], 'measures' => ['net_revenue', 'paid_qty']], $created['grouping']);

        $this->assertDatabaseHas('report_saved_views', [
            'id' => $created['id'],
            'user_id' => $admin->id,
            'name' => 'My monthly sales by salesperson',
            'tab' => 'pivot',
            'visibility' => 'shared',
        ]);

        $list = $this->getJson(self::BASE)->assertStatus(200);
        $views = $list->json('data.views');

        $this->assertCount(1, $views);
        $this->assertSame($created['id'], $views[0]['id']);
        // jsonb does not preserve key order; compare by value, not order.
        $this->assertEquals([
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-05',
            'salesperson_id' => 5,
        ], $views[0]['filters']);
        $this->assertSame($created['id'], $list->json('data.default_view_id'));
        $this->assertTrue($views[0]['is_owner']);
    }

    public function test_personal_views_are_private_and_shared_views_are_visible(): void
    {
        $admin = $this->makeUser('admin');
        $viewer = $this->makeUser('viewer');

        $this->actingAs($admin);
        $this->createView(['name' => 'Private View', 'visibility' => 'personal']);
        $this->createView(['name' => 'Team View', 'visibility' => 'shared']);

        $this->actingAs($viewer);
        $list = $this->getJson(self::BASE)->assertStatus(200);
        $names = array_column($list->json('data.views'), 'name');

        $this->assertContains('Team View', $names);
        $this->assertNotContains('Private View', $names);

        $shared = collect($list->json('data.views'))->firstWhere('name', 'Team View');
        $this->assertFalse($shared['is_owner']);
    }

    public function test_saved_views_require_reports_view_permission(): void
    {
        $cashier = $this->makeUser('cashier');
        $this->actingAs($cashier);

        $this->getJson(self::BASE)->assertStatus(403);
    }

    public function test_non_owner_cannot_update_or_delete_a_shared_view_but_owner_can(): void
    {
        $admin = $this->makeUser('admin');
        $viewer = $this->makeUser('viewer');

        $this->actingAs($admin);
        $view = $this->createView(['visibility' => 'shared']);

        $this->actingAs($viewer);
        $this->putJson(self::BASE."/{$view['id']}", ['name' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson(self::BASE."/{$view['id']}")->assertStatus(403);
        $this->postJson(self::BASE."/{$view['id']}/default")->assertStatus(403);

        $this->assertDatabaseHas('report_saved_views', ['id' => $view['id'], 'name' => 'My monthly sales by salesperson']);

        $this->actingAs($admin);
        $this->deleteJson(self::BASE."/{$view['id']}")->assertStatus(200);
        $this->assertDatabaseMissing('report_saved_views', ['id' => $view['id']]);
    }

    public function test_setting_a_default_view_replaces_the_previous_default(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);

        $first = $this->createView(['name' => 'First', 'is_default' => true]);
        $second = $this->createView(['name' => 'Second', 'is_default' => true]);

        $list = $this->getJson(self::BASE)->assertStatus(200);
        $this->assertSame($second['id'], $list->json('data.default_view_id'));

        $this->assertDatabaseHas('report_saved_views', ['id' => $first['id'], 'is_default' => false]);
        $this->assertDatabaseHas('report_saved_views', ['id' => $second['id'], 'is_default' => true]);
    }

    public function test_recipients_endpoint_lists_only_active_tenant_users(): void
    {
        $admin = $this->makeUser('admin');
        $active = $this->makeUser('viewer', ['name' => 'Active Viewer']);
        $inactive = $this->makeUser('viewer', ['name' => 'Inactive Viewer', 'is_active' => false]);

        $this->actingAs($admin);
        $response = $this->getJson(self::BASE.'/recipients')->assertStatus(200);

        $emails = array_column($response->json('data'), 'email');
        $this->assertContains($active->email, $emails);
        $this->assertNotContains($inactive->email, $emails);
    }

    // ── Schedules ─────────────────────────────────────────────────────────

    public function test_schedule_crud_round_trip(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);

        $view = $this->createView();
        $schedule = $this->createSchedule($view['id'], [
            'frequency' => 'weekly',
            'day_of_week' => 1,
            'time_of_day' => '07:30',
            'recipients' => [$admin->id],
            'format' => 'csv',
        ]);

        $this->assertSame('weekly', $schedule['frequency']);
        $this->assertSame(1, $schedule['day_of_week']);
        $this->assertSame([$admin->id], $schedule['recipients']);
        $this->assertNotNull($schedule['next_run_at']);

        $list = $this->getJson(self::BASE."/{$view['id']}/schedules")->assertStatus(200);
        $this->assertCount(1, $list->json('data'));

        $this->putJson(self::BASE."/{$view['id']}/schedules/{$schedule['id']}", [
            'is_active' => false,
            'frequency' => 'monthly',
            'day_of_month' => 15,
        ])->assertStatus(200);

        $this->assertDatabaseHas('report_view_schedules', [
            'id' => $schedule['id'],
            'frequency' => 'monthly',
            'is_active' => false,
        ]);

        $this->deleteJson(self::BASE."/{$view['id']}/schedules/{$schedule['id']}")->assertStatus(200);
        $this->assertDatabaseMissing('report_view_schedules', ['id' => $schedule['id']]);
    }

    public function test_non_owner_cannot_create_a_schedule_for_a_shared_view(): void
    {
        $admin = $this->makeUser('admin');
        $viewer = $this->makeUser('viewer');

        $this->actingAs($admin);
        $view = $this->createView(['visibility' => 'shared']);

        $this->actingAs($viewer);
        $this->postJson(self::BASE."/{$view['id']}/schedules", [
            'frequency' => 'daily',
            'recipients' => [$viewer->id],
            'format' => 'csv',
        ])->assertStatus(403);
    }

    // ── Scheduled command ────────────────────────────────────────────────

    public function test_scheduled_command_sends_only_to_permitted_recipients_and_logs_skipped_ones(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin', ['name' => 'Permitted Admin']);
        $cashier = $this->makeUser('cashier', ['name' => 'No Reports Cashier']);
        $scoped = $this->makeUser('viewer', ['name' => 'Entity Scoped Viewer']);

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue 598 Group',
            'code' => 'I598-'.Str::upper(Str::random(4)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entityA = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Entity A',
            'code' => 'A598-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ]);
        $entityB = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Entity B',
            'code' => 'B598-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ]);

        DB::table('user_entity_access')->insert([
            'user_id' => $scoped->id,
            'legal_entity_id' => $entityB->id,
            'role' => 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->makeSale($admin, 'Permitted Customer', 1234.50, $entityA->id);

        $this->actingAs($admin);
        $view = $this->createView([
            'tab' => 'by-customer',
            'filters' => [
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-05',
                'legal_entity_id' => $entityA->id,
            ],
            'grouping' => null,
        ]);
        $schedule = $this->createSchedule($view['id'], [
            'recipients' => [$admin->id, $cashier->id, $scoped->id],
            'format' => 'csv',
        ]);

        DB::table('report_view_schedules')
            ->where('id', $schedule['id'])
            ->update(['next_run_at' => now()->subMinute()]);

        $this->runCommand();

        Mail::assertSent(ScheduledSavedViewMail::class, 1);
        Mail::assertSent(ScheduledSavedViewMail::class, fn (ScheduledSavedViewMail $mail) => $mail->hasTo($admin->email));
        Mail::assertNotSent(ScheduledSavedViewMail::class, fn (ScheduledSavedViewMail $mail) => $mail->hasTo($cashier->email));
        Mail::assertNotSent(ScheduledSavedViewMail::class, fn (ScheduledSavedViewMail $mail) => $mail->hasTo($scoped->email));

        $this->assertDatabaseHas('report_view_schedule_logs', [
            'schedule_id' => $schedule['id'],
            'user_id' => $admin->id,
            'status' => 'sent',
        ]);

        $skippedCashier = DB::table('report_view_schedule_logs')
            ->where('schedule_id', $schedule['id'])
            ->where('user_id', $cashier->id)
            ->first();
        $this->assertNotNull($skippedCashier, 'The unauthorized recipient must have a visible skipped log row.');
        $this->assertSame('skipped', $skippedCashier->status);
        $this->assertStringContainsString('reports.view', (string) $skippedCashier->reason);

        $skippedScoped = DB::table('report_view_schedule_logs')
            ->where('schedule_id', $schedule['id'])
            ->where('user_id', $scoped->id)
            ->first();
        $this->assertNotNull($skippedScoped);
        $this->assertSame('skipped', $skippedScoped->status);
        $this->assertStringContainsString('legal entity', strtolower((string) $skippedScoped->reason));

        // The CSV really was rendered for the saved filters and carried data.
        $files = glob(storage_path('app/reports/'.$this->tenantId).'/*.csv') ?: [];
        $this->assertNotEmpty($files, 'A CSV file must be generated for the scheduled run.');
        $this->assertStringContainsString('Permitted Customer', file_get_contents($files[0]));

        $run = DB::table('report_view_schedules')->where('id', $schedule['id'])->first();
        $this->assertNotNull($run->last_run_at);
        $this->assertSame(1, (int) $run->run_count);
        $this->assertTrue(Carbon::parse($run->next_run_at)->greaterThan(now()));
    }

    public function test_scheduled_command_can_send_a_pdf(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $this->makeSale($admin, 'Pdf Customer', 900);

        $this->actingAs($admin);
        $view = $this->createView([
            'tab' => 'by-customer',
            'filters' => ['start_date' => '2026-10-01', 'end_date' => '2026-10-05'],
            'grouping' => null,
        ]);
        $schedule = $this->createSchedule($view['id'], [
            'recipients' => [$admin->id],
            'format' => 'pdf',
        ]);

        DB::table('report_view_schedules')
            ->where('id', $schedule['id'])
            ->update(['next_run_at' => now()->subMinute()]);

        $this->runCommand();

        Mail::assertSent(ScheduledSavedViewMail::class, 1);

        $files = glob(storage_path('app/reports/'.$this->tenantId).'/*.pdf') ?: [];
        $this->assertNotEmpty($files, 'A PDF file must be generated for a pdf-format schedule.');

        $this->assertDatabaseHas('report_view_schedule_logs', [
            'schedule_id' => $schedule['id'],
            'user_id' => $admin->id,
            'status' => 'sent',
            'format' => 'pdf',
        ]);
    }

    public function test_daily_schedule_runs_once_per_day_and_not_before_it_is_due(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');

        $this->actingAs($admin);
        $view = $this->createView(['tab' => 'by-customer', 'grouping' => null]);
        $schedule = $this->createSchedule($view['id'], ['recipients' => [$admin->id]]);

        // Not due yet: tomorrow's run must not fire today.
        DB::table('report_view_schedules')
            ->where('id', $schedule['id'])
            ->update(['next_run_at' => now()->addDay()]);

        $this->runCommand();
        Mail::assertNothingSent();

        // Due now: one send, and the schedule rolls forward.
        DB::table('report_view_schedules')
            ->where('id', $schedule['id'])
            ->update(['next_run_at' => now()->subMinute()]);

        $this->runCommand();
        Mail::assertSentCount(1);

        $afterFirst = DB::table('report_view_schedules')->where('id', $schedule['id'])->first();
        $this->assertTrue(Carbon::parse($afterFirst->next_run_at)->greaterThan(now()));

        // A second run the same day must not send again.
        $this->runCommand();
        Mail::assertSentCount(1);

        // The next day, once due, it sends again.
        Carbon::setTestNow(Carbon::parse('2026-10-06 09:00:00'));
        DB::table('report_view_schedules')
            ->where('id', $schedule['id'])
            ->update(['next_run_at' => now()->subMinute()]);

        $this->runCommand();
        Mail::assertSentCount(2);
    }

    public function test_scheduled_view_command_is_registered_on_the_scheduler(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'reports:send-scheduled-views'));

        $this->assertNotNull($event, 'reports:send-scheduled-views must be registered on the scheduler.');
        $this->assertTrue($event->withoutOverlapping, 'The scheduled-view sender must not overlap.');
    }

    public function test_command_class_uses_iterates_tenants(): void
    {
        $this->assertContains(
            \App\Console\Concerns\IteratesTenants::class,
            class_uses_recursive(SendScheduledReportViews::class),
        );
    }
}
