<?php

namespace Modules\FinancialReporting\Controllers;

use App\Http\Middleware\RequirePermission;
use App\Platform\Export\SpreadsheetFormulaGuard;
use App\Services\LotSerialTraceabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Issue #583 [SALES-ODOO]: lot/serial traceability report endpoint.
 *
 *   GET /api/v1/reports/traceability?lot_number=…|serial_number=…
 *   GET /api/v1/reports/traceability/export?lot_number=…|serial_number=…
 *
 * Both are gated by the reporting group's existing reports.view permission.
 * Patient names are masked server-side unless the caller holds
 * medical_cases.phi.view (#567/#611) — the same permission resolution the
 * document-render choke point uses. The CSV export neutralises
 * spreadsheet formula triggers via the shared #898 guard.
 */
class TraceabilityReportController extends Controller
{
    public function __construct(private readonly LotSerialTraceabilityService $service) {}

    public function show(Request $request): JsonResponse
    {
        [$type, $value] = $this->resolveQuery($request);

        $result = $this->service->trace($type, $value, $this->canViewPhi($request));

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        [$type, $value] = $this->resolveQuery($request);

        $result = $this->service->trace($type, $value, $this->canViewPhi($request));

        $filename = sprintf(
            'traceability-%s-%s-%s.csv',
            $type,
            preg_replace('/[^A-Za-z0-9._-]+/', '-', $value),
            now()->format('Y-m-d')
        );

        return response()->streamDownload(function () use ($result) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Segment', 'Event', 'Date', 'Reference Type', 'Reference ID', 'Reference', 'Title',
                'Product', 'Lot', 'Serial', 'Quantity', 'Warehouse', 'Supplier', 'Customer',
                'Case No', 'Patient Name', 'Invoice', 'Legal Entity', 'Details',
            ]);

            foreach ($result['timeline'] as $event) {
                fputcsv($handle, array_map(
                    fn ($cell) => SpreadsheetFormulaGuard::neutralise((string) $cell),
                    $this->csvRow($event)
                ));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: string, 1: string} [type, value]
     */
    private function resolveQuery(Request $request): array
    {
        $validated = $request->validate([
            'lot_number' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
        ]);

        $lotNumber = trim((string) ($validated['lot_number'] ?? ''));
        $serialNumber = trim((string) ($validated['serial_number'] ?? ''));

        if (($lotNumber === '') === ($serialNumber === '')) {
            throw ValidationException::withMessages([
                'lot_number' => ['Provide exactly one of lot_number or serial_number.'],
            ]);
        }

        return $lotNumber !== ''
            ? ['lot', $lotNumber]
            : ['serial', $serialNumber];
    }

    private function canViewPhi(Request $request): bool
    {
        return RequirePermission::allows($request, 'medical_cases.phi.view');
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<int, string|int|float|null>
     */
    private function csvRow(array $event): array
    {
        $details = $event['details'] ?? [];
        $goodsReceipt = $details['goods_receipt'] ?? [];

        $serial = $details['serial_number']
            ?? (is_array($details['serial_numbers'] ?? null) ? implode('|', $details['serial_numbers']) : null);

        $patientNames = $details['patient_names'] ?? [];
        $patientName = $details['patient_name']
            ?? (is_array($patientNames) && $patientNames !== [] ? implode('|', array_filter($patientNames)) : null);

        $quantity = $details['quantity']
            ?? (is_array($details['lines'] ?? null) && $details['lines'] !== []
                ? array_sum(array_map(fn ($line) => (float) ($line['quantity'] ?? $line['quantity_delivered'] ?? 0), $details['lines']))
                : null);

        return [
            $event['segment'] ?? '',
            $event['event'] ?? '',
            $event['occurred_at'] ?? '',
            $event['reference_type'] ?? '',
            $event['reference_id'] ?? '',
            $event['reference'] ?? '',
            $event['title'] ?? '',
            $details['product_name'] ?? $details['sku'] ?? '',
            $details['lot_number'] ?? $details['batch_number'] ?? '',
            $serial ?? '',
            $quantity ?? '',
            $details['warehouse'] ?? '',
            $details['supplier_name'] ?? $goodsReceipt['supplier_name'] ?? '',
            $details['customer_name'] ?? '',
            $details['case_no'] ?? '',
            $patientName ?? '',
            $details['invoice_number'] ?? ($event['segment'] === LotSerialTraceabilityService::SEGMENT_INVOICE ? ($event['reference'] ?? '') : ''),
            $event['legal_entity_id'] ?? '',
            json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }
}
