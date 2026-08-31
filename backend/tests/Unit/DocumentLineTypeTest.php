<?php

namespace Tests\Unit;

use App\Models\InvoiceLine;
use App\Models\QuotationLine;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\Sales\Models\SalesOrderLine;
use PHPUnit\Framework\TestCase;

class DocumentLineTypeTest extends TestCase
{
    public function test_non_product_lines_are_always_non_financial(): void
    {
        $invoice = new InvoiceLine(['line_type' => 'note', 'description' => 'Handle with care', 'quantity' => 5, 'unit_price' => 10, 'tax_rate' => 9]);
        $invoice->calculateTotals();
        $this->assertSame(0.0, (float) $invoice->line_total);
        $this->assertNull($invoice->product_id);

        $quotation = new QuotationLine(['line_type' => 'section', 'description' => 'Optional accessories', 'quantity' => 4, 'unit_price' => 25]);
        $quotation->calculateLineTotals();
        $this->assertSame(0.0, (float) $quotation->line_total);

        $salesOrder = new SalesOrderLine(['line_type' => 'note', 'quantity' => 2, 'unit_price' => 30]);
        $salesOrder->calculateLineTotal();
        $this->assertSame(0.0, (float) $salesOrder->line_total);

        $purchaseOrder = new PurchaseOrderItem(['line_type' => 'section', 'quantity_ordered' => 3, 'unit_cost' => 20]);
        $purchaseOrder->calculateLineTotal();
        $this->assertSame(0.0, (float) $purchaseOrder->line_total);
    }

    public function test_product_lines_still_calculate_normally(): void
    {
        $line = new InvoiceLine(['line_type' => 'product', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 10, 'discount_amount' => 0]);
        $line->calculateTotals();
        $this->assertSame(220.0, (float) $line->line_total);
        $this->assertSame(20.0, (float) $line->tax_amount);
    }
}
