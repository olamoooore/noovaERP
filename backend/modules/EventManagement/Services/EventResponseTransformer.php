<?php

namespace Modules\EventManagement\Services;

use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Http\Resources\CustomerEventResource;
use Modules\EventManagement\Http\Resources\StaffEventResource;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResponseTransformer
{
    protected AccessControlService $accessControlService;

    public function __construct(AccessControlService $accessControlService)
    {
        $this->accessControlService = $accessControlService;
    }

    /**
     * Transform event to customer response
     * Excludes inventory data, includes sale status and available actions
     *
     * @param Event $event
     * @return CustomerEventResource
     */
    public function toCustomerResponse(Event $event): CustomerEventResource
    {
        return new CustomerEventResource($event);
    }

    /**
     * Transform event to staff response
     * Includes inventory data (total, sold, remaining)
     *
     * @param Event $event
     * @return StaffEventResource
     */
    public function toStaffResponse(Event $event): StaffEventResource
    {
        return new StaffEventResource($event);
    }

    /**
     * Transform event based on current user's role
     * Automatically determines whether to use customer or staff response
     *
     * @param Event $event
     * @return JsonResource
     */
    public function transform(Event $event): JsonResource
    {
        $user = $this->accessControlService->getCurrentUser();
        
        if ($this->accessControlService->canViewInventory($user)) {
            return $this->toStaffResponse($event);
        }
        
        return $this->toCustomerResponse($event);
    }

    /**
     * Transform a collection of events based on current user's role
     *
     * @param iterable $events
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function transformCollection(iterable $events)
    {
        $user = $this->accessControlService->getCurrentUser();
        
        if ($this->accessControlService->canViewInventory($user)) {
            return StaffEventResource::collection($events);
        }
        
        return CustomerEventResource::collection($events);
    }
}
