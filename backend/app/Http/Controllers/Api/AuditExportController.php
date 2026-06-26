<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditExport;
use App\Models\AuditorActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class AuditExportController extends Controller
{
    public function adminIndex(Request $request)
    {
        $tenantId = tenant()->id;
        $query = AuditExport::where('tenant_id', $tenantId);

        if ($request->has('auditor_id')) {
            $query->where('auditor_id', $request->get('auditor_id'));
        }

        $exports = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($exports);
    }

    public function index(Request $request)
    {
        $auditor = $request->attributes->get('auditor');
        if (!$auditor) {
            return response()->json(['message' => 'Auditor access required'], 403);
        }

        $exports = AuditExport::where('auditor_id', $auditor->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($exports);
    }

    public function store(Request $request)
    {
        $auditor = $request->attributes->get('auditor');
        $isAuditor = $auditor !== null;
        $isAdmin = !$isAuditor && $request->user() !== null;

        if ($isAuditor && !$auditor->scopes()->where('can_export', true)->exists()) {
            return response()->json(['message' => 'No permission to export data'], 403);
        }

        $validated = $request->validate([
            'entity_id' => 'required|exists:legal_entities,id',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'modules' => 'nullable|array',
            'modules.*' => 'in:accounting,reports,financial_close',
            'reports' => 'nullable|array',
            'reports.*' => 'in:profit_loss,balance_sheet,trial_balance,general_ledger,ar_aging,ap_aging,tax_reports',
            'include_journals' => 'boolean',
            'include_transactions' => 'boolean',
            'include_documents' => 'boolean',
            'auditor_id' => 'nullable|integer|exists:auditors,id',
        ]);

        $auditorId = $auditor?->id ?? $validated['auditor_id'] ?? null;

        if ($auditor) {
            $scope = $auditor->scopes()->where('entity_id', $validated['entity_id'])->first();
            if (!$scope) {
                return response()->json(['message' => 'No scope for this entity'], 403);
            }
        } else {
            $scope = null;
        }

        $exportData = $this->gatherExportData($validated, $scope);

        $fileName = 'audit-export-' . now()->format('Y-m-d-His') . '.zip';
        $filePath = "auditor/exports/{$auditorId}/{$fileName}";

        Storage::disk('local')->put($filePath, $this->createZipArchive($exportData, $fileName));

        $fileSize = Storage::disk('local')->size($filePath);

        $export = AuditExport::create([
            'auditor_id' => $auditorId,
            'tenant_id' => tenant()->id,
            'scope' => $validated,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'file_type' => 'application/zip',
        ]);

        AuditorActivityLog::create([
            'auditor_id' => $auditorId,
            'tenant_id' => tenant()->id,
            'action' => 'export_created',
            'resource_type' => 'export',
            'resource_id' => $export->id,
            'metadata' => ['file_name' => $fileName, 'file_size' => $fileSize, 'modules' => $validated['modules'] ?? []],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Export created successfully',
            'data' => $export,
        ], 201);
    }

    public function download(Request $request, string $id)
    {
        $auditor = $request->attributes->get('auditor');
        if (!$auditor) {
            return response()->json(['message' => 'Auditor access required'], 403);
        }

        $export = AuditExport::where('auditor_id', $auditor->id)->findOrFail($id);

        if (!Storage::disk('local')->exists($export->file_path)) {
            return response()->json(['message' => 'Export file not found'], 404);
        }

        $export->update([
            'downloaded_at' => now(),
            'download_ip' => $request->ip(),
        ]);

        AuditorActivityLog::create([
            'auditor_id' => $auditor->id,
            'tenant_id' => tenant()->id,
            'action' => 'export_downloaded',
            'resource_type' => 'export',
            'resource_id' => $export->id,
            'metadata' => ['file_name' => $export->file_name, 'file_size' => $export->file_size],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return Storage::disk('local')->download($export->file_path, $export->file_name);
    }

    public function show(Request $request, string $id)
    {
        $auditor = $request->attributes->get('auditor');
        if (!$auditor) {
            return response()->json(['message' => 'Auditor access required'], 403);
        }

        $export = AuditExport::where('auditor_id', $auditor->id)->findOrFail($id);

        return response()->json($export);
    }

    private function gatherExportData(array $validated, $scope): array
    {
        $data = [];
        $entityId = $validated['entity_id'];

        $data['summary'] = [
            'export_date' => now()->toIso8601String(),
            'entity_id' => $entityId,
            'period' => ['start' => $validated['period_start'], 'end' => $validated['period_end']],
            'modules' => $validated['modules'] ?? [],
            'reports' => $validated['reports'] ?? [],
        ];

        if (in_array('accounting', $validated['modules'] ?? [])) {
            $data['accounts'] = \App\Models\ChartOfAccount::get()->toArray();
            $data['journal_entries'] = \App\Models\JournalEntry::whereBetween('entry_date', [$validated['period_start'], $validated['period_end']])
                ->get()->toArray();
        }

        if (($validated['include_transactions'] ?? false) || in_array('transactions', $validated['modules'] ?? [])) {
            $data['invoices'] = \App\Models\Invoice::whereBetween('invoice_date', [$validated['period_start'], $validated['period_end']])->get()->toArray();
            $data['bills'] = \App\Models\Bill::whereBetween('bill_date', [$validated['period_start'], $validated['period_end']])->get()->toArray();
        }

        return $data;
    }

    private function createZipArchive(array $data, string $fileName): string
    {
        $zip = new ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'audit-export-');

        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create ZIP archive');
        }

        $zip->addFromString('export-summary.json', json_encode($data['summary'], JSON_PRETTY_PRINT));

        foreach ($data as $key => $value) {
            if ($key !== 'summary' && is_array($value)) {
                $zip->addFromString("{$key}.json", json_encode($value, JSON_PRETTY_PRINT));
            }
        }

        $zip->close();

        return file_get_contents($tempFile);
    }
}
