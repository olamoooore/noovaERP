<?php

namespace Modules\Ecommerce\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ecommerce\Models\WebOrder;
use Modules\EventManagement\Models\Event;

class WebOrderEventReservationService
{
    private const RESERVATION_MINUTES = 15;

    public function reserveForOrder(WebOrder $order, Collection $orderItems): void
    {
        $eventQuantities = $this->extractEventQuantities($orderItems);

        if ($eventQuantities->isEmpty()) {
            return;
        }

        $this->cleanupExpiredReservations($eventQuantities->keys()->all());

        foreach ($eventQuantities as $eventId => $payload) {
            $event = Event::query()->lockForUpdate()->findOrFail((int) $eventId);
            $quantity = (int) $payload['quantity'];
            $existing = DB::table('web_order_event_reservations')
                ->where('web_order_id', $order->id)
                ->where('event_id', $event->id)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === 'active' && $existing->reserved_until && now()->lt($existing->reserved_until)) {
                DB::table('web_order_event_reservations')
                    ->where('id', $existing->id)
                    ->update([
                        'reserved_until' => now()->addMinutes(self::RESERVATION_MINUTES),
                        'updated_at' => now(),
                    ]);

                continue;
            }

            if ((int) ($event->available_capacity ?? 0) < $quantity) {
                throw new \InvalidArgumentException("Insufficient event capacity for {$event->title}");
            }

            $newAvailableCapacity = max(0, (int) $event->available_capacity - $quantity);
            $event->available_capacity = $newAvailableCapacity;
            if ($newAvailableCapacity === 0) {
                $event->status = 'sold_out';
            }
            $event->save();

            $data = [
                'product_id' => $payload['product_id'],
                'quantity' => $quantity,
                'reservation_token' => $this->generateReservationToken(),
                'status' => 'active',
                'reserved_until' => now()->addMinutes(self::RESERVATION_MINUTES),
                'fulfilled_at' => null,
                'released_at' => null,
                'release_reason' => null,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('web_order_event_reservations')
                    ->where('id', $existing->id)
                    ->update($data);
            } else {
                DB::table('web_order_event_reservations')->insert(array_merge($data, [
                    'web_order_id' => $order->id,
                    'event_id' => $event->id,
                    'booking_id' => null,
                    'created_at' => now(),
                ]));
            }
        }
    }

    public function ensureActiveReservations(WebOrder $order): void
    {
        $order->loadMissing('items');
        $this->reserveForOrder($order, $order->items);
    }

    public function markFulfilled(WebOrder $order, ?int $bookingId = null, ?int $eventId = null): void
    {
        $query = DB::table('web_order_event_reservations')
            ->where('web_order_id', $order->id)
            ->where('status', 'active');

        if ($eventId !== null) {
            $query->where('event_id', $eventId);
        }

        $query->update([
            'status' => 'fulfilled',
            'booking_id' => $bookingId,
            'fulfilled_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function releaseForOrder(WebOrder $order, string $reason): void
    {
        $reservations = DB::table('web_order_event_reservations')
            ->where('web_order_id', $order->id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            $event = Event::query()->lockForUpdate()->find($reservation->event_id);

            if ($event) {
                $event->available_capacity = (int) ($event->available_capacity ?? 0) + (int) $reservation->quantity;
                if ($event->status === 'sold_out' && $event->available_capacity > 0) {
                    $event->status = 'published';
                }
                $event->save();
            }

            DB::table('web_order_event_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'status' => 'released',
                    'released_at' => now(),
                    'release_reason' => $reason,
                    'updated_at' => now(),
                ]);
        }
    }

    public function cleanupExpiredReservations(array $eventIds = []): void
    {
        $query = DB::table('web_order_event_reservations')
            ->where('status', 'active')
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<=', now())
            ->orderBy('id');

        if (! empty($eventIds)) {
            $query->whereIn('event_id', $eventIds);
        }

        $reservations = $query->lockForUpdate()->get();

        foreach ($reservations as $reservation) {
            $event = Event::query()->lockForUpdate()->find($reservation->event_id);

            if ($event) {
                $event->available_capacity = (int) ($event->available_capacity ?? 0) + (int) $reservation->quantity;
                if ($event->status === 'sold_out' && $event->available_capacity > 0) {
                    $event->status = 'published';
                }
                $event->save();
            }

            DB::table('web_order_event_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'status' => 'expired',
                    'released_at' => now(),
                    'release_reason' => 'Reservation expired before payment completion',
                    'updated_at' => now(),
                ]);
        }
    }

    private function extractEventQuantities(Collection $orderItems): Collection
    {
        $productIds = $orderItems
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (empty($productIds)) {
            return collect();
        }

        $events = Event::query()
            ->whereIn('ticket_product_id', $productIds)
            ->get()
            ->keyBy('ticket_product_id');

        return $orderItems->reduce(function (Collection $carry, $item) use ($events) {
            $event = $events->get((int) $item->product_id);
            if (! $event) {
                return $carry;
            }

            $existing = $carry->get($event->id, ['quantity' => 0, 'product_id' => (int) $item->product_id]);
            $existing['quantity'] += (int) $item->quantity;
            $carry->put($event->id, $existing);

            return $carry;
        }, collect());
    }

    private function generateReservationToken(): string
    {
        do {
            $token = 'WOR_'.strtoupper(Str::random(20));
        } while (DB::table('web_order_event_reservations')->where('reservation_token', $token)->exists());

        return $token;
    }
}
