<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Feature;
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
use Tests\TenantTestCase;

/**
 * Issue #545 (SET-UX1): Sales Settings gets one section per document type.
 * Every setting the new Quotation and Sales Order sections show must be read
 * by a live code path (#539 ST2), so each one is driven here through the real
 * HTTP routes: PUT /settings/sales, then the document endpoint that reads it.
 *
 *   - quotation.default_terms      → POST /quotations fills blank terms
 *   - quotation.default_validity_days → GET /settings/sales/defaults (the
 *                                    quotation form's valid-until prefill)
 *   - sales_order.auto_lock_on_confirm → POST /sales/orders/{id}/confirm locks
 *   - sales_order.default_invoicing_policy → POST /sales/orders lines without
 *                                    an explicit policy take the default
 */
class Issue545SalesSettingsDocumentSectionsTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();
        $this->mockFeatureGate();

        $this->tenantId = (string) Str::uuid();
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue 545 Settings Tenant',
            'email' => 'issue545-'.uniqid().'@test.local',
            'plan' => 'standard',
        ]));
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);
        $this->product = Product::factory()->create([
            'name' => 'Issue 545 Widget',
            'sku' => 'I545-'.Str::upper(Str::random(6)),
            'price' => 10,
            'cost' => 4,
            'track_inventory' => false,
        ]);
        $this->warehouse = Warehouse::create([
            'name' => 'Issue 545 Warehouse',
            'code' => 'I545-'.uniqid(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin);
    }

    // ── Quotations ───────────────────────────────────────────────────────

    public function test_quotation_settings_round_trip(): void
    {
        $this->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->assertJsonPath('data.quotation.default_validity_days', 30)
            ->assertJsonPath('data.quotation.default_terms', '');

        $this->putJson('/api/v1/settings/sales', [
            'quotation' => ['default_validity_days' => 14, 'default_terms' => 'Quote valid for stock on hand.'],
        ])->assertOk();

        $this->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->assertJsonPath('data.quotation.default_validity_days', 14)
            ->assertJsonPath('data.quotation.default_terms', 'Quote valid for stock on hand.')
            // Saving the quotation section leaves its approval reviewers alone.
            ->assertJsonPath('data.quotation.reviewer_user_ids', []);
    }

    public function test_quotation_validity_days_reach_the_create_form_defaults(): void
    {
        $this->getJson('/api/v1/settings/sales/defaults')
            ->assertOk()
            ->assertJsonPath('data.quotation.default_validity_days', 30);

        $this->putJson('/api/v1/settings/sales', ['quotation' => ['default_validity_days' => 7]])->assertOk();

        $this->getJson('/api/v1/settings/sales/defaults')
            ->assertOk()
            ->assertJsonPath('data.quotation.default_validity_days', 7);

        // 0 = no default expiry date on new quotations.
        $this->putJson('/api/v1/settings/sales', ['quotation' => ['default_validity_days' => 0]])->assertOk();
        $this->getJson('/api/v1/settings/sales/defaults')
            ->assertOk()
            ->assertJsonPath('data.quotation.default_validity_days', 0);
    }

    public function test_quotation_validity_days_are_bounded(): void
    {
        $this->putJson('/api/v1/settings/sales', ['quotation' => ['default_validity_days' => 400]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quotation.default_validity_days');
    }

    public function test_new_quotation_without_terms_takes_the_default_quotation_terms(): void
    {
        $this->putJson('/api/v1/settings/sales', [
            'quotation' => ['default_terms' => 'Prices exclude installation.'],
        ])->assertOk();

        $this->getJson('/api/v1/settings/sales/defaults')
            ->assertOk()
            ->assertJsonPath('data.quotation.default_terms', 'Prices exclude installation.');

        $created = $this->storeQuotation()->assertCreated();
        $this->assertSame('Prices exclude installation.', DB::table('quotations')->where('id', $this->idOf($created))->value('terms'));

        // Terms the user typed win over the default.
        $explicit = $this->storeQuotation(['terms' => 'Special terms.'])->assertCreated();
        $this->assertSame('Special terms.', DB::table('quotations')->where('id', $this->idOf($explicit))->value('terms'));
    }

    public function test_quotation_terms_stay_blank_when_no_default_is_set(): void
    {
        $created = $this->storeQuotation()->assertCreated();

        $this->assertNull(DB::table('quotations')->where('id', $this->idOf($created))->value('terms'));
    }

    // ── Sales Orders ─────────────────────────────────────────────────────

    public function test_lock_confirmed_orders_setting_locks_on_confirm(): void
    {
        $this->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->assertJsonPath('data.sales_order.auto_lock_on_confirm', false);

        $unlocked = $this->createConfirmedOrder();
        $this->assertFalse($unlocked->fresh()->isLocked());

        $this->putJson('/api/v1/settings/sales', ['sales_order' => ['auto_lock_on_confirm' => true]])->assertOk();

        $this->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->assertJsonPath('data.sales_order.auto_lock_on_confirm', true);

        $locked = $this->createConfirmedOrder();
        $this->assertTrue($locked->fresh()->isLocked(), 'The settings toggle must lock orders on confirm.');
    }

    public function test_default_invoicing_policy_applies_to_new_order_lines_without_one(): void
    {
        $this->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->assertJsonPath('data.sales_order.default_invoicing_policy', 'ordered_qty');

        $before = $this->createDraftOrder();
        $this->assertSame('ordered_qty', $before->lines->first()->invoicing_policy);

        $this->putJson('/api/v1/settings/sales', ['sales_order' => ['default_invoicing_policy' => 'delivered_qty']])
            ->assertOk();

        $after = $this->createDraftOrder();
        $this->assertSame('delivered_qty', $after->lines->first()->invoicing_policy);

        // An explicit per-line policy still wins.
        $explicit = $this->createDraftOrder(['invoicing_policy' => 'ordered_qty']);
        $this->assertSame('ordered_qty', $explicit->lines->first()->invoicing_policy);
    }

    public function test_default_invoicing_policy_rejects_unknown_values(): void
    {
        $this->putJson('/api/v1/settings/sales', ['sales_order' => ['default_invoicing_policy' => 'shipped']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sales_order.default_invoicing_policy');
    }

    public function test_default_invoicing_policy_drives_what_the_order_invoices(): void
    {
        $this->putJson('/api/v1/settings/sales', ['sales_order' => ['default_invoicing_policy' => 'delivered_qty']])
            ->assertOk();

        $order = $this->createConfirmedOrder();

        // Nothing delivered yet: a delivered-quantity order has nothing to bill.
        $this->postJson("/api/v1/sales/orders/{$order->id}/invoice")->assertStatus(422);
        $this->assertSame(0, DB::table('invoices')->where('sales_order_id', $order->id)->count());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function storeQuotation(array $overrides = [])
    {
        return $this->postJson('/api/v1/quotations', array_merge([
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 0,
            ]],
        ], $overrides));
    }

    private function idOf($response): int
    {
        return (int) ($response->json('data.id') ?? $response->json('id'));
    }

    private function createDraftOrder(array $lineOverrides = []): SalesOrder
    {
        $response = $this->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [array_merge([
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 5,
                'unit_price' => 10,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ], $lineOverrides)],
        ]);
        $response->assertCreated();

        return SalesOrder::with('lines')->findOrFail((int) $response->json('id'));
    }

    private function createConfirmedOrder(): SalesOrder
    {
        $order = $this->createDraftOrder();
        $this->postJson("/api/v1/sales/orders/{$order->id}/confirm")->assertOk();

        return SalesOrder::with('lines')->findOrFail($order->id);
    }

    private function mockFeatureGate(): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(fn (string $tenantId, string $key) => [
                'key' => $key,
                'is_entitled' => true,
                'source' => 'tier_included',
                'name' => strtoupper($key),
            ]);
        $mock->shouldReceive('all')->andReturn([['key' => 'sales', 'source' => 'tier_included', 'name' => 'SALES']]);
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
            ['name' => 'Sales', 'description' => 'Sales module feature gate for tests', 'is_core' => false, 'price' => 0],
        );
    }
}
