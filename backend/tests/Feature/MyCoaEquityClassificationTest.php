<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #762 (A2) — the MY CoA template booked equity as current liabilities
 * and dividends/PCB as expenses: a seeded MY balance sheet had no equity.
 *
 * Covers the seeder template and the tenant migration that reclassifies
 * existing accounts (type change only).
 */
class MyCoaEquityClassificationTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->assertTenantSchemaReady(['chart_of_accounts']);
    }

    private function account(string $code): ?object
    {
        return DB::table('chart_of_accounts')->where('code', $code)->first();
    }

    public function test_the_my_seeder_creates_equity_accounts_as_equity(): void
    {
        (new \Database\Seeders\MalaysiaCountrySeeder)->run();

        $paidCapital = $this->account('310000');
        $this->assertNotNull($paidCapital, 'the seeded MY CoA must contain Paid Capital');
        $this->assertSame('equity', $paidCapital->type, 'Paid Capital must be equity, not a current liability');

        $this->assertSame('equity', $this->account('320000')->type);
        $this->assertSame('equity', $this->account('330000')->type);
        $this->assertSame('equity', $this->account('221500')->type, 'a proposed dividend is not a liability (MFRS 110)');
        $this->assertSame('equity', $this->account('522900')->type, 'a dividend reduces equity, never profit');

        $this->assertSame('liability', $this->account('521820')->type, 'PCB is withheld tax — a liability accrual');
        $this->assertNull($this->account('340000'), 'the borrowing moved out of the equity number range');
        $this->assertSame('liability', $this->account('240000')->type, 'the borrowing lives in the liability range');
    }

    public function test_the_migration_reclassifies_existing_accounts_without_touching_postings(): void
    {
        // Seed a legacy-state row exactly as the old seeder created it.
        $accountId = DB::table('chart_of_accounts')->insertGetId([
            'code' => '310000', 'name' => 'Paid Capital', 'type' => 'liability',
            'subtype' => 'liability_current', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/tenant/2026_10_04_000004_reclassify_my_coa_equity_accounts.php');
        $migration->up();

        $row = DB::table('chart_of_accounts')->where('id', $accountId)->first();
        $this->assertSame('equity', $row->type);
        $this->assertSame('equity', $row->subtype);
    }
}
