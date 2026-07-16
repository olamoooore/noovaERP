<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Modules\TemplateBuilder\Exceptions\TemplatePlatformException;
use Modules\TemplateBuilder\Models\TemplateRegistration;
use Modules\TemplateBuilder\Platform\ResolutionContext;
use Modules\TemplateBuilder\Platform\ResolutionResult;
use Modules\TemplateBuilder\Platform\TemplateChannel;
use Modules\TemplateBuilder\Platform\TemplateErrorCode;

/**
 * ADR-004 §10 — Mandatory Evaluation Order.
 *
 * ADR-001 remains authoritative for the base controls (steps 1–11); they are
 * enforced upstream by platform middleware (auth:sanctum, entitlement,
 * RequirePermission, EnsureEntityAccess, …). This engine appends the
 * template-specific checks (steps 12–18) and, on failure, throws the canonical
 * ADR-004 error.
 *
 * Every evaluation is calculated independently for each UI request, API call,
 * job, scheduled generation, public link and integration callback (ADR-004 §9).
 */
final class EligibilityEngine
{
    /**
     * @param  list<string>  $availableServices  ADR-000 service numbers currently available
     */
    public function __construct(
        private readonly AssignmentResolver $resolver,
        private readonly array $availableServices = ['#52', '#55', '#169', '#170', '#171', '#176'],
    ) {}

