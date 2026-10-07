<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 step 0: the three contact reports are run on production
 * WITHOUT --tenant (every tenant through IteratesTenants). This exercises
 * that path: it must finish cleanly, write a JSON array and change nothing.
 *
 * Limitation (recorded in the backfill plan): test tenants have no real
 * schema, so IteratesTenants skips them — a schema-backed run is covered
 * only by the single-tenant tests.
 */
class ContactReportsAllTenantsTest extends TenantTestCase
{
    public function test_reports_run_for_all_tenants_without_writing(): void
    {
        $this->bindTenantContextWithPermissions((string) Str::uuid());
        foreach (range(1, 2) as $n) {
            Tenant::withoutEvents(fn () => Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => 'All Tenants Report '.$n,
                'email' => 'all-tenants-'.$n.'-'.uniqid().'@example.test',
                'plan' => 'standard',
            ]));
        }

        $before = [
            'tenants' => DB::table('public.tenants')->count(),
            'customers' => DB::table('customers')->count(),
            'contact_addresses' => DB::table('contact_addresses')->count(),
            'core_contacts' => DB::table('core_contacts')->count(),
        ];

        foreach (['contacts:address-backfill-report', 'contacts:linking-report', 'contacts:person-merge-damage-report'] as $command) {
            $path = tempnam(sys_get_temp_dir(), 'all-tenants-');
            $this->artisan($command, ['--json' => $path, '--redact' => true])->assertExitCode(0);
            $this->assertIsArray(json_decode((string) file_get_contents($path), true), "{$command} must write a JSON array.");
            @unlink($path);
        }

        $this->assertSame($before, [
            'tenants' => DB::table('public.tenants')->count(),
            'customers' => DB::table('customers')->count(),
            'contact_addresses' => DB::table('contact_addresses')->count(),
            'core_contacts' => DB::table('core_contacts')->count(),
        ]);
    }
}
