<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\ChartOfAccount;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAddress;
use App\Services\TagService;
use Tests\TenantTestCase;

/**
 * The vendor-bill LIST search must be as wide as the invoice list: typing a
 * vendor, its address, a line description/model or a tag must find the bill.
 *
 * Regression: the list only matched bill_number + vendor name.
 */
class VendorBillWideSearchTest extends TenantTestCase
{
    private User $user;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = 'eeee5555-eeee-4555-eeee-555555555555';
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $tenantId,
            'name' => 'Vendor Bill Wide Search Tenant',
            'email' => 'vendor-bill-search@example.com',
            'plan' => 'standard',
        ]));
        $this->bindTenantContext($tenantId);

        $this->user = User::create([
            'name' => 'Tenant User',
            'email' => 'vendor-bill-search-user@test.local',
            'password' => bcrypt('password'),
        ]);

        $this->vendor = Vendor::factory()->create(['name' => 'Astra Supplies Pte Ltd']);
    }

    private function createBill(string $lineDescription, string $modelNumber = ''): Bill
    {
        $product = Product::factory()->create([
            'name' => 'Bill Search Product',
            'track_inventory' => false,
            'cost' => 15,
            'price' => 25,
            'expense_account_id' => ChartOfAccount::factory()->create([
                'type' => 'expense',
                'subtype' => 'operating_expense',
            ])->id,
        ]);

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/accounting/vendor-bills', [
                'vendor_id' => $this->vendor->id,
                'bill_date' => now()->toDateString(),
                'lines' => [[
                    'product_id' => $product->id,
                    'description' => $lineDescription,
                    'model_number' => $modelNumber ?: null,
                    'quantity' => 1,
                    'unit_price' => 15,
                    'tax_rate' => 0,
                ]],
            ]);

        $response->assertCreated();

        return Bill::findOrFail($response->json('data.id'));
    }

    /**
     * @return int[]
     */
    private function searchBillIds(string $term): array
    {
        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->getJson('/api/v1/accounting/vendor-bills?search='.urlencode($term).'&per_page=50')
            ->assertOk();

        return collect($response->json('data'))->pluck('id')->map('intval')->all();
    }

    public function test_bill_search_matches_vendor_name_and_address(): void
    {
        $bill = $this->createBill('Consulting');
        VendorAddress::create([
            'vendor_id' => $this->vendor->id,
            'type' => 'billing',
            'label' => 'HQ',
            'street' => '12 Jalan Kilang',
            'city' => 'Petaling Jaya',
            'country' => 'Malaysia',
        ]);

        $this->assertContains($bill->id, $this->searchBillIds('Astra Supplies'));
        $this->assertContains($bill->id, $this->searchBillIds('Petaling Jaya'));
    }

    public function test_bill_search_matches_line_contents_number_and_tags(): void
    {
        $bill = $this->createBill('Widget Assembly', 'MOD-9000');

        $this->assertContains($bill->id, $this->searchBillIds('Widget Assembly'));
        $this->assertContains($bill->id, $this->searchBillIds('MOD-9000'));
        $this->assertContains($bill->id, $this->searchBillIds($bill->bill_number));

        app(TagService::class)->attach($bill, ['VIP'], $this->user->id);
        $this->assertContains($bill->id, $this->searchBillIds('vip'));

        $this->assertSame([], $this->searchBillIds('Nobody Contactperson Zzz'));
    }
}
