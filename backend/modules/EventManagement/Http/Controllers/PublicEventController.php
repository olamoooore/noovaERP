<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\BookingConfirmation;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Services\BookingService;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentService;

/**
 * Public-facing event controller — no authentication required.
 * Used by the storefront /store/events page.
 */
class PublicEventController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly BookingService $bookingService,
    ) {}

    /**
     * GET /api/v1/website-public/events
     * Returns all published events for the storefront.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Event::where('status', 'published')
                ->orderBy('start_date', 'asc');

            if ($this->supportsEventProductLinks()) {
                $query->with(['merchandiseProducts', 'bundleProducts']);
            }

            $events = $query->get()->map(fn($event) => $this->formatEvent($event));

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
    public function show(string $id): JsonResponse
    {
        try {
            if (!ctype_digit($id)) {
                return response()->json(['message' => 'Event not found'], 404);
            }

            $query = Event::where('status', 'published');

            if ($this->supportsEventProductLinks()) {
                $query->with(['merchandiseProducts', 'bundleProducts']);
            }

            $event = $query->findOrFail((int) $id);

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
    public function book(Request $request, string $id): JsonResponse
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
            if (!ctype_digit($id)) {
                return response()->json(['message' => 'Event not found'], 404);
            }

            $event = Event::where('status', 'published')->findOrFail((int) $id);

            if ($event->available_capacity <= 0) {
                return response()->json(['message' => 'Sorry, this event is sold out.'], 422);
            }

            // Generate a unique booking reference
            $reference = 'BK-' . strtoupper(substr(md5(uniqid()), 0, 8));

            // Generate a queue number from current bookings count + 1
            $queueNumber = Booking::where('event_id', $event->id)->count() + 1;
            $formattedQueueNumber = str_pad($queueNumber, 4, '0', STR_PAD_LEFT);
            $paymentRequired = (float) ($event->price ?? 0) > 0;

            // Create the booking using Eloquent (tenant-scoped via search_path)
            $booking = Booking::create([
                'booking_reference' => $reference,
                'event_id'          => $event->id,
                'buyer_name'        => $request->name,
                'buyer_email'       => $request->email,
                'buyer_phone'       => $request->phone ?? '',
                'total_amount'      => $event->price ?? 0,
                'currency'          => 'SGD',
                'state'             => $paymentRequired ? 'pending' : 'confirmed',
                'payment_reference' => null,
                'consent_pdpa'      => true,
                'consent_marketing' => false,
            ]);

            if ($paymentRequired) {
                $paymentIntent = $this->paymentService->createIntent([
                    'amount' => (float) $event->price,
                    'currency' => 'SGD',
                    'description' => "Event Booking {$reference} - {$event->title}",
                    'email' => $request->email,
                    'gateway_id' => $this->resolvePreferredPublicGatewayId(),
                    'redirect_url' => rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/') . '/payment/callback',
                    'metadata' => [
                        'type' => 'event_booking',
                        'booking_id' => $booking->id,
                        'booking_reference' => $reference,
                        'event_id' => $event->id,
                        'event_title' => $event->title,
                    ],
                ]);

                return response()->json([
                    'data' => [
                        'booking_id' => $booking->id,
                        'booking_reference' => $reference,
                        'queue_number' => $formattedQueueNumber,
                        'event_title' => $event->title,
                        'email' => $request->email,
                        'payment_required' => true,
                        'gateway_name' => $paymentIntent['gateway_name'] ?? null,
                        'payment_intent' => [
                            'transaction_id' => $paymentIntent['transaction_id'] ?? null,
                            'payment_url' => $paymentIntent['payment_url'] ?? null,
                            'client_secret' => $paymentIntent['client_secret'] ?? null,
                            'publishable_key' => $paymentIntent['publishable_key'] ?? null,
                        ],
                    ],
                    'message' => 'Booking created. Complete payment to confirm.',
                ], 201);
            }

            // Decrement available capacity for free bookings only.
            Event::where('id', $event->id)->decrement('available_capacity');

            // Ensure tenant mail config is loaded (public routes may not trigger the listener)
            try {
                $mail = \App\Models\Setting::getValue('mail_config');
                if ($mail && is_array($mail)) {
                    config(['mail.mailers.smtp' => [
                        'transport' => $mail['driver'] ?? 'smtp',
                        'host' => $mail['host'] ?? config('mail.mailers.smtp.host'),
                        'port' => $mail['port'] ?? config('mail.mailers.smtp.port'),
                        'encryption' => $mail['encryption'] ?? config('mail.mailers.smtp.encryption'),
                        'username' => $mail['username'] ?? config('mail.mailers.smtp.username'),
                        'password' => $mail['password'] ?? config('mail.mailers.smtp.password'),
                        'timeout' => null,
                        'local_domain' => env('MAIL_EHLO_DOMAIN'),
                    ]]);
                    if (!empty($mail['from_address'])) {
                        config(['mail.from.address' => $mail['from_address']]);
                    }
                    if (!empty($mail['from_name'])) {
                        config(['mail.from.name' => $mail['from_name']]);
                    }
                    // Purge and set SSL stream options on the new transport
                    app('mail.manager')->purge('smtp');
                    $transport = app('mail.manager')->mailer('smtp')->getSymfonyTransport();
                    if ($transport instanceof \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport) {
                        $stream = $transport->getStream();
                        if ($stream instanceof \Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream) {
                            $stream->setStreamOptions([
                                'ssl' => [
                                    'verify_peer' => false,
                                    'verify_peer_name' => false,
                                    'allow_self_signed' => true,
                                ],
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to load tenant mail config for booking email', [
                    'error' => $e->getMessage(),
                ]);
            }

            $this->sendBookingConfirmationEmail($booking, $event, $formattedQueueNumber);

            return response()->json([
                'data' => [
                    'booking_id'       => $booking->id,
                    'booking_reference'=> $reference,
                    'queue_number'     => $formattedQueueNumber,
                    'event_title'      => $event->title,
                    'email'            => $request->email,
                    'payment_required' => false,
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
     * POST /api/v1/website-public/events/bookings/confirm-payment
     * Confirms a public event booking payment after gateway completion.
     */
    public function confirmPayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_reference' => 'required|string|max:32',
            'payment_intent_id' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $bookingReference = (string) $request->input('booking_reference');
            $paymentIntentId = (string) $request->input('payment_intent_id');

            $booking = Booking::where('booking_reference', $bookingReference)->firstOrFail();
            $event = Event::findOrFail($booking->event_id);
            $queueNumber = $this->calculateQueueNumber($booking);
            $transaction = PaymentTransaction::where('transaction_id', $paymentIntentId)->first();

            if (!$transaction || ($transaction->metadata['booking_reference'] ?? null) !== $bookingReference) {
                return response()->json([
                    'message' => 'Payment transaction does not match this booking.',
                ], 422);
            }

            if ($booking->payment_reference === $paymentIntentId && in_array($booking->state, ['confirmed', 'booked'], true)) {
                return response()->json([
                    'data' => [
                        'booking_id' => $booking->id,
                        'booking_reference' => $booking->booking_reference,
                        'queue_number' => $queueNumber,
                        'event_title' => $event->title,
                        'email' => $booking->buyer_email,
                    ],
                    'message' => 'Payment already confirmed.',
                ]);
            }

            $paymentStatus = $this->paymentService->confirmPayment($paymentIntentId);

            if (($paymentStatus['status'] ?? null) !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not yet completed',
                    'status' => $paymentStatus['status'] ?? 'unknown',
                ], 402);
            }

            $booking = $this->bookingService->processPaymentCallback($bookingReference, [
                'payment_reference' => $paymentIntentId,
                'status' => 'success',
                'amount' => $booking->total_amount,
                'currency' => $booking->currency,
                'payment_method' => $paymentStatus['payment_method'] ?? 'card',
                'timestamp' => now()->toISOString(),
            ]);

            if ((int) $event->available_capacity > 0) {
                Event::where('id', $event->id)->decrement('available_capacity');
                $event->refresh();
            }

            $this->sendBookingConfirmationEmail($booking->fresh(), $event, $queueNumber);

            return response()->json([
                'data' => [
                    'booking_id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'queue_number' => $queueNumber,
                    'event_title' => $event->title,
                    'email' => $booking->buyer_email,
                ],
                'message' => 'Payment confirmed.',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Booking not found'], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Payment confirmation failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Format an Event model into the shape the storefront expects.
     */
    private function formatEvent(Event $event): array
    {
        $supportsEventProductLinks = $this->supportsEventProductLinks();

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
            'merchandise_products' => $supportsEventProductLinks ? $this->formatLinkedProducts($event->merchandiseProducts) : [],
            'bundle_products'      => $supportsEventProductLinks ? $this->formatLinkedProducts($event->bundleProducts) : [],
        ];
    }

    private function supportsEventProductLinks(): bool
    {
        return Schema::hasTable('event_product_links');
    }

    private function formatLinkedProducts($products): array
    {
        return $products->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'price' => (float) $product->price,
                'is_bundle' => (bool) $product->is_bundle,
                'bundle_type' => $product->bundle_type,
            ];
        })->values()->all();
    }

    private function resolvePreferredPublicGatewayId(): ?int
    {
        return PaymentGateway::query()
            ->where('is_active', true)
            ->whereIn('gateway_name', ['hitpay', 'paypal', 'stripe'])
            ->orderByRaw("CASE WHEN gateway_name = 'hitpay' THEN 0 WHEN gateway_name = 'paypal' THEN 1 WHEN gateway_name = 'stripe' THEN 2 ELSE 3 END")
            ->value('id');
    }

    private function calculateQueueNumber(Booking $booking): string
    {
        $position = Booking::where('event_id', $booking->event_id)
            ->where('id', '<=', $booking->id)
            ->count();

        return str_pad((string) max(1, $position), 4, '0', STR_PAD_LEFT);
    }

    private function sendBookingConfirmationEmail(Booking $booking, Event $event, string $queueNumber): void
    {
        try {
            $this->ensureTenantMailConfigLoaded();

            Mail::to($booking->buyer_email)->send(new BookingConfirmation([
                'booking_reference' => $booking->booking_reference,
                'queue_number' => $queueNumber,
                'event_title' => $event->title,
                'buyer_name' => $booking->buyer_name,
                'buyer_email' => $booking->buyer_email,
                'total_amount' => $event->price ?? 0,
                'currency' => 'SGD',
                'event_date' => $event->start_date?->toIso8601String(),
                'venue' => $event->venue,
            ]));
        } catch (\Throwable $mailError) {
            \Illuminate\Support\Facades\Log::warning('Booking confirmation email failed', [
                'email' => $booking->buyer_email,
                'booking' => $booking->booking_reference,
                'error' => $mailError->getMessage(),
            ]);
        }
    }

    private function ensureTenantMailConfigLoaded(): void
    {
        try {
            $mail = \App\Models\Setting::getValue('mail_config');
            if (!$mail || !is_array($mail)) {
                return;
            }

            config(['mail.mailers.smtp' => [
                'transport' => $mail['driver'] ?? 'smtp',
                'host' => $mail['host'] ?? config('mail.mailers.smtp.host'),
                'port' => $mail['port'] ?? config('mail.mailers.smtp.port'),
                'encryption' => $mail['encryption'] ?? config('mail.mailers.smtp.encryption'),
                'username' => $mail['username'] ?? config('mail.mailers.smtp.username'),
                'password' => $mail['password'] ?? config('mail.mailers.smtp.password'),
                'timeout' => null,
                'local_domain' => env('MAIL_EHLO_DOMAIN'),
            ]]);

            if (!empty($mail['from_address'])) {
                config(['mail.from.address' => $mail['from_address']]);
            }
            if (!empty($mail['from_name'])) {
                config(['mail.from.name' => $mail['from_name']]);
            }

            app('mail.manager')->purge('smtp');
            $transport = app('mail.manager')->mailer('smtp')->getSymfonyTransport();
            if ($transport instanceof \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport) {
                $stream = $transport->getStream();
                if ($stream instanceof \Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream) {
                    $stream->setStreamOptions([
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                        ],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to load tenant mail config for booking email', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
