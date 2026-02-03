<?php

namespace Modules\IoT\Exceptions;

class UnsupportedProtocolException extends \Exception
{
    public function __construct(string $message = "Unsupported protocol", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
