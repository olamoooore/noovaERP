<?php

namespace App\Http\Controllers\Api;

use App\Contracts\EsignProviderInterface;
use App\Http\Controllers\Controller;
use App\Models\InvoiceEvidence;
use App\Services\InvoiceEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EsignWebhookController extends Controller
{
    public function __construct(
        private InvoiceEvidenceService $evidenceService
    ) {}

    public function handle(Request $request, string $provider = 'default')
    {
        $providerImpl = app(EsignProviderInterface::class);

        if (! $providerImpl->verifyWebhook($request)) {
            Log::warning('EsignWebhookController: invalid webhook signature', [
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        try {
            $webhookData = $providerImpl->processWebhook($request);

            if (($webhookData['event_type'] ?? '') === 'envelope.completed') {
                $envelopeId = $webhookData['envelope_id'];
                $signerData = $webhookData['signer_data'] ?? [];

                $evidence = InvoiceEvidence::where('external_id', $envelopeId)
                    ->where('source', \App\Models\InvoiceEvidence::SOURCE_ESIGN_PROVIDER)
                    ->first();

                if ($evidence) {
                    $invoice = $evidence->invoice;

                    $this->evidenceService->recordEsignCompletion(
                        $invoice,
                        class_basename($providerImpl),
                        $envelopeId,
                        [
                            'signer_name' => $signerData['name'] ?? null,
                            'signer_email' => $signerData['email'] ?? null,
                            'signer_ip' => $request->ip(),
                        ]
                    );

                    Log::info('EsignWebhookController: envelope completed', [
                        'envelope_id' => $envelopeId,
                        'invoice_id' => $invoice->id,
                    ]);
                }
            }

            return response()->json(['received' => true]);
        } catch (\Exception $e) {
            Log::error('EsignWebhookController: processing failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }
}
