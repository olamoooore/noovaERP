<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\GoodsReceipt;
use App\Services\ThreeWayMatchService;
use App\Services\VendorBillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

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

            $query = Bill::with(['vendor', 'lines', 'purchaseOrder', 'goodsReceipt'])
                ->orderBy('bill_date', 'desc');

            // Filter by status
            if ($status) {
                $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $status))));
                if (count($statuses) > 1) {
                    $query->whereIn('status', $statuses);
                } else {
                    $query->where('status', $status);
                }
            }

            // Filter by vendor
            if ($vendorId) {
                $query->where('vendor_id', $vendorId);
            }

            // Search filter
            if ($request->has('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('bill_number', 'ILIKE', "%{$search}%")
                      ->orWhereHas('vendor', function ($vq) use ($search) {
                          $vq->where('name', 'ILIKE', "%{$search}%");
                      });
                });
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
            $bill = Bill::with(['vendor', 'lines.account', 'journalEntry', 'purchaseOrder', 'goodsReceipt'])
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

    public function __construct(
        private readonly ThreeWayMatchService $threeWayMatchService,
    ) {}

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
                'purchase_order_id' => 'nullable|exists:purchase_orders,id',
                'goods_receipt_id' => 'nullable|exists:goods_receipts,id',
                'bill_date' => 'required|date',
                'due_date' => 'nullable|date',
                'vendor_reference' => 'nullable|string|max:100',
                'internal_reference' => 'nullable|string|max:100',
                'attention_to' => 'nullable|string|max:255',
                'contact_email' => 'nullable|email|max:255',
                'contact_phone' => 'nullable|string|max:50',
                'vendor_address_snapshot' => 'nullable|string',
                'remittance_address_snapshot' => 'nullable|string',
                'company_registration_number' => 'nullable|string|max:100',
                'tax_registration_number' => 'nullable|string|max:100',
                'notes' => 'nullable|string',
                'terms' => 'nullable|string',
                'discount_amount' => 'nullable|numeric|min:0',
                'lines' => 'required|array|min:1',
                'lines.*.account_id' => 'required|exists:chart_of_accounts,id',
                'lines.*.description' => 'nullable|string',
                'lines.*.quantity' => 'required|numeric|min:0',
                'lines.*.unit_price' => 'required|numeric',
                'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
                'lines.*.purchase_order_item_id' => 'nullable|integer',
                'lines.*.goods_receipt_line_id' => 'nullable|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $goodsReceipt = null;
            if ($request->filled('goods_receipt_id')) {
                $goodsReceipt = GoodsReceipt::findOrFail((int) $request->goods_receipt_id);
            }

            $resolvedPurchaseOrderId = $request->purchase_order_id;
            if ($goodsReceipt) {
                if ($resolvedPurchaseOrderId && (int) $resolvedPurchaseOrderId !== (int) $goodsReceipt->purchase_order_id) {
                    return response()->json([
                        'message' => 'goods_receipt_id does not belong to the provided purchase_order_id',
                    ], 422);
                }
                $resolvedPurchaseOrderId = $goodsReceipt->purchase_order_id;
            }

            // Run three-way match validation if PO or GRN is linked
            // NOTE: Deferred to after bill+lines creation so the service can inspect real lines.
            // See validation block below (after line creation).

            // Create bill
            $bill = Bill::create([
                'bill_number' => $request->bill_number,
                'vendor_id' => $request->vendor_id,
                'purchase_order_id' => $resolvedPurchaseOrderId,
                'goods_receipt_id' => $goodsReceipt?->id,
                'bill_date' => $request->bill_date,
                'due_date' => $request->due_date,
                'vendor_reference' => $request->vendor_reference,
                'internal_reference' => $request->internal_reference,
                'attention_to' => $request->attention_to,
                'contact_email' => $request->contact_email,
                'contact_phone' => $request->contact_phone,
                'vendor_address_snapshot' => $request->vendor_address_snapshot,
                'remittance_address_snapshot' => $request->remittance_address_snapshot,
                'company_registration_number' => $request->company_registration_number,
                'tax_registration_number' => $request->tax_registration_number,
                'status' => 'draft',
                'notes' => $request->notes,
                'terms' => $request->terms,
                'discount_amount' => $request->discount_amount ?? 0,
                'subtotal' => 0,
                'tax_amount' => 0,
                'total' => 0,
                'paid_amount' => 0,
                'balance' => 0,
                'created_by' => TenantUserHelper::resolveTenantUserId(),
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
                    'purchase_order_item_id' => $lineData['purchase_order_item_id'] ?? null,
                    'goods_receipt_line_id' => $lineData['goods_receipt_line_id'] ?? null,
                ]);
            }

            // Calculate totals
            $bill->load('lines');
            $bill->subtotal = $bill->lines->sum('line_total');
            $bill->tax_amount = $bill->lines->sum('tax_amount');
            $bill->total = $bill->subtotal + $bill->tax_amount - $bill->discount_amount;
            $bill->balance = $bill->total - $bill->paid_amount;
            $bill->save();

            // Run three-way match validation AFTER lines exist so the service
            // can actually compare bill line quantities/prices against PO/GRN.
            if ($bill->purchase_order_id || $bill->goods_receipt_id) {
                $bill->load(['purchaseOrder.items.product', 'goodsReceipt.lines.product']);
                $matchResult = $this->threeWayMatchService->validate($bill);
                if (!$matchResult['valid']) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Three-way match validation failed',
                        'errors' => $matchResult['errors'],
                        'warnings' => $matchResult['warnings'],
                    ], 422);
                }
            }

            DB::commit();

            // Reload with relationships
            $bill->load(['vendor', 'lines.account', 'purchaseOrder', 'goodsReceipt']);

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

            // Immutability guard: posted/paid/cancelled bills cannot be edited directly.
            // Use the dedicated service methods (postBill, cancelBill) for status transitions.
            if (in_array($bill->status, ['posted', 'paid', 'cancelled'])) {
                return response()->json([
                    'message' => "Cannot edit a vendor bill with status '{$bill->status}'. " .
                                 'Use the dedicated post/cancel endpoints for status transitions.',
                ], 422);
            }

            // Validate request — 'status' is intentionally excluded: status transitions
            // must go through VendorBillService state-machine methods, not direct mutation.
            $validator = Validator::make($request->all(), [
                'bill_number' => 'nullable|string|unique:bills,bill_number,' . $id,
                'vendor_id' => 'nullable|exists:vendors,id',
                'purchase_order_id' => 'nullable|exists:purchase_orders,id',
                'goods_receipt_id' => 'nullable|exists:goods_receipts,id',
                'bill_date' => 'nullable|date',
                'due_date' => 'nullable|date',
                'vendor_reference' => 'nullable|string|max:100',
                'internal_reference' => 'nullable|string|max:100',
                'attention_to' => 'nullable|string|max:255',
                'contact_email' => 'nullable|email|max:255',
                'contact_phone' => 'nullable|string|max:50',
                'vendor_address_snapshot' => 'nullable|string',
                'remittance_address_snapshot' => 'nullable|string',
                'company_registration_number' => 'nullable|string|max:100',
                'tax_registration_number' => 'nullable|string|max:100',
                'notes' => 'nullable|string',
                'terms' => 'nullable|string',
                'discount_amount' => 'nullable|numeric|min:0',
                'lines' => 'nullable|array',
                'lines.*.account_id' => 'required_with:lines|exists:chart_of_accounts,id',
                'lines.*.description' => 'nullable|string',
                'lines.*.quantity' => 'required_with:lines|numeric|min:0',
                'lines.*.unit_price' => 'required_with:lines|numeric',
                'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
                'lines.*.purchase_order_item_id' => 'nullable|integer',
                'lines.*.goods_receipt_line_id' => 'nullable|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $resolvedPurchaseOrderId = $request->has('purchase_order_id') ? $request->purchase_order_id : $bill->purchase_order_id;
            $resolvedGoodsReceiptId = $request->has('goods_receipt_id') ? $request->goods_receipt_id : $bill->goods_receipt_id;

            if ($resolvedGoodsReceiptId) {
                $goodsReceipt = GoodsReceipt::findOrFail((int) $resolvedGoodsReceiptId);
                if ($resolvedPurchaseOrderId && (int) $resolvedPurchaseOrderId !== (int) $goodsReceipt->purchase_order_id) {
                    return response()->json([
                        'message' => 'goods_receipt_id does not belong to the provided purchase_order_id',
                    ], 422);
                }
                $resolvedPurchaseOrderId = $goodsReceipt->purchase_order_id;
            }

            // Update bill — 'status' is intentionally excluded from this list.
            $bill->update($request->only([
                'bill_number',
                'vendor_id',
                'purchase_order_id',
                'goods_receipt_id',
                'bill_date',
                'due_date',
                'vendor_reference',
                'internal_reference',
                'attention_to',
                'contact_email',
                'contact_phone',
                'vendor_address_snapshot',
                'remittance_address_snapshot',
                'company_registration_number',
                'tax_registration_number',
                'notes',
                'terms',
                'discount_amount',
            ]));

            $bill->update([
                'purchase_order_id' => $resolvedPurchaseOrderId,
                'goods_receipt_id' => $resolvedGoodsReceiptId,
            ]);

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
                        'purchase_order_item_id' => $lineData['purchase_order_item_id'] ?? null,
                        'goods_receipt_line_id' => $lineData['goods_receipt_line_id'] ?? null,
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

            // Re-validate three-way match after update (lines may have changed)
            if ($bill->purchase_order_id || $bill->goods_receipt_id) {
                $bill->load(['lines', 'purchaseOrder.items.product', 'goodsReceipt.lines.product']);
                $matchResult = $this->threeWayMatchService->validate($bill);
                if (!$matchResult['valid']) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Three-way match validation failed',
                        'errors' => $matchResult['errors'],
                        'warnings' => $matchResult['warnings'],
                    ], 422);
                }
            }

            DB::commit();

            // Reload with relationships
            $bill->load(['vendor', 'lines.account', 'purchaseOrder', 'goodsReceipt']);

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
            $bill = Bill::with(['vendor', 'lines', 'purchaseOrder.items.product', 'goodsReceipt.lines.product'])->find($id);

            if (!$bill) {
                return response()->json(['message' => 'Vendor bill not found'], 404);
            }

            // Three-way match gate: reject posting if PO/GRN constraints are violated.
            if ($bill->purchase_order_id || $bill->goods_receipt_id) {
                $matchResult = $this->threeWayMatchService->validate($bill);
                if (!$matchResult['valid']) {
                    return response()->json([
                        'message' => 'Cannot post: three-way match validation failed',
                        'errors' => $matchResult['errors'],
                        'warnings' => $matchResult['warnings'],
                    ], 422);
                }
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
