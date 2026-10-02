<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxDeterminationService;
use App\Services\TaxEngineService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #744 T11: country facts live in ONE place — the country packs. Regime,
 * currency and rates are served by TaxPackRegistry; no PHP service constant
 * defines a tax rate any more, and country_defaults is derived from the
 * pack's rules effective on the requested date (so MY service tax reads 8%
 * for a 2025-08 document and 6% for a 2025-01 document, not one frozen value).
 */
class TaxPackRegistryTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@registry.test']);
        Auth::login($user);
        $user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_country_defaults_are_derived_from_the_pack_for_the_requested_date(): void
    {
        $engine = app(TaxEngineService::class);

        $sg = $engine->countryDefaults('SG', '2025-08-15');
        $this->assertSame('GST', $sg['regime']);
        $this->assertSame('SGD', $sg['currency']);
        $this->assertSame(9.0, $sg['rates']['gst']);

        $my2025 = $engine->countryDefaults('MY', '2025-08-15');
        $this->assertSame('SST', $my2025['regime']);
        $this->assertSame('MYR', $my2025['currency']);
        $this->assertSame(10.0, $my2025['rates']['sales']);
        $this->assertSame(8.0, $my2025['rates']['service']);

        // The load-bearing assertion: the same pack yields the rate effective
        // on the requested date — a frozen constant cannot do this.
        $myEarly = $engine->countryDefaults('MY', '2025-01-15');
        $this->assertSame(6.0, $myEarly['rates']['service'], 'MY service tax must read the rate effective on the requested date.');
    }

    public function test_country_facts_for_a_non_pack_country_come_from_the_registry_not_a_service_constant(): void
    {
        $defaults = app(TaxEngineService::class)->countryDefaults('US');

        $this->assertSame('SALES_TAX', $defaults['regime']);
        $this->assertSame([], $defaults['rates'], 'A jurisdiction with no pack ships no rate.');
    }

    public function test_rules_endpoint_reports_pack_derived_defaults_for_the_requested_date(): void
    {
        $response = $this->getJson('/api/v1/accounting/tax-engine/rules?jurisdiction=MY&date=2025-01-15')->assertOk();

        $this->assertSame('MY', $response->json('data.jurisdiction'));
        $this->assertSame('SST', $response->json('data.country_defaults.regime'));
        $this->assertSame(6.0, (float) $response->json('data.country_defaults.rates.service'));
    }

    public function test_no_php_constant_outside_the_pack_defines_a_tax_rate(): void
    {
        $engineConstants = (new ReflectionClass(TaxEngineService::class))->getConstants();
        $this->assertArrayNotHasKey('COUNTRY_DEFAULTS', $engineConstants, 'COUNTRY_DEFAULTS must be removed — country facts come from the pack registry.');

        $determinationConstants = (new ReflectionClass(TaxDeterminationService::class))->getConstants();
        $this->assertArrayNotHasKey('COUNTRY_REGIME', $determinationConstants);
        $this->assertArrayNotHasKey('COUNTRY_JURISDICTION', $determinationConstants);

        // And no service constant may carry a numeric rate at all.
        foreach ([TaxEngineService::class, TaxDeterminationService::class] as $class) {
            foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
                $this->assertFalse(
                    $this->containsNumber($value),
                    "Constant {$class}::{$name} carries a numeric rate — rates belong in the pack.",
                );
            }
        }

        // No service source mentions the retired constants either.
        foreach (glob(app_path('Services/*.php')) as $file) {
            $source = (string) file_get_contents($file);
            foreach (['COUNTRY_DEFAULTS', 'COUNTRY_REGIME', 'COUNTRY_JURISDICTION'] as $retired) {
                $this->assertStringNotContainsString($retired, $source, basename($file)." still references {$retired}.");
            }
        }
    }

    private function containsNumber(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsNumber($item)) {
                    return true;
                }
            }

            return false;
        }

        return is_int($value) || is_float($value);
    }
}
