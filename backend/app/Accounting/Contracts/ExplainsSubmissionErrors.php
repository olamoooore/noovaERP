<?php

namespace App\Accounting\Contracts;

/**
 * #673 (EINV-UX4): a country pack that can translate the errors its own
 * e-invoicing integration returns into plain language, naming the record
 * and field to fix. The core asks via instanceof (same pattern as #671
 * SupportsReadinessCheck); without it the raw message is shown.
 *
 * A pack only explains codes and messages its integration actually
 * produces. Anything it does not recognise comes back with the raw message
 * as `message` and no fix — never a guessed explanation.
 *
 * Each explained error:
 *  - code:        the error code as received (null when there was none)
 *  - issue_code:  the pack's readiness issue code when the message is one
 *                 of its own validation rules (#671), else null
 *  - message:     plain-language description
 *  - raw_message: the message as received
 *  - fix:         null, or the same fix target shape as SupportsReadinessCheck
 */
interface ExplainsSubmissionErrors
{
    /**
     * @param  object|null  $source  The source document (Invoice, CustomerCreditNote, Payment)
     * @param  array<string, mixed>  $context  The tenant/entity provider context
     * @return list<array{code: ?string, issue_code: ?string, message: string, raw_message: string, fix: array<string, mixed>|null}>
     */
    public function explainSubmissionError(?string $code, string $message, ?object $source, array $context): array;
}
