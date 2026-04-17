<?php

namespace Modules\POS\Services;

use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosDisplayEventLog;

class DisplayPublisherService
{
    /**
     * Publish a versioned display payload to the session's display channel.
     *
     * Current implementation uses polling (the frontend polls GET /display-payload).
     * When a realtime channel (Pusher / Laravel Echo / SSE) is available,
     * replace the body of this method with a broadcast call.
     *
     * @param array $payload    The built payload from DisplayPayloadBuilder
     * @param int   $version    The payload_version from DisplayAuditService
     */
    public function publish(PosSession $session, array $payload, int $version): void
    {
        // Log the publish event
        PosDisplayEventLog::create([
            'session_id'     => $session->id,
            'terminal_id'    => $session->terminal_id,
            'event_name'     => 'DisplayPayloadPublished',
            'aggregate_type' => 'pos_session',
            'aggregate_id'   => (string) $session->id,
            'event_json'     => [
                'payload_version' => $version,
                'display_state'   => $payload['session']['state'] ?? null,
            ],
            'occurred_at' => now(),
            'created_at'  => now(),
        ]);

        // TODO: When broadcasting is available, dispatch here:
        // broadcast(new \Modules\POS\Events\DisplayPayloadUpdated($session->id, $payload, $version));
    }

    /**
     * Log any named display event for audit/traceability.
     */
    public function logEvent(int $sessionId, int $terminalId, string $eventName, array $eventData = []): void
    {
        PosDisplayEventLog::create([
            'session_id'     => $sessionId,
            'terminal_id'    => $terminalId,
            'event_name'     => $eventName,
            'aggregate_type' => 'pos_session',
            'aggregate_id'   => (string) $sessionId,
            'event_json'     => $eventData,
            'occurred_at'    => now(),
            'created_at'     => now(),
        ]);
    }
}
