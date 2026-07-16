<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\EventManagement\Http\Middleware\RBACMiddleware;
use Modules\EventManagement\Models\FraudDetectionRule;
use Modules\EventManagement\Models\IpRestriction;
use Modules\EventManagement\Services\AntiAbuseService;

/**
 * AntiAbuseController
 *
 * ── X-Tenant-ID header REMOVED ──────────────────────────────────────────────
 * Previously every method read tenant identity from an arbitrary `X-Tenant-ID`
 * request header, falling back to the string literal `'default'` when the
 * header was absent. This pattern:
 *   - Bypasses the platform tenancy runtime (schema-per-tenant isolation)
 *   - Allows callers to cross tenant boundaries by sending any tenant ID
 *   - Silently processes requests against a fake `'default'` tenant
 *
 * Tenant identity is now resolved once per request via resolveTenantId(),
 * which reads the stancl/tenancy runtime (already active for authenticated
 * routes) and fails closed (403) when no tenant context can be established.
 */
class AntiAbuseController extends Controller
{
    private AntiAbuseService $antiAbuseService;

    public function __construct(AntiAbuseService $antiAbuseService)
    {
        $this->antiAbuseService = $antiAbuseService;

        // All anti-abuse endpoints require admin access
        $this->middleware([RBACMiddleware::class.':admin']);
    }

    // ── IP Restriction Management ─────────────────────────────────────────────

