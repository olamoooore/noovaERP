<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\TemplateBuilder\Exceptions\TemplatePlatformException;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\FormSubmission;
use Modules\TemplateBuilder\Services\FormRuntimeService;

/**
 * ADR-004 §8.2 / §12 — Data-Entry Form runtime API.
 */
class FormController extends Controller
{
    public function __construct(private readonly FormRuntimeService $forms) {}

    /**
     * Submit a form payload (authenticated tenant user OR on behalf of a
     * public-token respondent).
     */
    public function submit(Request $request, string $templateId): JsonResponse
    {
        $template = $this->findTemplate($templateId);

        $data = $request->validate([
            'answers' => 'required|array',
            'attachments' => 'nullable|array',
            'consent' => 'nullable|array',
            'submitter_email' => 'nullable|email',
            'submitter_token_id' => 'nullable|string',
            'idempotency_key' => 'nullable|string|max:120',
        ]);

        try {
            $submission = $this->forms->submit($template, $data['answers'], array_merge($data, [
                'submitter_user_id' => $request->user()?->getAuthIdentifier(),
            ]));
        } catch (TemplatePlatformException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                ...$e->responsePayload(),
            ], $e->getStatusCode());
        }

        return response()->json(['data' => $submission], 201);
    }

    public function index(Request $request, string $templateId): JsonResponse
    {
        $submissions = FormSubmission::where('template_id', $templateId)
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate((int) ($request->input('per_page', 20)));

        return response()->json(['data' => $submissions]);
    }

    public function show(string $templateId, string $submissionId): JsonResponse
    {
        $submission = FormSubmission::where('template_id', $templateId)->find($submissionId);
        if (! $submission) {
            return response()->json(['message' => 'Submission not found.'], 404);
        }

        return response()->json(['data' => $submission]);
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
