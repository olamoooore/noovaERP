<?php

declare(strict_types=1);

namespace App\PaymentAdvices;

/**
 * #1001: identify the remitting bank's advice layout from its text.
 * Signatures are layout phrases, never account-specific data.
 */
final class AdviceBankDetector
{
    public function detect(string $text): string
    {
        $upper = strtoupper($text);

        if (str_contains($upper, 'INTERBANK GIRO PAYMENT PARTICULARS')) {
            return 'uob_giro';
        }

        if (str_contains($upper, 'TRANSFER TO UOB ACCOUNT')) {
            return 'uob_transfer';
        }

        if (str_contains($upper, 'PAYEE ADVICE') || str_contains($upper, 'STRAIGHT2BANK')) {
            return 'scb';
        }

        if (str_contains($upper, 'TRANSACTION ADVICE') && str_contains($upper, 'DBS')) {
            return 'dbs';
        }

        if (
            str_contains($upper, 'ACH CREDITS')
            || str_contains($upper, 'REMITTANCE AMOUNT')
            || str_contains($upper, 'HK AND SHANGHAI')
            || str_contains($upper, 'HSBC')
        ) {
            return 'hsbc';
        }

        return 'unknown';
    }
}
