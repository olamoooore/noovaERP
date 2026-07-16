<?php

namespace Modules\PaymentGateway\Services;

use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    /**
     * Create a payment intent/request
     *
     * @param  array  $data  Payment data including amount, currency, metadata
     * @return array Payment response with transaction_id and payment_url
     */
    public function createPayment(array $data): array;

    /**
     * Get payment status from gateway
     *
     * @param  string  $transactionId  Gateway's transaction ID
     * @return array Status information
     */
    public function getPaymentStatus(string $transactionId): array;

    /**
     * Process a refund
     *
     * @param  string  $transactionId  Gateway's transaction ID
     * @param  float  $amount  Amount to refund
     * @return array Refund response
     */
    public function refundPayment(string $transactionId, float $amount): array;

    /**
     * Verify webhook signature
     *
     * @param  Request  $request  Incoming webhook request
     * @return bool True if signature is valid
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * Process webhook payload
     *
     * @param  Request  $request  Incoming webhook request
     * @return array Processed webhook data
     */
    public function processWebhook(Request $request): array;

    /**
     * Test gateway connection
     *
     * @return bool True if connection successful
     */
    public function testConnection(): bool;
}
