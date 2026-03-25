<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillLine;
use App\Services\VendorBillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class VendorBillsController extends Controller
{
    /**
     * List all vendor bills
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);
            $status = $request->get('status');
            $vendorId = $request->get('vendor_id');

            $query = Bill::with(['vendor', 'lines'])
                ->orderBy('bill_date', 'desc');

            // Filter by status
            if ($status) {
                $query->where('status', $status);
            }

            // Filter by vendor
            if ($vendorId) {
                $query->where('vendor_id', $vendorId);
            }

            // Get total count
            $total = $query->count();

            // Get paginated results
            $bills = $query
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            return response()->json([
                'data' => $bills,
                'meta' => [
                    'current_page' => (int) $page,
                    'per_page' => (int) $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch vendor bills: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to fetch vendor bills',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single vendor bill
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $bill = Bill::with(['vendor', 'lines.account', 'journalEntry'])
                ->find($id);

            if (!$bill) {
                return response()->json([
                    'message' => 'Vendor bill not found'
                ], 404);
            }

            return response()->json([
                'data' => $bill
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch vendor bill: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to fetch vendor bill',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new vendor bill
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'bill_number' => 'nullable|string|unique:bills,bill_number',
                'vendor_id' => 'required|exists:vendors,id',
                'bill_date' => 'required|date',
                'due_date' => 'nullable|date',
                'status' => 'nullable|in:draft,posted,paid,overdue,cancelled',
                'notes' => 'nullable|string',
                'terms' => 'nullable|string',
                'discount_amount' => 'nullable|numeric|min:0',
                'lines' => 'required|array|min:1',
                'lines.*.account_id' => 'required|exists:chart_of_accounts,id',
                'lines.*.description' => 'nullable|string',
                'lines.*.quantity' => 'required|numeric|min:0',
                'lines.*.unit_price' => 'required|numeric',
                'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Create bill
            $bill = Bill::create([
                'bill_number' => $request->bill_number,
                'vendor_id' => $request->vendor_id,
                'bill_date' => $request->bill_date,
                'due_date' => $request->due_date,
                'status' => $request->status ?? 'draft',
                'notes' => $request->notes,
                'terms' => $request->terms,
                'discount_amount' => $request->discount_amount ?? 0,
                'subtotal' => 0,
                'tax_amount' => 0,
                'total' => 0,
                'paid_amount' => 0,
                'balance' => 0,
                'created_by' => auth()->id(),
            ]);

            // Create bill lines
            foreach ($request->lines as $lineData) {
                $quantity = $lineData['quantity'];
                $unitPrice = $lineData['unit_price'];
                $taxRate = $lineData['tax_rate'] ?? 0;
                
                $lineTotal = $quantity * $unitPrice;
                $taxAmount = $lineTotal * ($taxRate / 100);

                BillLine::create([
                    'bill_id' => $bill->id,
                    'account_id' => $lineData['account_id'],
                    'description' => $lineData['description'] ?? null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                ]);
            }

            // Calculate totals
            $bill->load('lines');
            $bill->subtotal = $bill->lines->sum('line_total');
            $bill->tax_amount = $bill->lines->sum('tax_amount');
            $bill->total = $bill->subtotal + $bill->tax_amount - $bill->discount_amount;
            $bill->balance = $bill->total - $bill->paid_amount;
            $bill->save();

            DB::commit();

            // Reload with relationships
            $bill->load(['vendor', 'lines.account']);

            return response()->json([
                'message' => 'Vendor bill created successfully',
                'data' => $bill
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create vendor bill: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to create vendor bill',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a vendor bill
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $bill = Bill::find($id);

            if (!$bill) {
                return response()->json([
                    'message' => 'Vendor bill not found'
                ], 404);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'bill_number' => 'nullable|string|unique:bills,bill_number,' . $id,
                'vendor_id' => 'nullable|exists:vendors,id',
                'bill_date' => 'nullable|date',
                'due_date' => 'nullable|date',
                'status' => 'nullable|in:draft,posted,paid,overdue,cancelled',
                'notes' => 'nullable|string',
                'terms' => 'nullable|string',
                'discount_amount' => 'nullable|numeric|min:0',
                'lines' => 'nullable|array',
                'lines.*.account_id' => 'required_with:lines|exists:chart_of_accounts,id',
                'lines.*.description' => 'nullable|string',
                'lines.*.quantity' => 'required_with:lines|numeric|min:0',
                'lines.*.unit_price' => 'required_with:lines|numeric',
                'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Update bill
            $bill->update($request->only([
                'bill_number',
                'vendor_id',
                'bill_date',
                'due_date',
                'status',
                'notes',
                'terms',
                'discount_amount',
            ]));

            // Update lines if provided
            if ($request->has('lines')) {
                // Delete existing lines
                $bill->lines()->delete();

                // Create new lines
                foreach ($request->lines as $lineData) {
                    $quantity = $lineData['quantity'];
                    $unitPrice = $lineData['unit_price'];
                    $taxRate = $lineData['tax_rate'] ?? 0;
                    
                    $lineTotal = $quantity * $unitPrice;
                    $taxAmount = $lineTotal * ($taxRate / 100);

                    BillLine::create([
                        'bill_id' => $bill->id,
                        'account_id' => $lineData['account_id'],
                        'description' => $lineData['description'] ?? null,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $taxAmount,
                        'line_total' => $lineTotal,
                    ]);
                }

                // Recalculate totals
                $bill->load('lines');
                $bill->subtotal = $bill->lines->sum('line_total');
                $bill->tax_amount = $bill->lines->sum('tax_amount');
                $bill->total = $bill->subtotal + $bill->tax_amount - $bill->discount_amount;
                $bill->balance = $bill->total - $bill->paid_amount;
                $bill->save();
            }

            DB::commit();

            // Reload with relationships
            $bill->load(['vendor', 'lines.account']);

            return response()->json([
                'message' => 'Vendor bill updated successfully',
                'data' => $bill
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update vendor bill: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update vendor bill',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Post a vendor bill to the general ledger
     *
     * @param int $id
     * @param VendorBillService $billService
     * @return \Illuminate\Http\JsonResponse
     */
    public function post($id, VendorBillService $billService)
    {
        try {
            $bill = Bill::with(['vendor', 'lines'])->find($id);

            if (!$bill) {
                return response()->json(['message' => 'Vendor bill not found'], 404);
            }

            $result = $billService->postBill($bill);

            if (!$result->success) {
                return response()->json(['message' => $result->error], 422);
            }

            return response()->json([
                'message' => 'Vendor bill posted successfully',
                'data' => $bill->fresh(['vendor', 'lines.account', 'journalEntry']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to post vendor bill: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to post vendor bill',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a vendor bill
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $bill = Bill::find($id);

            if (!$bill) {
                return response()->json([
                    'message' => 'Vendor bill not found'
                ], 404);
            }

            // Check if bill is posted
            if ($bill->status === 'posted') {
                return response()->json([
                    'message' => 'Cannot delete a posted bill'
                ], 422);
            }

            DB::beginTransaction();

            // Delete lines
            $bill->lines()->delete();

            // Delete bill
            $bill->delete();

            DB::commit();

            return response()->json([
                'message' => 'Vendor bill deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete vendor bill: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to delete vendor bill',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
