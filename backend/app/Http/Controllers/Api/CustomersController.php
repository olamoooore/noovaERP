<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CustomerImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Accounting-prefixed customer surface (/accounting/customers).
 *
 * Issue #433 (CU5) decision: this route stays as the accounting-scoped
 * facade (ledger list/detail and the staged import used by the accounting
 * UI). It is NOT a second customer creation path — the import delegates to
 * the generic CustomerImportService, whose authoritative writes go through
 * CanonicalCustomerService. Primary customer CRUD stays on /customers.
 */
class CustomersController extends Controller
{
    /**
     * List all customers
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);

            $query = DB::table('customers')
                ->select('id', 'name', 'email', 'phone', 'company', 'billing_address', 'shipping_address', 'created_at', 'updated_at')
                ->orderBy('name', 'asc');

            // Get total count
            $total = $query->count();

            // Get paginated results
            $customers = $query
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            return response()->json([
                'data' => $customers,
                'meta' => [
                    'current_page' => (int) $page,
                    'per_page' => (int) $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch customers: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch customers',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single customer
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $customer = DB::table('customers')
                ->where('id', $id)
                ->first();

            if (! $customer) {
                return response()->json([
                    'message' => 'Customer not found',
                ], 404);
            }

            return response()->json([
                'data' => $customer,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch customer: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch customer',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import customers from CSV file (DRY_RUN staging).
     *
     * Rows are staged and validated without authoritative writes.
     * Call commitImport with the batch_id to execute the import.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function import(Request $request, CustomerImportService $importService)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
            'legal_entity_id' => 'nullable|integer',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return response()->json(['message' => 'Unable to read file'], 400);
        }

        $rawHeader = fgetcsv($handle);
        if (! is_array($rawHeader) || count($rawHeader) === 0) {
            fclose($handle);

            return response()->json(['message' => 'CSV header missing'], 422);
        }

        $columnAliases = [
            'customer_id' => 'code',
            'customer_code' => 'code',
            'cust_id' => 'code',
            'id' => 'code',
            'customer_name' => 'name',
            'company_name' => 'company',
            'email_address' => 'email',
            'telephone' => 'phone',
            'mobile_number' => 'mobile',
            'tax_id' => 'tax_number',
            'vat_number' => 'tax_number',
            'address' => 'billing_address',
            'billing' => 'billing_address',
            'shipping' => 'shipping_address',
            'address_label' => 'label',
            'address_street' => 'street',
            'address_street2' => 'street2',
            'address_city' => 'city',
            'address_state' => 'state',
            'address_zip' => 'zip',
            'postal_code' => 'zip',
            'address_country' => 'country',
            'credit' => 'credit_limit',
            'payment_terms_days' => 'payment_terms',
            'category_name' => 'category',
            'customer_category' => 'category',
            'contact' => 'contact_name',
            'contact_person' => 'contact_name',
            'name_of_contact' => 'contact_name',
            'contact_person_name' => 'contact_name',
            'contact_role' => 'contact_job_title',
            'contact_postal_code' => 'contact_zip',
            'contact_zip_code' => 'contact_zip',
            'contact_category' => 'contact_category',
            'contact_type' => 'contact_type',
        ];

        $header = array_map(function ($h) use ($columnAliases) {
            $h = strtolower(trim((string) $h));
            $h = preg_replace('/[^a-z0-9]+/', '_', $h);
            $h = trim($h, '_');

            return $columnAliases[$h] ?? $h;
        }, $rawHeader);

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (! is_array($data) || count(array_filter($data, fn ($v) => (string) $v !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($header as $idx => $key) {
                $row[$key] = isset($data[$idx]) ? trim((string) $data[$idx]) : null;
            }
            $rows[] = $row;
        }
        fclose($handle);

        if (count($rows) === 0) {
            return response()->json(['message' => 'No rows found'], 422);
        }

        $fileHash = hash_file('sha256', $file->getRealPath());
        $legalEntityId = $request->input('legal_entity_id');

        $result = $importService->stageBatch(
            $rows,
            tenant()->id,
            $request->user()->id,
            $file->getClientOriginalName(),
            $fileHash,
            null,
            $legalEntityId
        );

        return response()->json([
            'batch_id' => $result['batch_id'],
            'total_rows' => $result['total_rows'],
            'counts' => $result['counts'],
            'code_conflicts' => $result['code_conflicts'],
            'issues' => $result['issues'],
            'status' => $result['status'],
            'message' => 'Import staged. Call POST /customers/import/{batch_id}/commit to execute.',
        ]);
    }

    /**
     * Commit a staged import batch (COMMIT phase).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function commitImport(string $batchId, CustomerImportService $importService)
    {
        try {
            $result = $importService->commitBatch(
                $batchId,
                tenant()->id,
                request()->input('legal_entity_id')
            );

            // #1037: a commit with rejected rows must not read as a clean
            // import, and one that imported nothing is a failure (422) that
            // leaves the batch open for another commit.
            $body = [
                'imported' => $result['imported'],
                'rejected' => $result['rejected'],
                'rejections' => $result['rejections'],
                'rejections_truncated' => $result['rejections_truncated'],
                'batch_id' => $result['batch_id'],
            ];
            if (! $result['committed']) {
                return response()->json($body + [
                    'message' => "Nothing imported: {$result['rejected']} rows rejected. The batch is still open; fix the cause and commit it again.",
                ], 422);
            }

            return response()->json($body + [
                'message' => $result['rejected'] > 0
                    ? "Import committed: {$result['imported']} imported, {$result['rejected']} rejected."
                    : 'Import committed.',
            ]);
        } catch (\DomainException $e) {
            $code = $e->getMessage();
            $status = match ($code) {
                'IMPORT_BATCH_NOT_FOUND' => 404,
                'IMPORT_BATCH_ALREADY_COMPLETED' => 409,
                default => 400,
            };

            return response()->json(['message' => $code], $status);
        }
    }
}
