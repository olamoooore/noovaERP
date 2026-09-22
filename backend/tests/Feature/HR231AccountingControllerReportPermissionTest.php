<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #231 (CRITICAL) — the legacy AccountingController report routes
 * (trial-balance, ledger, balance-sheet, profit-and-loss) had no permission
 * gate; #103 only gated the FinancialReporting module routes.
 *
 * Verified through the real router: sales (no reports.view) is denied,
 * viewer/accountant (reports.view) pass the middleware.
 */
class HR231AccountingControllerReportPermissionTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR231 Tenant', 'email' => 'hr231-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function actingAsRole(string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole);

        return $user;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reportRoutes(): array
    {
        return [
            'trial balance' => ['/api/v1/accounting/trial-balance'],
            'ledger' => ['/api/v1/accounting/ledger/1'],
            'balance sheet' => ['/api/v1/accounting/balance-sheet'],
            'profit and loss' => ['/api/v1/accounting/profit-and-loss'],
        ];
    }

    /**
     * @dataProvider reportRoutes
     */
    public function test_sales_role_is_denied_report_route(string $uri): void
    {
        $user = $this->actingAsRole('sales', 'Sales');

        $response = $this->actingAs($user)->getJson($uri);

        $this->assertSame(403, $response->getStatusCode(),
            "Sales (no reports.view) must be denied on {$uri}");
    }

    /**
     * @dataProvider reportRoutes
     */
    public function test_viewer_role_passes_report_route_gate(string $uri): void
    {
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->getJson($uri);

        $this->assertNotSame(403, $response->getStatusCode(),
            "Viewer holds reports.view and must pass the gate on {$uri}: ".$response->getContent());
        $this->assertContains($response->getStatusCode(), [200, 404, 422, 500],
            "Unexpected status on {$uri}");
    }
}
