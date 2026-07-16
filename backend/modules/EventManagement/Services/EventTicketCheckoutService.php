<?php

namespace Modules\EventManagement\Services;

use App\Models\Product;
use Illuminate\Support\Str;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventTicketType;

class EventTicketCheckoutService
{
    public function prepareCheckoutProduct(Event $event): Product
    {
        $product = $this->resolveExistingProduct($event) ?? new Product;

        $product->fill([
            'name' => $this->buildProductName($event),
            'slug' => $this->buildProductSlug($event),
            'sku' => $this->buildProductSku($event),
            'description' => $event->description,
            'short_description' => $event->description ? Str::limit(strip_tags($event->description), 180) : null,
            'type' => Product::TYPE_SERVICE,
            'price' => $event->price ?? 0,
            'cost' => 0,
            'track_inventory' => false,
            'tracking_method' => Product::TRACKING_NONE,
            'unit' => 'ticket',
            'manufacturer' => $event->venue,
            'image' => $event->image_url,
            'status' => Product::STATUS_ACTIVE,
            'is_active' => true,
            'is_bundle' => false,
            'is_merchandise' => false,
            'show_on_website' => true,
            'is_digital' => true,
            'attributes' => [
                'event_ticket' => true,
                'event_id' => $event->id,
                'event_date' => optional($event->event_date)->toDateTimeString(),
                'event_venue' => $event->venue,
            ],
        ]);
        $product->save();

        $this->ensureEventTicketType($event);

        if ((int) $event->ticket_product_id !== (int) $product->id) {
            $event->ticket_product_id = $product->id;
            $event->save();
        }

        return $product->fresh();
    }

    private function resolveExistingProduct(Event $event): ?Product
    {
        if ($event->ticketProduct) {
            return $event->ticketProduct;
        }

        $product = Product::withTrashed()
            ->where('sku', $this->buildProductSku($event))
            ->first();

        if ($product?->trashed()) {
            $product->restore();
        }

        return $product;
    }

    private function ensureEventTicketType(Event $event): EventTicketType
    {
        $defaultCapacity = max((int) ($event->total_capacity ?? 1), 1);
        $availableCapacity = max((int) ($event->available_capacity ?? $defaultCapacity), 0);

        return EventTicketType::firstOrCreate(
            [
                'event_id' => $event->id,
                'name' => 'General Admission',
            ],
            [
                'price' => $event->price ?? 0,
                'capacity' => $defaultCapacity,
                'available_capacity' => $availableCapacity,
                'description' => 'General admission ticket',
            ]
        );
    }

    private function buildProductName(Event $event): string
    {
        return sprintf('%s Ticket', $event->title);
    }

    private function buildProductSlug(Event $event): string
    {
        return Str::slug($this->buildProductName($event)).'-'.$event->id;
    }

    private function buildProductSku(Event $event): string
    {
        return sprintf('EVENT-%d-TICKET', $event->id);
    }
}
