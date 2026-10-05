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
    ) {}

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
        ];
    }
}
