<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * #486: the tenant payment-method master. The payment forms build their
 * method dropdowns from the active list instead of a hardcoded 5-item array.
 *
 * #545 (SET-UX1): Sales Settings → Payments edits the master. Only the
 * fields the live payment path already reads are editable — is_active and
 * requires_reference (PaymentController::store/update), label (the payment
 * forms) and default_bank_account_id (PaymentPostingService's deposit-to
 * fallback). code and mapping_key stay owned by the seeders/country packs,
 * so an edit can never point a method at a GL mapping nobody configured.
 */
class PaymentMethodsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PaymentMethod::query();

        // #545: the settings screen also lists switched-off methods so they
        // can be switched back on; the payment forms keep the active list.
        if (! $request->boolean('include_inactive')) {
            $query->active();
        }

        return response()->json([
            'data' => $query->orderBy('sort_order')->orderBy('code')->get(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $method = PaymentMethod::query()->findOrFail($id);

        $validated = $request->validate([
            'label' => 'sometimes|required|string|max:100',
            'is_active' => 'sometimes|boolean',
            'requires_reference' => 'sometimes|boolean',
            'default_bank_account_id' => 'sometimes|nullable|integer|exists:bank_accounts,id',
        ]);

        // Switching off the last active method would make PaymentController
        // fall back to its legacy hardcoded list — the opposite of what the
        // tenant asked for — so refuse it.
        if (array_key_exists('is_active', $validated)
            && ! $validated['is_active']
            && $method->is_active
            && PaymentMethod::query()->active()->whereKeyNot($method->id)->doesntExist()) {
            throw ValidationException::withMessages([
                'is_active' => 'At least one payment method must stay active.',
            ]);
        }

        $method->fill($validated)->save();

        return response()->json(['data' => $method->fresh()]);
    }
}
