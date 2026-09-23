<?php

namespace App\Services;

use App\Contracts\EsignProviderInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Null/self-hosted e-sign provider stub.
 *
 * Used when no third-party e-sign provider is configured.
 * All envelopes are immediately completed with a self-sign placeholder.
 * Replace with a real provider (e.g., DocuSign, HelloSign) by implementing
 * EsignProviderInterface and binding it in the service container.
 */
class NullEsignProvider implements EsignProviderInterface
{
    public function createEnvelope(array $data): array
    {
        return [
            'envelope_id' => Str::uuid()->toString(),
            'status' => 'sent',
            'sent_at' => now()->toIso8601String(),
        ];
    }

    public function getStatus(string $envelopeId): array
    {
        return [
            'envelope_id' => $envelopeId,
            'status' => 'completed',
            'signers' => [],
        ];
    }

    public function verifyWebhook(Request $request): bool
    {
        return true;
    }

    public function processWebhook(Request $request): array
    {
        return [
            'event_type' => 'envelope.completed',
            'envelope_id' => $request->input('envelope_id', Str::uuid()->toString()),
            'signer_data' => [],
        ];
    }

    public function testConnection(): bool
    {
        return true;
    }
}
