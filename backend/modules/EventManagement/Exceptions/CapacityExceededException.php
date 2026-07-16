<?php

namespace Modules\EventManagement\Exceptions;

class CapacityExceededException extends EventManagementException
{
    protected $eventId;

    protected $requestedTickets;

    protected $availableCapacity;

    public function __construct(int $eventId, int $requestedTickets, int $availableCapacity, string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $this->eventId = $eventId;
        $this->requestedTickets = $requestedTickets;
        $this->availableCapacity = $availableCapacity;

        if (empty($message)) {
            $message = "Event has reached maximum capacity. Requested {$requestedTickets} tickets but only {$availableCapacity} available";
        }

        parent::__construct($message, $code, $previous);
    }

    public function getErrorCode(): string
    {
        return 'CAPACITY_EXCEEDED';
    }

    public function getHttpStatusCode(): int
    {
        return 409;
    }

    public function getDetails(): ?array
    {
        return [
            'event_id' => $this->eventId,
            'requested_tickets' => $this->requestedTickets,
            'available_capacity' => $this->availableCapacity,
        ];
    }
}
