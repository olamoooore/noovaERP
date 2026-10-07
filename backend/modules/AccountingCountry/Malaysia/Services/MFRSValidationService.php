<?php

namespace Modules\AccountingCountry\Malaysia\Services;

use App\Accounting\Contracts\CountryValidationContract;
use App\Accounting\Contracts\ExplainsSubmissionErrors;
use App\Accounting\Contracts\SupportsCredentialValidation;
use App\Accounting\Contracts\SupportsReadinessCheck;
use App\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * Validates invoices against Malaysia MFRS and MyInvois requirements.
 */
class MFRSValidationService implements CountryValidationContract, ExplainsSubmissionErrors, SupportsCredentialValidation, SupportsReadinessCheck
{
    /**
     * #699 (EC2): MyInvois credential requirements belong to the MY pack —
     * the core used to branch on `countryCode === 'MY'` to call this.
     *
     * @return string|null an onboarding error message, or null when complete
     */
    public function validateCredentials(array $context): ?string
    {
        $required = [
            'myinvoice_client_id' => 'LHDN API Client ID',
            'myinvoice_client_secret' => 'LHDN API Client Secret',
            'supplier_tin' => 'Supplier TIN',
            'supplier_name' => 'Supplier Name',
        ];

        $missing = [];
        foreach ($required as $key => $label) {
            $val = $context[$key] ?? null;
            if ($val === null || $val === '') {
                $missing[] = $label;
            }
        }

        if (empty($missing)) {
            return null;
        }

        return 'MyInvois credentials are not fully configured for this tenant. '
            .'Missing: '.implode(', ', $missing).'. '
            .'Configure them at Accounting → MyInvois Settings.';
    }

    protected array $errors = [];

    private array $providerContext = [];

    public function validateInvoice(Invoice $invoice, array $providerContext = []): array
    {
        $errors = array_map(
            fn (array $issue) => $issue['message'],
            $this->collectInvoiceIssues($invoice, $providerContext),
        );

        $this->errors = array_values(array_unique($errors));

        return ['valid' => $this->errors === [], 'errors' => $this->errors];
    }

    /**
     * #671 (EINV-UX2): the pre-submission dry run shown before posting. It is
     * this validator's own rules (the same ones submission enforces, as
     * blocking issues) plus what the MyInvois mapper/provider would otherwise
     * silently default (as warnings naming the default). Nothing is written
     * and no provider is called.
     */
    public function readinessIssues(Invoice $invoice, array $context): array
    {
        $issues = [];

        foreach ($this->collectInvoiceIssues($invoice, $context) as $issue) {
            $issues[] = [
                'code' => $issue['code'],
                'severity' => 'blocking',
                'message' => $issue['message'],
                'fix' => $this->fixTarget($issue['target'], $invoice, $context),
            ];
        }

        $credentialError = $this->validateCredentials($context);
        if ($credentialError !== null) {
            $issues[] = [
                'code' => 'credentials_missing',
                'severity' => 'blocking',
                'message' => $credentialError,
                'fix' => ['type' => 'einvoice_settings', 'id' => null, 'fields' => []],
            ];
        }

        $mapper = app(MyInvoiceMapper::class);

        $seenProducts = [];
        foreach ($mapper->linesWithoutOwnClassification($invoice) as $gap) {
            $product = $gap['product'];
            if ($product !== null) {
                if (isset($seenProducts[$product->id])) {
                    continue;
                }
                $seenProducts[$product->id] = true;
            }

            $subject = $product !== null
                ? "Product '".(trim((string) ($product->name ?? '')) ?: 'Product #'.$product->id)."'"
                : 'Line '.$gap['line_number'];

            $issues[] = [
                'code' => 'line_classification_missing',
                'severity' => $gap['default'] === null ? 'blocking' : 'warning',
                'message' => $gap['default'] === null
                    ? "{$subject} has no LHDN classification code and the Malaysia pack declares no default — the line cannot be submitted."
                    : "{$subject} has no LHDN classification code — the pack default '{$gap['default']}' would be sent.",
                'fix' => $product !== null
                    ? ['type' => 'product', 'id' => (int) $product->id, 'fields' => ['einvoice_classification_code']]
                    : ['type' => 'invoice', 'id' => (int) $invoice->id, 'fields' => ['lines']],
            ];
        }

        $customer = $invoice->customer;
        if ($customer !== null) {
            $buyer = $mapper->mapBuyerParty($customer);
            $isGeneralPublic = strtoupper((string) ($buyer['tin'] ?? '')) === MyInvoiceMapper::LHDN_GENERAL_PUBLIC_TIN;
            $state = trim((string) ($buyer['address']['state'] ?? ''));

            // Same scope as the provider's state rule: a non-consolidated
            // Malaysian buyer. With no state the provider sends 14 (Kuala Lumpur).
            if (! $isGeneralPublic && ($buyer['address']['country'] ?? null) === 'MY' && $state === '') {
                $customerName = trim((string) ($customer->name ?? '')) ?: 'Customer';
                $issues[] = [
                    'code' => 'buyer_state_missing',
                    'severity' => 'warning',
                    'message' => "Customer '{$customerName}' has no state on its address — MyInvois would default the buyer state to 14 (Kuala Lumpur).",
                    'fix' => [
                        'type' => 'customer',
                        'id' => (int) $customer->id,
                        'fields' => ['state'],
                        'address_id' => $mapper->buyerAddressRecordId($customer),
                    ],
                ];
            }
        }

        return $issues;
    }

