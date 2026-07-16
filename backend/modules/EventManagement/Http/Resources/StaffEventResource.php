<?php

namespace Modules\EventManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\EventManagement\Services\SaleWindowService;

class StaffEventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $saleWindowService = app(SaleWindowService::class);

        // Handle both Event models and test objects
        $saleStatus = 'open'; // default
        if (method_exists($this->resource, 'isSoldOut')) {
            $saleStatus = $saleWindowService->getSaleStatus($this->resource);
        } elseif (property_exists($this->resource, 'sale_status')) {
            $saleStatus = $this->resource->sale_status;
        }

        // Determine available actions based on acquisition mode
        $availableActions = [];
        if ($this->ticket_acquisition_mode === 'buy') {
            $availableActions = ['purchase'];
        } elseif ($this->ticket_acquisition_mode === 'bid') {
            $availableActions = ['bid'];
        }

        // Calculate inventory data
        $totalCapacity = $this->total_capacity ?? 0;
        $availableCapacity = $this->available_capacity ?? 0;
        $soldCount = $totalCapacity - $availableCapacity;

        return [
            'id' => $this->id,
            'name' => $this->title,   // alias for frontend compatibility
            'title' => $this->title,
            'description' => $this->description,
            'venue' => $this->venue,
            'event_date' => $this->event_date?->toIso8601String(),
            'start_date' => $this->start_date?->toIso8601String(),
            'end_date' => $this->end_date?->toIso8601String(),
            'event_type' => [
                'name' => $this->eventType?->name,
                'slug' => $this->eventType?->slug,
            ],
            'ticket_acquisition_mode' => $this->ticket_acquisition_mode,
            'sale_status' => $saleStatus,
            'sale_start_date' => $this->sale_start_date?->toIso8601String(),
            'sale_end_date' => $this->sale_end_date?->toIso8601String(),
            'available_actions' => $availableActions,
            'inventory' => [
                'total' => $totalCapacity,
                'sold' => $soldCount,
                'remaining' => $availableCapacity,
            ],
            'status' => $this->status,
            'terms_conditions' => $this->terms_conditions,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
