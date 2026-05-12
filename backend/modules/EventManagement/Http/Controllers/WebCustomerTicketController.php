<?php

namespace Modules\EventManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\EventManagement\Models\Booking;

class WebCustomerTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('web_customer');

        if (!$customer) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $bookings = Booking::query()
            ->with(['event', 'tickets.attendee'])
            ->where(function ($query) use ($customer) {
                $query->where('customer_id', $customer->id);

                if (!empty($customer->email)) {
                    $query->orWhere('buyer_email', $customer->email);
                }
            })
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'tickets' => $bookings->map(function (Booking $booking) {
                return [
                    'id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'status' => $booking->state,
                    'total_amount' => (float) $booking->total_amount,
                    'currency' => $booking->currency,
                    'buyer_name' => $booking->buyer_name,
                    'buyer_email' => $booking->buyer_email,
                    'event_id' => $booking->event_id,
                    'event_title' => $booking->event?->title,
                    'event_date' => $booking->event?->start_date?->toIso8601String() ?? $booking->event?->event_date?->toIso8601String(),
                    'event_venue' => $booking->event?->venue,
                    'ticket_count' => $booking->tickets->count(),
                    'tickets' => $booking->tickets->map(fn ($ticket) => [
                        'id' => $ticket->id,
                        'ticket_code' => $ticket->ticket_code,
                        'status' => $ticket->status,
                        'issued_at' => $ticket->issued_at?->toIso8601String(),
                        'attendee_name' => $ticket->attendee?->attendee_name,
                    ])->values(),
                    'created_at' => $booking->created_at?->toIso8601String(),
                ];
            })->values(),
        ]);
    }
}