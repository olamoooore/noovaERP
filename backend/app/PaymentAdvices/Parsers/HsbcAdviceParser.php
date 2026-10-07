<?php

declare(strict_types=1);

namespace App\PaymentAdvices\Parsers;

use App\PaymentAdvices\AbstractAdviceParser;
use App\PaymentAdvices\ParsedAdvice;

/**
 * #1001: HSBC ACH payment advice (e.g. Parkway Hospital) — table
 * `Document Number | Amount | Date | Description` where Document Number is
 * our invoice number. The PDF has real text, so no OCR is involved.
 */
final class HsbcAdviceParser extends AbstractAdviceParser
{
    public function bankCode(): string
    {
        return 'hsbc';
    }

    public function parse(string $text): ParsedAdvice
    {
        $lines = [];

        if (preg_match_all(
            '/(\d{4}\/\d{2}\/\d{3})\s+(?:SGD|MYR|USD)?\s*([\d,]+\.\d{2})\s*(\d{2}\/\d{2}\/\d{4})/i',
            $text,
            $matches,
            PREG_SET_ORDER,
        ) !== false) {
            foreach ($matches as $match) {
                $lines[] = [
                    'invoice_number' => $match[1],
                    'amount' => $this->money($match[2]),
                    'date' => $this->isoDate($match[3]),
                    'description' => null,
                ];
            }
        }

        $total = $this->money($this->first('/Remittance amount:\s*(?:SGD|MYR|USD)?\s*([\d,]+\.\d{2})/i', $text));

        return new ParsedAdvice(
            bankCode: $this->bankCode(),
            extractor: 'pdf-text',
            payerName: $this->cleanLabel($this->first("/Remitter's name:\s*\n?\s*(.+)/i", $text)),
            valueDate: $this->isoDate($this->first('/Value date:\s*\n?\s*(\d{1,2}\s+\w{3}\s+\d{4})/i', $text)),
            currency: 'SGD',
            totalAmount: $total,
            bankReference: $this->first('/Advice reference no:\s*\n?\s*(\S+)/i', $text)
                ?? $this->first('/Instruction reference:\s*(\S+)/i', $text),
            lines: $lines,
        );
    }
}
