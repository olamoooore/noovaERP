<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductPriceTier;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #245 — ProductPriceTier.customer_group was ignored: a group-scoped
 * tier passed the whereNull('customer_id') branch for every customer/guest.
 *
 * Issue #244 — the web checkout now prices lines through the same resolver.
 */
class HR245PriceTierCustomerGroupTest extends TenantTestCase
{
    private Product $product;

    private Customer $wholesale;

    private Customer $retail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContext((string) Str::uuid());

        // Product creation audits through TenantUserHelper — authenticate first.
        $this->actingAs(\App\Models\User::factory()->create());

        $this->product = Product::factory()->create([
            'name' => 'HR245 Widget',
            'sku' => 'HR245-'.Str::upper(Str::random(6)),
            'price' => 100.00,
            'cost' => 50.00,
            'track_inventory' => false,
        ]);

        $this->wholesale = Customer::factory()->create([
            'name' => 'Wholesale Buyer',
            'customer_group' => 'wholesale',
        ]);
        $this->retail = Customer::factory()->create([
            'name' => 'Retail Buyer',
            'customer_group' => 'retail',
        ]);

        // Generic tier: 5% for anyone.
        ProductPriceTier::create([
            'product_id' => $this->product->id,
            'customer_id' => null,
            'customer_group' => null,
            'min_quantity' => 1,
            'discount_type' => 'percentage',
            'discount_value' => 5,
            'is_active' => true,
        ]);

        // Group-scoped tier: 25% for wholesale only.
        ProductPriceTier::create([
            'product_id' => $this->product->id,
            'customer_id' => null,
            'customer_group' => 'wholesale',
            'min_quantity' => 1,
            'discount_type' => 'percentage',
            'discount_value' => 25,
            'is_active' => true,
        ]);
    }

    public function test_group_scoped_tier_applies_only_to_group_members(): void
    {
        $this->assertSame(75.00, $this->product->getPriceForQuantity(1, $this->wholesale->id),
            'Wholesale customer must receive the 25% group tier');
    }

    public function test_non_member_customer_gets_only_the_generic_tier(): void
    {
        $this->assertSame(95.00, $this->product->getPriceForQuantity(1, $this->retail->id),
            'Retail customer must not receive the wholesale group tier');
    }

    public function test_guest_never_receives_a_group_scoped_tier(): void
    {
        $this->assertSame(95.00, $this->product->getPriceForQuantity(1, null),
            'Guest (no customer/group) must fall back to the generic tier');
    }

    public function test_explicit_group_parameter_is_honoured(): void
    {
        $this->assertSame(75.00, $this->product->getPriceForQuantity(1, null, null, null, 'wholesale'),
            'An explicitly supplied group must match the group tier');
    }

    public function test_customer_specific_tier_beats_group_and_generic(): void
    {
        ProductPriceTier::create([
            'product_id' => $this->product->id,
            'customer_id' => $this->retail->id,
            'customer_group' => null,
            'min_quantity' => 1,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'is_active' => true,
        ]);

        $this->assertSame(90.00, $this->product->getPriceForQuantity(1, $this->retail->id),
            'Customer-specific 10% must win over the generic 5% (and never see wholesale 25%)');
    }
}
