<?php

namespace App\Accounting\Contracts;

/**
 * #699 (EC2) — credential validation belongs to the country pack, not to the
 * country-neutral core. A pack whose submission flow needs tenant credentials
 * implements this interface; the core asks via instanceof instead of branching
 * on a country code.
 */
interface SupportsCredentialValidation
{
    /**
     * @param  array  $context  The tenant/entity provider context
     * @return string|null an onboarding error message, or null when complete
     */
    public function validateCredentials(array $context): ?string;
}
