<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Application\SubscriptionService;
use App\SuperAdmin\Domain\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminSubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:trialing,active,past_due,canceled,expired'],
            'plan_version_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = Subscription::with(['planVersion.family'])
            ->orderByDesc('created_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['plan_version_id'])) {
            $query->where('plan_version_id', $validated['plan_version_id']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->whereIn('tenant_id', function ($sub) use ($search) {
                $sub->select('id')->from('tenants')
                    ->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('email', 'ILIKE', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        $subscription->load(['planVersion.family', 'planVersion.prices', 'invoices' => function ($q) {
            $q->orderByDesc('created_at');
        }]);

        return response()->json(['data' => $subscription]);
    }

    public function forTenant(string $tenantId): JsonResponse
    {
        $subscription = Subscription::where('tenant_id', $tenantId)
            ->with(['planVersion.family', 'planVersion.prices', 'invoices' => function ($q) {
                $q->orderByDesc('created_at')->limit(5);
            }])
            ->latest()
            ->first();

        return response()->json(['data' => $subscription]);
    }

    public function create(Request $request, string $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'plan_version_id' => ['required', 'integer', 'exists:plan_versions,id'],
            'billing_interval' => ['required', 'string', 'in:monthly,yearly'],
            'trial_ends_at' => ['nullable', 'date', 'after:now'],
        ]);

        $subscription = $this->subscriptionService->createSubscription(
            tenantId: $tenantId,
            planVersionId: (int) $validated['plan_version_id'],
            billingInterval: $validated['billing_interval'],
            trialEndsAt: isset($validated['trial_ends_at'])
                ? \Carbon\Carbon::parse($validated['trial_ends_at'])
                : null,
        );

        $subscription->load(['planVersion.family', 'planVersion.prices']);

        return response()->json(['data' => $subscription], 201);
    }

    public function cancel(Request $request, Subscription $subscription): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $subscription = $this->subscriptionService->cancelSubscription(
            subscription: $subscription,
            reason: $validated['reason'] ?? null,
        );

        return response()->json(['data' => $subscription]);
    }

    public function reactivate(Subscription $subscription): JsonResponse
    {
        $subscription = $this->subscriptionService->reactivateSubscription($subscription);

        return response()->json(['data' => $subscription]);
    }
}
