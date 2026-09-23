<?php

namespace App\Http\Middleware;

use App\Services\ChatterModelRegistry;
use App\Services\ChatterSubjectAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #76 (CRITICAL) — EnsureCommunicationAccess middleware.
 *
 * Validates that any `model` + `record_id` query/body parameter resolves to
 * a chatter-enabled model via ChatterModelRegistry, and that the record
 * exists. Rejects unwhitelisted model strings with 422.
 *
 * Apply to all /api/communication/* routes (per the issue's Required Fix).
 */
class EnsureCommunicationAccess
{
    public function __construct(
        protected ChatterModelRegistry $registry,
        protected ChatterSubjectAuthorization $authorization,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $model = $request->query('model') ?? $request->input('model');
        $recordId = $request->query('record_id') ?? $request->input('record_id');

        // Only enforce when both model and record_id are present.
        if ($model === null || $recordId === null) {
            return $next($request);
        }

        $modelClass = $this->registry->resolveClass((string) $model);
        if ($modelClass === null) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid model: not in chatter registry.',
                'allowed_models' => $this->registry->allowedAliases(),
            ], 422);
        }

        $record = $this->registry->resolveRecord((string) $model, $recordId);
        if ($record === null) {
            return response()->json([
                'success' => false,
                'message' => 'Record not found.',
            ], 404);
        }

        // Issue #74 (hostile re-review B1): record-level authorization. The
        // subject's mapped domain permission gates access; unmapped subjects
        // fail closed. Previously the resolved record was attached but never
        // checked, so any entitled user could reach any registry record.
        if (! $this->authorization->allows($request, (string) $model)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access this record\'s communication.',
                'required_permission' => $this->authorization->permissionFor((string) $model),
            ], 403);
        }

        // Attach the resolved record to the request for downstream use.
        $request->attributes->set('chatter_record', $record);

        return $next($request);
    }
}
