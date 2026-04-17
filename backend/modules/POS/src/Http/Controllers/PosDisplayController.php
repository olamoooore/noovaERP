<?php

namespace Modules\POS\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosDisplayPayload;
use Modules\POS\Services\SessionStateMachineService;
use Modules\POS\Services\DisplayPayloadBuilder;
use Modules\POS\Services\DisplayAuditService;
use Modules\POS\Services\DisplayPublisherService;

class PosDisplayController extends Controller
{
    public function __construct(
        private SessionStateMachineService $stateMachine,
        private DisplayPayloadBuilder      $payloadBuilder,
        private DisplayAuditService        $auditService,
        private DisplayPublisherService    $publisher,
    ) {}

    /**
     * GET /pos/sessions/{sessionId}/display-payload
     *
     * Poll endpoint for the customer display.
     * Supports ?since_version=N to avoid redundant re-renders.
     */
    public function getPayload(Request $request, int $sessionId): JsonResponse
    {
        $session = PosSession::with('terminal')->findOrFail($sessionId);

        $sinceVersion = (int) $request->query('since_version', 0);
        $currentVersion = $this->auditService->latestVersion($sessionId);

        // Return 304-equivalent if client is already on latest version
        if ($sinceVersion >= $currentVersion && $currentVersion > 0) {
            return response()->json([
                'up_to_date'      => true,
                'payload_version' => $currentVersion,
                'display_state'   => $session->current_display_state,
            ]);
        }

        // Fetch the latest persisted payload
        $record = PosDisplayPayload::where('session_id', $sessionId)
            ->where('payload_version', $currentVersion)
            ->first();

        if (! $record) {
            // Build a fresh one on demand
            $payload = $this->payloadBuilder->build($session);
            $record  = $this->auditService->record($session, $session->current_display_state ?? 'IDLE_SCREENSAVER', $payload);
        }

        $this->auditService->markDelivered($record);

        return response()->json([
            'type'            => 'display.payload.updated',
            'session_id'      => $session->id,
            'payload_version' => $record->payload_version,
            'display_state'   => $record->display_state,
            'occurred_at'     => $record->created_at?->toIso8601String(),
            'payload'         => $record->payload_json,
        ]);
    }

    /**
     * POST /pos/sessions/{sessionId}/display-event
     *
     * Dispatch a named display event from the cashier POS to drive state transitions.
     *
     * Body: {
     *   "event": "CartItemAdded",
     *   "cart_items": [...],
     *   "focus_item": {...},
     *   "focus_reason": "item_added",
     *   "member_id": null
     * }
     */
    public function dispatchEvent(Request $request, int $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'event'        => 'required|string|max:100',
            'cart_items'   => 'nullable|array',
            'focus_item'   => 'nullable|array',
            'focus_reason' => 'nullable|string|max:50',
            'member_id'    => 'nullable|integer',
        ]);

        $session    = PosSession::with('terminal')->findOrFail($sessionId);
        $eventName  = $validated['event'];
        $cartItems  = $validated['cart_items'] ?? [];
        $focusItem  = $validated['focus_item'] ?? null;
        $focusReason = $validated['focus_reason'] ?? 'item_added';

        // Update member_id on session if provided
        if (array_key_exists('member_id', $validated)) {
            $session->update(['member_id' => $validated['member_id']]);
            $session->refresh();
        }

        // Run state transition
        $newState = $this->stateMachine->transition($session, $eventName);

        if ($newState === null) {
            // Transition was not valid — still return current state without error
            $newState = $session->current_display_state;
        }

        $session->refresh();

        // Build and persist new payload
        $payload = $this->payloadBuilder->build($session, $cartItems, $focusReason, $focusItem);
        $record  = $this->auditService->record($session, $newState, $payload);

        // Publish (log + future broadcast)
        $this->publisher->publish($session, $payload, $record->payload_version);

        return response()->json([
            'ok'              => true,
            'display_state'   => $newState,
            'payload_version' => $record->payload_version,
        ]);
    }

    /**
     * GET /pos/display/health
     */
    public function health(): JsonResponse
    {
        return response()->json(['module' => 'pos_display', 'ok' => true]);
    }
}
