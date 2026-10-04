<?php

namespace App\Services\Reconciliation;

use RuntimeException;

/**
 * Issue #881 (SA8): the tenant has no active `accounts_receivable`
 * account_mappings entry, so the AR control account cannot be resolved.
 *
 * Reconciliation refuses to guess an account by code/name (previously it fell
 * back to COA code 1100, which is wrong for tenants on the MY CoA — 124000).
 * A missing mapping is a setup error the caller must surface.
 */
class ARControlAccountNotConfiguredException extends RuntimeException {}
