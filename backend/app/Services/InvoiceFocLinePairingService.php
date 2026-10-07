<?php

namespace App\Services;

use App\Models\BonusSchemeProduct;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * #1006: bonus-scheme FOC line pairing on invoices.
 *
 * A bonus (buy X get Y) invoice shows the paid product line first and the FOC
 * (free) line for the bonus quantity directly beneath it, linked to its source
 * line. The pair stays adjacent across draft reorders.
 *
 * Two entry points:
 *  - planFromPayload(): resolves each submitted FOC line's paid source line
 *    BEFORE anything is written. A FOC line with no source is rejected (422),
 *    never silently dropped.
 *  - normalize(): orders/links an invoice's stored lines. It only ever writes
 *    `sequence`, `is_foc` and `source_line_id` through the query builder, so
 *    amounts, tax and totals can never be recomputed (safe on posted
 *    invoices). It never deletes a line.
 */
class InvoiceFocLinePairingService
{
    public const FOC_OVERRIDE_REASON = 'Bonus scheme FOC';

    /** @var array<string, array<int, string>> foc product id => qualifying product ids */
    private array $qualifyingByFocProduct = [];

    /**
     * Resolve the paid source line of every FOC line in a submitted lines
     * array. Returns the lines (re-indexed) with `source_line_index` set on
     * every FOC line to the index of its paid line in the returned array.
     *
     * Source resolution: an explicit `source_line_index` wins; else the
     * nearest non-FOC line of the same product; else the nearest non-FOC line
     * whose product is a bonus-scheme qualifying product for the FOC product.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, int|string>  $cascadeProductIds  on a draft edit: products of FOC lines
     *                                                     that were already paired on the invoice. An unpaired FOC line
     *                                                     of such a product is the leftover of a removed paid line and is
     *                                                     removed with it (no explicit source given).
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function planFromPayload(array $lines, array $cascadeProductIds = []): array
    {
        $lines = array_values($lines);
        $cascade = array_map('strval', $cascadeProductIds);
        $sources = [];
        $dropped = [];
        $errors = [];

        foreach ($lines as $index => $line) {
            if (! (bool) ($line['is_foc'] ?? false)) {
                continue;
            }

            $explicit = $line['source_line_index'] ?? null;

            if ($explicit !== null && $explicit !== '') {
                $explicit = (int) $explicit;
                $source = $lines[$explicit] ?? null;

                if ($explicit === $index || $source === null || (bool) ($source['is_foc'] ?? false)
                    || ($source['line_type'] ?? 'product') !== 'product' || empty($source['product_id'])) {
                    $errors["lines.{$index}.source_line_index"] = 'The FOC line\'s source line must be a paid product line on this invoice.';

                    continue;
                }

                $sources[$index] = $explicit;

                continue;
            }

            $sourceIndex = $this->nearestPayloadSource($index, $line, $lines);

            if ($sourceIndex !== null) {
                $sources[$index] = $sourceIndex;

                continue;
            }

            if (! empty($line['product_id']) && in_array((string) $line['product_id'], $cascade, true)) {
                $dropped[$index] = true;

                continue;
            }

            $errors["lines.{$index}.is_foc"] = 'FOC line has no paid source line.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Re-index after removing cascaded FOC leftovers.
        $newIndex = [];
        $result = [];
        foreach ($lines as $index => $line) {
            if (isset($dropped[$index])) {
                continue;
            }
            $newIndex[$index] = count($result);
            $result[] = $line;
        }

        foreach ($result as $position => $line) {
            $original = array_search($position, $newIndex, true);
            if (isset($sources[$original])) {
                $result[$position]['source_line_index'] = $newIndex[$sources[$original]];
            } else {
                unset($result[$position]['source_line_index']);
            }
        }

        return $result;
    }

    /**
     * Pricing-trace stamp for a FOC line: carried at 0 with an audit reason so
     * the free unit is traceable (it skips the manual-price floor check).
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public function focPricingTrace(array $line, ?string $currency = null): array
    {
        $reason = trim((string) ($line['override_reason'] ?? ''));

        return [
            'source' => 'foc',
            'base' => 'bonus_scheme_foc',
            'currency' => $currency,
            'override_reason' => $reason !== '' ? $reason : self::FOC_OVERRIDE_REASON,
        ];
    }

    /**
     * Link stored lines to their payload-resolved source lines.
     *
     * @param  array<int, int>  $lineIdByPayloadIndex
     * @param  array<int, array<string, mixed>>  $plannedLines  output of planFromPayload()
     */
    public function linkPlannedLines(array $lineIdByPayloadIndex, array $plannedLines): void
    {
        foreach ($plannedLines as $index => $line) {
            if (! isset($lineIdByPayloadIndex[$index])) {
                continue;
            }

            $isFoc = (bool) ($line['is_foc'] ?? false);
            $sourceIndex = $line['source_line_index'] ?? null;
            $sourceId = $isFoc && $sourceIndex !== null ? ($lineIdByPayloadIndex[$sourceIndex] ?? null) : null;

            InvoiceLine::query()->whereKey($lineIdByPayloadIndex[$index])->update([
                'is_foc' => $isFoc,
                'source_line_id' => $sourceId,
            ]);
        }
    }

