<?php

namespace Modules\EventManagement\Exceptions;

class InvalidStateTransitionException extends EventManagementException
{
    protected $fromState;
    protected $toState;
    protected $bookingId;

    public function __construct(string $fromState, string $toState, ?int $bookingId = null, string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $this->fromState = $fromState;
        $this->toState = $toState;
        $this->bookingId = $bookingId;

        if (empty($message)) {
            $message = "Invalid state transition from '{$fromState}' to '{$toState}'";
            if ($bookingId) {
                $message .= " for booking ID {$bookingId}";
            }
        }

        parent::__construct($message, $code, $previous);
    }

    public function getErrorCode(): string
    {
        return 'INVALID_STATE_TRANSITION';
    }

    public function getHttpStatusCode(): int
    {
        return 422;
    }

    public function getDetails(): ?array
    {
        $details = [
            'from_state' => $this->fromState,
            'to_state' => $this->toState
        ];

        if ($this->bookingId) {
            $details['booking_id'] = $this->bookingId;
        }

        return $details;
    }
}