<?php

namespace App\Accounting\Contracts;

/**
 * #699 (EC2/AC3) — payload defaults that depend on the pack's tenant/provider
 * context (supplier identity overlay and the like) belong to the pack, not to
 * the country-neutral core.
 */
interface SupportsPayloadDefaults
{
    /**
     * Overlay provider/tenant context onto a mapped payload before validation,
     * signing and submission.
     *
     * @param  array  $payload  The mapped document payload
     * @param  array  $context  The tenant/entity provider context
     */
    public function applyPayloadDefaults(array $payload, array $context): array;
}
