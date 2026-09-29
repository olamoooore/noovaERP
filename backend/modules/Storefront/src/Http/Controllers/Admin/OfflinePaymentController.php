<?php

namespace Modules\Storefront\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\Storefront\Exceptions\CheckoutException;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontPaymentProof;
use Modules\Storefront\Services\StorefrontOfflinePaymentService;

/**
 * Offline settlement review console (PAY-R13, FR-ADM-04). Approving does NOT
 * mark anything paid by fiat: it drives the canonical completion chain with
 * an internal offline transaction record, so the machine, ledger and
 * notification behavior are identical to a gateway-paid order.
 */
final class OfflinePaymentController extends Controller
{
    public function __construct(
        private readonly StorefrontOfflinePaymentService $offline,
    ) {}

    public function review(Request $request, int $order): JsonResponse
    {
        $validated = $request->validate([
            'proof_id' => ['required', 'integer'],
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $model = StorefrontOrder::query()->find($order);
        if ($model === null) {
            return response()->json(['message' => 'Order not found.', 'code' => 'not_found'], 404);
        }

        try {
            $out = $this->offline->review(
                $model,
                (int) $validated['proof_id'],
                (string) $validated['decision'],
                isset($validated['note']) ? (string) $validated['note'] : null,
                Auth::id() !== null ? 'user:'.Auth::id() : null,
            );
        } catch (CheckoutException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json(['data' => [
            'proof_id' => $out['proof']->id,
            'proof_status' => $out['proof']->status,
            'order_id' => $out['order']->id,
            'status' => $out['order']->status,
            'payment_status' => $out['order']->payment_status,
            'completed' => $out['completed'],
        ]]);
    }

    /**
     * Proof download — permission-gated (storefront.write). Private disk, so
     * bank evidence never gets a guessable public path.
     */
    public function file(int $order, int $proof): Response
    {
        $model = StorefrontOrder::query()->find($order);
        if ($model === null) {
            return response()->json(['message' => 'Order not found.', 'code' => 'not_found'], 404);
        }

        $record = StorefrontPaymentProof::query()
            ->where('order_id', (int) $model->id)
            ->whereKey($proof)
            ->first();

        if ($record === null || ! Storage::disk('local')->exists($record->file_path)) {
            return response()->json(['message' => 'Proof file not found.', 'code' => 'not_found'], 404);
        }

        return response(Storage::disk('local')->get($record->file_path), 200, [
            'Content-Type' => $record->mime_type,
            'Content-Disposition' => 'attachment; filename="'.basename((string) $record->file_name).'"',
        ]);
    }
}
