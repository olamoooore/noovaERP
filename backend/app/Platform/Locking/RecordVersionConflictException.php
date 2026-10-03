<?php

namespace App\Platform\Locking;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Issue #896 — thrown by RecordVersionGuard when a client supplies a
 * concurrency token (`version`, `updated_at` or `If-Match`) that no longer
 * matches the row it is trying to update.
 *
 * Carries the fresh, current model so the 409 response can include the
 * current state without a second lookup.
 */
class RecordVersionConflictException extends RuntimeException
{
    public function __construct(
        public readonly Model $current,
        public readonly string $resourceType,
        public readonly string $clientToken,
    ) {
        parent::__construct(
            "Stale {$resourceType} update: supplied version [{$clientToken}] does not match the current record."
        );
    }
}
