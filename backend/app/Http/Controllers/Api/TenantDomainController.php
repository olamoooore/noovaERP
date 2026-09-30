<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tenancy\DomainOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-PLAT-03 — tenant-side domain onboarding. Rows are CENTRAL-schema and
 * always scoped to the caller's own tenant (from tenancy()->tenant, same
 * resolution as TenantUserController), so one tenant can never list or
 * mutate another tenant's domains even when guessing row ids.
 *
 * RBAC: settings.view / settings.write from the seeded PERMISSION_MATRIX
 * (OWNER/ADMIN only) — domains are workspace-level configuration, not a
 * per-module permission.
 */
class TenantDomainController extends Controller
{
    public function __construct(
        private readonly DomainOnboardingService $domains,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        if ($tenantId === null) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        return response()->json(['data' => $this->domains->list($tenantId)]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        if ($tenantId === null) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'type' => ['nullable', 'string', 'in:platform,custom'],
        ]);

        // Tenants onboard their own (custom) domains; the platform
        // subdomain type is assigned by signup/SuperAdmin, not self-served.
        if (($validated['type'] ?? 'custom') === 'platform') {
            return response()->json([
                'message' => 'Platform subdomains are system-assigned.',
                'code' => 'platform_domain_protected',
            ], 403);
        }

        $data = $this->domains->add($tenantId, $validated['domain'], 'custom');

        return response()->json(['data' => $data], 201);
    }

    public function destroy(Request $request, int $domainId): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        if ($tenantId === null) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        $this->domains->remove($tenantId, $domainId);

        return response()->json(['message' => 'Domain removed.']);
    }

    public function verify(Request $request, int $domainId): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        if ($tenantId === null) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        return response()->json(['data' => $this->domains->verify($tenantId, $domainId)]);
    }

    public function check(Request $request, int $domainId): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        if ($tenantId === null) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        return response()->json(['data' => $this->domains->check($tenantId, $domainId)]);
    }

    private function resolveTenantId(Request $request): ?string
    {
        if (function_exists('tenant') && tenant()) {
            $id = tenant('id');
            if ($id !== null && $id !== '') {
                return (string) $id;
            }
        }

        $headerTenantId = $request->header('X-Tenant-ID');

        return (is_string($headerTenantId) && $headerTenantId !== '') ? $headerTenantId : null;
    }
}
