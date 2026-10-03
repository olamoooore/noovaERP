<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Configurable Document Numbering Service.
 *
 * Reads format patterns from tenant settings and generates document numbers.
 * Supported tokens: {PREFIX}, {YYYY}, {YY}, {MM}, {SEQ:n}
 *
 * Examples:
 *   QU{YYYY}/{MM}/{SEQ:3}  → QU2026/07/009
 *   {YYYY}/{MM}/{SEQ:3}    → 2026/07/078
 *   DO{YYYY}/{MM}/{SEQ:3}  → DO2026/01/008
 */
class DocumentNumberingService
{
    private const SETTING_PREFIX = 'document_format.';

    /**
     * Generate a document number for the given document type.
     *
     * @param  string  $documentType  e.g. 'invoice', 'quotation', 'delivery_order'
     * @param  string  $defaultFormat  e.g. 'INV-{YYYY}-{SEQ:4}'
     */
    public function generate(string $documentType, string $defaultFormat): string
    {
        $format = $this->getFormat($documentType, $defaultFormat);
        $now = now();

        $number = strtr($format, [
            '{YYYY}' => $now->format('Y'),
            '{YY}' => $now->format('y'),
            '{MM}' => $now->format('m'),
        ]);

        // Handle {SEQ:n} — zero-padded sequence number with n digits
        if (preg_match('/\{SEQ:(\d+)\}/', $number, $m)) {
            $padLength = (int) $m[1];
            $seq = $this->nextSequenceFor($documentType, $format);
            $number = str_replace($m[0], str_pad($seq, $padLength, '0', STR_PAD_LEFT), $number);
        }

        return $number;
    }

    /**
     * #538 (ST1): generate a number that is unique against the numbers already
     * issued for the same counter scope.
     *
     * The stored counter can sit behind the issued numbers: after the scope
     * change from per-month to per-year keys (existing tenants), after a
     * format change, or after data was imported. Instead of retrying random
     * counter values and finally issuing an unchecked timestamp-suffixed
     * number (which broke the sequence), this method reads the issued numbers,
     * moves the counter to max(existing sequence) inside the locked update and
     * regenerates. It never returns a number it has seen and never falls back
     * to a timestamp — if the store cannot be advanced, it fails loudly.
     *
     * @param  callable(string): array<int, string>  $existingNumbers  receives
     *                                                                 the literal prefix rendered up to {SEQ:...} and returns every issued
     *                                                                 number beginning with it (including trashed documents).
     */
    public function generateUnique(string $documentType, string $defaultFormat, callable $existingNumbers): string
    {
        $format = $this->getFormat($documentType, $defaultFormat);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $prefix = $this->literalPrefix($format);
            $existing = array_values((array) $existingNumbers($prefix));

            $maxSequence = $this->maxSequenceIn($format, $existing);

            if ($maxSequence !== null) {
                $this->setSequenceAtLeast($documentType, $format, $maxSequence);
            }

            $number = $this->generate($documentType, $defaultFormat);

            if (! in_array($number, $existing, true)) {
                return $number;
            }
        }

