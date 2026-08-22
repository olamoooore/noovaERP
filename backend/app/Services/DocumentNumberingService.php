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
     * @param string $documentType  e.g. 'invoice', 'quotation', 'delivery_order'
     * @param string $defaultFormat e.g. 'INV-{YYYY}-{SEQ:4}'
     * @return string
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
            $seq = $this->nextSequence($documentType, $now->format('Y'), $now->format('m'));
            $number = str_replace($m[0], str_pad($seq, $padLength, '0', STR_PAD_LEFT), $number);
        }

        return $number;
    }

    /**
     * Get the configured format for a document type.
     */
    public function getFormat(string $documentType, string $default): string
    {
        $value = \App\Models\Setting::getValue(self::SETTING_PREFIX . $documentType, $default);

        return $value ?: $default;
    }

    /**
     * Set the format for a document type.
     */
    public function setFormat(string $documentType, string $format): void
    {
        \App\Models\Setting::setValue(self::SETTING_PREFIX . $documentType, $format);
    }

    /**
     * Get all document type formats.
     */
    public function getAllFormats(): array
    {
        return [
            'invoice' => [
                'current' => $this->getFormat('invoice', 'INV-{YYYY}-{SEQ:4}'),
                'default' => 'INV-{YYYY}-{SEQ:4}',
                'example' => 'INV-2026-0001',
            ],
            'quotation' => [
                'current' => $this->getFormat('quotation', 'QUO-{YYYY}-{SEQ:4}'),
                'default' => 'QUO-{YYYY}-{SEQ:4}',
                'example' => 'QUO-2026-0001',
            ],
            'delivery_order' => [
                'current' => $this->getFormat('delivery_order', 'DO-{YYYY}-{SEQ:4}'),
                'default' => 'DO-{YYYY}-{SEQ:4}',
                'example' => 'DO-2026-0001',
            ],
            'payment' => [
                'current' => $this->getFormat('payment', 'PAY/{YYYY}/{MM}/{SEQ:3}'),
                'default' => 'PAY/{YYYY}/{MM}/{SEQ:3}',
                'example' => 'PAY/2026/08/001',
            ],
        ];
    }

    /**
     * Get the next sequence number for a document type in a given year/month.
     */
    private function nextSequence(string $documentType, string $year, string $month): int
    {
        $seqKey = self::SETTING_PREFIX . $documentType . '.seq.' . $year . '.' . $month;

        // Atomic increment using DB
        $settingTable = (new \App\Models\Setting())->getTable();
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
