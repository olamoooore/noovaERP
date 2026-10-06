<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChatterActivity;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Platform\EventBus\DomainEventDispatcher;
use App\Platform\Notifications\NotificationLog;
use App\Platform\Notifications\NotificationPreference;
use App\Platform\SystemLog\SystemLog;
use App\Services\SalesApprovalGate;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Sales\Events\SalesEventCatalogue;
use Modules\Sales\Models\AutomationExecution;
use Modules\Sales\Models\AutomationRule;
use Modules\Sales\Models\AutomationRuleVersion;
use Modules\Sales\Models\SalesEventOutboxEvent;
use Modules\Sales\Subscriptions\SalesAutomationSubscription;
use Modules\Sales\Subscriptions\SalesEventSubscription;
use Modules\WhatsApp\Models\WhatsAppApiKey;
use Modules\WhatsApp\Services\WhatsAppService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #913 (SALES-DIFF) — no-code automation rules over the #901 sales
 * event catalogue.
 *
 * These tests run through the real HTTP routes: rule CRUD/dry-run/execution
 * log through /api/v1/sales/automation/*, the triggering event through the
 * real invoice posting route, and the engine through the real #901 outbox
 * publisher + DomainEventDispatcher path (the subscription registered in
 * setUp is the same registration the SalesServiceProvider performs on
 * tenancy initialization).
 *
 * Acceptance: rules are per entity, versioned, with a dry-run preview and an
 * execution log.
 */
