<?php

namespace Modules\IoT\Services\Drivers;

/**
 * Terminal Driver Interface
 *
 * Defines the contract for payment terminal drivers.
 * Implementations handle communication with payment gateways (HitPay, Stripe, etc.)
 */
interface TerminalDriverInterface
{
    /**
     * Create a payment request.
     *
     * @param  float  $amount  Payment amount
     * @param  string  $currency  Currency code (SGD, USD, EUR, etc.)
     * @param  array  $options  Additional options:
     *                          - order_reference: Order reference ID for tracking
     *                          - payment_method: Payment method (wifi_card_reader, paynow_online, grabpay, etc.)
     *                          - redirect_url: URL to redirect after payment (optional)
     * @return array Payment request data:
     *               - id: Payment request ID
     *               - status: Payment status (pending, completed, failed)
     *               - url: QR code URL (for QR payments)
     *
     * @throws \Exception If payment request creation fails
     */
    public function createPaymentRequest(float $amount, string $currency, array $options = []): array;

    /**
     * Check payment status.
     *
     * @param  string  $paymentRequestId  Payment request ID from createPaymentRequest
     * @return array Payment status data:
     *               - status: Current payment status (pending, completed, failed, cancelled)
     *               - payment_id: Transaction ID (if completed)
     *               - error_message: Error message (if failed)
     *
     * @throws \Exception If status check fails
     */
    public function checkPaymentStatus(string $paymentRequestId): array;

    /**
     * Cancel a pending payment.
     *
     * @param  string  $paymentRequestId  Payment request ID to cancel
     * @return bool True if cancellation successful
     *
     * @throws \Exception If cancellation fails
     */
    public function cancelPayment(string $paymentRequestId): bool;

    /**
     * Test terminal connectivity.
     *
     * @return bool True if terminal is reachable and credentials are valid
     *
     * @throws \Exception If connection test fails
     */
    public function testConnection(): bool;

    /**
     * Verify webhook signature.
     *
     * @param  array  $payload  Webhook payload data
     * @param  string  $signature  HMAC signature from webhook header
     * @return bool True if signature is valid
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool;
}
