<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorsController extends Controller
{
    /**
     * List all vendors
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);
            $search = trim((string) $request->get('search', ''));

            $query = Vendor::query()
                ->select([
                    'id',
                    'name',
                    'email',
                    'phone',
                    'company',
                    'tax_number',
                    'company_registration_number',
                    'address',
                    'contact_person',
                    'website',
                    'payment_terms',
                    'notes',
                    'is_active',
                    'created_at',
                    'updated_at',
                ])
                ->orderBy('name', 'asc');

            if ($search !== '') {
                $query->where(function ($builder) use ($search) {
                    $builder->where('name', 'ILIKE', "%{$search}%")
                        ->orWhere('email', 'ILIKE', "%{$search}%")
                        ->orWhere('company', 'ILIKE', "%{$search}%")
                        ->orWhere('contact_person', 'ILIKE', "%{$search}%")
                        ->orWhere('tax_number', 'ILIKE', "%{$search}%");
                });
            }

            // Get total count
            $total = $query->count();

            // Get paginated results
            $vendors = $query
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            return response()->json([
                'data' => $vendors,
                'meta' => [
                    'current_page' => (int) $page,
                    'per_page' => (int) $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch vendors: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch vendors',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single vendor
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $vendor = Vendor::find($id);

            if (! $vendor) {
                return response()->json([
                    'message' => 'Vendor not found',
                ], 404);
            }

            return response()->json([
                'data' => $vendor,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch vendor: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch vendor',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a vendor.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:100',
            'company_registration_number' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'website' => 'nullable|url|max:255',
            'payment_terms' => 'nullable|integer|min:0|max:365',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        try {
            $data = [
                ...$validated,
                'payment_terms' => $validated['payment_terms'] ?? 30,
                'is_active' => $validated['is_active'] ?? true,
            ];

            // Defensive: if company_registration_number column doesn't exist in DB,
            // remove it to avoid SQL errors on tenants where the migration hasn't run.
            if (isset($data['company_registration_number']) && ! \Illuminate\Support\Facades\Schema::hasColumn('vendors', 'company_registration_number')) {
                unset($data['company_registration_number']);
            }

            $vendor = Vendor::create($data);

            return response()->json([
                'message' => 'Vendor created successfully',
                'data' => $vendor,
            ], 201);

        } catch (\Illuminate\Database\QueryException $e) {
            \Log::error('Vendor creation failed — SQL error', [
                'error' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);

            return response()->json([
                'message' => 'Failed to create vendor — database error',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Vendor creation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Failed to create vendor — '.$e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a vendor.
     */
    public function update(Request $request, $id)
    {
        $vendor = Vendor::find($id);

        if (! $vendor) {
            return response()->json([
                'message' => 'Vendor not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:100',
            'company_registration_number' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'website' => 'nullable|url|max:255',
            'payment_terms' => 'nullable|integer|min:0|max:365',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $vendor->update($validated);

        return response()->json([
            'message' => 'Vendor updated successfully',
            'data' => $vendor->fresh(),
        ]);
    }

    /**
     * Import vendors from CSV file
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return response()->json(['message' => 'Unable to read file'], 400);
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || count($header) === 0) {
            fclose($handle);

            return response()->json(['message' => 'CSV header missing'], 422);
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (! is_array($data) || count(array_filter($data, fn ($v) => (string) $v !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($header as $idx => $key) {
                $row[$key] = $data[$idx] ?? null;
            }
            $rows[] = $row;
        }
        fclose($handle);

        if (count($rows) === 0) {
            return response()->json(['message' => 'No rows found'], 422);
        }

        $created = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                $name = trim((string) ($row['name'] ?? ''));

                if ($name === '') {
                    $errors[] = [
                        'row' => $index + 2,
                        'message' => 'Missing required field: name',
                    ];

                    continue;
                }

                try {
                    DB::table('vendors')->insert([
                        'name' => $name,
                        'email' => isset($row['email']) && $row['email'] !== '' ? trim((string) $row['email']) : null,
                        'phone' => isset($row['phone']) && $row['phone'] !== '' ? trim((string) $row['phone']) : null,
                        'company' => isset($row['company']) && $row['company'] !== '' ? trim((string) $row['company']) : null,
                        'address' => isset($row['address']) && $row['address'] !== '' ? trim((string) $row['address']) : null,
                        'contact_person' => isset($row['contact_person']) && $row['contact_person'] !== '' ? trim((string) $row['contact_person']) : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $created++;
                } catch (\Throwable $e) {
                    $errors[] = [
                        'row' => $index + 2,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => 'Import failed', 'error' => $e->getMessage()], 500);
        }

        return response()->json([
            'created' => $created,
            'errors' => $errors,
        ]);
    }
}
