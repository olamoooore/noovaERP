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
     * @param Request $request
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
            Log::error('Failed to fetch customers: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to fetch customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single customer
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $customer = DB::table('customers')
                ->where('id', $id)
                ->first();

            if (!$customer) {
                return response()->json([
                    'message' => 'Customer not found'
                ], 404);
            }

            return response()->json([
                'data' => $customer
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch customer: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to fetch customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import customers from CSV file
     * 
     * @param Request $request
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
        if (!is_array($header) || count($header) === 0) {
            fclose($handle);
            return response()->json(['message' => 'CSV header missing'], 422);
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (!is_array($data) || count(array_filter($data, fn ($v) => (string) $v !== '')) === 0) {
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
                    DB::table('customers')->insert([
                        'name' => $name,
                        'email' => isset($row['email']) && $row['email'] !== '' ? trim((string) $row['email']) : null,
                        'phone' => isset($row['phone']) && $row['phone'] !== '' ? trim((string) $row['phone']) : null,
                        'company' => isset($row['company']) && $row['company'] !== '' ? trim((string) $row['company']) : null,
                        'billing_address' => isset($row['billing_address']) && $row['billing_address'] !== '' ? trim((string) $row['billing_address']) : null,
                        'shipping_address' => isset($row['shipping_address']) && $row['shipping_address'] !== '' ? trim((string) $row['shipping_address']) : null,
                        'credit_limit' => 0,
                        'payment_terms' => 30,
                        'total_outstanding' => 0,
                        'is_active' => true,
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
