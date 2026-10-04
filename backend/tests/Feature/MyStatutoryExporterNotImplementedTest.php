<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\ChartOfAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #764 (A4) — the MY MFRS exporter returned hard-coded zeros with
 * success:true (trial balance even queried a non-existent table), so a tenant
 * could download an all-zero "MFRS balance sheet". It must fail loudly (501)
 * until the shared statutory-statements engine lands.
 *
 * Real HTTP through the router.
 */
class MyStatutoryExporterNotImplementedTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->withMiddleware(
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
        );

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($user);

        // A tenant with real postings: the exporter must never pretend they
        // total zero.
        $cash = ChartOfAccount::factory()->create(['code' => 'MN-CASH-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);
        $revenue = ChartOfAccount::factory()->create(['code' => 'MN-REV-'.Str::upper(Str::random(4)), 'type' => 'revenue', 'is_active' => true]);

        $result = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: '2026-09-15',
            description: 'MN posting',
            lines: [
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $cash->id, debit: 1234.0, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $revenue->id, debit: 0.0, credit: 1234.0),
            ],
            userId: $user->id,
        ));
        $this->assertTrue($result->success, $result->error ?? '');
    }

    public function test_every_my_statutory_report_is_501_not_a_zero_statement(): void
    {
        foreach (['balance_sheet', 'income_statement', 'trial_balance'] as $report) {
            $response = $this->actingAs(auth()->user())
                ->getJson("/api/v1/accounting/compliance/reports/MY/{$report}?as_of_date=2026-09-30&start_date=2026-09-01");

            $response->assertStatus(501, "MY {$report} must be 501 Not implemented");
            $this->assertFalse((bool) $response->json('success'), "MY {$report} must not claim success");
            $this->assertStringContainsString('not implemented', strtolower(json_encode($response->json())), 'the response must say it is not implemented');
        }
    }

    public function test_the_my_xbrl_download_is_501_as_well(): void
    {
        $this->actingAs(auth()->user())
            ->getJson('/api/v1/accounting/compliance/reports/MY/balance_sheet/download?as_of_date=2026-09-30&format=xbrl')
            ->assertStatus(501);
    }
}
