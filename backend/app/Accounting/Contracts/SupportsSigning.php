<?php

namespace App\Accounting\Contracts;

/**
 * #699 (EC2) — an explicit capability instead of the core probing with
 * method_exists(): a provider that can sign documents implements this.
 */
interface SupportsSigning
{
    /**
     * Sign a mapped payload with the pack's configured certificate.
     *
     * @param  array  $payload  The mapped document payload
     * @param  array  $context  The tenant/entity provider context
     * @return array ['success' => bool, 'signed_document' => array|null, 'signature_metadata' => array|null, 'message' => string|null]
     */
    public function signDocument(array $payload, array $context): array;
}
