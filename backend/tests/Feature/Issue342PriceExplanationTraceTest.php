<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\PriceList;
use App\Models\PriceListApplication;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue #342 (SALES-DIFF D5): the per-line price explanation is built from
 * the stored `pricing_trace`. A quantity-break rule ("qty ≥ 10 tier") can
 * only be explained when the trace records the band the matched rule
 * applied to — and the stored trace must reach the document detail payload.
 */
class Issue342PriceExplanationTraceTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) \Illuminate\Support\Str::uuid();
        $this->bindTenantContextWithPermissions($this->tenantId, null, UserRole::ADMIN->spatieName(), autoAssignRole: false);
        \App\Models\Tenant::withoutEvents(fn () => \App\Models\Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Price Explanation Tenant',
            'email' => 'pe-'.substr(md5($this->tenantId), 0, 8).'@example.com',
            'plan' => 'standard',
        ]));
        $this->admin = $this->makeRoleUser(UserRole::ADMIN);
        CompanySettings::query()->create(['base_currency' => 'SGD']);

        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'SGD', 'rate' => 1.3500,
            'effective_date' => now()->subDays(2)->toDateString(), 'source' => 'api',
        ]);

        $this->customer = Customer::factory()->create(['name' => 'Tier Buyer Pte Ltd', 'payment_terms' => 30]);
    }

    private function makeRoleUser(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->spatieName());

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => (int) $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $role->value, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        return $user;
    }

    /** A USD list whose only rule is a qty-break: 10 % off from 10 units up to 99. */
    private function tierList(): PriceList
    {
        $list = PriceList::create([
            'name' => 'VIP USD',
            'code' => 'VIP-USD',
            'currency_code' => 'USD',
            'is_active' => true,
            'sequence' => 10,
        ]);
        PriceListApplication::create([
            'price_list_id' => $list->id,
            'applies_to_type' => 'customer',
            'applies_to_id' => $this->customer->id,
        ]);
        PriceListItem::create([
            'price_list_id' => $list->id,
            'sequence' => 10,
            'apply_on' => 'all',
            'compute_type' => 'percentage',
            'percent' => 10,
            'min_quantity' => 10,
            'max_quantity' => 99,
        ]);
        $this->customer->update(['price_list_id' => $list->id]);

        return $list;
    }

    public function test_resolved_trace_records_the_quantity_band_of_the_matched_rule(): void
    {
        $product = Product::factory()->create(['price' => 100.0, 'cost' => 40.0, 'track_inventory' => false]);
        $this->tierList();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/pricing/resolve-batch', [
            'customer_id' => $this->customer->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 12]],
        ]);

        $response->assertOk();
        $trace = $response->json('data.lines.0.pricing_trace');
        $this->assertSame('pricelist', $trace['source']);
        $this->assertSame('VIP-USD', $trace['pricelist_code']);
        $this->assertEquals(10, $trace['min_quantity'] ?? null, 'the qty ≥ 10 tier the rule applied to');
        $this->assertEquals(99, $trace['max_quantity'] ?? null);
    }

    public function test_quotation_detail_lines_carry_the_trace_with_band_and_fx_snapshot(): void
    {
        $product = Product::factory()->create(['price' => 100.0, 'cost' => 40.0, 'track_inventory' => false]);
        $this->tierList();

        $created = $this->actingAs($this->admin)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'lines' => [
                ['product_id' => $product->id, 'description' => 'Widget', 'quantity' => 12, 'price_mode' => 'auto', 'tax_rate' => 0],
            ],
        ]);
        $created->assertCreated();

        $shown = $this->actingAs($this->admin)->getJson('/api/v1/quotations/'.$created->json('data.id'));
        $shown->assertOk();
        $trace = $shown->json('lines.0.pricing_trace');
        $this->assertIsArray($trace, 'detail payload exposes the stored trace');
        $this->assertSame('VIP-USD', $trace['pricelist_code']);
        $this->assertEquals(10, $trace['min_quantity'] ?? null);
        $this->assertSame('USD', $trace['document_currency']);
        $this->assertSame('SGD', $trace['base_currency']);
        $this->assertSame(1.35, round((float) $trace['exchange_rate'], 2));
    }

    public function test_sales_document_defaults_expose_the_pricelist_display_mode_to_non_admins(): void
    {
        // #553 display mode, readable by the sales roles that open the
        // documents (GET /settings/sales itself is OWNER/ADMIN-only).
        \App\Models\Setting::setValue('sales.pricelist.display', 'net_price');

        $sales = $this->makeRoleUser(UserRole::SALES);
        $response = $this->actingAs($sales)->getJson('/api/v1/settings/sales/defaults');

        $response->assertOk();
        $this->assertSame('net_price', $response->json('data.pricelist.display'));
    }
}
