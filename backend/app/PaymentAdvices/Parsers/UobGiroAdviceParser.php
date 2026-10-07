<?php

declare(strict_types=1);

namespace App\PaymentAdvices\Parsers;

use App\PaymentAdvices\AbstractAdviceParser;
use App\PaymentAdvices\ParsedAdvice;

/**
 * #1001: UOB Interbank GIRO particulars — BILL_DATE / BILL_REF / AMOUNT /
 * CURRENCY table, BILL_REF being our invoice number.
 */
final class UobGiroAdviceParser extends AbstractAdviceParser
{
    public function bankCode(): string
    {
        return 'uob_giro';
    }

    public function parse(string $text): ParsedAdvice
    {
        $lines = [];

        if (preg_match(
            '/BILL_DATE\s+BILL_REF\s+AMOUNT\s+CURRENCY(.*?)(?:IMPORTANT|$)/is',
            $text,
            $table,
        ) === 1) {
            if (preg_match_all(
                '/(\d{2}\/\d{2}\/\d{4})\s+(\d{4}\/\d{2}\/\d{3})\s+([\d,]+(?:\.\d{2})?)\s+([A-Z]{3})/',
                $table[1],
                $rows,
                PREG_SET_ORDER,
            ) !== false) {
                foreach ($rows as $row) {
                    $lines[] = [
                        'invoice_number' => $row[2],
                        'amount' => $this->money($row[3]),
                        'date' => $this->isoDate($row[1]),
                        'description' => null,
                    ];
                }
            }
        }

        // OCR commonly prints the currency as SCD/SGD before the amount.
        $total = $this->money($this->first('/S(?:GD|CD)\s*([\d,]+(?:\.\d{2})?)/i', $text));

        return new ParsedAdvice(
            bankCode: $this->bankCode(),
            extractor: 'ocr',
            payerName: $this->cleanLabel($this->first("/Payer'?s Name\s*:\s*(.+)/i", $text)),
            valueDate: $this->isoDate($this->first('/Date\s*\n?\s*(\d{2}\/\d{2}\/\d{4})/', $text)),
            currency: 'SGD',
            totalAmount: $total,
            bankReference: $this->first('/Reference No\.?\s*:?\s*(\S+)/i', $text),
            lines: $lines,
        );
    }
}
