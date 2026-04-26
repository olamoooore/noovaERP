<?php

namespace App\Services;

use App\Models\RefundTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class RefundService
{
    /**
     * Create a refund request with idempotency protection
     */
    public function createRefundRequest(
        string $sourceType,
        int $sourceId,
        float $refundAmount,
        float $originalAmount,
        array $refundLines,
        string $reason,
        ?string $notes = null,
        ?string $idempotencyKey = null
    ): RefundTransaction {
        return DB::transaction(function () use (
            $sourceType,
            $sourceId,
            $refundAmount,
            $originalAmount,
            $refundLines,
            $reason,
            $notes,
            $idempotencyKey
        ) {
            // Idempotency check: prevent duplicate refund requests
            if ($idempotencyKey) {
                $existing = RefundTransaction::where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    Log::info('Duplicate refund request detected (idempotent)', [
                        'refund_id' => $existing->id,
                        'refund_number' => $existing->refund_number,
                        'idempotency_key' => $idempotencyKey,
                    ]);
                    return $existing;
                }
            }

            // Check for existing refund by source
            $existingBySource = RefundTransaction::where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->whereIn('status', ['pending', 'approved', 'completed'])
                ->first();

            if ($existingBySource) {
                Log::warning('Refund already exists for this source', [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'existing_refund_id' => $existingBySource->id,
                ]);
                return $existingBySource;
            }

            // Validate refund amount
            if ($refundAmount > $originalAmount) {
                throw new \Exception("Refund amount ({$refundAmount}) cannot exceed original amount ({$originalAmount})");
            }

            if ($refundAmount <= 0) {
                throw new \Exception("Refund amount must be greater than zero");
            }

            // Determine refund type
            $refundType = abs($refundAmount - $originalAmount) < 0.01 ? 'full' : 'partial';

            // Create refund transaction
            $refund = RefundTransaction::create([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'refund_type' => $refundType,
                'status' => 'pending',
                'refund_amount' => $refundAmount,
                'original_amount' => $originalAmount,
                'refund_lines' => $refundLines,
                'reason' => $reason,
                'notes' => $notes,
                'requested_by' => TenantUserHelper::resolveTenantUserId(),
                'requested_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);

            Log::info('Refund request created', [
                'refund_id' => $refund->id,
                'refund_number' => $refund->refund_number,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'refund_amount' => $refundAmount,
                'refund_type' => $refundType,
            ]);

            return $refund;
        });
    }

    /**
     * Approve a refund request
     */
    public function approveRefund(int $refundId, int $approvedBy): RefundTransaction
    {
        return DB::transaction(function () use ($refundId, $approvedBy) {
            $refund = RefundTransaction::lockForUpdate()->findOrFail($refundId);

            if (!$refund->isPending()) {
                throw new \Exception("Refund is {$refund->status}, cannot approve");
            }

            $refund->update([
                'status' => 'approved',
                'approved_by' => $approvedBy,
                'approved_at' => now(),
            ]);

            Log::info('Refund approved', [
                'refund_id' => $refund->id,
                'refund_number' => $refund->refund_number,
                'approved_by' => $approvedBy,
            ]);

            return $refund;
        });
    }

    /**
     * Reject a refund request
     */
    public function rejectRefund(int $refundId, int $rejectedBy, string $reason): RefundTransaction
    {
        return DB::transaction(function () use ($refundId, $rejectedBy, $reason) {
            $refund = RefundTransaction::lockForUpdate()->findOrFail($refundId);

            if (!$refund->isPending()) {
                throw new \Exception("Refund is {$refund->status}, cannot reject");
            }

            $refund->update([
                'status' => 'rejected',
                'approved_by' => $rejectedBy,
                'approved_at' => now(),
                'notes' => ($refund->notes ? $refund->notes . "\n\n" : '') . "Rejected: {$reason}",
            ]);

            Log::info('Refund rejected', [
                'refund_id' => $refund->id,
                'refund_number' => $refund->refund_number,
                'rejected_by' => $rejectedBy,
                'reason' => $reason,
            ]);

            return $refund;
        });
    }

    /**
     * Complete a refund (execute the refund)
     */
    public function completeRefund(int $refundId): RefundTransaction
    {
        return DB::transaction(function () use ($refundId) {
            $refund = RefundTransaction::lockForUpdate()->findOrFail($refundId);

            if (!$refund->isApproved()) {
                throw new \Exception("Refund must be approved before completion. Current status: {$refund->status}");
            }

            $refund->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            Log::info('Refund completed', [
                'refund_id' => $refund->id,
                'refund_number' => $refund->refund_number,
            ]);

            return $refund;
        });
    }

    /**
     * Cancel a refund
     */
    public function cancelRefund(int $refundId, string $reason): RefundTransaction
    {
        return DB::transaction(function () use ($refundId, $reason) {
            $refund = RefundTransaction::lockForUpdate()->findOrFail($refundId);

            if ($refund->isCompleted()) {
                throw new \Exception("Cannot cancel completed refund");
            }

            $refund->update([
                'status' => 'cancelled',
                'notes' => ($refund->notes ? $refund->notes . "\n\n" : '') . "Cancelled: {$reason}",
            ]);

            Log::info('Refund cancelled', [
                'refund_id' => $refund->id,
                'refund_number' => $refund->refund_number,
                'reason' => $reason,
            ]);

            return $refund;
        });
    }

    /**
     * Get refund by source
     */
    public function getRefundBySource(string $sourceType, int $sourceId): ?RefundTransaction
    {
        return RefundTransaction::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    /**
     * Validate refund lines against original order
     */
    public function validateRefundLines(array $refundLines, array $originalLines): bool
    {
        foreach ($refundLines as $refundLine) {
            $originalLine = collect($originalLines)->firstWhere('id', $refundLine['line_id']);
            
            if (!$originalLine) {
                throw new \Exception("Refund line {$refundLine['line_id']} not found in original order");
            }

            if ($refundLine['quantity'] > $originalLine['quantity']) {
                throw new \Exception("Refund quantity ({$refundLine['quantity']}) exceeds original quantity ({$originalLine['quantity']}) for line {$refundLine['line_id']}");
            }

            if ($refundLine['amount'] > $originalLine['line_total']) {
                throw new \Exception("Refund amount ({$refundLine['amount']}) exceeds original amount ({$originalLine['line_total']}) for line {$refundLine['line_id']}");
            }
        }

        return true;
    }
}
