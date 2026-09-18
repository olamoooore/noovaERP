<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #90 (BLOCKER, RBAC-1) — Real HTTP verification that accounting reversal
 * routes require `accounting.reverse` permission.
 *
 * Any authenticated user without the permission must receive 403, not 200/422/500.
 * Owners, Admins, Managers, and Accountants must pass through to the controller
 * (the controller may still return 422 for missing fields, but never 403).
 */
class HR90ReversalPermissionGuardTest extends TenantTestCase
{
    private string $tenantId;

    private int $viewerUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        // Ensure Viewer role exists (the role that should be blocked).
        Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web']);
    }

    /**
     * Authenticate as a Viewer-role user. Viewer is NOT in the
     * accounting.reverse permission list.
     */
    private function actingAsViewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Viewer');
        Auth::login($user);

        return $user;
    }

    public function test_viewer_cannot_call_full_reversal(): void
    {
        $this->actingAsViewer();

        $response = $this->postJson('/api/v1/accounting/reversals/full', [
            'journal_entry_id' => 1,
            'reason_code' => 'TEST_HR90',
            'reason_text' => 'test',
            'user_id' => 1,
        ]);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'Viewer must NOT be able to call full reversal — got '.$response->getStatusCode()
        );
    }

    public function test_viewer_cannot_call_partial_reversal(): void
    {
        $this->actingAsViewer();

        $response = $this->postJson('/api/v1/accounting/reversals/partial', [
            'journal_entry_id' => 1,
            'amount' => 100,
            'reason_code' => 'TEST_HR90',
            'reason_text' => 'test',
            'user_id' => 1,
        ]);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'Viewer must NOT be able to call partial reversal'
        );
    }

    public function test_viewer_cannot_call_schedule_auto_reversal(): void
    {
        $this->actingAsViewer();

        $response = $this->postJson('/api/v1/accounting/reversals/schedule-auto', [
            'journal_entry_id' => 1,
            'reason_code' => 'TEST_HR90',
            'reason_text' => 'test',
            'user_id' => 1,
        ]);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'Viewer must NOT be able to call schedule-auto reversal'
        );
    }

    public function test_viewer_cannot_call_execute_due_auto_reversals(): void
    {
        $this->actingAsViewer();

        $response = $this->postJson('/api/v1/accounting/reversals/execute-due', []);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'Viewer must NOT be able to call execute-due reversal'
        );
    }

    public function test_routes_have_accounting_reverse_middleware(): void
    {
        // Confirm the route group is correctly wired at the framework level.
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->map(fn ($r) => [
                'uri' => $r->uri(),
                'methods' => $r->methods(),
                'middleware' => $r->middleware(),
            ])
            ->filter(fn ($r) => str_contains($r['uri'], 'accounting/reversals'));

        $this->assertGreaterThan(0, $routes->count(), 'Reversal routes must be registered');

        foreach ($routes as $r) {
            $hasGuard = collect($r['middleware'])->contains(function ($mw) {
                $str = is_object($mw) ? serialize($mw) : (string) $mw;

                return str_contains($str, 'accounting.reverse');
            });
            $this->assertTrue(
                $hasGuard,
                "Route {$r['uri']} must require accounting.reverse permission"
            );
        }
    }
}
