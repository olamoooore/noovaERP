<?php

namespace Modules\EventManagement\Exceptions;

use Exception;
use Carbon\Carbon;

class SaleClosedException extends Exception
{
    protected $code = 403;

    public function __construct(Carbon $endDate)
    {
        $message = "Ticket sales ended on {$endDate->toDateTimeString()}";
        parent::__construct($message);
    }
}
