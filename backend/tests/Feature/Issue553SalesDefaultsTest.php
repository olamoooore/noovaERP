<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #553 (SALES-ODOO): settings and user preferences set the default
 * salesperson and sales team on new sales documents, and the pricelist
 * display mode decides whether discounts are shown on the document lines or
 * baked into the displayed net price.
 *
 * These are router-level tests: settings are written through the real
 * /settings/sales API and applied by creating quotations and sales orders
 * through their real HTTP routes.
 */
class Issue553SalesDefaultsTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['name' => 'Default Seller']);
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
            'name' => 'Default Widget',
            'sku' => 'DFT-'.Str::upper(Str::random(6)),
            'price' => 10,
            'cost' => 4,
            'track_inventory' => false,
        ]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Warehouse::factory()->create(['is_default' => true]);
    }

    public function test_sales_defaults_and_pricelist_display_round_trip_through_the_settings_api(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->assertJsonPath('data.defaults.salesperson_mode', 'none')
            ->assertJsonPath('data.defaults.team_by_user', [])
            ->assertJsonPath('data.pricelist.display', 'show_discount');

        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/sales', [
                'defaults' => [
                    'salesperson_mode' => 'current_user',
                    'team_by_user' => [(string) $this->admin->id => 'Enterprise'],
                ],
                'pricelist' => ['display' => 'net_price'],
            ])
            ->assertOk();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->assertJsonPath('data.defaults.salesperson_mode', 'current_user')
            ->assertJsonPath('data.defaults.team_by_user.'.$this->admin->id, 'Enterprise')
            ->assertJsonPath('data.pricelist.display', 'net_price');

        $this->assertSame('current_user', Setting::getValue('sales.defaults.salesperson_mode'));
        $this->assertSame('Enterprise', Setting::getValue('sales.defaults.team_by_user')[(string) $this->admin->id]);
        $this->assertSame('net_price', Setting::getValue('sales.pricelist.display'));
    }

    public function test_sales_defaults_reject_unknown_values(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/sales', [
                'defaults' => ['salesperson_mode' => 'sales_rep'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('defaults.salesperson_mode');

        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/sales', [
                'pricelist' => ['display' => 'gross'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pricelist.display');
    }

    public function test_quotation_creation_gets_current_user_and_team_defaults(): void
    {
        $this->enableDefaults();

        $response = $this->storeQuotation();
        $response->assertCreated();

        $response
            ->assertJsonPath('data.salesperson', 'Default Seller')
            ->assertJsonPath('data.sales_team', 'Enterprise');

        $quotationId = (int) ($response->json('data.id') ?? $response->json('id'));
        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'salesperson' => 'Default Seller',
            'sales_team' => 'Enterprise',
        ]);
    }

    public function test_explicit_quotation_values_override_the_defaults(): void
    {
        $this->enableDefaults();

        $response = $this->storeQuotation([
            'salesperson' => 'Field Rep',
            'sales_team' => 'Direct',
        ]);
        $response->assertCreated();

        $quotationId = (int) ($response->json('data.id') ?? $response->json('id'));
        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'salesperson' => 'Field Rep',
            'sales_team' => 'Direct',
        ]);
    }

    public function test_quotation_creation_leaves_salesperson_blank_when_defaults_are_off(): void
    {
        $response = $this->storeQuotation();
        $response->assertCreated();

        $quotationId = (int) ($response->json('data.id') ?? $response->json('id'));
        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'salesperson' => null,
            'sales_team' => null,
        ]);
    }

    public function test_quotation_conversion_carries_its_salesperson_and_team_to_the_order(): void
    {
        $this->enableDefaults();

        $created = $this->storeQuotation(['salesperson' => 'Field Rep', 'sales_team' => 'Direct']);
        $created->assertCreated();
        $quotationId = (int) ($created->json('data.id') ?? $created->json('id'));

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotationId}/confirm")
            ->assertOk();

        $salesOrderId = DB::table('quotations')->where('id', $quotationId)->value('sales_order_id');
        $this->assertNotNull($salesOrderId, 'confirming the quotation creates the sales order');
        $this->assertDatabaseHas('sales_orders', [
            'id' => $salesOrderId,
            'salesperson' => 'Field Rep',
            'sales_team' => 'Direct',
        ]);
    }

    public function test_sales_order_creation_gets_current_user_and_team_defaults(): void
    {
        $this->enableDefaults();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 0,
            ]],
        ]);
        $response->assertCreated();

        $orderId = (int) ($response->json('id') ?? $response->json('data.id'));
        $this->assertDatabaseHas('sales_orders', [
            'id' => $orderId,
            'salesperson' => 'Default Seller',
            'sales_team' => 'Enterprise',
        ]);
    }

    private function enableDefaults(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/sales', [
                'defaults' => [
                    'salesperson_mode' => 'current_user',
                    'team_by_user' => [(string) $this->admin->id => 'Enterprise'],
                ],
            ])
            ->assertOk();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return \Illuminate\Testing\TestResponse
     */
    private function storeQuotation(array $overrides = [])
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/quotations', array_merge([
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
}
