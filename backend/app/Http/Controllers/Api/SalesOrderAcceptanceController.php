<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Services\SalesOrderAcceptanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Issue #149: online quotation acceptance / eSignature portal.
 *
 * Public (no auth, no Sanctum), token-authenticated endpoints. The token
 * is the only credential; rate-limited at the route level (5/min per IP)
 * to make brute-force guessing impractical.
 *
 * Issue #334 (Q5): the route serves the quotation tokens the new
 * `POST /quotations/{id}/send` endpoint issues. A token is resolved against
 * the Quotation first and falls back to the #149 SalesOrder path.
 */
class SalesOrderAcceptanceController extends Controller
{
    public function __construct(private SalesOrderAcceptanceService $service) {}

    public function show(string $token)
    {
        $quotation = $this->service->findQuotationByToken($token);

        if ($quotation) {
            if ($quotation->acceptance_expires_at && $quotation->acceptance_expires_at->isPast() && $quotation->accepted_at === null) {
                return response()->json(['error' => 'Acceptance link has expired.'], 410);
            }

            if ($quotation->accepted_at === null
                && ! in_array((string) $quotation->status, ['draft', 'sent'], true)) {
                return response()->json(['error' => 'This quotation is no longer open for acceptance.'], 410);
            }

            $snapshot = $this->service->quotationSnapshot($quotation);
            $snapshot['company'] = \App\Support\TenantBranding::forCurrentTenant();

            return response()->json($snapshot);
        }

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
            // Issue #338: the optional products the customer chose to add.
            'optional_line_ids' => 'nullable|array',
            'optional_line_ids.*' => 'integer|distinct',
        ]);

        $quotation = $this->service->findQuotationByToken($token);

        if ($quotation) {
            return $this->acceptQuotation($request, $quotation, $validated);
        }

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

    /**
     * Issue #334: the Quotation arm of the public acceptance. Accepting runs
     * the same approval gate + release path as the authenticated Confirm
     * action; when the tenant's gate requires approval the request is raised
     * (HTTP 202) and the quotation stays `sent`.
     */
    private function acceptQuotation(Request $request, Quotation $quotation, array $validated)
    {
        if ($quotation->accepted_at !== null) {
            return response()->json([
                'message' => 'Quotation already accepted.',
                'order_number' => $quotation->quotation_number,
                'quotation_number' => $quotation->quotation_number,
                'status' => $quotation->status,
                'requires_approval' => false,
                'signer_name' => $quotation->signer_name,
                'accepted_at' => optional($quotation->accepted_at)->toIso8601String(),
            ]);
        }

        if ($quotation->acceptance_expires_at && $quotation->acceptance_expires_at->isPast()) {
            return response()->json(['error' => 'Acceptance link has expired.'], 410);
        }

        if (! in_array((string) $quotation->status, ['draft', 'sent'], true)) {
            return response()->json(['error' => 'This quotation is no longer open for acceptance.'], 410);
        }

        // Issue #550: a drawn signature is required when the quotation (or,
        // for one created before #550, the tenant's Sales Settings) says so.
        if ($this->service->quotationRequirements($quotation)['require_signature']) {
            $request->validate([
                'signature' => ['required', 'string', 'max:1048576', 'starts_with:data:image/'],
            ]);
        }

        // Issue #338: every selected id must be an optional line of THIS
        // quotation — refused before any acceptance trace is written.
        $optionalLineIds = $this->service->validateOptionalSelection(
            $quotation,
            (array) ($validated['optional_line_ids'] ?? []),
        );

        try {
            $result = $this->service->acceptQuotation(
                $quotation,
                $validated['signer_name'],
                $validated['signer_email'],
                $validated['signature'] ?? null,
                $request->ip() ?? 'unknown',
                $request->userAgent() ?? 'unknown',
                $optionalLineIds,
            );

            $accepted = $result['quotation'];

            // Issue #550: accepted, but not confirmed until the prepayment
            // is received (HTTP 202, like a pending approval).
            if ($result['requires_payment'] ?? false) {
                return response()->json([
                    'message' => 'Quotation accepted. It will be confirmed once the prepayment has been received.',
                    'order_number' => $accepted->quotation_number,
                    'quotation_number' => $accepted->quotation_number,
                    'status' => $accepted->status,
                    'requires_approval' => false,
                    'requires_payment' => true,
                    'prepayment_percent' => $result['prepayment_percent'],
                    'prepayment_amount' => $result['prepayment_amount'],
                    'currency_code' => $accepted->currency_code,
                    'sales_order_id' => null,
                    'signer_name' => $validated['signer_name'],
                    'accepted_at' => optional($accepted->accepted_at)->toIso8601String(),
                ], 202);
            }

            return response()->json([
                'message' => $result['requires_approval']
                    ? 'Quotation accepted. It now awaits internal approval before it can be confirmed.'
                    : 'Quotation accepted successfully.',
                'order_number' => $accepted->quotation_number,
                'quotation_number' => $accepted->quotation_number,
                'status' => $accepted->status,
                'requires_approval' => $result['requires_approval'],
                'requires_payment' => false,
                'sales_order_id' => $accepted->sales_order_id !== null ? (int) $accepted->sales_order_id : null,
                'signer_name' => $validated['signer_name'],
                'accepted_at' => optional($accepted->accepted_at)->toIso8601String(),
            ], $result['requires_approval'] ? 202 : 200);
        } catch (\App\Exceptions\QuotationExpiredException $e) {
            // Issue #335 (Q6): the quotation's validity lapsed while its
            // acceptance token was still alive — a clear 422, not a token 410.
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 410);
        } catch (\Exception $e) {
            Log::error('Quotation acceptance failed', [
                'quotation_id' => $quotation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to record acceptance. Please try again.'], 500);
        }
    }
}
