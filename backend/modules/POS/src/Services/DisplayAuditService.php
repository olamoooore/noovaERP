<?php

namespace Modules\POS\Services;

use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosDisplayPayload;

class DisplayAuditService
{
    /**
     * Persist a payload version and return the persisted record.
     */
    public function record(PosSession $session, string $displayState, array $payload, ?int $focusedLineId = null): PosDisplayPayload
    {
        $lastVersion = PosDisplayPayload::where('session_id', $session->id)
            ->max('payload_version') ?? 0;

        $hash = hash('sha256', json_encode($payload));

        return PosDisplayPayload::create([
            'session_id'      => $session->id,
            'payload_version' => $lastVersion + 1,
            'display_state'   => $displayState,
            'focused_line_id' => $focusedLineId,
            'payload_json'    => $payload,
            'hash'            => $hash,
            'created_at'      => now(),
        ]);
    }

    /**
     * Mark a payload as delivered to the display client.
     */
    public function markDelivered(PosDisplayPayload $payloadRecord): void
    {
        $payloadRecord->update(['delivered_at' => now()]);
    }

    /**
     * Get the latest payload version number for a session.
     */
    public function latestVersion(int $sessionId): int
    {
        return PosDisplayPayload::where('session_id', $sessionId)
            ->max('payload_version') ?? 0;
    }
}
