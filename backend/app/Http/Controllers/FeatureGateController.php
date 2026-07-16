<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeatureGateController extends Controller
{
    public function __construct(private readonly FeatureGateService $gate) {}

    /**
     * GET /api/v1/features
     * Return all entitled features for the authenticated tenant.
     *
     * Optional header: X-Feature-Gate-Fresh: 1  → bypass cache
     */
    public function all(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $fresh = $request->header('X-Feature-Gate-Fresh') === '1';

        // Logging: Track tenant ID
        \Log::info('[FeatureGateController] Fetching features', [
            'tenant_id' => $tenantId,
            'fresh' => $fresh,
        ]);

        $features = $this->gate->all($tenantId, $fresh);

        // Logging: Track entitled keys
        $entitledKeys = array_column($features, 'key');
        \Log::info('[FeatureGateController] Entitled keys', [
            'tenant_id' => $tenantId,
            'entitled_keys' => $entitledKeys,
            'hrm_included' => in_array('hrm', $entitledKeys),
        ]);

        // Logging: Track final features array
        \Log::info('[FeatureGateController] Final features array', [
            'tenant_id' => $tenantId,
            'count' => count($features),
            'features' => $features,
        ]);

        return response()->json([
            'tenant_id' => $tenantId,
            'count' => count($features),
            'features' => $features,
            'cached_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/features/{key}
     * Check a single feature key.
     *
     * Example key: "payroll.overtime" or "ai_assistant"
     */
    public function check(Request $request, string $key): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $fresh = $request->header('X-Feature-Gate-Fresh') === '1';

        // Sanitise key — allow only lowercase alphanumeric, dots, underscores
        if (! preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){0,2}$/', $key)) {
            return response()->json(['error' => 'Invalid feature key format.'], 422);
        }

        $result = $this->gate->check($tenantId, $key, $fresh);

        return response()->json($result, $result['is_entitled'] ? 200 : 403);
    }

    /**
     * POST /api/v1/features/check-many
     * Batch-check multiple feature keys in one request.
     *
     * Body: { "keys": ["payroll", "hr.leave", "ai_assistant"] }
     */
    public function checkMany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keys' => ['required', 'array', 'min:1', 'max:50'],
            'keys.*' => ['string', 'regex:/^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*){0,2}$/'],
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $fresh = $request->header('X-Feature-Gate-Fresh') === '1';

        $results = $this->gate->checkMany($tenantId, $data['keys'], $fresh);

        return response()->json([
            'tenant_id' => $tenantId,
            'results' => $results,
            'cached_at' => now()->toIso8601String(),
        ]);
    }
}
