<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Illuminate\Support\Facades\DB;
use Modules\TemplateBuilder\Exceptions\TemplatePlatformException;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\SurveyResponse;
use Modules\TemplateBuilder\Platform\InteractionType;
use Modules\TemplateBuilder\Platform\TemplateErrorCode;

/**
 * ADR-004 §12 / §13 — Survey & Assessment runtime.
 *
 * Evaluates survey answers against the template's scoring + branching
 * definition, enforces response limits & identity policy, and persists an
 * immutable response record keyed by the scoring version (ADR-004 §13:
 * "Template/scoring version, answers, identity policy, calculated result").
 */
final class SurveyRuntimeService
{
    /**
     * @param  array<string,mixed>  $respondentContext  respondent_user_id, respondent_email, respondent_token_id, identity_policy
     */
    public function respond(DocumentTemplate $template, array $answers, array $respondentContext = []): SurveyResponse
    {
        $this->guardSurveyTemplate($template);

        $definition = $this->extractDefinition($template);

        $this->guardResponseLimits($template, $definition, $respondentContext);
        $this->guardConsent($definition, $respondentContext);

        $branchAnswers = $this->applyBranching($definition, $answers);
        $result = $this->score($definition, $branchAnswers);

        $idempotencyKey = $respondentContext['idempotency_key']
            ?? 'survey:'.$template->id.':'.hash('sha256', json_encode($respondentContext + $branchAnswers) ?: '');

        $existing = SurveyResponse::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($template, $branchAnswers, $respondentContext, $result, $idempotencyKey, $definition) {
            return SurveyResponse::create([
                'template_id' => $template->id,
                'template_version' => $template->published_version,
                'scoring_version' => (string) ($definition['scoring_version'] ?? 1),
                'answers' => $branchAnswers,
                'identity_policy' => $respondentContext['identity_policy'] ?? SurveyResponse::IDENTITY_ANONYMOUS,
                'respondent_user_id' => $respondentContext['respondent_user_id'] ?? null,
                'respondent_email' => $respondentContext['respondent_email'] ?? null,
                'respondent_token_id' => $respondentContext['respondent_token_id'] ?? null,
                'calculated_result' => $result,
                'status' => SurveyResponse::STATUS_SUBMITTED,
                'idempotency_key' => $idempotencyKey,
                'responded_at' => now(),
            ]);
        });
    }

    /**
     * Branching: prune answers to questions that should be answered given
     * prior answers (ADR-004 §12 Surveys: "branching").
     *
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $answers
     * @return array<string,mixed>
     */
    public function applyBranching(array $definition, array $answers): array
    {
        $questions = $definition['questions'] ?? [];
        $branched = [];
        $path = [];

        foreach ($questions as $question) {
            $key = $question['key'] ?? null;
            if ($key === null) {
                continue;
            }

            // A question may declare it only applies when a prior answer matches.
            $showWhen = $question['show_when'] ?? null;
            if (is_array($showWhen)) {
                $other = $showWhen['field'] ?? null;
                $equals = $showWhen['equals'] ?? null;
                if ($other !== null && ($answers[$other] ?? null) !== $equals) {
                    continue;
                }
            }

            $path[] = $key;
            if (array_key_exists($key, $answers)) {
                $branched[$key] = $answers[$key];
            }
        }

        return $branched;
    }

    /**
     * Score the branched answers (ADR-004 §12 Surveys: "scoring, thresholds").
     *
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $answers
     * @return array<string,mixed>
     */
    public function score(array $definition, array $answers): array
    {
        $score = 0;
        $bands = [];
        $thresholdsCrossed = [];

        foreach ($definition['questions'] ?? [] as $question) {
            $key = $question['key'] ?? null;
            if ($key === null || ! array_key_exists($key, $answers)) {
                continue;
            }

            // Per-option scoring (e.g. NPS 0-10, Likert 1-5).
            foreach ($question['options'] ?? [] as $option) {
                if (($answers[$key] ?? null) === ($option['value'] ?? null) && isset($option['score'])) {
                    $score += (int) $option['score'];
                }
            }
        }

        // Bands: map score ranges to labels (e.g. NPS detractor/passive/promoter).
        foreach ($definition['bands'] ?? [] as $band) {
            $min = $band['min'] ?? null;
            $max = $band['max'] ?? null;
            if (($min === null || $score >= $min) && ($max === null || $score <= $max)) {
                $bands[] = $band['label'] ?? 'band';
            }
        }

        foreach ($definition['thresholds'] ?? [] as $threshold) {
            $at = $threshold['at'] ?? null;
            if ($at !== null && $score >= $at) {
                $thresholdsCrossed[] = $threshold;
            }
        }

        return [
            'score' => $score,
            'bands' => $bands,
            'thresholds_crossed' => $thresholdsCrossed,
            'answer_count' => count($answers),
        ];
    }

    private function guardSurveyTemplate(DocumentTemplate $template): void
    {
        if ($template->interactionType() !== InteractionType::SurveyAssessment) {
            throw TemplatePlatformException::fromCode(
                TemplateErrorCode::TypeNotRegistered,
                'Template is not a survey/assessment.',
                ['interaction_type' => $template->interactionType()->value],
            );
        }

        if (! $template->isResolvable()) {
            throw TemplatePlatformException::fromCode(TemplateErrorCode::NotPublished, 'Only published surveys accept responses.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function extractDefinition(DocumentTemplate $template): array
    {
        $layout = is_array($template->layout_config) ? $template->layout_config : [];

        return is_array($layout['survey'] ?? null) ? $layout['survey'] : $layout;
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $respondentContext
     */
    private function guardResponseLimits(DocumentTemplate $template, array $definition, array $respondentContext): void
    {
        $maxResponses = $definition['max_responses'] ?? null;
        if ($maxResponses === null) {
            return;
        }

        $count = SurveyResponse::where('template_id', $template->id)
            ->where('status', SurveyResponse::STATUS_SUBMITTED)
            ->count();

        if ($count >= (int) $maxResponses) {
            throw TemplatePlatformException::fromCode(
                TemplateErrorCode::ValidationFailed,
                'Survey response limit reached.',
                ['limit' => $maxResponses],
            );
        }
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $respondentContext
     */
    private function guardConsent(array $definition, array $respondentContext): void
    {
        if (($definition['requires_consent'] ?? false) === true) {
            $consented = $respondentContext['consent'] ?? false;
            if (! $consented) {
                throw TemplatePlatformException::fromCode(
                    TemplateErrorCode::ValidationFailed,
                    'This survey requires explicit consent.',
                );
            }
        }
    }
}
