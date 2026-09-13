<?php

namespace Modules\AccountingCountry\Malaysia\Services;

use App\Accounting\Contracts\CountryValidationContract;
use App\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * Validates invoices against Malaysia MFRS and MyInvois requirements.
 */
class MFRSValidationService implements CountryValidationContract
{
    protected array $errors = [];

    private array $providerContext = [];

    public function validateInvoice(Invoice $invoice, array $providerContext = []): array
    {
        $this->providerContext = $providerContext;
        $errors = [];

        if (! $invoice->invoice_date) {
            $errors[] = 'Invoice date is required';
        }
        if (! $invoice->invoice_number) {
            $errors[] = 'Invoice number is required';
        }

        $supplierTin = $this->resolveSupplierValue($invoice, [
            'tax_identification_number', 'tax_id', 'tin',
            'tax_registration_number', 'tax_number',
        ], 'supplier_tin');
        if (! $supplierTin) {
            $errors[] = 'Supplier TIN is required for Malaysia MyInvois submission';
        }

        $supplierRegistration = $this->resolveSupplierValue($invoice, [
            'registration_number', 'company_registration', 'tax_id',
            'tax_registration_number', 'tax_number',
        ], 'supplier_registration_number');
        $supplierIdentityNumber = $this->resolveSupplierValue(
            $invoice,
            ['identity_number', 'nric', 'id_number'],
            'supplier_id_number'
        );
        $supplierIdentityType = $this->resolveSupplierValue(
            $invoice,
            ['identity_type', 'id_type'],
            'supplier_id_type'
        );

        if (! $supplierRegistration && ! $supplierIdentityNumber) {
            $errors[] = 'Supplier company registration or identity document is required for Malaysia';
        } elseif ($supplierRegistration && ! $this->validateCompanyId($supplierRegistration)) {
            $errors[] = 'Invalid supplier company registration format';
        }
        if ($supplierIdentityNumber && ! $supplierIdentityType) {
            $errors[] = 'Supplier identity type is required when identity number is provided';
        }

        if (! isset($invoice->tax_amount) || is_null($invoice->tax_amount)) {
            $errors[] = 'Tax amount must be specified (0 if exempt)';
        } else {
            $taxRate = $invoice->tax_rate ?? 0;
            if ($taxRate > 0 && ! in_array((float) $taxRate, [6.0], true)) {
                $errors[] = "Invalid SST rate {$taxRate}% for Malaysia (must be 0 or 6)";
            }
        }

        $customer = $invoice->customer;
        if (! $customer) {
            $errors[] = 'Customer/Buyer details are required';
        } elseif ($this->isBusinessCustomer($customer)
            && ! ($customer->registration_number ?? $customer->tax_number ?? null)) {
            $errors[] = 'Buyer company registration is required if not individual';
        }

        $lines = $invoice->lineItems ?? $invoice->lines ?? collect();
        if ($lines->isEmpty()) {
            if ((float) ($invoice->subtotal ?? 0) <= 0 && (float) ($invoice->total ?? 0) <= 0) {
                $errors[] = 'Invoice must have at least one line item';
            }
        } else {
            foreach ($lines as $line) {
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

        $issuedAt = $invoice->invoice_date ?? $invoice->issued_date ?? null;
        if ($issuedAt && ! $issuedAt instanceof Carbon) {
            try {
                Carbon::parse($issuedAt);
            } catch (\Throwable) {
                $errors[] = 'Invoice date is invalid';
            }
        }

        $this->errors = array_values(array_unique($errors));

        return ['valid' => $this->errors === [], 'errors' => $this->errors];
    }

    public function validateAccountMapping(string $accountCode, string $accountType): bool
    {
        return $accountCode !== '' && $accountType !== '';
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    private function resolveSupplierValue(Invoice $invoice, array $attributes, string $configKey): ?string
    {
        $supplier = $invoice->supplier ?? null;
        foreach ($attributes as $attribute) {
            $value = $supplier?->{$attribute} ?? null;
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        $value = $this->providerContext[$configKey] ?? null;
        if ($value === null || $value === '') {
            $value = app()->bound('config')
                ? config("compliance.malaysia.{$configKey}")
                : null;
        }

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    private function isBusinessCustomer(object $customer): bool
    {
        $type = strtolower((string) ($customer->type ?? ''));
        if ($type === 'individual') {
            return false;
        }

        return $type === 'company' || ! empty($customer->company);
    }

    private function validateCompanyId(string $id): bool
    {
        $normalized = preg_replace('/[\s\-]/', '', $id);

        return preg_match('/^[0-9]+$/', $normalized) === 1;
    }
}
