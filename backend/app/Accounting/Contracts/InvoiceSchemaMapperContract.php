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
     * @return array Structured payload for submission
     */
    public function mapInvoice(Invoice $invoice): array;

    /**
     * Map credit note to country format
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
     */
    public function validatePayload(array $payload): bool;
}
