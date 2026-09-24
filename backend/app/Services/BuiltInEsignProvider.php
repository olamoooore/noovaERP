<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EsignProviderInterface;
use App\Models\EsignRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Built-in (self-hosted) e-signature provider.
 *
 * There is no third-party service: an "envelope" is an EsignRequest row and the
 * signing happens in our own public signing page. This class exists so the rest
 * of the platform keeps talking to EsignProviderInterface — swapping in a real
 * external provider later means binding a different implementation, nothing
 * else.
 *
 * It never completes an envelope on its own. The document only becomes signed
 * when the customer actually signs (EsignService::complete), which is what makes
 * this an e-signature rather than a UI that says "sent".
 */
class BuiltInEsignProvider implements EsignProviderInterface
{
    /**
     * Create the signature request and return its token as the envelope id.
     *
     * @param  array{invoice_id?:int,document_type?:string,document_id?:int,document_number?:string,signers?:array,template_id?:int}  $data
     * @return array{envelope_id:string,status:string,sent_at:string}
     */
    public function createEnvelope(array $data): array
    {
        $token = Str::random(48);

        $request = EsignRequest::create([
            'document_type' => $data['document_type'] ?? 'invoice',
            'document_id' => (int) ($data['document_id'] ?? $data['invoice_id'] ?? 0),
            'document_number' => $data['document_number'] ?? null,
            'template_id' => isset($data['template_id']) ? (int) $data['template_id'] : null,
            'token' => $token,
            'status' => EsignRequest::STATUS_PENDING,
            'signer_name' => $data['signers'][0]['name'] ?? null,
            'signer_email' => $data['signers'][0]['email'] ?? null,
            'sent_at' => now(),
            'expires_at' => now()->addDays((int) ($data['expires_in_days'] ?? 30)),
            'created_by' => auth()->id(),
        ]);

        return [
            'envelope_id' => $request->token,
            'status' => 'sent',
            'sent_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{envelope_id:string,status:string,signers:array}
     */
    public function getStatus(string $envelopeId): array
    {
        $request = EsignRequest::where('token', $envelopeId)->first();

        if (! $request) {
            return ['envelope_id' => $envelopeId, 'status' => 'not_found', 'signers' => []];
        }

        return [
            'envelope_id' => $envelopeId,
            'status' => $request->isExpired() && $request->isPending() ? 'expired' : $request->status,
            'signers' => [[
                'name' => $request->signer_name,
                'email' => $request->signer_email,
                'signed' => $request->status === EsignRequest::STATUS_COMPLETED,
                'signed_at' => $request->signed_at?->toIso8601String(),
            ]],
        ];
    }

    /**
     * The built-in flow has no inbound webhook to verify — completion arrives
     * through our own signing endpoint, authenticated by the token itself.
     */
    public function verifyWebhook(Request $request): bool
    {
        return false;
    }

    /**
     * @return array{event_type:string,envelope_id:?string,signer_data:array}
     */
    public function processWebhook(Request $request): array
    {
        return ['event_type' => 'unsupported', 'envelope_id' => null, 'signer_data' => []];
    }

    public function testConnection(): bool
    {
        return true;
    }

    /** Human label for logs/activity entries. */
    public function name(): string
    {
        return 'Built-in e-signature';
    }
}
