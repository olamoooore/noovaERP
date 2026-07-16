<?php

namespace Modules\EventManagement\Services;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\EventManagement\Http\Resources\CustomerEventResource;
use Modules\EventManagement\Http\Resources\StaffEventResource;
use Modules\EventManagement\Models\Event;

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
     */
    public function toCustomerResponse(Event $event): CustomerEventResource
    {
        return new CustomerEventResource($event);
    }

    /**
     * Transform event to staff response
     * Includes inventory data (total, sold, remaining)
     */
    public function toStaffResponse(Event $event): StaffEventResource
    {
        return new StaffEventResource($event);
    }

    /**
     * Transform event based on current user's role
     * Automatically determines whether to use customer or staff response
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
