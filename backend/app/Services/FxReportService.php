<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MRD pricelist-multicurrency FR-7.7 — FX reporting.
 *
 * Two views, both read-only and snapshot-driven (never re-derived from
 * today's rates):
 *
 *   realized()  — realized FX gain/loss per currency and period, computed
 *                 from settled foreign payments with the §9.5 formula
 *                 fx = amount × (payment_rate − invoice_rate), matching the
 *                 PostingEngine's realized gain/loss postings;
 *   exposure()  — open foreign AR/AP by document currency at the document
 *                 snapshot rate, plus the net position per currency.
 *
 * Base currency comes from company_settings (entity-aware resolution is a
 * P3 concern; the ledger is tenant-base today).
 */
class FxReportService
{
    /**
     * @return array{base_currency:string,period:array{from:string,to:string},rows:array,totals:array,total_base:float}
     */
    public function realized(?string $from = null, ?string $to = null): array
    {
        $from = $from ?: now()->subYear()->startOfMonth()->toDateString();
        $to = $to ?: now()->toDateString();

        $base = $this->baseCurrency();

        $rows = [];

        Payment::query()
            ->with('invoice:id,invoice_number,currency_code,exchange_rate,base_currency_code')
            ->whereNotNull('invoice_id')
            ->whereNotNull('exchange_rate')
            ->where('payment_status', 'applied')
            ->whereBetween('payment_date', [$from, $to])
            ->orderBy('payment_date')
            ->chunk(500, function ($payments) use (&$rows, $base) {
                foreach ($payments as $payment) {
                    $invoice = $payment->invoice;
                    if (! $invoice || $invoice->exchange_rate === null) {
                        continue;
                    }

                    $paymentCurrency = strtoupper((string) ($payment->currency ?: $invoice->currency_code ?: $base));
                    $paymentBase = strtoupper((string) ($payment->base_currency_code ?: $invoice->base_currency_code ?: $base));

                    // Only foreign settlements produce realized FX.
                    if ($paymentCurrency === $paymentBase) {
                        continue;
                    }

                    $payRate = (float) $payment->exchange_rate;
                    $invRate = (float) $invoice->exchange_rate;
                    $fx = round((float) $payment->amount * ($payRate - $invRate), 2);

                    $period = Carbon::parse($payment->payment_date)->format('Y-m');
                    $key = $paymentCurrency.'|'.$period;

                    $rows[$key] ??= [
                        'currency_code' => $paymentCurrency,
                        'base_currency' => $paymentBase,
                        'period' => $period,
                        'settled_amount' => 0.0,
                        'gain' => 0.0,
                        'loss' => 0.0,
                        'net' => 0.0,
                        'payment_count' => 0,
                    ];

                    $rows[$key]['settled_amount'] = round($rows[$key]['settled_amount'] + (float) $payment->amount, 2);
                    $rows[$key]['gain'] = round($rows[$key]['gain'] + max($fx, 0.0), 2);
                    $rows[$key]['loss'] = round($rows[$key]['loss'] + min($fx, 0.0), 2);
                    $rows[$key]['net'] = round($rows[$key]['net'] + $fx, 2);
                    $rows[$key]['payment_count']++;
                }
            });

        $totals = [];
        foreach ($rows as $row) {
            $code = $row['currency_code'];
            $totals[$code] ??= ['currency_code' => $code, 'base_currency' => $row['base_currency'], 'gain' => 0.0, 'loss' => 0.0, 'net' => 0.0];
            $totals[$code]['gain'] = round($totals[$code]['gain'] + $row['gain'], 2);
            $totals[$code]['loss'] = round($totals[$code]['loss'] + $row['loss'], 2);
            $totals[$code]['net'] = round($totals[$code]['net'] + $row['net'], 2);
        }

        ksort($rows);

        return [
            'base_currency' => $base,
            'period' => ['from' => $from, 'to' => $to],
            'rows' => array_values($rows),
            'totals' => array_values($totals),
            'total_base' => round(array_sum(array_column($totals, 'net')), 2),
        ];
    }

    /**
     * Open foreign AR/AP by document currency; `base` is each document's
     * snapshot-rate equivalent. Invoices/bills whose currency equals base
     * still appear (base exposure is part of the position) but with a 1:1
     * equivalent.
     *
     * @return array{base_currency:string,as_of:string,ar:array,ap:array,net:array}
     */
    public function exposure(?string $asOf = null): array
    {
        $asOf = $asOf ?: now()->toDateString();
        $base = $this->baseCurrency();

        $ar = $this->exposureByCurrency(
            Invoice::query()
                ->whereIn('status', ['sent', 'posted', 'partial', 'overdue'])
                ->where('balance', '>', 0)
                ->where('invoice_date', '<=', $asOf)
                ->notSuperseded(),
            $base,
        );

        $ap = $this->exposureByCurrency(
            Bill::query()
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->where('balance', '>', 0)
                ->where('bill_date', '<=', $asOf),
            $base,
        );

        $net = [];
        foreach (array_unique(array_merge(array_keys($ar), array_keys($ap))) as $code) {
            $net[$code] = [
                'currency_code' => $code,
                'ar_outstanding' => $ar[$code]['outstanding'] ?? 0.0,
                'ap_outstanding' => $ap[$code]['outstanding'] ?? 0.0,
                'net_outstanding' => round(($ar[$code]['outstanding'] ?? 0.0) - ($ap[$code]['outstanding'] ?? 0.0), 2),
                'net_base' => round(($ar[$code]['base_outstanding'] ?? 0.0) - ($ap[$code]['base_outstanding'] ?? 0.0), 2),
            ];
        }

        return [
            'base_currency' => $base,
            'as_of' => $asOf,
            'ar' => array_values($ar),
            'ap' => array_values($ap),
            'net' => array_values($net),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return array<string,array{currency_code:string,outstanding:float,base_outstanding:float,document_count:int}>
     */
    private function exposureByCurrency($query, string $base): array
    {
        $buckets = [];

        $query->orderBy('id')->chunk(500, function ($documents) use (&$buckets, $base) {
            foreach ($documents as $document) {
                $code = strtoupper((string) ($document->currency_code ?: $base));
                $rate = (float) ($document->exchange_rate ?? 1.0);
                $outstanding = (float) $document->balance;

                $buckets[$code] ??= ['currency_code' => $code, 'outstanding' => 0.0, 'base_outstanding' => 0.0, 'document_count' => 0];

                $buckets[$code]['outstanding'] = round($buckets[$code]['outstanding'] + $outstanding, 2);
                $buckets[$code]['base_outstanding'] = round($buckets[$code]['base_outstanding'] + $outstanding * $rate, 2);
                $buckets[$code]['document_count']++;
            }
        });

        ksort($buckets);

        return $buckets;
    }

    private function baseCurrency(): string
    {
        return strtoupper((string) (DB::table('company_settings')->orderBy('id')->value('base_currency') ?: 'USD'));
    }
}
