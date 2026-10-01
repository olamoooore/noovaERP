<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PricingService;
use Illuminate\Http\Request;

/**
 * Pricing resolution API (MRD pricelist-multicurrency FR-11).
 *
 * POST /pricing/resolve         — single line quote (FR-4.1)
 * POST /pricing/resolve-batch   — whole-document pricing, ≤200 lines (FR-4.6)
 *
 * Read-only and side-effect free (FR-4.5) — safe for editors, previews and
 * the storefront (P2). Cost provenance is masked unless the actor holds the
 * cost capability (FR-3.6).
 */
class PricingController extends Controller
{
    public function __construct(
        private readonly PricingService $pricing,
    ) {}

    public function resolve(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|integer',
            'quantity' => 'nullable|numeric|min:0',
            'date' => 'nullable|date',
            'customer_id' => 'nullable|exists:customers,id',
            'document_currency' => 'nullable|string|size:3',
            'price_list_id' => 'nullable|integer',
        ]);

        $result = $this->quote($validated);

        return response()->json(['data' => $result]);
    }

    public function resolveBatch(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'date' => 'nullable|date',
            'document_currency' => 'nullable|string|size:3',
            'price_list_id' => 'nullable|integer',
            'currency_code' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|gt:0',
            'base_currency_code' => 'nullable|string|size:3',
            'lines' => 'required|array|min:1|max:200',
            'lines.*.product_id' => 'required|exists:products,id',
            'lines.*.variant_id' => 'nullable|integer',
            'lines.*.quantity' => 'nullable|numeric|min:0',
        ]);

        $context = $this->pricing->documentContext([
            'customer_id' => $validated['customer_id'] ?? null,
            'date' => $validated['date'] ?? null,
            'currency_code' => $validated['document_currency'] ?? $validated['currency_code'] ?? null,
            'exchange_rate' => $validated['exchange_rate'] ?? null,
            'base_currency_code' => $validated['base_currency_code'] ?? null,
            'price_list_id' => $validated['price_list_id'] ?? null,
            'include_cost' => $this->actorCan('pricelists.cost'),
        ]);

        $results = $this->pricing->resolveBatch(
            collect($validated['lines'])->map(fn ($line) => [
                'product_id' => (int) $line['product_id'],
                'variant_id' => isset($line['variant_id']) ? (int) $line['variant_id'] : null,
                'quantity' => (float) ($line['quantity'] ?? 1),
            ])->all(),
            [
                'date' => $context['date'],
                'customer_id' => $context['customer_id'],
                'legal_entity_id' => $context['legal_entity_id'],
                'document_currency' => $context['document_currency'],
                'base_currency' => $context['base_currency'],
                'price_list_id' => $context['price_list_id'],
                'include_cost' => $context['include_cost'],
            ]
        );

        $appliedList = $this->pricing->resolvePriceList(
            $context['customer_id'],
            $context['document_currency'],
            $context['date'],
            $context['price_list_id'],
        );

        return response()->json([
            'data' => [
                'currency_code' => $context['document_currency'],
                'base_currency_code' => $context['base_currency'],
                'exchange_rate' => $context['exchange_rate'],
                'rate_date' => $context['rate']['rate_date'] ?? $context['date'],
                'rate_source' => $context['rate']['rate_source'] ?? null,
                'rate_id' => $context['rate']['rate_id'] ?? null,
                'rate_stale' => (bool) ($context['rate']['rate_stale'] ?? false),
                'price_list_id' => $appliedList?->id,
                'price_list_name' => $appliedList?->name,
                'price_list_currency' => $appliedList?->currency_code,
                'lines' => array_values(array_map(function ($quote, $line) {
                    if (isset($quote['error'])) {
                        return ['product_id' => $line['product_id'] ?? null, 'error' => $quote['error']];
                    }

                    return $this->presentQuote($quote, $line);
                }, $results, $validated['lines'])),
            ],
        ]);
    }

    private function quote(array $validated): array
    {
        $quote = $this->pricing->resolvePrice([
            'product_id' => (int) $validated['product_id'],
            'variant_id' => isset($validated['variant_id']) ? (int) $validated['variant_id'] : null,
            'quantity' => (float) ($validated['quantity'] ?? 1),
            'date' => $validated['date'] ?? null,
            'customer_id' => isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
            'document_currency' => $validated['document_currency'] ?? null,
            'price_list_id' => isset($validated['price_list_id']) ? (int) $validated['price_list_id'] : null,
            'include_cost' => $this->actorCan('pricelists.cost'),
        ]);

        return $this->presentQuote($quote, $validated);
    }

    private function presentQuote(array $quote, array $line = []): array
    {
        return [
            'product_id' => $line['product_id'] ?? null,
            'unit_price' => (float) $quote['unit_price'],
            'base_unit_price' => (float) $quote['base_unit_price'],
            'currency' => $quote['currency'],
            'base_currency' => $quote['base_currency'],
            'exchange_rate' => $quote['exchange_rate'],
            'rate_date' => $quote['rate_date'],
            'source' => $quote['source'],
            'list_price' => $quote['list_price'],
            'pricing_trace' => $quote['price_source'],
        ];
    }

    private function actorCan(string $permission): bool
    {
        $user = \Illuminate\Support\Facades\Auth::user();

        if (! $user) {
            return false;
        }

        if ($user->is_super_admin ?? false) {
            return true;
        }

        try {
            return app(\App\Services\TenantRoleService::class)
                ->hasPermission((int) $user->id, (string) tenant('id'), $permission);
        } catch (\Throwable) {
            return false;
        }
    }
}