    /**
     * The validator's rules, each with a stable code and the record a user
     * edits to fix it. validateInvoice() returns the messages unchanged.
     *
     * @return list<array{code: string, message: string, target: string}>
     */
    private function collectInvoiceIssues(Invoice $invoice, array $providerContext): array
    {
        $this->providerContext = $providerContext;
        $issues = [];
        $add = function (string $code, string $message, string $target) use (&$issues): void {
            $issues[] = ['code' => $code, 'message' => $message, 'target' => $target];
        };

        if (! $invoice->invoice_date) {
            $add('invoice_date_missing', 'Invoice date is required', 'invoice');
        }
        if (! $invoice->invoice_number) {
            $add('invoice_number_missing', 'Invoice number is required', 'invoice');
        }

        $supplierTin = $this->resolveSupplierValue($invoice, [
            'tax_identification_number', 'tax_id', 'tin',
            'tax_registration_number', 'tax_number',
        ], 'supplier_tin');
        if (! $supplierTin) {
            $add('supplier_tin_missing', 'Supplier TIN is required for Malaysia MyInvois submission', 'supplier');
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
            $add('supplier_registration_missing', 'Supplier company registration or identity document is required for Malaysia', 'supplier');
        } elseif ($supplierRegistration && ! $this->validateCompanyId($supplierRegistration)) {
            $add('supplier_registration_invalid', 'Invalid supplier company registration format', 'supplier');
        }
        if ($supplierIdentityNumber && ! $supplierIdentityType) {
            $add('supplier_identity_type_missing', 'Supplier identity type is required when identity number is provided', 'supplier');
        }

        if (! isset($invoice->tax_amount) || is_null($invoice->tax_amount)) {
            $add('tax_amount_missing', 'Tax amount must be specified (0 if exempt)', 'invoice');
        } else {
            $taxRate = $invoice->tax_rate ?? 0;
            if ($taxRate > 0 && ! in_array((float) $taxRate, [6.0], true)) {
                $add('tax_rate_invalid', "Invalid SST rate {$taxRate}% for Malaysia (must be 0 or 6)", 'invoice');
            }
        }

        $customer = $invoice->customer;
        if (! $customer) {
            $add('buyer_missing', 'Customer/Buyer details are required', 'invoice');
        } elseif ($this->isBusinessCustomer($customer) && $this->resolveBuyerTin($customer) === null) {
            // Issue #664: LHDN requires a real buyer TIN for business buyers —
            // the general-public TIN is only for individual/consolidated B2C
            // invoices. Fail with an actionable message before any mapping or
            // provider call.
            $customerName = trim((string) ($customer->name ?? '')) ?: 'Customer';
            $add('buyer_tin_missing', "Customer '{$customerName}' has no TIN — add it or mark as individual", 'customer');
        }

        $lines = $invoice->lineItems ?? $invoice->lines ?? collect();
        if ($lines->isEmpty()) {
            if ((float) ($invoice->subtotal ?? 0) <= 0 && (float) ($invoice->total ?? 0) <= 0) {
                $add('lines_missing', 'Invoice must have at least one line item', 'invoice');
            }
        } else {
            foreach ($lines as $line) {
                if (! $line->description) {
                    $add('line_description_missing', 'All line items must have descriptions', 'invoice');
                    break;
                }
                if (! isset($line->quantity) || ! isset($line->unit_price)) {
                    $add('line_quantity_price_missing', 'All line items must have quantity and unit price', 'invoice');
                    break;
                }
            }
        }

        $issuedAt = $invoice->invoice_date ?? $invoice->issued_date ?? null;
        if ($issuedAt && ! $issuedAt instanceof Carbon) {
            try {
                Carbon::parse($issuedAt);
            } catch (\Throwable) {
                $add('invoice_date_invalid', 'Invoice date is invalid', 'invoice');
            }
        }

        return $issues;
    }