    /**
     * Normalise one invoice's lines. Idempotent — safe to re-run.
     *
     * @return int number of FOC lines whose order/link changed
     */
    public function normalize(Invoice $invoice): int
    {
        /** @var Collection<int, InvoiceLine> $lines */
        $lines = $invoice->lines()->get()->values();

        if ($lines->count() < 2) {
            return 0;
        }

        $candidates = [];
        foreach ($lines as $index => $line) {
            if ($this->isFocCandidate($line, $lines)) {
                $candidates[$index] = true;
            }
        }

        if ($candidates === []) {
            return 0;
        }

        $indexById = [];
        foreach ($lines as $index => $line) {
            $indexById[$line->id] = $index;
        }

        /** @var array<int, int> $parentByFocId FOC line id => paid line id */
        $parentByFocId = [];

        foreach ($lines as $index => $line) {
            if (! isset($candidates[$index])) {
                continue;
            }

            $parentIndex = null;

            // An existing link to a paid line on this invoice wins.
            if ($line->source_line_id !== null && isset($indexById[$line->source_line_id])
                && ! isset($candidates[$indexById[$line->source_line_id]])) {
                $parentIndex = $indexById[$line->source_line_id];
            }

            $parentIndex ??= $this->nearestStoredSource($index, $line, $lines, $candidates);

            if ($parentIndex !== null) {
                $parentByFocId[$line->id] = $lines[$parentIndex]->id;
            }
        }

        if ($parentByFocId === []) {
            return 0;
        }

        $childrenByParent = [];
        foreach ($parentByFocId as $focId => $parentId) {
            $childrenByParent[$parentId][] = $focId;
        }

        $sequence = 0;
        $changed = 0;

        foreach ($lines as $index => $line) {
            if (isset($parentByFocId[$line->id])) {
                // Emitted under its parent below, keeping the paid line first.
                continue;
            }

            // An unpaired line keeps its own FOC flag (a flagged line whose
            // paid line is gone stays visible as FOC, unlinked — never deleted).
            $isFoc = isset($candidates[$index]) ? (bool) $line->is_foc : false;
            $this->updateLine($line, $sequence++, $isFoc, null);

            foreach ($childrenByParent[$line->id] ?? [] as $focId) {
                $focLine = $lines[$indexById[$focId]];
                if ($this->updateLine($focLine, $sequence++, true, $line->id)) {
                    $changed++;
                }
            }
        }

        return $changed;
    }

