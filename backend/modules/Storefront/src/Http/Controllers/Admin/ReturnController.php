<?php

namespace Modules\Storefront\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Storefront\Exceptions\CheckoutException;
use Modules\Storefront\Models\StorefrontReturn;
use Modules\Storefront\Services\StorefrontReturnService;

/**
 * Return desk for tenant staff (FR-ADM-01/FR-ORD-06, Appendix E §E4.4
 * `/…/returns` + approve|reject|receive): every decision routes through
 * StorefrontReturnService — the money leg is the canonical refund chain,
 * the stock leg the canonical InventoryService, the ledger the engine.
 */
final class ReturnController extends Controller
{
    public function __construct(
        private readonly StorefrontReturnService $returns,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = StorefrontReturn::query()->with('order:id,order_number,total,currency')->latest('id');

        if (($status = $request->string('status')->toString()) !== '' && in_array($status, StorefrontReturn::STATUSES, true)) {
            $query->where('status', $status);
        }

        $page = $query->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json([
            'data' => collect($page->items())->map($this->present(...)),
            'meta' => ['page' => $page->currentPage(), 'total' => $page->total()],
        ]);
    }

    public function approve(Request $request, int $return): JsonResponse
    {
        $validated = $request->validate([
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->call(fn () => $this->returns->approve(
            $this->find($return),
            isset($validated['refund_amount']) ? (float) $validated['refund_amount'] : null,
            $validated['note'] ?? null,
            'user:'.Auth::id(),
        ));
    }

    public function reject(Request $request, int $return): JsonResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->call(fn () => $this->returns->reject(
            $this->find($return),
            $validated['note'] ?? null,
            'user:'.Auth::id(),
        ));
    }

    public function receive(int $return): JsonResponse
    {
        return $this->call(fn () => $this->returns->receive($this->find($return), 'user:'.Auth::id()));
    }

    public function refund(int $return): JsonResponse
    {
        return $this->call(fn () => $this->returns->refund($this->find($return), Auth::id() !== null ? (int) Auth::id() : null, 'user:'.Auth::id()));
    }

    public function close(Request $request, int $return): JsonResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->call(fn () => $this->returns->close($this->find($return), $validated['note'] ?? null, 'user:'.Auth::id()));
    }

    private function find(int $id): StorefrontReturn
    {
        $model = StorefrontReturn::query()->find($id);
        if ($model === null) {
            abort(404, 'Return not found.');
        }

        return $model;
    }

    private function call(callable $fn): JsonResponse
    {
        try {
            $ret = $fn();
        } catch (CheckoutException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        $ret->loadMissing('order:id,status,payment_status');

        return response()->json(['data' => array_merge($this->present($ret), [
            'order_status' => $ret->order?->status,
            'order_payment_status' => $ret->order?->payment_status,
        ])]);
    }

    private function present(StorefrontReturn $r): array
    {
        return [
            'id' => $r->id,
            'order_id' => $r->order_id,
            'order_number' => $r->order?->order_number,
            'status' => $r->status,
            'reason' => $r->reason,
            'notes' => $r->notes,
            'refund_amount' => (string) $r->refund_amount,
            'currency' => $r->order?->currency,
            'line_items' => $r->line_items,
            'reviewed_by' => $r->reviewed_by,
            'requested_at' => optional($r->created_at)->toIso8601String(),
            'received_at' => optional($r->received_at)->toIso8601String(),
            'refunded_at' => optional($r->refunded_at)->toIso8601String(),
        ];
    }
}
