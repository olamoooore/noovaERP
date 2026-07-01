<?php

namespace App\Features\ActivityLogging\Exceptions;

use Exception;

class ActivityLoggingException extends Exception
{
    public readonly array $context;

    public function __construct(
        string $message,
        array $context = [],
        int $code = 0,
        ?Exception $previous = null,
    ) {
        $this->context = $context;
        parent::__construct($message, $code, $previous);
    }
}
