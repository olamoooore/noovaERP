<?php

namespace Modules\EventManagement\Services;

use Carbon\Carbon;
use Modules\EventManagement\Exceptions\InvalidAcquisitionModeException;
use Modules\EventManagement\Exceptions\SaleClosedException;
use Modules\EventManagement\Exceptions\SaleNotStartedException;
use Modules\EventManagement\Models\Bid;
use Modules\EventManagement\Models\Event;

/**
 * Bidding Service
 *
 * Handles ticket bidding mechanism with acquisition mode and sale window validation.
 * Requirements: 3.5, 4.5, 8.1, 8.2, 8.3, 9.1, 9.2
 */
class BiddingService
{
    protected SaleWindowService $saleWindowService;

    public function __construct(SaleWindowService $saleWindowService)
    {
        $this->saleWindowService = $saleWindowService;
    }

    /**
     * Submit a bid for event tickets
     *
     * Validates:
     * - Event acquisition mode is 'bid'
     * - Current time is within sale window
     * - Bid amount is positive
     * - Quantity is positive
     *
     * @throws InvalidAcquisitionModeException
     * @throws SaleNotStartedException
     * @throws SaleClosedException
     * @throws \InvalidArgumentException
     */
    public function submitBid(array $bidData): Bid
    {
        $event = Event::findOrFail($bidData['event_id']);

        // Validate acquisition mode is 'bid'
        $this->validateAcquisitionMode($event);

        // Validate sale window
        $this->validateSaleWindow($event);

        // Validate bid data
        $this->validateBidData($bidData);

        // Create the bid
        $bid = Bid::create([
            'event_id' => $bidData['event_id'],
            'customer_id' => $bidData['customer_id'],
            'bid_amount' => $bidData['bid_amount'],
            'quantity' => $bidData['quantity'],
            'status' => Bid::STATUS_PENDING,
        ]);

        return $bid;
    }

    /**
     * Validate that event acquisition mode is 'bid'
     *
     * Requirements: 4.5
     *
     * @throws InvalidAcquisitionModeException
     */
    protected function validateAcquisitionMode(Event $event): void
    {
        if ($event->ticket_acquisition_mode !== Event::MODE_BID) {
            throw new InvalidAcquisitionModeException('bid', 'purchase');
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
     * Validate bid data
     *
     * @throws \InvalidArgumentException
     */
    protected function validateBidData(array $bidData): void
    {
        if (! isset($bidData['bid_amount']) || $bidData['bid_amount'] <= 0) {
            throw new \InvalidArgumentException('Bid amount must be greater than zero');
        }

        if (! isset($bidData['quantity']) || $bidData['quantity'] <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero');
        }
    }

    /**
     * Check if bidding is allowed for an event
     *
     * Requirements: 4.2, 9.1
     */
    public function canBid(int $eventId, ?Carbon $currentTime = null): array
    {
        try {
            $event = Event::findOrFail($eventId);
            $currentTime = $currentTime ?? Carbon::now();

            // Check acquisition mode
            if ($event->ticket_acquisition_mode !== Event::MODE_BID) {
                return [
                    'can_bid' => false,
                    'reason' => 'This event only supports direct purchase. Cannot bid.',
                ];
            }

            // Check sale window
            $saleStatus = $this->saleWindowService->getSaleStatus($event, $currentTime);

            if ($saleStatus === SaleWindowService::STATUS_NOT_STARTED) {
                return [
                    'can_bid' => false,
                    'reason' => "Bidding will start on {$event->sale_start_date}",
                ];
            }

            if ($saleStatus === SaleWindowService::STATUS_CLOSED) {
                return [
                    'can_bid' => false,
                    'reason' => "Bidding ended on {$event->sale_end_date}",
                ];
            }

            if ($saleStatus === SaleWindowService::STATUS_SOLD_OUT) {
                return [
                    'can_bid' => false,
                    'reason' => 'This event is sold out',
                ];
            }

            return [
                'can_bid' => true,
                'reason' => null,
            ];

        } catch (\Exception $e) {
            return [
                'can_bid' => false,
                'reason' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get bids for an event (staff only)
     *
     * Requirements: 9.2
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getBidsForEvent(int $eventId)
    {
        return Bid::where('event_id', $eventId)
            ->with(['customer', 'event'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get pending bids for an event
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getPendingBidsForEvent(int $eventId)
    {
        return Bid::where('event_id', $eventId)
            ->pending()
            ->with(['customer', 'event'])
            ->orderBy('bid_amount', 'desc')
            ->get();
    }

    /**
     * Process bid action (accept/reject)
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \InvalidArgumentException
     */
    public function processBidAction(int $bidId, string $action, int $adminId, ?string $adminNotes = null, ?string $reason = null): Bid
    {
        $bid = Bid::findOrFail($bidId);

        // Check if bid is in a state that can be processed
        if ($bid->status !== Bid::STATUS_PENDING) {
            throw new \InvalidArgumentException("Bid is already {$bid->status} and cannot be {$action}ed");
        }

        // Update bid status
        $newStatus = $action === 'accept' ? Bid::STATUS_ACCEPTED : Bid::STATUS_REJECTED;

        $bid->update([
            'status' => $newStatus,
            'processed_by' => $adminId,
            'processed_at' => now(),
            'admin_notes' => $adminNotes,
            'rejection_reason' => $action === 'reject' ? $reason : null,
        ]);

        // Reload with relationships
        return $bid->load(['customer', 'event', 'processedBy']);
    }

    /**
     * Get detailed bid information
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getBidDetails(int $bidId): Bid
    {
        return Bid::with(['customer', 'event', 'processedBy'])
            ->findOrFail($bidId);
    }

    /**
     * Bulk process multiple bids
     *
     * @param  array  $bids  Array of bid data with bid_id, action, and optional reason
     */
    public function bulkProcessBids(array $bids, int $adminId, ?string $adminNotes = null): array
    {
        $results = [
            'processed' => [],
            'failed' => [],
            'summary' => [
                'total' => count($bids),
                'accepted' => 0,
                'rejected' => 0,
                'failed' => 0,
            ],
        ];

        foreach ($bids as $bidData) {
            try {
                $bid = $this->processBidAction(
                    $bidData['bid_id'],
                    $bidData['action'],
                    $adminId,
                    $adminNotes,
                    $bidData['reason'] ?? null
                );

                $results['processed'][] = [
                    'bid_id' => $bid->id,
                    'action' => $bidData['action'],
                    'status' => 'success',
                    'new_status' => $bid->status,
                ];

                if ($bidData['action'] === 'accept') {
                    $results['summary']['accepted']++;
                } else {
                    $results['summary']['rejected']++;
                }

            } catch (\Exception $e) {
                $results['failed'][] = [
                    'bid_id' => $bidData['bid_id'],
                    'action' => $bidData['action'],
                    'error' => $e->getMessage(),
                ];

                $results['summary']['failed']++;
            }
        }

        return $results;
    }
}
