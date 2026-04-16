<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Application\BillingService;
use App\SuperAdmin\Domain\Subscription;
use App\SuperAdmin\Domain\SubscriptionInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminBillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billingService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status'     => ['nullable', 'string', 'in:draft,open,paid,void,uncollectible'],
            'tenant_id'  => ['nullable', 'string', 'uuid'],
            'from'       => ['nullable', 'date'],
            'to'         => ['nullable', 'date', 'after_or_equal:from'],
            'search'     => ['nullable', 'string', 'max:100'],
            'page'       => ['nullable', 'integer', 'min:1'],
            'per_page'   => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = SubscriptionInvoice::with(['subscription.planVersion.family'])
            ->orderByDesc('created_at');

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['tenant_id'])) {
            $query->where('tenant_id', $validated['tenant_id']);
        }

        if (!empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (!empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'ILIKE', "%{$search}%")
                  ->orWhereIn('tenant_id', function ($sub) use ($search) {
                      $sub->select('id')->from('tenants')
                          ->where('name', 'ILIKE', "%{$search}%");
                  });
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', (int) ($validated['page'] ?? 1));

        // Summary totals for the current filter
        $openTotal = SubscriptionInvoice::where('status', 'open')->sum('total');
        $paidThisMonth = SubscriptionInvoice::where('status', 'paid')
            ->whereMonth('paid_at', now()->month)
            ->whereYear('paid_at', now()->year)
            ->sum('total');

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'total'           => $paginator->total(),
                'page'            => $paginator->currentPage(),
                'per_page'        => $paginator->perPage(),
                'last_page'       => $paginator->lastPage(),
                'open_total'      => $openTotal,
                'paid_this_month' => $paidThisMonth,
            ],
        ]);
    }

    public function show(SubscriptionInvoice $invoice): JsonResponse
    {
        $invoice->load(['subscription.planVersion.family']);

        return response()->json(['data' => $invoice]);
    }

    public function markPaid(SubscriptionInvoice $invoice): JsonResponse
    {
        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'Invoice is already paid.'], 422);
        }

        if ($invoice->status === 'void') {
            return response()->json(['message' => 'Cannot mark a voided invoice as paid.'], 422);
        }

        $invoice = $this->billingService->markPaid($invoice);

        return response()->json(['data' => $invoice]);
    }

    public function voidInvoice(Request $request, SubscriptionInvoice $invoice): JsonResponse
    {
        if ($invoice->status === 'void') {
            return response()->json(['message' => 'Invoice is already voided.'], 422);
        }

        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'Cannot void a paid invoice.'], 422);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $invoice = $this->billingService->voidInvoice($invoice, $validated['reason'] ?? null);

        return response()->json(['data' => $invoice]);
    }

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscription_id'  => ['required', 'integer', 'exists:subscriptions,id'],
            'billing_interval' => ['required', 'string', 'in:monthly,yearly'],
        ]);

        $subscription = Subscription::findOrFail($validated['subscription_id']);
        $invoice = $this->billingService->generateInvoice(
            subscription: $subscription,
            billingInterval: $validated['billing_interval'],
        );

        return response()->json(['data' => $invoice], 201);
    }
}
