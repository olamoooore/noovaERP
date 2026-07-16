<?php

namespace Tests\Unit;

use App\Services\InvoiceService;
use PHPUnit\Framework\TestCase;

class InvoiceServiceGuardTest extends TestCase
{
    /** @test */
    public function test_creation_token_cannot_be_reused(): void
    {
        $invoiceService = new InvoiceService(
            $this->createMock(\App\Accounting\Contracts\PostingEngineInterface::class),
            $this->createMock(\Modules\Inventory\Services\InventoryService::class)
        );

        $token = $invoiceService->issueCreationToken();
        $token->consume();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('InvoiceCreationToken has already been consumed');

        $token->consume();
    }

    /** @test */
    public function test_creation_token_is_not_consumed_initially(): void
    {
        $invoiceService = new InvoiceService(
            $this->createMock(\App\Accounting\Contracts\PostingEngineInterface::class),
            $this->createMock(\Modules\Inventory\Services\InventoryService::class)
        );

        $token = $invoiceService->issueCreationToken();

        $this->assertFalse($token->isConsumed());
    }
}
