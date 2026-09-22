<?php

namespace App\Services;

use App\Models\LockDate;
use App\Models\LockDateOverride;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lock Date Validation Service
 * Validates transaction dates against lock dates
 */
class LockDateValidationService
{
    /**
     * Validate a transaction date against lock dates
     *
     * @param  string  $transactionType  - Type of transaction (sales, purchases, journal_entries, etc.)
     * @param  string|Carbon  $transactionDate  - Date to validate
     * @return array - ['is_locked' => bool, 'lock_date' => date|null, 'message' => string]
     */
    public function validateTransactionDate(string $transactionType, $transactionDate): array
    {
        $date = $transactionDate instanceof Carbon ? $transactionDate : Carbon::parse($transactionDate);

        // Check for specific transaction type lock
        $lockDate = LockDate::where('lock_type', $transactionType)
            ->where('is_active', true)
            ->orderBy('lock_date', 'desc')
            ->first();

        // If no specific lock, check for 'all' type lock
        if (! $lockDate) {
            $lockDate = LockDate::where('lock_type', 'all')
                ->where('is_active', true)
                ->orderBy('lock_date', 'desc')
                ->first();
        }

        // Fallback: check company_settings.lock_date (set via Accounting > Settings > Fiscal Year)
        if (! $lockDate) {
            $companyLockDate = DB::table('company_settings')
                ->whereNotNull('lock_date')
                ->value('lock_date');

            if ($companyLockDate) {
                $lockDate = new \stdClass;
                $lockDate->lock_date = Carbon::parse($companyLockDate);
                $lockDate->lock_type = 'all';
                $lockDate->reason = 'Company-wide lock date';
            }
        }

        // No lock date set
        if (! $lockDate) {
            return [
                'is_locked' => false,
                'lock_date' => null,
                'message' => 'No lock date set for this transaction type',
            ];
        }

        // Check if date is on or before lock date
        if ($date->lte($lockDate->lock_date)) {
            return [
                'is_locked' => true,
                'lock_date' => $lockDate->lock_date->format('Y-m-d'),
                'lock_type' => $lockDate->lock_type,
                'message' => "Cannot post transactions dated on or before {$lockDate->lock_date->format('Y-m-d')}. Period is closed/locked.",
                'reason' => $lockDate->reason ?? null,
            ];
        }

        return [
            'is_locked' => false,
            'lock_date' => $lockDate->lock_date->format('Y-m-d'),
            'message' => 'Transaction date is valid',
        ];
    }

    /**
     * Check if a date is locked for a specific transaction type
     *
     * @param  string|Carbon  $transactionDate
     */
    public function isDateLocked(string $transactionType, $transactionDate): bool
    {
        $result = $this->validateTransactionDate($transactionType, $transactionDate);

        return $result['is_locked'];
    }

    /**
     * Get the lock date for a specific transaction type
     */
    public function getLockDate(string $transactionType): ?LockDate
    {
        $lockDate = LockDate::where('lock_type', $transactionType)
            ->where('is_active', true)
            ->orderBy('lock_date', 'desc')
            ->first();

        if (! $lockDate) {
            $lockDate = LockDate::where('lock_type', 'all')
                ->where('is_active', true)
                ->orderBy('lock_date', 'desc')
                ->first();
        }

        return $lockDate;
    }

    /**
     * Log an override when a user posts to a locked period
     *
     * @param  string|Carbon  $transactionDate
     */
    public function logOverride(
        int $userId,
        string $transactionType,
        ?int $transactionId,
        $transactionDate,
        string $reason,
        ?int $approvedBy = null
    ): LockDateOverride {
        return LockDateOverride::logOverride(
            $userId,
            $transactionType,
            $transactionId,
            $transactionDate,
            $reason,
            $approvedBy
        );
    }

    /**
     * Check if user has permission to override lock dates
     */
    public function canOverrideLockDate(int $userId): bool
    {
        $user = \App\Models\User::find($userId);
        if (! $user) {
            return false;
        }

        // Check for explicit lock date override permission or admin role
        $hasPermission = false;
        if (method_exists($user, 'hasPermissionTo')) {
            try {
                $hasPermission = $user->hasPermissionTo('accounting.lock_date_override');
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
                \Illuminate\Support\Facades\Log::warning('LockDateValidationService: permission does not exist, falling back to roles', [
                    'permission' => 'accounting.lock_date_override',
                    'user_id' => $userId,
                ]);
            }
        }

        return $hasPermission
            || $user->hasRole('admin')
            || $user->hasRole('super-admin');
    }

    /**
     * Validate and throw exception if date is locked
     *
     * @param  string|Carbon  $transactionDate
     *
     * @throws \Exception
     */
    public function validateOrFail(string $transactionType, $transactionDate): void
    {
        $result = $this->validateTransactionDate($transactionType, $transactionDate);

        if ($result['is_locked']) {
            throw new \Exception($result['message']);
        }
    }

    /**
     * Get all active lock dates
     */
    public function getActiveLockDates(): array
    {
        return LockDate::where('is_active', true)
            ->orderBy('lock_date', 'desc')
            ->get()
            ->groupBy('lock_type')
            ->map(function ($group) {
                return $group->first();
            })
            ->toArray();
    }
}
