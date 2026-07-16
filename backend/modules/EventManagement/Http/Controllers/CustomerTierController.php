<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\EventManagement\Services\FanTierService;

class CustomerTierController extends Controller
{
    private FanTierService $fanTierService;

    public function __construct(FanTierService $fanTierService)
    {
        $this->fanTierService = $fanTierService;
        $this->middleware('auth:api');
    }

    /**
     * Get customer's current tier information.
     */
    public function show(Request $request, int $customerId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        // Check if user can access this customer's data
        $user = $request->user();
        if (! $user->hasRole('admin') && $user->customer_id !== $customerId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to customer data',
            ], 403);
        }

        try {
            $customerTier = $this->fanTierService->getCustomerTier($tenantId, $customerId);

            if (! $customerTier) {
                return response()->json([
                    'success' => true,
                    'data' => null,
                    'message' => 'Customer has no tier assigned',
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $customerTier->load(['fanTier', 'customer']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get customer tier: '.$e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get customer's tier progression information.
     */
    public function progression(Request $request, int $customerId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        // Check if user can access this customer's data
        $user = $request->user();
        if (! $user->hasRole('admin') && $user->customer_id !== $customerId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to customer data',
            ], 403);
        }

        try {
            $progression = $this->fanTierService->calculateTierProgression($tenantId, $customerId);

            return response()->json([
                'success' => true,
                'data' => $progression,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate tier progression: '.$e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get customer's tier benefits.
     */
    public function benefits(Request $request, int $customerId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        // Check if user can access this customer's data
        $user = $request->user();
        if (! $user->hasRole('admin') && $user->customer_id !== $customerId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to customer data',
            ], 403);
        }

        try {
            $benefits = $this->fanTierService->getCustomerBenefits($tenantId, $customerId);

            return response()->json([
                'success' => true,
                'data' => $benefits,
                'meta' => [
                    'total' => $benefits->count(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get customer benefits: '.$e->getMessage(),
            ], 400);
        }
    }

    /**
     * Check customer's early access eligibility for an event.
     */
    public function earlyAccess(Request $request, int $customerId, int $eventId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        // Check if user can access this customer's data
        $user = $request->user();
        if (! $user->hasRole('admin') && $user->customer_id !== $customerId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to customer data',
            ], 403);
        }

        try {
            $earlyAccessInfo = $this->fanTierService->hasEarlyAccess($tenantId, $customerId, $eventId);

            return response()->json([
                'success' => true,
                'data' => $earlyAccessInfo,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check early access: '.$e->getMessage(),
            ], 400);
        }
    }

    /**
     * Process automatic tier progression for a customer (admin only).
     */
    public function processProgression(Request $request, int $customerId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        // Admin-only endpoint
        if (! $request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Admin access required',
            ], 403);
        }

        try {
            $customerTier = $this->fanTierService->processAutomaticTierProgression($tenantId, $customerId);

            return response()->json([
                'success' => true,
                'message' => 'Tier progression processed successfully',
                'data' => $customerTier ? $customerTier->load(['fanTier', 'customer']) : null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process tier progression: '.$e->getMessage(),
            ], 400);
        }
    }
}
