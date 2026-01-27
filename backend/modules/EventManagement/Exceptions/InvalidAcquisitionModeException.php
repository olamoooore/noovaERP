<?php

namespace Modules\EventManagement\Exceptions;

use Exception;

class InvalidAcquisitionModeException extends Exception
{
    protected $code = 400;

    public function __construct(string $attemptedMode, string $allowedMode)
    {
        $message = "This event only supports {$allowedMode}. Cannot {$attemptedMode}.";
        parent::__construct($message);
    }
}
