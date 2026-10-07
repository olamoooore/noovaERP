<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerEmail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceActivityLog;
use App\Models\InvoiceDelivery;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationPreference;
use Modules\Communication\Models\CommunicationTemplate;
use Tests\TenantTestCase;

/**
 * Issue #328 (Odoo 20 parity): customer follow-up reminders.
 *
 * Acceptance criteria:
 *  - Follow-up levels (days overdue -> template, action), with manual send
 *    from the follow-up list (per customer) or the invoice detail page.
 *  - Scheduled automatic send; history logged to activity.
 *  - Respects the customer's communication preferences.
 *
 * Manual sends go through the real HTTP routes (router -> middleware ->
 * controller); the automatic part runs the real scheduled artisan command.
 */
class Issue328CustomerFollowupRemindersTest extends TenantTestCase
{
    private string $tenantUuid;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Issue 328 Follow-up Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = $this->userWithRole('admin');

        // The reminder email carries no PDF here so no renderer is involved;
        // PDF attachment is the existing #539 delivery behaviour.
        Setting::setValue('sales.delivery.attach_pdf', false);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        DB::table('public.user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantUuid,
            'role' => $role,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'legal_entity_id' => null,
            'email' => 'ar-'.Str::random(6).'@acme.example.test',
            'preferred_contact' => 'email',
        ], $overrides));
    }

    private function invoice(Customer $customer, int $daysOverdue, array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'invoice_number' => 'INV-328-'.Str::upper(Str::random(6)),
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'invoice_date' => now()->subDays($daysOverdue + 30)->toDateString(),
            'due_date' => now()->subDays($daysOverdue)->toDateString(),
            'total' => 500,
            'paid_amount' => 0,
            'balance' => 500,
            'payment_schedule' => null,
            'currency_code' => null,
            'exchange_rate' => 1,
        ], $overrides));
    }

    private function reminders(Invoice $invoice)
    {
        return InvoiceDelivery::query()
            ->where('invoice_id', $invoice->id)
            ->where('document_type', InvoiceDelivery::DOCUMENT_REMINDER)
            ->get();
    }

    private function enableAutomaticFollowups(array $levels): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/settings/sales', [
                'followup' => [
                    'auto_send_enabled' => true,
                    'levels' => $levels,
                ],
            ])
            ->assertOk();
    }

    // ── Manual send from the follow-up list (per customer) ──────────────

    public function test_manual_send_from_the_followup_list_emails_a_reminder_for_each_overdue_invoice_and_logs_it(): void
    {
        Mail::fake();

        $customer = $this->customer(['email' => 'billing@acme.example.test']);
        $overdue = $this->invoice($customer, 12);
        $notYetDue = $this->invoice($customer, -5);
        $paid = $this->invoice($customer, 40, ['balance' => 0, 'paid_amount' => 500, 'status' => 'paid']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customers/{$customer->id}/followup-reminders");

        $response->assertOk();
        $response->assertJsonPath('data.sent', 1);
        $response->assertJsonPath('data.results.0.invoice_id', $overdue->id);
        $response->assertJsonPath('data.results.0.outcome', 'sent');

        Mail::assertSent(CustomerEmail::class, 1);
        Mail::assertSent(CustomerEmail::class, fn (CustomerEmail $mail) => $mail->hasTo('billing@acme.example.test')
            && str_contains($mail->emailSubject, $overdue->invoice_number)
            && str_contains(strtolower($mail->emailSubject), 'overdue')
            && str_contains($mail->emailBody, '12 day'));

        $this->assertCount(1, $this->reminders($overdue));
        $this->assertSame(InvoiceDelivery::STATUS_SENT, $this->reminders($overdue)->first()->status);
        $this->assertCount(0, $this->reminders($notYetDue));
        $this->assertCount(0, $this->reminders($paid));

        // History: the send is on the invoice's activity log as a reminder.
        $this->assertTrue(InvoiceActivityLog::query()
            ->where('invoice_id', $overdue->id)
            ->where('event', InvoiceActivityLog::EVENT_SENT)
            ->get()
            ->contains(fn ($row) => ($row->metadata['document_type'] ?? null) === InvoiceDelivery::DOCUMENT_REMINDER));
    }

    public function test_manual_send_for_a_customer_with_nothing_overdue_is_refused(): void
    {
        Mail::fake();

        $customer = $this->customer();
        $this->invoice($customer, -10);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customers/{$customer->id}/followup-reminders")
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_manual_send_requires_the_invoices_send_permission(): void
    {
        Mail::fake();

        $cashier = $this->userWithRole('cashier');
        $customer = $this->customer();
        $invoice = $this->invoice($customer, 12);

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/customers/{$customer->id}/followup-reminders")
            ->assertForbidden();

        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/followup-reminder")
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    // ── Manual send from the invoice detail page ───────────────────────

    public function test_manual_send_from_the_invoice_detail_page_sends_one_reminder(): void
    {
        Mail::fake();

        $customer = $this->customer(['email' => 'detail@acme.example.test']);
        $invoice = $this->invoice($customer, 20);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/followup-reminder")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'sent');

        Mail::assertSent(CustomerEmail::class, fn (CustomerEmail $mail) => $mail->hasTo('detail@acme.example.test')
            && str_contains($mail->emailSubject, $invoice->invoice_number));
        $this->assertCount(1, $this->reminders($invoice));
    }

    public function test_an_invoice_that_is_not_overdue_gets_no_reminder(): void
    {
        Mail::fake();

        $invoice = $this->invoice($this->customer(), -3);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/followup-reminder")
            ->assertStatus(422);

        Mail::assertNothingSent();
        $this->assertCount(0, $this->reminders($invoice));
    }

    // ── Levels: days overdue -> template ────────────────────────────────

    public function test_the_reminder_uses_the_template_of_the_level_the_invoice_has_reached(): void
    {
        Mail::fake();

        CommunicationTemplate::create([
            'tenant_id' => $this->tenantUuid,
            'key' => 'invoice.followup.final_notice',
            'name' => 'Final notice',
            'module' => 'Sales',
            'subject' => 'FINAL NOTICE {{invoice.number}}',
            'body_plain' => 'Dear {{customer.name}}, invoice {{invoice.number}} is {{invoice.days_overdue}} days overdue.',
            'channels' => ['email'],
            'language' => 'en',
            'is_active' => true,
            'is_system' => false,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/settings/sales', ['followup' => ['levels' => [
                ['days' => 1, 'action' => 'send', 'template' => 'invoice.overdue'],
                ['days' => 30, 'action' => 'send', 'template' => 'invoice.followup.final_notice'],
            ]]])
            ->assertOk();

        $customer = $this->customer();
        $early = $this->invoice($customer, 5);
        $late = $this->invoice($customer, 45);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customers/{$customer->id}/followup-reminders")
            ->assertOk()
            ->assertJsonPath('data.sent', 2);

        Mail::assertSent(CustomerEmail::class, fn (CustomerEmail $mail) => $mail->emailSubject === "FINAL NOTICE {$late->invoice_number}"
            && str_contains($mail->emailBody, '45 days overdue'));
        Mail::assertSent(CustomerEmail::class, fn (CustomerEmail $mail) => str_contains($mail->emailSubject, $early->invoice_number)
            && ! str_starts_with($mail->emailSubject, 'FINAL NOTICE'));

        $this->assertSame(30, $this->reminders($late)->first()->metadata['followup_level'] ?? null);
        $this->assertSame(1, $this->reminders($early)->first()->metadata['followup_level'] ?? null);
    }

    // ── Communication preferences ──────────────────────────────────────

    public function test_a_customer_who_prefers_phone_is_not_emailed_and_the_skip_is_logged(): void
    {
        Mail::fake();

        $customer = $this->customer(['preferred_contact' => 'phone']);
        $invoice = $this->invoice($customer, 15);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customers/{$customer->id}/followup-reminders")
            ->assertOk()
            ->assertJsonPath('data.sent', 0)
            ->assertJsonPath('data.results.0.outcome', 'skipped');

        Mail::assertNothingSent();
        $this->assertCount(0, $this->reminders($invoice));
        $this->assertTrue(InvoiceActivityLog::query()
            ->where('invoice_id', $invoice->id)
            ->where('event', InvoiceActivityLog::EVENT_FOLLOWUP_SKIPPED)
            ->exists());
    }

    public function test_a_customer_who_turned_email_off_in_communication_preferences_is_not_emailed(): void
    {
        Mail::fake();

        $customer = $this->customer();
        $invoice = $this->invoice($customer, 15);
        CommunicationPreference::create([
            'tenant_id' => $this->tenantUuid,
            'customer_id' => $customer->id,
            'email_enabled' => false,
            'in_app_enabled' => true,
            'delivery_mode' => CommunicationPreference::MODE_INSTANT,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/followup-reminder")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'skipped');

        Mail::assertNothingSent();
        $this->assertCount(0, $this->reminders($invoice));
    }

    // ── Settings ───────────────────────────────────────────────────────

    public function test_followup_settings_round_trip_through_the_sales_settings_screen(): void
    {
        $this->enableAutomaticFollowups([
            ['days' => 30, 'action' => 'manual', 'template' => 'invoice.overdue'],
            ['days' => 7, 'action' => 'send', 'template' => 'invoice.overdue'],
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/settings/sales')->assertOk();

        $response->assertJsonPath('data.followup.auto_send_enabled', true);
        // Levels are kept in ascending days-overdue order.
        $response->assertJsonPath('data.followup.levels.0.days', 7);
        $response->assertJsonPath('data.followup.levels.1.days', 30);
        $response->assertJsonPath('data.followup.levels.1.action', 'manual');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/settings/sales', ['followup' => ['levels' => [['days' => 0, 'action' => 'fax']]]])
            ->assertStatus(422);
    }

    // ── Scheduled automatic send ───────────────────────────────────────

    public function test_the_scheduled_command_sends_each_level_once_and_logs_manual_actions(): void
    {
        Mail::fake();

        $this->enableAutomaticFollowups([
            ['days' => 7, 'action' => 'send', 'template' => 'invoice.overdue'],
            ['days' => 30, 'action' => 'manual', 'template' => 'invoice.overdue'],
        ]);

        $customer = $this->customer(['email' => 'auto@acme.example.test']);
        $level1 = $this->invoice($customer, 10);
        $belowFirstLevel = $this->invoice($customer, 3);
        $manualLevel = $this->invoice($customer, 40);
        $phoneCustomer = $this->customer(['preferred_contact' => 'phone']);
        $prefersPhone = $this->invoice($phoneCustomer, 10);

        $this->artisan('sales:send-followup-reminders', ['--tenant' => $this->tenantUuid])->assertSuccessful();

        Mail::assertSent(CustomerEmail::class, 1);
        Mail::assertSent(CustomerEmail::class, fn (CustomerEmail $mail) => $mail->hasTo('auto@acme.example.test')
            && str_contains($mail->emailSubject, $level1->invoice_number));

        $reminder = $this->reminders($level1)->first();
        $this->assertNotNull($reminder);
        $this->assertNull($reminder->sent_by, 'An automatic reminder has no user sender.');
        $this->assertSame(7, $reminder->metadata['followup_level'] ?? null);
        $this->assertSame('automatic', $reminder->metadata['followup_mode'] ?? null);

        $this->assertCount(0, $this->reminders($belowFirstLevel));
        $this->assertCount(0, $this->reminders($manualLevel));
        $this->assertCount(0, $this->reminders($prefersPhone));

        $this->assertTrue(InvoiceActivityLog::query()
            ->where('invoice_id', $manualLevel->id)
            ->where('event', InvoiceActivityLog::EVENT_FOLLOWUP_ACTION_DUE)
            ->where('actor_type', InvoiceActivityLog::ACTOR_SYSTEM)
            ->exists());
        $this->assertTrue(InvoiceActivityLog::query()
            ->where('invoice_id', $prefersPhone->id)
            ->where('event', InvoiceActivityLog::EVENT_FOLLOWUP_SKIPPED)
            ->exists());

        // A second daily run does not repeat a level already handled.
        $this->artisan('sales:send-followup-reminders', ['--tenant' => $this->tenantUuid])->assertSuccessful();

        Mail::assertSent(CustomerEmail::class, 1);
        $this->assertCount(1, $this->reminders($level1));
        $this->assertSame(1, InvoiceActivityLog::query()
            ->where('invoice_id', $manualLevel->id)
            ->where('event', InvoiceActivityLog::EVENT_FOLLOWUP_ACTION_DUE)
            ->count());
        $this->assertSame(1, InvoiceActivityLog::query()
            ->where('invoice_id', $prefersPhone->id)
            ->where('event', InvoiceActivityLog::EVENT_FOLLOWUP_SKIPPED)
            ->count());
    }

    public function test_the_scheduled_command_sends_nothing_while_automatic_followups_are_off(): void
    {
        Mail::fake();

        $invoice = $this->invoice($this->customer(), 20);

        $this->artisan('sales:send-followup-reminders', ['--tenant' => $this->tenantUuid])->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertCount(0, $this->reminders($invoice));
    }

    public function test_the_followup_command_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'sales:send-followup-reminders'));

        $this->assertCount(1, $events);
        $this->assertSame('0 8 * * *', $events->first()->expression);
    }
}
