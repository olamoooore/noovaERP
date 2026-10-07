<?php

namespace App\Accounting\DTOs;

/**
 * Individual line for an accounting-origin document (invoice or bill).
 * Supports product, service, expense, tax, and discount line types.
 */
class AccountingDocumentLineData
{
    public function __construct(
        public readonly string $lineType, // product|service|expense|tax|discount
        public readonly string $description,
        public readonly int|float $quantity,
        public readonly float $unitPrice,
        public readonly ?int $productId = null,
        public readonly ?int $glAccountId = null,
        public readonly float $taxRate = 0,
        public readonly float $discountAmount = 0,
        public readonly ?int $costCenterId = null,
        public readonly ?int $projectId = null,
        public readonly ?int $departmentId = null,
        public readonly ?int $branchId = null,
        // Issue #456: stable link to the originating sales-order line so the
        // converted invoice keeps the same traceability as the SO→invoice path.
        public readonly ?int $salesOrderLineId = null,
        // Issue #331: a "Less: down payment" deduction line — its negative
        // price, the down payment's tax identity and the down-payment link.
        public readonly ?int $taxRuleId = null,
        public readonly ?string $taxCode = null,
        public readonly ?string $taxTreatment = null,
        public readonly ?bool $taxInclusive = null,
        public readonly ?int $downPaymentInvoiceId = null,
    ) {}

    /**
     * Issue #331: a deduction line built by
     * SalesOrderService::downPaymentDeductionLines().
     *
     * @param  array<string, mixed>  $line
     */
    public static function fromDownPaymentDeduction(array $line): self
    {
        return new self(
            lineType: (string) $line['line_type'],
            description: (string) $line['description'],
            quantity: (float) $line['quantity'],
            unitPrice: (float) $line['unit_price'],
            taxRate: (float) ($line['tax_rate'] ?? 0),
            discountAmount: (float) ($line['discount_amount'] ?? 0),
            taxRuleId: isset($line['tax_rule_id']) ? (int) $line['tax_rule_id'] : null,
            taxCode: $line['tax_code'] ?? null,
            taxTreatment: $line['tax_treatment'] ?? null,
            taxInclusive: (bool) ($line['tax_inclusive'] ?? false),
            downPaymentInvoiceId: (int) $line['down_payment_invoice_id'],
        );
    }

    public function toArray(): array
    {
        return [
            'line_type' => $this->lineType,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'product_id' => $this->productId,
            'gl_account_id' => $this->glAccountId,
            'tax_rate' => $this->taxRate,
            'discount_amount' => $this->discountAmount,
            'cost_center_id' => $this->costCenterId,
            'project_id' => $this->projectId,
            'department_id' => $this->departmentId,
            'branch_id' => $this->branchId,
            'sales_order_line_id' => $this->salesOrderLineId,
            'tax_rule_id' => $this->taxRuleId,
            'tax_code' => $this->taxCode,
            'tax_treatment' => $this->taxTreatment,
            'down_payment_invoice_id' => $this->downPaymentInvoiceId,
        ];
    }
}
