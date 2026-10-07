<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceActivityLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * Issue #327 (Odoo 20 style duplicate warning): one duplicate rule for
 * customer invoices, used by invoice create (POST /invoices) and the D7
 * pre-flight checklist. It replaces the pre-flight's own "possible
 * duplicate" query (same total within 7 days), which it keeps unchanged as
 * the POSSIBLE tier, and adds the LIKELY tier.
 *
 *  - likely:   the same customer has a live invoice with the same customer
 *              reference or the same PO number (trimmed, case-insensitive);
 *              any date. Create refuses it until the user confirms.
 *  - possible: the same customer has a live invoice in the same currency for
 *              the same total (±0.5%, at least 0.01) within 7 days of the
 *              invoice date. Create goes through with a warning.
 *
 * Live = draft/posted/sent/partial/overdue/paid and not superseded, voided
 * or reversed (the pre-flight's existing filter).
 *
 * Invoices raised from the same sales order (or the same quotation) are
 * parts of one sale — staged invoicing, down payment + final invoice — and
 * every one of them carries the order's PO number and customer reference.
 * They are never compared with each other; an invoice from another source,
 * or with no source, still is.
 */
class InvoiceDuplicateCheck
{
    public const LEVEL_NONE = 'none';

    public const LEVEL_POSSIBLE = 'possible';

    public const LEVEL_LIKELY = 'likely';

    public const POSSIBLE_WINDOW_DAYS = 7;

    private const LIVE_STATUSES = ['draft', 'posted', 'sent', 'partial', 'overdue', 'paid'];

    private const DEAD_LIFECYCLES = ['superseded', 'voided', 'reversed'];

    private const MAX_MATCHES = 5;

    /**
     * @return array{level: string, matches: list<array<string, mixed>>, confirmed: bool}
     */
    public function check(Invoice $invoice): array
    {
        $matches = [];

        if ($invoice->customer_id) {
            foreach ($this->likelyMatches($invoice) as $match) {
                $matches[$match['id']] = $match;
            }

            foreach ($this->possibleMatches($invoice) as $match) {
                $matches[$match['id']] ??= $match;
            }
        }

        $matches = array_slice(array_values($matches), 0, self::MAX_MATCHES);

        $level = match (true) {
            in_array(self::LEVEL_LIKELY, array_column($matches, 'level'), true) => self::LEVEL_LIKELY,
            $matches !== [] => self::LEVEL_POSSIBLE,
            default => self::LEVEL_NONE,
        };

        return [
            'level' => $level,
            'matches' => $matches,
            'confirmed' => $level === self::LEVEL_LIKELY && $this->confirmedAtCreation($invoice),
        ];
    }

    /**
     * Whether the user confirmed "create anyway" for this invoice — recorded
     * on its `created` activity entry by InvoiceController::store.
     */
    public function confirmedAtCreation(Invoice $invoice): bool
    {
        if (! $invoice->exists) {
            return false;
        }

        $metadata = InvoiceActivityLog::query()
            ->where('invoice_id', $invoice->id)
            ->where('event', InvoiceActivityLog::EVENT_CREATED)
            ->orderBy('id')
            ->value('metadata');

        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;

        return is_array($metadata) && ($metadata['duplicate_confirmed'] ?? false) === true;
    }

    /** One-line summary of the matches for messages and the checklist. */
    public function describe(array $result): string
    {
        return implode('; ', array_map(fn (array $match) => sprintf(
            '%s (%s, %s%s)',
            $match['invoice_number'],
            $match['invoice_date'] ?? 'no date',
            number_format((float) $match['total'], 2),
            $match['level'] === self::LEVEL_LIKELY ? ', same '.implode(' and ', array_map(
                fn (string $field) => $field === 'po_number' ? 'PO number' : 'customer reference',
                $match['matched_on'],
            )) : '',
        ), $result['matches']));
    }

    /** @return list<array<string, mixed>> */
    private function likelyMatches(Invoice $invoice): array
    {
        $fields = array_filter([
            'customer_reference' => $this->normalise($invoice->customer_reference),
            'po_number' => $this->normalise($invoice->po_number),
        ], fn (?string $value) => $value !== null);

        if ($fields === []) {
            return [];
        }

        $candidates = $this->live($invoice)
            ->where(function (Builder $query) use ($fields) {
                foreach ($fields as $column => $value) {
                    $query->orWhereRaw("LOWER(TRIM({$column})) = ?", [$value]);
                }
            })
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->limit(self::MAX_MATCHES)
            ->get();

        return $candidates->map(function (Invoice $candidate) use ($fields) {
            $matchedOn = array_keys(array_filter(
                $fields,
                fn (string $value, string $column) => $this->normalise($candidate->{$column}) === $value,
                ARRAY_FILTER_USE_BOTH,
            ));

            return $this->match($candidate, self::LEVEL_LIKELY, $matchedOn);
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function possibleMatches(Invoice $invoice): array
    {
        if ((float) $invoice->total <= 0) {
            return [];
        }

        $base = $invoice->invoice_date ? $invoice->invoice_date->copy() : now();

        return $this->live($invoice)
            ->when($invoice->currency_code, fn (Builder $query, string $currency) => $query->where('currency_code', $currency))
            ->whereBetween('invoice_date', [
                $base->copy()->subDays(self::POSSIBLE_WINDOW_DAYS)->toDateString(),
                $base->copy()->addDays(self::POSSIBLE_WINDOW_DAYS)->toDateString(),
            ])
            ->whereRaw('ABS(total - ?) <= GREATEST(0.01, ABS(total) * 0.005)', [(float) $invoice->total])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->limit(self::MAX_MATCHES)
            ->get()
            ->map(fn (Invoice $candidate) => $this->match($candidate, self::LEVEL_POSSIBLE, ['total']))
            ->all();
    }

    private function live(Invoice $invoice): Builder
    {
        return Invoice::query()
            ->where('customer_id', $invoice->customer_id)
            ->when($invoice->exists, fn (Builder $query) => $query->whereKeyNot($invoice->id))
            ->when($invoice->sales_order_id, fn (Builder $query, $salesOrderId) => $query->where(fn (Builder $query) => $query
                ->whereNull('sales_order_id')
                ->orWhere('sales_order_id', '!=', $salesOrderId)))
            ->when($invoice->quotation_id, fn (Builder $query, $quotationId) => $query->where(fn (Builder $query) => $query
                ->whereNull('quotation_id')
                ->orWhere('quotation_id', '!=', $quotationId)))
            ->whereIn('status', self::LIVE_STATUSES)
            ->where(fn (Builder $query) => $query
                ->whereNull('lifecycle_status')
                ->orWhereNotIn('lifecycle_status', self::DEAD_LIFECYCLES));
    }

    /** @param list<string> $matchedOn */
    private function match(Invoice $candidate, string $level, array $matchedOn): array
    {
        return [
            'id' => (int) $candidate->id,
            'invoice_number' => $candidate->invoice_number,
            'invoice_date' => $candidate->invoice_date?->toDateString(),
            'total' => (float) $candidate->total,
            'currency_code' => $candidate->currency_code,
            'status' => $candidate->status,
            'level' => $level,
            'matched_on' => $matchedOn,
        ];
    }

    private function normalise(mixed $value): ?string
    {
        $value = mb_strtolower(trim((string) ($value ?? '')));

        return $value === '' ? null : $value;
    }
}
