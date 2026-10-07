<?php

declare(strict_types=1);

namespace App\PaymentAdvices\Parsers;

use App\PaymentAdvices\AbstractAdviceParser;
use App\PaymentAdvices\ParsedAdvice;

/**
 * #1001: DBS transaction advice (email screenshot) — a single "Invoice
 * Details" line: `<batch date> <invoice no> <amount> <payer ref>`.
 */
final class DbsAdviceParser extends AbstractAdviceParser
{
    public function bankCode(): string
    {
        return 'dbs';
    }

    public function parse(string $text): ParsedAdvice
    {
        $lines = [];

        if (preg_match(
            '/Invoice Details:\s*\n?\s*(\d{8})\s+(\d{4}\/\d{2}\/\d{3})\s+([\d,]+\.\d{2})\s+(\S+)/i',
            $text,
            $match,
        ) === 1) {
            $lines[] = [
                'invoice_number' => $match[2],
                'amount' => $this->money($match[3]),
                'date' => $this->isoDate(substr($match[1], 0, 4).'-'.substr($match[1], 4, 2).'-'.substr($match[1], 6, 2)),
                'description' => $match[4],
            ];
        }

        $payer = $this->first('/On behalf of\s+(.+?)\s+DBS has remitted/is', $text)
            ?? $this->first('/On behalf of\s+(.+?)\s*,?\s*(?:DBS|has remitted)/is', $text);

        return new ParsedAdvice(
            bankCode: $this->bankCode(),
            extractor: 'ocr',
            payerName: $this->cleanLabel($payer),
            valueDate: $this->isoDate($this->first('/on\s+(\d{1,2}\s+\w{3}\s+\d{4})\./i', $text)),
            currency: 'SGD',
            totalAmount: $this->money($this->first('/payment of SGD\s*([\d,]+(?:\.\d{2})?)/i', $text)),
            bankReference: $this->first('/Batch Reference:\s*\n?\s*(\S+)/i', $text),
            lines: $lines,
        );
    }
}
