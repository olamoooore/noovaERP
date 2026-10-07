<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\PaymentAdviceImport;
use App\PaymentAdvices\PaymentAdviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * #1001: customer payment advice reconciliation — upload, review the parsed
 * lines and suggested invoice matches, then confirm the allocations.
 */
class PaymentAdviceController extends Controller
{
    public function __construct(private readonly PaymentAdviceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $imports = PaymentAdviceImport::query()
            ->with(['customer:id,name'])
            ->orderByDesc('id')
            ->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json($imports);
    }

    public function show(string $id): JsonResponse
    {
        $import = PaymentAdviceImport::query()
            ->with(['customer:id,name', 'payments:id,invoice_id,payment_number,amount,payment_status'])
            ->findOrFail($id);

        return response()->json(['data' => $import]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // PDF or image advice; 10 MB cap matches the OCR service's limit.
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $import = $this->service->createFromUpload(
            $validated['file'],
            (int) $request->user()->id,
        );

        return response()->json([
            'message' => 'Payment advice parsed — review the matches before confirming.',
            'data' => $import->fresh(['customer:id,name']),
        ], 201);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $import = PaymentAdviceImport::query()->findOrFail($id);

        $validated = $request->validate([
            'allocations' => 'required|array|min:1',
            'allocations.*.line_index' => 'required|integer|min:0',
            'allocations.*.invoice_id' => 'required|integer|exists:invoices,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ]);

        $import = $this->service->confirm(
            $import,
            $validated['allocations'],
            (int) $request->user()->id,
        );

        return response()->json([
            'message' => 'Allocations confirmed — draft payments created; post them from the Payments page.',
            'data' => $import->load(['customer:id,name', 'payments:id,invoice_id,payment_number,amount,payment_status']),
        ]);
    }
}
