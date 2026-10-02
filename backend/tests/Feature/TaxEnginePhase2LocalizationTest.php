<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\TaxRegistration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxCountryPackService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Malaysia\Services\Sst02ReturnService;
use Spatie\Permission\Models\Permission;
use Tests\TenantTestCase;

/**
 * MRD Phase 2 (TAX-REQ-05/06): country packs as effective-dated DATA,
 * taxonomy-driven SST-02 classification, registrations and thresholds —
 * through real HTTP against the live resolution pipeline.
 */
class TaxEnginePhase2LocalizationTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
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
        foreach (['accounting.write', 'reports.view', 'settings.write'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $this->user->givePermissionTo($permission);
        }

        foreach (
            [
                'cash' => ['1000-P2', 'asset'],
                'accounts_receivable' => ['1100-P2', 'asset'],
                'sales_tax_payable' => ['2200-P2', 'liability'],
                'input_tax' => ['2210-P2', 'asset'],
                'sales_revenue' => ['3000-P2', 'revenue'],
            ] as $key => [$code, $type]
        ) {
            $account = ChartOfAccount::create([
                'code' => $code, 'name' => "P2 {$key}", 'type' => $type,
                'account_type' => $type, 'is_active' => true,
            ]);
            AccountMapping::create(['mapping_key' => $key, 'account_id' => $account->id, 'is_active' => true]);
        }
    }

    // ── Packs: activation, idempotency, date-resolved rates ───────────────

    public function test_activating_packs_is_idempotent_and_never_overwrites_existing_rules(): void
    {
        // A tenant customisation on a pack code must survive activation.
        Tax::create(['name' => 'My GST 9 (custom)', 'code' => 'SG-GST-9', 'type' => 'gst', 'rate' => 9, 'is_active' => true]);

        $first = $this->actingAs($this->user)->postJson('/api/v1/accounting/tax-engine/packs/SG/activate');
        $first->assertOk()
            ->assertJsonPath('data.created.0', 'SG-GST-8')
            ->assertJsonPath('data.label', 'Singapore GST (IRAS)');
        $this->assertNotContains('SG-GST-9', $first->json('data.created'), 'existing code must not be recreated');
        $this->assertContains('SG-GST-9', $first->json('data.existing'));

        $second = $this->actingAs($this->user)->postJson('/api/v1/accounting/tax-engine/packs/SG/activate');
        $second->assertOk()->assertJsonPath('data.created', []);
        $this->assertCount(5, $second->json('data.existing'));

        $this->assertSame('My GST 9 (custom)', Tax::where('code', 'SG-GST-9')->value('name'), 'activation must not clobber tenant edits');

        // Home jurisdiction defaulted onto the tenant setting.
        $this->assertSame('SG', Setting::getValue('tax', [])['jurisdiction'] ?? null);
    }

    public function test_unknown_pack_is_rejected_and_status_reports_coverage(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/accounting/tax-engine/packs/ZZ/activate')->assertStatus(422);

        $status = $this->actingAs($this->user)->getJson('/api/v1/accounting/tax-engine/packs');
        $status->assertOk()
            ->assertJsonPath('data.SG.activated', false)
            ->assertJsonPath('data.MY.activated', false);

        $this->actingAs($this->user)->postJson('/api/v1/accounting/tax-engine/packs/SG/activate')->assertOk();

        $after = $this->actingAs($this->user)->getJson('/api/v1/accounting/tax-engine/packs');
        $after->assertOk()->assertJsonPath('data.SG.activated', true);
    }

    public function test_sg_pack_resolves_historical_and_current_rates_by_transaction_date(): void
    {
        app(TaxCountryPackService::class)->activate('SG');
        $engine = app(\App\Services\TaxEngineService::class);
        $product = Product::create(['name' => 'P2', 'sku' => 'P2-'.Str::random(6), 'is_active' => true, 'track_inventory' => false]);

        $this->assertSame(7.0, (float) $engine->resolve($product, 'SG', 'output', null, '2022-06-01')['rate']);
        $this->assertSame(8.0, (float) $engine->resolve($product, 'SG', 'output', null, '2023-06-01')['rate']);
        $this->assertSame(9.0, (float) $engine->resolve($product, 'SG', 'output', null, '2024-06-01')['rate']);
    }

    public function test_my_pack_service_rate_moves_6_to_8_by_date_and_carries_no_fictional_2026_rows(): void
    {
        app(TaxCountryPackService::class)->activate('MY');
        $engine = app(\App\Services\TaxEngineService::class);
        $product = Product::create(['name' => 'P2 svc', 'sku' => 'P2S-'.Str::random(6), 'is_active' => true, 'track_inventory' => false]);

        // DoD proof: same code path, different dates, different correct rates.
        $this->assertSame(6.0, (float) $engine->resolve($product, 'MY', 'output', 'service', '2025-06-15')['rate']);
        $this->assertSame(8.0, (float) $engine->resolve($product, 'MY', 'output', 'service', '2025-08-15')['rate']);

        // #734 T1 (decision reversal on #269): the fictional 2026 GST-model
        // 8%/10% rows are gone. The 2026 MY service-tax timeline is under
        // correction per #735 pending RMCD-verified data, so no 2026 rate is
        // asserted here.
        $this->assertFalse(Tax::withTrashed()->where('code', 'MY-SSTG-8')->exists());
        $this->assertFalse(Tax::withTrashed()->where('code', 'MY-SSTG-10')->exists());
        $this->assertFalse(Tax::withTrashed()->where('code', 'MY-SST-ZERO')->exists());
        $this->assertFalse(Tax::withTrashed()->where('code', 'MY-SST-EXEMPT')->exists());
    }

    // ── SST-02 classification by rule taxonomy (D9) ───────────────────────

    public function test_sst02_buckets_come_from_rule_type_not_code_substrings(): void
    {
        app(TaxCountryPackService::class)->activate('MY');
        $serviceRule = Tax::where('code', 'MY-SVC-8')->firstOrFail();
        $salesRule = Tax::where('code', 'MY-SALES-10')->firstOrFail();

        $customer = Customer::create(['name' => 'P2 Cust', 'email' => 'p2@x.test', 'is_active' => true]);
        $serviceProduct = Product::create([
            'name' => 'Consulting', 'sku' => 'P2C-'.Str::random(6), 'is_active' => true,
            'type' => 'service', 'track_inventory' => false,
        ]);
        $serviceProduct->forceFill(['tax_id' => $serviceRule->id])->save();
        $goodsProduct = Product::create([
            'name' => 'Widgets', 'sku' => 'P2W-'.Str::random(6), 'is_active' => true,
            'track_inventory' => false,
        ]);
        $goodsProduct->forceFill(['tax_id' => $salesRule->id])->save();

        // Both invoices carry deliberately NON-matching codes in the loop
        // below: identity comes from the linked rules (tax_id), so the
        // substring heuristic would mis-bucket, the taxonomy cannot.
        foreach ([[$serviceProduct, 100.0, 8.0, 8.0], [$goodsProduct, 200.0, 10.0, 20.0]] as $row) {
            [$product, $price, $rate, $taxAmount] = $row;
            $invoiceSubtotal = $price;
            $invoiceTax = $taxAmount;
            $invoice = Invoice::create([
                'customer_id' => $customer->id,
                'invoice_number' => 'P2INV-'.Str::random(8),
                'invoice_date' => '2025-08-15',
                'due_date' => '2025-09-14',
                'status' => 'draft',
                'subtotal' => $invoiceSubtotal,
                'tax_amount' => $invoiceTax,
                'total' => $invoiceSubtotal + $invoiceTax,
                'created_by' => $this->user->id,
            ]);
            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'product_id' => $product->id,
                'description' => 'line',
                'quantity' => 1,
                'unit_price' => $price,
                'tax_rate' => $rate,
                'tax_inclusive' => false,
                'discount_amount' => 0,
            ]);
            app(\App\Services\InvoiceService::class)->postInvoice($invoice->fresh(['lines']));
        }

        $return = app(Sst02ReturnService::class)->generate('2025-08');

        $this->assertSame('rule_taxonomy', $return['classification']['method']);
        $this->assertSame(0, $return['classification']['unclassified_legacy_rows']);
        $this->assertSame(8.0, $return['service_tax']['tax_due']);
        $this->assertSame(100.0, $return['service_tax']['taxable_value']);
        $this->assertSame(20.0, $return['sales_tax']['tax_due']);
        $this->assertSame(200.0, $return['sales_tax']['taxable_value']);
        $this->assertSame(28.0, $return['total_tax_payable']);
    }

    // ── Registrations (R5.3/R6.2) ─────────────────────────────────────────

    public function test_registration_lifecycle_endpoints(): void
    {
        $store = $this->actingAs($this->user)->postJson('/api/v1/accounting/tax-registrations', [
            'jurisdiction' => 'my',
            'regime' => 'sst',
            'registration_number' => 'W10-2026-000123',
            'effective_from' => '2026-01-01',
        ]);
        $store->assertCreated()->assertJsonPath('data.jurisdiction', 'MY');

        $id = $store->json('data.id');

        $this->actingAs($this->user)->putJson("/api/v1/accounting/tax-registrations/{$id}", [
            'jurisdiction' => 'MY',
            'registration_number' => 'W10-2026-000123',
            'effective_from' => '2026-01-01',
            'status' => 'active',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $list = $this->actingAs($this->user)->getJson('/api/v1/accounting/tax-registrations');
        $list->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($this->user)->deleteJson("/api/v1/accounting/tax-registrations/{$id}")->assertOk();
        $this->assertSame(0, TaxRegistration::count());
    }

    // ── Threshold monitoring (R5.3/R6.2) ──────────────────────────────────

    public function test_thresholds_report_trailing_taxable_value_against_authority_limits(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/accounting/tax-engine/packs/SG/activate')->assertOk();

        $response = $this->actingAs($this->user)->getJson('/api/v1/accounting/tax-engine/thresholds?jurisdiction=SG');
        $response->assertOk()
            ->assertJsonPath('data.SG.thresholds.0.amount', 1000000)
            ->assertJsonPath('data.SG.thresholds.0.monitoring_flag', false);

        $this->assertSame(0.0, (float) $response->json('data.SG.trailing_12m_taxable_value'));

        $missing = $this->actingAs($this->user)->getJson('/api/v1/accounting/tax-engine/thresholds?jurisdiction=ZZ');
        $missing->assertOk()->assertJsonPath('data', []);
    }
}
