<?php

namespace Tests\Feature;

use Tests\TenantTestCase;

class PosGatewayCompletionTest extends TenantTestCase
{
    // ── POS gateway completion uses canonical posting path ────────────────

    public function test_payment_controller_delegates_pos_completion_to_canonical_service(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Controllers/PaymentController.php'));

        $this->assertStringContainsString('PaymentTransactionCompletionService', $source,
            'PaymentController must delegate POS completion through PaymentTransactionCompletionService.');
        $this->assertStringContainsString('completionService->complete(', $source,
            'PaymentController confirm flow must call the canonical completion service.');
    }

    public function test_canonical_completion_service_uses_pos_posting_service(): void
    {
        $source = file_get_contents(base_path('modules/POS/src/Services/PosPaymentCompletionService.php'));

        $this->assertStringContainsString('PosPostingService', $source,
            'POS completion must use PosPostingService for GL posting.');
        $this->assertStringContainsString('posPostingService->postPaidOrder(', $source,
            'POS completion must call PosPostingService::postPaidOrder.');
    }

    public function test_canonical_pos_payment_creation_is_idempotent(): void
    {
        $source = file_get_contents(base_path('modules/POS/src/Services/PosPaymentCompletionService.php'));

        $this->assertStringContainsString("where('pos_order_id'", $source,
            'Canonical POS completion must check for existing PosPayment before creating a new one.');
        $this->assertStringContainsString("where('reference'", $source,
            'The idempotency check must match on both pos_order_id and reference to prevent duplicate payments.');
    }

    public function test_legacy_pos_completion_helpers_are_removed_from_payment_controller(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Controllers/PaymentController.php'));

        $this->assertStringNotContainsString('processCompletedPosOrderPayment(', $source,
            'PaymentController should no longer own legacy POS completion helpers.');
        $this->assertStringNotContainsString('createInvoiceFromPosOrder(', $source,
            'PaymentController should no longer own POS invoice creation helpers.');
    }

    public function test_canonical_pos_completion_sets_journal_entry_id_on_order(): void
    {
        $source = file_get_contents(base_path('modules/POS/src/Services/PosPaymentCompletionService.php'));

        $this->assertStringContainsString('journal_entry_id', $source,
            'Canonical POS completion must set journal_entry_id on the PosOrder from the posting result.');
    }

    public function test_canonical_pos_completion_uses_tenant_user_helper_for_received_by(): void
    {
        $source = file_get_contents(base_path('modules/POS/src/Services/PosPaymentCompletionService.php'));

        $this->assertStringContainsString('TenantUserHelper', $source,
            'Canonical POS completion must use TenantUserHelper for actor attribution.');
    }

    public function test_canonical_pos_payment_actor_fallback_uses_order_created_by(): void
    {
        $source = file_get_contents(base_path('modules/POS/src/Services/PosPaymentCompletionService.php'));

        $this->assertStringContainsString('requireTenantUserId', $source,
            'Canonical POS completion must use TenantUserHelper::requireTenantUserId for received_by.');
        $this->assertStringContainsString('$posOrder->created_by', $source,
            'The actor resolution must prefer the order creator over the current session user.');
    }

    public function test_gateway_transaction_completion_service_delegates_pos_flow_to_shared_pos_completion_service(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Services/PaymentTransactionCompletionService.php'));

        $this->assertStringContainsString('PosPaymentCompletionService', $source,
            'Gateway transaction completion must delegate POS flow to PosPaymentCompletionService.');
        $this->assertStringContainsString('posPaymentCompletionService->complete(', $source,
            'Gateway transaction completion must call the shared POS completion service.');
    }

    // ── Webhook/ecommerce payment actor resolution ──────────────────────

    public function test_webhook_payment_uses_invoice_created_by_for_received_by(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Controllers/PaymentWebhookController.php'));

        $this->assertStringContainsString('$invoice->created_by', $source,
            'PaymentWebhookController must use invoice->created_by for the received_by field in webhook-created Payment records.');
    }

    // ── General actor safety ─────────────────────────────────────────────

    public function test_payment_controller_invoice_flow_does_not_use_auth_id_fallback(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Controllers/PaymentController.php'));

        $this->assertStringNotContainsString('?? 1;', $source,
            'PaymentController must not use hardcoded user ID 1 as fallback for received_by.');
    }
}
