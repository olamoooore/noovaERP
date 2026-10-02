<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxCountryPackService;
use App\Services\TaxPackRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #743 T10: registration thresholds are pack DATA — effective-dated,
 * categorised, sourced and flagged for verification — not a constant baked
 * into the service. The pack mutations in this test are test-local config
 * changes; no statutory value or date is asserted as fact here (the shipped
 * rows are marked verified:false pending the tax agent / #735).
 */
class TaxThresholdPackDataTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@thresholdpack.test']);
        Auth::login($user);
        $user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_thresholds_are_pack_data_not_a_service_constant(): void
    {
        $constants = (new ReflectionClass(TaxCountryPackService::class))->getConstants();
        $this->assertArrayNotHasKey('THRESHOLDS', $constants, 'Threshold definitions must move to the pack.');

        foreach (glob(app_path('Services/*.php')) as $file) {
            $this->assertStringNotContainsString('THRESHOLDS', (string) file_get_contents($file), basename($file).' still defines threshold data in code.');
        }
    }

    public function test_service_reports_pack_defined_thresholds_with_effective_dating(): void
    {
        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $existing = (array) config('tax_packs.SG.thresholds');
        config(['tax_packs.SG.thresholds' => array_merge($existing, [
            [
                'key' => 'test_effective_today', 'label' => 'Test effective now', 'amount' => 123.0,
                'currency' => 'SGD', 'category' => 'all', 'test' => 'backward',
                'effective_from' => $today, 'effective_to' => null,
                'source' => 'test fixture', 'verified' => false, 'note' => 'test-only',
            ],
            [
                'key' => 'test_not_yet_effective', 'label' => 'Test future', 'amount' => 456.0,
                'currency' => 'SGD', 'category' => 'all', 'test' => 'forward',
                'effective_from' => $tomorrow, 'effective_to' => null,
                'source' => 'test fixture', 'verified' => false, 'note' => 'test-only',
            ],
        ])]);

        $rows = collect(app(TaxCountryPackService::class)->thresholds('SG')['SG']['thresholds']);
        $keys = $rows->pluck('key')->all();

        $this->assertContains('test_effective_today', $keys, 'The service must report thresholds from the pack, effective today.');
        $this->assertNotContains('test_not_yet_effective', $keys, 'A threshold not yet effective on the date must be omitted.');
    }

    public function test_each_shipped_threshold_row_carries_category_test_and_source(): void
    {
        $registry = app(TaxPackRegistry::class);

        foreach (['SG', 'MY'] as $jurisdiction) {
            $rows = $registry->thresholds($jurisdiction);
            $this->assertNotEmpty($rows, "{$jurisdiction} must ship threshold data.");

            foreach ($rows as $row) {
                $this->assertNotEmpty($row['key'] ?? null);
                $this->assertIsNumeric($row['amount'] ?? null, "{$jurisdiction}/{$row['key']} needs an amount.");
                $this->assertNotEmpty($row['currency'] ?? null);
                $this->assertNotEmpty($row['category'] ?? null, 'AC: thresholds are keyed by category/sector.');
                $this->assertContains($row['test'] ?? null, ['backward', 'forward', 'both'], 'AC: a threshold declares which test it is.');
                $this->assertNotEmpty($row['source'] ?? null, 'AC: each threshold carries a source reference.');
                $this->assertIsBool($row['verified'] ?? null, 'Each threshold must declare its verification state.');
            }
        }
    }

    public function test_endpoint_exposes_pack_thresholds_with_provenance(): void
    {
        $response = $this->getJson('/api/v1/accounting/tax-engine/thresholds?jurisdiction=SG')->assertOk();

        $row = $response->json('data.SG.thresholds.0');
        $this->assertNotNull($row, 'The endpoint must report the pack threshold rows.');
        $this->assertSame('SGD', $row['currency']);
        $this->assertNotEmpty($row['source']);
        $this->assertFalse((bool) $row['verified'], 'Shipped thresholds are unverified until the tax agent confirms them.');
        $this->assertNotEmpty($row['category']);
    }
}
