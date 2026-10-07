<?php

declare(strict_types=1);

namespace App\PaymentAdvices;

/**
 * #1001: the extracted content of one payment advice.
 */
final class ParsedAdvice
{
    /**
     * @param  array<int, array{invoice_number: ?string, amount: float, date: ?string, description: ?string}>  $lines
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly string $bankCode,
        public readonly string $extractor,
        public readonly ?string $payerName = null,
        public readonly ?string $valueDate = null,
        public readonly ?string $currency = null,
        public readonly ?float $totalAmount = null,
        public readonly ?string $bankReference = null,
        public readonly array $lines = [],
        public readonly array $warnings = [],
    ) {}
}
