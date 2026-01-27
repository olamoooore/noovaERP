<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\EventManagement\Services\LoyaltyService;

class LoyaltyController extends Controller
{
    private LoyaltyService $loyaltyService;

    public function __construct(LoyaltyService $loyaltyService)
    {
        $this->loyaltyService = $loyaltyService;
        $this->middleware('auth:api');
    }

    /**
     * Get customer's points information.
     */
    public function getPoints(Request $request, int $customerId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        // Check if user can access this customer's data
        $user = $request->user();
        if (!$user->hasRole('admin') && $user->customer_id !== $customerId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to customer data',
            ], 403);
        }

        try {
            $pointsInfo = $this->loyaltyService->getCustomerPointsInfo($tenantId, $customerId);

            return response()->json([
                'success' => true,
                'data' => $pointsInfo,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get points information: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Award points to a customer (admin only).
     */
    public function awardPoints(Request $request, int $customerId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        // Admin-only endpoint
        if (!$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Admin access required',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'points' => 'required|integer|min:1',
            'source_type' => 'required|string|max:50',
            'source_id' => 'nullable|integer',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->loyaltyService->awardPoints(
                $tenantId,
                $customerId,
                $request->points,
                $request->source_type,
                $request->source_id,
                $request->description
            );

            return response()->json([
                'success' => true,
                'message' => 'Points awarded successfully',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to award points: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Redeem points for a customer.
     */
    public function redeemPoints(Request $request, int $customerId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        // Check if user can access this customer's data
        $user = $request->user();
        if (!$user->hasRole('admin') && $user->customer_id !== $customerId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to customer data',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'points' => 'required|integer|min:1',
            'source_type' => 'required|string|max:50',
            'source_id' => 'nullable|integer',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->loyaltyService->redeemPoints(
                $tenantId,
                $customerId,
                $request->points,
                $request->source_type,
                $request->source_id,
                $request->description
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['error'],
                    'data' => $result,
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Points redeemed successfully',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to redeem points: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Calculate points discount for an order.
     */
    public function calculateDiscount(Request $request, int $customerId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        // Check if user can access this customer's data
        $user = $request->user();
        if (!$user->hasRole('admin') && $user->customer_id !== $customerId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to customer data',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'points_to_redeem' => 'required|integer|min:1',
            'order_amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $discountInfo = $this->loyaltyService->calculatePointsDiscount(
                $tenantId,
                $customerId,
                $request->points_to_redeem,
                $request->order_amount
            );

            return response()->json([
                'success' => true,
                'data' => $discountInfo,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate discount: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get points earning rules (admin only).
     */
    public function getEarningRules(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        // Admin-only endpoint
        if (!$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Admin access required',
            ], 403);
        }

        try {
            $rules = $this->loyaltyService->getPointsEarningRules($tenantId);

            return response()->json([
                'success' => true,
                'data' => $rules,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get earning rules: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Process expired points for all customers (admin only).
     */
    public function processExpiredPoints(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        // Admin-only endpoint
        if (!$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Admin access required',
            ], 403);
        }

        try {
            $result = $this->loyaltyService->processExpiredPoints($tenantId);

            return response()->json([
                'success' => true,
                'message' => 'Expired points processed successfully',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process expired points: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Calculate points for a booking amount.
     */
    public function calculateBookingPoints(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $validator = Validator::make($request->all(), [
            'booking_amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $points = $this->loyaltyService->calculateBookingPoints($tenantId, $request->booking_amount);

            return response()->json([
                'success' => true,
                'data' => [
                    'booking_amount' => $request->booking_amount,
                    'points_to_earn' => $points,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate booking points: ' . $e->getMessage(),
            ], 400);
        }
    }
}