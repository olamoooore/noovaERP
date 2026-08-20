<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
                ->with('addresses')
                ->select([
                    'id',
                    'code',
                    'name',
                    'email',
                    'phone',
                    'company',
                    'tax_number',
                    'company_registration_number',
                    'address',
                    'billing_address',
                    'shipping_address',
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
                    $builder->where('code', 'ILIKE', "%{$search}%")
                        ->orWhere('name', 'ILIKE', "%{$search}%")
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
            $vendor = Vendor::with('addresses')->find($id);

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
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('vendors', 'code')->whereNull('deleted_at'),
            ],
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:100',
            'company_registration_number' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'billing_address' => 'nullable|string',
            'shipping_address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'website' => 'nullable|url|max:255',
            'payment_terms' => 'nullable|integer|min:0|max:365',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
            'addresses' => 'sometimes|array',
            'addresses.*.type' => 'required|in:billing,shipping,other',
            'addresses.*.label' => 'nullable|string|max:255',
            'addresses.*.street' => 'required|string|max:255',
            'addresses.*.street2' => 'nullable|string|max:255',
            'addresses.*.city' => 'nullable|string|max:150',
            'addresses.*.state' => 'nullable|string|max:150',
            'addresses.*.zip' => 'nullable|string|max:30',
            'addresses.*.country' => 'nullable|string|max:150',
            'addresses.*.is_default' => 'boolean',
        ]);

        try {
            $addresses = $validated['addresses'] ?? [];
            unset($validated['addresses']);

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

            $vendor = DB::transaction(function () use ($data, $addresses) {
                $vendor = Vendor::create($data);
                $this->replaceAddresses($vendor, $addresses);

                return $vendor;
            });

            return response()->json([
                'message' => 'Vendor created successfully',
                'data' => $vendor->load('addresses'),
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
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('vendors', 'code')->whereNull('deleted_at')->ignore($vendor->id),
            ],
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:100',
            'company_registration_number' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'billing_address' => 'nullable|string',
            'shipping_address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'website' => 'nullable|url|max:255',
            'payment_terms' => 'nullable|integer|min:0|max:365',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
            'addresses' => 'sometimes|array',
            'addresses.*.type' => 'required|in:billing,shipping,other',
            'addresses.*.label' => 'nullable|string|max:255',
            'addresses.*.street' => 'required|string|max:255',
            'addresses.*.street2' => 'nullable|string|max:255',
            'addresses.*.city' => 'nullable|string|max:150',
            'addresses.*.state' => 'nullable|string|max:150',
            'addresses.*.zip' => 'nullable|string|max:30',
            'addresses.*.country' => 'nullable|string|max:150',
            'addresses.*.is_default' => 'boolean',
        ]);

        $addressesSupplied = array_key_exists('addresses', $validated);
        $addresses = $validated['addresses'] ?? [];
        unset($validated['addresses']);

        DB::transaction(function () use ($vendor, $validated, $addresses, $addressesSupplied) {
            $vendor->update($validated);
            if ($addressesSupplied) {
                $this->replaceAddresses($vendor, $addresses);
            }
        });

        return response()->json([
            'message' => 'Vendor updated successfully',
            'data' => $vendor->fresh()->load('addresses'),
        ]);
    }

    /**
     * Soft-delete an unused vendor while preserving accounting history.
     */
    public function destroy($id)
    {
        $vendor = Vendor::find($id);

        if (! $vendor) {
            return response()->json(['message' => 'Vendor not found'], 404);
        }

        $dependencies = [
            'bills' => $vendor->bills()->count(),
            'payments' => $vendor->payments()->count(),
            'bill payments' => $vendor->billPayments()->count(),
        ];
        $dependencies = array_filter($dependencies);

        if ($dependencies !== []) {
            $summary = collect($dependencies)
                ->map(fn ($count, $type) => "{$count} {$type}")
                ->implode(', ');

            return response()->json([
                'message' => "Vendor cannot be deleted because it is referenced by {$summary}. Deactivate the vendor instead.",
                'code' => 'VENDOR_HAS_ACCOUNTING_HISTORY',
                'dependencies' => $dependencies,
            ], 409);
        }

        $vendor->delete();

        return response()->json(['message' => 'Vendor deleted successfully']);
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
        $updated = 0;
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
                    $vendorData = [
                        'code' => isset($row['code']) && $row['code'] !== '' ? trim((string) $row['code']) : null,
                        'name' => $name,
                        'email' => isset($row['email']) && $row['email'] !== '' ? trim((string) $row['email']) : null,
                        'phone' => isset($row['phone']) && $row['phone'] !== '' ? trim((string) $row['phone']) : null,
                        'company' => isset($row['company']) && $row['company'] !== '' ? trim((string) $row['company']) : null,
                        'address' => isset($row['address']) && $row['address'] !== '' ? trim((string) $row['address']) : null,
                        'billing_address' => isset($row['billing_address']) && $row['billing_address'] !== ''
                            ? trim((string) $row['billing_address'])
                            : (isset($row['address']) && $row['address'] !== '' ? trim((string) $row['address']) : null),
                        'shipping_address' => isset($row['shipping_address']) && $row['shipping_address'] !== ''
                            ? trim((string) $row['shipping_address'])
                            : (isset($row['address']) && $row['address'] !== '' ? trim((string) $row['address']) : null),
                        'contact_person' => isset($row['contact_person']) && $row['contact_person'] !== '' ? trim((string) $row['contact_person']) : null,
                        'updated_at' => now(),
                    ];

                    $existing = DB::table('vendors')
                        ->whereNull('deleted_at')
                        ->where(function ($query) use ($vendorData, $name) {
                            if ($vendorData['code'] !== null) {
                                $query->where('code', $vendorData['code']);
                            } else {
                                $query->where('name', $name);
                            }
                        })
                        ->first();

                    if ($existing) {
                        if ($vendorData['code'] === null) {
                            unset($vendorData['code']);
                        }
                        DB::table('vendors')->where('id', $existing->id)->update($vendorData);
                        $updated++;
                    } else {
                        $vendorData['code'] ??= Vendor::generateCode();
                        $vendorData['created_at'] = now();
                        DB::table('vendors')->insert($vendorData);
                        $created++;
                    }
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
            'updated' => $updated,
            'errors' => $errors,
        ]);
    }

    private function replaceAddresses(Vendor $vendor, array $addresses): void
    {
        $vendor->addresses()->delete();
        $defaults = [];

        foreach ($addresses as $address) {
            $type = $address['type'];
            $isDefault = (bool) ($address['is_default'] ?? false) && ! isset($defaults[$type]);
            if ($isDefault) {
                $defaults[$type] = true;
            }

            $vendor->addresses()->create([...$address, 'is_default' => $isDefault]);
        }
    }
}
