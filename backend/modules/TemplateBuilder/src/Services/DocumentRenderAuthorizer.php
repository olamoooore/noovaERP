<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use App\Http\Middleware\RequirePermission;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\TemplateBuilder\Services\DataSource\DataSourceRegistry;

/**
 * Issue #611: the single authorization gate for rendering a source document.
 *
 * Every path that renders a REAL business record — the consumer render
 * endpoints and (issue #620) the template editor's "preview with a real
 * document" — checks the source document's own read permission and its legal
 * entity against the request's entity context here. document.view /
 * template.view (the coarse route gates) are not enough on their own.
 */
final class DocumentRenderAuthorizer
{
    public function __construct(private readonly DataSourceRegistry $dataSources) {}

    /**
     * Deny (403) when the caller does not hold the source document type's own
     * read permission; null when allowed.
     */
    public function denyType(string $documentType, Request $request): ?JsonResponse
    {
        $permission = $this->dataSources->make($documentType)->permission();

        if (! RequirePermission::allows($request, $permission)) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
                'required_permission' => $permission,
            ], 403);
        }

        return null;
    }

    /**
     * Deny when the caller may not render this specific document: missing
     * source permission, or the document belongs to another legal entity.
     *
     * A non-existent id answers exactly like an id outside the caller's
     * entity (same 403 + same body) so the endpoint is not an existence
     * oracle.
     */
    public function deny(string $documentType, string $documentId, Request $request): ?JsonResponse
    {
        $denied = $this->denyType($documentType, $request);
        if ($denied !== null) {
            return $denied;
        }

        return $this->visibleInRequestEntity($documentType, $documentId, $request)
            ? null
            : response()->json(['message' => 'Document not found.'], 403);
    }

    /**
     * Whether the document exists and is inside the request's legal-entity
     * scope. Entity scoping mirrors the platform's EnsureEntityAccess
     * convention: when the request has an entity context and the document's
     * entity is resolvable, they must match. Documents with no reachable
     * entity link (or requests without an entity context — the single-entity
     * tenant shortcut) are not blocked here.
     */
    public function visibleInRequestEntity(string $documentType, string $documentId, Request $request): bool
    {
        $source = $this->dataSources->make($documentType);

        try {
            $source->load($documentId);
        } catch (ModelNotFoundException) {
            return false;
        }

        $documentEntityId = $source->legalEntityId();
        $requestEntityId = $request->attributes->get('legal_entity_id');

        return ! ($documentEntityId !== null
            && $requestEntityId !== null
            && (int) $documentEntityId !== (int) $requestEntityId);
    }
}
