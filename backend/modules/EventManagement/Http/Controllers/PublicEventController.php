<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\BookingConfirmation;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\Booking;

/**
 * Public-facing event controller — no authentication required.
 * Used by the storefront /store/events page.
 */
class PublicEventController extends Controller
{
    /**
     * GET /api/v1/website-public/events
     * Returns all published events for the storefront.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $events = Event::where('status', 'published')
                ->orderBy('start_date', 'asc')
                ->get()
                ->map(fn($event) => $this->formatEvent($event));

            return response()->json([
                'data'    => $events,
                'message' => 'Events retrieved successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'data'    => [],
                'message' => 'Failed to fetch events',
                'error'   => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * GET /api/v1/website-public/events/{id}
     * Returns a single published event.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $event = Event::where('status', 'published')->findOrFail($id);

            return response()->json([
                'data'    => $this->formatEvent($event),
                'message' => 'Event retrieved successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Event not found'], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch event',
                'error'   => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * POST /api/v1/website-public/events/{id}/book
     * Creates a booking for an event (public, no auth required).
     */
    public function book(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'  => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $event = Event::where('status', 'published')->findOrFail($id);

            if ($event->available_capacity <= 0) {
                return response()->json(['message' => 'Sorry, this event is sold out.'], 422);
            }

            // Generate a unique booking reference
            $reference = 'BK-' . strtoupper(substr(md5(uniqid()), 0, 8));

            // Generate a queue number from current bookings count + 1
            $queueNumber = DB::table('bookings')
                ->where('event_id', $id)
                ->count() + 1;

            // Create the booking
            $booking = DB::table('bookings')->insertGetId([
                'booking_reference' => $reference,
                'event_id'          => $id,
                'buyer_name'        => $request->name,
                'buyer_email'       => $request->email,
                'buyer_phone'       => $request->phone ?? '',
                'total_amount'      => $event->price ?? 0,
                'currency'          => 'SGD',
                'state'             => 'confirmed',
                'payment_reference' => null,
                'consent_pdpa'      => true,
                'consent_marketing' => false,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            // Decrement available capacity
            Event::where('id', $id)->decrement('available_capacity');

            // Send booking confirmation email (fire-and-forget, never breaks the booking response)
            try {
                Mail::to($request->email)->send(new BookingConfirmation([
                    'booking_reference' => $reference,
                    'queue_number'      => str_pad($queueNumber, 4, '0', STR_PAD_LEFT),
                    'event_title'       => $event->title,
                    'buyer_name'        => $request->name,
                    'buyer_email'       => $request->email,
                    'total_amount'      => $event->price ?? 0,
                    'currency'          => 'SGD',
                    'event_date'        => $event->start_date?->toIso8601String(),
                    'venue'             => $event->venue,
                ]));
            } catch (\Throwable $mailError) {
                // Log mail failure but don't abort the booking
                \Illuminate\Support\Facades\Log::warning('Booking confirmation email failed', [
                    'email'     => $request->email,
                    'booking'   => $reference,
                    'error'     => $mailError->getMessage(),
                ]);
            }

            return response()->json([
                'data' => [
                    'booking_id'       => $booking,
                    'booking_reference'=> $reference,
                    'queue_number'     => str_pad($queueNumber, 4, '0', STR_PAD_LEFT),
                    'event_title'      => $event->title,
                    'email'            => $request->email,
                ],
                'message' => 'Booking successful!',
            ], 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Event not found'], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Booking failed',
                'error'   => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Format an Event model into the shape the storefront expects.
     */
    private function formatEvent(Event $event): array
    {
        // Fallback images based on event keywords
        $image = $event->image_url;
        if (!$image) {
            $title = strtolower($event->title);
            if (str_contains($title, 'concert') || str_contains($title, 'music') || str_contains($title, 'k-pop') || str_contains($title, 'kpop')) {
                $image = 'https://images.unsplash.com/photo-1540039155733-5bb30b53aa14?w=800&q=80';
            } elseif (str_contains($title, 'festival') || str_contains($title, 'summer')) {
                $image = 'https://images.unsplash.com/photo-1459749411175-04bf5292ceea?w=800&q=80';
            } elseif (str_contains($title, 'conference') || str_contains($title, 'tech') || str_contains($title, 'workshop') || str_contains($title, 'training')) {
                $image = 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?w=800&q=80';
            } elseif (str_contains($title, 'jazz') || str_contains($title, 'night')) {
                $image = 'https://images.unsplash.com/photo-1415201364774-f6f0bb35f28f?w=800&q=80';
            } else {
                $image = 'https://images.unsplash.com/photo-1540039155733-5bb30b53aa14?w=800&q=80';
            }
        }

        return [
            'id'                 => $event->id,
            'title'              => $event->title,
            'description'        => $event->description ?? '',
            'start_date'         => $event->start_date?->toIso8601String(),
            'end_date'           => $event->end_date?->toIso8601String(),
            'event_date'         => $event->start_date?->toIso8601String(),
            'venue'              => $event->venue ?? 'TBA',
            'price'              => (float) ($event->price ?? 0),
            'image_url'          => $image,
            'total_capacity'     => $event->total_capacity ?? 0,
            'available_capacity' => $event->available_capacity ?? 0,
            'spots_left'         => $event->available_capacity ?? 0,
            'total_spots'        => $event->total_capacity ?? 0,
            'status'             => $event->status,
            'is_sold_out'        => ($event->available_capacity ?? 1) <= 0,
        ];
    }
}
