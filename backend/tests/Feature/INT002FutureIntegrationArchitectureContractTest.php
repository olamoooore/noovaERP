<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * INT-002: Future integration architecture contract evidence.
 *
 * This suite keeps the gate stable by asserting cross-module contracts
 * required for long-term integration readiness and CI enforcement.
 */
class INT002FutureIntegrationArchitectureContractTest extends TestCase
{
    public function test_tenant_api_stack_includes_auth_tenant_context_and_search_path_guards(): void
    {
        $routes = file_get_contents(base_path('routes/tenant.php'));

        $this->assertStringContainsString("'auth:sanctum'", $routes);
        $this->assertStringContainsString('SetTenantFromUserPreference::class', $routes);
        $this->assertStringContainsString('EnsureTenantSearchPath::class', $routes);
        $this->assertStringContainsString('SyncTenantUserFromCentralAuth::class', $routes);
    }

    public function test_pos_sync_controller_returns_structured_envelopes_for_queue_and_resource_reads(): void
    {
        $controller = file_get_contents(base_path('modules/POS/src/Http/Controllers/PosSyncController.php'));

        $this->assertStringContainsString("'message' => 'Operation queued successfully'", $controller);
        $this->assertStringContainsString("'data' => \$queueItem", $controller);
        $this->assertStringContainsString("return response()->json(['data' => \$item]);", $controller);
        $this->assertStringContainsString('return response()->json([', $controller);
        $this->assertStringContainsString("'count' => \$items->count()", $controller);
    }

    public function test_repository_contains_pre_push_guardrail_hook_for_tenant_drift(): void
    {
        $hook = file_get_contents(base_path('../.githooks/pre-push'));
        $script = file_get_contents(base_path('../scripts/tenant_guardrails_pre_push.sh'));

        $this->assertStringContainsString('tenant_guardrails_pre_push.sh', $hook);
        $this->assertStringContainsString('php artisan ops:tenant-guardrails', $script);
    }
}
