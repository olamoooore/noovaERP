<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\DocumentTemplateAssignment;
use Modules\TemplateBuilder\Platform\AssignmentOutcome;
use Modules\TemplateBuilder\Platform\ResolutionContext;
use Modules\TemplateBuilder\Platform\ResolutionResult;

/**
 * ADR-004 section 11 - Deterministic Template Assignment Resolver.
 *
 * Replaces the legacy "highest priority first()" heuristic with a contract
 * that:
 *  - matches a context against every populated scope dimension
 *  - honours effective periods
 *  - only considers PUBLISHED templates
 *  - rejects equal-priority ties as TEMPLATE_RESOLUTION_AMBIGUOUS
 *  - returns TEMPLATE_NOT_ASSIGNED when no match and fallback is disallowed
 *  - applies the system default only when policy permits
 *  - always returns a structured resolution explanation.
 */
final class AssignmentResolver
{
    /**
     * Allow the system-default fallback when nothing matches?
     * ADR-004 section 11: "Use compatible approved system default only if policy permits".
     */
    public function __construct(
        private readonly bool $allowDefaultFallback = true,
    ) {}

    public function resolve(ResolutionContext $context): ResolutionResult
    {
        $explanation = [];
        $explanation[] = ['step' => 'start', 'message' => 'Resolving template for type '.$context->templateTypeCode.'.'];

        $candidates = $this->matchingAssignments($context, $explanation);

        if ($candidates->isEmpty()) {
            return $this->handleNoMatch($context, $explanation);
        }

        $topPriority = $candidates->first()->priority;
        $winners = $candidates->filter(fn (DocumentTemplateAssignment $a) => $a->priority === $topPriority)->values();

        if ($winners->count() > 1) {
            $explanation[] = [
                'step' => 'tie',
                'message' => $winners->count().' assignments tie at priority '.$topPriority.'.',
                'winners' => $winners->map(fn (DocumentTemplateAssignment $a) => $a->template_id)->all(),
            ];

            return new ResolutionResult(
                outcome: AssignmentOutcome::Ambiguous,
                candidates: $candidates->all(),
                explanation: $explanation,
            );
        }

        /** @var DocumentTemplateAssignment $winner */
        $winner = $winners->first();
        $template = $winner->template;
        $templateName = $template?->name ?? '(missing)';
        $templateVersion = $template?->published_version ?? 0;
        $templateId = $template?->id;

        $explanation[] = [
            'step' => 'resolved',
            'message' => 'Resolved template '.$templateName.' (v'.$templateVersion.').',
            'template_id' => $templateId,
            'assignment_id' => $winner->id,
            'priority' => $winner->priority,
        ];

        return new ResolutionResult(
            outcome: AssignmentOutcome::Resolved,
            template: $template,
            assignment: $winner,
            candidates: $candidates->all(),
            explanation: $explanation,
        );
    }

    /**
     * @param  list<array<string,mixed>>  $explanation
     * @return Collection<int, DocumentTemplateAssignment>
     */
    private function matchingAssignments(ResolutionContext $context, array &$explanation): Collection
    {
        $query = DocumentTemplateAssignment::query()
            ->active()
            ->with('template')
            ->where(function ($q) use ($context) {
                // Match by document_type (legacy) OR template_type_code (ADR-004).
                $q->where('document_type', $context->templateTypeCode)
                    ->orWhere('template_type_code', $context->templateTypeCode);
            });

        if ($context->interactionType !== null) {
            $query->where(function ($q) use ($context) {
                $q->whereNull('interaction_type')
                    ->orWhere('interaction_type', $context->interactionType->value);
            });
        }

        /** @var list<DocumentTemplateAssignment> $rows */
        $rows = $query->get();

        $matching = collect($rows)
            ->filter(fn (DocumentTemplateAssignment $a) => $this->matchesContext($a, $context))
            ->filter(fn (DocumentTemplateAssignment $a) => $this->isEffective($a, $context))
            ->filter(function (DocumentTemplateAssignment $a) {
                // Boundary rule (ADR-004 §11): only resolvable templates are
                // eligible — published, or a draft carrying a published
                // version (edited published template). Archived never is.
                return $a->template && $a->template->hasRenderableDefinition();
            })
            ->sortByDesc('priority')
            ->sortBy('tiebreaker')
            ->values();

        $explanation[] = [
            'step' => 'filter',
            'message' => $matching->count().' of '.count($rows).' active assignment(s) match context + effectiveness + published.',
        ];

        return $matching;
    }

