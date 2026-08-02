<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * Import customers from CSV file
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
        ];

        $header = array_map(function ($h) use ($columnAliases) {
            $h = strtolower(trim((string) $h));
            $h = preg_replace('/[^a-z0-9]+/', '_', $h);
            $h = trim($h, '_');

            return $columnAliases[$h] ?? $h;
        }, $rawHeader);
        $presentColumns = array_fill_keys($header, true);

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

        $created = 0;
        $updated = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                $name = $row['name'] ?? '';

                if ($name === '') {
                    $errors[] = [
                        'row' => $index + 2,
                        'message' => 'Missing required field: name',
                    ];

                    continue;
                }

                // Resolve customer code: use the provided one, else auto-generate.
                // DB::table->insert() bypasses the model's creating event, so we
                // must generate explicitly to match the manual create behaviour.
                $code = trim((string) ($row['code'] ?? ''));
                if ($code === '') {
                    $code = \App\Models\Customer::generateCode();
                }

                $customerData = [
                    'code' => $code,
                    'name' => $name,
                    'is_active' => true,
                ];

                // Only overwrite fields represented by columns in the uploaded CSV.
                // This keeps an update-only import from clearing unrelated customer data.
                foreach ([
                    'email',
                    'phone',
                    'mobile',
                    'company',
                    'tax_number',
                    'website',
                    'billing_address',
                    'shipping_address',
                    'notes',
                ] as $field) {
                    if (isset($presentColumns[$field])) {
                        $customerData[$field] = ($row[$field] ?? '') !== '' ? $row[$field] : null;
                    }
                }

                $rawType = strtolower(trim((string) ($row['type'] ?? '')));
                if (isset($presentColumns['type']) && in_array($rawType, ['individual', 'company'], true)) {
                    $customerData['type'] = in_array($rawType, ['individual', 'company'], true)
                        ? $rawType
                        : 'individual';
                }

                if (isset($row['credit_limit']) && $row['credit_limit'] !== '') {
                    $customerData['credit_limit'] = (float) $row['credit_limit'];
                }
                if (isset($row['payment_terms']) && $row['payment_terms'] !== '') {
                    $customerData['payment_terms'] = (int) $row['payment_terms'];
                }

                try {
                    // Only match live customers — soft-deleted rows (deleted_at) are
                    // ignored so re-importing after a delete creates fresh customers
                    // instead of silently updating trashed, invisible ones.
                    $existing = DB::table('customers')
                        ->whereRaw('LOWER(code) = ?', [strtolower($code)])
                        ->whereNull('deleted_at')
                        ->first();

                    $customerId = $existing->id ?? null;

                    if ($existing) {
                        // Avoid wiping financial stats on re-import
                        $customerData['updated_at'] = now();
                        DB::table('customers')->where('id', $existing->id)->update($customerData);
                        $updated++;
                    } else {
                        $customerData['total_outstanding'] = 0;
                        $customerData['created_at'] = now();
                        $customerData['updated_at'] = now();
                        $customerId = DB::table('customers')->insertGetId($customerData);
                        $created++;
                    }

                    if ($customerId) {
                        $explicitAddressType = strtolower(trim((string) ($row['address_type'] ?? '')));
                        $addressType = in_array($explicitAddressType, ['billing', 'shipping', 'other'], true)
                            ? $explicitAddressType
                            : (in_array($rawType, ['billing', 'shipping', 'other'], true) ? $rawType : 'shipping');
                        $hasAddressColumns = collect(['address_type', 'label', 'street', 'street2', 'city', 'state', 'zip', 'country'])
                            ->contains(fn (string $field) => isset($presentColumns[$field]));

                        if ($hasAddressColumns) {
                            $addressData = [
                                'type' => $addressType,
                                'updated_at' => now(),
                            ];
                            foreach (['label', 'street', 'street2', 'city', 'state', 'zip', 'country'] as $field) {
                                if (isset($presentColumns[$field])) {
                                    $addressData[$field] = ($row[$field] ?? '') !== '' ? $row[$field] : null;
                                }
                            }

                            $addressQuery = DB::table('customer_addresses')
                                ->where('customer_id', $customerId)
                                ->where('type', $addressType);
                            $addressLabel = trim((string) ($row['label'] ?? ''));
                            if ($addressLabel !== '') {
                                $addressQuery->whereRaw('LOWER(label) = ?', [strtolower($addressLabel)]);
                            } else {
                                $addressQuery->whereNull('label');
                            }
                            $existingAddress = $addressQuery->first();

                            if ($existingAddress) {
                                DB::table('customer_addresses')->where('id', $existingAddress->id)->update($addressData);
                            } else {
                                DB::table('customer_addresses')->insert($addressData + [
                                    'customer_id' => $customerId,
                                    'is_default' => false,
                                    'created_at' => now(),
                                ]);
                            }
                        }

                        // Attach a category by id or name (case-insensitive)
                        $categoryInput = trim((string) ($row['category'] ?? ''));
                        if ($categoryInput !== '') {
                            $categoryQuery = \App\Models\CustomerCategory::query()
                                ->whereRaw('LOWER(name) = ?', [strtolower($categoryInput)]);
                            if (ctype_digit($categoryInput)) {
                                $categoryQuery->orWhere('id', (int) $categoryInput);
                            }
                            $category = $categoryQuery->first();
                            if ($category) {
                                DB::table('customer_category')->updateOrInsert([
                                    'customer_id' => $customerId,
                                    'customer_category_id' => $category->id,
                                ]);
                            }
                        }

                        // Create a contact person when a contact name is supplied
                        $contactName = trim((string) ($row['contact_name'] ?? ''));
                        if ($contactName !== '') {
                            $contactData = ['name' => $contactName, 'updated_at' => now()];
                            foreach ([
                                'contact_job_title' => 'job_title',
                                'contact_category' => 'contact_category',
                                'contact_type' => 'contact_type',
                                'contact_email' => 'email',
                                'contact_phone' => 'phone',
                                'contact_mobile' => 'mobile',
                                'contact_street' => 'street',
                                'contact_street2' => 'street2',
                                'contact_city' => 'city',
                                'contact_state' => 'state',
                                'contact_zip' => 'zip',
                                'contact_country' => 'country',
                            ] as $csvField => $databaseField) {
                                if (isset($presentColumns[$csvField])) {
                                    $contactData[$databaseField] = ($row[$csvField] ?? '') !== ''
                                        ? $row[$csvField]
                                        : null;
                                }
                            }

                            $existingContact = DB::table('customer_contacts')
                                ->where('customer_id', $customerId)
                                ->whereRaw('LOWER(name) = ?', [strtolower($contactName)])
                                ->first();

                            if ($existingContact) {
                                DB::table('customer_contacts')->where('id', $existingContact->id)->update($contactData);
                            } else {
                                DB::table('customer_contacts')->insert($contactData + [
                                    'customer_id' => $customerId,
                                    'is_primary' => false,
                                    'created_at' => now(),
                                ]);
                            }
                        }
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
}
