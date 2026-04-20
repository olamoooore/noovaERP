<?php

namespace Tests\Unit;

use App\Services\InvoiceService;
use PHPUnit\Framework\TestCase;

class InvoiceServiceGuardTest extends TestCase
{
    /** @test */
    public function test_create_invoice_throws_when_called_outside_sales_order_service(): void
    {
        $invoiceService = new InvoiceService(
            $this->createMock(\App\Accounting\Contracts\PostingEngineInterface::class),
            $this->createMock(\Modules\Inventory\Services\InventoryService::class)
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('InvoiceService::createInvoice() must only be called from SalesOrderService');

        $invoiceService->createInvoice([
            'customer_id' => 1,
            'lines' => [
                [
                    'product_id' => 1,
                    'description' => 'Test',
                    'quantity' => 1,
                    'unit_price' => 10,
                ]
            ],
        ]);
    }
}
