<?php

namespace Modules\FieldService\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\FieldService\Models\WorkOrder;
use Modules\FieldService\Models\WorksheetResponse;
use Modules\FieldService\Models\WorksheetTemplate;
use Modules\FieldService\Services\WorksheetPDFService;

class WorksheetResponseController extends Controller
{
    public function __construct(private WorksheetPDFService $pdfService) {}

    /**
     * GET /field-service/work-orders/{workOrder}/worksheet
     *
     * Returns the assigned template + any existing response for this WO.
     */
    public function show(int $work_order_id): JsonResponse
    {
        $workOrder = $this->findWorkOrderOrFail($work_order_id);

        // Determine the template: from existing response, or from WO type, or the default
        $response = WorksheetResponse::with('template')
            ->where('work_order_id', $workOrder->id)
            ->first();

        if ($response) {
            return response()->json([
                'template' => $response->template,
                'response' => $response,
            ]);
        }

        // No response yet — figure out which template to use
        $template = null;

        if ($workOrder->type && $workOrder->type->worksheet_template_id) {
            $template = WorksheetTemplate::find($workOrder->type->worksheet_template_id);
        }

        if (! $template) {
            $template = WorksheetTemplate::where('is_default', true)->first();
        }

        return response()->json([
            'template' => $template,
            'response' => null,
        ]);
    }

    /**
     * POST /field-service/work-orders/{workOrder}/worksheet
     *
     * Save (create or update) the worksheet response.
     */
    public function save(Request $request, int $work_order_id): JsonResponse
    {
        $workOrder = $this->findWorkOrderOrFail($work_order_id);

        $validated = $request->validate([
            'template_id' => 'required|exists:fs_worksheet_templates,id',
            'responses' => 'required|array',
        ]);

        $response = WorksheetResponse::updateOrCreate(
            ['work_order_id' => $workOrder->id],
            [
                'template_id' => $validated['template_id'],
                'responses' => $validated['responses'],
                'completed_by' => Auth::id(),
                'completed_at' => now(),
            ]
        );

        return response()->json($response->load('template'), 200);
    }

    /**
     * POST /field-service/work-orders/{workOrder}/worksheet/sign
     *
     * Apply technician or customer signature.
     * Body: { signer: 'technician'|'customer', signature: '<base64>' }
     */
    public function sign(Request $request, int $work_order_id): JsonResponse
    {
        $workOrder = $this->findWorkOrderOrFail($work_order_id);

        $validated = $request->validate([
            'signer' => 'required|in:technician,customer',
            'signature' => 'required|string',
        ]);

        $response = WorksheetResponse::where('work_order_id', $workOrder->id)->firstOrFail();

        if ($validated['signer'] === 'technician') {
            $response->technician_signature = $validated['signature'];
            $workOrder->technician_signed_at = now();
        } else {
            $response->customer_signature = $validated['signature'];
            $workOrder->customer_signed_at = now();
        }

        $response->save();
        $workOrder->save();

        // If both parties signed, auto-generate the PDF
        if ($response->isFullySigned()) {
            try {
                $pdfPath = $this->pdfService->generate($workOrder, $response);
                $response->update(['pdf_path' => $pdfPath]);
            } catch (\Exception $e) {
                // PDF generation failure is non-fatal — log and continue
                \Illuminate\Support\Facades\Log::warning('Worksheet PDF generation failed', [
                    'work_order_id' => $workOrder->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'response' => $response->fresh(),
            'work_order' => $workOrder->only([
                'id', 'technician_signed_at', 'customer_signed_at',
            ]),
        ]);
    }

    /**
     * GET /field-service/work-orders/{workOrder}/worksheet/pdf
     *
     * Stream the generated PDF.
     */
    public function pdf(int $work_order_id): mixed
    {
        $workOrder = $this->findWorkOrderOrFail($work_order_id);

        $response = WorksheetResponse::where('work_order_id', $workOrder->id)->firstOrFail();

        // Generate on-demand if PDF doesn't exist yet
        if (! $response->pdf_path || ! \Illuminate\Support\Facades\Storage::exists($response->pdf_path)) {
            $pdfPath = $this->pdfService->generate($workOrder, $response);
            $response->update(['pdf_path' => $pdfPath]);
        }

        $path = storage_path('app/'.$response->pdf_path);

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="worksheet-'.$workOrder->reference.'.pdf"',
        ]);
    }

    private function findWorkOrderOrFail(int $workOrderId): WorkOrder
    {
        $workOrder = WorkOrder::query()->find($workOrderId);

        if (! $workOrder) {
            throw (new ModelNotFoundException)->setModel(WorkOrder::class, [$workOrderId]);
        }

        return $workOrder;
    }
}
