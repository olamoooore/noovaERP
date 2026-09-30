<?php

declare(strict_types=1);

namespace Modules\EventManagement\Services;

use App\Contracts\EventBookingPaymentCompletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\Event;
use Modules\PaymentGateway\Models\PaymentTransaction;

/**
 * P-04e: platform event bookings complete via the canonical service
 * (status + ledger). Driven by the shared
 * PaymentTransactionCompletionService (webhook, confirm-payment endpoint
 * and repair command all funnel through it), this handler is the single
 * place a paid booking becomes confirmed:
 *
 *  1. booking state through BookingService's OWN state machine + analytics
 *     fact + outbox (processPaymentCallback — already idempotent on
 *     payment_reference);
 *  2. capacity decrement ONLY on the first conversion (conditional UPDATE;
 *     the old controller decremented on every confirmPayment call — an
 *     over-sell race pinned by L-10);
 *  3. the ledger entry (EventBookingPostingService, engine-idempotent);
 *  4. transaction processed_at + customer stamped LAST so any failure above
 *     leaves the delivery retryable and retries converge forward.
 */
final class EventBookingCompletionService implements EventBookingPaymentCompletion
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly EventBookingPostingService $posting,
    ) {}

    public function complete(PaymentTransaction $transaction, ?string $paymentMethod = null): void
    {
        $meta = (array) ($transaction->metadata ?? []);
        $reference = (string) ($meta['booking_reference'] ?? '');

        if ($reference === '') {
            throw new \RuntimeException('Event booking transaction is missing its booking_reference metadata.');
        }

        DB::transaction(function () use ($transaction, $reference, $meta, $paymentMethod): void {
            /** @var Booking|null $booking */
            $booking = Booking::query()
                ->where('booking_reference', $reference)
                ->lockForUpdate()
                ->first();

            if ($booking === null) {
                // Fail closed — never stamp a transaction we cannot bind to
                // its booking; the delivery retries until data is consistent.
                throw new \RuntimeException("Booking {$reference} not found for paid transaction; refusing to mark settlement.");
            }

            $alreadyConverted = $booking->payment_reference === $transaction->transaction_id
                && in_array($booking->state, ['confirmed', 'booked'], true);

            if (! $alreadyConverted) {
                if (in_array($booking->state, ['cancelled'], true)) {
                    // Money arrived for a cancelled booking: do NOT resurrect
                    // it; a real refund decision is human (disclosed).
                    throw new \RuntimeException("Booking {$reference} is cancelled; gateway settlement requires manual refund review.");
                }

                $before = $booking->payment_reference;

                $booking = $this->bookings->processPaymentCallback($reference, [
                    'payment_reference' => $transaction->transaction_id,
                    'status' => 'success',
                    'amount' => (float) $booking->total_amount,
                    'currency' => (string) $booking->currency,
                    'payment_method' => $paymentMethod ?? $transaction->payment_method ?? $transaction->gateway?->gateway_name,
                    'timestamp' => now()->toISOString(),
                ]);

                // First conversion only: conditional atomic decrement (no
                // double-consume on replay; over-sell guarded by the WHERE).
                if ($booking->payment_reference === $transaction->transaction_id && $before !== $transaction->transaction_id) {
                    Event::query()
                        ->where('id', (int) $booking->event_id)
                        ->where('available_capacity', '>', 0)
                        ->decrement('available_capacity');
                }
            }

            $this->posting->postPaidBooking(
                (int) $booking->id,
                (float) $transaction->amount,
                (string) $transaction->currency,
                $reference,
            );

            $transaction->update([
                'customer_id' => $transaction->customer_id ?? (($meta['customer_id'] ?? null) ?: null),
                'processed_at' => $transaction->processed_at ?? now(),
                'error_message' => null,
            ]);

            Log::info('Event booking payment completed via canonical chain', [
                'booking_reference' => $reference,
                'transaction_id' => $transaction->transaction_id,
            ]);
        });
    }
}
