<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ImpersonationMiddleware;
use App\Http\Middleware\SetTenantFromUserPreference;
use App\Models\Impersonation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantSuspensionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issues #217 / #218 / #219 / #220 (regressions from the #173 impersonation
 * wiring fix):
 *  #217 — a valid token presented by anyone was honored (no actor check)
 *  #218 — SetTenantFromUserPreference re-resolved the swapped user's default
 *         tenant, overriding the impersonated tenant
 *  #219 — the #172 suspension gate blocked legitimate support impersonation
 *  #220 — FinancialClose / AccountingFoundation / FinancialReporting route
 *         groups never ran ImpersonationMiddleware (silent no-op)
 */
class HR217220ImpersonationHardeningTest extends TenantTestCase
{
    private function makeUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    private function makeImpersonation(User $actor, User $target, string $tenantId, string $rawToken): Impersonation
    {
        return Impersonation::create([
            'actor_id' => $actor->id,
            'target_user_id' => $target->id,
            'tenant_id' => $tenantId,
            'token' => hash('sha256', $rawToken),
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'status' => 'active',
        ]);
    }

    private function probeRoute(): void
    {
        Route::middleware([ImpersonationMiddleware::class, SetTenantFromUserPreference::class])
            ->get('/__hr217_probe', fn () => response()->json([
                'auth_id' => auth()->id(),
                'tenant_id' => function_exists('tenant') && tenant() ? tenant('id') : null,
            ]));
    }

    // ─────────────────────────────────────────────────────────────────────
    // #217 — presenter must be the actor or a super-admin
    // ─────────────────────────────────────────────────────────────────────

    public function test_token_presented_by_a_non_actor_is_rejected(): void
    {
        $this->bindTenantContext((string) Str::uuid());
        $this->probeRoute();

        $actor = $this->makeUser();
        $target = $this->makeUser();
        $stranger = $this->makeUser();

        $impersonation = $this->makeImpersonation($actor, $target, (string) Str::uuid(), 'leaked-token');

        $response = $this->actingAs($stranger)->withHeader('X-Impersonation-Token', 'leaked-token')
            ->getJson('/__hr217_probe');

        $response->assertStatus(403);
        $this->assertNotSame($target->id, $response->json('auth_id'), 'The swap must not have happened');
        $this->assertTrue($impersonation->fresh()->isActive());
    }

    public function test_token_presented_by_the_actor_is_honored(): void
    {
        $tenantId = (string) Str::uuid();
        $this->bindTenantContext($tenantId);
        $this->probeRoute();

        $actor = $this->makeUser();
        $target = $this->makeUser();
        $this->makeImpersonation($actor, $target, $tenantId, 'actor-token');

        $response = $this->actingAs($actor)->withHeader('X-Impersonation-Token', 'actor-token')
            ->getJson('/__hr217_probe');

        $response->assertStatus(200);
        $this->assertSame($target->id, $response->json('auth_id'), 'The actor must be swapped into the target user');
    }

    // ─────────────────────────────────────────────────────────────────────
    // #218 — impersonated tenant wins over the swapped user's default
    // ─────────────────────────────────────────────────────────────────────

    public function test_impersonated_tenant_is_not_overridden_by_the_targets_default(): void
    {
        $impersonatedTenant = (string) Str::uuid();
        $defaultTenant = (string) Str::uuid();

        $this->bindTenantContext($impersonatedTenant);
        $this->probeRoute();

        $actor = $this->makeUser();
        $target = $this->makeUser();

        // The target's default company is a DIFFERENT tenant.
        DB::table('public.tenants')->updateOrInsert(
            ['id' => $defaultTenant],
            ['name' => 'Default Co', 'email' => 'default-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('public.user_company_access')->insert([
            'user_id' => $target->id,
            'tenant_id' => $defaultTenant,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->makeImpersonation($actor, $target, $impersonatedTenant, 'tenant-token');

        $response = $this->actingAs($actor)->withHeader('X-Impersonation-Token', 'tenant-token')
            ->getJson('/__hr217_probe');

        $response->assertStatus(200);
        $this->assertSame(
            $impersonatedTenant,
            $response->json('tenant_id'),
            'The impersonated tenant must not be replaced by the target user\'s default company'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // #219 — verified support impersonation works on a suspended tenant
    // ─────────────────────────────────────────────────────────────────────

    public function test_impersonation_into_a_suspended_tenant_is_allowed_for_the_actor(): void
    {
        $tenantId = (string) Str::uuid();
        $this->bindTenantContext($tenantId);
        $this->probeRoute();

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $tenantId],
            ['name' => 'Suspended Co', 'email' => 'susp-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );
        TenantSuspensionGuard::mergeTenantData($tenantId, ['suspended' => true]);

        $actor = $this->makeUser();
        $target = $this->makeUser();
        $this->makeImpersonation($actor, $target, $tenantId, 'support-token');

        $response = $this->actingAs($actor)->withHeader('X-Impersonation-Token', 'support-token')
            ->getJson('/__hr217_probe');

        $response->assertStatus(200, 'Verified support impersonation must reach a suspended tenant: '.$response->getContent());
        $this->assertSame($target->id, $response->json('auth_id'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // #220 — module route groups actually run the middleware
    // ─────────────────────────────────────────────────────────────────────

    public function test_module_route_groups_include_impersonation_middleware(): void
    {
        $prefixes = ['api/v1/close', 'api/v1/accounting-foundation', 'api/v1/reports'];

        foreach ($prefixes as $prefix) {
            $routes = collect(Route::getRoutes()->getRoutes())
                ->filter(fn ($route) => str_starts_with($route->uri(), $prefix));

            $this->assertNotEmpty($routes, "No routes found for {$prefix}");

            foreach ($routes as $route) {
                $this->assertContains(
                    ImpersonationMiddleware::class,
                    $route->gatherMiddleware(),
                    "Route {$route->uri()} must run ImpersonationMiddleware (issue #220)"
                );
            }
        }
    }
}