    /**
     * Where a user fixes an issue: the invoice, the customer, or — for the
     * supplier identity — the legal entity when the identity came from it
     * (#668), otherwise the tenant e-invoice settings.
     *
     * @return array<string, mixed>
     */
    private function fixTarget(string $target, Invoice $invoice, array $context): array
    {
        return match ($target) {
            'customer' => [
                'type' => 'customer',
                'id' => $invoice->customer?->id !== null ? (int) $invoice->customer->id : null,
                'fields' => ['tax_number', 'buyer_type'],
            ],
            'supplier' => $this->supplierFixTarget($context),
            default => ['type' => 'invoice', 'id' => (int) $invoice->id, 'fields' => []],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierFixTarget(array $context): array
    {
        return ($context['supplier_identity_legal_entity_id'] ?? null) !== null
            ? [
                'type' => 'legal_entity',
                'id' => (int) $context['supplier_identity_legal_entity_id'],
                'fields' => ['tax_id', 'registration_number'],
            ]
            : ['type' => 'einvoice_settings', 'id' => null, 'fields' => []];
    }

    /**
     * #673 (EINV-UX4): the plain-language version of an error this pack's
     * MyInvois integration returned, with the record and field to fix.
     *
     * Only the codes MyInvoiceSubmissionProvider itself emits or classifies
     * (mapFinanceErrorCategory, PAYLOAD_VALIDATION_FAILED,
     * SIGNATURE_REQUIRED_IN_PRODUCTION) are translated. A message that is
     * one of this validator's own rules (the "Validation failed: ..." a
     * submission returns) is matched back to its readiness issue (#671).
     * Anything else is returned as received, with no fix.
     */
    public function explainSubmissionError(?string $code, string $message, ?object $source, array $context): array
    {
        $code = $code !== null && trim($code) !== '' ? strtoupper(trim($code)) : null;
        $invoice = $source instanceof Invoice ? $source : null;
        $customer = $source?->customer ?? null;
        $customerName = $customer !== null
            ? "customer '".(trim((string) ($customer->name ?? '')) ?: 'Customer #'.$customer->id)."'"
            : 'the customer';
        $customerFix = fn (array $fields) => $customer !== null
            ? ['type' => 'customer', 'id' => (int) $customer->id, 'fields' => $fields]
            : null;
        $supplierWhere = ($context['supplier_identity_legal_entity_id'] ?? null) !== null
            ? 'the issuing legal entity'
            : 'MyInvois settings';
        $settingsFix = ['type' => 'einvoice_settings', 'id' => null, 'fields' => []];

        $explained = fn (string $plain, ?array $fix, ?string $issueCode = null) => [
            'code' => $code,
            'issue_code' => $issueCode,
            'message' => $plain,
            'raw_message' => $message,
            'fix' => $fix,
        ];

        switch ($code) {
            case 'BUYER_TIN_INVALID':
                return [$explained("Buyer TIN not accepted by LHDN — check the TIN on {$customerName}.", $customerFix(['tax_number', 'buyer_type']))];
            case 'SUPPLIER_TIN_INVALID':
                return [$explained("Supplier TIN not accepted by LHDN — check the supplier TIN in {$supplierWhere}.", $this->supplierFixTarget($context))];
            case 'INVALID_TIN':
                return [$explained("A TIN on this document was not accepted by LHDN — check the TIN on {$customerName} and the supplier TIN in {$supplierWhere}.", $customerFix(['tax_number', 'buyer_type']))];
            case 'INVALID_REGISTRATION':
                return [$explained("A registration number on this document was not accepted by LHDN — check the registration number on {$customerName} and the supplier registration in {$supplierWhere}.", $customerFix(['company_registration_number']))];
            case 'INVALID_TAX_CODE':
            case 'INVALID_SST_RATE':
            case 'TAX_MISMATCH':
                return [$explained(
                    'LHDN did not accept the tax on this document — check the tax code and SST rate on its lines.',
                    $invoice !== null ? ['type' => 'invoice', 'id' => (int) $invoice->id, 'fields' => ['lines']] : null,
                )];
            case 'UNAUTHORIZED':
            case 'TOKEN_EXPIRED':
            case 'INVALID_API_KEY':
                return [$explained('LHDN refused the MyInvois API credentials — check the client ID and secret in MyInvois settings.', $settingsFix)];
            case 'SYSTEM_ERROR':
            case 'SERVICE_UNAVAILABLE':
            case 'TIMEOUT':
                return [$explained('The LHDN service did not respond — nothing needs to change on the document; retry it.', null)];
            case 'DUPLICATE_SUBMISSION':
                return [$explained('LHDN already holds this document — check its status on the MyInvois portal before retrying.', null)];
            case 'SIGNATURE_REQUIRED_IN_PRODUCTION':
                return [$explained('Production submissions must be digitally signed — set up the signing certificate in MyInvois settings.', $settingsFix)];
        }

        // One of this validator's own rules, reported by a submission as
        // "Validation failed: <rule>, <rule>": link each to its record.
        if ($invoice !== null) {
            $matched = [];
            foreach ($this->collectInvoiceIssues($invoice, $context) as $issue) {
                if (str_contains($message, $issue['message'])) {
                    $matched[] = $explained($issue['message'], $this->fixTarget($issue['target'], $invoice, $context), $issue['code']);
                }
            }
            if ($matched !== []) {
                return $matched;
            }
        }

        return [$explained($message, null)];
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
        $buyerType = strtolower(trim((string) ($customer->buyer_type ?? '')));
        if (in_array($buyerType, ['business', 'government', 'foreign'], true)) {
            return true;
        }
        if ($buyerType === 'individual') {
            return false;
        }

        $type = strtolower((string) ($customer->type ?? ''));
        if ($type === 'individual') {
            return false;
        }

        return $type === 'company' || ! empty($customer->company);
    }

    /**
     * Resolve the buyer TIN from the customer record (the same field list the
     * Malaysia mapper reads), returning null when none is captured.
     */
    private function resolveBuyerTin(object $customer): ?string
    {
        foreach (['tax_identification_number', 'tin', 'tax_number', 'tax_id'] as $attribute) {
            $value = $customer->{$attribute} ?? null;
            if ($value === null) {
                continue;
            }

            $trimmed = trim((string) $value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    private function validateCompanyId(string $id): bool
    {
        $normalized = preg_replace('/[\s\-]/', '', $id);

        return preg_match('/^[0-9]+$/', $normalized) === 1;
    }
}
