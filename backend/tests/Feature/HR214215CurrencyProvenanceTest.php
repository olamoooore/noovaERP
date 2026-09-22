<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Currency;
use App\Models\Invoice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issues #214 / #215 (regressions from the #98 currency fix):
 *  #215 — raw inserts (SampleTransactionsSeeder) bypass the model hook and left
 *         currency_code NULL, so the views' COALESCE made the reported currency
 *         float with the tenant's base currency later.
 *  #214 — the backfill stamped historical rows with the CURRENT base currency;
 *         where no settings history exists, those rows are unverifiable and are
 *         now flagged for manual review instead of silently trusted.
 */
class HR214215CurrencyProvenanceTest extends TenantTestCase
{
    private \App\Models\User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext((string) Str::uuid());
        $this->assertTenantSchemaReady(['invoices', 'bills', 'payments', 'bill_payments', 'company_settings']);

        $this->user = \App\Models\User::factory()->create();

        Currency::query()->updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true]
        );
    }

    private function makeInvoice(string $currency, Carbon $createdAt): int
    {
        return DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-HR214-'.Str::upper(Str::random(6)),
            'customer_id' => DB::table('customers')->insertGetId([
                'name' => 'HR214 Customer', 'created_at' => now(), 'updated_at' => now(),
            ]),
            'invoice_date' => $createdAt->toDateString(),
            'due_date' => $createdAt->copy()->addDays(30)->toDateString(),
            'subtotal' => 100, 'tax_amount' => 0, 'discount_amount' => 0,
            'total' => 100, 'paid_amount' => 0, 'balance' => 100,
            'status' => 'sent', 'lifecycle_status' => 'posted',
            'currency_code' => $currency,
            'version_number' => 1, 'is_active_version' => true,
            'created_by' => $this->user->id,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // #214 — provenance audit
    // ─────────────────────────────────────────────────────────────────────

    public function test_audit_flags_documents_created_before_the_last_settings_change(): void
    {
        $settings = CompanySettings::create(['base_currency' => 'USD']);
        // Settings were changed "now"; force updated_at to a known point.
        DB::table('company_settings')->where('id', $settings->id)->update(['updated_at' => now()->subDays(5)]);

        $old = $this->makeInvoice('USD', now()->subDays(20));   // before settings change
        $new = $this->makeInvoice('USD', now()->subDay());      // after settings change
        $nonBase = $this->makeInvoice('SGD', now()->subDays(20));

        Artisan::call('finance:audit-currency-provenance');
        $output = Artisan::output();

        $this->assertStringContainsString('provenance unverifiable', $output);
        $this->assertStringContainsString('invoices: 1 row(s)', $output);
        $this->assertStringContainsString('#'.$old, $output);
        $this->assertStringNotContainsString('#'.$new, $output, 'Documents created after the settings change are verifiable');
        $this->assertStringNotContainsString('#'.$nonBase, $output, 'Non-base-currency documents are not ambiguous');
    }

    public function test_audit_reports_clean_when_nothing_predates_the_settings_change(): void
    {
        $settings = CompanySettings::create(['base_currency' => 'USD']);
        DB::table('company_settings')->where('id', $settings->id)->update(['updated_at' => now()->subDays(30)]);

        $this->makeInvoice('USD', now()->subDay());

        Artisan::call('finance:audit-currency-provenance');

        $this->assertStringContainsString('provenance clean', Artisan::output());
    }

    // ─────────────────────────────────────────────────────────────────────
    // #215 — seeder inserts stamp the currency explicitly
    // ─────────────────────────────────────────────────────────────────────

    public function test_sample_transactions_seeder_stamps_currency_on_raw_inserts(): void
    {
        // Real run of the seeder against a provisioned tenant: every seeded
        // invoice/bill must carry an explicit currency_code (the Eloquent
        // creating hook cannot fire for DB::table() inserts).
        CompanySettings::create(['base_currency' => 'USD']);

        $account = fn (string $code, string $type) => \App\Models\ChartOfAccount::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => "Account {$code}",
                'type' => $type,
                'subtype' => $type === 'expense' ? 'operating_expense' : 'current_asset',
                'is_active' => true,
            ]
        );

        $account('1010', 'asset');
        $account('1020', 'asset');
        $account('3000', 'revenue');
        $account('5000', 'expense');
        $account('2100', 'liability');

        DB::table('journals')->insert([
            'code' => 'GEN',
            'name' => 'General',
            'type' => 'general',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \App\Models\Customer::factory()->create(['code' => 'HR214-C1']);
        \App\Models\Customer::factory()->create(['code' => 'HR214-C2']);
        \App\Models\Vendor::factory()->create(['code' => 'HR214-V1']);
        \App\Models\Vendor::factory()->create(['code' => 'HR214-V2']);
        \App\Models\Product::factory()->create(['sku' => 'HR214-P1', 'track_inventory' => false]);
        \App\Models\Product::factory()->create(['sku' => 'HR214-P2', 'track_inventory' => false]);

        Artisan::call('db:seed', ['--class' => \Database\Seeders\SampleTransactionsSeeder::class, '--force' => true]);

        $this->assertSame(0, DB::table('invoices')->whereNull('currency_code')->count(), 'Seeded invoices must carry currency_code');
        $this->assertSame(0, DB::table('bills')->whereNull('currency_code')->count(), 'Seeded bills must carry currency_code');
        $this->assertGreaterThan(0, DB::table('invoices')->count());
        $this->assertSame('USD', DB::table('invoices')->value('currency_code'));
    }
}
