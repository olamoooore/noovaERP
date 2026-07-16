<?php

namespace Modules\EventManagement\Services;

use Carbon\Carbon;
use Modules\EventManagement\Exceptions\InvalidAcquisitionModeException;
use Modules\EventManagement\Exceptions\SaleClosedException;
use Modules\EventManagement\Exceptions\SaleNotStartedException;
use Modules\EventManagement\Models\Event;

/**
 * Purchase Service
 *
 * Handles direct ticket purchases with acquisition mode and sale window validation.
 * Requirements: 3.4, 4.4, 8.1, 8.2, 8.3
 */
class PurchaseService
{
    protected BookingService $bookingService;

    protected SaleWindowService $saleWindowService;

    public function __construct(
        BookingService $bookingService,
        SaleWindowService $saleWindowService
    ) {
        $this->bookingService = $bookingService;
        $this->saleWindowService = $saleWindowService;
    }

    /**
     * Purchase tickets for an event
     *
     * Validates:
     * - Event acquisition mode is 'buy'
     * - Current time is within sale window
     *
     * @return \Modules\EventManagement\Models\Booking
     *
     * @throws InvalidAcquisitionModeException
     * @throws SaleNotStartedException
     * @throws SaleClosedException
     */
    public function purchaseTickets(array $purchaseData)
    {
        $event = Event::findOrFail($purchaseData['event_id']);

        // Validate acquisition mode is 'buy'
        $this->validateAcquisitionMode($event);

        // Validate sale window
        $this->validateSaleWindow($event);

        // Delegate to BookingService for actual booking creation
        return $this->bookingService->createBooking($purchaseData);
    }

    /**
     * Validate that event acquisition mode is 'buy'
     *
     * Requirements: 3.4, 4.4
     *
     * @throws InvalidAcquisitionModeException
     */
    protected function validateAcquisitionMode(Event $event): void
    {
        if ($event->ticket_acquisition_mode !== Event::MODE_BUY) {
            throw new InvalidAcquisitionModeException('purchase', 'bidding');
        }
    }

    /**
     * Validate that current time is within sale window
     *
     * Requirements: 8.1, 8.2, 8.3
     *
     * @throws SaleNotStartedException
     * @throws SaleClosedException
     */
    protected function validateSaleWindow(Event $event, ?Carbon $currentTime = null): void
    {
        $currentTime = $currentTime ?? Carbon::now();
        $saleStatus = $this->saleWindowService->getSaleStatus($event, $currentTime);

        if ($saleStatus === SaleWindowService::STATUS_NOT_STARTED) {
            throw new SaleNotStartedException(Carbon::parse($event->sale_start_date));
        }

        if ($saleStatus === SaleWindowService::STATUS_CLOSED) {
            throw new SaleClosedException(Carbon::parse($event->sale_end_date));
        }
    }

    /**
     * Check if purchase is allowed for an event
     */
    public function canPurchase(int $eventId, ?Carbon $currentTime = null): array
    {
        try {
            $event = Event::findOrFail($eventId);
            $currentTime = $currentTime ?? Carbon::now();

            // Check acquisition mode
            if ($event->ticket_acquisition_mode !== Event::MODE_BUY) {
                return [
                    'can_purchase' => false,
                    'reason' => 'This event only supports bidding. Cannot purchase.',
                ];
            }

            // Check sale window
            $saleStatus = $this->saleWindowService->getSaleStatus($event, $currentTime);

            if ($saleStatus === SaleWindowService::STATUS_NOT_STARTED) {
                return [
                    'can_purchase' => false,
                    'reason' => "Ticket sales will start on {$event->sale_start_date}",
                ];
            }

            if ($saleStatus === SaleWindowService::STATUS_CLOSED) {
                return [
                    'can_purchase' => false,
                    'reason' => "Ticket sales ended on {$event->sale_end_date}",
                ];
            }

            if ($saleStatus === SaleWindowService::STATUS_SOLD_OUT) {
                return [
                    'can_purchase' => false,
                    'reason' => 'This event is sold out',
                ];
            }

            return [
                'can_purchase' => true,
                'reason' => null,
            ];

        } catch (\Exception $e) {
            return [
                'can_purchase' => false,
                'reason' => $e->getMessage(),
            ];
        }
    }
}
