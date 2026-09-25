<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SalesOrderAcceptanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Issue #149: online quotation acceptance / eSignature portal.
 *
 * Public (no auth, no Sanctum), token-authenticated endpoints. The token
 * is the only credential; rate-limited at the route level (5/min per IP)
 * to make brute-force guessing impractical.
 */
class SalesOrderAcceptanceController extends Controller
{
    public function __construct(private SalesOrderAcceptanceService $service) {}

    public function show(string $token)
    {
        $order = $this->service->findByToken($token);

        if (! $order) {
            return response()->json(['error' => 'Invalid or expired acceptance link.'], 404);
        }

        if ($order->acceptance_expires_at && $order->acceptance_expires_at->isPast() && $order->accepted_at === null) {
            return response()->json(['error' => 'Acceptance link has expired.'], 410);
        }

        $snapshot = $this->service->snapshot($order);
        $snapshot['company'] = \App\Support\TenantBranding::forCurrentTenant();

        return response()->json($snapshot);
    }

    public function accept(Request $request, string $token)
    {
        $validated = $request->validate([
            'signer_name' => 'required|string|max:255',
            'signer_email' => 'required|email|max:255',
            'signature' => 'nullable|string|max:1048576',
        ]);

        $order = $this->service->findByToken($token);

        if (! $order) {
            return response()->json(['error' => 'Invalid or expired acceptance link.'], 404);
        }

        if ($order->accepted_at !== null) {
            return response()->json([
                'message' => 'Quotation already accepted.',
                'signer_name' => $order->signer_name,
                'accepted_at' => optional($order->accepted_at)->toIso8601String(),
            ]);
        }

        if ($order->acceptance_expires_at && $order->acceptance_expires_at->isPast()) {
            return response()->json(['error' => 'Acceptance link has expired.'], 410);
        }

        try {
            $this->service->accept(
                $order,
                $validated['signer_name'],
                $validated['signer_email'],
                $validated['signature'] ?? null,
                $request->ip() ?? 'unknown',
                $request->userAgent() ?? 'unknown',
            );

            return response()->json([
                'message' => 'Quotation accepted successfully.',
                'order_number' => $order->fresh()->sales_order_number,
                'status' => $order->fresh()->status,
                'signer_name' => $validated['signer_name'],
                'accepted_at' => optional($order->fresh()->accepted_at)->toIso8601String(),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 410);
        } catch (\Exception $e) {
            Log::error('SalesOrderAcceptance: failed', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to record acceptance. Please try again.'], 500);
        }
    }
}
