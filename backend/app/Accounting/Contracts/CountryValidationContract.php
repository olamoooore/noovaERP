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
     * @param Invoice $invoice
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public function validateInvoice(Invoice $invoice): array;

    /**
     * Validate account mappings for country standards
     * 
     * @param string $accountCode
     * @param string $accountType
     * @return bool
     */
    public function validateAccountMapping(string $accountCode, string $accountType): bool;

    /**
     * Get validation error messages
     * 
     * @return string[]
     */
    public function getErrors(): array;
}
