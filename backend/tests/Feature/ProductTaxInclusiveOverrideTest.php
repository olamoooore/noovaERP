<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxEngineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Per-product inclusive/exclusive pricing override:
 * products.tax_inclusive_override = null (inherit) | true (force inclusive)
 * | false (force exclusive) for rule-linked / default / legacy modes.
 * `tax_mode='custom'` keeps its own tax_included flag.
 */
class ProductTaxInclusiveOverrideTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rule(bool $inclusive): Tax
    {
        return Tax::create([
            'name' => 'Override rule '.Str::random(4), 'code' => 'OVR-'.Str::upper(Str::random(4)),
            'type' => 'gst', 'regime' => 'GST', 'jurisdiction' => 'SG',
            'scope' => 'both', 'treatment' => 'standard_rated', 'rate' => 9,
            'is_active' => true, 'is_inclusive' => $inclusive,
        ])->refresh();
    }

    private function product(?Tax $rule, ?bool $override, string $mode = 'tax'): Product
    {
        $product = Product::create([
            'name' => 'Override product', 'sku' => 'OVR-'.Str::upper(Str::random(6)),
            'is_active' => true, 'track_inventory' => false, 'tax_mode' => $mode,
            'tax_inclusive_override' => $override,
        ]);

        if ($rule) {
            $product->forceFill(['tax_id' => $rule->id])->save();
        }

        return $product->refresh();
    }

    public function test_override_forces_inclusive_on_an_exclusive_rule(): void
    {
        $rule = $this->rule(inclusive: false);
        $product = $this->product($rule, override: true);

        $resolved = app(TaxEngineService::class)->resolve($product);

        $this->assertSame(9.0, (float) $resolved['rate']);
        $this->assertTrue((bool) $resolved['is_inclusive'], 'The product override must beat the exclusive rule flag.');
    }

    public function test_override_forces_exclusive_on_an_inclusive_rule(): void
    {
        $rule = $this->rule(inclusive: true);
        $product = $this->product($rule, override: false);

        $resolved = app(TaxEngineService::class)->resolve($product);

        $this->assertFalse((bool) $resolved['is_inclusive'], 'The product override must beat the inclusive rule flag.');
    }

    public function test_null_override_inherits_the_rule_flag(): void
    {
        $rule = $this->rule(inclusive: true);
        $product = $this->product($rule, override: null);

        $this->assertTrue((bool) app(TaxEngineService::class)->resolve($product)['is_inclusive']);
    }

    public function test_custom_mode_keeps_its_own_tax_included_flag(): void
    {
        $product = Product::create([
            'name' => 'Custom product', 'sku' => 'OVR-'.Str::upper(Str::random(6)),
            'is_active' => true, 'track_inventory' => false,
            'tax_mode' => 'custom', 'tax_rate' => 9, 'tax_included' => false,
            'tax_inclusive_override' => true,
        ])->refresh();

        $resolved = app(TaxEngineService::class)->resolve($product);

        $this->assertFalse((bool) $resolved['is_inclusive'], 'Custom mode owns its tax_included flag.');
    }

    public function test_override_applies_on_the_default_tax_setting_path(): void
    {
        $rule = $this->rule(inclusive: false);
        Setting::setValue('tax', ['enabled' => true, 'default_tax_id' => $rule->id]);

        $product = $this->product(null, override: true);

        $resolved = app(TaxEngineService::class)->resolve($product);

        $this->assertTrue((bool) $resolved['is_inclusive'], 'Override must apply when tax comes from settings default.');
    }

    public function test_effective_tax_is_inclusive_attribute_honours_the_override(): void
    {
        $rule = $this->rule(inclusive: false);
        $product = $this->product($rule, override: true);

        $this->assertTrue($product->effective_tax_is_inclusive);

        $product->update(['tax_inclusive_override' => null]);
        $this->assertFalse($product->refresh()->effective_tax_is_inclusive);
    }

    public function test_api_persists_and_clears_the_override(): void
    {
        $this->withoutMiddleware();

        $create = $this->actingAs($this->user)->postJson('/api/v1/products', [
            'name' => 'API override product', 'sku' => 'API-OVR-'.Str::upper(Str::random(4)),
            'type' => 'product', 'cost' => 5, 'price' => 10,
            'track_inventory' => false, 'is_active' => true,
            'tax_mode' => 'default', 'tax_inclusive_override' => true,
        ]);

        $create->assertStatus(201);
        $id = (int) $create->json('id');
        $this->assertTrue((bool) Product::find($id)->tax_inclusive_override);

        $update = $this->actingAs($this->user)->putJson("/api/v1/products/{$id}", [
            'tax_inclusive_override' => null,
        ]);

        $update->assertStatus(200);
        $this->assertNull(Product::find($id)->tax_inclusive_override, 'Clearing must return the product to inherit.');
    }
}
