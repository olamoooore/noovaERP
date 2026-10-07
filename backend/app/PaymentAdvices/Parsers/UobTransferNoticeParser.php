<?php

declare(strict_types=1);

namespace App\PaymentAdvices\Parsers;

use App\PaymentAdvices\AbstractAdviceParser;
use App\PaymentAdvices\ParsedAdvice;

/**
 * #1001: UOB internet-banking transfer confirmation — amount, value date,
 * payer account and bank reference only, no invoice references. Lines stay
 * empty and the caller matches by customer + amount instead.
 */
final class UobTransferNoticeParser extends AbstractAdviceParser
{
    public function bankCode(): string
    {
        return 'uob_transfer';
    }

    public function parse(string $text): ParsedAdvice
    {
        // The "From" block lists the company name/account name(s) before the
        // account number; OCR order varies, so take the block between the
        // Account Name label and the Account Number label.
        $payer = null;

        if (preg_match('/Account Name\s*\n((?:[^\n]+\n)+?)\s*Account Number/i', $text, $block) === 1) {
            $payer = $this->cleanLabel(str_replace("\n", ' ', trim($block[1])));
        }

        $payer ??= $this->cleanLabel($this->first('/Company Name\s*\n\s*([^\n]+)/i', $text));

        $total = $this->money($this->first('/Transaction Amount\s*\n?\s*(?:\d{2}\/\d{2}\/\d{4}\s+[A-Z]{3}\s+)?([\d,]+(?:\.\d{2})?)/i', $text))
            ?? $this->money($this->first('/SGD\s*\n\s*([\d,]+(?:\.\d{2})?)/', $text));

        return new ParsedAdvice(
            bankCode: $this->bankCode(),
            extractor: 'ocr',
            payerName: $this->cleanLabel($payer),
            valueDate: $this->isoDate($this->first('/Value Date\s*\n?\s*(\d{2}\/\d{2}\/\d{4})/', $text)),
            currency: 'SGD',
            totalAmount: $total,
            bankReference: $this->first('/\b(FT\d{10,})\b/', $text),
            lines: [],
            warnings: ['no_invoice_references'],
        );
    }
}
