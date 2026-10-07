<?php

namespace App\Services;

use App\Accounting\DTOs\AccountingDocumentLineData;
use App\Accounting\DTOs\AccountingOriginBillData;
use App\Accounting\DTOs\AccountingOriginInvoiceData;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Vendor;

/**
 * Validates and enforces policies on accounting-origin document creation.
 *
 * This service is the single gate for all business-rule validation before
 * an invoice or bill is persisted via the accounting-origin path.
 * It does NOT handle GL posting — that remains with PostingEngine.
 */
class AccountingDocumentPolicyService
{
    /**
     * Validate an accounting-origin invoice request.
     *
     * @throws \InvalidArgumentException on policy violation
     */
    public function validateInvoice(AccountingOriginInvoiceData $data): void
    {
        // Customer must exist
        if (! Customer::where('id', $data->customerId)->exists()) {
            throw new \InvalidArgumentException("Customer ID {$data->customerId} does not exist.");
        }

        // Must have at least one line
        if (empty($data->lines)) {
            throw new \InvalidArgumentException('Invoice must have at least one line.');
        }

        foreach ($data->lines as $i => $line) {
            $this->validateLine($line, $i, 'invoice');
        }

        // Total must be positive
        // Issue #331: an invoice fully covered by its down payment may
        // total zero; never below.
        $total = $this->computeLineTotal($data->lines);
        $deductsDownPayment = collect($data->lines)->contains(fn ($line) => $line->downPaymentInvoiceId !== null);
        if ($deductsDownPayment ? round($total, 2) < 0 : $total <= 0) {
            throw new \InvalidArgumentException('Invoice total must be positive.');
        }
    }

    /**
     * Validate an accounting-origin bill request.
     *
     * @throws \InvalidArgumentException on policy violation
     */
    public function validateBill(AccountingOriginBillData $data): void
    {
        // Vendor must exist
        if (! Vendor::where('id', $data->vendorId)->exists()) {
            throw new \InvalidArgumentException("Vendor ID {$data->vendorId} does not exist.");
        }

        // Valid bill type
        if (! in_array($data->billType, ['expense', 'procurement'], true)) {
            throw new \InvalidArgumentException("Invalid bill_type: {$data->billType}. Must be 'expense' or 'procurement'.");
        }

        // Must have at least one line
        if (empty($data->lines)) {
            throw new \InvalidArgumentException('Bill must have at least one line.');
        }

        foreach ($data->lines as $i => $line) {
            $this->validateLine($line, $i, 'bill');

            // Expense bill lines must NOT have product_id — sole exception
            // (MRD R4.5): a withholding-linked product, which IS the rule
            // identity for s.109B/s.45 deduction and cannot be free text.
            if ($data->billType === 'expense' && $line->productId !== null
                && ! \App\Support\TaxWithholding::lineIsWithholding($line->productId)) {
                throw new \InvalidArgumentException(
                    "Line {$i}: expense bill lines may only reference a product whose linked tax rule is withholding. Use description instead."
                );
            }

            // Expense bill lines must have a GL account
            if ($data->billType === 'expense' && $line->glAccountId === null && $line->lineType === 'expense') {
                // account_id is required for expense lines on bills
                // (will be mapped to account_id on bill_lines)
            }
        }

        // Total must be positive
        $total = $this->computeLineTotal($data->lines);
        if ($total <= 0) {
            throw new \InvalidArgumentException('Bill total must be positive.');
        }
    }

    /**
     * Validate a single document line.
     */
    private function validateLine(AccountingDocumentLineData $line, int $index, string $docType): void
    {
        $validTypes = ['product', 'service', 'expense', 'tax', 'discount'];
        if (! in_array($line->lineType, $validTypes, true)) {
            throw new \InvalidArgumentException(
                "Line {$index}: invalid line_type '{$line->lineType}'. Must be one of: ".implode(', ', $validTypes)
            );
        }

        if (empty(trim($line->description))) {
            throw new \InvalidArgumentException("Line {$index}: description is required.");
        }

        if ($line->quantity <= 0) {
            throw new \InvalidArgumentException("Line {$index}: quantity must be greater than zero.");
        }

        // Issue #331: a "Less: down payment" deduction is the one negative
        // line allowed; its amount is re-checked against the down payment
        // when the invoice posts (InvoiceService).
        if ($line->unitPrice < 0 && ! ($docType === 'invoice' && $line->downPaymentInvoiceId !== null)) {
            throw new \InvalidArgumentException("Line {$index}: unit_price must not be negative.");
        }

        // Product lines must have a product_id
        // (a down-payment deduction is shaped like the order → invoice one:
        // a product-type line with no product).
        if ($line->lineType === 'product' && $line->productId === null && $line->downPaymentInvoiceId === null) {
            throw new \InvalidArgumentException("Line {$index}: product lines must have a product_id.");
        }

        // GL account override must reference a valid account
        if ($line->glAccountId !== null) {
            if (! ChartOfAccount::where('id', $line->glAccountId)->exists()) {
                throw new \InvalidArgumentException(
                    "Line {$index}: GL account ID {$line->glAccountId} does not exist."
                );
            }
        }

        if ($line->taxRate < 0 || $line->taxRate > 100) {
            throw new \InvalidArgumentException("Line {$index}: tax_rate must be between 0 and 100.");
        }

        if ($line->discountAmount < 0) {
            throw new \InvalidArgumentException("Line {$index}: discount_amount must not be negative.");
        }
    }

    /**
     * Compute total across lines (subtotal before discount).
     *
     * @param  AccountingDocumentLineData[]  $lines
     */
    private function computeLineTotal(array $lines): float
    {
        $total = 0;
        foreach ($lines as $line) {
            // MRD R3.1: engine-owned arithmetic (discount handled in-engine).
            $calc = \App\Services\TaxEngineService::calculate(
                (float) ($line->quantity * $line->unitPrice),
                (float) $line->taxRate,
                false,
                (float) $line->discountAmount,
            );
            $total += $calc['gross'];
        }

        return $total;
    }
}