    private function matchesContext(DocumentTemplateAssignment $assignment, ResolutionContext $context): bool
    {
        // Multi-dimension matching (ADR-004 section 11). Every populated dimension must match.
        // Legacy single-dimension rows use scope_type/scope_value.
        if ($assignment->scope_type && $assignment->scope_type !== 'tenant') {
            $legacyValue = $this->legacyContextValue($assignment->scope_type, $context);
            if ($assignment->scope_value !== null && $assignment->scope_value !== '' && (string) $legacyValue !== $assignment->scope_value) {
                return false;
            }
        }

        $dimensions = [
            'entity_id' => $context->entityId,
            'branch_id' => $context->branchId,
            'language' => $context->language,
            'currency' => $context->currency,
            'country' => $context->country,
            'jurisdiction' => $context->jurisdiction,
            'customer_group' => $context->customerGroup,
            'channel' => $context->channel?->value,
        ];

        foreach ($dimensions as $column => $contextValue) {
            $assignmentValue = $assignment->getAttribute($column);
            if ($assignmentValue !== null && $assignmentValue !== '' && (string) $contextValue !== (string) $assignmentValue) {
                return false;
            }
        }

        return true;
    }

    private function legacyContextValue(string $scopeType, ResolutionContext $context): mixed
    {
        return match ($scopeType) {
            'branch', 'warehouse' => $context->branchId,
            'language' => $context->language,
            'currency' => $context->currency,
            'customer_group' => $context->customerGroup,
            default => $context->entityId,
        };
    }

    private function isEffective(DocumentTemplateAssignment $assignment, ResolutionContext $context): bool
    {
        $at = $context->at ?? new \DateTimeImmutable;
        $check = Carbon::instance($at);

        if ($assignment->effective_from && $check->lt(Carbon::parse($assignment->effective_from))) {
            return false;
        }
        if ($assignment->effective_to && $check->gt(Carbon::parse($assignment->effective_to))) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<array<string,mixed>>  $explanation
     */
    private function handleNoMatch(ResolutionContext $context, array $explanation): ResolutionResult
    {
        if (! $this->allowDefaultFallback) {
            $explanation[] = ['step' => 'no_match', 'message' => 'No match; default fallback disabled by policy.'];

            return new ResolutionResult(
                outcome: AssignmentOutcome::NotAssigned,
                explanation: $explanation,
            );
        }

        $default = DocumentTemplate::query()
            ->resolvable()
            ->where(function ($q) use ($context) {
                $q->where('document_type', $context->templateTypeCode)
                    ->orWhereHas('registration', fn ($rq) => $rq->where('template_type_code', $context->templateTypeCode));
            })
            ->where('is_system_default', true)
            ->latest()
            ->first();

        if ($default) {
            $explanation[] = ['step' => 'default', 'message' => 'No assignment matched; applied system default '.$default->name.'.'];

            return new ResolutionResult(
                outcome: AssignmentOutcome::DefaultApplied,
                template: $default,
                explanation: $explanation,
            );
        }

        $explanation[] = ['step' => 'no_match', 'message' => 'No match and no system default available.'];

        return new ResolutionResult(
            outcome: AssignmentOutcome::NotAssigned,
            explanation: $explanation,
        );
    }
}
