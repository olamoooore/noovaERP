<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #451: Sales Settings "Default Tax Treatment" was rendered by the SPA but
 * never persisted — GET hard-coded 'standard' and update ignored the value —
 * so the tenant's choice was silently discarded and the tax resolver never
 * read it.
 *
 * Acceptance: save -> reload returns the saved value, and the resolver
 * honours it as the tenant-level treatment default. Every assertion runs
 * through the real HTTP routes (router -> middleware -> controller):
 * PUT/GET /api/v1/settings/sales and POST /api/v1/invoices + /send, reading
 * the canonical tax_transaction_details trail the resolver feeds.
 */
class Issue451DefaultTaxTreatmentTest extends TenantTestCase
{
    private User $admin;

    private string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Issue 451 Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_default_tax_treatment_round_trips_and_persists(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->assertJsonPath('data.tax.default_tax_treatment', 'standard');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/settings/sales', [
                'tax' => ['default_tax_treatment' => 'exempt'],
            ])
            ->assertOk()
            ->assertJsonPath('data.tax.default_tax_treatment', 'exempt');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->assertJsonPath('data.tax.default_tax_treatment', 'exempt');

        $this->assertSame(
            'exempt',
            Setting::getValue('tax', [])['default_tax_treatment'] ?? null,
            'The value must persist in the canonical tax setting block the resolver reads.',
        );

        foreach (['standard', 'zero_rated', 'exempt', 'out_of_scope'] as $value) {
            $this->actingAs($this->admin, 'sanctum')
                ->putJson('/api/v1/settings/sales', [
                    'tax' => ['default_tax_treatment' => $value],
                ])
                ->assertOk()
                ->assertJsonPath('data.tax.default_tax_treatment', $value);
        }
    }

    public function test_default_tax_treatment_rejects_values_outside_the_ui_options(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/settings/sales', [
                'tax' => ['default_tax_treatment' => 'standard_rated'],
            ])
            ->assertStatus(422);
    }

    public function test_invoice_tax_trail_honours_the_saved_tenant_default_treatment(): void
    {
        $customer = Customer::factory()->create(['payment_terms' => 30]);
        $product = Product::factory()->create([
            'name' => 'Consulting Services',
            'sku' => 'SVC-'.uniqid(),
            'track_inventory' => false,
            'price' => 100,
            'cost' => 60,
        ]);

        // Baseline: with no tenant treatment saved, an unclassified 0% line
        // keeps the existing zero_rated fallback.
        $baseline = $this->postAndSendInvoice($customer, $product);
        $baselineRow = DB::table('tax_transaction_details')
            ->where('journal_entry_id', $baseline->journalEntry->id)
            ->first();
        $this->assertNotNull($baselineRow, 'A zero-rated supply row must exist on the tax trail.');
        $this->assertSame('zero_rated', $baselineRow->tax_treatment);

        // The tenant picks the tenant-level default treatment in Sales Settings.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/settings/sales', [
                'tax' => ['default_tax_treatment' => 'exempt'],
            ])
            ->assertOk();

        $invoice = $this->postAndSendInvoice($customer, $product);
        $row = DB::table('tax_transaction_details')
            ->where('journal_entry_id', $invoice->journalEntry->id)
            ->first();

        $this->assertNotNull($row, 'An exempt supply row must be written to the tax trail.');
        $this->assertSame('exempt', $row->tax_treatment, 'The resolver must honour the saved tenant default treatment.');
        $this->assertSame('supply', $row->direction);
        $this->assertSame(0.0, round((float) $row->tax_amount, 2));
        $this->assertSame(100.0, round((float) $row->taxable_amount, 2));
    }

    private function postAndSendInvoice(Customer $customer, Product $product): Invoice
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Consulting services',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated();

        $invoiceId = (int) $response->json('id');
        $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoiceId}/send")->assertOk();

        return Invoice::with('journalEntry')->findOrFail($invoiceId);
    }
}
