<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Exceptions\InvalidAcquisitionModeException;
use Modules\EventManagement\Exceptions\SaleClosedException;
use Modules\EventManagement\Exceptions\SaleNotStartedException;
use Modules\EventManagement\Models\Bid;
use Modules\EventManagement\Models\Event;

/**
 * Bid Flow Orchestrator
 *
 * Consolidated orchestrator for all bid flow processing operations.
 * This service acts as the single entry point for bid flow logic,
 * coordinating between BiddingService, SaleWindowService, and AccessControlService.
 *
 * Requirements: 6.3 - Isolate bid flow processing (if present)
 */
class BidFlowOrchestrator
{
    protected BiddingService $biddingService;

    protected SaleWindowService $saleWindowService;

    protected AccessControlService $accessControlService;

    public function __construct(
        BiddingService $biddingService,
        SaleWindowService $saleWindowService,
        AccessControlService $accessControlService
    ) {
        $this->biddingService = $biddingService;
        $this->saleWindowService = $saleWindowService;
        $this->accessControlService = $accessControlService;
    }

    /**
     * Complete bid submission flow with all validations
     */
    public function submitBidFlow(array $bidData, ?int $userId = null): array
    {
        try {
            Log::info('Starting bid submission flow', [
                'event_id' => $bidData['event_id'],
                'customer_id' => $bidData['customer_id'],
                'user_id' => $userId,
            ]);

            // Step 1: Validate bid eligibility
            $eligibilityCheck = $this->validateBidEligibility($bidData['event_id'], $bidData['customer_id']);
            if (! $eligibilityCheck['eligible']) {
                return [
                    'success' => false,
                    'error' => $eligibilityCheck['reason'],
                    'error_code' => $eligibilityCheck['error_code'],
                ];
            }

            // Step 2: Submit the bid
            $bid = $this->biddingService->submitBid($bidData);

            Log::info('Bid submitted successfully', [
                'bid_id' => $bid->id,
                'event_id' => $bidData['event_id'],
                'customer_id' => $bidData['customer_id'],
            ]);

            return [
                'success' => true,
                'data' => $bid,
                'message' => 'Bid submitted successfully',
            ];

        } catch (InvalidAcquisitionModeException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => 'INVALID_ACQUISITION_MODE',
            ];
        } catch (SaleNotStartedException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => 'SALE_NOT_STARTED',
            ];
        } catch (SaleClosedException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => 'SALE_CLOSED',
            ];
        } catch (\Exception $e) {
            Log::error('Bid submission flow failed', [
                'error' => $e->getMessage(),
                'event_id' => $bidData['event_id'] ?? null,
                'customer_id' => $bidData['customer_id'] ?? null,
            ]);

            return [
                'success' => false,
                'error' => 'An unexpected error occurred during bid submission',
                'error_code' => 'INTERNAL_ERROR',
            ];
        }
    }

    /**
     * Complete bid processing flow with access control
     */
    public function processBidFlow(int $bidId, string $action, int $adminId, array $additionalData = []): array
    {
        try {
            Log::info('Starting bid processing flow', [
                'bid_id' => $bidId,
                'action' => $action,
                'admin_id' => $adminId,
            ]);

            // Step 1: Validate admin permissions (if user object is available)
            // Note: Access control validation should be done at controller level

            // Step 2: Process the bid action
            $bid = $this->biddingService->processBidAction(
                $bidId,
                $action,
                $adminId,
                $additionalData['admin_notes'] ?? null,
                $additionalData['reason'] ?? null
            );

            Log::info('Bid processed successfully', [
                'bid_id' => $bidId,
                'action' => $action,
                'new_status' => $bid->status,
                'admin_id' => $adminId,
            ]);

            return [
                'success' => true,
                'data' => $bid,
                'message' => "Bid {$action}ed successfully",
            ];

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return [
                'success' => false,
                'error' => 'The specified bid was not found',
                'error_code' => 'BID_NOT_FOUND',
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => 'INVALID_BID_STATE',
            ];
        } catch (\Exception $e) {
            Log::error('Bid processing flow failed', [
                'error' => $e->getMessage(),
                'bid_id' => $bidId,
                'action' => $action,
                'admin_id' => $adminId,
            ]);

            return [
                'success' => false,
                'error' => 'An unexpected error occurred during bid processing',
                'error_code' => 'INTERNAL_ERROR',
            ];
        }
    }

    /**
     * Bulk bid processing flow
     */
    public function bulkProcessBidsFlow(array $bids, int $adminId, ?string $adminNotes = null): array
    {
        try {
            Log::info('Starting bulk bid processing flow', [
                'bid_count' => count($bids),
                'admin_id' => $adminId,
            ]);

            $results = $this->biddingService->bulkProcessBids($bids, $adminId, $adminNotes);

            Log::info('Bulk bid processing completed', [
                'total' => $results['summary']['total'],
                'accepted' => $results['summary']['accepted'],
                'rejected' => $results['summary']['rejected'],
                'failed' => $results['summary']['failed'],
                'admin_id' => $adminId,
            ]);

            return [
                'success' => true,
                'data' => $results,
                'message' => 'Bulk bid processing completed',
            ];

        } catch (\Exception $e) {
            Log::error('Bulk bid processing flow failed', [
                'error' => $e->getMessage(),
                'bid_count' => count($bids),
                'admin_id' => $adminId,
            ]);

            return [
                'success' => false,
                'error' => 'An unexpected error occurred during bulk bid processing',
                'error_code' => 'INTERNAL_ERROR',
            ];
        }
    }

    /**
     * Get comprehensive bid information for management
     */
    public function getBidManagementData(int $eventId, array $filters = []): array
    {
        try {
            $bids = $this->biddingService->getBidsForEvent($eventId);
            $event = Event::findOrFail($eventId);

            // Apply filters if provided
            if (! empty($filters['status'])) {
                $bids = $bids->where('status', $filters['status']);
            }

            // Calculate statistics
            $stats = [
                'total_bids' => $bids->count(),
                'pending_bids' => $bids->where('status', Bid::STATUS_PENDING)->count(),
                'accepted_bids' => $bids->where('status', Bid::STATUS_ACCEPTED)->count(),
                'rejected_bids' => $bids->where('status', Bid::STATUS_REJECTED)->count(),
                'average_bid_amount' => $bids->avg('bid_amount'),
                'highest_bid_amount' => $bids->max('bid_amount'),
                'lowest_bid_amount' => $bids->min('bid_amount'),
            ];

            return [
                'success' => true,
                'data' => [
                    'event' => $event,
                    'bids' => $bids->values(),
                    'statistics' => $stats,
                ],
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get bid management data', [
                'error' => $e->getMessage(),
                'event_id' => $eventId,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve bid management data',
                'error_code' => 'INTERNAL_ERROR',
            ];
        }
    }

    /**
     * Validate bid eligibility with comprehensive checks
     */
    protected function validateBidEligibility(int $eventId, int $customerId): array
    {
        try {
            // Check if bidding is allowed for the event
            $canBidResult = $this->biddingService->canBid($eventId);
            if (! $canBidResult['can_bid']) {
                return [
                    'eligible' => false,
                    'reason' => $canBidResult['reason'],
                    'error_code' => 'BIDDING_NOT_ALLOWED',
                ];
            }

            // Check if customer already has a pending bid for this event
            $existingBid = Bid::where('event_id', $eventId)
                ->where('customer_id', $customerId)
                ->where('status', Bid::STATUS_PENDING)
                ->first();

            if ($existingBid) {
                return [
                    'eligible' => false,
                    'reason' => 'You already have a pending bid for this event',
                    'error_code' => 'DUPLICATE_BID',
                ];
            }

            return [
                'eligible' => true,
                'reason' => null,
                'error_code' => null,
            ];

        } catch (\Exception $e) {
            Log::error('Bid eligibility validation failed', [
                'error' => $e->getMessage(),
                'event_id' => $eventId,
                'customer_id' => $customerId,
            ]);

            return [
                'eligible' => false,
                'reason' => 'Unable to validate bid eligibility',
                'error_code' => 'VALIDATION_ERROR',
            ];
        }
    }

    /**
     * Get bid flow analytics and insights
     */
    public function getBidFlowAnalytics(?int $eventId = null, array $dateRange = []): array
    {
        try {
            $query = Bid::query();

            if ($eventId) {
                $query->where('event_id', $eventId);
            }

            if (! empty($dateRange['from'])) {
                $query->where('created_at', '>=', $dateRange['from']);
            }

            if (! empty($dateRange['to'])) {
                $query->where('created_at', '<=', $dateRange['to']);
            }

            $bids = $query->get();

            $analytics = [
                'total_bids' => $bids->count(),
                'pending_bids' => $bids->where('status', Bid::STATUS_PENDING)->count(),
                'accepted_bids' => $bids->where('status', Bid::STATUS_ACCEPTED)->count(),
                'rejected_bids' => $bids->where('status', Bid::STATUS_REJECTED)->count(),
                'average_bid_amount' => $bids->avg('bid_amount'),
                'highest_bid_amount' => $bids->max('bid_amount'),
                'total_bid_value' => $bids->sum('bid_amount'),
                'acceptance_rate' => $bids->count() > 0 ?
                    ($bids->where('status', Bid::STATUS_ACCEPTED)->count() / $bids->count()) * 100 : 0,
                'bid_distribution' => [
                    'by_status' => $bids->groupBy('status')->map->count(),
                    'by_amount_range' => $this->getBidAmountDistribution($bids),
                ],
            ];

            return [
                'success' => true,
                'data' => $analytics,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get bid flow analytics', [
                'error' => $e->getMessage(),
                'event_id' => $eventId,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve bid analytics',
                'error_code' => 'INTERNAL_ERROR',
            ];
        }
    }

    /**
     * Get bid amount distribution for analytics
     *
     * @param  \Illuminate\Support\Collection  $bids
     */
    protected function getBidAmountDistribution($bids): array
    {
        $ranges = [
            '0-100' => 0,
            '101-500' => 0,
            '501-1000' => 0,
            '1001-5000' => 0,
            '5000+' => 0,
        ];

        foreach ($bids as $bid) {
            $amount = $bid->bid_amount;

            if ($amount <= 100) {
                $ranges['0-100']++;
            } elseif ($amount <= 500) {
                $ranges['101-500']++;
            } elseif ($amount <= 1000) {
                $ranges['501-1000']++;
            } elseif ($amount <= 5000) {
                $ranges['1001-5000']++;
            } else {
                $ranges['5000+']++;
            }
        }

        return $ranges;
    }
}
