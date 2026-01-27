<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * LoyaltyLedgerService - Task 7.3
 * 
 * Provides ledger-based points transaction system with idempotent operations,
 * points balance reconciliation, audit trails, and expiration management.
 * 
 * Requirements: 23.3, 23.4
 */
class LoyaltyLedgerService
{
    protected AuditService $auditService;
    
    // Cache configuration
    private const CACHE_PREFIX = 'loyalty_ledger:';
    private const BALANCE_CACHE_TTL = 1800; // 30 minutes
    private const EXPIRY_CACHE_TTL = 3600; // 1 hour
    
    // Transaction limits
    private const MAX_TRANSACTION_AMOUNT = 1000000; // 1 million points
    private const MIN_TRANSACTION_AMOUNT = -1000000; // -1 million points
    
    // Points expiry configuration
    private const DEFAULT_EXPIRY_MONTHS = 24; // 2 years
    private const EXPIRY_WARNING_DAYS = 30; // Warn 30 days before expiry

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Process a points transaction with idempotency.
     * 
     * @param array $transactionData Transaction data
     * @return array Transaction result
     * 
     * Requirements: 23.3, 23.4
     */
    public function processTransaction(array $transactionData): array
    {
        return DB::transaction(function () use ($transactionData) {
            try {
                // Validate transaction data
                $this->validateTransactionData($transactionData);
                
                // Check for existing transaction with same idempotency key
                $existingTransaction = DB::table('loyalty_ledger')
                    ->where('idempotency_key', $transactionData['idempotency_key'])
                    ->first();
                
                if ($existingTransaction) {
                    return [
                        'success' => true,
                        'transaction_id' => $existingTransaction->id,
                        'points_balance' => $existingTransaction->points_balance_after,
                        'is_duplicate' => true,
                        'message' => 'Transaction already processed',
                    ];
                }
                
                // Get current balance
                $currentBalance = $this->getCurrentBalance($transactionData['customer_id']);
                
                // Validate transaction constraints
                $this->validateTransactionConstraints($transactionData, $currentBalance);
                
                // Calculate new balance
                $newBalance = $currentBalance + $transactionData['points_delta'];
                
                // Create transaction record
                $transactionId = DB::table('loyalty_ledger')->insertGetId([
                    'customer_id' => $transactionData['customer_id'],
                    'transaction_type' => $transactionData['transaction_type'],
                    'points_delta' => $transactionData['points_delta'],
                    'points_balance_before' => $currentBalance,
                    'points_balance_after' => $newBalance,
                    'reference_type' => $transactionData['reference_type'],
                    'reference_id' => $transactionData['reference_id'] ?? null,
                    'idempotency_key' => $transactionData['idempotency_key'],
                    'transaction_reason' => $transactionData['transaction_reason'],
                    'transaction_metadata' => json_encode($transactionData['metadata'] ?? []),
                    'exchange_rate' => $transactionData['exchange_rate'] ?? null,
                    'currency_code' => $transactionData['currency_code'] ?? null,
                    'transaction_timestamp' => $transactionData['transaction_timestamp'] ?? Carbon::now(),
                    'effective_date' => $transactionData['effective_date'] ?? Carbon::now(),
                    'expiry_date' => $this->calculateExpiryDate($transactionData),
                    'processed_by_system' => $transactionData['processed_by_system'] ?? 'loyalty_ledger',
                    'authorized_by' => $transactionData['authorized_by'] ?? null,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                
                // Handle points earning - create expiry schedule
                if ($transactionData['transaction_type'] === 'earn' && $transactionData['points_delta'] > 0) {
                    $this->createExpirySchedule($transactionId, $transactionData);
                }
                
                // Handle points redemption - update expiry schedule
                if (in_array($transactionData['transaction_type'], ['redeem', 'transfer_out']) && $transactionData['points_delta'] < 0) {
                    $this->updateExpiryScheduleForRedemption($transactionData['customer_id'], abs($transactionData['points_delta']));
                }
                
                // Clear balance cache
                $this->clearBalanceCache($transactionData['customer_id']);
                
                // Create audit log
                $this->auditService->log('loyalty_transaction_processed', [
                    'transaction_id' => $transactionId,
                    'customer_id' => $transactionData['customer_id'],
                    'transaction_type' => $transactionData['transaction_type'],
                    'points_delta' => $transactionData['points_delta'],
                    'new_balance' => $newBalance,
                    'reference_type' => $transactionData['reference_type'],
                ]);
                
                Log::info('Loyalty transaction processed', [
                    'transaction_id' => $transactionId,
                    'customer_id' => $transactionData['customer_id'],
                    'transaction_type' => $transactionData['transaction_type'],
                    'points_delta' => $transactionData['points_delta'],
                    'new_balance' => $newBalance,
                ]);
                
                return [
                    'success' => true,
                    'transaction_id' => $transactionId,
                    'points_balance' => $newBalance,
                    'points_delta' => $transactionData['points_delta'],
                    'transaction_type' => $transactionData['transaction_type'],
                    'is_duplicate' => false,
                ];
                
            } catch (\Exception $e) {
                Log::error('Failed to process loyalty transaction', [
                    'error' => $e->getMessage(),
                    'transaction_data' => $transactionData,
                ]);
                
                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        });
    }

    /**
     * Earn points for a customer.
     * 
     * @param int $customerId Customer ID
     * @param int $points Points to earn
     * @param array $context Transaction context
     * @return array Earning result
     * 
     * Requirements: 23.3, 23.4
     */
    public function earnPoints(int $customerId, int $points, array $context): array
    {
        $transactionData = [
            'customer_id' => $customerId,
            'transaction_type' => 'earn',
            'points_delta' => $points,
            'reference_type' => $context['reference_type'] ?? 'manual',
            'reference_id' => $context['reference_id'] ?? null,
            'idempotency_key' => $context['idempotency_key'] ?? $this->generateIdempotencyKey($customerId, 'earn', $points),
            'transaction_reason' => $context['reason'] ?? 'Points earned',
            'metadata' => $context['metadata'] ?? [],
            'transaction_timestamp' => $context['timestamp'] ?? Carbon::now(),
            'effective_date' => $context['effective_date'] ?? Carbon::now(),
            'authorized_by' => $context['authorized_by'] ?? null,
        ];
        
        return $this->processTransaction($transactionData);
    }

    /**
     * Redeem points for a customer.
     * 
     * @param int $customerId Customer ID
     * @param int $points Points to redeem
     * @param array $context Transaction context
     * @return array Redemption result
     * 
     * Requirements: 23.3, 23.4
     */
    public function redeemPoints(int $customerId, int $points, array $context): array
    {
        // Check available balance
        $availableBalance = $this->getAvailableBalance($customerId);
        
        if ($availableBalance < $points) {
            return [
                'success' => false,
                'error' => 'Insufficient points balance',
                'available_balance' => $availableBalance,
                'requested_points' => $points,
            ];
        }
        
        $transactionData = [
            'customer_id' => $customerId,
            'transaction_type' => 'redeem',
            'points_delta' => -$points, // Negative for redemption
            'reference_type' => $context['reference_type'] ?? 'manual',
            'reference_id' => $context['reference_id'] ?? null,
            'idempotency_key' => $context['idempotency_key'] ?? $this->generateIdempotencyKey($customerId, 'redeem', $points),
            'transaction_reason' => $context['reason'] ?? 'Points redeemed',
            'metadata' => $context['metadata'] ?? [],
            'transaction_timestamp' => $context['timestamp'] ?? Carbon::now(),
            'effective_date' => $context['effective_date'] ?? Carbon::now(),
            'authorized_by' => $context['authorized_by'] ?? null,
        ];
        
        return $this->processTransaction($transactionData);
    }

    /**
     * Adjust points balance (manual adjustment).
     * 
     * @param int $customerId Customer ID
     * @param int $pointsDelta Points adjustment (positive or negative)
     * @param array $context Adjustment context
     * @return array Adjustment result
     * 
     * Requirements: 23.3, 23.4
     */
    public function adjustPoints(int $customerId, int $pointsDelta, array $context): array
    {
        if (!isset($context['authorized_by']) || empty($context['authorized_by'])) {
            return [
                'success' => false,
                'error' => 'Manual adjustments require authorization',
            ];
        }
        
        $transactionData = [
            'customer_id' => $customerId,
            'transaction_type' => 'adjustment',
            'points_delta' => $pointsDelta,
            'reference_type' => $context['reference_type'] ?? 'manual_adjustment',
            'reference_id' => $context['reference_id'] ?? null,
            'idempotency_key' => $context['idempotency_key'] ?? $this->generateIdempotencyKey($customerId, 'adjustment', $pointsDelta),
            'transaction_reason' => $context['reason'] ?? 'Manual points adjustment',
            'metadata' => $context['metadata'] ?? [],
            'transaction_timestamp' => $context['timestamp'] ?? Carbon::now(),
            'effective_date' => $context['effective_date'] ?? Carbon::now(),
            'authorized_by' => $context['authorized_by'],
        ];
        
        return $this->processTransaction($transactionData);
    }

    /**
     * Reverse a transaction.
     * 
     * @param int $transactionId Transaction ID to reverse
     * @param array $context Reversal context
     * @return array Reversal result
     * 
     * Requirements: 23.3, 23.4
     */
    public function reverseTransaction(int $transactionId, array $context): array
    {
        return DB::transaction(function () use ($transactionId, $context) {
            try {
                // Get original transaction
                $originalTransaction = DB::table('loyalty_ledger')->where('id', $transactionId)->first();
                
                if (!$originalTransaction) {
                    return [
                        'success' => false,
                        'error' => 'Original transaction not found',
                    ];
                }
                
                if ($originalTransaction->is_reversed) {
                    return [
                        'success' => false,
                        'error' => 'Transaction already reversed',
                        'reversal_transaction_id' => $originalTransaction->reversal_transaction_id,
                    ];
                }
                
                // Create reversal transaction
                $reversalData = [
                    'customer_id' => $originalTransaction->customer_id,
                    'transaction_type' => 'adjustment',
                    'points_delta' => -$originalTransaction->points_delta, // Opposite of original
                    'reference_type' => 'reversal',
                    'reference_id' => $transactionId,
                    'idempotency_key' => $this->generateIdempotencyKey($originalTransaction->customer_id, 'reversal', $transactionId),
                    'transaction_reason' => $context['reason'] ?? 'Transaction reversal',
                    'metadata' => array_merge(['original_transaction_id' => $transactionId], $context['metadata'] ?? []),
                    'authorized_by' => $context['authorized_by'] ?? null,
                ];
                
                $reversalResult = $this->processTransaction($reversalData);
                
                if ($reversalResult['success']) {
                    // Mark original transaction as reversed
                    DB::table('loyalty_ledger')
                        ->where('id', $transactionId)
                        ->update([
                            'is_reversed' => true,
                            'reversal_transaction_id' => $reversalResult['transaction_id'],
                            'updated_at' => Carbon::now(),
                        ]);
                    
                    // Handle expiry schedule updates for reversals
                    $this->handleReversalExpiryUpdates($originalTransaction, $reversalResult['transaction_id']);
                }
                
                return $reversalResult;
                
            } catch (\Exception $e) {
                Log::error('Failed to reverse transaction', [
                    'transaction_id' => $transactionId,
                    'error' => $e->getMessage(),
                ]);
                
                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        });
    }

    /**
     * Get current points balance for a customer.
     * 
     * @param int $customerId Customer ID
     * @return int Current balance
     * 
     * Requirements: 23.3
     */
    public function getCurrentBalance(int $customerId): int
    {
        $cacheKey = self::CACHE_PREFIX . "balance:{$customerId}";
        $cachedBalance = Cache::get($cacheKey);
        
        if ($cachedBalance !== null) {
            return $cachedBalance;
        }
        
        $latestTransaction = DB::table('loyalty_ledger')
            ->where('customer_id', $customerId)
            ->orderBy('transaction_timestamp', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        
        $balance = $latestTransaction ? $latestTransaction->points_balance_after : 0;
        
        // Cache the balance
        Cache::put($cacheKey, $balance, self::BALANCE_CACHE_TTL);
        
        return $balance;
    }

    /**
     * Get available points balance (excluding expired points).
     * 
     * @param int $customerId Customer ID
     * @return int Available balance
     * 
     * Requirements: 23.3, 23.4
     */
    public function getAvailableBalance(int $customerId): int
    {
        $cacheKey = self::CACHE_PREFIX . "available_balance:{$customerId}";
        $cachedBalance = Cache::get($cacheKey);
        
        if ($cachedBalance !== null) {
            return $cachedBalance;
        }
        
        $availablePoints = DB::table('loyalty_points_expiry_schedule')
            ->where('customer_id', $customerId)
            ->where('expiry_status', 'active')
            ->where('expiry_date', '>', Carbon::now())
            ->sum('points_remaining');
        
        // Cache the available balance
        Cache::put($cacheKey, $availablePoints, self::BALANCE_CACHE_TTL);
        
        return $availablePoints;
    }

    /**
     * Reconcile customer points balance.
     * 
     * @param int $customerId Customer ID
     * @return array Reconciliation result
     * 
     * Requirements: 23.4
     */
    public function reconcileBalance(int $customerId): array
    {
        try {
            // Get ledger balance
            $ledgerBalance = $this->getCurrentBalance($customerId);
            
            // Calculate balance from expiry schedule
            $expiryScheduleBalance = DB::table('loyalty_points_expiry_schedule')
                ->where('customer_id', $customerId)
                ->sum('points_remaining');
            
            // Calculate variance
            $variance = $ledgerBalance - $expiryScheduleBalance;
            
            // Determine variance status
            $varianceStatus = $this->determineVarianceStatus($variance);
            
            // Create reconciliation log
            $reconciliationId = DB::table('loyalty_reconciliation_log')->insertGetId([
                'reconciliation_type' => 'customer_dispute',
                'customer_id' => $customerId,
                'reconciliation_date' => Carbon::now()->toDateString(),
                'expected_balance' => $expiryScheduleBalance,
                'actual_balance' => $ledgerBalance,
                'variance' => $variance,
                'variance_status' => $varianceStatus,
                'variance_explanation' => $this->generateVarianceExplanation($variance),
                'reconciliation_details' => json_encode([
                    'ledger_balance' => $ledgerBalance,
                    'expiry_schedule_balance' => $expiryScheduleBalance,
                    'reconciliation_timestamp' => Carbon::now()->toISOString(),
                ]),
                'reconciliation_started_at' => Carbon::now(),
                'reconciliation_completed_at' => Carbon::now(),
                'reconciliation_duration_seconds' => 0,
                'requires_manual_review' => $varianceStatus !== 'no_variance',
                'is_resolved' => $varianceStatus === 'no_variance',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            
            // Clear caches
            $this->clearBalanceCache($customerId);
            
            // Audit log
            $this->auditService->log('loyalty_balance_reconciled', [
                'reconciliation_id' => $reconciliationId,
                'customer_id' => $customerId,
                'ledger_balance' => $ledgerBalance,
                'expiry_schedule_balance' => $expiryScheduleBalance,
                'variance' => $variance,
                'variance_status' => $varianceStatus,
            ]);
            
            return [
                'success' => true,
                'reconciliation_id' => $reconciliationId,
                'customer_id' => $customerId,
                'ledger_balance' => $ledgerBalance,
                'expiry_schedule_balance' => $expiryScheduleBalance,
                'variance' => $variance,
                'variance_status' => $varianceStatus,
                'requires_manual_review' => $varianceStatus !== 'no_variance',
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to reconcile balance', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get customer transaction history.
     * 
     * @param int $customerId Customer ID
     * @param array $options Query options
     * @return array Transaction history
     * 
     * Requirements: 23.3, 23.4
     */
    public function getTransactionHistory(int $customerId, array $options = []): array
    {
        try {
            $limit = min($options['limit'] ?? 50, 200);
            $offset = $options['offset'] ?? 0;
            $transactionType = $options['transaction_type'] ?? null;
            $startDate = $options['start_date'] ?? null;
            $endDate = $options['end_date'] ?? null;
            
            $query = DB::table('loyalty_ledger')
                ->where('customer_id', $customerId);
            
            if ($transactionType) {
                $query->where('transaction_type', $transactionType);
            }
            
            if ($startDate) {
                $query->where('transaction_timestamp', '>=', $startDate);
            }
            
            if ($endDate) {
                $query->where('transaction_timestamp', '<=', $endDate);
            }
            
            $transactions = $query->orderBy('transaction_timestamp', 'desc')
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->offset($offset)
                ->get();
            
            $totalCount = $query->count();
            
            return [
                'success' => true,
                'customer_id' => $customerId,
                'transactions' => $transactions,
                'pagination' => [
                    'total' => $totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $totalCount,
                ],
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to get transaction history', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // Private helper methods

    /**
     * Validate transaction data.
     */
    private function validateTransactionData(array $transactionData): void
    {
        $requiredFields = ['customer_id', 'transaction_type', 'points_delta', 'idempotency_key', 'transaction_reason'];
        
        foreach ($requiredFields as $field) {
            if (!isset($transactionData[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }
        
        $validTypes = ['earn', 'redeem', 'expire', 'adjustment', 'bonus', 'penalty', 'transfer_in', 'transfer_out', 'refund'];
        if (!in_array($transactionData['transaction_type'], $validTypes)) {
            throw new \InvalidArgumentException("Invalid transaction type: {$transactionData['transaction_type']}");
        }
        
        if ($transactionData['points_delta'] > self::MAX_TRANSACTION_AMOUNT || $transactionData['points_delta'] < self::MIN_TRANSACTION_AMOUNT) {
            throw new \InvalidArgumentException("Transaction amount out of allowed range");
        }
    }

    /**
     * Validate transaction constraints.
     */
    private function validateTransactionConstraints(array $transactionData, int $currentBalance): void
    {
        // Check for negative balance on redemptions
        if ($transactionData['points_delta'] < 0) {
            $newBalance = $currentBalance + $transactionData['points_delta'];
            if ($newBalance < 0) {
                throw new \InvalidArgumentException("Transaction would result in negative balance");
            }
        }
    }

    /**
     * Calculate expiry date for earned points.
     */
    private function calculateExpiryDate(array $transactionData): ?Carbon
    {
        if ($transactionData['transaction_type'] !== 'earn' || $transactionData['points_delta'] <= 0) {
            return null;
        }
        
        if (isset($transactionData['expiry_date'])) {
            return Carbon::parse($transactionData['expiry_date']);
        }
        
        return Carbon::now()->addMonths(self::DEFAULT_EXPIRY_MONTHS);
    }

    /**
     * Create expiry schedule for earned points.
     */
    private function createExpirySchedule(int $transactionId, array $transactionData): void
    {
        if ($transactionData['points_delta'] <= 0) {
            return;
        }
        
        $expiryDate = $this->calculateExpiryDate($transactionData);
        
        DB::table('loyalty_points_expiry_schedule')->insert([
            'customer_id' => $transactionData['customer_id'],
            'earning_transaction_id' => $transactionId,
            'points_earned' => $transactionData['points_delta'],
            'points_remaining' => $transactionData['points_delta'],
            'points_used' => 0,
            'earned_date' => Carbon::parse($transactionData['effective_date'] ?? Carbon::now())->toDateString(),
            'expiry_date' => $expiryDate->toDateString(),
            'expiry_status' => 'active',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Update expiry schedule for point redemption (FIFO).
     */
    private function updateExpiryScheduleForRedemption(int $customerId, int $pointsToRedeem): void
    {
        $remainingToRedeem = $pointsToRedeem;
        
        // Get active points in FIFO order (oldest first)
        $activePoints = DB::table('loyalty_points_expiry_schedule')
            ->where('customer_id', $customerId)
            ->where('expiry_status', 'active')
            ->where('points_remaining', '>', 0)
            ->orderBy('earned_date')
            ->orderBy('id')
            ->get();
        
        foreach ($activePoints as $pointBatch) {
            if ($remainingToRedeem <= 0) {
                break;
            }
            
            $pointsToUseFromBatch = min($remainingToRedeem, $pointBatch->points_remaining);
            $newRemainingPoints = $pointBatch->points_remaining - $pointsToUseFromBatch;
            $newUsedPoints = $pointBatch->points_used + $pointsToUseFromBatch;
            
            // Determine new status
            $newStatus = $newRemainingPoints > 0 ? 'partially_used' : 'fully_used';
            
            // Update the batch
            DB::table('loyalty_points_expiry_schedule')
                ->where('id', $pointBatch->id)
                ->update([
                    'points_remaining' => $newRemainingPoints,
                    'points_used' => $newUsedPoints,
                    'expiry_status' => $newStatus,
                    'updated_at' => Carbon::now(),
                ]);
            
            $remainingToRedeem -= $pointsToUseFromBatch;
        }
    }

    /**
     * Handle expiry updates for transaction reversals.
     */
    private function handleReversalExpiryUpdates($originalTransaction, int $reversalTransactionId): void
    {
        // This would implement the logic to handle expiry schedule updates
        // when transactions are reversed
        Log::info('Handling expiry updates for reversal', [
            'original_transaction_id' => $originalTransaction->id,
            'reversal_transaction_id' => $reversalTransactionId,
        ]);
    }

    /**
     * Generate idempotency key.
     */
    private function generateIdempotencyKey(int $customerId, string $type, $identifier): string
    {
        return hash('sha256', "{$customerId}:{$type}:{$identifier}:" . Carbon::now()->format('Y-m-d'));
    }

    /**
     * Determine variance status.
     */
    private function determineVarianceStatus(int $variance): string
    {
        if ($variance === 0) {
            return 'no_variance';
        } elseif (abs($variance) <= 10) {
            return 'minor_variance';
        } elseif (abs($variance) <= 100) {
            return 'significant_variance';
        } else {
            return 'critical_variance';
        }
    }

    /**
     * Generate variance explanation.
     */
    private function generateVarianceExplanation(int $variance): string
    {
        if ($variance === 0) {
            return 'No variance detected - balances match perfectly';
        } elseif ($variance > 0) {
            return "Ledger balance is {$variance} points higher than expiry schedule";
        } else {
            return "Ledger balance is " . abs($variance) . " points lower than expiry schedule";
        }
    }

    /**
     * Clear balance-related caches.
     */
    private function clearBalanceCache(int $customerId): void
    {
        $cacheKeys = [
            self::CACHE_PREFIX . "balance:{$customerId}",
            self::CACHE_PREFIX . "available_balance:{$customerId}",
        ];
        
        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }
    }
}