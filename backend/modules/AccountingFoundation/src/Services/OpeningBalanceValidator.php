<?php

namespace Modules\AccountingFoundation\Services;

use App\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;
use Modules\AccountingFoundation\Models\OpeningBalanceBatch;

/**
 * ACC-001 Opening Balance — validation rules.
 *
 * Issue #70: validates a batch before it can proceed to reconciliation.
 * All validation failures are terminal (VALIDATION_FAILED) — the batch
 * must be corrected and re-imported.
 *
 * Validation rules:
 * 1. Lines exist
 * 2. Total debits = total credits (balanced)
 * 3. Every account_id exists in chart_of_accounts
 * 4. Fiscal period is open (fail-closed)
 * 5. No zero-amount lines
 * 6. Source hash is consistent
 */
class OpeningBalanceValidator
{
    public function validate(OpeningBalanceBatch $batch): ValidationResult
    {
        $errors = [];

        // Rule 1: lines exist
        $lineCount = $batch->lines()->count();
        if ($lineCount === 0) {
            return ValidationResult::failed(['No lines captured in batch']);
        }

        // Rule 2: balanced (total debits = total credits)
        $totalDebit = (float) $batch->lines()->sum('debit');
        $totalCredit = (float) $batch->lines()->sum('credit');
        if (abs($totalDebit - $totalCredit) > 0.01) {
            $errors[] = sprintf('Unbalanced: debits %.2f ≠ credits %.2f', $totalDebit, $totalCredit);
        }

        // Rule 3: every account exists
        $accountIds = $batch->lines()->pluck('account_id')->unique()->toArray();
        $existingAccounts = DB::table('chart_of_accounts')
            ->whereIn('id', $accountIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
        $missing = array_diff($accountIds, $existingAccounts);
        if ($missing) {
            $errors[] = 'Missing accounts: ' . implode(', ', $missing);
        }

        // Rule 4: fiscal period is open
        $period = FiscalPeriod::find($batch->fiscal_period_id);
        if (! $period) {
            $errors[] = 'Fiscal period not found';
        } elseif ($period->status !== 'open') {
            $errors[] = "Fiscal period is {$period->status} (must be open)";
        }

        // Rule 5: no zero-amount lines
        $zeroAmountLines = $batch->lines()
            ->where('debit', 0)
            ->where('credit', 0)
            ->count();
        if ($zeroAmountLines > 0) {
            $errors[] = "{$zeroAmountLines} lines have zero amounts";
        }

        // Rule 6: source hash consistency
        $currentHash = $batch->computeSourceHash();
        if ($batch->source_hash && $currentHash !== $batch->source_hash) {
            $errors[] = 'Source hash mismatch — data may have been modified since import';
        }

        return empty($errors)
            ? ValidationResult::passed()
            : ValidationResult::failed($errors);
    }
}

/**
 * Value object for validation/reconciliation results.
 */
class ValidationResult
{
    private function __construct(
        public readonly bool $passed,
        public readonly array $errors = [],
    ) {}

    public static function passed(): self
    {
        return new self(passed: true);
    }

    public static function failed(array $errors): self
    {
        return new self(passed: false, errors: $errors);
    }
}
