<?php

namespace Modules\EventManagement\Services;

use Modules\Ecommerce\Services\WebOrderEventReservationService;
use Modules\Ecommerce\Models\WebOrder;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\BookingAttendee;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\Ticket;
use Carbon\Carbon;

class WebOrderTicketFulfillmentService
{
    public function __construct(
        private readonly EventTicketCheckoutService $eventTicketCheckoutService,
        private readonly TicketService $ticketService,
        private readonly WebOrderEventReservationService $webOrderEventReservationService,
    ) {
    }

    public function syncPaidWebOrder(WebOrder $order): void
    {
        $order->loadMissing('items');

        $ticketQuantities = $order->items
            ->groupBy('product_id')
            ->map(fn ($items) => (int) $items->sum('quantity'));

        if ($ticketQuantities->isEmpty()) {
            return;
        }

        $eventsByProduct = Event::query()
            ->whereIn('ticket_product_id', $ticketQuantities->keys()->all())
            ->get()
            ->keyBy('ticket_product_id');

        foreach ($ticketQuantities as $productId => $quantity) {
            $event = $eventsByProduct->get((int) $productId);

            if (!$event || $quantity <= 0) {
                continue;
            }

            $this->syncEventBooking($order, $event, $quantity);
        }
    }

    private function syncEventBooking(WebOrder $order, Event $event, int $quantity): void
    {
        $product = $this->eventTicketCheckoutService->prepareCheckoutProduct($event);
        $ticketType = $event->ticketTypes()->orderBy('id')->firstOrFail();
        $buyerName = trim(implode(' ', array_filter([$order->first_name, $order->last_name]))) ?: $order->email;
        $lineTotal = (float) $order->items
            ->where('product_id', $product->id)
            ->sum('line_total');

        $booking = Booking::firstOrCreate(
            ['booking_reference' => $this->buildBookingReference($order, $event)],
            [
                'event_id' => $event->id,
                'customer_id' => $order->customer_id,
                'buyer_name' => $buyerName,
                'buyer_email' => $order->email,
                'buyer_phone' => $order->phone ?? '',
                'total_amount' => $lineTotal,
                'original_amount' => $lineTotal,
                'currency' => $order->currency ?? 'SGD',
                'state' => 'booked',
                'payment_reference' => $this->buildPaymentReference($order),
                'consent_pdpa' => true,
                'consent_marketing' => false,
            ]
        );

        if ($booking->wasRecentlyCreated) {
            $this->webOrderEventReservationService->markFulfilled($order, $booking->id, $event->id);
        } elseif ($booking->state !== 'booked') {
            $booking->state = 'booked';
            $booking->save();
        }

        if (!$booking->wasRecentlyCreated) {
            $this->webOrderEventReservationService->markFulfilled($order, $booking->id, $event->id);
        }

        $existingAttendees = $booking->attendees()->count();

        for ($index = $existingAttendees; $index < $quantity; $index++) {
            BookingAttendee::create([
                'booking_id' => $booking->id,
                'ticket_type_id' => $ticketType->id,
                'attendee_name' => $buyerName,
                'attendee_email' => $order->email,
                'attendee_phone' => $order->phone,
            ]);
        }

        $this->issueMissingTickets($booking->fresh(['attendees', 'tickets', 'event']));
    }

    private function issueMissingTickets(Booking $booking): void
    {
        $ticketedAttendeeIds = $booking->tickets->pluck('attendee_id')->filter()->map(fn ($id) => (int) $id)->all();
        $missingAttendees = $booking->attendees->filter(fn ($attendee) => !in_array((int) $attendee->id, $ticketedAttendeeIds, true));

        foreach ($missingAttendees as $attendee) {
            $ticketCode = $this->ticketService->generateUniqueCode();
            $eventDate = $booking->event?->event_date ?? $booking->event?->start_date;

            Ticket::create([
                'ticket_code' => $ticketCode,
                'booking_id' => $booking->id,
                'attendee_id' => $attendee->id,
                'qr_payload' => $this->ticketService->createQRPayload([
                    'ticket_code' => $ticketCode,
                    'booking_reference' => $booking->booking_reference,
                    'event_id' => $booking->event_id,
                    'attendee_id' => $attendee->id,
                    'attendee_name' => $attendee->attendee_name,
                    'event_title' => $booking->event?->title ?? 'Event',
                    'event_date' => $eventDate ? Carbon::parse($eventDate)->toISOString() : now()->toISOString(),
                    'venue' => $booking->event?->venue,
                ]),
                'status' => 'active',
                'issued_at' => now(),
            ]);
        }
    }

    private function buildBookingReference(WebOrder $order, Event $event): string
    {
        return sprintf('WEB%dEV%d', $order->id, $event->id);
    }

    private function buildPaymentReference(WebOrder $order): string
    {
        return sprintf('WEB-ORDER-%d', $order->id);
    }
}