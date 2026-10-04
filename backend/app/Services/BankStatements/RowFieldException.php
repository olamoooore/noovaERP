<?php

namespace App\Services\BankStatements;

/**
 * Issue #840 (BK8): a row that cannot be normalized is reported against the
 * exact field that failed (date / amount / description) with the raw value
 * preserved as a safe preview — never a generic, field-less row error.
 */
class RowFieldException extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $field,
        public readonly string $errorCode,
        public readonly string $raw,
        string $message,
    ) {
        parent::__construct($message);
    }
}
