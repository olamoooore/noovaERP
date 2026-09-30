<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\DocumentCurrencyResolver;
use App\Services\ExchangeRateService;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * MRD pricelist-multicurrency-mrd FR-1 — custom (manual) rate setup.
 *
 * Verifies the "custom rate setup works as it should" contract end to end:
 *   FR-1.4  manual rows win over API rows for the same pair+date;
 *   FR-1.4  the nightly sync never clobbers a manual row (coexists);
 *   FR-1.6  inverse resolution with 6-decimal precision;
 *   FR-1.5  staleness flagged but non-blocking;
 *   FR-9.2  future-dated rates only ever resolve from MANUAL rows;
 *   AC-3    missing rates raise a distinct, actionable error;
 *   FR-1.7  deleting a rate never touches document snapshots;
 *   FR-1.1  non-sellable currencies are rejected;
 *           the manual store endpoint is deterministic (upsert semantics).
 */
class ExchangeRateCustomSetupTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) \Illuminate\Support\Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'FX Setup Tenant',
            'email' => 'fx-'.substr(md5($this->tenantId), 0, 8).'@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = $this->setUpTenantAdminUser('FX Setup Tenant');
        CompanySettings::query()->create(['base_currency' => 'SGD']);
    }

    private function service(): ExchangeRateService
    {
        return app(ExchangeRateService::class);
    }

    public function test_manual_row_wins_over_api_row_for_the_same_date(): void
    {
        // FR-1.4 — API row first, manual override recorded same day.
        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'SGD', 'rate' => 1.3500,
            'effective_date' => '2026-09-29', 'source' => 'api',
        ]);
        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'SGD', 'rate' => 1.3527,
            'effective_date' => '2026-09-29', 'source' => 'manual',
        ]);

        $rate = $this->service()->getLatestRate('USD', 'SGD', '2026-09-30');

        $this->assertSame(1.3527, round((float) $rate['rate'], 4), 'manual override must win');
        $this->assertSame('manual', $rate['source']);
        $this->assertNotNull($rate['rate_id'], 'rate id surfaced for the pricing trace (FR-3.9)');
    }

    public function test_later_api_date_still_beats_older_manual_date(): void
    {
        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'SGD', 'rate' => 1.30,
            'effective_date' => '2026-09-27', 'source' => 'manual',
        ]);
        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'SGD', 'rate' => 1.36,
            'effective_date' => '2026-09-29', 'source' => 'api',
        ]);

        $rate = $this->service()->getLatestRate('USD', 'SGD', '2026-09-30');
        $this->assertSame(1.36, round((float) $rate['rate'], 4), 'manual precedence is a same-DATE tie-break, not a blanket override');
    }

    public function test_sync_never_clobbers_manual_and_coexists_under_unique_indexes(): void
    {
        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'SGD', 'rate' => 1.3527,
            'effective_date' => now()->toDateString(), 'source' => 'manual',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'v6.exchangerate-api.com/*' => \Illuminate\Support\Facades\Http::response([
                'result' => 'success',
                'conversion_rates' => ['USD' => 1.0, 'SGD' => 1.3700, 'MYR' => 4.60],
            ]),
            'open.er-api.com/*' => \Illuminate\Support\Facades\Http::response([
                'result' => 'success',
                'rates' => ['SGD' => 1.3700, 'MYR' => 4.60],
            ]),
        ]);

        config(['services.exchange_rate.api_key' => 'test-key']);

        $results = $this->service()->syncRatesFromAPI('USD', ['SGD', 'MYR']);

        $this->assertTrue($results['SGD']['success']);

        $manual = ExchangeRate::where('source', 'manual')->where('to_currency', 'SGD')->first();
        $api = ExchangeRate::where('source', 'api')->where('to_currency', 'SGD')->first();

        $this->assertNotNull($manual, 'manual row must survive the sync');
        $this->assertSame(1.3527, round((float) $manual->rate, 4), 'manual rate untouched by sync');
        $this->assertNotNull($api, 'sync writes its own api row alongside');

        // Read side: manual still wins for today.
        $rate = $this->service()->getLatestRate('USD', 'SGD', now()->toDateString());
        $this->assertSame('manual', $rate['source']);
    }

    public function test_inverse_pair_resolution_with_6dp_precision(): void
    {
        // Only SGD→USD stored; USD→SGD must resolve as the inverse (FR-1.6).
        ExchangeRate::create([
            'from_currency' => 'SGD', 'to_currency' => 'USD', 'rate' => 0.74,
            'effective_date' => '2026-09-25', 'source' => 'api',
        ]);

        $rate = $this->service()->getLatestRate('USD', 'SGD', '2026-09-26');

        $this->assertSame('inverse_api', $rate['source']);
        $this->assertSame(1.351351, round((float) $rate['rate'], 6)); // round(1/0.74, 6)
    }

    public function test_missing_rate_blocks_with_distinct_actionable_error(): void
    {
        $this->expectException(\App\Exceptions\ExchangeRateMissingException::class);
        $this->expectExceptionMessage('Record a manual rate');

        DocumentCurrencyResolver::snapshot('EUR', null, null, '2026-09-30');
    }

    public function test_future_dated_manual_row_is_the_only_post_date_fallback(): void
    {
        // FR-9.2 — nothing at/before the date; a FUTURE manual row may stand
        // in, an API row may never leak backwards.
        ExchangeRate::create([
            'from_currency' => 'EUR', 'to_currency' => 'SGD', 'rate' => 1.4700,
            'effective_date' => now()->addWeek()->toDateString(), 'source' => 'manual',
        ]);

        $snap = DocumentCurrencyResolver::snapshot('EUR', null, null, now()->toDateString());
        $this->assertSame(1.4700, round((float) $snap['exchange_rate'], 4));
        $this->assertSame('manual_future_override', $snap['rate_source']);

        ExchangeRate::where('source', 'manual')->delete();
        ExchangeRate::create([
            'from_currency' => 'EUR', 'to_currency' => 'SGD', 'rate' => 1.4900,
            'effective_date' => now()->addWeek()->toDateString(), 'source' => 'api',
        ]);

        $this->expectException(\App\Exceptions\ExchangeRateMissingException::class);
        DocumentCurrencyResolver::snapshot('EUR', null, null, now()->toDateString());
    }

    public function test_stale_rate_is_flagged_but_not_blocked(): void
    {
        // FR-1.5 — rate 30 days old, default staleness 7 days → warning only.
        ExchangeRate::create([
            'from_currency' => 'GBP', 'to_currency' => 'SGD', 'rate' => 1.85,
            'effective_date' => now()->subDays(30)->toDateString(), 'source' => 'api',
        ]);

        $snap = DocumentCurrencyResolver::snapshot('GBP', null, null, now()->toDateString());

        $this->assertSame(1.85, round((float) $snap['exchange_rate'], 4), 'stale rate still resolves');
        $this->assertTrue($snap['rate_stale'], 'staleness flagged for the warning chip (FR-6.1)');
    }

    public function test_same_currency_snapshot_never_needs_a_rate(): void
    {
        $snap = DocumentCurrencyResolver::snapshot('SGD', null, null, now()->toDateString());

        $this->assertSame(1.0, (float) $snap['exchange_rate']);
        $this->assertSame('SGD', $snap['base_currency_code']);
        $this->assertFalse($snap['rate_stale']);
    }

    public function test_selling_in_disabled_currency_is_rejected(): void
    {
        // FR-1.1
        Currency::query()->where('code', 'AUD')->update(['is_sellable' => false]);

        try {
            DocumentCurrencyResolver::assertSellable('AUD');
            $this->fail('AUD should not be sellable after disabling');
        } catch (\App\Exceptions\CurrencyNotAvailableException $e) {
            $this->assertStringContainsString('not enabled', $e->getMessage());
        }

        // Unknown code rejected too (the legacy resolver blindly uppercased).
        $this->expectException(\App\Exceptions\CurrencyNotAvailableException::class);
        DocumentCurrencyResolver::assertSellable('XXX');
    }

    public function test_duplicate_pair_date_rows_are_swept_by_migration(): void
    {
        // Migration 2026_10_01_000002 deduped + added partial unique indexes.
        // A fresh duplicate insert must now fail at the DB level.
        ExchangeRate::create([
            'from_currency' => 'JPY', 'to_currency' => 'SGD', 'rate' => 0.0072,
            'effective_date' => '2026-09-01', 'source' => 'api',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        ExchangeRate::create([
            'from_currency' => 'JPY', 'to_currency' => 'SGD', 'rate' => 0.0073,
            'effective_date' => '2026-09-01', 'source' => 'api',
        ]);
    }

    public function test_manual_store_endpoint_upserts_one_row_per_pair_date(): void
    {
        $payload = [
            'from_currency' => 'USD',
            'to_currency' => 'SGD',
            'rate' => 1.3500,
            'effective_date' => '2026-09-29',
            'source' => 'manual',
        ];

        $first = $this->actingAs($this->admin)->postJson('/api/v1/accounting/exchange-rates', $payload);
        $first->assertStatus(201);

        $second = $this->actingAs($this->admin)->postJson('/api/v1/accounting/exchange-rates', array_merge($payload, ['rate' => 1.3560]));
        $second->assertStatus(201);

        $rows = ExchangeRate::where('from_currency', 'USD')
            ->where('to_currency', 'SGD')
            ->whereDate('effective_date', '2026-09-29')
            ->where('source', 'manual')
            ->get();

        $this->assertCount(1, $rows, 're-saving an override for the same day updates the single manual row');
        $this->assertSame(1.3560, round((float) $rows->first()->rate, 4));

        // And the resolver immediately sees the corrected custom rate.
        $rate = $this->service()->getLatestRate('USD', 'SGD', '2026-09-29');
        $this->assertSame(1.3560, round((float) $rate['rate'], 4));
    }

    public function test_rate_mutation_requires_currencies_manage(): void
    {
        // AC-7 pattern: real HTTP request through the router.
        $sales = \App\Enums\UserRole::SALES;

        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $sales->value, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($sales->spatieName());

        $this->actingAs($user)->postJson('/api/v1/accounting/exchange-rates', [
            'from_currency' => 'USD', 'to_currency' => 'SGD', 'rate' => 2, 'effective_date' => now()->toDateString(),
        ])->assertForbidden()->assertJsonPath('required_permission', 'currencies.manage');
    }

    public function test_snapshot_immutability_deleting_rate_keeps_document_rate(): void
    {
        // FR-1.7 — historical rate edits/deletes never mutate stamped rows.
        $rate = ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'SGD', 'rate' => 1.35,
            'effective_date' => '2026-09-01', 'source' => 'api',
        ]);

        DB::table('invoices')->insert([
            'invoice_number' => 'IMMUT-1',
            'customer_id' => \App\Models\Customer::factory()->create()->id,
            'invoice_date' => '2026-09-01',
            'due_date' => '2026-10-01',
            'status' => 'draft',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 1000,
            'paid_amount' => 0,
            'balance' => 1000,
            'created_by' => $this->admin->id,
            'currency_code' => 'USD',
            'exchange_rate' => 1.350000,
            'base_currency_code' => 'SGD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rate->delete();

        $invoice = DB::table('invoices')->where('invoice_number', 'IMMUT-1')->first();
        $this->assertSame(1.350000, round((float) $invoice->exchange_rate, 6), 'snapshot survives rate deletion');
    }
}
