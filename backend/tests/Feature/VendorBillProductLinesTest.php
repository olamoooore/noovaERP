<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\ChartOfAccount;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use Tests\TenantTestCase;

/**
 * Vendor bill lines mirror the invoice line editor: a product supplies the
 * description, price, tax rate AND the expense account (with the explicit
 * account still available as an override). Only account-only lines used to
 * be accepted, which forced AP clerks to code GL accounts by hand.
 *
 * Also locks the tax math: a document discount must prorate the taxable base
 * the same way on create and update (update used to charge tax pre-discount).
 */
class VendorBillProductLinesTest extends TenantTestCase
{
    private User $user;

    private Vendor $vendor;

    private ChartOfAccount $expenseAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = 'dddd4444-dddd-4444-dddd-444444444444';
        Tenant::withoutEvents(function () use ($tenantId) {
            return Tenant::create([
                'id' => $tenantId,
                'name' => 'Vendor Bill Product Lines Tenant',
                'email' => 'vendor-bill-products@example.com',
                'plan' => 'standard',
            ]);
        });
        $this->bindTenantContext($tenantId);

        $this->user = User::create([
            'name' => 'Tenant User',
            'email' => 'vendor-bill-products-user@test.local',
            'password' => bcrypt('password'),
        ]);

        $this->vendor = Vendor::factory()->create();
        $this->expenseAccount = ChartOfAccount::factory()->create([
            'type' => 'expense',
            'subtype' => 'operating_expense',
        ]);
    }

    private function productWithAccount(): Product
    {
        return Product::factory()->create([
            'name' => 'Vendor Bill Test Product',
            'track_inventory' => false,
            'cost' => 15,
            'price' => 25,
            'expense_account_id' => $this->expenseAccount->id,
        ]);
    }

    public function test_a_product_line_resolves_its_expense_account_and_tax(): void
    {
        $product = $this->productWithAccount();

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/accounting/vendor-bills', [
                'vendor_id' => $this->vendor->id,
                'bill_date' => now()->toDateString(),
                'lines' => [[
                    'product_id' => $product->id,
                    'description' => 'Product-coded expense',
                    'quantity' => 2,
                    'unit_price' => 15,
                    'tax_rate' => 10,
                ]],
            ]);

        $response->assertCreated();

        $bill = Bill::with('lines')->find($response->json('data.id'));
        $line = $bill->lines->first();

        $this->assertSame($this->expenseAccount->id, $line->account_id, 'The product expense account must code the line.');
        $this->assertSame($product->id, $line->product_id);
        $this->assertSame(30.0, (float) $bill->subtotal);
        $this->assertSame(3.0, (float) $bill->tax_amount);
        $this->assertSame(33.0, (float) $bill->total);
    }

    public function test_an_explicit_account_overrides_the_product_mapping(): void
    {
        $product = $this->productWithAccount();
        $override = ChartOfAccount::factory()->create([
            'type' => 'expense',
            'subtype' => 'operating_expense',
        ]);

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/accounting/vendor-bills', [
                'vendor_id' => $this->vendor->id,
                'bill_date' => now()->toDateString(),
                'lines' => [[
                    'product_id' => $product->id,
                    'account_id' => $override->id,
                    'description' => 'Negotiated coding',
                    'quantity' => 1,
                    'unit_price' => 10,
                    'tax_rate' => 0,
                ]],
            ]);

        $response->assertCreated();

        $bill = Bill::with('lines')->find($response->json('data.id'));
        $this->assertSame($override->id, $bill->lines->first()->account_id);
    }

    public function test_note_and_section_lines_are_stored_without_affecting_totals(): void
    {
        $product = $this->productWithAccount();

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/accounting/vendor-bills', [
                'vendor_id' => $this->vendor->id,
                'bill_date' => now()->toDateString(),
                'lines' => [
                    [
                        'line_type' => 'section',
                        'description' => 'Consumables',
                        'quantity' => 0,
                        'unit_price' => 0,
                        'tax_rate' => 0,
                    ],
                    [
                        'line_type' => 'product',
                        'product_id' => $product->id,
                        'description' => 'Coded expense',
                        'quantity' => 1,
                        'unit_price' => 20,
                        'tax_rate' => 9,
                    ],
                    [
                        'line_type' => 'note',
                        'description' => 'Delivered in two shipments',
                        'quantity' => 0,
                        'unit_price' => 0,
                        'tax_rate' => 0,
                    ],
                ],
            ]);

        $response->assertCreated();

        $bill = Bill::with('lines')->find($response->json('data.id'));

        $this->assertSame(20.0, (float) $bill->subtotal, 'Notes/sections must not change the subtotal.');
        $this->assertSame(1.8, (float) $bill->tax_amount);
        $this->assertSame(21.8, (float) $bill->total);

        $textLines = $bill->lines->whereIn('line_type', ['note', 'section']);
        $this->assertCount(2, $textLines);
        $this->assertNull($textLines->first()->account_id, 'Text lines carry no account.');
    }

    public function test_document_discount_prorates_the_taxable_base_on_create(): void
    {
        $product = $this->productWithAccount();

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/accounting/vendor-bills', [
                'vendor_id' => $this->vendor->id,
                'bill_date' => now()->toDateString(),
                'discount_amount' => 50,
                'lines' => [[
                    'product_id' => $product->id,
                    'description' => 'Discounted expense',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 10,
                ]],
            ]);

        $response->assertCreated();

        $bill = Bill::find($response->json('data.id'));

        // 100 gross - 50 discount = 50 taxable -> 5 tax -> 55 total.
        $this->assertSame(5.0, (float) $bill->tax_amount);
        $this->assertSame(55.0, (float) $bill->total);
    }

    public function test_update_prorates_document_discount_tax_the_same_way_as_create(): void
    {
        $product = $this->productWithAccount();

        $create = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/accounting/vendor-bills', [
                'vendor_id' => $this->vendor->id,
                'bill_date' => now()->toDateString(),
                'lines' => [[
                    'product_id' => $product->id,
                    'description' => 'Discounted expense',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 10,
                ]],
            ]);
        $create->assertCreated();
        $billId = (int) $create->json('data.id');

        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->putJson("/api/v1/accounting/vendor-bills/{$billId}", [
                'discount_amount' => 50,
                'lines' => [[
                    'product_id' => $product->id,
                    'description' => 'Discounted expense',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 10,
                ]],
            ])
            ->assertOk();

        $bill = Bill::find($billId);

        // The update path used to charge 10 tax on the full 100 and subtract
        // the discount afterwards (total 60) instead of prorating (total 55).
        $this->assertSame(5.0, (float) $bill->tax_amount);
        $this->assertSame(55.0, (float) $bill->total);
    }
}
