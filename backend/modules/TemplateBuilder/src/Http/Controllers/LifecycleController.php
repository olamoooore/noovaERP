<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\TemplateBuilder\Exceptions\TemplatePlatformException;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\TemplateRegistration;
use Modules\TemplateBuilder\Platform\ResolutionContext;
use Modules\TemplateBuilder\Platform\TemplateChannel;
use Modules\TemplateBuilder\Services\EditorialLifecycleService;
use Modules\TemplateBuilder\Services\EligibilityEngine;

/**
 * ADR-004 §9 / §10 — Editorial lifecycle + runtime eligibility API.
 *
 * Exposes the controlled publication flow (submit → review → approve/reject →
 * publish/supersede → archive → restore) and the 18-step eligibility check.
 */
class LifecycleController extends Controller
{
    public function __construct(
        private readonly EditorialLifecycleService $lifecycle,
        private readonly EligibilityEngine $eligibility,
    ) {}

    public function submitForReview(Request $request, string $id): JsonResponse
    {
        $template = $this->findTemplate($id);

        return response()->json(['data' => $this->lifecycle->submitForReview($template, $request->integer('reviewer_id'))]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reviewer_id' => 'required|integer',
            'change_note' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:2000',
        ]);

        $template = $this->findTemplate($id);

        return response()->json(['data' => $this->lifecycle->approve(
            $template,
            $data['reviewer_id'],
            $data['change_note'] ?? null,
            $data['notes'] ?? null,
        )]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reviewer_id' => 'required|integer',
            'notes' => 'nullable|string|max:2000',
        ]);

        $template = $this->findTemplate($id);

        return response()->json(['data' => $this->lifecycle->reject($template, $data['reviewer_id'], $data['notes'] ?? null)]);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $template = $this->findTemplate($id);

        return response()->json(['data' => $this->lifecycle->publish($template, $request->input('change_note'))]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $template = $this->findTemplate($id);

        return response()->json(['data' => $this->lifecycle->archive($template)]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $template = $this->findTemplate($id);

        return response()->json(['data' => $this->lifecycle->restore($template)]);
    }

    /**
     * Evaluate runtime eligibility for a context (ADR-004 §10).
     */
    public function evaluateEligibility(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_type_code' => 'required|string',
            'interaction_type' => 'nullable|in:BUSINESS_DOCUMENT,DATA_ENTRY_FORM,SURVEY_ASSESSMENT',
            'entity_id' => 'nullable|string',
            'branch_id' => 'nullable|string',
            'language' => 'nullable|string',
            'currency' => 'nullable|string',
            'country' => 'nullable|string',
            'jurisdiction' => 'nullable|string',
            'customer_group' => 'nullable|string',
            'channel' => 'nullable|string',
        ]);

        $context = ResolutionContext::forDocument($data['template_type_code'], $data);
        $registration = TemplateRegistration::active()
            ->where('template_type_code', $data['template_type_code'])
            ->first();

        $channel = isset($data['channel']) ? TemplateChannel::from($data['channel']) : null;

        try {
            $evaluation = $this->eligibility->evaluate($context, $registration, $channel, throwOnFailure: false);
        } catch (TemplatePlatformException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                ...$e->responsePayload(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'data' => [
                'resolved' => $evaluation['result']->resolved(),
                'outcome' => $evaluation['result']->outcome->value,
                'template' => $evaluation['result']->template,
                'explanation' => $evaluation['result']->explanation,
                'checks' => $evaluation['checks'],
            ],
        ]);
    }

    private function findTemplate(string $id): DocumentTemplate
    {
        $template = DocumentTemplate::find($id);
        if (! $template) {
            abort(404, 'Template not found.');
        }

        return $template;
    }
}
