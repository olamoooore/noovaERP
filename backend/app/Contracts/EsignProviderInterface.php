<?php

namespace App\Contracts;

use Illuminate\Http\Request;

interface EsignProviderInterface
{
    /**
     * Create an e-sign envelope for an invoice.
     *
     * @param  array  $data  [invoice_id, invoice_number, signers[], document_url or document_content]
     * @return array [envelope_id, status, sent_at]
     */
    public function createEnvelope(array $data): array;

    /**
     * Get the status of an envelope.
     *
     * @param  string  $envelopeId  The provider's envelope ID
     * @return array [envelope_id, status, signers[]]
     */
    public function getStatus(string $envelopeId): array;

    /**
     * Verify the webhook signature from the provider.
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * Process a webhook callback from the provider.
     *
     * @return array [event_type, envelope_id, signer_data[]]
     */
    public function processWebhook(Request $request): array;

    /**
     * Test connectivity to the e-sign provider.
     */
    public function testConnection(): bool;
}
