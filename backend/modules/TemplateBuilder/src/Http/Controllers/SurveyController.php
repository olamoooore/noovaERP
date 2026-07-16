<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\TemplateBuilder\Exceptions\TemplatePlatformException;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\SurveyResponse;
use Modules\TemplateBuilder\Services\SurveyRuntimeService;

/**
 * ADR-004 §12 / §13 — Survey & Assessment runtime API.
 */
class SurveyController extends Controller
{
    public function __construct(private readonly SurveyRuntimeService $surveys) {}

    public function respond(Request $request, string $templateId): JsonResponse
    {
        $template = $this->findTemplate($templateId);

        $data = $request->validate([
            'answers' => 'required|array',
            'identity_policy' => 'nullable|in:anonymous,identified,known_customer',
            'respondent_email' => 'nullable|email',
            'respondent_token_id' => 'nullable|string',
            'consent' => 'nullable|boolean',
            'idempotency_key' => 'nullable|string|max:120',
        ]);

        try {
            $response = $this->surveys->respond($template, $data['answers'], array_merge($data, [
                'respondent_user_id' => $request->user()?->getAuthIdentifier(),
            ]));
        } catch (TemplatePlatformException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                ...$e->responsePayload(),
            ], $e->getStatusCode());
        }

        return response()->json(['data' => $response], 201);
    }

    public function index(Request $request, string $templateId): JsonResponse
    {
        $responses = SurveyResponse::where('template_id', $templateId)
            ->latest()
            ->paginate((int) ($request->input('per_page', 20)));

        return response()->json(['data' => $responses]);
    }

    public function show(string $templateId, string $responseId): JsonResponse
    {
        $response = SurveyResponse::where('template_id', $templateId)->find($responseId);
        if (! $response) {
            return response()->json(['message' => 'Response not found.'], 404);
        }

        return response()->json(['data' => $response]);
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
