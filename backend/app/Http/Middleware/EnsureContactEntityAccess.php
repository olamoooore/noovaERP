<?php

namespace App\Http\Middleware;

use App\Services\EntityAccessService;
use Closure;
use Illuminate\Http\Request;
use Modules\Contact\src\Models\CoreContact;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hostile re-review (#124/#232): the contact list was entity-scoped but every
 * id-addressed route (show/360/profiles/roles/addresses/… and the mutating
 * equivalents) bypassed the scope — an Entity-A user could read an Entity-B
 * contact by id.
 *
 * This middleware applies the same canonical scoping as
 * ContactMasterService::list() (CoreContact::accessibleBy) to any route
 * carrying a contact id, so the scope cannot be bypassed per-endpoint.
 * Empty entity grants keep the legacy fail-open behaviour (single-entity
 * tenants), matching the platform convention.
 */
class EnsureContactEntityAccess
{
    public function __construct(
        private readonly EntityAccessService $entityAccessService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $contactId = $request->route('id') ?? $request->route('contactId');

        if ($contactId !== null) {
            $user = $request->user();
            $accessibleEntityIds = $user
                ? $this->entityAccessService->accessibleEntityIds((int) $user->id)
                : [];

            if (! empty($accessibleEntityIds)
                && ! CoreContact::whereKey($contactId)->accessibleBy($accessibleEntityIds)->exists()) {
                return response()->json([
                    'message' => 'You do not have access to this contact.',
                ], 403);
            }
        }

        return $next($request);
    }
}
