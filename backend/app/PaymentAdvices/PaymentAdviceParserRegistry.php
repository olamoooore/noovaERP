<?php

declare(strict_types=1);

namespace App\PaymentAdvices;

use App\PaymentAdvices\Parsers\DbsAdviceParser;
use App\PaymentAdvices\Parsers\HsbcAdviceParser;
use App\PaymentAdvices\Parsers\ScbAdviceParser;
use App\PaymentAdvices\Parsers\UobGiroAdviceParser;
use App\PaymentAdvices\Parsers\UobTransferNoticeParser;

/**
 * #1001: per-bank parser registry. Unknown layouts still return a ParsedAdvice
 * (no lines, no invented values) so the advice is retained for manual work.
 */
final class PaymentAdviceParserRegistry
{
    public function __construct(private readonly AdviceBankDetector $detector) {}

    public function parse(string $text): ParsedAdvice
    {
        $bank = $this->detector->detect($text);

        $parser = match ($bank) {
            'hsbc' => new HsbcAdviceParser,
            'uob_giro' => new UobGiroAdviceParser,
            'uob_transfer' => new UobTransferNoticeParser,
            'dbs' => new DbsAdviceParser,
            'scb' => new ScbAdviceParser,
            default => null,
        };

        if ($parser === null) {
            return new ParsedAdvice(
                bankCode: 'unknown',
                extractor: 'unknown',
                warnings: ['unknown_format'],
            );
        }

        return $parser->parse($text);
    }
}
