<?php

namespace Modules\AccountingCountry\Singapore\Services;

use App\Accounting\Contracts\InvoiceSchemaMapperContract;
use App\Models\Invoice;

/**
 * Singapore PEPPOL Invoice Mapper
 * Transforms GL invoice data to PEPPOL UBL 3.0 XML format
 */
class SFRSInvoiceMapper implements InvoiceSchemaMapperContract
{
    /**
     * Map GL invoice to PEPPOL format
     */
    public function mapInvoice(Invoice $invoice): array
    {
        return [
            'invoice_id' => $invoice->id,
            'document_type' => 'Invoice',
            'specification_id' => 'urn:cen.eu:en16931:2017',
            'custom_id' => $invoice->invoice_number,
            'issue_date' => $invoice->issued_date->format('Y-m-d'),
            'due_date' => $invoice->due_date?->format('Y-m-d'),
            'invoice_period' => [
                'start_date' => $invoice->issued_date->startOfMonth()->format('Y-m-d'),
                'end_date' => $invoice->issued_date->endOfMonth()->format('Y-m-d'),
            ],
            'supplier' => $this->mapParty($invoice->supplier, 'supplier'),
            'customer' => $this->mapParty($invoice->customer, 'customer'),
            'line_items' => $invoice->lineItems->map(function ($line) use ($invoice) {
                return $this->mapLineItem($line, $invoice);
            })->toArray(),
            'totals' => $this->mapTotals($invoice),
            'payment_terms' => $this->mapPaymentTerms($invoice),
            'allowances_charges' => $this->mapAllowances($invoice),
        ];
    }

    /**
     * Map credit note
     */
    public function mapCreditNote(object $creditNote): array
    {
        $mapped = $this->mapInvoice($creditNote->originalInvoice);
        $mapped['document_type'] = 'CreditNote';
        $mapped['related_invoice_id'] = $creditNote->originalInvoice->id;
        $mapped['reason'] = $creditNote->reason;

        return $mapped;
    }

    /**
     * Get required fields for SG/Peppol payload generation.
     */
    public function getRequiredFields(): array
    {
        return [
            'invoice_id',
            'document_type',
            'custom_id',
            'issue_date',
            'supplier',
            'customer',
            'line_items',
            'totals',
        ];
    }

    /**
     * Validate mapped payload structure
     */
    public function validatePayload(array $payload): bool
    {
        // Check required fields
        $required = ['invoice_id', 'document_type', 'supplier', 'customer', 'line_items', 'totals'];
        foreach ($required as $field) {
            if (! isset($payload[$field])) {
                return false;
            }
        }

        // Validate party structure
        if (! $this->validateParty($payload['supplier'])) {
            return false;
        }
        if (! $this->validateParty($payload['customer'])) {
            return false;
        }

        // Validate line items
        if (empty($payload['line_items'])) {
            return false;
        }

        return true;
    }

    /**
     * Map party (supplier/customer) to PEPPOL format
     */
    private function mapParty($party, string $type): array
    {
        return [
            'party_id' => [
                'scheme_id' => 'SG_UEN',
                'value' => $party->registration_number ?? $party->id,
            ],
            'party_name' => $party->name,
            'party_legal_entity' => [
                'registration_name' => $party->legal_name ?? $party->name,
                'company_id' => $party->registration_number,
            ],
            'party_address' => [
                'street' => $party->address_line_1,
                'city' => $party->city,
                'postal_code' => $party->postal_code,
                'country_code' => 'SG',
            ],
            'party_contact' => [
                'name' => $party->contact_person,
                'telephone' => $party->phone,
                'email' => $party->email,
            ],
        ];
    }

    /**
     * Map line item
     */
    private function mapLineItem($line, $invoice): array
    {
        return [
            'id' => (string) $line->id,
            'quantity' => $line->quantity,
            'unit_code' => $line->unit_code ?? 'C62', // Default: piece
            'description' => $line->description,
            'unit_price' => [
                'amount' => $line->unit_price,
                'currency_id' => $invoice->currency_code ?? 'SGD',
            ],
            'line_extension_amount' => [
                'amount' => $line->total_amount,
                'currency_id' => $invoice->currency_code ?? 'SGD',
            ],
            'item' => [
                'name' => $line->description,
                'classification_code' => $line->gst_classification_code ?? null,
            ],
            'accounting_cost' => $line->cost_center ?? null,
        ];
    }

