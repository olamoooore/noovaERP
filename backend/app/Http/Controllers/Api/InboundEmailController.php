<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InboundEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issue #85 — incoming email bridge (IMAP integration).
 *
 * Single HTTP seam that accepts parsed RFC-822 payloads from any
 * upstream source (IMAP poller, SendGrid Inbound Parse, Graph
 * subscription, manual upload). The controller delegates to
 * \`App\Services\InboundEmailService\` which owns the match + chatter
 * routing logic.
 */
class InboundEmailController extends Controller
{
    public function __construct(private InboundEmailService $service) {}

    public function ingest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message_id' => 'nullable|string|max:255',
            'in_reply_to' => 'nullable|string|max:255',
            'from' => 'nullable|array',
            'from.address' => 'nullable|email|max:255',
            'from.name' => 'nullable|string|max:255',
            'from_address' => 'nullable|email|max:255',
            'from_name' => 'nullable|string|max:255',
            'subject' => 'required|string|max:998',
            'body_plain' => 'nullable|string',
            'body_html' => 'nullable|string',
            'text' => 'nullable|string',
            'html' => 'nullable|string',
            'headers' => 'nullable|array',
            'tenant_id' => 'nullable|string|max:64',
        ]);

        $inbound = $this->service->ingest($validated);

        return response()->json([
            'data' => [
                'id' => $inbound->id,
                'matched_model' => $inbound->matched_model,
                'matched_record_id' => $inbound->matched_record_id,
                'match_strategy' => $inbound->match_strategy,
                'communication_message_id' => $inbound->communication_message_id,
            ],
        ], 201);
    }
}
