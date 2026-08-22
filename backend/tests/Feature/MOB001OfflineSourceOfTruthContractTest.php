<?php

namespace Tests\Feature;

use Tests\TenantTestCase;

/**
 * MOB-001: Offline source-of-truth rule enforced.
 *
 * Mobile/offline flow must capture intent and submit for server-side acceptance;
 * authoritative stock/accounting mutations must run through server services.
 */
class MOB001OfflineSourceOfTruthContractTest extends TenantTestCase
{
    public function test_offline_queue_capture_requires_idempotent_intent_payload(): void
    {
        $controller = file_get_contents(base_path('modules/POS/src/Http/Controllers/PosSyncController.php'));

        $this->assertStringContainsString("'operation_type' => 'required|string|in:create_order,payment,void,refund'", $controller);
        $this->assertStringContainsString("'payload' => 'required|array'", $controller);
        $this->assertStringContainsString("'idempotency_key' => 'required|string|max:120'", $controller);
    }

    public function test_offline_create_order_sync_defaults_to_draft_intent(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));

        $this->assertStringContainsString("'status' => ", $service);
        $this->assertStringContainsString("?? 'draft'", $service);
    }

    public function test_offline_payment_sync_delegates_to_server_completion_service(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));

        $this->assertStringContainsString('PosPaymentCompletionService::class', $service);
        $this->assertStringContainsString('$paymentService->complete(', $service);
        $this->assertStringNotContainsString('StockLevel::', $service);
        $this->assertStringNotContainsString('StockMovement::create(', $service);
    }

    public function test_offline_refund_sync_requires_approval_and_server_restock_path(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));

        $this->assertStringContainsString('Refund operation requires approval', $service);
        $this->assertStringContainsString('createAndRestockReturnForSync', $service);
        $this->assertStringContainsString('reverseOrder($order, $returnOrder', $service);
    }
}