    /**
     * A line is a FOC candidate when it is flagged, or when it is a
     * zero-priced product line with a paid sibling of the same product (or of
     * a bonus-scheme qualifying product) on the invoice — the shape bonus
     * lines are written with.
     */
    private function isFocCandidate(InvoiceLine $line, Collection $lines): bool
    {
        if ((bool) $line->is_foc) {
            return true;
        }

        if (! $line->product_id || (float) $line->quantity <= 0) {
            return false;
        }

        $zeroPriced = (float) $line->unit_price === 0.0 && (float) $line->line_total === 0.0;

        if (! $zeroPriced) {
            return false;
        }

        $qualifying = $this->qualifyingProductIds((string) $line->product_id);

        return $lines->contains(
            fn (InvoiceLine $other) => $other->id !== $line->id
                && (float) $other->unit_price > 0
                && ((string) $other->product_id === (string) $line->product_id
                    || in_array((string) $other->product_id, $qualifying, true))
        );
    }

    /**
     * Nearest non-FOC stored line of the same product, else of a scheme
     * qualifying product (the preceding line wins a tie).
     *
     * @param  array<int, bool>  $candidates
     */
    private function nearestStoredSource(int $index, InvoiceLine $line, Collection $lines, array $candidates): ?int
    {
        if (! $line->product_id) {
            return null;
        }

        $product = (string) $line->product_id;
        $same = [];
        $mapped = [];
        $qualifying = $this->qualifyingProductIds($product);

        foreach ($lines as $i => $other) {
            if (isset($candidates[$i]) || ! $other->product_id) {
                continue;
            }
            if ((string) $other->product_id === $product) {
                $same[] = $i;
            } elseif (in_array((string) $other->product_id, $qualifying, true)) {
                $mapped[] = $i;
            }
        }

        return $this->nearest($index, $same) ?? $this->nearest($index, $mapped);
    }

    /**
     * Nearest non-FOC payload product line of the same product, else of a
     * scheme qualifying product.
     *
     * @param  array<string, mixed>  $line
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function nearestPayloadSource(int $index, array $line, array $lines): ?int
    {
        if (empty($line['product_id'])) {
            return null;
        }

        $product = (string) $line['product_id'];
        $same = [];
        $mapped = [];
        $qualifying = $this->qualifyingProductIds($product);

        foreach ($lines as $i => $other) {
            if ($i === $index || (bool) ($other['is_foc'] ?? false)
                || ($other['line_type'] ?? 'product') !== 'product' || empty($other['product_id'])) {
                continue;
            }
            if ((string) $other['product_id'] === $product) {
                $same[] = $i;
            } elseif (in_array((string) $other['product_id'], $qualifying, true)) {
                $mapped[] = $i;
            }
        }

        return $this->nearest($index, $same) ?? $this->nearest($index, $mapped);
    }

    /**
     * @param  array<int, int>  $candidates
     */
    private function nearest(int $index, array $candidates): ?int
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if ($best === null || abs($candidate - $index) < abs($best - $index)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Qualifying products of every bonus-scheme row that gives this product
     * free of charge.
     *
     * @return array<int, string>
     */
    private function qualifyingProductIds(string $focProductId): array
    {
        if (! array_key_exists($focProductId, $this->qualifyingByFocProduct)) {
            $this->qualifyingByFocProduct[$focProductId] = BonusSchemeProduct::query()
                ->where('foc_product_id', $focProductId)
                ->whereColumn('qualifying_product_id', '!=', 'foc_product_id')
                ->pluck('qualifying_product_id')
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values()
                ->all();
        }

        return $this->qualifyingByFocProduct[$focProductId];
    }

    /**
     * Query-builder write limited to the pairing columns: no model events, so
     * InvoiceLine::calculateTotals() never runs and posted amounts never move.
     */
    private function updateLine(InvoiceLine $line, int $sequence, bool $isFoc, ?int $sourceLineId): bool
    {
        if ((int) $line->sequence === $sequence
            && (bool) $line->is_foc === $isFoc
            && ($line->source_line_id === null ? null : (int) $line->source_line_id) === $sourceLineId) {
            return false;
        }

        InvoiceLine::query()->whereKey($line->id)->update([
            'sequence' => $sequence,
            'is_foc' => $isFoc,
            'source_line_id' => $sourceLineId,
        ]);

        $line->forceFill([
            'sequence' => $sequence,
            'is_foc' => $isFoc,
            'source_line_id' => $sourceLineId,
        ])->syncOriginal();

        return true;
    }
}
