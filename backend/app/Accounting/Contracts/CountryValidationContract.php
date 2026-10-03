<?php

namespace App\Accounting\Contracts;

use App\Models\Invoice;

/**
 * Country-specific validation rules for invoices
 * Plugins implement this to enforce jurisdiction-specific business rules
 */
interface CountryValidationContract
{
    /**
     * Validate invoice against country-specific rules
     *
     * #699 (EC2): the context carries the tenant/entity provider context
     * (supplier identity, environment, document type) so a pack can resolve
     * tenant-specific requirements without the core branching on a country
     * code.
     *
     * @param  array  $context  Provider/tenant context for the submission
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public function validateInvoice(Invoice $invoice, array $context = []): array;

    /**
     * Validate account mappings for country standards
     */
    public function validateAccountMapping(string $accountCode, string $accountType): bool;

    /**
     * Get validation error messages
     *
     * @return string[]
     */
    public function getErrors(): array;
}
