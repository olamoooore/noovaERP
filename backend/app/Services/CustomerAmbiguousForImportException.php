<?php

namespace App\Services;

/**
 * Issue #431 (CU3): an import row whose customer resolves by name to MORE
 * THAN ONE existing customer must never silently book to the first match —
 * ADR-003A VEN-AT-04 / VEN-NT-07: same-name only → candidate, no auto-merge.
 * The row is flagged for review instead.
 */
class CustomerAmbiguousForImportException extends \DomainException
{
    /**
     * @param  list<array{id: string, code: ?string}>  $matches
     */
    public function __construct(
        public readonly string $customerName,
        public readonly array $matches,
    ) {
        $keys = implode(', ', array_map(
            fn (array $m) => $m['code'] !== null && $m['code'] !== '' ? $m['code'] : $m['id'],
            $matches,
        ));
        parent::__construct(
            'Customer name "'.$customerName.'" matches '.count($matches)
            ." existing customers ({$keys}). Provide customer_id or customer_code to import this row; "
            .'it was flagged as a duplicate candidate for review instead of being merged.'
        );
    }
}
