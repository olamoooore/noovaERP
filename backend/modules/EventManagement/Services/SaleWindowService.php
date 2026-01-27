<?php

namespace Modules\EventManagement\Services;

use Modules\EventManagement\Models\Event;
use Carbon\Carbon;

class SaleWindowService
{
    // Sale status constants
    const STATUS_NOT_STARTED = 'not_started';
    const STATUS_OPEN = 'open';
    const STATUS_CLOSED = 'closed';
    const STATUS_SOLD_OUT = 'sold_out';

    /**
     * Check if current time is within the sale window
     *
     * @param Event $event
     * @param Carbon|null $currentTime
     * @return bool
     */
    public function isWithinSaleWindow(Event $event, ?Carbon $currentTime = null): bool
    {
        $currentTime = $currentTime ?? Carbon::now();
        
        $saleStart = Carbon::parse($event->sale_start_date);
        $saleEnd = Carbon::parse($event->sale_end_date);
        
        return $currentTime->greaterThanOrEqualTo($saleStart) 
            && $currentTime->lessThanOrEqualTo($saleEnd);
    }

    /**
     * Get the sale status for an event
     *
     * @param Event $event
     * @param Carbon|null $currentTime
     * @return string
     */
    public function getSaleStatus(Event $event, ?Carbon $currentTime = null): string
    {
        $currentTime = $currentTime ?? Carbon::now();
        
        // Check if sold out first
        if ($event->isSoldOut()) {
            return self::STATUS_SOLD_OUT;
        }
        
        $saleStart = Carbon::parse($event->sale_start_date);
        $saleEnd = Carbon::parse($event->sale_end_date);
        
        // Check if before sale window
        if ($currentTime->lessThan($saleStart)) {
            return self::STATUS_NOT_STARTED;
        }
        
        // Check if after sale window
        if ($currentTime->greaterThan($saleEnd)) {
            return self::STATUS_CLOSED;
        }
        
        // Within sale window
        return self::STATUS_OPEN;
    }

    /**
     * Validate sale window configuration
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    public function validateSaleWindow(Carbon $startDate, Carbon $endDate): array
    {
        $errors = [];
        
        if ($endDate->lessThanOrEqualTo($startDate)) {
            $errors[] = 'Sale end date must be after sale start date';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}
