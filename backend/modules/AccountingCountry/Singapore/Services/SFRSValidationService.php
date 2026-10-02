<?php

namespace Modules\AccountingCountry\Singapore\Services;

use App\Accounting\Contracts\CountryValidationContract;
use App\Models\Invoice;

/**
 * Singapore SFRS (Singapore Financial Reporting Standards) Validation
 * Enforces Singapore Accounting Standards and PEPPOL requirements
 */
class SFRSValidationService implements CountryValidationContract
{
    protected array $errors = [];

    public function __construct(
        private ?SFRSTaxonomyProfile $taxonomyProfile = null
    ) {
        $this->taxonomyProfile ??= new SFRSTaxonomyProfile;
    }

    /**
     * Validate invoice against Singapore requirements
     * - SFRS/MFRS compliance
     * - PEPPOL UBL/XML compatibility
     * - GST identification
     */
    public function validateInvoice(Invoice $invoice): array
    {
        $errors = [];

        // Required fields
        if (! $invoice->invoice_date) {
            $errors[] = 'Invoice date is required';
        }
        if (! $invoice->invoice_number) {
            $errors[] = 'Invoice number is required';
        }
        if (! $invoice->customer_id) {
            $errors[] = 'Customer/Buyer is required';
        }

        // Singapore UEN validation
        $supplier = $invoice->supplier;
        if (! $supplier || ! $supplier->registration_number) {
            $errors[] = 'Supplier UEN is required for Singapore registration';
        } else {
            if (! $this->validateUEN($supplier->registration_number)) {
                $errors[] = 'Invalid supplier UEN format';
            }
        }

        // Customer/Buyer validation
        $customer = $invoice->customer;
        if (! $customer) {
            $errors[] = 'Buyer details are required';
        } else {
            if ($customer->is_sme && ! $customer->registration_number) {
                // SMEs can be exempt from UEN requirement but must be identifiable
            } elseif (! $customer->is_sme && ! $customer->registration_number) {
                $errors[] = 'Buyer UEN is required for non-SME entities';
            }
        }

        // GST/Tax validation
        if (! isset($invoice->tax_amount) || is_null($invoice->tax_amount)) {
            $errors[] = 'Tax amount must be specified (0 if exempt)';
        } else {
            $taxRate = $invoice->tax_rate ?? 0;
            if ($taxRate > 0 && $taxRate != 8) {
                // Singapore GST is 8% (increased from 7% in April 2024)
                $errors[] = "Invalid tax rate {$taxRate}% for Singapore (must be 0 or 8)";
            }
        }

        // Line items validation
        if (! $invoice->lineItems || $invoice->lineItems->isEmpty()) {
            $errors[] = 'Invoice must have at least one line item';
        } else {
            foreach ($invoice->lineItems as $line) {
                if (! $line->description) {
                    $errors[] = 'All line items must have descriptions';
                    break;
                }
                if (! isset($line->quantity) || ! isset($line->unit_price)) {
                    $errors[] = 'All line items must have quantity and unit price';
                    break;
                }
            }
        }

        // PEPPOL format validation
        if ($invoice->issued_date && $invoice->issued_date->isFuture()) {
            $errors[] = 'Invoice date cannot be in the future';
        }

        $this->errors = $errors;

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate account mapping for Singapore GL structure
     */
    public function validateAccountMapping(string $accountCode, string $accountType): bool
    {
        $validation = $this->taxonomyProfile->validateAccountMapping($accountCode, $accountType);

        if (! $validation['valid']) {
            $this->errors = array_values(array_unique(array_merge($this->errors, $validation['errors'])));
        }

        return $validation['valid'];
    }

    /**
     * Return cached validation errors from the latest run.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Singapore UEN validation
     * Format: XXXXXXXXXX (10 digits) or with pattern check
     */
    private function validateUEN(string $uen): bool
    {
        // Remove spaces/dashes
        $uen = preg_replace('/[\s\-]/', '', $uen);

        // Must be 10-12 characters alphanumeric
        return preg_match('/^[0-9]{10}[A-Z]$|^[0-9]{8}[A-Z]{2}$/', $uen);
    }
}
