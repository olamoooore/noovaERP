<?php

declare(strict_types=1);

namespace App\PaymentAdvices\Parsers;

use App\PaymentAdvices\AbstractAdviceParser;
use App\PaymentAdvices\ParsedAdvice;

/**
 * #1001: Standard Chartered Straight2Bank payee advice — Reference column is
 * our invoice number; total from "Invoice Total" or "Remittance amount".
 * Handles both the older `Date :` layout and the newer ACH advice.
 */
final class ScbAdviceParser extends AbstractAdviceParser
{
    public function bankCode(): string
    {
        return 'scb';
    }

    public function parse(string $text): ParsedAdvice
    {
        $lines = [];

        [$header, $table] = str_contains($text, 'Payment Details')
            ? explode('Payment Details', $text, 2)
            : ['', $text];

        // OCR keeps each cell on its own line: the reference and date lines
        // are followed by one or more description lines with the amount merged
        // in. Capture blocks between reference/date pairs and take the first
        // standalone money token that is not a reference code or a year.
        if (preg_match_all(
            '/(\d{4}\s*\/\s*\d{2}\s*\/\s*\d{2,3})\s*\n\s*(\d{2}\/\d{2}\/\d{4}|\d{1,2}-[A-Za-z]{3}-\d{4})\s*\n(.*?)(?=\n\s*\d{4}\s*\/|$)/is',
            $table,
            $blocks,
            PREG_SET_ORDER,
        ) !== false) {
            foreach ($blocks as $block) {
                $amount = $this->firstAmount($block[3]);

                if ($amount === null) {
                    continue;
                }

                $lines[] = [
                    'invoice_number' => preg_replace('/\s+/', '', $block[1]),
                    'amount' => $amount,
                    'date' => $this->isoDate($block[2]),
                    'description' => $this->cleanLabel(preg_replace('/\s+/', ' ', trim($block[3]))),
                ];
            }
        }

        // Single-line rows (Tesseract keeps the table cells on one line):
        // reference, date, description, amount.
        if ($lines === [] && preg_match_all(
            '/(\d{4}\s*\/\s*\d{2}\s*\/\s*\d{2,3})\s+(\d{2}\/\d{2}\/\d{4}|\d{1,2}-[A-Za-z]{3}-\d{4})\s+(.+?)\s+([\d,]+(?:\.\d{1,2})?)\s*$/im',
            $table,
            $rows,
            PREG_SET_ORDER,
        ) !== false) {
            foreach ($rows as $row) {
                $lines[] = [
                    'invoice_number' => preg_replace('/\s+/', '', $row[1]),
                    'amount' => $this->money($row[4]),
                    'date' => $this->isoDate($row[2]),
                    'description' => $this->cleanLabel($row[3]),
                ];
            }
        }

        $total = $this->money($this->first('/(?:Invoice Total|Remittance amount|REMITTANCE AMOUNT)\s*:?\s*\n?\s*([\d,]+\.\d{2})/i', $header.$text));
        $payer = $this->first('/instructed by\s+(.+?)\./i', $text)
            ?? $this->first('/REMITTER NAME\s*\n\s*(.+)/i', $text);

        return new ParsedAdvice(
            bankCode: $this->bankCode(),
            extractor: 'ocr',
            payerName: $this->cleanLabel($payer),
            valueDate: $this->isoDate(
                $this->first('/VALUE DATE\s*:?\s*(\d{2}-[A-Za-z]{3}-\d{4})/i', $text)
                ?? $this->first('/Date\s*:\s*(\d{2}\/\d{2}\/\d{4})/i', $header)
            ),
            currency: 'SGD',
            totalAmount: $total,
            bankReference: $this->first('/(?:SCB Ref|Bank Reference)\s*:\s*(\S+)/i', $text),
            lines: $lines,
        );
    }

    /**
     * The first money token in a description block: standalone digits (with
     * optional decimals), not part of a reference like "62-03-08440" and not
     * a bare year that leaked from a wrapped description.
     */
    private function firstAmount(string $block): ?float
    {
        if (preg_match_all('/(?<![A-Za-z0-9\-])(\d{1,3}(?:,\d{3})*(?:\.\d{1,2})?)(?![A-Za-z0-9\-])/', $block, $tokens) === false) {
            return null;
        }

        foreach ($tokens[1] as $token) {
            if (preg_match('/^(19|20)\d{2}$/', $token) === 1) {
                continue;
            }

            $amount = $this->money($token);

            if ($amount !== null && $amount > 0) {
                return $amount;
            }
        }

        return null;
    }
}
