<?php

namespace App\Accounting\Contracts;

use App\Models\Invoice;

/**
 * Maps invoices to country-specific schema formats
 * Transforms GL invoice data to compliance submission formats
 */
interface InvoiceSchemaMapperContract
{
    /**
     * Map invoice to country-specific payload
     * 
     * @param Invoice $invoice
     * @return array Structured payload for submission
     */
    public function mapInvoice(Invoice $invoice): array;

    /**
     * Map credit note to country format
     * 
     * @param object $creditNote
     * @return array
     */
    public function mapCreditNote(object $creditNote): array;

    /**
     * Get required fields for submission
     * 
     * @return string[]
     */
    public function getRequiredFields(): array;

    /**
     * Validate mapped payload against schema
     * 
     * @param array $payload
     * @return bool
     */
    public function validatePayload(array $payload): bool;
}
