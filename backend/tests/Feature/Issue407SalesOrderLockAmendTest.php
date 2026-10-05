<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #407 (SALES-ODOO): Odoo locks a confirmed sales order to prevent
 * changes, unlocks it to amend it, and an amended line produces additional
 * delivery / invoiceable quantity. NoovaERP previously accepted `PUT
 * /sales/orders/{id}` on drafts only — a confirmed order could only be
 * cancelled or re-created.
 *
 * These tests exercise the real HTTP routes (router → middleware →
 * controller):
 *   - draft header updates keep working;
 *   - a confirmed+locked order rejects line changes (403);
 *   - unlock then amend succeeds with an audited before/after diff, and the
 *     delivery / invoice remaining quantities follow the amended lines;
 *   - the `sales.orders.auto_lock_on_confirm` tenant setting (default off)
 *     locks the order as part of confirmation;
 *   - everything is gated by the existing seeded `sales.write` permission.
 */
class Issue407SalesOrderLockAmendTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private User $viewer;

    private Customer $customer;

    private Product $productA;

    private Product $productB;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();
        $this->mockFeatureGate(true);

        $this->tenantId = (string) Str::uuid();
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue 407 Lock Amend Tenant',
            'email' => 'issue407-'.uniqid().'@test.local',
            'plan' => 'standard',
        ]));
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.orders.auto_lock_on_confirm', false);

        $this->admin = $this->makeUser('admin', UserRole::ADMIN);
        $this->viewer = $this->makeUser('viewer', UserRole::VIEWER);

        $this->customer = Customer::factory()->create([
            'payment_terms' => 30,
            'credit_limit' => 100000,
        ]);

        $this->productA = Product::factory()->create([
            'name' => 'Issue 407 Widget A',
            'sku' => 'I407-A-'.uniqid(),
            'price' => 10,
            'cost' => 4,
            'track_inventory' => false,
        ]);
        $this->productB = Product::factory()->create([
            'name' => 'Issue 407 Widget B',
            'sku' => 'I407-B-'.uniqid(),
            'price' => 20,
            'cost' => 8,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Issue 407 Warehouse',
            'code' => 'I407-'.uniqid(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin);
    }

    private function makeUser(string $accessRole, UserRole $spatieRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole->spatieName());

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $accessRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->productA->id,
                'description' => $this->productA->name,
                'quantity' => 10,
                'unit_price' => 10,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ], $overrides);
    }

    private function createDraftOrder(array $overrides = []): int
    {
        $response = $this->postJson('/api/v1/sales/orders', $this->orderPayload($overrides));
        $response->assertCreated();

        return (int) $response->json('id');
    }

    private function createConfirmedOrder(array $overrides = []): SalesOrder
    {
        $orderId = $this->createDraftOrder($overrides);

        $this->postJson("/api/v1/sales/orders/{$orderId}/confirm")->assertOk();

        return SalesOrder::with('lines')->findOrFail($orderId);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Regression: drafts keep behaving exactly as before
    // ════════════════════════════════════════════════════════════════════════

    public function test_draft_header_update_still_works(): void
    {
        $orderId = $this->createDraftOrder();

        $update = $this->putJson("/api/v1/sales/orders/{$orderId}", [
            'notes' => 'Promised before month end',
        ]);

        $update->assertOk();
        $this->assertSame('Promised before month end', $update->json('notes'));
        $this->assertSame(
            'Promised before month end',
            (string) SalesOrder::findOrFail($orderId)->notes,
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Permission gate — the existing seeded sales.write string
    // ════════════════════════════════════════════════════════════════════════

    public function test_lock_unlock_and_amend_require_sales_write(): void
    {
        $order = $this->createConfirmedOrder();
        $line = $order->lines->first();

        $this->actingAs($this->viewer);

        // A viewer holds sales.view but never sales.write — every mutating
        // route of this issue must answer 403 from RequirePermission (which
        // also proves the route exists; a missing route would be 404).
        $this->postJson("/api/v1/sales/orders/{$order->id}/lock")
            ->assertStatus(403);
        $this->postJson("/api/v1/sales/orders/{$order->id}/unlock")
            ->assertStatus(403);
        $this->putJson("/api/v1/sales/orders/{$order->id}", [
            'lines' => [[
                'id' => $line->id,
                'quantity' => 99,
            ]],
        ])->assertStatus(403);

        // Nothing changed under the denied attempts.
        $line->refresh();
        $this->assertSame(10.0, (float) $line->quantity);
        $this->assertFalse($order->fresh()->isLocked());
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Lock / unlock
    // ════════════════════════════════════════════════════════════════════════

    public function test_confirmed_locked_order_rejects_line_changes_until_unlocked(): void
    {
        $order = $this->createConfirmedOrder();
        $line = $order->lines->first();

        // Drafts are not lockable — the lock protects CONFIRMED orders.
        $draftId = $this->createDraftOrder();
        $this->postJson("/api/v1/sales/orders/{$draftId}/lock")
            ->assertStatus(422);

        // Lock the confirmed order.
        $this->postJson("/api/v1/sales/orders/{$order->id}/lock")->assertOk();
        $this->getJson("/api/v1/sales/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('is_locked', true);

        // Both header and line changes are refused while locked.
        $this->putJson("/api/v1/sales/orders/{$order->id}", [
            'notes' => 'sneaky header edit',
        ])->assertStatus(403);

        $amend = $this->putJson("/api/v1/sales/orders/{$order->id}", [
            'lines' => [[
                'id' => $line->id,
                'description' => $line->description,
                'quantity' => 12,
                'unit_price' => 10,
            ]],
        ]);
        $amend->assertStatus(403);
        $this->assertStringContainsString('locked', strtolower((string) $amend->json('message')));

        $line->refresh();
        $this->assertSame(10.0, (float) $line->quantity, 'A locked order must not change.');
        $this->assertNull(
            SalesOrder::find($order->id)->notes,
            'A locked order must not accept header edits either.',
        );

        // Unlocking restores the amendment path.
        $this->postJson("/api/v1/sales/orders/{$order->id}/unlock")->assertOk();
        $this->getJson("/api/v1/sales/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('is_locked', false);

        $this->putJson("/api/v1/sales/orders/{$order->id}", [
            'lines' => [[
                'id' => $line->id,
                'description' => $line->description,
                'quantity' => 12,
                'unit_price' => 10,
            ]],
        ])->assertOk();

        $this->assertSame(12.0, (float) $line->fresh()->quantity);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Amendment: audited diff + delivery / invoice remainders follow
    // ════════════════════════════════════════════════════════════════════════

    public function test_amend_unlocked_confirmed_order_records_diff_and_updates_remainders(): void
    {
        $order = $this->createConfirmedOrder();
        $line = $order->lines->first();

        $response = $this->putJson("/api/v1/sales/orders/{$order->id}", [
            'lines' => [
                [
                    'id' => $line->id,
                    'description' => $this->productA->name.' (amended)',
                    'quantity' => 12,
                    'unit_price' => 10,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ],
                [
                    'product_id' => $this->productB->id,
                    'description' => $this->productB->name,
                    'quantity' => 5,
                    'unit_price' => 20,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ],
            ],
        ]);
        $response->assertOk();

        $line->refresh();
        $this->assertSame(12.0, (float) $line->quantity, 'Amendment must change the confirmed line.');
        $this->assertSame($this->productA->name.' (amended)', (string) $line->description);

        $newLine = SalesOrderLine::query()
            ->where('sales_order_id', $order->id)
            ->where('product_id', $this->productB->id)
            ->first();
        $this->assertNotNull($newLine, 'The amendment must add the new line.');
        $this->assertSame(5.0, (float) $newLine->quantity);

        // ── Audited before/after diff (#895 AuditLogger, keyed by line id) ──
        $log = AuditLog::query()
            ->where('reference_type', 'sales_order')
            ->where('reference_id', $order->id)
            ->where('operation', 'sales_order_updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'The amendment must write an explicit audit row.');

        $beforeLines = is_array($log->before_state) ? ($log->before_state['lines'] ?? []) : [];
        $afterLines = is_array($log->after_state) ? ($log->after_state['lines'] ?? []) : [];

        $this->assertSame(10.0, (float) ($beforeLines[$line->id]['quantity'] ?? 0));
        $this->assertSame(12.0, (float) ($afterLines[$line->id]['quantity'] ?? 0));
        $this->assertArrayNotHasKey(
            $newLine->id,
            $beforeLines,
            'The added line must be absent from the before snapshot.',
        );
        $this->assertArrayHasKey($newLine->id, $afterLines);
        $this->assertSame(5.0, (float) ($afterLines[$newLine->id]['quantity'] ?? 0));

        // ── Chatter entry on the document's History feed ────────────────────
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'chatter',
            'subject_id' => $order->id,
        ], config('database.default'));

        // ── Delivery remaining follows the amended lines ────────────────────
        // Pre-amendment the line remaining was 10; the amended remaining is
        // 12 (+5 on the new line), so this delivery is only possible because
        // the over-delivery guard now judges against the amended quantities.
        $delivery = $this->postJson('/api/v1/sales/delivery-orders', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['sales_order_line_id' => $line->id, 'quantity_delivered' => 12],
                ['sales_order_line_id' => $newLine->id, 'quantity_delivered' => 5],
            ],
        ]);
        $delivery->assertStatus(201);

        // ── Invoice remaining follows the amended lines ─────────────────────
        $invoice = $this->postJson("/api/v1/sales/orders/{$order->id}/invoice");
        $invoice->assertCreated();

        $invoicedByLine = InvoiceLine::query()
            ->where('invoice_id', $invoice->json('invoice.id'))
            ->get()
            ->groupBy('sales_order_line_id')
            ->map(fn ($lines) => (float) $lines->sum('quantity'));

        $this->assertSame(12.0, (float) ($invoicedByLine[$line->id] ?? 0), 'Amended quantity must be invoiceable.');
        $this->assertSame(5.0, (float) ($invoicedByLine[$newLine->id] ?? 0), 'Added line must be invoiceable.');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Tenant option: auto-lock on confirm (default off)
    // ════════════════════════════════════════════════════════════════════════

    public function test_auto_lock_on_confirm_setting_is_off_by_default_and_applies_when_enabled(): void
    {
        // Default (explicitly off): confirmation leaves the order amendable.
        $unlocked = $this->createConfirmedOrder();
        $this->assertFalse($unlocked->fresh()->isLocked());

        Setting::setValue('sales.orders.auto_lock_on_confirm', true);

        $locked = $this->createConfirmedOrder();
        $this->assertTrue($locked->fresh()->isLocked(), 'Auto-lock must apply on confirm when the tenant enables it.');

        $this->getJson("/api/v1/sales/orders/{$locked->id}")
            ->assertOk()
            ->assertJsonPath('is_locked', true);

        $autoLockLog = AuditLog::query()
            ->where('reference_type', 'sales_order')
            ->where('reference_id', $locked->id)
            ->where('operation', 'sales_order_locked')
            ->latest('id')
            ->first();

        $this->assertNotNull($autoLockLog, 'The auto-lock must be an audited action entry.');
        $this->assertTrue(
            (bool) (is_array($autoLockLog->metadata) ? ($autoLockLog->metadata['auto'] ?? false) : false),
            'The audit entry must record that the lock was applied automatically on confirm.',
        );

        // The auto-locked order refuses amendments until explicitly unlocked.
        $line = $locked->lines->first();
        $this->putJson("/api/v1/sales/orders/{$locked->id}", [
            'lines' => [['id' => $line->id, 'quantity' => 11]],
        ])->assertStatus(403);

        $this->postJson("/api/v1/sales/orders/{$locked->id}/unlock")->assertOk();
        $this->putJson("/api/v1/sales/orders/{$locked->id}", [
            'lines' => [['id' => $line->id, 'quantity' => 11]],
        ])->assertOk();
        $this->assertSame(11.0, (float) $line->fresh()->quantity);
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(fn (string $tenantId, string $key) => [
                'key' => $key,
                'is_entitled' => $isEntitled,
                'source' => $isEntitled ? 'tier_included' : null,
                'name' => strtoupper($key),
            ]);
        $mock->shouldReceive('all')
            ->andReturn($isEntitled ? [['key' => 'sales', 'source' => 'tier_included', 'name' => 'SALES']] : []);
        $mock->shouldReceive('invalidate')->andReturnNull();

        app()->instance(FeatureGateService::class, $mock);
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

        Feature::query()->updateOrCreate(
            ['key' => 'sales'],
            [
                'name' => 'Sales',
                'description' => 'Sales module feature gate for tests',
                'is_core' => false,
                'price' => 0,
            ]
        );
    }
}
