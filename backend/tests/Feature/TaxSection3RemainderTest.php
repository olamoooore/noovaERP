<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StatutoryReturnFiling;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\TaxDeterminationService;
use App\Services\TaxResolutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Services\TaxReportingService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #274 Section 3 remainder (G-26..G-29): inclusive bills, invoice identity /
 * rate mismatch + per-rate aggregation, POS/web resolution context + canonical
 * variant codes, and filings() period/entity matching without N+1.
 */
class TaxSection3RemainderTest extends TenantTestCase
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

    private function rule(array $extra = []): Tax
    {
        return Tax::create(array_merge([
            'name' => 'S3 rule', 'code' => 'S3-'.Str::upper(Str::random(4)),
            'type' => 'gst', 'regime' => 'GST', 'jurisdiction' => 'SG',
            'scope' => 'both', 'treatment' => 'standard_rated', 'rate' => 10,
            'is_active' => true,
        ], $extra))->refresh();
    }

    private function product(?Tax $rule = null, array $extra = []): Product
    {
        $product = Product::create(array_merge([
            'name' => 'S3 product', 'sku' => 'S3-'.Str::upper(Str::random(6)),
            'is_active' => true, 'track_inventory' => false,
        ], $extra));

        if ($rule) {
            $product->forceFill(['tax_id' => $rule->id])->save();
        }

        return $product->refresh();
    }

    // ── G-26: inclusive bills ─────────────────────────────────────────────

    public function test_inclusive_bill_extracts_tax_from_the_entered_amount(): void
    {
        $rule = $this->rule(['rate' => 10, 'is_inclusive' => true]);
        $product = $this->product($rule);

        $bill = Bill::create([
            'vendor_id' => Vendor::factory()->create()->id,
            'bill_number' => 'S3BILL-'.Str::random(6), 'bill_type' => 'expense',
            'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft', 'subtotal' => 110, 'tax_amount' => 10, 'discount_amount' => 0,
            'total' => 120, 'paid_amount' => 0, 'balance' => 120, 'created_by' => $this->user->id,
        ]);
        BillLine::create([
            'bill_id' => $bill->id, 'product_id' => $product->id, 'description' => 'Inclusive line',
            'quantity' => 1, 'unit_price' => 110, 'tax_rate' => 10, 'tax_amount' => 0,
            'line_total' => 110, 'account_id' => null,
        ]);

        $result = app(TaxDeterminationService::class)
            ->determineForBill($bill->load('lines.product', 'vendor.addresses'));

        $line = $result['tax_lines'][0];
        $this->assertSame(10.0, (float) $line['tax_rate']);
        $this->assertSame(100.0, (float) $line['taxable_base'], 'Inclusive tax must be extracted from the entered gross.');
        $this->assertSame(10.0, (float) $line['tax_amount']);
    }

    // ── G-27: identity / rate mismatch + aggregation ──────────────────────

    public function test_invoice_booked_rate_never_borrows_a_mismatched_rule_identity(): void
    {
        $rule = $this->rule(['rate' => 10, 'code' => 'MISM-10']);
        $product = $this->product($rule);
        $customer = Customer::create(['name' => 'S3 Cust '.Str::random(4), 'is_active' => true]);

        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'invoice_number' => 'S3INV-'.Str::random(6),
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft', 'lifecycle_status' => 'draft',
            'subtotal' => 100, 'tax_amount' => 5, 'discount_amount' => 0, 'total' => 105,
            'paid_amount' => 0, 'balance' => 105, 'created_by' => $this->user->id,
        ]);
        InvoiceLine::create([
            'invoice_id' => $invoice->id, 'product_id' => $product->id, 'description' => 'Booked 5% line',
            'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 5, 'tax_inclusive' => false,
            'discount_amount' => 0, 'line_total' => 105, 'tax_amount' => 5,
        ]);

        $result = app(TaxDeterminationService::class)
            ->determineForInvoice($invoice->load('lines.product', 'customer'));

        $line = $result['tax_lines'][0];
        $this->assertSame(5.0, (float) $line['tax_rate'], 'The booked rate stays authoritative.');
        $this->assertNotSame('MISM-10', $line['tax_code'], 'A 10% rule must not label a 5% booked line.');
        $this->assertNull($line['tax_id'], 'No mismatched rule may be bound to the line.');
        $this->assertSame(100.0, (float) $line['taxable_base']);
    }

    public function test_aggregation_does_not_collapse_mixed_rates_sharing_a_code(): void
    {
        $result = app(TaxDeterminationService::class)->determineForLineSet(
            'invoice', 93001, now()->toDateString(),
            [
                ['source_line_id' => 1, 'base' => 100.0, 'rate' => 5.0, 'tax' => 5.0, 'tax_code_override' => 'DUP-CODE', 'treatment_override' => 'standard_rated'],
                ['source_line_id' => 2, 'base' => 100.0, 'rate' => 10.0, 'tax' => 10.0, 'tax_code_override' => 'DUP-CODE', 'treatment_override' => 'standard_rated'],
            ],
            jurisdiction: 'SG',
        );

        $rows = collect($result['tax_lines'])->where('tax_code', 'DUP-CODE')->values();
        $this->assertCount(2, $rows, 'Mixed-rate lines sharing a code must stay separate rows.');
        $this->assertSame([5.0, 10.0], $rows->pluck('tax_rate')->map(fn ($r) => (float) $r)->sort()->values()->all());
    }

    // ── G-28: POS/web resolution context + canonical variant codes ────────

    public function test_variant_zero_rate_is_an_explicit_override_with_a_canonical_code(): void
    {
        $product = $this->product();
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'name' => 'Zero variant', 'sku' => 'S3-V-'.Str::upper(Str::random(4)),
            'tax_rate' => 0,
        ]);

        $info = app(TaxResolutionService::class)->resolveTaxForProduct($product->refresh(), $variant->refresh());

        $this->assertSame(0.0, (float) $info['rate'], 'An explicit 0% variant must override, not fall through.');
        $this->assertMatchesRegularExpression('/^[A-Z]{2}-[A-Z]+-0$/', (string) $info['tax_code'], 'Variant codes must use the engine format.');
        $this->assertStringNotContainsString('VARIANT', (string) $info['tax_code']);
    }

    public function test_variant_override_respects_passed_jurisdiction_and_date(): void
    {
        $product = $this->product();
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'name' => 'MY variant', 'sku' => 'S3-V-'.Str::upper(Str::random(4)),
            'tax_rate' => 8,
        ]);

        $info = app(TaxResolutionService::class)->resolveTaxForProduct(
            $product->refresh(), $variant->refresh(), 'MY', 'output', '2026-06-15',
        );

        $this->assertSame(8.0, (float) $info['rate']);
        $this->assertSame('MY-SST-8', $info['tax_code'], 'The code must reflect the resolved jurisdiction/regime.');
    }

    // ── G-29: filings() matching ──────────────────────────────────────────

    private function activity(string $period, ?string $jurisdiction = 'SG', ?int $legalEntityId = null): void
    {
        if ($legalEntityId !== null && ! DB::table('legal_entities')->where('id', $legalEntityId)->exists()) {
            $groupId = DB::table('entity_groups')->insertGetId([
                'name' => 'S3 group '.Str::random(4), 'code' => 'S3G-'.Str::upper(Str::random(6)),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('legal_entities')->insert([
                'id' => $legalEntityId, 'group_id' => $groupId, 'name' => 'S3 entity '.$legalEntityId,
                'code' => 'S3E-'.Str::upper(Str::random(6)), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $entry = JournalEntry::factory()->create([
            'status' => 'posted',
            'entry_date' => $period.'-15',
            'legal_entity_id' => $legalEntityId,
        ]);

        DB::table('tax_transaction_details')->insert([
            'journal_entry_id' => $entry->id,
            'source_document_type' => 'invoice',
            'source_document_id' => 1,
            'tax_code' => 'S3-T',
            'taxable_amount' => 100,
            'tax_amount' => 9,
            'jurisdiction' => $jurisdiction,
            'reporting_period' => $period,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function filing(string $country, string $period, int $entityKey): void
    {
        StatutoryReturnFiling::create([
            'tenant_id' => tenant('id'),
            'country' => $country,
            'form' => 'GST-F5',
            'period' => $period,
            'entity_key' => $entityKey,
            'filing_version' => 1,
            'boxes' => [],
            'filed_at' => now(),
        ]);
    }

    public function test_quarterly_filing_closes_its_monthly_activity_periods(): void
    {
        $this->activity('2026-02');

        $service = app(TaxReportingService::class);
        $open = collect($service->filings()['open_periods']);
        $this->assertTrue($open->contains(fn ($p) => $p['period'] === '2026-02' && $p['country'] === 'SG'), 'Unfiled activity must be open.');

        $this->filing('SG', '2026-Q1', 0);

        $open = collect($service->filings()['open_periods']);
        $this->assertFalse($open->contains(fn ($p) => $p['period'] === '2026-02' && $p['entity_key'] === 0), 'A Q1 filing must close February.');
    }

    public function test_filings_match_the_legal_entity_and_surface_null_jurisdiction_rows(): void
    {
        $this->activity('2026-03', 'SG', null);
        $this->activity('2026-03', 'SG', 5);
        $this->activity('2026-03', null, null);

        $this->filing('SG', '2026-03', 5);

        $open = collect(app(TaxReportingService::class)->filings()['open_periods']);

        $this->assertFalse(
            $open->contains(fn ($p) => $p['period'] === '2026-03' && $p['country'] === 'SG' && $p['entity_key'] === 5),
            'A filing for entity 5 must close entity 5 activity only.'
        );
        $this->assertTrue(
            $open->contains(fn ($p) => $p['period'] === '2026-03' && $p['country'] === 'SG' && $p['entity_key'] === 0),
            'Tenant-wide activity stays open when only entity 5 filed.'
        );
        $this->assertTrue(
            $open->contains(fn ($p) => $p['period'] === '2026-03' && $p['country'] === 'UNSPECIFIED'),
            'Rows without jurisdiction must be surfaced, never silently skipped.'
        );
    }

    public function test_months_between_does_not_silently_truncate_long_ranges(): void
    {
        $this->assertCount(36, TaxReportingService::monthsBetween('2024-01', '2026-12'));
    }
}
