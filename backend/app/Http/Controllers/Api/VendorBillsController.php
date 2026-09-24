<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\GoodsReceipt;
use App\Services\BillService;
use App\Services\LockDateValidationService;
use App\Services\ThreeWayMatchService;
use App\Services\VendorBillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class VendorBillsController extends Controller
{
    public function __construct(
        private LockDateValidationService $lockDateValidator,
        private ThreeWayMatchService $threeWayMatchService,
        private BillService $billService,
    ) {}

    /**
     * List all vendor bills
     *
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
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc');

            // Filter by status
            if ($status) {
                $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $status))));
                if (count($statuses) > 1) {
                    $query->whereIn('status', $statuses);
                } else {
                    $query->where('status', $status);
                }
            }

            // Filter by lifecycle_status
            if ($request->has('lifecycle_status')) {
                $lifecycleStatuses = array_values(array_filter(array_map('trim', explode(',', (string) $request->lifecycle_status))));
                if (count($lifecycleStatuses) > 1) {
                    $query->whereIn('lifecycle_status', $lifecycleStatuses);
                } else {
                    $query->where('lifecycle_status', $request->lifecycle_status);
                }
            }

            // Filter by vendor
            if ($vendorId) {
                $query->where('vendor_id', $vendorId);
            }

            // Filter by document tag.
            if ($request->filled('tag')) {
                $taggedIds = app(\App\Services\TagService::class)
                    ->subjectIdsForTagSlugs([$request->tag], 'bill');

                $query->whereIn('id', $taggedIds !== [] ? $taggedIds : [-1]);
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
            Log::error('Failed to fetch vendor bills: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch vendor bills',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single vendor bill
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $bill = Bill::with(['vendor', 'lines.account', 'journalEntry', 'purchaseOrder', 'goodsReceipt'])
                ->find($id);

            if (! $bill) {
                return response()->json([
                    'message' => 'Vendor bill not found',
                ], 404);
            }

            return response()->json([
                'data' => $bill,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch vendor bill: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch vendor bill',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new vendor bill
     *
     * Delegates to BillService::createBill() — canonical bill creation owner.
     * bill_type enforcement (FIX-02) and three-way match enforcement (FIX-02/FIX-03)
     * are handled by BillService.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'bill_number' => 'nullable|string|unique:bills,bill_number',
                'vendor_id' => 'required|exists:vendors,id',
                'bill_type' => 'nullable|string|in:procurement,expense',
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
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Lock-date check: block creating bill dated in a closed period
            $lockResult = $this->lockDateValidator->validateTransactionDate('purchases', $request->input('bill_date'));
            if ($lockResult['is_locked']) {
                return response()->json([
                    'message' => "Cannot create bill dated in a closed period. {$lockResult['message']}",
                    'lock_date' => $lockResult['lock_date'],
                    'transaction_date' => $request->input('bill_date'),
                ], 422);
            }

            // BillService owns GRN/PO consistency, bill_type enforcement,
            // line creation, total calculation, and three-way match (WS-07 FIX-02/FIX-03).
            $bill = $this->billService->createBill(
                $request->only([
                    'bill_number', 'vendor_id', 'bill_type', 'purchase_order_id',
                    'goods_receipt_id', 'bill_date', 'due_date', 'vendor_reference',
                    'internal_reference', 'attention_to', 'contact_email', 'contact_phone',
                    'vendor_address_snapshot', 'remittance_address_snapshot',
                    'company_registration_number', 'tax_registration_number',
                    'notes', 'terms', 'discount_amount', 'lines',
                ]),
                TenantUserHelper::resolveTenantUserId() ?? 0,
            );

            return response()->json([
                'message' => 'Vendor bill created successfully',
                'data' => $bill,
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Failed to create vendor bill: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to create vendor bill',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a vendor bill
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $bill = Bill::find($id);

            if (! $bill) {
                return response()->json([
                    'message' => 'Vendor bill not found',
                ], 404);
            }

            // Immutability guard: posted/paid/cancelled bills cannot be edited directly.
            // Use the dedicated service methods (postBill, cancelBill) for status transitions.
            if (in_array($bill->status, ['posted', 'paid', 'cancelled'])) {
                return response()->json([
                    'message' => "Cannot edit a vendor bill with status '{$bill->status}'. ".
                                 'Use the dedicated post/cancel endpoints for status transitions.',
                ], 422);
            }

            // Validate request — 'status' is intentionally excluded: status transitions
            // must go through VendorBillService state-machine methods, not direct mutation.
            $validator = Validator::make($request->all(), [
                'bill_number' => 'nullable|string|unique:bills,bill_number,'.$id,
                'vendor_id' => 'nullable|exists:vendors,id',
                'purchase_order_id' => 'nullable|exists:purchase_orders,id',
                'goods_receipt_id' => 'nullable|exists:goods_receipts,id',
                'bill_type' => 'nullable|string|in:procurement,expense',
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
                    'errors' => $validator->errors(),
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

            // ── Bill type inference (mirrors BillService::createBill) ─────────────
            $billType = $request->input('bill_type', $bill->bill_type ?? 'expense');
            if ($resolvedPurchaseOrderId || $resolvedGoodsReceiptId) {
                $billType = 'procurement';
            }
            if ($billType === 'procurement' && ! $resolvedPurchaseOrderId && ! $resolvedGoodsReceiptId) {
                DB::rollBack();

                return response()->json([
                    'message' => "A bill with bill_type 'procurement' must be linked to a purchase_order_id or goods_receipt_id.",
                ], 422);
            }

            // ── Expense line validation ───────────────────────────────────────────
            $linePayloads = $request->has('lines') ? $request->input('lines', []) : null;
            if ($billType === 'expense' && $linePayloads !== null) {
                foreach ($linePayloads as $idx => $lineData) {
                    if (empty(trim($lineData['description'] ?? ''))) {
                        DB::rollBack();

                        return response()->json([
                            'message' => 'Validation failed',
                            'errors' => ["lines.{$idx}.description" => ['Expense bill lines require a description since they have no product linkage.']],
                        ], 422);
                    }
                    if (! empty($lineData['purchase_order_item_id']) || ! empty($lineData['goods_receipt_line_id'])) {
                        DB::rollBack();

                        return response()->json([
                            'message' => 'Validation failed',
                            'errors' => ["lines.{$idx}" => ['Expense bill lines cannot carry PO/GRN linkage.']],
                        ], 422);
                    }
                    if (! empty($lineData['product_id'])) {
                        DB::rollBack();

                        return response()->json([
                            'message' => 'Validation failed',
                            'errors' => ["lines.{$idx}.product_id" => ['Expense bill lines should not reference products. Use a GL account and description instead.']],
                        ], 422);
                    }
                }
            }

            $purchaseOrder = $resolvedPurchaseOrderId
                ? PurchaseOrder::with('items')->findOrFail((int) $resolvedPurchaseOrderId)
                : null;

            if (isset($goodsReceipt)) {
                $goodsReceipt->loadMissing('lines');
            }

            // Reuse $linePayloads from expense validation above, or resolve for existing lines.
            if ($linePayloads === null) {
                $linePayloads = $bill->lines()
                    ->get(['purchase_order_item_id', 'goods_receipt_line_id'])
                    ->map(fn (BillLine $line) => [
                        'purchase_order_item_id' => $line->purchase_order_item_id,
                        'goods_receipt_line_id' => $line->goods_receipt_line_id,
                    ])
                    ->all();
            }

            $lineageErrors = $this->validateProcurementLineage($linePayloads, $purchaseOrder, $resolvedGoodsReceiptId ? $goodsReceipt : null);
            if ($lineageErrors !== []) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Procurement linkage validation failed',
                    'errors' => $lineageErrors,
                ], 422);
            }

            // Update bill — 'status' is intentionally excluded from this list.
            // bill_type is set from our resolved value, not raw input (prevents bypass).
            $bill->update(array_merge(
                $request->only([
                    'bill_number',
                    'vendor_id',
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
                ]),
                [
                    'bill_type' => $billType,
                    'purchase_order_id' => $resolvedPurchaseOrderId,
                    'goods_receipt_id' => $resolvedGoodsReceiptId,
                ]
            ));

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

            // Re-validate three-way match after update (lines may have changed).
            // Uses bill_type (not PO/GRN presence) for consistency with BillService.
            if ($billType === 'procurement') {
                $bill->load(['lines', 'purchaseOrder.items.product', 'goodsReceipt.lines.product']);
                $matchResult = $this->threeWayMatchService->validate($bill);
                if (! $matchResult['valid']) {
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
                'data' => $bill,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update vendor bill: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to update vendor bill',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Post a vendor bill to the general ledger
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function post($id, VendorBillService $billService)
    {
        try {
            $bill = Bill::with(['vendor', 'lines', 'purchaseOrder.items.product', 'goodsReceipt.lines.product'])->find($id);

            if (! $bill) {
                return response()->json(['message' => 'Vendor bill not found'], 404);
            }

            // Three-way match gate: reject posting if procurement constraints are violated.
            // Uses bill_type (not PO/GRN presence) so it cannot be bypassed by omitting linkage fields.
            if ($bill->isProcurement()) {
                $matchResult = $this->threeWayMatchService->validate($bill);
                if (! $matchResult['valid']) {
                    return response()->json([
                        'message' => 'Cannot post: three-way match validation failed',
                        'errors' => $matchResult['errors'],
                        'warnings' => $matchResult['warnings'],
                    ], 422);
                }
            }

            // Expense bills: verify every line has a description before posting.
            if ($bill->isExpense()) {
                foreach ($bill->lines as $line) {
                    if (empty(trim($line->description ?? ''))) {
                        return response()->json([
                            'message' => "Cannot post: expense bill line #{$line->id} has no description.",
                        ], 422);
                    }
                }
            }

            // Lock-date check: block posting into a closed period
            $lockResult = $this->lockDateValidator->validateTransactionDate('purchases', $bill->bill_date);
            if ($lockResult['is_locked']) {
                return response()->json([
                    'message' => "Cannot post bill dated in a closed period. {$lockResult['message']}",
                    'lock_date' => $lockResult['lock_date'],
                    'transaction_date' => $bill->bill_date?->format('Y-m-d'),
                ], 422);
            }

            $result = $billService->postBill($bill);

            if (! $result->success) {
                $errorMessage = $result->error;

                // Surface account-mapping errors with actionable guidance
                if (str_contains($errorMessage, 'Cannot resolve GL account')) {
                    $errorMessage = 'Default accounts not configured. '.
                        'Please go to Company Settings > Default Accounts and map the required GL accounts before posting bills.';
                }

                return response()->json(['message' => $errorMessage], 422);
            }

            return response()->json([
                'message' => 'Vendor bill posted successfully',
                'data' => $bill->fresh(['vendor', 'lines.account', 'journalEntry']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to post vendor bill: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to post vendor bill',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve a vendor bill.
     * Only draft bills can be approved. Approval is required before posting.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function approve($id)
    {
        try {
            $bill = Bill::find($id);

            if (! $bill) {
                return response()->json(['message' => 'Vendor bill not found'], 404);
            }

            if (! is_null($bill->approved_at)) {
                return response()->json([
                    'message' => 'This bill has already been approved.',
                ], 422);
            }

            if ($bill->status !== 'draft') {
                return response()->json([
                    'message' => "Only draft bills can be approved. Current status: {$bill->status}",
                ], 422);
            }

            $bill->update([
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            return response()->json([
                'message' => 'Vendor bill approved successfully',
                'data' => $bill->fresh(['vendor', 'lines', 'approvedBy']),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to approve vendor bill: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to approve vendor bill',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject a vendor bill approval.
     * Resets an approved bill back to draft.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function reject($id)
    {
        try {
            $bill = Bill::find($id);

            if (! $bill) {
                return response()->json(['message' => 'Vendor bill not found'], 404);
            }

            if (is_null($bill->approved_at)) {
                return response()->json([
                    'message' => 'Only approved bills can be rejected.',
                ], 422);
            }

            $bill->update([
                'approved_by' => null,
                'approved_at' => null,
            ]);

            return response()->json([
                'message' => 'Vendor bill approval rejected',
                'data' => $bill->fresh(['vendor', 'lines']),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to reject vendor bill: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to reject vendor bill',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get eligible vendor bills for recording a payment.
     * Only posted or partially paid bills with a positive balance are eligible.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function eligibleBills(Request $request)
    {
        try {
            $request->validate([
                'vendor_id' => 'nullable|integer|exists:vendors,id',
            ]);

            $query = Bill::with('vendor')
                ->whereIn('status', ['posted', 'partial', 'overdue'])
                // Issue #96: a voided/reversed/superseded bill keeps its legacy
                // status+balance, but must never be selectable for payment.
                ->notSuperseded()
                ->where(function ($q) {
                    $q->whereNull('balance')
                        ->orWhere('balance', '>', 0);
                })
                ->orderBy('bill_date', 'desc');

            if ($request->has('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            $bills = $query->get()->map(function ($bill) {
                return [
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'bill_date' => $bill->bill_date,
                    'total' => (float) $bill->total,
                    'balance' => (float) ($bill->balance ?? $bill->total),
                    'status' => $bill->status,
                    'vendor_id' => $bill->vendor_id,
                    'vendor_name' => $bill->vendor?->name ?? 'Unknown',
                ];
            });

            if ($bills->isEmpty()) {
                return response()->json([
                    'data' => [],
                    'message' => 'No eligible bills found. Only posted unpaid bills for the selected vendor can be paid.',
                ]);
            }

            return response()->json([
                'data' => $bills,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch eligible bills: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch eligible bills',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a vendor bill
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $bill = Bill::find($id);

            if (! $bill) {
                return response()->json([
                    'message' => 'Vendor bill not found',
                ], 404);
            }

            // Issue #171 (CRITICAL): the old guard only blocked
            // `status === 'posted'`, but a bill progresses to 'partial' (once
            // any payment is applied) and 'overdue' (posted, unpaid, past due)
            // — both real post-posting states with a live GL journal entry.
            // Deleting those orphaned the AP journal entry (unrecoverable
            // AP-subledger-to-GL break) and, for 'partial' bills, leaked a raw
            // SQL FK-restrict error instead of a clean 422.
            //
            // Fix: invert to a WHITELIST of the one truly-safe-to-delete state
            // ('draft'), matching InvoiceController::destroy() on the AR side.
            // Use void/reversal for any posted-family bill.
            if ($bill->status !== 'draft') {
                return response()->json([
                    'message' => 'Only draft bills can be deleted. Use void/reversal for posted bills.',
                ], 422);
            }

            // Defence in depth: even a draft bill must not be deletable if a
            // payment somehow exists against it (avoids the FK-restrict raw
            // error leak and any AP/GL inconsistency).
            if ($bill->payments()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete a bill that has payments applied. Use void/reversal instead.',
                ], 422);
            }

            DB::beginTransaction();

            // Delete lines
            $bill->lines()->delete();

            // Delete bill
            $bill->delete();

            DB::commit();

            return response()->json([
                'message' => 'Vendor bill deleted successfully',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to delete vendor bill: '.$e->getMessage());

            // Issue #171: do not leak raw SQL/constraint messages to the caller.
            return response()->json([
                'message' => 'Failed to delete vendor bill',
            ], 500);
        }
    }

    /**
     * When a bill is linked to a PO/GRN, every line must keep explicit lineage
     * back to the linked procurement documents instead of being treated as a
     * generic AP line.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, array<int, string>>
     */
    private function validateProcurementLineage(array $lines, ?PurchaseOrder $purchaseOrder, ?GoodsReceipt $goodsReceipt): array
    {
        if (! $purchaseOrder && ! $goodsReceipt) {
            return [];
        }

        $errors = [];

        foreach (array_values($lines) as $index => $lineData) {
            $grnLine = null;
            $goodsReceiptLineId = isset($lineData['goods_receipt_line_id']) && $lineData['goods_receipt_line_id'] !== ''
                ? (int) $lineData['goods_receipt_line_id']
                : null;
            $purchaseOrderItemId = isset($lineData['purchase_order_item_id']) && $lineData['purchase_order_item_id'] !== ''
                ? (int) $lineData['purchase_order_item_id']
                : null;

            if ($goodsReceipt) {
                if (! $goodsReceiptLineId) {
                    $errors["lines.{$index}.goods_receipt_line_id"][] = 'A goods receipt line reference is required when goods_receipt_id is provided.';
                } else {
                    $grnLine = $goodsReceipt->lines->firstWhere('id', $goodsReceiptLineId);

                    if (! $grnLine) {
                        $errors["lines.{$index}.goods_receipt_line_id"][] = 'The selected goods receipt line does not belong to the linked goods receipt.';
                    }
                }
            }

            if ($purchaseOrder) {
                if (! $purchaseOrderItemId) {
                    $errors["lines.{$index}.purchase_order_item_id"][] = 'A purchase order item reference is required when purchase_order_id is provided.';
                } elseif (! $purchaseOrder->items->firstWhere('id', $purchaseOrderItemId)) {
                    $errors["lines.{$index}.purchase_order_item_id"][] = 'The selected purchase order item does not belong to the linked purchase order.';
                }

                if (
                    $purchaseOrderItemId
                    && $grnLine
                    && $grnLine->purchase_order_item_id
                    && $purchaseOrderItemId !== (int) $grnLine->purchase_order_item_id
                ) {
                    $errors["lines.{$index}.purchase_order_item_id"][] = 'The purchase order item does not match the selected goods receipt line.';
                }
            }
        }

        return $errors;
    }
}
