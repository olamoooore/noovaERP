<?php

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Application\PricingEngineService;
use App\SuperAdmin\Domain\CatalogPriceRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class AdminPricingController extends Controller
{
    public function __construct(private readonly PricingEngineService $engine) {}

    // ── Prices ────────────────────────────────────────────────────────────────

    /**
     * GET /pricing/prices
     * GET /pricing/prices?catalog_item_id=5
     */
    public function listPrices(Request $request): JsonResponse
    {
        $itemId = $request->query('catalog_item_id');
        $prices = $this->engine->listPrices($itemId ? (int) $itemId : null);

        return response()->json(['data' => $prices]);
    }

    /**
     * PUT /pricing/prices
     * Upsert a single price (creates or updates).
     */
    public function upsertPrice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'catalog_item_id'  => ['required', 'integer', 'exists:catalog_items,id'],
            'billing_interval' => ['required', Rule::in(['monthly', 'yearly'])],
            'currency'         => ['required', 'string', 'size:3'],
            'unit_amount'      => ['required', 'integer', 'min:0'],
        ]);

        $price = $this->engine->upsertPrice(
            (int)    $data['catalog_item_id'],
            $data['billing_interval'],
            $data['currency'],
            (int)    $data['unit_amount'],
        );

        return response()->json(['data' => $price], 200);
    }

    /**
     * DELETE /pricing/prices/{priceId}
     */
    public function deletePrice(int $priceId): JsonResponse
    {
        $this->engine->deletePrice($priceId);

        return response()->json(['message' => 'Price deleted.']);
    }

    // ── Rules ─────────────────────────────────────────────────────────────────

    /**
     * GET /pricing/rules
     * GET /pricing/rules?catalog_item_id=5
     */
    public function listRules(Request $request): JsonResponse
    {
        $itemId = $request->query('catalog_item_id');
        $rules  = $this->engine->listRules($itemId ? (int) $itemId : null);

        return response()->json(['data' => $rules]);
    }

    /**
     * POST /pricing/rules
     */
    public function createRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'catalog_item_id' => ['nullable', 'integer', 'exists:catalog_items,id'],
            'name'            => ['required', 'string', 'max:255'],
            'rule_type'       => ['required', Rule::in(CatalogPriceRule::RULE_TYPES)],
            'condition'       => ['nullable', 'array'],
            'modifier_value'  => ['required', 'numeric', 'min:0'],
            'priority'        => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_exclusive'    => ['nullable', 'boolean'],
            'is_active'       => ['nullable', 'boolean'],
            'effective_from'  => ['nullable', 'date'],
            'effective_to'    => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $rule = $this->engine->createRule($data);

        return response()->json(['data' => $rule], 201);
    }

    /**
     * PUT /pricing/rules/{rule}
     */
    public function updateRule(Request $request, CatalogPriceRule $rule): JsonResponse
    {
        $data = $request->validate([
            'name'           => ['sometimes', 'string', 'max:255'],
            'rule_type'      => ['sometimes', Rule::in(CatalogPriceRule::RULE_TYPES)],
            'condition'      => ['sometimes', 'nullable', 'array'],
            'modifier_value' => ['sometimes', 'numeric', 'min:0'],
            'priority'       => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_exclusive'   => ['sometimes', 'boolean'],
            'is_active'      => ['sometimes', 'boolean'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to'   => ['sometimes', 'nullable', 'date'],
        ]);

        $rule = $this->engine->updateRule($rule, $data);

        return response()->json(['data' => $rule]);
    }

    /**
     * DELETE /pricing/rules/{ruleId}
     */
    public function deleteRule(int $ruleId): JsonResponse
    {
        $this->engine->deleteRule($ruleId);

        return response()->json(['message' => 'Rule deleted.']);
    }

    // ── Quotes ────────────────────────────────────────────────────────────────

    /**
     * POST /pricing/quotes/{tenantId}
     * Generate and store a new quote snapshot for a tenant.
     */
    public function generateQuote(Request $request, string $tenantId): JsonResponse
    {
        $data = $request->validate([
            'billing_interval' => ['nullable', Rule::in(['monthly', 'yearly'])],
            'currency'         => ['nullable', 'string', 'size:3'],
            'plan_family_id'   => ['nullable', 'integer', 'exists:plan_families,id'],
        ]);

        $snapshot = $this->engine->generateQuote(
            tenantId:       $tenantId,
            billingInterval:$data['billing_interval'] ?? 'monthly',
            currency:       $data['currency']         ?? 'USD',
            planFamilyId:   isset($data['plan_family_id']) ? (int) $data['plan_family_id'] : null,
        );

        return response()->json(['data' => $snapshot->load('planFamily', 'generatedByAdmin')], 201);
    }

    /**
     * GET /pricing/quotes/{tenantId}/latest
     * Return the most recent quote snapshot for a tenant.
     */
    public function latestQuote(string $tenantId): JsonResponse
    {
        $snapshot = $this->engine->latestQuote($tenantId);

        if (! $snapshot) {
            return response()->json(['data' => null, 'message' => 'No quotes generated yet.']);
        }

        return response()->json(['data' => $snapshot]);
    }

    /**
     * GET /pricing/quotes/{tenantId}
     * Return paginated quote history for a tenant.
     */
    public function quoteHistory(Request $request, string $tenantId): JsonResponse
    {
        $perPage  = min(50, max(1, (int) $request->input('per_page', 10)));
        $history  = $this->engine->quoteHistory($tenantId, $perPage);

        return response()->json($history);
    }
}
