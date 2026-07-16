<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Platform;

use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\DocumentTemplateAssignment;

/**
 * ADR-004 §11 — deterministic assignment resolution outcome.
 *
 * Carries the resolved template (when any), the outcome enum, and a
 * human-readable resolution explanation that the API must surface.
 */
final class ResolutionResult
{
    /**
     * @param  list<DocumentTemplateAssignment>  $candidates  every assignment considered
     * @param  list<array<string,mixed>>  $explanation  step-by-step resolution trace
     */
    public function __construct(
        public readonly AssignmentOutcome $outcome,
        public readonly ?DocumentTemplate $template = null,
        public readonly ?DocumentTemplateAssignment $assignment = null,
        public readonly array $candidates = [],
        public readonly array $explanation = [],
    ) {}

    public function resolved(): bool
    {
        return $this->template !== null
            && in_array($this->outcome, [AssignmentOutcome::Resolved, AssignmentOutcome::DefaultApplied], true);
    }

    public function explanationText(): string
    {
        return implode(' → ', array_map(
            static fn (array $step) => (string) $step['message'],
            $this->explanation,
        ));
    }
}
