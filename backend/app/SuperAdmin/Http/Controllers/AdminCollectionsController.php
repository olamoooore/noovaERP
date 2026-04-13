<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Application\CollectionsService;
use App\SuperAdmin\Domain\SubscriptionInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class AdminCollectionsController extends Controller
{
    public function __construct(
        private readonly CollectionsService $collectionsService,
    ) {}

    /** GET /admin/collections */
    public function index(): JsonResponse
    {
        Gate::authorize('manage-collections');

        $atRisk = $this->collectionsService->listAtRisk();

        return response()->json(['data' => $atRisk]);
    }

    /** POST /admin/collections/{invoice}/advance */
    public function advance(Request $request, SubscriptionInvoice $invoice): JsonResponse
    {
        Gate::authorize('manage-collections');

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $invoice = $this->collectionsService->advanceStage(
            $invoice,
            $validated['note'] ?? null,
        );

        return response()->json(['data' => $invoice]);
    }

    /** POST /admin/collections/{invoice}/clear */
    public function clear(Request $request, SubscriptionInvoice $invoice): JsonResponse
    {
        Gate::authorize('manage-collections');

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $invoice = $this->collectionsService->clear(
            $invoice,
            $validated['note'] ?? null,
        );

        return response()->json(['data' => $invoice]);
    }
}
