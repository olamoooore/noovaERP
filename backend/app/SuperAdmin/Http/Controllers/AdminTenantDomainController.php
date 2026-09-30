<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\Tenancy\DomainOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * FR-PLAT-03 / A-26 — domain onboarding from the SuperAdmin console.
 * Reads are open to every admin role; writes sit behind DenyReadonlyRole
 * in routes/admin.php (same convention as every other tenant write).
 */
class AdminTenantDomainController extends Controller
{
    public function __construct(
        private readonly DomainOnboardingService $domains,
    ) {}

    public function index(string $tenantId): JsonResponse
    {
        return response()->json(['data' => $this->domains->list($tenantId)]);
    }

    public function store(Request $request, string $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'type' => ['nullable', 'string', 'in:platform,custom'],
        ]);

        $data = $this->domains->add(
            $tenantId,
            $validated['domain'],
            $validated['type'] ?? 'custom',
        );

        return response()->json(['data' => $data], 201);
    }

    public function destroy(string $tenantId, int $domainId): JsonResponse
    {
        $this->domains->remove($tenantId, $domainId);

        return response()->json(['message' => 'Domain removed.']);
    }

    public function verify(string $tenantId, int $domainId): JsonResponse
    {
        return response()->json(['data' => $this->domains->verify($tenantId, $domainId)]);
    }

    public function check(string $tenantId, int $domainId): JsonResponse
    {
        return response()->json(['data' => $this->domains->check($tenantId, $domainId)]);
    }
}
