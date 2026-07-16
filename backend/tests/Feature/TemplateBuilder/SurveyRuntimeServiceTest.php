<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use Illuminate\Support\Str;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Platform\InteractionType;
use Modules\TemplateBuilder\Services\SurveyRuntimeService;
use Tests\TenantTestCase;

class SurveyRuntimeServiceTest extends TenantTestCase
{
    private SurveyRuntimeService $surveys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext(Str::uuid()->toString());
        $this->surveys = app(SurveyRuntimeService::class);
    }

    public function test_scoring_sums_option_scores_and_assigns_band(): void
    {
        $template = $this->publishedSurvey([
            'questions' => [
                ['key' => 'q1', 'options' => [['value' => 'yes', 'score' => 5], ['value' => 'no', 'score' => 0]]],
                ['key' => 'q2', 'options' => [['value' => 'yes', 'score' => 5], ['value' => 'no', 'score' => 0]]],
            ],
            'bands' => [
                ['min' => 0, 'max' => 5, 'label' => 'low'],
                ['min' => 6, 'max' => null, 'label' => 'high'],
            ],
        ]);

        $response = $this->surveys->respond($template, ['q1' => 'yes', 'q2' => 'yes'], [
            'respondent_email' => 'r1@example.com',
        ]);

        $this->assertSame(10, $response->calculated_result['score']);
        $this->assertContains('high', $response->calculated_result['bands']);
    }

    public function test_branching_skips_questions_whose_show_when_does_not_match(): void
    {
        $definition = [
            'questions' => [
                ['key' => 'liked', 'options' => [['value' => 'yes', 'score' => 1], ['value' => 'no', 'score' => 0]]],
                ['key' => 'why', 'show_when' => ['field' => 'liked', 'equals' => 'yes']],
            ],
        ];

        $branched = $this->surveys->applyBranching($definition, ['liked' => 'no', 'why' => 'irrelevant']);

        $this->assertArrayNotHasKey('why', $branched);
        $this->assertArrayHasKey('liked', $branched);
    }

    public function test_thresholds_crossed_when_score_meets_threshold(): void
    {
        $template = $this->publishedSurvey([
            'questions' => [
                ['key' => 'q1', 'options' => [['value' => 'high', 'score' => 10]]],
            ],
            'thresholds' => [
                ['at' => 8, 'action' => 'escalate'],
            ],
        ]);

        $response = $this->surveys->respond($template, ['q1' => 'high'], ['respondent_email' => 'r2@example.com']);

        $this->assertCount(1, $response->calculated_result['thresholds_crossed']);
    }

    public function test_idempotency_replay_returns_same_response(): void
    {
        $template = $this->publishedSurvey([
            'questions' => [['key' => 'q1', 'options' => [['value' => 'a', 'score' => 1]]]],
        ]);

        $ctx = ['respondent_email' => 'dup@example.com', 'idempotency_key' => 'sur-1'];

        $first = $this->surveys->respond($template, ['q1' => 'a'], $ctx);
        $second = $this->surveys->respond($template, ['q1' => 'a'], $ctx);

        $this->assertSame($first->id, $second->id);
    }

    public function test_response_limit_blocks_further_responses(): void
    {
        $template = $this->publishedSurvey([
            'questions' => [['key' => 'q1', 'options' => [['value' => 'a', 'score' => 1]]]],
            'max_responses' => 1,
        ]);

        $this->surveys->respond($template, ['q1' => 'a'], ['respondent_email' => 'a@example.com']);

        $this->expectException(\Modules\TemplateBuilder\Exceptions\TemplatePlatformException::class);

        $this->surveys->respond($template->fresh(), ['q1' => 'a'], ['respondent_email' => 'b@example.com']);
    }

    private function publishedSurvey(array $definition): DocumentTemplate
    {
        return DocumentTemplate::create([
            'document_type' => 'satisfaction',
            'name' => 'Survey',
            'interaction_type' => InteractionType::SurveyAssessment->value,
            'mode' => DocumentTemplate::MODE_BLOCK,
            'layout_config' => ['survey' => $definition],
            'status' => DocumentTemplate::STATUS_PUBLISHED,
            'published_version' => 1,
        ]);
    }
}
