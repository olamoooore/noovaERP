<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPortalLink;
use App\Services\CustomerPortalService;
use App\Services\EntityAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issue #337 — staff side of the customer portal: issue, list and revoke a
 * customer's portal links. The customer is resolved inside the requester's
 * entity scope (same rule as CustomerController, #434); out of scope → 404.
 */
class CustomerPortalLinkController extends Controller
{
    public function __construct(private CustomerPortalService $portal) {}

    public function index(Request $request, string $customer): JsonResponse
    {
        $model = $this->scopedCustomer($request, $customer);
        if (! $model) {
            return $this->customerNotFound();
        }

        $links = CustomerPortalLink::query()
            ->where('customer_id', $model->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (CustomerPortalLink $link) => $this->present($link))
            ->values();

        return response()->json(['data' => $links]);
    }

    public function store(Request $request, string $customer): JsonResponse
    {
        $model = $this->scopedCustomer($request, $customer);
        if (! $model) {
            return $this->customerNotFound();
        }

        $validated = $request->validate([
            'expires_in_days' => 'nullable|integer|min:1|max:'.CustomerPortalService::MAX_TTL_DAYS,
            'label' => 'nullable|string|max:120',
        ]);

        // The portal is bound to ONE legal entity: the customer's own entity
        // when it has one, otherwise the requester's resolved entity context
        // (null on a tenant without entities → no entity narrowing).
        $legalEntityId = $model->legal_entity_id !== null
            ? (int) $model->legal_entity_id
            : ($request->attributes->get('legal_entity_id') !== null
                ? (int) $request->attributes->get('legal_entity_id')
                : null);

        $issued = $this->portal->issue(
            $model,
            $legalEntityId,
            (int) ($validated['expires_in_days'] ?? CustomerPortalService::DEFAULT_TTL_DAYS),
            $request->user()?->id ? (int) $request->user()->id : null,
            $validated['label'] ?? null,
        );

        return response()->json([
            'data' => array_merge($this->present($issued['link']), [
                // Shown ONCE — only the hash is stored.
                'token' => $issued['token'],
                'url' => $issued['url'],
            ]),
        ], 201);
    }

    public function destroy(Request $request, string $customer, string $link): JsonResponse
    {
        $model = $this->scopedCustomer($request, $customer);
        if (! $model || ! ctype_digit($link)) {
            return $this->customerNotFound();
        }

        $portalLink = CustomerPortalLink::query()
            ->where('customer_id', $model->id)
            ->whereKey((int) $link)
            ->first();

        if (! $portalLink) {
            return response()->json(['message' => 'Portal link not found'], 404);
        }

        $this->portal->revoke($portalLink, $request->user()?->id ? (int) $request->user()->id : null);

        return response()->json(['data' => $this->present($portalLink->fresh())]);
    }

    private function scopedCustomer(Request $request, string $id): ?Customer
    {
        if (! ctype_digit($id)) {
            return null;
        }

        $query = Customer::query();
        $user = $request->user();

        if ($user) {
            $entityIds = app(EntityAccessService::class)->accessibleEntityIds((int) $user->id);

            if ($entityIds !== []) {
                $query->accessibleBy($entityIds);
            }
        }

        return $query->find((int) $id);
    }

    private function present(CustomerPortalLink $link): array
    {
        return [
            'id' => $link->id,
            'customer_id' => $link->customer_id,
            'legal_entity_id' => $link->legal_entity_id,
            'label' => $link->label,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'last_accessed_at' => $link->last_accessed_at?->toIso8601String(),
            'created_at' => $link->created_at?->toIso8601String(),
            'status' => $link->isRevoked() ? 'revoked' : ($link->isExpired() ? 'expired' : 'active'),
        ];
    }

    private function customerNotFound(): JsonResponse
    {
        return response()->json(['message' => 'Customer not found'], 404);
    }
}
