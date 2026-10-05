<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #887 — the Odoo-style income account chain is product → product
 * category → default, with the line's own GL override beating both. #876/SA2
 * delivered line override → sales-order line → product and #884/UX2 carried
 * the values quotation → SO → invoice, but the category step was never read:
 * `product_categories` had no `income_account_id`, so a product with no
 * income account of its own silently fell through to the document default
 * instead of its category's account (a gap disclosed in #876's notes).
 *
 * Everything below goes through the real routes (create category/invoice →
 * post), not a service instantiated by the test.
 */
class ProductCategoryIncomeAccountFallbackOnInvoicePostTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private ChartOfAccount $defaultRevenue;

    private ChartOfAccount $categoryRevenue;

    private ChartOfAccount $productRevenue;

    private ChartOfAccount $overrideRevenue;

    private ChartOfAccount $receivable;

    private ProductCategory $category;

    private int $legalEntityId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);

        $this->admin = User::factory()->create(['email' => 'pc887-'.uniqid().'@example.local']);
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        CompanySettings::query()->create([
            'company_name' => 'Category Fallback Sdn Bhd',
            'base_currency' => 'MYR',
        ]);

        $entityGroupId = DB::table('entity_groups')->insertGetId([
            'name' => 'PC887 Group',
            'code' => 'PC887-G-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->legalEntityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $entityGroupId,
            'name' => 'PC887 Entity',
            'code' => 'PC887-E-'.Str::upper(Str::random(5)),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create([
            'legal_entity_id' => $this->legalEntityId,
            'payment_terms' => 30,
        ]);

        $this->defaultRevenue = $this->revenueAccount('PC887-4100', 'PC887 Default Sales Revenue');
        $this->categoryRevenue = $this->revenueAccount('PC887-4110', 'PC887 Category Income');
        $this->productRevenue = $this->revenueAccount('PC887-4120', 'PC887 Product Income');
        $this->overrideRevenue = $this->revenueAccount('PC887-4130', 'PC887 Line Override Income');
        $this->receivable = ChartOfAccount::factory()->create([
            'code' => 'PC887-1240',
            'name' => 'PC887 Accounts Receivable',
            'type' => 'asset',
            'is_active' => true,
        ]);

        foreach (['accounts_receivable' => $this->receivable->id, 'sales_revenue' => $this->defaultRevenue->id] as $key => $accountId) {
            DB::table('account_mappings')->where('mapping_key', $key)->delete();
            DB::table('account_mappings')->insert([
                'mapping_key' => $key,
                'account_id' => $accountId,
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->category = ProductCategory::create([
            'name' => 'PC887 Consulting Category',
            'income_account_id' => $this->categoryRevenue->id,
        ]);

        DB::table('currencies')->updateOrInsert(
            ['code' => 'MYR'],
            ['name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimal_places' => 2, 'is_active' => true, 'is_sellable' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    /**
     * AC: a line whose product has no income account of its own takes the
     * account configured on the product's category — not the document default.
     */
    public function test_invoice_line_falls_back_to_the_product_category_income_account(): void
    {
        $product = $this->productInCategory('PC887 Category Only Product', null);

        $invoice = $this->createInvoice($product);

        $this->postInvoice($invoice);

        $invoice = Invoice::with('journalEntry.lines')->findOrFail($invoice->id);
        $lines = $invoice->journalEntry->lines;

        $this->assertEqualsWithDelta(
            100.0,
            (float) $lines->where('account_id', $this->categoryRevenue->id)->sum('credit'),
            0.001,
            'A product without an income account must fall back to its category income account.'
        );
        $this->assertSame(
            0.0,
            round((float) $lines->where('account_id', $this->defaultRevenue->id)->sum('credit'), 2),
            'The default sales_revenue account must not be used when the category names an account.'
        );
    }

    /**
     * Precedence AC: the product's own income account beats its category's.
     */
    public function test_product_income_account_wins_over_the_product_category(): void
    {
        $product = $this->productInCategory('PC887 Product Account Product', $this->productRevenue->id);

        $invoice = $this->createInvoice($product);

        $this->postInvoice($invoice);

        $invoice = Invoice::with('journalEntry.lines')->findOrFail($invoice->id);
        $lines = $invoice->journalEntry->lines;

        $this->assertEqualsWithDelta(
            100.0,
            (float) $lines->where('account_id', $this->productRevenue->id)->sum('credit'),
            0.001,
            'The product income account must win over the category income account.'
        );
        $this->assertSame(
            0.0,
            round((float) $lines->where('account_id', $this->categoryRevenue->id)->sum('credit'), 2),
            'The category income account must not be used when the product names its own.'
        );
        $this->assertSame(0.0, round((float) $lines->where('account_id', $this->defaultRevenue->id)->sum('credit'), 2));
    }

    /**
     * Precedence AC: the line's own GL override still beats the category.
     */
    public function test_line_gl_override_wins_over_the_product_category(): void
    {
        $product = $this->productInCategory('PC887 Line Override Product', null);

        $invoice = $this->createInvoice($product);

        $invoice->lines()->firstOrFail()->forceFill(['gl_account_id' => $this->overrideRevenue->id])->save();

        $this->postInvoice($invoice);

        $invoice = Invoice::with('journalEntry.lines')->findOrFail($invoice->id);
        $lines = $invoice->journalEntry->lines;

        $this->assertEqualsWithDelta(
            100.0,
            (float) $lines->where('account_id', $this->overrideRevenue->id)->sum('credit'),
            0.001,
            'The line GL override must win over the category income account.'
        );
        $this->assertSame(
            0.0,
            round((float) $lines->where('account_id', $this->categoryRevenue->id)->sum('credit'), 2),
            'The category income account must not be used when the line overrides it.'
        );
    }

    /**
     * The category must be configurable through its own API, the same way a
     * product's income account is — otherwise users have no way to set the
     * fallback in the first place.
     */
    public function test_product_category_api_persists_the_income_account(): void
    {
        $store = $this->actingAs($this->admin)->postJson('/api/v1/product-categories', [
            'name' => 'PC887 API Category',
            'income_account_id' => $this->categoryRevenue->id,
        ]);
        $store->assertCreated($store->getContent());

        $categoryId = $store->json('id');
        $this->assertSame(
            (int) $this->categoryRevenue->id,
            (int) $store->json('income_account_id'),
            'Creating a category with an income account must return and persist it.'
        );

        $update = $this->actingAs($this->admin)->putJson("/api/v1/product-categories/{$categoryId}", [
            'income_account_id' => $this->overrideRevenue->id,
        ]);
        $update->assertOk($update->getContent());

        $this->assertSame(
            (int) $this->overrideRevenue->id,
            (int) ProductCategory::findOrFail($categoryId)->income_account_id,
            'Updating a category income account must persist the new account.'
        );
    }

    private function productInCategory(string $name, ?int $incomeAccountId): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'sku' => 'PC887-'.Str::upper(Str::random(6)),
            'price' => 100,
            'cost' => 0,
            'track_inventory' => false,
            'category_id' => $this->category->id,
            'income_account_id' => $incomeAccountId,
        ]);
    }

    private function createInvoice(Product $product): Invoice
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated($response->getContent());

        return Invoice::with('lines')->findOrFail($response->json('id'));
    }

    private function postInvoice(Invoice $invoice): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post");
        $response->assertOk($response->getContent());
    }

    private function revenueAccount(string $code, string $name): ChartOfAccount
    {
        return ChartOfAccount::factory()->create([
            'code' => $code,
            'name' => $name,
            'type' => 'revenue',
            'is_active' => true,
        ]);
    }
}