    /**
     * Map invoice totals
     */
    private function mapTotals($invoice): array
    {
        // G-37 (#274): tax breakdown per Peppol category (S/Z/E/O) sourced
        // from the persisted determination when available; falls back to a
        // rate-based split from the lines.
        $determinationLines = [];
        if (function_exists('tenant') && tenant() !== null && isset($invoice->id)) {
            $determinationLines = app(\App\Services\TaxDeterminationService::class)
                ->latestDeterminationLines('invoice', (int) $invoice->id);
        }

        $categories = [];
        $lines = $invoice->lineItems ?? $invoice->lines ?? collect();

        foreach ($lines as $line) {
            $det = $determinationLines[$line->id] ?? null;
            $treatment = $det['treatment'] ?? null;

            $code = match ($treatment) {
                'standard_rated' => 'S',
                'zero_rated' => 'Z',
                'exempt' => 'E',
                'out_of_scope' => 'O',
                default => (float) ($line->tax_amount ?? 0) > 0 ? 'S' : 'Z',
            };

            $taxable = (float) ($det['taxable_base']
                ?? ((float) ($line->quantity ?? 1) * (float) ($line->unit_price ?? 0) - (float) ($line->discount_amount ?? 0)));
            $taxAmount = (float) ($det['tax_amount'] ?? $line->tax_amount ?? 0);
            $rate = (float) ($det['tax_rate'] ?? $line->tax_rate ?? 0);

            $categories[$code] ??= ['code' => $code, 'percent' => $rate, 'taxable' => 0.0, 'tax' => 0.0];
            $categories[$code]['taxable'] = round($categories[$code]['taxable'] + $taxable, 2);
            $categories[$code]['tax'] = round($categories[$code]['tax'] + $taxAmount, 2);
        }

        $taxSubtotals = [];

        foreach ($categories as $category) {
            $taxSubtotals[] = [
                'tax_category_code' => $category['code'],
                'percent' => $category['percent'],
                'taxable_amount' => [
                    'amount' => $category['taxable'],
                    'currency_id' => $invoice->currency_code ?? 'SGD',
                ],
                'tax_amount' => [
                    'amount' => $category['tax'],
                    'currency_id' => $invoice->currency_code ?? 'SGD',
                ],
            ];
        }

        if ($taxSubtotals === []) {
            $taxSubtotals[] = [
                'tax_category_code' => 'S', // Standard rate
                'percent' => $invoice->tax_rate ?? 0,
                'taxable_amount' => [
                    'amount' => $invoice->subtotal,
                    'currency_id' => $invoice->currency_code ?? 'SGD',
                ],
                'tax_amount' => [
                    'amount' => $invoice->tax_amount ?? 0,
                    'currency_id' => $invoice->currency_code ?? 'SGD',
                ],
            ];
        }

        return [
            'line_extension_total_amount' => [
                'amount' => $invoice->subtotal,
                'currency_id' => $invoice->currency_code ?? 'SGD',
            ],
            'tax_total' => [
                'amount' => $invoice->tax_amount ?? 0,
                'currency_id' => $invoice->currency_code ?? 'SGD',
                'tax_subtotal' => $taxSubtotals,
            ],
            'legal_monetary_total' => [
                'line_extension_total_amount' => [
                    'amount' => $invoice->subtotal,
                    'currency_id' => $invoice->currency_code ?? 'SGD',
                ],
                'tax_exclusive_amount' => [
                    'amount' => $invoice->subtotal,
                    'currency_id' => $invoice->currency_code ?? 'SGD',
                ],
                'tax_inclusive_amount' => [
                    'amount' => $invoice->total_amount,
                    'currency_id' => $invoice->currency_code ?? 'SGD',
                ],
                'prepaid_amount' => [
                    'amount' => $invoice->prepaid_amount ?? 0,
                    'currency_id' => $invoice->currency_code ?? 'SGD',
                ],
                'payable_amount' => [
                    'amount' => $invoice->total_amount - ($invoice->prepaid_amount ?? 0),
                    'currency_id' => $invoice->currency_code ?? 'SGD',
                ],
            ],
        ];
    }

    /**
     * Map payment terms
     */
    private function mapPaymentTerms($invoice): array
    {
        if (! $invoice->payment_terms) {
            return [];
        }

        return [
            'note' => $invoice->payment_terms,
            'penalty_surcharge_percent' => null,
        ];
    }

    /**
     * Map allowances and charges
     */
    private function mapAllowances($invoice): array
    {
        $allowances = [];

        if ($invoice->discount_amount && $invoice->discount_amount > 0) {
            $allowances[] = [
                'charge_indicator' => false, // Allowance
                'reason_code' => '95', // Discount
                'reason' => 'Discount',
                'amount' => [
                    'amount' => $invoice->discount_amount,
                    'currency_id' => $invoice->currency_code ?? 'SGD',
                ],
            ];
        }

        return $allowances;
    }

    /**
     * Validate party structure
     */
    private function validateParty(array $party): bool
    {
        return isset($party['party_name']) && isset($party['party_address']);
    }
}
