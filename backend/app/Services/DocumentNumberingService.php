<?php

namespace App\Services;

use App\Platform\Numbering\NumberingService;

/**
 * DocumentNumberingService — thin compatibility facade (deprecated).
 *
 * Issue #899 (SALES-PLAT / PC5): the counter logic, collision repair loop and
 * storage that used to live here are gone. Every allocation now happens in the
 * single platform service, {@see NumberingService}, which locks the sequence
 * row, respects the configured reset period and never guesses a number.
 *
 * This class only remains because platform models outside the Sales module
 * (Invoice, Quotation, CustomerCreditNote, Payment, MedicalCase, BonusScheme)
 * and the document-format settings screens still call it. New code must inject
 * NumberingService directly.
 */
class DocumentNumberingService
{
    public function __construct(
        private readonly NumberingService $numbering,
    ) {}

    /**
     * Generate a document number for the given document type.
     *
     * @param  string  $documentType  e.g. 'invoice', 'quotation', 'delivery_order'
     * @param  string  $defaultFormat  e.g. 'INV-{YYYY}-{SEQ:4}'
     */
    public function generate(string $documentType, string $defaultFormat): string
    {
        return $this->numbering->nextFor($documentType, $defaultFormat);
    }

    /**
     * Generate a document number, advancing the locked counter past any
     * number the callback reports as already issued (imported documents,
     * legacy counters). Kept for the callers that pass an issued-number
     * callback; it no longer retries or guesses locally.
     *
     * @param  callable(string): array<int, string>  $existingNumbers  receives
     *                                                                 the literal prefix rendered up to {SEQ:...} and returns every issued
     *                                                                 number beginning with it (including trashed documents).
     */
    public function generateUnique(string $documentType, string $defaultFormat, callable $existingNumbers): string
    {
        return $this->numbering->nextFor($documentType, $defaultFormat, null, $existingNumbers);
    }

    /**
     * Get the configured format for a document type.
     */
    public function getFormat(string $documentType, string $default): string
    {
        return $this->numbering->resolveFormat($documentType, $default);
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

        \App\Models\Setting::setValue('document_format.'.$documentType, $format);
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

        return $this->numbering->peekFor($documentType, $default);
    }
}
