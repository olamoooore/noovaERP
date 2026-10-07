<?php

namespace App\Platform\Numbering;

use App\Models\Setting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * NumberingService — the single, locked document-numbering service.
 *
 * Owns every document number issued by the platform: the counter row is
 * locked (SELECT ... FOR UPDATE), seeded past any already-issued numbers for
 * the current period, and incremented inside the caller's transaction so a
 * failed document create rolls the number back with it (no gaps from failed
 * writes, no reissued numbers after deletes).
 *
 * Usage (business documents):
 *   $number = app(NumberingService::class)->nextFor(
 *       'sales_order',
 *       'SO-{YYYY}-{SEQ:4}',
 *       null,                                   // tenant (defaults to tenant())
 *       fn (string $prefix): array => ...,      // numbers already issued
 *   );
 */
class NumberingService
{
    private const SETTING_PREFIX = 'document_format.';

    /**
     * #548 / decision on #538 (2026-10-02): the reset period is an explicit
     * per-document-type setting. When a tenant has not chosen one, the period
     * is inferred from the format exactly as before (no behaviour change for
     * existing tenants).
     */
    private const RESET_SETTING_PREFIX = 'document_reset.';

    public const RESET_PERIODS = ['never', 'yearly', 'monthly'];

    /**
     * Register a document numbering sequence.
     */
    public function register(
        string $documentType,
        string $prefix,
        int $padding,
        ?string $format,
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
     * The single, locked entry point for business documents.
     *
     * Resolves the tenant's configured format (falling back to
     * $defaultFormat), keeps the sequence row in sync with that format's
     * period (yearly/monthly/never reset), advances the counter past any
     * number the callback reports as already issued, and returns the next
     * number. The row lock serializes concurrent callers; the increment lives
     * in the same transaction as the document insert, so it rolls back with a
     * failed create.
     *
     * @param  callable(string): array<int, string>|null  $existingNumbers  receives
     *                                                                      the literal prefix rendered up to the sequence token and returns every
     *                                                                      number already issued with that prefix (including trashed documents).
     */
    public function nextFor(
        string $documentType,
        string $defaultFormat,
        ?string $tenantId = null,
        ?callable $existingNumbers = null,
    ): string {
        $tenantId = $this->resolveTenantId($tenantId);
        $format = $this->resolveFormat($documentType, $defaultFormat);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($documentType, $tenantId, $format, $existingNumbers) {

                    $sequence = $this->lockedSequence($documentType, $tenantId, $format);

                    // #548: the same computation the read-only preview uses.
                    $sequence->current_number = $this->pendingValue($sequence, $format, $existingNumbers);
                    $sequence->save();

                    return $this->formatNumber($sequence);
                });
            } catch (UniqueConstraintViolationException $e) {
                // Another connection created the sequence row between our read
                // and insert. Retry against the now-locked row.
                if ($attempt === 2) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException("Unable to allocate a {$documentType} number.");
    }

    /**
     * Preview the number the next business document would receive, without
     * consuming the counter.
     *
     * #548: this runs the exact computation {@see nextFor()} runs (configured
     * format, effective reset period, the period reset, the jump past issued
     * numbers) on an unsaved copy of the sequence row, so the preview equals
     * the next number nextFor() issues. Nothing is written.
     *
     * @param  callable(string): array<int, string>|null  $existingNumbers  the same
     *                                                                      issued-number lookup the document's generator passes to nextFor().
     * @param  string|null  $format  render an unsaved format instead of the configured one
     * @param  string|null  $resetPeriod  preview an unsaved reset period
     */
    public function peekFor(
        string $documentType,
        string $defaultFormat,
        ?string $tenantId = null,
        ?callable $existingNumbers = null,
        ?string $format = null,
        ?string $resetPeriod = null,
    ): string {
        $format ??= $this->resolveFormat($documentType, $defaultFormat);
        $probe = $this->previewSequence($documentType, $defaultFormat, $tenantId, $existingNumbers, $format, $resetPeriod);

        return $this->formatNumber($probe);
    }

    /**
     * The sequence value (integer) the next document of this type receives,
     * without consuming it.
     */
    public function peekValueFor(
        string $documentType,
        string $defaultFormat,
        ?string $tenantId = null,
        ?callable $existingNumbers = null,
    ): int {
        $format = $this->resolveFormat($documentType, $defaultFormat);

        return (int) $this->previewSequence($documentType, $defaultFormat, $tenantId, $existingNumbers, $format, null)->current_number;
    }

    /**
     * #548: move the next sequence value forward (admin setting). Moving it
     * backwards would reissue numbers, so a value below the next number the
     * generator would issue is refused.
     *
     * @throws \InvalidArgumentException when $nextValue is behind the next number
     */
    public function advanceTo(
        string $documentType,
        string $defaultFormat,
        int $nextValue,
        ?string $tenantId = null,
        ?callable $existingNumbers = null,
    ): void {
        $tenantId = $this->resolveTenantId($tenantId);
        $format = $this->resolveFormat($documentType, $defaultFormat);

        DB::transaction(function () use ($documentType, $tenantId, $format, $existingNumbers, $nextValue) {
            $sequence = $this->lockedSequence($documentType, $tenantId, $format);
            $pending = $this->pendingValue($sequence, $format, $existingNumbers);

            if ($nextValue < $pending) {
                throw new \InvalidArgumentException(
                    "The next sequence value can only move forward: it must be at least {$pending}."
                );
            }

            $sequence->current_number = $nextValue - 1;
            $sequence->save();
        });
    }

    /**
     * The explicit reset period stored for a document type, or null when the
     * tenant has not chosen one.
     */
    public function explicitResetPeriod(string $documentType): ?string
    {
        $value = Setting::getValue(self::RESET_SETTING_PREFIX.$documentType);

        return is_string($value) && in_array($value, self::RESET_PERIODS, true) ? $value : null;
    }

    /**
     * The reset period in force: the explicit setting, else inferred from the
     * format.
     */
    public function resolveResetPeriod(string $documentType, string $format): string
    {
        return $this->explicitResetPeriod($documentType) ?? $this->resetFrequencyFor($format);
    }

    /**
     * Store the explicit reset period for a document type.
     */
    public function setResetPeriod(string $documentType, string $period): void
    {
        if (! in_array($period, self::RESET_PERIODS, true)) {
            throw new \InvalidArgumentException("Unsupported reset period {$period}.");
        }

        Setting::setValue(self::RESET_SETTING_PREFIX.$documentType, $period);
    }

    /**
     * Generate the next document number for a registered sequence.
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

            if (! $sequence) {
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

        if (! $sequence) {
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

    /**
     * Resolve the configured number format for a document type.
     *
     * A stored pattern with no alphabetic token (e.g. "#{SEQ:5}" or
     * "{SEQ:4}") regenerates every number without its document prefix;
     * invoices then read as bare "#09009" instead of INV-2026-09009. Such
     * patterns are malformed for numbering; fall back to the documented
     * default and leave a breadcrumb for support.
     */
    public function resolveFormat(string $documentType, string $default): string
    {
        $value = Setting::getValue(self::SETTING_PREFIX.$documentType, $default);

        if (! is_string($value) || $value === '') {
            return $default;
        }

        // Remove the supported tokens first: their letters ({SEQ}, {YYYY})
        // are not a document prefix. What remains must contain a letter.
        $literal = (string) preg_replace(
            '/\{(SEQ:\d+|SEQ|YYYY|YY|MM|PREFIX)\}/',
            '',
            $value,
        );

        if (! preg_match('/[A-Za-z]/', $literal)) {
            Log::warning('NumberingService: prefix-less format ignored', [
                'document_type' => $documentType,
                'configured' => $value,
                'using' => $default,
            ]);

            return $default;
        }

        return $value;
    }

    // ─── Private ──────────────────────────────────────────────────

    private function resolveTenantId(?string $tenantId): string
    {
        if ($tenantId !== null && $tenantId !== '') {
            return $tenantId;
        }

        if (function_exists('tenant') && tenant() !== null) {
            return (string) tenant()->getTenantKey();
        }

        // The sequences table keys tenant_id as a UUID. Central/tenant-less
        // callers (importers, console commands, tests) share this nil UUID
        // instead of failing the uuid cast.
        return '00000000-0000-0000-0000-000000000000';
    }

    /**
     * Load (and lock) or create the sequence row for a document type, synced
     * to the configured format and the effective reset period. Must be called
     * inside a transaction.
     */
    private function lockedSequence(string $documentType, string $tenantId, string $format): NumberingSequence
    {
        $sequence = NumberingSequence::where('tenant_id', $tenantId)
            ->where('document_type', $documentType)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            $sequence = NumberingSequence::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'document_type' => $documentType,
                'prefix' => $this->prefixFor($format, $documentType),
                'padding' => $this->paddingFor($format),
                'format' => $format,
                'current_number' => 0,
                'reset_frequency' => $this->resolveResetPeriod($documentType, $format),
                'is_active' => true,
            ]);
        }

        $this->syncToFormat($sequence, $documentType, $format, null);

        return $sequence;
    }

    private function syncToFormat(NumberingSequence $sequence, string $documentType, string $format, ?string $resetPeriod): void
    {
        $sequence->prefix = $this->prefixFor($format, $documentType);
        $sequence->padding = $this->paddingFor($format);
        $sequence->format = $format;
        $sequence->reset_frequency = $resetPeriod ?? $this->resolveResetPeriod($documentType, $format);
        $sequence->is_active = true;
    }

    /**
     * The value the next number takes: the counter (zeroed when the period
     * has rolled over), advanced past any number already issued for the
     * current period, plus one. Pure — does not write.
     */
    private function pendingValue(NumberingSequence $sequence, string $format, ?callable $existingNumbers): int
    {
        $current = $this->shouldReset($sequence) ? 0 : (int) $sequence->current_number;

        if ($existingNumbers !== null) {
            $existing = array_values((array) $existingNumbers($this->literalPrefix($format)));
            $max = $this->maxSequenceIn($format, $existing);

            if ($max !== null && $max > $current) {
                $current = $max;
            }
        }

        return $current + 1;
    }

    /**
     * An unsaved copy of the sequence row holding the next value — what
     * nextFor() would save, without the lock or the write.
     */
    private function previewSequence(
        string $documentType,
        string $defaultFormat,
        ?string $tenantId,
        ?callable $existingNumbers,
        string $format,
        ?string $resetPeriod,
    ): NumberingSequence {
        $tenantId = $this->resolveTenantId($tenantId);

        $stored = NumberingSequence::where('tenant_id', $tenantId)
            ->where('document_type', $documentType)
            ->first();

        $probe = new NumberingSequence(['current_number' => 0]);

        if ($stored !== null) {
            $probe->current_number = (int) $stored->current_number;
            $probe->updated_at = $stored->updated_at;
        }

        $this->syncToFormat($probe, $documentType, $format, $resetPeriod);
        $probe->current_number = $this->pendingValue($probe, $format, $existingNumbers);

        return $probe;
    }

    /**
     * The counter resets with the format's period: a {MM} token resets
     * monthly, only {YYYY}/{YY} resets yearly, no period token never resets.
     */
    private function resetFrequencyFor(string $format): string
    {
        if (str_contains($format, '{MM}')) {
            return 'monthly';
        }

        if (str_contains($format, '{YYYY}') || str_contains($format, '{YY}')) {
            return 'yearly';
        }

        return 'never';
    }

    private function paddingFor(string $format): int
    {
        if (preg_match('/\{SEQ:(\d+)\}/', $format, $m)) {
            return (int) $m[1];
        }

        return 6;
    }

    /**
     * The literal document prefix stored on the sequence row (cosmetic; the
     * rendered number always comes from the format).
     */
    private function prefixFor(string $format, string $documentType): string
    {
        $cut = strpos($format, '{');
        $head = $cut === false ? $format : substr($format, 0, $cut);
        $head = trim((string) preg_replace('/[^A-Za-z0-9\/_-]/', '', $head), '-_/');

        return $head !== '' ? substr($head, 0, 20) : substr($documentType, 0, 20);
    }

    private function formatNumber(NumberingSequence $sequence, ?int $number = null): string
    {
        $num = $number ?? $sequence->current_number;
        $padded = str_pad((string) $num, $sequence->padding, '0', STR_PAD_LEFT);

        $year = now()->format('Y');
        $month = now()->format('m');

        $format = $sequence->format ?? $sequence->prefix.'-{NNNNNN}';

        // {SEQ:n} carries its own width (the DocumentNumberingService format
        // language); the fixed-width {N...} tokens remain for registered
        // sequences that used them before.
        $format = (string) preg_replace_callback(
            '/\{SEQ:(\d+)\}/',
            fn (array $m): string => str_pad((string) $num, (int) $m[1], '0', STR_PAD_LEFT),
            $format,
        );

        return str_replace(
            ['{YYYY}', '{YY}', '{MM}', '{NNNNNN}', '{NNNNN}', '{NNNN}', '{NNN}', '{NN}', '{N}'],
            [$year, substr($year, 2), $month, $padded, substr($padded, 1), substr($padded, 2), substr($padded, 3), substr($padded, 4), substr($padded, 5)],
            $format
        );
    }

    private function checkReset(NumberingSequence $sequence): void
    {
        if ($this->shouldReset($sequence)) {
            $sequence->update(['current_number' => 0]);
        }
    }

    private function shouldReset(NumberingSequence $sequence): bool
    {
        if ($sequence->reset_frequency === 'never') {
            return false;
        }

        $lastReset = $sequence->updated_at ?? now();

        if ($sequence->reset_frequency === 'yearly' && $lastReset->year < now()->year) {
            return true;
        }

        if ($sequence->reset_frequency === 'monthly'
            && ($lastReset->year < now()->year || $lastReset->month < now()->month)) {
            return true;
        }

        return false;
    }

    /**
     * The format rendered up to the {SEQ:...} token — the literal prefix every
     * issued number for the current period shares.
     */
    private function literalPrefix(string $format): string
    {
        $rendered = $this->renderDateTokens($format);

        $cut = strpos($rendered, '{SEQ');

        return $cut === false ? $rendered : substr($rendered, 0, $cut);
    }

    /**
     * Build the regex for one rendered format so the sequence part of an
     * issued number can be extracted.
     */
    private function sequenceRegex(string $format): ?string
    {
        if (! preg_match('/\{SEQ(?::(\d+))?\}/', $format, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $before = substr($format, 0, $m[0][1]);
        $after = substr($format, $m[0][1] + strlen($m[0][0]));

        return '/^'.$this->renderForRegex($before).'(\d+)'.$this->renderForRegex($after).'$/';
    }

    /**
     * The highest sequence rendered by $format among the issued numbers, or
     * null when none of them match the current format.
     *
     * @param  array<int, string>  $numbers
     */
    private function maxSequenceIn(string $format, array $numbers): ?int
    {
        $regex = $this->sequenceRegex($format);

        if ($regex === null) {
            return null;
        }

        $max = null;

        foreach ($numbers as $candidate) {
            if (is_string($candidate) && preg_match($regex, $candidate, $matches)) {
                $value = (int) $matches[1];
                $max = $max === null ? $value : max($max, $value);
            }
        }

        return $max;
    }

    private function renderDateTokens(string $part): string
    {
        return strtr($part, [
            '{YYYY}' => now()->format('Y'),
            '{YY}' => now()->format('y'),
            '{MM}' => now()->format('m'),
        ]);
    }

    private function renderForRegex(string $part): string
    {
        return preg_quote($this->renderDateTokens($part), '/');
    }
}
