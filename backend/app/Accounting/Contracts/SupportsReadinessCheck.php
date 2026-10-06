<?php

namespace App\Accounting\Contracts;

use App\Models\Invoice;

/**
 * #671 (EINV-UX2): a country pack that can dry-run its own submission rules
 * against an invoice and report each gap with the record that fixes it, so
 * the problems are visible before posting instead of after an Invalid
 * response. The core asks via instanceof (same pattern as #699 EC2).
 *
 * Each issue:
 *  - code:     stable machine key (e.g. buyer_tin_missing)
 *  - severity: 'blocking' (the pack refuses to submit) or 'warning' (the
 *              pack would submit, but with a default instead of real data)
 *  - message:  plain-language description
 *  - fix:      null, or ['type' => customer|product|legal_entity|einvoice_settings|invoice,
 *              'id' => ?int, 'fields' => list<string>, ...extra keys the UI needs]
 */
interface SupportsReadinessCheck
{
    /**
     * @param  array<string, mixed>  $context  The tenant/entity provider context
     * @return list<array{code: string, severity: string, message: string, fix: array<string, mixed>|null}>
     */
    public function readinessIssues(Invoice $invoice, array $context): array;
}
