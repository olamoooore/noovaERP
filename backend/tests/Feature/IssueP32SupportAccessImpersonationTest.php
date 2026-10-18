<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Impersonation;
use App\Models\User;
use App\Services\SecurityIncidentService;
use Tests\TenantTestCase;

/**
 * Pillar 2 Part 1 §1 + Part 2 §1 — support-access / impersonation:
 *
 * A formal impersonation mechanism must exist so platform support staff
 * can temporarily access a tenant user's session for troubleshooting,
 * with full audit logging and time-boxed expiry.
 */
class IssueP32SupportAccessImpersonationTest extends TenantTestCase
{
    // ------------------------------------------------------------------
    // Source-checks
    // ------------------------------------------------------------------

    public function test_admin_routes_include_impersonation_endpoints(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../routes/admin.php'
        );

        $this->assertStringContainsString('ImpersonationController', $source);
        $this->assertStringContainsString("prefix('impersonation')", $source);
        $this->assertStringContainsString('/start', $source);
        $this->assertStringContainsString('/end', $source);
        $this->assertStringContainsString('/active', $source);
    }

    public function test_impersonation_model_has_correct_table(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../app/Models/Impersonation.php'
        );

        $this->assertStringContainsString("protected \$table = 'impersonations'", $source);
        $this->assertStringContainsString('actor_id', $source);
        $this->assertStringContainsString('target_user_id', $source);
        $this->assertStringContainsString('tenant_id', $source);
        $this->assertStringContainsString('token', $source);
        $this->assertStringContainsString('expires_at', $source);
    }

    public function test_impersonation_controller_has_required_methods(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../app/SuperAdmin/Http/Controllers/ImpersonationController.php'
        );

        $this->assertStringContainsString('public function start', $source);
        $this->assertStringContainsString('public function end', $source);
        $this->assertStringContainsString('public function active', $source);
        $this->assertStringContainsString('MAX_DURATION_MINUTES', $source);
    }

    public function test_impersonation_middleware_checks_token(): void
    {
        // Issue #173 (CRITICAL): the previous version of this test was a pure
        // source-string check that passed even while the middleware was never
        // registered on ANY route (i.e. the whole feature was dead). Assert the
        // real wiring instead: the middleware must appear in at least one live
        // api/v1 route's middleware stack. See HR173ImpersonationWiringTest for
        // the full HTTP-level proof.
        $wired = false;
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach ($route->middleware() as $mw) {
                if (str_contains((string) $mw, 'ImpersonationMiddleware')) {
                    $wired = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($wired, 'ImpersonationMiddleware must be registered on tenant api/v1 routes');

        // The implementation must still hash the token before lookup.
        $source = file_get_contents(
            __DIR__.'/../../app/Http/Middleware/ImpersonationMiddleware.php'
        );
        $this->assertStringContainsString('X-Impersonation-Token', $source);
        $this->assertStringContainsString('hash(\'sha256\'', $source);
        $this->assertStringContainsString('Impersonation::where', $source);
    }

    public function test_impersonation_migration_creates_table(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../database/migrations/2026_08_27_000002_create_impersonations_table.php'
        );

        $this->assertStringContainsString("Schema::create('impersonations'", $source);
        $this->assertStringContainsString('actor_id', $source);
        $this->assertStringContainsString('target_user_id', $source);
        $this->assertStringContainsString('tenant_id', $source);
        $this->assertStringContainsString('token', $source);
        $this->assertStringContainsString('expires_at', $source);
        $this->assertStringContainsString('reason', $source);
    }

    public function test_impersonation_logs_security_incident(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../app/SuperAdmin/Http/Controllers/ImpersonationController.php'
        );

        $this->assertStringContainsString('recordImpersonationStart', $source);
        $this->assertStringContainsString('recordImpersonationEnd', $source);
        $this->assertStringContainsString(SecurityIncidentService::class, $source);
    }

    // ------------------------------------------------------------------
    // Runtime: Impersonation model tests
    // ------------------------------------------------------------------

    public function test_impersonation_is_active_when_not_expired(): void
    {
        $impersonation = new Impersonation([
            'status' => 'active',
            'expires_at' => now()->addHour(),
        ]);

        $this->assertTrue($impersonation->isActive());
    }

    public function test_impersonation_is_not_active_when_expired(): void
    {
        $impersonation = new Impersonation([
            'status' => 'active',
            'expires_at' => now()->subHour(),
        ]);

        $this->assertFalse($impersonation->isActive());
    }

    public function test_impersonation_is_not_active_when_ended(): void
    {
        $impersonation = new Impersonation([
            'status' => 'ended',
            'expires_at' => now()->addHour(),
        ]);

        $this->assertFalse($impersonation->isActive());
    }

    public function test_impersonation_is_expired_returns_true_when_past(): void
    {
        $impersonation = new Impersonation([
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertTrue($impersonation->isExpired());
    }

    public function test_impersonation_is_expired_returns_false_when_future(): void
    {
        $impersonation = new Impersonation([
            'expires_at' => now()->addHour(),
        ]);

        $this->assertFalse($impersonation->isExpired());
    }
}