class Issue913SalesAutomationRulesTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customerA;

    private Customer $customerB;

    private Product $product;

    private Warehouse $warehouse;

    private int $entityA;

    private int $entityB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Sales Automation Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => UserRole::ADMIN->value,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        $this->seedEntities();

        FiscalPeriod::firstOrCreate(
            ['code' => now()->format('Y-m')],
            [
                'name' => now()->format('F Y'),
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $this->admin->id,
            ]
        );

        $this->seedPostingAccounts();

        $this->customerA = Customer::factory()->create([
            'payment_terms' => 30,
            'credit_limit' => 100000,
            'legal_entity_id' => $this->entityA,
            'customer_group' => 'sme',
            'phone' => '+60123456789',
        ]);

        $this->customerB = Customer::factory()->create([
            'payment_terms' => 30,
            'credit_limit' => 100000,
            'legal_entity_id' => $this->entityB,
            'customer_group' => 'sme',
            'phone' => '+60123456780',
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Automation Warehouse',
            'code' => 'AW-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Automation Widget',
            'sku' => 'AW-'.uniqid(),
            'track_inventory' => true,
            'cost' => 10,
            'price' => 25,
            'stock_quantity' => 0,
        ]);

        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1000,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        // The Sales provider wires both subscriptions on tenancy init; the
        // test tenant is bound after app boot, so register them explicitly.
        app(DomainEventDispatcher::class)->subscribe(new SalesEventSubscription);
        app(DomainEventDispatcher::class)->subscribe(new SalesAutomationSubscription);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Setup helpers
    // ─────────────────────────────────────────────────────────────────────

    private function seedEntities(): void
    {
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Automation Group',
            'code' => 'AG-'.uniqid(),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity A',
            'code' => 'EA-'.uniqid(),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity B',
            'code' => 'EB-'.uniqid(),
            'country_code' => 'SG',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$this->entityA, $this->entityB] as $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $this->admin->id,
                'legal_entity_id' => $entityId,
                'role' => 'admin',
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')->andReturnUsing(fn (string $tenantId, string $key) => [
            'key' => $key,
            'is_entitled' => $isEntitled,
            'source' => $isEntitled ? 'tier_included' : null,
            'name' => strtoupper($key),
        ]);

        $this->app->instance(FeatureGateService::class, $mock);
    }

    private function seedPostingAccounts(): void
    {
        foreach ([
            'cash' => '1010',
            'bank' => '1020',
            'accounts_receivable' => '1100',
            'sales_tax_payable' => '2200',
            'sales_revenue' => '4100',
        ] as $mappingKey => $accountCode) {
            if (DB::table('account_mappings')->where('mapping_key', $mappingKey)->whereNull('document_type')->exists()) {
                continue;
            }

            DB::table('account_mappings')->insert([
                'mapping_key' => $mappingKey,
                'account_id' => DB::table('chart_of_accounts')->where('code', $accountCode)->value('id'),
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Business helpers
    // ─────────────────────────────────────────────────────────────────────

    private function entityHeaders(int $entityId): array
    {
        return ['X-Entity-Id' => (string) $entityId];
    }

    private function createRule(array $overrides = [], ?int $entityId = null): AutomationRule
    {
        $payload = array_merge([
            'legal_entity_id' => $entityId ?? $this->entityA,
            'name' => 'Rule '.uniqid(),
            'event_name' => SalesEventCatalogue::INVOICE_POSTED,
            'conditions' => [],
            'actions' => [['type' => 'create_task', 'summary' => 'Follow up :document_number']],
            'is_active' => true,
        ], $overrides);

        $response = $this->actingAs($this->admin)->postJson(
            '/api/v1/sales/automation/rules',
            $payload,
            $this->entityHeaders((int) $payload['legal_entity_id']),
        );
        $response->assertCreated();

        return AutomationRule::query()->findOrFail((int) $response->json('data.id'));
    }

    /**
     * Create + post an invoice through the real routes, emitting
     * invoice.posted.v1 and publishing it after commit.
     */
    private function postInvoice(float $quantity = 8, ?Customer $customer = null): Invoice
    {
        $customer ??= $this->customerA;

        $response = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => $quantity,
                'unit_price' => 25,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ], $this->entityHeaders((int) $customer->legal_entity_id));
        $response->assertCreated();

        $invoice = Invoice::query()->findOrFail((int) $response->json('id'));

        $this->actingAs($this->admin)->postJson(
            "/api/v1/invoices/{$invoice->id}/post",
            [],
            $this->entityHeaders((int) $customer->legal_entity_id),
        )->assertOk();

        return $invoice->fresh();
    }

    private function redeliverOutboxFor(Invoice $invoice): void
    {
        $outbox = SalesEventOutboxEvent::query()
            ->where('event_name', SalesEventCatalogue::INVOICE_POSTED)
            ->where('document_id', (string) $invoice->id)
            ->firstOrFail();

        $outbox->update(['published_at' => null, 'publish_attempts' => 0, 'dead_lettered_at' => null]);

        $this->artisan('sales:publish-outbox')->assertSuccessful();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Core: matching rule fires actions exactly once
    // ═════════════════════════════════════════════════════════════════════

    public function test_a_matching_rule_fires_its_actions_and_writes_one_execution_row(): void
    {
        $rule = $this->createRule([
            'conditions' => [['field' => 'total', 'operator' => 'gt', 'value' => 100]],
            'actions' => [
                ['type' => 'create_task', 'summary' => 'Collect :document_number', 'due_in_days' => 3],
                ['type' => 'hold_customer', 'reason' => '30 days overdue'],
                ['type' => 'notify', 'user_ids' => [$this->admin->id], 'message' => 'Invoice :document_number needs attention'],
            ],
        ]);

        $invoice = $this->postInvoice(8); // 8 × 25 = 200 > 100

        $execution = AutomationExecution::query()->where('rule_id', $rule->id)->sole();
        $this->assertSame(AutomationExecution::STATUS_SUCCESS, $execution->status);
        $this->assertSame((int) $rule->version, (int) $execution->rule_version);
        $this->assertSame(3, count($execution->actions));
        $this->assertSame(
            [(string) $invoice->id],
            [$this->invoiceDocumentIdFrom($execution)],
            'The execution is attached to the invoice that fired it.'
        );

        // create_task → a real chatter task on the invoice.
        $activity = ChatterActivity::query()->where('subject_type', Invoice::class)->sole();
        $this->assertSame('Collect '.$invoice->invoice_number, $activity->summary);
        $this->assertSame('todo', $activity->activity_type);

        // hold_customer → explicit flag + audit trail.
        $this->assertTrue($this->customerA->fresh()->on_hold);
        $this->assertSame('30 days overdue', $this->customerA->fresh()->hold_reason);
        $this->assertSame(1, SystemLog::query()->where('category', 'automation.customer_held')->count());

        // notify → platform notification log for the admin.
        $this->assertSame(1, NotificationLog::query()
            ->where('user_id', $this->admin->id)
            ->where('event', \Modules\Sales\Services\AutomationActionExecutor::NOTIFY_EVENT)
            ->count());

        // Redelivery of the SAME event id must not execute the actions twice.
        $this->redeliverOutboxFor($invoice);

        $this->assertSame(1, AutomationExecution::query()->where('rule_id', $rule->id)->count());
        $this->assertSame(1, ChatterActivity::query()->where('subject_type', Invoice::class)->count());
        $this->assertSame(1, NotificationLog::query()
            ->where('user_id', $this->admin->id)
            ->where('event', \Modules\Sales\Services\AutomationActionExecutor::NOTIFY_EVENT)
            ->count());
    }

    private function invoiceDocumentIdFrom(AutomationExecution $execution): string
    {
        return (string) $execution->document_id;
    }

    public function test_non_matching_conditions_skip_the_rule_without_side_effects(): void
    {
        $rule = $this->createRule([
            'conditions' => [['field' => 'total', 'operator' => 'gt', 'value' => 100000]],
            'actions' => [
                ['type' => 'create_task', 'summary' => 'Should not happen'],
                ['type' => 'hold_customer'],
            ],
        ]);

        $this->postInvoice(8);

        $execution = AutomationExecution::query()->where('rule_id', $rule->id)->sole();
        $this->assertSame(AutomationExecution::STATUS_SKIPPED, $execution->status);
        $this->assertSame(0, ChatterActivity::query()->where('subject_type', Invoice::class)->count());
        $this->assertFalse($this->customerA->fresh()->on_hold);
    }

    public function test_rules_are_scoped_to_their_legal_entity(): void
    {
        $rule = $this->createRule([], $this->entityA);

        // An event from entity B must not evaluate entity A's rule.
        $this->postInvoice(8, $this->customerB);
        $this->assertSame(0, AutomationExecution::query()->where('rule_id', $rule->id)->count());

        // The same rule fires for its own entity.
        $this->postInvoice(8, $this->customerA);
        $this->assertSame(1, AutomationExecution::query()->where('rule_id', $rule->id)->count());

        // The list endpoint is entity-scoped by X-Entity-Id.
        $entityBList = $this->actingAs($this->admin)->getJson(
            '/api/v1/sales/automation/rules',
            $this->entityHeaders($this->entityB),
        );
        $entityBList->assertOk();
        $this->assertSame([], $entityBList->json('data'));

        $entityAList = $this->actingAs($this->admin)->getJson(
            '/api/v1/sales/automation/rules',
            $this->entityHeaders($this->entityA),
        );
        $entityAList->assertOk();
        $this->assertSame([$rule->id], array_column($entityAList->json('data'), 'id'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Versioning
    // ═════════════════════════════════════════════════════════════════════

    public function test_editing_a_rule_bumps_its_version_and_records_history(): void
    {
        $rule = $this->createRule([
            'conditions' => [['field' => 'total', 'operator' => 'gt', 'value' => 100]],
            'actions' => [['type' => 'create_task', 'summary' => 'First version']],
        ]);

        $this->assertSame(1, (int) $rule->version);

        $response = $this->actingAs($this->admin)->putJson(
            "/api/v1/sales/automation/rules/{$rule->id}",
            [
                'name' => 'Edited rule',
                'event_name' => SalesEventCatalogue::INVOICE_OVERDUE,
                'conditions' => [['field' => 'days_overdue', 'operator' => 'gte', 'value' => 30]],
                'actions' => [['type' => 'hold_customer', 'reason' => 'Overdue']],
                'is_active' => true,
            ],
            $this->entityHeaders($this->entityA),
        );
        $response->assertOk();
        $this->assertSame(2, (int) $response->json('data.version'));

        $versions = $this->actingAs($this->admin)->getJson(
            "/api/v1/sales/automation/rules/{$rule->id}/versions",
            $this->entityHeaders($this->entityA),
        );
        $versions->assertOk();
        $this->assertCount(2, $versions->json('data'));
        $this->assertSame(2, (int) $versions->json('data.0.version'));
        $this->assertSame(1, (int) $versions->json('data.1.version'));

        // The v1 snapshot is immutable and still holds the original shape.
        $v1 = AutomationRuleVersion::query()->where('rule_id', $rule->id)->where('version', 1)->sole();
        $this->assertSame(SalesEventCatalogue::INVOICE_POSTED, $v1->event_name);
        $this->assertSame('First version', $v1->actions[0]['summary']);
        $this->assertSame('gt', $v1->conditions[0]['operator']);

        // The live row carries the new shape.
        $rule->refresh();
        $this->assertSame(SalesEventCatalogue::INVOICE_OVERDUE, $rule->event_name);
        $this->assertSame(2, AutomationRuleVersion::query()->where('rule_id', $rule->id)->count());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Dry-run
    // ═════════════════════════════════════════════════════════════════════

    public function test_dry_run_previews_conditions_and_actions_without_side_effects(): void
    {
        $rule = $this->createRule([
            'event_name' => SalesEventCatalogue::INVOICE_OVERDUE,
            'conditions' => [
                ['field' => 'days_overdue', 'operator' => 'gte', 'value' => 30],
                ['field' => 'total', 'operator' => 'gt', 'value' => 100],
            ],
            'actions' => [
                ['type' => 'hold_customer', 'reason' => 'Overdue'],
                ['type' => 'create_task', 'summary' => 'Call about :document_number'],
            ],
        ]);

        $response = $this->actingAs($this->admin)->postJson(
            "/api/v1/sales/automation/rules/{$rule->id}/dry-run",
            ['payload' => [
                'event_id' => (string) Str::uuid(),
                'event_name' => SalesEventCatalogue::INVOICE_OVERDUE,
                'document_type' => 'invoice',
                'document_id' => 4242,
                'document_number' => 'INV-4242',
                'customer_id' => $this->customerA->id,
                'legal_entity_id' => $this->entityA,
                'total' => 500,
                'balance' => 500,
                'due_date' => now()->subDays(45)->toDateString(),
                'occurred_at' => now()->toIso8601String(),
            ]],
            $this->entityHeaders($this->entityA),
        );

        $response->assertOk();
        $this->assertTrue($response->json('data.matched'));
        $this->assertFalse($response->json('data.side_effects'));
        $this->assertTrue($response->json('data.conditions.0.matched'));
        $this->assertSame(45, $response->json('data.conditions.0.actual'));
        $this->assertTrue($response->json('data.actions.0.would_execute'));

        // No side effects: no execution row, no task, no hold.
        $this->assertSame(0, AutomationExecution::query()->count());
        $this->assertSame(0, ChatterActivity::query()->count());
        $this->assertFalse($this->customerA->fresh()->on_hold);
    }

    public function test_dry_run_falls_back_to_the_latest_emitted_event_payload(): void
    {
        // Inactive rule so live evaluation is irrelevant to this test.
        $rule = $this->createRule([
            'name' => 'Latest payload preview',
            'conditions' => [['field' => 'total', 'operator' => 'gt', 'value' => 100]],
            'actions' => [['type' => 'create_task', 'summary' => 'Preview only']],
            'is_active' => false,
        ]);

        $invoice = $this->postInvoice(8);

        $response = $this->actingAs($this->admin)->postJson(
            "/api/v1/sales/automation/rules/{$rule->id}/dry-run",
            [],
            $this->entityHeaders($this->entityA),
        );

        $response->assertOk();
        $this->assertTrue($response->json('data.matched'));
        $this->assertSame((int) $invoice->id, (int) $response->json('data.event.document_id'));
        $this->assertSame(0, AutomationExecution::query()->count());
        $this->assertSame(0, ChatterActivity::query()->count());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Actions
    // ═════════════════════════════════════════════════════════════════════

    public function test_notify_action_respects_user_notification_preferences(): void
    {
        $rule = $this->createRule([
            'actions' => [['type' => 'notify', 'user_ids' => [$this->admin->id], 'message' => 'Heads up']],
        ]);

        NotificationPreference::updateOrCreate(
            [
                'user_id' => $this->admin->id,
                'event' => \Modules\Sales\Services\AutomationActionExecutor::NOTIFY_EVENT,
                'channel' => 'mail',
            ],
            ['mail_enabled' => false, 'tenant_id' => $this->tenantId],
        );

        $this->postInvoice(8);

        $execution = AutomationExecution::query()->where('rule_id', $rule->id)->sole();
        $this->assertSame(AutomationExecution::STATUS_SUCCESS, $execution->status);
        $this->assertSame(0, NotificationLog::query()
            ->where('user_id', $this->admin->id)
            ->where('event', \Modules\Sales\Services\AutomationActionExecutor::NOTIFY_EVENT)
            ->count(), 'An opted-out user must not be notified.');

        // Turning the preference back on delivers.
        NotificationPreference::query()
            ->where('user_id', $this->admin->id)
            ->where('event', \Modules\Sales\Services\AutomationActionExecutor::NOTIFY_EVENT)
            ->update(['mail_enabled' => true]);

        // The opt-out rule must not fire again in the second half.
        $rule->update(['is_active' => false]);

        $rule2 = $this->createRule([
            'name' => 'Notify again',
            'actions' => [['type' => 'notify', 'user_ids' => [$this->admin->id], 'message' => 'Heads up']],
        ]);

        $this->postInvoice(8);

        $this->assertSame(1, AutomationExecution::query()->where('rule_id', $rule2->id)->count());
        $this->assertSame(1, NotificationLog::query()
            ->where('user_id', $this->admin->id)
            ->where('event', \Modules\Sales\Services\AutomationActionExecutor::NOTIFY_EVENT)
            ->count());
    }

    public function test_whatsapp_action_requires_a_phone_and_records_visible_failure(): void
    {
        $customer = Customer::factory()->create([
            'legal_entity_id' => $this->entityA,
            'phone' => null,
            'mobile' => null,
            'preferred_contact' => 'whatsapp',
        ]);

        $rule = $this->createRule([
            'actions' => [['type' => 'whatsapp_customer', 'message' => 'Please settle :document_number']],
        ]);

        $this->postInvoice(8, $customer);

        $execution = AutomationExecution::query()->where('rule_id', $rule->id)->sole();
        $this->assertSame(AutomationExecution::STATUS_FAILED, $execution->status);
        $this->assertStringContainsString('no phone number', (string) $execution->actions[0]['error']);
    }

    public function test_whatsapp_action_sends_via_the_shared_service_and_respects_contact_preference(): void
    {
        WhatsAppApiKey::create([
            'name' => 'Automation key',
            'whatsapp_provider' => 'custom',
            'api_endpoint' => 'https://api.example.test',
            'is_active' => true,
        ]);

        $sent = [];
        $mock = \Mockery::mock(WhatsAppService::class);
        $mock->shouldReceive('sendMessage')->andReturnUsing(function ($key, $phone, $message) use (&$sent) {
            $sent[] = ['phone' => $phone, 'message' => $message];

            return ['success' => true, 'message_id' => 'msg-1'];
        });
        $this->app->instance(WhatsAppService::class, $mock);

        $rule = $this->createRule([
            'name' => 'WhatsApp reminder',
            'actions' => [['type' => 'whatsapp_customer', 'message' => 'Invoice :document_number is due']],
        ]);

        // Customer opting into WhatsApp gets the message.
        $whatsappCustomer = Customer::factory()->create([
            'legal_entity_id' => $this->entityA,
            'preferred_contact' => 'whatsapp',
            'phone' => '+60123456789',
        ]);

        $invoice = $this->postInvoice(8, $whatsappCustomer);

        $execution = AutomationExecution::query()->where('rule_id', $rule->id)->sole();
        $this->assertSame(AutomationExecution::STATUS_SUCCESS, $execution->status);
        $this->assertCount(1, $sent);
        $this->assertSame('60123456789', preg_replace('/[^0-9]/', '', $sent[0]['phone']));
        $this->assertStringContainsString($invoice->invoice_number, $sent[0]['message']);

        // A customer who explicitly prefers email is skipped, not messaged.
        $emailCustomer = Customer::factory()->create([
            'legal_entity_id' => $this->entityA,
            'preferred_contact' => 'email',
            'phone' => '+60111111111',
        ]);

        $this->postInvoice(8, $emailCustomer);

        $skips = AutomationExecution::query()
            ->where('rule_id', $rule->id)
            ->where('status', AutomationExecution::STATUS_SKIPPED)
            ->get();
        $this->assertCount(1, $skips);
        $this->assertSame('skipped', $skips->first()->actions[0]['status']);
        $this->assertCount(1, $sent, 'The email-preferring customer must not receive a WhatsApp.');

        // An explicit override sends anyway, and the override is recorded.
        $rule2 = $this->createRule([
            'name' => 'WhatsApp override',
            'actions' => [[
                'type' => 'whatsapp_customer',
                'message' => 'Invoice :document_number is due',
                'respect_preferences' => false,
            ]],
        ]);

        $overrideCustomer = Customer::factory()->create([
            'legal_entity_id' => $this->entityA,
            'preferred_contact' => 'email',
            'phone' => '+60122222222',
        ]);

        $this->postInvoice(8, $overrideCustomer);

        $this->assertSame(
            AutomationExecution::STATUS_SUCCESS,
            AutomationExecution::query()->where('rule_id', $rule2->id)->sole()->status,
        );
        $this->assertCount(2, $sent);
    }

    public function test_hold_customer_action_toggles_the_flag_and_audits_the_change(): void
    {
        $rule = $this->createRule([
            'actions' => [['type' => 'hold_customer', 'reason' => 'SERIOUS_DELINQUENCY']],
        ]);

        $this->postInvoice(8);

        $customer = $this->customerA->fresh();
        $this->assertTrue($customer->on_hold);
        $this->assertSame('SERIOUS_DELINQUENCY', $customer->hold_reason);
        $this->assertNotNull($customer->held_at);

        $log = SystemLog::query()->where('category', 'automation.customer_held')->sole();
        $this->assertSame((int) $rule->id, (int) $log->context['rule_id']);
        $this->assertSame((int) $customer->id, (int) $log->context['customer_id']);
    }

    public function test_held_customer_is_blocked_by_the_credit_check_on_new_orders(): void
    {
        $this->customerA->update([
            'on_hold' => true,
            'hold_reason' => 'Automation hold',
            'held_at' => now(),
        ]);

        $result = app(\Modules\Sales\Services\CreditCheckService::class)
            ->checkCreditLimit($this->customerA->fresh(), 50.0);

        $this->assertFalse($result->approved);
        $this->assertStringContainsString('on hold', $result->reason);
    }

    public function test_webhook_action_records_attempt_log_and_failure_with_error(): void
    {
        Http::fake(['*' => Http::response(['error' => 'downstream down'], 500)]);

        $rule = $this->createRule([
            'actions' => [['type' => 'webhook', 'url' => 'https://8.8.8.8/hooks/automation']],
        ]);

        $this->postInvoice(8);

        $execution = AutomationExecution::query()->where('rule_id', $rule->id)->sole();
        $this->assertSame(AutomationExecution::STATUS_FAILED, $execution->status);
        $this->assertStringContainsString('HTTP 500', (string) $execution->error);

        $action = $execution->actions[0];
        $this->assertSame('failed', $action['status']);
        $this->assertCount(3, $action['detail']['attempts'], 'Every webhook attempt is logged.');
        $this->assertSame(500, $action['detail']['attempts'][2]['status']);

        Http::assertSentCount(3);
    }

    public function test_webhook_action_succeeds_on_a_2xx_and_records_the_attempt(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $rule = $this->createRule([
            'actions' => [['type' => 'webhook', 'url' => 'https://8.8.8.8/hooks/automation']],
        ]);

        $this->postInvoice(8);

        $execution = AutomationExecution::query()->where('rule_id', $rule->id)->sole();
        $this->assertSame(AutomationExecution::STATUS_SUCCESS, $execution->status);
        $this->assertSame(200, $execution->actions[0]['detail']['attempts'][0]['status']);
        Http::assertSentCount(1);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['event']['event_id'], $body['rule']['id'], $body['execution']['id']);
        });
    }

    // ═════════════════════════════════════════════════════════════════════
    // Validation / permissions
    // ═════════════════════════════════════════════════════════════════════

    public function test_webhook_urls_are_blocked_from_internal_hosts_at_save_time(): void
    {
        $response = $this->actingAs($this->admin)->postJson(
            '/api/v1/sales/automation/rules',
            [
                'legal_entity_id' => $this->entityA,
                'name' => 'SSRF attempt',
                'event_name' => SalesEventCatalogue::INVOICE_POSTED,
                'conditions' => [],
                'actions' => [['type' => 'webhook', 'url' => 'http://127.0.0.1:8080/hook']],
            ],
            $this->entityHeaders($this->entityA),
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('actions.0.url');
        $this->assertSame(0, AutomationRule::query()->count());
    }

    public function test_unknown_event_condition_field_and_operator_are_rejected(): void
    {
        $response = $this->actingAs($this->admin)->postJson(
            '/api/v1/sales/automation/rules',
            [
                'legal_entity_id' => $this->entityA,
                'name' => 'Bad rule',
                'event_name' => 'not.a.real.event',
                'conditions' => [['field' => 'sql_injection', 'operator' => 'drop_table', 'value' => 'x']],
                'actions' => [['type' => 'create_task', 'summary' => 'x']],
            ],
            $this->entityHeaders($this->entityA),
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['event_name']);
    }

    public function test_unknown_condition_field_for_a_known_event_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)->postJson(
            '/api/v1/sales/automation/rules',
            [
                'legal_entity_id' => $this->entityA,
                'name' => 'Bad condition',
                'event_name' => SalesEventCatalogue::INVOICE_POSTED,
                'conditions' => [['field' => 'password_hash', 'operator' => 'eq', 'value' => 'x']],
                'actions' => [['type' => 'create_task', 'summary' => 'x']],
            ],
            $this->entityHeaders($this->entityA),
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('conditions.0.field');
    }

    public function test_automation_admin_routes_require_the_existing_sales_settings_permission(): void
    {
        $viewer = User::factory()->create();
        Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web']);
        $viewer->assignRole('Viewer');
        DB::table('user_company_access')->insert([
            'user_id' => $viewer->id,
            'tenant_id' => $this->tenantId,
            'role' => UserRole::VIEWER->value,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($viewer)->getJson('/api/v1/sales/automation/rules')->assertStatus(403);
        $this->actingAs($viewer)->postJson('/api/v1/sales/automation/rules', [])->assertStatus(403);
        $this->actingAs($viewer)->getJson('/api/v1/sales/automation/executions')->assertStatus(403);

        $this->actingAs($this->admin)->getJson('/api/v1/sales/automation/rules')->assertOk();
        $this->actingAs($this->admin)->getJson('/api/v1/sales/automation/catalog')->assertOk();
    }

    public function test_execution_log_lists_status_and_action_results(): void
    {
        $rule = $this->createRule([
            'actions' => [['type' => 'create_task', 'summary' => 'Logged task']],
        ]);

        $this->postInvoice(8);

        $response = $this->actingAs($this->admin)->getJson(
            '/api/v1/sales/automation/executions?rule_id='.$rule->id,
            $this->entityHeaders($this->entityA),
        );

        $response->assertOk();
        $this->assertSame(1, $response->json('total'));
        $this->assertSame(AutomationExecution::STATUS_SUCCESS, $response->json('data.0.status'));
        $this->assertSame('create_task', $response->json('data.0.actions.0.type'));
    }
}
