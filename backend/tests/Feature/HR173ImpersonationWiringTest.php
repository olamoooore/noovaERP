<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Impersonation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #173 (CRITICAL) — SuperAdmin impersonation was completely
 * non-functional because ImpersonationMiddleware was never registered on any
 * route. The only existing test was a source-string check that passed even
 * though the middleware was dead.
 *
 * Fix: ImpersonationMiddleware is now wired into the tenant route group in
 * routes/tenant.php, after auth:sanctum and before SetTenantFromUserPreference.
 *
 * These tests are REAL HTTP tests through the router (not source-string
 * checks), per the issue's required fix #2:
 *  - an invalid X-Impersonation-Token must now be actively rejected (proving
 *    the middleware runs — before the fix the header was silently ignored);
 *  - a valid active token must pass the middleware's token lookup;
 *  - the tenant route group must actually include the middleware (route
 *    inspection, not source text).
 */
class HR173ImpersonationWiringTest extends TenantTestCase
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
            ['name' => 'HR173 Tenant', 'email' => 'hr173-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );
        User::factory()->create();
    }

    private function makeUser(): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('public.user_company_access')->where('user_id', $centralId)->delete();
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'owner', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole('Owner');

        return $user;
    }

    // ─── The middleware is really registered on the tenant route group ──

    public function test_impersonation_middleware_is_registered_on_tenant_routes(): void
    {
        $found = false;
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }
            foreach ($route->middleware() as $mw) {
                if (str_contains((string) $mw, 'ImpersonationMiddleware')) {
                    $found = true;
                    break 2;
                }
            }
        }

        $this->assertTrue(
            $found,
            'ImpersonationMiddleware must be attached to the tenant route group (routes/tenant.php)'
        );
    }

    // ─── Invalid token is actively rejected (proves the middleware runs) ──

    public function test_invalid_impersonation_token_is_rejected(): void
    {
        $user = $this->makeUser();

        // Before the fix this header was silently ignored (the middleware was
        // never wired). Now the middleware must run and reject it with 401.
        $response = $this->actingAs($user)
            ->withHeaders(['X-Impersonation-Token' => 'this-is-not-a-valid-token'])
            ->getJson('/api/v1/accounting/customers');

        $this->assertSame(401, $response->getStatusCode(), 'Invalid impersonation token must be rejected: '.$response->getContent());
        $this->assertStringContainsString('Invalid or expired impersonation token', $response->json('message'));
    }

    // ─── Valid active token passes the middleware's lookup ──────────

    public function test_valid_active_token_passes_the_middleware_lookup(): void
    {
        $user = $this->makeUser();

        $rawToken = Str::random(64);
        Impersonation::create([
            'actor_id' => $user->id,
            'target_user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'token' => hash('sha256', $rawToken),
            'started_at' => now(),
            'expires_at' => now()->addMinutes(60),
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)
            ->withHeaders(['X-Impersonation-Token' => $rawToken])
            ->getJson('/api/v1/accounting/customers');

        // Must NOT be the "Invalid or expired impersonation token" rejection —
        // the token lookup succeeded and the middleware proceeded.
        $body = json_decode($response->getContent(), true);
        $message = (string) ($body['message'] ?? '');
        $this->assertStringNotContainsString(
            'Invalid or expired impersonation token',
            $message,
            'A valid active token must pass the middleware lookup'
        );
    }

    // ─── Expired token is rejected ──────────────────────────────────

    public function test_expired_token_is_rejected(): void
    {
        $user = $this->makeUser();

        $rawToken = Str::random(64);
        Impersonation::create([
            'actor_id' => $user->id,
            'target_user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'token' => hash('sha256', $rawToken),
            'started_at' => now()->subHours(2),
            'expires_at' => now()->subHour(),
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)
            ->withHeaders(['X-Impersonation-Token' => $rawToken])
            ->getJson('/api/v1/accounting/customers');

        $this->assertSame(401, $response->getStatusCode(), 'Expired token must be rejected');
        $this->assertStringContainsString('Invalid or expired impersonation token', $response->json('message'));
    }

    // ─── No header = no impersonation (normal request unaffected) ───

    public function test_request_without_impersonation_header_is_unaffected(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->getJson('/api/v1/accounting/customers');

        $this->assertNotSame(401, $response->getStatusCode(), 'Normal request must not be rejected by impersonation middleware: '.$response->getContent());
    }
}
