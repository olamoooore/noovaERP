<?php

namespace Modules\Loyalty\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Loyalty\Services\PointsEarningService;

/**
 * Listener for invoice payment events
 * Automatically awards loyalty points when invoices are paid
 */
class InvoicePaymentListener
{
    protected PointsEarningService $pointsEarningService;

    public function __construct(PointsEarningService $pointsEarningService)
    {
        $this->pointsEarningService = $pointsEarningService;
    }

    /**
     * Handle the invoice paid event
     */
    public function handle($event): void
    {
        try {
            $invoice = $event->invoice;
            $payment = $event->payment;

            // Award points for the payment amount
            $result = $this->pointsEarningService->earnPoints(
                $invoice->customer_id,
                $payment->amount,
                'invoice_payment',
                "Payment for Invoice #{$invoice->invoice_number}",
                $invoice->id
            );

            Log::info('Loyalty points awarded for invoice payment', [
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'payment_amount' => $payment->amount,
                'points_earned' => $result['points_earned'],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to award loyalty points for invoice payment', [
                'error' => $e->getMessage(),
                'invoice_id' => $invoice->id ?? null,
            ]);
        }
    }
}
