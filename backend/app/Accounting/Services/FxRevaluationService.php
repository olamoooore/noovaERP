<?php

namespace App\Accounting\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\PostingResult;
use App\Models\JournalEntryLine;
use App\Services\ExchangeRateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Period-end FX revaluation for open monetary balances.
 *
 * Finds posted journal lines on monetary accounts (AR, AP, bank)
 * that carry a foreign currency, recalculates the base-currency
 * equivalent at the revaluation-date rate, and posts the difference
 * as unrealised FX gain/loss through the PostingEngine.
 */
class FxRevaluationService
{
    public function __construct(
        private PostingEngineInterface $postingEngine,
        private ExchangeRateService $exchangeRateService,
    ) {}

    /**
     * Run FX revaluation for a given date.
     *
     * @param string     $revaluationDate  Y-m-d
     * @param string     $baseCurrency     Functional / base currency code
     * @param array|null $accountIds       Limit to specific GL account IDs (null = all monetary)
     * @return PostingResult[]             One result per currency revalued
     */
    public function revalue(string $revaluationDate, string $baseCurrency, ?array $accountIds = null): array
    {
        // Aggregate foreign-currency balances grouped by account + currency
        $query = JournalEntryLine::query()
            ->select([
                'account_id',
                'currency_code',
                DB::raw('SUM(original_amount) as fc_balance'),
                DB::raw('SUM(debit) - SUM(credit) as book_balance'),
            ])
            ->whereNotNull('currency_code')
            ->where('currency_code', '!=', $baseCurrency)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->groupBy('account_id', 'currency_code')
            ->havingRaw('SUM(original_amount) != 0');

        if ($accountIds) {
            $query->whereIn('account_id', $accountIds);
        }

        $balances = $query->get();

        if ($balances->isEmpty()) {
            Log::info('FX revaluation: no foreign-currency balances found');
            return [];
        }

        $results = [];
        $linesByCurrency = [];

        foreach ($balances as $row) {
            $rate = $this->exchangeRateService->getLatestRate(
                $row->currency_code,
                $baseCurrency,
                $revaluationDate,
            );

            if (!$rate) {
                Log::warning('FX revaluation: no rate found', [
                    'currency' => $row->currency_code,
                    'date' => $revaluationDate,
                ]);
                continue;
            }

            $rateValue = is_array($rate) ? (float) $rate['rate'] : (float) $rate->rate;
            $revaluedBase = round((float) $row->fc_balance * $rateValue, 2);
            $bookBase = round((float) $row->book_balance, 2);
            $difference = round($revaluedBase - $bookBase, 2);

            if (abs($difference) < 0.01) {
                continue; // no material adjustment needed
            }

            $linesByCurrency[$row->currency_code][] = [
                'account_id' => $row->account_id,
                'difference' => $difference,
                'fc_balance' => (float) $row->fc_balance,
                'rate' => $rateValue,
            ];
        }

        // Post one journal per currency
        foreach ($linesByCurrency as $currencyCode => $adjustments) {
            $lines = [];

            foreach ($adjustments as $adj) {
                // Adjust the monetary account
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'unrealized_fx_gain_loss', // placeholder, overridden by accountId
                    accountId: $adj['account_id'],
                    debit: $adj['difference'] > 0 ? $adj['difference'] : 0,
                    credit: $adj['difference'] < 0 ? abs($adj['difference']) : 0,
                    description: "FX reval {$currencyCode} @ {$adj['rate']}",
                    currencyCode: $currencyCode,
                    originalAmount: $adj['fc_balance'],
                    exchangeRate: $adj['rate'],
                );

                // Contra: unrealised FX gain/loss
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'unrealized_fx_gain_loss',
                    accountId: null,
                    debit: $adj['difference'] < 0 ? abs($adj['difference']) : 0,
                    credit: $adj['difference'] > 0 ? $adj['difference'] : 0,
                    description: "Unrealised FX " . ($adj['difference'] > 0 ? 'gain' : 'loss') . " {$currencyCode}",
                );
            }

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'fx_revaluation',
                documentId: 0, // batch process, no single source document
                postingDate: $revaluationDate,
                description: "FX revaluation — {$currencyCode}",
                lines: $lines,
                reference: "FXREVAL-{$currencyCode}-{$revaluationDate}",
                currencyCode: $currencyCode,
                userId: auth()->id() ?? 1,
                metadata: ['base_currency' => $baseCurrency],
            ));

            if ($result->success) {
                Log::info('FX revaluation posted', [
                    'currency' => $currencyCode,
                    'entry_id' => $result->journalEntryId,
                    'adjustments' => count($adjustments),
                ]);
            } else {
                Log::warning('FX revaluation failed', [
                    'currency' => $currencyCode,
                    'error' => $result->error,
                ]);
            }

            $results[] = $result;
        }

        return $results;
    }
}
