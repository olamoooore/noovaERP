<?php

namespace Modules\EventManagement\Exceptions;

use Exception;

abstract class EventManagementException extends Exception
{
    /**
     * Get the error code for this exception.
     */
    abstract public function getErrorCode(): string;

    /**
     * Get the HTTP status code for this exception.
     */
    abstract public function getHttpStatusCode(): int;

    /**
     * Render the exception as an HTTP response with consistent formatting.
     */
    public function render()
    {
        $errorResponse = [
            'error' => [
                'code' => $this->getErrorCode(),
                'message' => $this->getMessage(),
                'request_id' => request()->header('X-Request-ID', uniqid('req_'))
            ]
        ];

        // Add additional details if available
        if (method_exists($this, 'getDetails') && $this->getDetails()) {
            $errorResponse['error']['details'] = $this->getDetails();
        }

        return response()->json($errorResponse, $this->getHttpStatusCode());
    }

    /**
     * Get additional error details (optional, can be overridden by subclasses).
     */
    public function getDetails(): ?array
    {
        return null;
    }
}