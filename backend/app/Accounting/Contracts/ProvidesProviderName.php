<?php

namespace App\Accounting\Contracts;

/**
 * #699 (EC2/AC3) — the provider declares its own provider name, so the core
 * does not carry a country-code → provider-name map.
 */
interface ProvidesProviderName
{
    /** e.g. "myinvoice" (MY), "peppol" (SG). */
    public function providerName(): string;
}
