<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Platform;

/**
 * ADR-004 §11 — deterministic assignment outcome.
 */
enum AssignmentOutcome: string
{
    /** Exactly one assignment resolved at the highest priority. */
    case Resolved = 'RESOLVED';

    /** No eligible assignment; system-default fallback applied (policy permitting). */
    case DefaultApplied = 'DEFAULT_APPLIED';

    /** No eligible assignment and fallback denied. */
    case NotAssigned = 'TEMPLATE_NOT_ASSIGNED';

    /** Two or more assignments tied at the top priority. */
    case Ambiguous = 'TEMPLATE_RESOLUTION_AMBIGUOUS';

    /** The only candidate crosses a forbidden boundary (module/type/contract/jurisdiction). */
    case Incompatible = 'INCOMPATIBLE_FALLBACK';
}
