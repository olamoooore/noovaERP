<?php

namespace App\Services;

use App\Models\FiscalPeriod;
use App\Models\LockDateOverride;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Fiscal Period Validator
 * 
 * Validates transactions against fiscal period rules
 * Prevents posting to locked periods unless user has override permission
 */
class FiscalPeriodValidator
{
    /**
     * Permission required to override fiscal lock
     */
    private const OVERRIDE_PERMISSION = 'fiscal.override';

    /**
     * Validate if a date can be posted to
     * 
     * @param string|Carbon $date The transaction date
     * @param User|null $user The user attempting to post (null = current user)
     * @return array ['allowed' => bool, 'reason' => string|null, 'period' => FiscalPeriod|null]
     */
    public function validateDate($date, ?User $user = null): array
    {
        $date = Carbon::parse($date);
        $user = $user ?? auth()->user();

        // Get the period for this date
        $period = FiscalPeriod::getPeriodForDate($date);

        if (!$period) {
            Log::warning("Posting blocked: no fiscal period defined for date: {$date->toDateString()}");

            return [
                'allowed' => false,
                'reason'  => "No fiscal period is defined for {$date->toDateString()}. Create or open a fiscal period covering this date before posting.",
                'period'  => null,
            ];
        }

        // Check if period is locked
        if ($period->isLocked()) {
            // Check if user has override permission
            if ($user && $this->userCanOverride($user)) {
                Log::info("User {$user->id} overriding fiscal lock for period: {$period->code}");
                
                return [
                    'allowed' => true,
                    'reason' => null,
                    'period' => $period,
                    'override' => true,
                    'warning' => 'Posting to locked period with override permission',
                ];
            }

            // Period is locked and user cannot override
            return [
                'allowed' => false,
                'reason' => "Fiscal period '{$period->name}' is locked. Cannot post transactions to locked periods.",
                'period' => $period,
            ];
        }

        // Period is open - allow posting
        return [
            'allowed' => true,
            'reason' => null,
            'period' => $period,
        ];
    }

    /**
     * Check if user has permission to override fiscal lock
     * 
     * @param User $user
     * @return bool
     */
    private function userCanOverride(User $user): bool
    {
        // Check if user has the override permission
        // This assumes you're using Spatie Permission or similar
        if (method_exists($user, 'hasPermissionTo')) {
            return $user->hasPermissionTo(self::OVERRIDE_PERMISSION);
        }

        // Fallback: Check if user has a specific role
        if (method_exists($user, 'hasRole')) {
            return $user->hasRole(['admin', 'financial_controller']);
        }

        // Default: No override permission
        return false;
    }

    /**
     * Validate and throw exception if not allowed
     * 
     * @param string|Carbon $date
     * @param User|null $user
     * @return array Validation result
     * @throws \Exception
     */
    public function validateOrFail($date, ?User $user = null): array
    {
        $result = $this->validateDate($date, $user);

        if (!$result['allowed']) {
            throw new \Exception($result['reason']);
        }

        return $result;
    }

    /**
     * Create a DB-backed audit record for fiscal period overrides.
     *
     * Reuses the lock_date_overrides table (transaction_type = 'fiscal_period')
     * so all accounting overrides are queryable from a single table.
     * The Log::warning() is kept alongside for real-time observability.
     *
     * @param FiscalPeriod $period
     * @param User         $user
     * @param string       $action Description of the action (e.g. 'manual_journal_post')
     * @return void
     */
    public function logOverride(FiscalPeriod $period, User $user, string $action): void
    {
        Log::warning('Fiscal period override', [
            'period_id'   => $period->id,
            'period_code' => $period->code,
            'period_name' => $period->name,
            'user_id'     => $user->id,
            'user_email'  => $user->email,
            'action'      => $action,
            'timestamp'   => now()->toIso8601String(),
        ]);

        // Persist to DB via lock_date_overrides (transaction_type = 'fiscal_period')
        LockDateOverride::logOverride(
            userId:          $user->id,
            transactionType: 'fiscal_period',
            transactionId:   $period->id,
            transactionDate: $period->period_start,
            reason:          "Fiscal period override: {$action} on period '{$period->code}'",
            approvedBy:      null,
        );
    }

    /**
     * Get all locked periods
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getLockedPeriods()
    {
        return FiscalPeriod::locked()->orderBy('period_start', 'desc')->get();
    }

    /**
     * Get all open periods
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getOpenPeriods()
    {
        return FiscalPeriod::open()->orderBy('period_start', 'desc')->get();
    }

    /**
     * Check if any period is locked for a date range
     * 
     * @param string|Carbon $startDate
     * @param string|Carbon $endDate
     * @return bool
     */
    public function isRangeLocked($startDate, $endDate): bool
    {
        $startDate = Carbon::parse($startDate);
        $endDate = Carbon::parse($endDate);

        $lockedPeriods = FiscalPeriod::locked()
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('period_start', [$startDate, $endDate])
                      ->orWhereBetween('period_end', [$startDate, $endDate])
                      ->orWhere(function ($q) use ($startDate, $endDate) {
                          $q->where('period_start', '<=', $startDate)
                            ->where('period_end', '>=', $endDate);
                      });
            })
            ->exists();

        return $lockedPeriods;
    }

    /**
     * Get validation summary for multiple dates
     * 
     * @param array $dates Array of dates to validate
     * @param User|null $user
     * @return array Summary of validation results
     */
    public function validateMultipleDates(array $dates, ?User $user = null): array
    {
        $results = [
            'all_allowed' => true,
            'dates' => [],
            'locked_count' => 0,
            'override_count' => 0,
        ];

        foreach ($dates as $date) {
            $validation = $this->validateDate($date, $user);
            $results['dates'][$date] = $validation;

            if (!$validation['allowed']) {
                $results['all_allowed'] = false;
                $results['locked_count']++;
            }

            if (isset($validation['override']) && $validation['override']) {
                $results['override_count']++;
            }
        }

        return $results;
    }
}
