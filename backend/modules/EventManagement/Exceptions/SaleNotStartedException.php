<?php

namespace Modules\EventManagement\Exceptions;

use Carbon\Carbon;
use Exception;

class SaleNotStartedException extends Exception
{
    protected $code = 403;

    public function __construct(Carbon $startDate)
    {
        $message = "Ticket sales will start on {$startDate->toDateTimeString()}";
        parent::__construct($message);
    }
}
