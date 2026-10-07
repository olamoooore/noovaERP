<?php

declare(strict_types=1);

namespace App\PaymentAdvices;

use App\Models\Customer;
use App\Models\Invoice;

/**
 * #1001: match an extracted advice against the tenant's open invoices.
 *
 * Suggestions are never posted: a line is either matched to the exact invoice
 * number (status `matched`) or left open with a reason. Amount-based
 * suggestions are offered only when there are no invoice references or none
 * of them matched.
 */
class PaymentAdviceMatchingService
{
    /**
     * @return array{lines: array<int, array<string, mixed>>, customer_id: ?int, extracted_total: float, variance: ?float, suggestions: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    public function match(ParsedAdvice $advice): array
    {
        $lines = [];
        $matchedInvoiceIds = [];
        $matchCount = 0;

        foreach ($advice->lines as $line) {
            $invoice = null;

            if ($line['invoice_number'] !== null) {
                $invoice = Invoice::query()
                    ->where('invoice_number', $line['invoice_number'])
                    ->first();
            }

            $status = 'unmatched';
            $note = null;

            if ($line['invoice_number'] === null) {
                $status = 'no_reference';
                $note = 'The advice line carries no invoice number.';
            } elseif ($invoice === null) {
                $note = 'No invoice with this number was found.';
            } elseif (! in_array($invoice->status, [...PaymentAdviceService::OPEN_INVOICE_STATUSES, 'paid'], true)) {
                $status = 'not_open';
                $note = "Invoice {$invoice->invoice_number} is {$invoice->status}, not an open receivable.";
            } elseif ((float) $invoice->balance <= 0.01) {
                $status = 'already_paid';
                $note = "Invoice {$invoice->invoice_number} has no outstanding balance.";
            } else {
                $status = 'matched';
                $matchCount++;
                $matchedInvoiceIds[] = (int) $invoice->id;
            }

            $lines[] = [
                'invoice_number' => $line['invoice_number'],
                'amount' => $line['amount'],
                'date' => $line['date'] ?? null,
                'description' => $line['description'] ?? null,
                'status' => $status,
                'note' => $note,
                'invoice_id' => $invoice?->id,
                'invoice_balance' => $invoice !== null ? (float) $invoice->balance : null,
            ];
        }

        $extractedTotal = round(array_sum(array_map(fn (array $line) => (float) ($line['amount'] ?? 0), $lines)), 2);
        $variance = $advice->totalAmount !== null
            ? round($advice->totalAmount - $extractedTotal, 2)
            : null;

        // Resolve the payer: an exact invoice match decides the customer;
        // otherwise score the payer name against customer names.
        $customerId = null;
        if ($matchedInvoiceIds !== []) {
            $customerId = (int) Invoice::query()->whereKey($matchedInvoiceIds[0])->value('customer_id');
        } elseif ($advice->payerName !== null) {
            $customerId = $this->resolveCustomerId($advice->payerName);
        }

        $suggestions = $this->suggestionsFor($advice, $customerId, $matchCount);

        $warnings = $advice->warnings;
        if ($variance !== null && abs($variance) > 0.01) {
            $warnings[] = 'line_total_mismatch';
        }
        if ($lines !== [] && $matchCount === 0) {
            $warnings[] = 'no_invoices_matched';
        }
        if ($advice->lines === [] && ! in_array('no_invoice_references', $warnings, true)) {
            $warnings[] = 'no_invoice_references';
        }

        return [
            'lines' => $lines,
            'customer_id' => $customerId,
            'extracted_total' => $extractedTotal,
            'variance' => $variance,
            'suggestions' => $suggestions,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Open-invoice suggestions by customer + amount, used when the advice has
     * no usable invoice references.
     *
     * @return array<int, array<string, mixed>>
     */
    private function suggestionsFor(ParsedAdvice $advice, ?int $customerId, int $matchCount): array
    {
        if ($customerId === null || $advice->totalAmount === null) {
            return [];
        }

        $hasNoReferences = $advice->lines === [];
        if (! $hasNoReferences && $matchCount > 0) {
            return [];
        }

        return Invoice::query()
            ->where('customer_id', $customerId)
            // Open receivables, including partially paid invoices (#1001).
            ->whereIn('status', PaymentAdviceService::OPEN_INVOICE_STATUSES)
            ->where('balance', '>', 0)
            ->orderBy('invoice_date')
            ->get(['id', 'invoice_number', 'invoice_date', 'balance'])
            ->filter(fn (Invoice $invoice) => abs((float) $invoice->balance - $advice->totalAmount) <= 0.01)
            ->map(fn (Invoice $invoice) => [
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'balance' => (float) $invoice->balance,
                'suggested_amount' => $advice->totalAmount,
            ])
            ->values()
            ->all();
    }

    /**
     * Token-overlap score against customer names ("ASCENSUS WOMEN (TMC) PTE.
     * LTD" vs "ASCENSUS-BEH'S CLIINIC FOR WOMEN PTE.LTD."). Company suffixes
     * carry no signal.
     */
    public function resolveCustomerId(string $payerName): ?int
    {
        $needle = $this->nameTokens($payerName);

        if ($needle === []) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        Customer::query()
            ->whereNull('deleted_at')
            ->select(['id', 'name'])
            ->chunkById(200, function ($customers) use ($needle, &$best, &$bestScore) {
                foreach ($customers as $customer) {
                    $tokens = $this->nameTokens((string) $customer->name);
                    if ($tokens === []) {
                        continue;
                    }

                    $overlap = count(array_intersect($needle, $tokens));
                    $score = $overlap / max(count($tokens), 1);

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = (int) $customer->id;
                    }
                }
            });

        return $bestScore >= 0.4 ? $best : null;
    }

    /** @return array<int, string> */
    private function nameTokens(string $name): array
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9\s]/', ' ', $name) ?? '';
        $tokens = preg_split('/\s+/', trim($name)) ?: [];

        $stop = ['pte', 'ltd', 'limited', 'the', 'and', 'pl', 'co', 'inc', 'bhd', 'sdn'];

        return array_values(array_diff(array_filter($tokens), $stop));
    }
}
