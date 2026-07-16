<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\TemplateBuilder\Exceptions\TemplatePlatformException;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Platform\InteractionType;
use Modules\TemplateBuilder\Services\FormRuntimeService;
use Modules\TemplateBuilder\Services\PublicTokenService;
use Modules\TemplateBuilder\Services\SurveyRuntimeService;

/**
 * ADR-000 #169 / #176 — public, signed-token form & survey access.
 *
 * Two responsibilities:
 *  - Authenticated issuers mint signed tokens (scoped, expiring, revocable).
 *  - Anonymous respondents open a form / respond to a survey by presenting
 *    a valid signed token (no Sanctum session required).
 */
class PublicAccessController extends Controller
{
    public function __construct(
        private readonly PublicTokenService $tokens,
        private readonly FormRuntimeService $forms,
        private readonly SurveyRuntimeService $surveys,
    ) {}

    /**
     * Mint a signed public token (authenticated issuer only).
     */
    public function issueToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_id' => 'nullable|uuid',
            'interaction_type' => 'required|in:DATA_ENTRY_FORM,SURVEY_ASSESSMENT',
            'capability' => 'sometimes|in:respond,view',
            'scope' => 'sometimes|array',
            'expires_in_minutes' => 'sometimes|integer|min:5|max:525600',
            'issued_to_email' => 'sometimes|email',
            'rate_limit_per_hour' => 'sometimes|integer|min:1|max:10000',
            'max_uses' => 'sometimes|integer|min:0',
        ]);

        $expiresAt = isset($data['expires_in_minutes'])
            ? now()->addMinutes($data['expires_in_minutes'])
            : now()->addDays(7);

        $issued = $this->tokens->issue(
            templateId: $data['template_id'] ?? null,
            interactionType: InteractionType::from($data['interaction_type']),
            capability: $data['capability'] ?? 'respond',
            scope: $data['scope'] ?? [],
            expiresAt: $expiresAt,
            issuedToEmail: $data['issued_to_email'] ?? null,
            issuedIp: $request->ip(),
            rateLimitPerHour: $data['rate_limit_per_hour'] ?? 60,
            maxUses: $data['max_uses'] ?? 0,
        );

        // The raw token is returned EXACTLY ONCE.
        return response()->json([
            'data' => [
                'token' => $issued['token'],
                'token_id' => $issued['record']->id,
                'expires_at' => $issued['record']->expires_at,
                'capability' => $issued['record']->capability,
            ],
        ], 201);
    }

    public function revokeToken(Request $request): JsonResponse
    {
        $data = $request->validate(['token_id' => 'required|uuid', 'reason' => 'sometimes|string|max:500']);

        return response()->json(['data' => $this->tokens->revoke($data['token_id'], $data['reason'] ?? 'revoked')]);
    }

    /**
     * Open a public form (validate the token + return the published schema).
     */
    public function openForm(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string', 'nonce' => 'nullable|string']);

        try {
            $record = $this->tokens->verify($data['token'], $data['nonce'] ?? null);
        } catch (TemplatePlatformException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), ...$e->responsePayload()], $e->getStatusCode());
        }

        $template = $record->template_id ? DocumentTemplate::find($record->template_id) : null;
        if (! $template || ! $template->isResolvable()) {
            return response()->json(['message' => 'Form unavailable.', 'error_code' => 'TEMPLATE_NOT_PUBLISHED'], 404);
        }

        return response()->json([
            'data' => [
                'template_id' => $template->id,
                'version' => $template->published_version,
                'schema' => $template->layout_config['fields'] ?? $template->layout_config['schema'] ?? [],
                'capability' => $record->capability,
            ],
        ]);
    }

    /**
     * Submit a public form via signed token.
     */
    public function submitPublicForm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'nonce' => 'required|string',
            'answers' => 'required|array',
            'consent' => 'nullable|array',
            'submitter_email' => 'nullable|email',
        ]);

        try {
            $record = $this->tokens->verify($data['token'], $data['nonce']);
            $template = $record->template_id ? DocumentTemplate::find($record->template_id) : null;
            if (! $template) {
                throw TemplatePlatformException::fromCode(\Modules\TemplateBuilder\Platform\TemplateErrorCode::NotAssigned, 'No template bound to token.');
            }

            $submission = $this->forms->submit($template, $data['answers'], [
                'consent' => $data['consent'] ?? null,
                'submitter_email' => $data['submitter_email'] ?? null,
                'submitter_token_id' => $record->id,
                'idempotency_key' => 'public:'.$data['nonce'],
            ]);
        } catch (TemplatePlatformException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), ...$e->responsePayload()], $e->getStatusCode());
        }

        return response()->json(['data' => ['submission_id' => $submission->id, 'status' => $submission->status]], 201);
    }

    /**
     * Respond to a public survey via signed token.
     */
    public function respondPublicSurvey(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'nonce' => 'required|string',
            'answers' => 'required|array',
            'identity_policy' => 'sometimes|in:anonymous,identified,known_customer',
            'respondent_email' => 'nullable|email',
            'consent' => 'nullable|boolean',
        ]);

        try {
            $record = $this->tokens->verify($data['token'], $data['nonce']);
            $template = $record->template_id ? DocumentTemplate::find($record->template_id) : null;
            if (! $template) {
                throw TemplatePlatformException::fromCode(\Modules\TemplateBuilder\Platform\TemplateErrorCode::NotAssigned, 'No template bound to token.');
            }

            $response = $this->surveys->respond($template, $data['answers'], [
                'identity_policy' => $data['identity_policy'] ?? 'anonymous',
                'respondent_email' => $data['respondent_email'] ?? null,
                'respondent_token_id' => $record->id,
                'consent' => $data['consent'] ?? false,
                'idempotency_key' => 'public:'.$data['nonce'],
            ]);
        } catch (TemplatePlatformException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), ...$e->responsePayload()], $e->getStatusCode());
        }

        return response()->json(['data' => ['response_id' => $response->id, 'status' => $response->status]], 201);
    }
}
