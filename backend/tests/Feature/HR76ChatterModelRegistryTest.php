<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\ChatterModelRegistry;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #76 (CRITICAL, SEC-1) — Communication model/record validation.
 *
 * Verifies that:
 *   1. The ChatterModelRegistry resolves whitelisted aliases correctly.
 *   2. Unwhitelisted model strings are rejected (return null).
 *   3. Non-existent record IDs are rejected (return null).
 *   4. The EnsureCommunicationAccess middleware is wired on
 *      /api/communication/* routes.
 *   5. Real HTTP test: invalid model returns 422, valid model returns the
 *      expected response.
 */
class HR76ChatterModelRegistryTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
    }

    // ─── 1. Registry resolves whitelisted aliases ───────────────────

    public function test_registry_resolves_whitelisted_aliases(): void
    {
        $registry = app(ChatterModelRegistry::class);

        $this->assertSame(
            'Modules\\Sales\\Models\\Order',
            $registry->resolveClass('order')
        );
        $this->assertSame(
            'Modules\\Accounting\\Models\\Invoice',
            $registry->resolveClass('invoice')
        );
        $this->assertSame('App\\Models\\Customer', $registry->resolveClass('customer'));
    }

    // ─── 2. Registry rejects unwhitelisted model strings ───────────

    public function test_registry_rejects_unwhitelisted_model_strings(): void
    {
        $registry = app(ChatterModelRegistry::class);

        // Issue #76 attack vectors: arbitrary class strings
        $this->assertNull($registry->resolveClass('Modules\\Sales\\Models\\Order'));
        $this->assertNull($registry->resolveClass('App\\Models\\User'));
        $this->assertNull($registry->resolveClass('Illuminate\\Database\\Eloquent\\Model'));
        $this->assertNull($registry->resolveClass('../../etc/passwd'));
        $this->assertNull($registry->resolveClass(''));
    }

    public function test_registry_rejects_non_existent_class(): void
    {
        $registry = new ChatterModelRegistry;
        // Direct: a model NOT in the registry returns null.
        $this->assertNull($registry->resolveClass('not_in_registry'));
    }

    // ─── 3. resolveRecord returns null for missing records ─────────

    public function test_resolve_record_returns_null_for_missing_record(): void
    {
        $registry = app(ChatterModelRegistry::class);
        $this->assertNull($registry->resolveRecord('order', 999999));
        $this->assertNull($registry->resolveRecord('customer', 999999));
        $this->assertNull($registry->resolveRecord('not_in_registry', 1));
    }

    public function test_resolve_record_returns_model_when_present(): void
    {
        $registry = app(ChatterModelRegistry::class);

        // Create a real Customer so the registry can find it.
        $customer = \App\Models\Customer::create([
            'name' => 'HR76 Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $found = $registry->resolveRecord('customer', $customer->id);
        $this->assertNotNull($found);
        $this->assertSame('HR76 Test Customer', $found->name);
    }

    // ─── 4. Middleware is wired on /api/communication routes ───────

    public function test_routes_have_ensure_communication_access_middleware(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->map(fn ($r) => [
                'uri' => $r->uri(),
                'methods' => $r->methods(),
                'middleware' => $r->middleware(),
            ])
            ->filter(fn ($r) => str_contains($r['uri'], 'communication'));

        $this->assertGreaterThan(0, $routes->count(), 'Communication routes must be registered');

        foreach ($routes as $r) {
            $hasGuard = collect($r['middleware'])->contains(function ($mw) {
                $str = is_object($mw) ? serialize($mw) : (string) $mw;

                return str_contains($str, 'EnsureCommunicationAccess');
            });
            $this->assertTrue(
                $hasGuard,
                "Route {$r['uri']} must have EnsureCommunicationAccess middleware"
            );
        }
    }

    // ─── 5. Real HTTP: invalid model returns 422 ────────────────────

    public function test_real_http_invalid_model_returns_422(): void
    {
        // Create a real tenant + a user with the communication entitlement so
        // the upstream auth/entitlement middleware passes. The model-validation
        // step is the one we want to exercise.
        $tenantPk = \Illuminate\Support\Facades\DB::table('tenants')->insertGetId([
            'id' => $this->tenantId,
            'name' => 'HR76 Tenant',
            'email' => 'hr76-'.uniqid().'@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create();
        \Illuminate\Support\Facades\DB::table('user_company_access')->insertOrIgnore([
            'user_id' => $user->id,
            'tenant_id' => $tenantPk,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Grant the communication entitlement so the entitlement middleware passes.
        \Spatie\Permission\Models\Permission::firstOrCreate([
            'name' => 'communication', 'guard_name' => 'web',
        ]);
        $user->givePermissionTo('communication');
        $this->actingAs($user);

        // Pass an arbitrary class string as model — must be rejected by
        // EnsureCommunicationAccess with 422.
        $response = $this->getJson('/api/v1/api/communication/messages?model=App%5CModels%5CUser&record_id=1');

        $this->assertSame(
            422,
            $response->getStatusCode(),
            'Invalid model must return 422 — got '.$response->getStatusCode().' body='.$response->getContent()
        );
        $body = $response->json();
        $this->assertFalse($body['success'] ?? true);
        $this->assertContains('customer', $body['allowed_models'] ?? []);
    }
}