    /**
     * Get IP restrictions with filtering and pagination.
     * Requirements: 14.5 - IP restriction management
     */
    public function getIpRestrictions(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        $request->validate([
            'restriction_type' => 'nullable|in:blocked,allowed,flagged',
            'is_active' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $query = IpRestriction::forTenant($tenantId)
                ->orderBy('created_at', 'desc');

            if ($request->has('restriction_type')) {
                $query->where('restriction_type', $request->restriction_type);
            }
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            $restrictions = $query->paginate($request->query('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => $restrictions->items(),
                'pagination' => [
                    'current_page' => $restrictions->currentPage(),
                    'per_page' => $restrictions->perPage(),
                    'total' => $restrictions->total(),
                    'last_page' => $restrictions->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve IP restrictions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Block an IP address.
     * Requirements: 14.1, 14.5 - IP blocking functionality
     */
    public function blockIpAddress(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        $request->validate([
            'ip_address' => 'required|ip',
            'reason' => 'required|string|max:500',
            'duration_hours' => 'nullable|integer|min:1|max:8760',
        ]);

        try {
            $restriction = $this->antiAbuseService->blockIpAddress(
                $tenantId,
                $request->ip_address,
                $request->reason,
                $request->query('duration_hours', 24)
            );

            return response()->json([
                'success' => true,
                'message' => 'IP address blocked successfully',
                'data' => $restriction,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to block IP address',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add IP address to allowlist.
     * Requirements: 14.5 - IP allowlist management
     */
    public function allowlistIpAddress(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        $request->validate([
            'ip_address' => 'required|ip',
            'reason' => 'required|string|max:500',
        ]);

        try {
            $restriction = $this->antiAbuseService->allowlistIpAddress(
                $tenantId,
                $request->ip_address,
                $request->reason
            );

            return response()->json([
                'success' => true,
                'message' => 'IP address added to allowlist successfully',
                'data' => $restriction,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to allowlist IP address',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Flag IP address for monitoring.
     * Requirements: 14.5 - IP monitoring and flagging
     */
    public function flagIpAddress(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        $request->validate([
            'ip_address' => 'required|ip',
            'reason' => 'required|string|max:500',
        ]);

        try {
            $restriction = $this->antiAbuseService->flagIpAddress(
                $tenantId,
                $request->ip_address,
                $request->reason
            );

            return response()->json([
                'success' => true,
                'message' => 'IP address flagged for monitoring',
                'data' => $restriction,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to flag IP address',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove IP restriction.
     * Requirements: 14.5 - IP restriction management
     */
    public function removeIpRestriction(Request $request, int $restrictionId): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        try {
            $restriction = IpRestriction::forTenant($tenantId)->findOrFail($restrictionId);
            $restriction->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'IP restriction removed successfully',
                'data' => $restriction->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove IP restriction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ── Fraud Detection Rules ─────────────────────────────────────────────────

    /**
     * Get fraud detection rules.
     * Requirements: 14.5 - Fraud rule management
     */
    public function getFraudRules(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        $request->validate([
            'rule_type' => 'nullable|in:velocity,pattern,identity,ip_reputation',
            'is_active' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $query = FraudDetectionRule::forTenant($tenantId)
                ->orderBy('priority', 'desc')
                ->orderBy('created_at', 'desc');

            if ($request->has('rule_type')) {
                $query->where('rule_type', $request->rule_type);
            }
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            $rules = $query->paginate($request->query('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => $rules->items(),
                'pagination' => [
                    'current_page' => $rules->currentPage(),
                    'per_page' => $rules->perPage(),
                    'total' => $rules->total(),
                    'last_page' => $rules->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve fraud rules',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create fraud detection rule.
     * Requirements: 14.5 - Fraud rule configuration
     */
    public function createFraudRule(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        $request->validate([
            'rule_name' => 'required|string|max:255',
            'rule_type' => 'required|in:velocity,pattern,identity,ip_reputation',
            'description' => 'nullable|string|max:1000',
            'conditions' => 'required|array',
            'actions' => 'required|array',
            'risk_score' => 'required|integer|min:1|max:100',
            'priority' => 'nullable|integer|min:1|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $rule = FraudDetectionRule::create([
                'tenant_id' => $tenantId,
                'rule_name' => $request->rule_name,
                'rule_type' => $request->rule_type,
                'description' => $request->description,
                'conditions' => $request->conditions,
                'actions' => $request->actions,
                'risk_score' => $request->risk_score,
                'priority' => $request->priority ?? 50,
                'is_active' => $request->boolean('is_active', true),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Fraud detection rule created successfully',
                'data' => $rule,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create fraud rule',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update fraud detection rule.
     * Requirements: 14.5 - Fraud rule management
     */
    public function updateFraudRule(Request $request, int $ruleId): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        $request->validate([
            'rule_name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'conditions' => 'nullable|array',
            'actions' => 'nullable|array',
            'risk_score' => 'nullable|integer|min:1|max:100',
            'priority' => 'nullable|integer|min:1|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $rule = FraudDetectionRule::forTenant($tenantId)->findOrFail($ruleId);

            $rule->update($request->only([
                'rule_name', 'description', 'conditions',
                'actions', 'risk_score', 'priority', 'is_active',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Fraud detection rule updated successfully',
                'data' => $rule->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update fraud rule',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete fraud detection rule.
     * Requirements: 14.5 - Fraud rule management
     */
    public function deleteFraudRule(Request $request, int $ruleId): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        try {
            $rule = FraudDetectionRule::forTenant($tenantId)->findOrFail($ruleId);
            $rule->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Fraud detection rule deactivated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete fraud rule',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test fraud detection rule against sample data.
     * Requirements: 14.5 - Fraud rule testing
     */
    public function testFraudRule(Request $request, int $ruleId): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        $request->validate([
            'test_data' => 'required|array',
            'test_data.customer_id' => 'nullable|integer',
            'test_data.ip_address' => 'nullable|ip',
            'test_data.event_id' => 'required|integer',
            'test_data.tickets_requested' => 'nullable|integer|min:1',
        ]);

        try {
            $rule = FraudDetectionRule::forTenant($tenantId)->findOrFail($ruleId);

            $testData = array_merge($request->test_data, [
                'tenant_id' => $tenantId,
                'ip_address' => $request->test_data['ip_address'] ?? '127.0.0.1',
                'tickets_requested' => $request->test_data['tickets_requested'] ?? 1,
            ]);

            $shouldEvaluate = $rule->shouldEvaluate($testData);
            $result = $shouldEvaluate ? $rule->evaluate($testData) : null;

            return response()->json([
                'success' => true,
                'data' => [
                    'rule_name' => $rule->rule_name,
                    'should_evaluate' => $shouldEvaluate,
                    'evaluation_result' => $result,
                    'test_data' => $testData,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to test fraud rule',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ── Rate Limiting ─────────────────────────────────────────────────────────

    /**
     * Check rate limit status for an entity.
     * Requirements: 14.1 - Rate limiting monitoring
     */
    public function checkRateLimit(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        $request->validate([
            'customer_id' => 'nullable|integer',
            'ip_address' => 'nullable|ip',
            'session_id' => 'nullable|string',
        ]);

        try {
            $context = array_filter([
                'customer_id' => $request->customer_id,
                'ip_address' => $request->ip_address,
                'session_id' => $request->session_id,
            ]);

            if (empty($context)) {
                return response()->json([
                    'success' => false,
                    'message' => 'At least one identifier (customer_id, ip_address, or session_id) is required',
                ], 400);
            }

            $rateLimitStatus = $this->antiAbuseService->enforceRateLimit($tenantId, $context);

            return response()->json([
                'success' => true,
                'data' => $rateLimitStatus,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check rate limit',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    /**
     * Resolve the current tenant ID from the stancl/tenancy runtime.
     *
     * Fails closed with a 403 when no tenant context is active — prevents
     * cross-tenant data access that the old X-Tenant-ID/default fallback enabled.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException (403)
     */
    private function resolveTenantId(Request $request): string
    {
        // Primary: stancl/tenancy runtime (initialized by route middleware)
        if (function_exists('tenant') && tenant()) {
            return (string) tenant('id');
        }

        // Fail closed — no fallback to arbitrary header or fake 'default'
        abort(403, 'No tenant context could be established for this request. Ensure you are authenticated on a tenant route.');
    }
}
