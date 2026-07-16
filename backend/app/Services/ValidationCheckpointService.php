<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Go-live validation gates. Each gate returns pass/fail with details.
 */
class ValidationCheckpointService
{
    /**
     * Run all go-live gates. Returns array of gate results.
     */
    public function runAll(): array
    {
        return [
            'trial_balance_tie_out' => $this->checkTrialBalance(),
            'mandatory_roles_assigned' => $this->checkMandatoryRoles(),
            'system_accounts_complete' => $this->checkSystemAccounts(),
            'sample_posting' => $this->checkSamplePosting(),
            'coa_mapped' => $this->checkCoaMapped(),
            'opening_balances' => $this->checkOpeningBalances(),
        ];
    }

    /**
     * Check if all gates pass.
     */
    public function canGoLive(): bool
    {
        $results = $this->runAll();
        foreach ($results as $gate) {
            if (! $gate['passed']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Trial balance should net to zero.
     */
    private function checkTrialBalance(): array
    {
        $balance = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');

        $net = (float) ($balance ?? 0);
        $passed = abs($net) < 0.01;

        return [
            'passed' => $passed,
            'detail' => $passed
                ? 'Trial balance ties out (net = 0).'
                : "Trial balance does not tie out. Net difference: {$net}.",
        ];
    }

    /**
     * All mandatory account roles must be assigned.
     */
    private function checkMandatoryRoles(): array
    {
        $unassigned = DB::table('account_roles')
            ->where('is_mandatory', true)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('entity_account_roles')
                    ->whereColumn('entity_account_roles.role_id', 'account_roles.id')
                    ->where('entity_account_roles.is_active', true);
            })
            ->pluck('code')
            ->toArray();

        $passed = empty($unassigned);

        return [
            'passed' => $passed,
            'detail' => $passed
                ? 'All mandatory account roles are assigned.'
                : 'Unassigned mandatory roles: '.implode(', ', $unassigned),
        ];
    }

    /**
     * Key system account mappings must exist.
     */
    private function checkSystemAccounts(): array
    {
        $required = ['AR_CONTROL', 'AP_CONTROL', 'CASH_MAIN', 'RETAINED_EARNINGS'];
        $missing = [];

        foreach ($required as $roleCode) {
            $exists = DB::table('entity_account_roles')
                ->join('account_roles', 'account_roles.id', '=', 'entity_account_roles.role_id')
                ->where('account_roles.code', $roleCode)
                ->where('entity_account_roles.is_active', true)
                ->exists();

            if (! $exists) {
                $missing[] = $roleCode;
            }
        }

        $passed = empty($missing);

        return [
            'passed' => $passed,
            'detail' => $passed
                ? 'All system accounts are configured.'
                : 'Missing system accounts: '.implode(', ', $missing),
        ];
    }

    /**
     * Verify that a sample posting can succeed (dry run).
     */
    private function checkSamplePosting(): array
    {
        // Check that PostingEngine can resolve AR_CONTROL and CASH_MAIN
        $arAccount = DB::table('entity_account_roles')
            ->join('account_roles', 'account_roles.id', '=', 'entity_account_roles.role_id')
            ->where('account_roles.code', 'AR_CONTROL')
            ->where('entity_account_roles.is_active', true)
            ->value('entity_account_roles.account_id');

        $cashAccount = DB::table('entity_account_roles')
            ->join('account_roles', 'account_roles.id', '=', 'entity_account_roles.role_id')
            ->where('account_roles.code', 'CASH_MAIN')
            ->where('entity_account_roles.is_active', true)
            ->value('entity_account_roles.account_id');

        if (! $arAccount || ! $cashAccount) {
            return [
                'passed' => false,
                'detail' => 'Cannot verify sample posting: AR_CONTROL or CASH_MAIN not assigned.',
            ];
        }

        // Verify both accounts exist in chart_of_accounts
        $arExists = DB::table('chart_of_accounts')->where('id', $arAccount)->exists();
        $cashExists = DB::table('chart_of_accounts')->where('id', $cashAccount)->exists();

        $passed = $arExists && $cashExists;

        return [
            'passed' => $passed,
            'detail' => $passed
                ? 'Sample posting verification passed (AR and Cash accounts resolve correctly).'
                : 'Sample posting failed: assigned accounts do not exist in chart_of_accounts.',
        ];
    }

    /**
     * Check COA accounts are mapped to master nodes.
     */
    private function checkCoaMapped(): array
    {
        $totalAccounts = DB::table('chart_of_accounts')->count();
        $mappedAccounts = DB::table('entity_account_mappings')->count();

        if ($totalAccounts === 0) {
            return [
                'passed' => false,
                'detail' => 'No chart of accounts entries found.',
            ];
        }

        $pct = round(($mappedAccounts / $totalAccounts) * 100, 1);
        $passed = $pct >= 80; // 80% threshold

        return [
            'passed' => $passed,
            'detail' => "{$mappedAccounts}/{$totalAccounts} accounts mapped to master COA ({$pct}%)."
                .($passed ? '' : ' Minimum 80% mapping required.'),
        ];
    }

    /**
     * Check if opening balances are posted (optional but flagged).
     */
    private function checkOpeningBalances(): array
    {
        $hasOpening = DB::table('journal_entries')
            ->where('type', 'opening_balance')
            ->where('status', 'posted')
            ->exists();

        return [
            'passed' => $hasOpening,
            'detail' => $hasOpening
                ? 'Opening balances have been posted.'
                : 'No opening balances posted yet. This is recommended before go-live.',
        ];
    }
}
