<?php

namespace App\Contracts;

use Modules\PaymentGateway\Models\PaymentTransaction;

/**
 * Platform seam: EventManagement provides the booking-completion behavior
 * for transactions whose metadata type is `event_booking`; the shared
 * completion service delegates here instead of the invoice-only error path
 * (which left paid bookings stuck at pending forever — P-04e/L-10).
 *
 * Fail-closed when unbound: a booking payment must never be half-processed
 * by a fallback built for a different document type.
 */
interface EventBookingPaymentCompletion
{
    public function complete(PaymentTransaction $transaction, ?string $paymentMethod = null): void;
}
