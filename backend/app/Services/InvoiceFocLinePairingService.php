<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;

/**
 * #1006: bonus-scheme FOC line pairing on invoices.
 *
 * A bonus (buy X get Y) invoice shows the paid product line first and the FOC
 * (free) line for the bonus quantity directly beneath it, linked to its source
 * line. The pair stays adjacent across draft reorders, and removing the paid
 * line removes the orphaned FOC line. Purely a line-ordering/link normaliser:
 * amounts, tax and posting are untouched (FOC lines are already zero-priced,
 * and stock was moved by the delivery order).
 */
class InvoiceFocLinePairingService
{
    /**
     * Normalise one invoice's lines. Idempotent — safe to re-run.
     *
     * @return int number of FOC lines paired (0 when nothing changed)
     */
    public function normalize(Invoice $invoice): int
    {
        $lines = $invoice->lines()->get();

        if ($lines->count() < 2) {
            return 0;
        }

        $linesById = $lines->keyBy('id');
        $paidIndexesByProduct = [];

        foreach ($lines as $index => $line) {
            if (! $this->isFocCandidate($line, $lines)) {
                $paidIndexesByProduct[(string) $line->product_id][] = $index;
            }
        }

        /** @var array<int, int> $parentByFocId FOC line id => paid line id */
        $parentByFocId = [];
        $orphanedFocIds = [];

        foreach ($lines as $index => $line) {
            if (! $this->isFocCandidate($line, $lines)) {
                continue;
            }

            $parentIndex = $this->nearestPaidIndex($index, $line, $paidIndexesByProduct);

            if ($parentIndex === null) {
                // A line that was already linked but lost its parent is an
                // orphan (the paid line was deleted) — drop it. A standalone
                // zero-priced line that was never a bonus FOC stays as-is.
                if ($line->is_foc || $line->source_line_id !== null) {
                    $orphanedFocIds[] = $line->id;
                }

                continue;
            }

            $parentByFocId[$line->id] = $lines[$parentIndex]->id;
        }

        if ($parentByFocId === [] && $orphanedFocIds === []) {
            return 0;
        }

        $childrenByParent = [];
        foreach ($parentByFocId as $focId => $parentId) {
            $childrenByParent[$parentId][] = $focId;
        }

        $sequence = 0;
        $paired = 0;

        foreach ($lines as $line) {
            if (in_array($line->id, $orphanedFocIds, true)) {
                $line->delete();

                continue;
            }

            if (isset($parentByFocId[$line->id])) {
                // Emitted under its parent below, keeping the paid line first.
                continue;
            }

            $this->updateLine($line, $sequence++, false, null);

            foreach ($childrenByParent[$line->id] ?? [] as $focId) {
                /** @var InvoiceLine $focLine */
                $focLine = $linesById[$focId];
                if ($this->updateLine($focLine, $sequence++, true, $line->id)) {
                    $paired++;
                }
            }
        }

        return $paired;
    }

    /**
     * A line is a FOC candidate when it is flagged, or when it is a
     * zero-priced product line that has a paid sibling of the same product on
     * the invoice (the shape bonus lines are written with).
     */
    private function isFocCandidate(InvoiceLine $line, $lines): bool
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

        return $lines->contains(
            fn (InvoiceLine $other) => $other->id !== $line->id
                && (string) $other->product_id === (string) $line->product_id
                && (float) $other->unit_price > 0
        );
    }

    /**
     * Nearest paid line of the same product — the preceding line first, then
     * the following one (covers FOC rows submitted before their paid row).
     */
    private function nearestPaidIndex(int $index, InvoiceLine $line, array $paidIndexesByProduct): ?int
    {
        $candidates = $paidIndexesByProduct[(string) $line->product_id] ?? [];

        $best = null;
        foreach ($candidates as $candidate) {
            if ($best === null || abs($candidate - $index) < abs($best - $index)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function updateLine(InvoiceLine $line, int $sequence, bool $isFoc, ?int $sourceLineId): bool
    {
        if ((int) $line->sequence === $sequence
            && (bool) $line->is_foc === $isFoc
            && $line->source_line_id === $sourceLineId) {
            return false;
        }

        $line->update([
            'sequence' => $sequence,
            'is_foc' => $isFoc,
            'source_line_id' => $sourceLineId,
        ]);

        return true;
    }
}
