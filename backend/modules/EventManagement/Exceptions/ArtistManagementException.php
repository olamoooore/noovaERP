<?php

namespace Modules\EventManagement\Exceptions;

/**
 * Artist Management Exception
 * 
 * Thrown when artist management operations fail due to business logic
 * violations, validation errors, or data integrity issues.
 * 
 * Requirements: 12.1, 12.2, 12.4
 */
class ArtistManagementException extends EventManagementException
{
    public function getErrorCode(): string
    {
        return 'ARTIST_MANAGEMENT_ERROR';
    }

    public function getHttpStatusCode(): int
    {
        return 422; // Unprocessable Entity
    }
}