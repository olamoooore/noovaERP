<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #771 (A11) — the retired `tax_rates` table now has no writers (the
 * country seeders stopped inserting), and the legacy migration must never
 * carry the seeder-created rows (wrong tax types, unsourced WHT rates) into
 * the live engine.
 */
class MigrateLegacyTaxRatesSkipSeederRowsTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContext((string) Str::uuid());
        $this->assertTenantSchemaReady(['tax_rates', 'taxes']);
    }

    private int $taxAccountId;

    private function legacyRate(string $code, string $name): void
    {
        DB::table('tax_rates')->insert([
            'name' => $name,
            'code' => $code,
            'rate' => 15.0,
            'type' => 'withholding',
            'description' => 'legacy fixture',
            'tax_account_id' => $this->taxAccountId,
            'is_compound' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_migration_skips_seeder_created_rows_and_migrates_others(): void
    {
        $this->taxAccountId = ChartOfAccount::factory()->create(['code' => 'MLR-TAX-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true])->id;

        $this->legacyRate('MY-WHT15', 'Withholding Tax 15% (MY) — seeder row');
        $this->taxAccountId = ChartOfAccount::factory()->create(['code' => 'MLR-TAX-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true])->id;

        $this->legacyRate('CUSTOM-WHT-'.Str::random(4), 'Hand-made legacy row');

        Artisan::call('taxes:migrate-legacy-rates', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('SKIPPED (seeder-created legacy row', $output);
        $this->assertStringNotContainsString('would create: MY-WHT15', $output, 'seeder-created rows must never be migrated');
        $this->assertStringContainsString('would create: CUSTOM-WHT-', $output, 'genuine legacy rows still migrate');

        $this->assertSame(0, DB::table('taxes')->where('code', 'MY-WHT15')->count());
    }
}
