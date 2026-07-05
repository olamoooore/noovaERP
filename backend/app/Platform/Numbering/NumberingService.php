<?php

namespace App\Platform\Numbering;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NumberingService — platform-wide document numbering (Pillar 8).
 *
 * Provides centralized prefix/sequence management for all document types.
 * Supports: invoices, purchase orders, quotes, receipts, journals, etc.
 *
 * Usage:
 *   $service = app(NumberingService::class);
 *   $service->register('invoice', 'INV', 6, 'INV-{YYYY}-{NNNNNN}');
 *   $number = $service->next('invoice', $tenantId);
 *   // → 'INV-2026-000001'
 */
class NumberingService
{
    /**
     * Register a document numbering sequence.
     */
    public function register(
        string $documentType,
        string $prefix,
        int $padding = 6,
        string $format = null,
        string $tenantId,
        string $resetFrequency = 'never', // never, yearly, monthly
    ): NumberingSequence {
        return NumberingSequence::updateOrCreate(
            ['tenant_id' => $tenantId, 'document_type' => $documentType],
            [
                'id' => Str::uuid(),
                'prefix' => $prefix,
                'padding' => $padding,
                'format' => $format ?? "{$prefix}-{NNNNNN}",
                'current_number' => 0,
                'reset_frequency' => $resetFrequency,
                'is_active' => true,
            ]
        );
    }

    /**
     * Generate the next document number.
     *
     * Uses DB transaction + FOR UPDATE to prevent race conditions.
     */
    public function next(string $documentType, string $tenantId): string
    {
        return DB::transaction(function () use ($documentType, $tenantId) {
            $sequence = NumberingSequence::where('tenant_id', $tenantId)
                ->where('document_type', $documentType)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$sequence) {
                throw new \DomainException("No numbering sequence registered for '{$documentType}'");
            }

            // Check if reset is needed
            $this->checkReset($sequence);

            // Increment
            $sequence->increment('current_number');
            $sequence->save();

            return $this->formatNumber($sequence);
        });
    }

    /**
     * Peek at the next number without incrementing.
     */
    public function peek(string $documentType, string $tenantId): string
    {
        $sequence = NumberingSequence::where('tenant_id', $tenantId)
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->first();

        if (!$sequence) {
            throw new \DomainException("No numbering sequence registered for '{$documentType}'");
        }

        return $this->formatNumber($sequence, $sequence->current_number + 1);
    }

    /**
     * Get the current number for a document type.
     */
    public function current(string $documentType, string $tenantId): ?int
    {
        $sequence = NumberingSequence::where('tenant_id', $tenantId)
            ->where('document_type', $documentType)
            ->first();

        return $sequence?->current_number;
    }

    /**
     * Get all sequences for a tenant.
     *
     * @return NumberingSequence[]
     */
    public function listSequences(string $tenantId): array
    {
        return NumberingSequence::forTenant($tenantId)
            ->orderBy('document_type')
            ->get()
            ->toArray();
    }

    /**
     * Generate a batch of numbers (for bulk operations).
     *
     * @return string[]
     */
    public function nextBatch(string $documentType, string $tenantId, int $count): array
    {
        $numbers = [];
        for ($i = 0; $i < $count; $i++) {
            $numbers[] = $this->next($documentType, $tenantId);
        }
        return $numbers;
    }

    /**
     * Reset a sequence back to 0.
     */
    public function reset(string $documentType, string $tenantId): bool
    {
        return NumberingSequence::where('tenant_id', $tenantId)
            ->where('document_type', $documentType)
            ->update(['current_number' => 0]) > 0;
    }

    // ─── Private ──────────────────────────────────────────────────

    private function formatNumber(NumberingSequence $sequence, ?int $number = null): string
    {
        $num = $number ?? $sequence->current_number;
        $padded = str_pad((string) $num, $sequence->padding, '0', STR_PAD_LEFT);

        $year = date('Y');
        $month = date('m');

        $format = $sequence->format ?? $sequence->prefix . '-{NNNNNN}';

        return str_replace(
            ['{YYYY}', '{YY}', '{MM}', '{NNNNNN}', '{NNNNN}', '{NNNN}', '{NNN}', '{NN}', '{N}'],
            [$year, substr($year, 2), $month, $padded, substr($padded, 1), substr($padded, 2), substr($padded, 3), substr($padded, 4), substr($padded, 5)],
            $format
        );
    }

    private function checkReset(NumberingSequence $sequence): void
    {
        if ($sequence->reset_frequency === 'never') return;

        $lastReset = $sequence->updated_at;
        $shouldReset = false;

        if ($sequence->reset_frequency === 'yearly' && $lastReset->year < now()->year) {
            $shouldReset = true;
        } elseif ($sequence->reset_frequency === 'monthly' && ($lastReset->year < now()->year || $lastReset->month < now()->month)) {
            $shouldReset = true;
        }

        if ($shouldReset) {
            $sequence->update(['current_number' => 0]);
        }
    }
}
