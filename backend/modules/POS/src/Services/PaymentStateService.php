<?php

namespace Modules\POS\Services;

use Modules\POS\Models\PosSession;

class PaymentStateService
{
    const STATUS_NONE      = 'NONE';
    const STATUS_PENDING   = 'PENDING';
    const STATUS_APPROVED  = 'APPROVED';
    const STATUS_FAILED    = 'FAILED';

    /**
     * Derive a customer-safe payment status block from the session's display state.
     */
    public function forSession(PosSession $session): array
    {
        $displayState = $session->current_display_state ?? 'IDLE_SCREENSAVER';

        return match ($displayState) {
            'PAYMENT_PENDING' => [
                'status'       => self::STATUS_PENDING,
                'method_label' => null,
                'status_label' => 'Waiting for payment',
            ],
            'PAYMENT_APPROVED' => [
                'status'       => self::STATUS_APPROVED,
                'method_label' => null,
                'status_label' => 'Payment approved',
            ],
            'PAYMENT_FAILED' => [
                'status'       => self::STATUS_FAILED,
                'method_label' => null,
                'status_label' => 'Payment failed — please try again',
            ],
            default => [
                'status'       => self::STATUS_NONE,
                'method_label' => null,
                'status_label' => null,
            ],
        };
    }
}
