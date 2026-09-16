<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmBankStatementImportRequest;
use App\Http\Requests\PreviewBankStatementImportRequest;
use App\Models\BankStatementImport;
use App\Services\BankStatements\BankStatementImportService;
use App\Services\BankStatements\DuplicateStatementFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BankStatementImportController extends Controller
{
    public function __construct(
        private BankStatementImportService $service,
        private \App\Services\BankStatements\BankStatementAuditService $audit,
    ) {}

    public function preview(PreviewBankStatementImportRequest $request): JsonResponse
    {
        try {
            $data = $this->service->preview($request->file('file'), (int) $request->integer('bank_account_id'), (int) $request->user()->id, (int) ($request->integer('header_row') ?: 1), $request->string('worksheet')->toString() ?: null);

            return response()->json(['data' => $data, 'correlation_id' => (string) Str::uuid()]);
        } catch (DuplicateStatementFile $e) {
            return response()->json(['code' => 'DUPLICATE_STATEMENT_FILE', 'message' => $e->getMessage(), 'existing_import_id' => $e->importId, 'correlation_id' => (string) Str::uuid()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['code' => 'INVALID_STATEMENT', 'message' => $e->getMessage(), 'correlation_id' => (string) Str::uuid()], 422);
        }
    }

    public function store(ConfirmBankStatementImportRequest $request): JsonResponse
    {
        try {
            $import = $this->service->confirm($request->validated(), (int) $request->user()->id);

            return response()->json(['data' => $import, 'correlation_id' => (string) Str::uuid()], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['code' => 'INVALID_IMPORT_CONFIRMATION', 'message' => $e->getMessage(), 'correlation_id' => (string) Str::uuid()], 422);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $query = BankStatementImport::with('bankAccount:id,account_name,currency_code')->latest();
        if ($request->filled('bank_account_id')) {
            $query->where('bank_account_id', $request->integer('bank_account_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->paginate(min(250, max(1, $request->integer('per_page', 50)))));
    }

    public function show(string $importId): JsonResponse
    {
        return response()->json(['data' => BankStatementImport::with('bankAccount:id,account_name,currency_code')->withCount('transactions')->findOrFail($importId)]);
    }

    public function errors(Request $request, string $importId): JsonResponse
    {
        BankStatementImport::findOrFail($importId);

        return response()->json(DB::table('bank_statement_import_errors')
            ->where('import_id', $importId)->orderBy('source_row')
            ->paginate(min(250, max(1, $request->integer('per_page', 50)))));
    }

    public function source(Request $request, string $importId)
    {
        $import = BankStatementImport::findOrFail($importId);
        if (! Storage::disk('local')->exists($import->storage_path)) {
            abort(404, 'Original statement source file is unavailable.');
        }
        $this->audit->record('import_source_downloaded', [
            'actor_id' => $request->user()->id,
            'bank_account_id' => $import->bank_account_id,
            'import_id' => $import->id,
            'metadata' => ['file_hash' => $import->file_hash],
        ]);

        return Storage::disk('local')->download($import->storage_path, $import->file_name);
    }

    public function reverse(Request $request, string $importId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try {
            return response()->json(['data' => $this->service->reverse($importId, $data['reason'], (int) $request->user()->id)]);
        } catch (\DomainException $exception) {
            return response()->json(['code' => 'IMPORT_REVERSAL_BLOCKED', 'message' => $exception->getMessage()], 409);
        }
    }
}
