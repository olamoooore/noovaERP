<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxCountryPackService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TenantTestCase;

/**
 * #308 (X8): SST-02 must never guess a service line into the sales tax
 * bucket. A line whose booked rate did not match the resolved rule carries
 * no rule identity (tax_rule_id null, synthetic code MY-SST-8) — the old
 * substring fallback counted it as sales tax. It is now an unclassified
 * exception, while a matched MY service line at 8% still lands in the
 * service tax section.
 */
class Issue308Sst02UnmatchedLineClassificationTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@issue308.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Permission::firstOrCreate(['name' => 'accounting.write', 'guard_name' => 'web']);
        $this->user->givePermissionTo('accounting.write');

        foreach (
            [
                'cash' => ['1000-I308', 'asset'],
                'accounts_receivable' => ['1100-I308', 'asset'],
                'sales_tax_payable' => ['2200-I308', 'liability'],
                'input_tax' => ['2210-I308', 'asset'],
                'sales_revenue' => ['3000-I308', 'revenue'],
            ] as $key => [$code, $type]
        ) {
            $account = ChartOfAccount::create([
                'code' => $code, 'name' => "I308 {$key}", 'type' => $type,
                'account_type' => $type, 'is_active' => true,
            ]);
            AccountMapping::create(['mapping_key' => $key, 'account_id' => $account->id, 'is_active' => true]);
        }
    }

    private function postServiceInvoice(Product $product, string $invoiceNumber): void
    {
        $customer = Customer::create(['name' => 'I308 Cust', 'email' => 'i308@x.test', 'is_active' => true]);

        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => '2025-08-15',
            'due_date' => '2025-09-14',
            'status' => 'draft',
            'subtotal' => 100.00,
            'tax_amount' => 8.00,
            'total' => 108.00,
            'created_by' => $this->user->id,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => 'service line at 8%',
            'quantity' => 1,
            'unit_price' => 100.00,
            'tax_rate' => 8.00,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);

        app(\App\Services\InvoiceService::class)->postInvoice($invoice->fresh(['lines']));
    }

    public function test_unmatched_service_line_is_unclassified_and_matched_service_line_stays_service(): void
    {
        app(TaxCountryPackService::class)->activate('MY');
        $serviceRule = Tax::where('code', 'MY-SVC-8')->firstOrFail();
        $salesRule = Tax::where('code', 'MY-SALES-10')->firstOrFail();

        // The X8 scenario: the line was booked at 8% while the product's
        // resolved rule is sales tax at 10%. G-27 drops the mismatched
        // identity, leaving a synthetic MY-SST-8 code and no tax_rule_id.
        $mismatchedProduct = Product::create([
            'name' => 'I308 mismatched service', 'sku' => 'I308M-'.Str::random(6),
            'is_active' => true, 'type' => 'service', 'track_inventory' => false,
        ]);
        $mismatchedProduct->forceFill(['tax_id' => $salesRule->id])->save();
        $this->postServiceInvoice($mismatchedProduct, 'I308-MIS-'.Str::random(6));

        // A properly matched MY service line at 8% (its resolved rule IS the
        // 8% service rule) must keep its service tax identity.
        $matchedProduct = Product::create([
            'name' => 'I308 matched service', 'sku' => 'I308S-'.Str::random(6),
            'is_active' => true, 'type' => 'service', 'track_inventory' => false,
        ]);
        $matchedProduct->forceFill(['tax_id' => $serviceRule->id])->save();
        $this->postServiceInvoice($matchedProduct, 'I308-MATCH-'.Str::random(6));

        $response = $this->actingAs($this->user)->getJson('/api/v1/accounting/statutory-returns/MY/2025-08');
        $response->assertOk();

        // AC2: the unmatched 8% line is an unclassified exception — never sales tax.
        $this->assertSame(0.0, (float) $response->json('data.sales_tax.taxable_value'));
        $this->assertSame(0.0, (float) $response->json('data.sales_tax.tax_due'));
        $this->assertSame(1, (int) $response->json('data.classification.unclassified_rows'));
        $this->assertSame(1, (int) $response->json('data.classification.unclassified_legacy_rows'));

        // AC3: the matched service line at 8% still lands in the service section.
        $this->assertSame(100.0, (float) $response->json('data.service_tax.taxable_value'));
        $this->assertSame(8.0, (float) $response->json('data.service_tax.tax_due'));

        // An unclassified row cannot be filed until it is mapped to a rule.
        $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/statutory-returns/MY/2025-08/file')
            ->assertStatus(422);
    }
}