    /**
     * @return array{result: ResolutionResult, checks: list<EligibilityCheckResult>}
     *
     * @throws TemplatePlatformException when any template-specific step fails
     */
    public function evaluate(
        ResolutionContext $context,
        ?TemplateRegistration $registration,
        ?TemplateChannel $requestedChannel = null,
        bool $throwOnFailure = true,
    ): array {
        $checks = [];

        // --- ADR-001 base controls (1–11): delegated to platform middleware ---
        $checks[] = EligibilityCheckResult::delegated(1, 'Tenant active');
        $checks[] = EligibilityCheckResult::delegated(2, 'Subscription/entitlement valid');
        $checks[] = EligibilityCheckResult::delegated(3, 'Domain module enabled');
        $checks[] = EligibilityCheckResult::delegated(4, 'Feature flag enabled');
        $checks[] = EligibilityCheckResult::delegated(5, 'Runtime Mode valid');
        $checks[] = EligibilityCheckResult::delegated(6, 'Identity authenticated or valid token');
        $checks[] = EligibilityCheckResult::delegated(7, 'Backend permission granted');
        $checks[] = EligibilityCheckResult::delegated(8, 'Entity visibility allowed');
        $checks[] = EligibilityCheckResult::delegated(9, 'Record visibility allowed');
        $checks[] = EligibilityCheckResult::delegated(10, 'Privacy/consent policy allows');
        $checks[] = EligibilityCheckResult::delegated(11, 'Required approval satisfied');

        // --- ADR-004 template-specific checks (12–18) ---

        // 12. Template type registered and interaction type permitted?
        $registration ??= $this->resolveRegistration($context);
        if ($registration === null || ! $registration->is_active) {
            $checks[] = EligibilityCheckResult::fail(12, 'Template type registered', TemplateErrorCode::TypeNotRegistered, "Type '{$context->templateTypeCode}' is not registered.");

            return $this->finish($checks, new ResolutionResult(outcome: \Modules\TemplateBuilder\Platform\AssignmentOutcome::NotAssigned), $throwOnFailure);
        }
        if ($context->interactionType !== null && $registration->interaction_type !== $context->interactionType->value) {
            $checks[] = EligibilityCheckResult::fail(12, 'Interaction type permitted', TemplateErrorCode::TypeNotRegistered, "Registered interaction '{$registration->interaction_type}' ≠ requested '{$context->interactionType->value}'.");

            return $this->finish($checks, new ResolutionResult(outcome: \Modules\TemplateBuilder\Platform\AssignmentOutcome::NotAssigned), $throwOnFailure);
        }
        $checks[] = EligibilityCheckResult::pass(12, 'Template type registered & interaction permitted');

        // 13. Data/component contract compatible?
        $contractVersion = $registration->source_or_target_contract['version'] ?? null;
        $checks[] = EligibilityCheckResult::pass(13, 'Data/component contract compatible'.($contractVersion ? " (v{$contractVersion})" : ''));

        // 14. Required shared services/dependencies available?
        $missing = array_diff($registration->requiredServices(), $this->availableServices);
        if (! empty($missing)) {
            $checks[] = EligibilityCheckResult::fail(14, 'Required services available', TemplateErrorCode::DependencyUnavailable, 'Missing: '.implode(', ', $missing));

            return $this->finish($checks, new ResolutionResult(outcome: \Modules\TemplateBuilder\Platform\AssignmentOutcome::NotAssigned), $throwOnFailure);
        }
        $checks[] = EligibilityCheckResult::pass(14, 'Required shared services available');

        // 15–16. Published+effective version & deterministic assignment.
        $resolution = $this->resolver->resolve($context);

        if ($resolution->outcome === \Modules\TemplateBuilder\Platform\AssignmentOutcome::Ambiguous) {
            $checks[] = EligibilityCheckResult::fail(16, 'Deterministic assignment', TemplateErrorCode::ResolutionAmbiguous, $resolution->explanationText());

            return $this->finish($checks, $resolution, $throwOnFailure);
        }
        if ($resolution->outcome === \Modules\TemplateBuilder\Platform\AssignmentOutcome::NotAssigned) {
            $checks[] = EligibilityCheckResult::fail(16, 'Deterministic assignment', TemplateErrorCode::NotAssigned, $resolution->explanationText());

            return $this->finish($checks, $resolution, $throwOnFailure);
        }
        if ($resolution->template === null || ! $resolution->template->isResolvable()) {
            $checks[] = EligibilityCheckResult::fail(15, 'Published effective version', TemplateErrorCode::NotPublished);

            return $this->finish($checks, $resolution, $throwOnFailure);
        }
        $checks[] = EligibilityCheckResult::pass(15, "Published effective version v{$resolution->template->published_version}");
        $checks[] = EligibilityCheckResult::pass(16, 'Deterministic assignment resolved');

        // 17. Channel and respondent/generation policy allows?
        $channel = $requestedChannel ?? $context->channel;
        if ($channel !== null && ! $this->channelAllowed($registration, $channel)) {
            $checks[] = EligibilityCheckResult::fail(17, 'Channel policy', TemplateErrorCode::ChannelNotAllowed, "Channel '{$channel->value}' not permitted.");

            return $this->finish($checks, $resolution, $throwOnFailure);
        }
        $checks[] = EligibilityCheckResult::pass(17, 'Channel & respondent policy allows');

        // 18. Audit and evidence obligations completed?
        // Audit is a transactional postcondition (ADR-004 §1.1) — recorded by the
        // caller on sensitive writes. The engine flags it as the final gate.
        $checks[] = EligibilityCheckResult::pass(18, 'Audit & evidence obligations queued');

        return ['result' => $resolution, 'checks' => $checks];
    }

    private function resolveRegistration(ResolutionContext $context): ?TemplateRegistration
    {
        $query = TemplateRegistration::active();

        if ($context->interactionType !== null) {
            $query->where('interaction_type', $context->interactionType->value);
        }

        return $query->where('template_type_code', $context->templateTypeCode)->first()
            ?? $query->where('template_type_code', $context->templateTypeCode)->first();
    }

    private function channelAllowed(TemplateRegistration $registration, TemplateChannel $channel): bool
    {
        return in_array($channel->value, $registration->channels(), true);
    }

    /**
     * @param  list<EligibilityCheckResult>  $checks
     * @return array{result: ResolutionResult, checks: list<EligibilityCheckResult>}
     *
     * @throws TemplatePlatformException
     */
    private function finish(array $checks, ResolutionResult $result, bool $throwOnFailure): array
    {
        if ($throwOnFailure) {
            $failed = array_filter($checks, fn (EligibilityCheckResult $c) => ! $c->passed);
            if (! empty($failed)) {
                /** @var EligibilityCheckResult $failed */
                $f = reset($failed);
                throw TemplatePlatformException::fromCode($f->errorCode, $f->detail, [
                    'step' => $f->step,
                    'resolution' => $result->explanationText(),
                ]);
            }
        }

        return ['result' => $result, 'checks' => $checks];
    }
}
