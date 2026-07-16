<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\EventManagement\Http\Requests\CreateFanTierRequest;
use Modules\EventManagement\Http\Requests\UpdateFanTierRequest;
use Modules\EventManagement\Services\FanTierService;

class FanTierController extends Controller
{
    private FanTierService $fanTierService;

    public function __construct(FanTierService $fanTierService)
    {
        $this->fanTierService = $fanTierService;
        $this->middleware('auth:api');
        $this->middleware('role:admin'); // Admin-only access
    }

    /**
     * Get all fan tiers for the tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');
        $activeOnly = $request->boolean('active_only', false);

        $fanTiers = $this->fanTierService->getFanTiers($tenantId, $activeOnly);

        return response()->json([
            'success' => true,
            'data' => $fanTiers->load('tierBenefits'),
            'meta' => [
                'total' => $fanTiers->count(),
                'active_only' => $activeOnly,
            ],
        ]);
    }

    /**
     * Get a specific fan tier.
     */
    public function show(Request $request, int $fanTierId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        try {
            $fanTier = $this->fanTierService->getFanTier($tenantId, $fanTierId);

            return response()->json([
                'success' => true,
                'data' => $fanTier->load(['tierBenefits', 'customerTiers', 'earlyAccessWindows']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Fan tier not found',
            ], 404);
        }
    }

    /**
     * Create a new fan tier.
     */
    public function store(CreateFanTierRequest $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        try {
            $fanTier = $this->fanTierService->createFanTier($tenantId, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Fan tier created successfully',
                'data' => $fanTier->load('tierBenefits'),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create fan tier: '.$e->getMessage(),
            ], 400);
        }
    }

    /**
     * Update an existing fan tier.
     */
    public function update(UpdateFanTierRequest $request, int $fanTierId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        try {
            $fanTier = $this->fanTierService->updateFanTier($tenantId, $fanTierId, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Fan tier updated successfully',
                'data' => $fanTier->load('tierBenefits'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update fan tier: '.$e->getMessage(),
            ], 400);
        }
    }

    /**
     * Delete a fan tier (soft delete).
     */
    public function destroy(Request $request, int $fanTierId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        try {
            $this->fanTierService->deleteFanTier($tenantId, $fanTierId);

            return response()->json([
                'success' => true,
                'message' => 'Fan tier deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete fan tier: '.$e->getMessage(),
            ], 400);
        }
    }

    /**
     * Assign a customer to a fan tier.
     */
    public function assignCustomer(Request $request, int $fanTierId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|integer|exists:customers,id',
            'qualification_data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $customerTier = $this->fanTierService->assignCustomerToTier(
                $tenantId,
                $request->customer_id,
                $fanTierId,
                $request->qualification_data ?? []
            );

            return response()->json([
                'success' => true,
                'message' => 'Customer assigned to tier successfully',
                'data' => $customerTier->load(['fanTier', 'customer']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign customer to tier: '.$e->getMessage(),
            ], 400);
        }
    }

    /**
     * Create an early access window for an event and tier.
     */
    public function createEarlyAccessWindow(Request $request, int $fanTierId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $validator = Validator::make($request->all(), [
            'event_id' => 'required|integer|exists:events,id',
            'access_starts_at' => 'required|date|after:now',
            'access_ends_at' => 'required|date|after:access_starts_at',
            'max_tickets_per_customer' => 'nullable|integer|min:1',
            'eligible_ticket_types' => 'nullable|array',
            'eligible_ticket_types.*' => 'integer|exists:event_ticket_types,id',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $request->validated();
            $data['fan_tier_id'] = $fanTierId;

            $earlyAccessWindow = $this->fanTierService->createEarlyAccessWindow($tenantId, $data);

            return response()->json([
                'success' => true,
                'message' => 'Early access window created successfully',
                'data' => $earlyAccessWindow->load(['event', 'fanTier']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create early access window: '.$e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get tier benefits for a fan tier.
     */
    public function getBenefits(Request $request, int $fanTierId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        try {
            $benefits = $this->fanTierService->getTierBenefits($tenantId, $fanTierId);

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
                'message' => 'Failed to get tier benefits: '.$e->getMessage(),
            ], 400);
        }
    }

    /**
     * Create a tier benefit.
     */
    public function createBenefit(Request $request, int $fanTierId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $validator = Validator::make($request->all(), [
            'benefit_type' => 'required|string|in:early_access,discount,exclusive_tickets,priority_support,points_multiplier',
            'benefit_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'configuration' => 'required|array',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $benefit = $this->fanTierService->createTierBenefit($tenantId, $fanTierId, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Tier benefit created successfully',
                'data' => $benefit,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create tier benefit: '.$e->getMessage(),
            ], 400);
        }
    }
}
