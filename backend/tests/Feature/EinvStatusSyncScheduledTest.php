<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Plugin\CountryPluginRegistry;
use App\Accounting\Services\ComplianceService;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #666 EI4: the e-invoice status sync is scheduled and runs per tenant and
 * enabled country (so Invalid results and buyer rejection requests are
 * noticed, not left until someone presses sync).
 */
class EinvStatusSyncScheduledTest extends TenantTestCase
{
    public function test_the_sync_command_polls_each_tenants_enabled_countries(): void
    {
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);

        DB::table('acct_country_configurations')->insert([
            'tenant_id' => $tenant->id, 'country_code' => 'MY', 'is_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // A disabled country must not be polled.
        DB::table('acct_country_configurations')->insert([
            'tenant_id' => $tenant->id, 'country_code' => 'SG', 'is_enabled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $fake = new class(app(CountryPluginRegistry::class)) extends ComplianceService
        {
            public array $calls = [];

            public array $scheduledSweepFlags = [];

            public function syncPendingSubmissions(string $countryCode, bool $scheduledSweep = false): int
            {
                $this->calls[] = strtoupper($countryCode);
                $this->scheduledSweepFlags[] = $scheduledSweep;

                return 1;
            }
        };
        $this->app->instance(ComplianceService::class, $fake);

        $this->artisan('compliance:sync-einvoice-status')->assertExitCode(0);

        $this->assertContains('MY', $fake->calls, 'The enabled e-invoice country must be polled.');
        $this->assertNotContains('SG', $fake->calls, 'A disabled country must not be polled.');
        $this->assertTrue(
            in_array(true, $fake->scheduledSweepFlags, true),
            'The scheduled run must sweep with the polling window applied (#666 AC1).'
        );
    }
}