        throw new \RuntimeException(
            "Unable to generate a unique {$documentType} number: the sequence store is out of step with the issued numbers."
        );
    }

    /**
     * The format rendered up to the {SEQ:...} token — the literal prefix every
     * issued number for the current period shares.
     */
    private function literalPrefix(string $format): string
    {
        $rendered = strtr($format, [
            '{YYYY}' => now()->format('Y'),
            '{YY}' => now()->format('y'),
            '{MM}' => now()->format('m'),
        ]);

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

        $render = fn (string $part): string => preg_quote(strtr($part, [
            '{YYYY}' => now()->format('Y'),
            '{YY}' => now()->format('y'),
            '{MM}' => now()->format('m'),
        ]), '/');

        return '/^'.$render($before).'(\d+)'.$render($after).'$/';
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

    /**
     * Move the counter for the format's scope up to $atLeast, if it is behind.
     * The read-modify-write happens inside the row lock the counter updates use.
     */
    private function setSequenceAtLeast(string $documentType, string $format, int $atLeast): void
    {
        $seqKey = $this->sequenceKey($documentType, $format);
        $settingTable = (new \App\Models\Setting)->getTable();

        $existing = DB::table($settingTable)
            ->where('key', $seqKey)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            if ((int) $existing->value < $atLeast) {
                DB::table($settingTable)->where('key', $seqKey)->update([
                    'value' => (string) $atLeast,
                    'updated_at' => now(),
                ]);
            }

            return;
        }

        DB::table($settingTable)->insert([
            'key' => $seqKey,
            'value' => (string) $atLeast,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Get the configured format for a document type.
     */
    public function getFormat(string $documentType, string $default): string
    {
        $value = \App\Models\Setting::getValue(self::SETTING_PREFIX.$documentType, $default);

        if (! $value) {
            return $default;
        }

        // A stored pattern with no alphabetic token (e.g. "#{SEQ:5}" or
        // "{SEQ:4}") regenerates every number without its document prefix —
        // invoices then read as bare "#09009" instead of INV-2026-09009.
        // Such patterns are malformed for numbering; fall back to the
        // documented default and leave a breadcrumb for support.
        // Remove the supported tokens first: their letters ({SEQ}, {YYYY})
        // are not a document prefix. What remains must contain a letter.
        $literal = (string) preg_replace(
            '/\{(SEQ:\d+|SEQ|YYYY|YY|MM|PREFIX)\}/',
            '',
            $value,
        );

        if (! preg_match('/[A-Za-z]/', $literal)) {
            Log::warning('DocumentNumberingService: prefix-less format ignored', [
                'document_type' => $documentType,
                'configured' => $value,
                'using' => $default,
            ]);

            return $default;
        }

        return $value;
    }

    /**
     * #542 (ST5): the format must describe a valid numbering scheme: exactly
     * one {SEQ:n} with 3-8 digits, only supported tokens, and at least one
     * letter so every number carries a document prefix. Saving an invalid
     * format used to write it blindly — a format without {SEQ:n} made every
     * document share one number and fall into the collision loop; unknown
     * tokens stayed as literal text.
     *
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateFormat(string $format): array
    {
        $errors = [];
        $sequenceCount = 0;

        preg_match_all('/\{[^}]*\}/', $format, $tokens);

        foreach ($tokens[0] as $token) {
            if (preg_match('/^\{SEQ:(\d+)\}$/', $token, $m)) {
                $sequenceCount++;
                $width = (int) $m[1];

                if ($width < 3 || $width > 8) {
                    $errors[] = "Sequence token {$token} must use between 3 and 8 digits.";
                }

                continue;
            }

            if (! in_array($token, ['{PREFIX}', '{YYYY}', '{YY}', '{MM}'], true)) {
                $errors[] = "Unsupported token {$token}. Supported tokens: {PREFIX}, {YYYY}, {YY}, {MM}, {SEQ:n}.";
            }
        }

        if ($sequenceCount !== 1) {
            $errors[] = 'The format must contain exactly one {SEQ:n} token.';
        }

        $literal = (string) preg_replace('/\{(SEQ:\d+|SEQ|YYYY|YY|MM|PREFIX)\}/', '', $format);
        if (! preg_match('/[A-Za-z]/', $literal)) {
            $errors[] = 'The format must contain at least one letter (a document prefix).';
        }

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /**
     * Set the format for a document type. Invalid formats are rejected.
     *
     * @throws \InvalidArgumentException
     */
    public function setFormat(string $documentType, string $format): void
    {
        $result = $this->validateFormat($format);

        if (! $result['valid']) {
            throw new \InvalidArgumentException(implode(' ', $result['errors']));
        }

        \App\Models\Setting::setValue(self::SETTING_PREFIX.$documentType, $format);
    }

    /**
     * Every configurable sales-document numbering format (#542: all six are
     * editable in one table), each with the next issued value as a preview.
     */
    public const TYPE_DEFAULTS = [
        'invoice' => 'INV-{YYYY}-{SEQ:4}',
        'quotation' => 'QUO-{YYYY}-{SEQ:4}',
        'sales_order' => 'SO-{YYYY}-{SEQ:4}',
        'delivery_order' => 'DO-{YYYY}-{SEQ:4}',
        'credit_note' => 'CN-{YYYY}-{SEQ:4}',
        'payment' => 'PAY/{YYYY}/{MM}/{SEQ:3}',
    ];

    /**
     * Get all document type formats.
     */
    public function getAllFormats(): array
    {
        $formats = [];

        foreach (self::TYPE_DEFAULTS as $type => $default) {
            $formats[$type] = [
                'current' => $this->getFormat($type, $default),
                'default' => $default,
                'example' => $this->previewNext($type, $default),
                'preview' => $this->previewNext($type, $default),
            ];
        }

        return $formats;
    }

    /**
     * Render the number the NEXT document of this type would receive, without
     * consuming a counter value (#542).
     */
    public function previewNext(string $documentType, ?string $defaultFormat = null): string
    {
        $default = $defaultFormat ?? self::TYPE_DEFAULTS[$documentType] ?? 'DOC-{YYYY}-{SEQ:4}';
        $format = $this->getFormat($documentType, $default);

        $next = $this->currentSequence($documentType, $format) + 1;

        $rendered = strtr($format, [
            '{YYYY}' => now()->format('Y'),
            '{YY}' => now()->format('y'),
            '{MM}' => now()->format('m'),
        ]);

        return (string) preg_replace_callback(
            '/\{SEQ:(\d+)\}/',
            fn (array $m): string => str_pad((string) $next, (int) $m[1], '0', STR_PAD_LEFT),
            $rendered,
        );
    }

    private function currentSequence(string $documentType, string $format): int
    {
        $settingTable = (new \App\Models\Setting)->getTable();

        return (int) DB::table($settingTable)
            ->where('key', $this->sequenceKey($documentType, $format))
            ->value('value');
    }

    /**
     * #538 (ST1): the counter scope follows the format.
     *
     *   {MM} present            → per month, {type}.seq.{YYYY}.{MM}
     *   only {YYYY}/{YY}        → per year,  {type}.seq.{YYYY}
     *   neither token           → never resets, {type}.seq
     *
     * Before this, every format used the monthly key, so a year-only format
     * (the default INV-{YYYY}-{SEQ:4}) restarted at 0001 every month and
     * collided with January's numbers.
     */
    private function sequenceKey(string $documentType, string $format): string
    {
        $base = self::SETTING_PREFIX.$documentType.'.seq.';

        if (str_contains($format, '{MM}')) {
            return $base.now()->format('Y').'.'.now()->format('m');
        }

        if (str_contains($format, '{YYYY}') || str_contains($format, '{YY}')) {
            return $base.now()->format('Y');
        }

        return rtrim($base, '.');
    }

    /**
     * Get the next sequence number for a document type in the format's scope.
     */
    private function nextSequenceFor(string $documentType, string $format): int
    {
        $seqKey = $this->sequenceKey($documentType, $format);

        // Atomic increment using DB
        $settingTable = (new \App\Models\Setting)->getTable();
        $existing = DB::table($settingTable)
            ->where('key', $seqKey)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            $next = (int) $existing->value + 1;
            DB::table($settingTable)->where('key', $seqKey)->update(['value' => (string) $next]);
        } else {
            $next = 1;
            DB::table($settingTable)->insert([
                'key' => $seqKey,
                'value' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $next;
    }
}
