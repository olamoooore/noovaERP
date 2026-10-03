<?php

namespace App\Services\BankStatements;

/**
 * Issue #834 (BK2): an amount the parser cannot read is a row error that
 * NAMES THE FIELD — never a silent 0.
 */
class UnparsableAmountException extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $field,
        public readonly string $raw,
    ) {
        parent::__construct("Cannot parse the {$field} amount \"{$raw}\".");
    }
}
