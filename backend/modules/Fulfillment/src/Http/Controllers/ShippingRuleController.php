<?php

namespace Modules\Fulfillment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Fulfillment\Services\ShippingRuleEngine;

/**
 * Issue #148: ShippingRule CRUD was implemented in ShippingRuleEngine but no
 * route exposed it (dead code). These endpoints make rule-based carrier
 * selection configurable; RateShopperService consults the engine.
 */
class ShippingRuleController extends \App\Http\Controllers\Controller
{
    public function __construct(
        private readonly ShippingRuleEngine $engine,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->engine->getRules(tenant()->id));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'priority' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'conditions' => 'nullable|array',
            'conditions.order_total_min' => 'nullable|numeric|min:0',
            'conditions.order_total_max' => 'nullable|numeric|min:0',
            'conditions.weight_min' => 'nullable|numeric|min:0',
            'conditions.weight_max' => 'nullable|numeric|min:0',
            'conditions.destination_country' => 'nullable',
            'actions' => 'required|array',
            'actions.selected_provider' => 'required|array',
            'actions.selected_provider.provider_id' => 'required|string|exists:fulfillment_providers,id',
        ]);

        $validated['tenant_id'] = tenant()->id;

        return response()->json($this->engine->createRule($validated), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'priority' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'conditions' => 'sometimes|array',
            'actions' => 'sometimes|array',
            'actions.selected_provider.provider_id' => 'sometimes|string|exists:fulfillment_providers,id',
        ]);

        try {
            return response()->json($this->engine->updateRule($id, $validated));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            throw ValidationException::withMessages(['id' => ['Shipping rule not found.']]);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json(['deleted' => $this->engine->deleteRule($id)]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rule_ids' => 'required|array',
            'rule_ids.*' => 'integer|exists:shipping_rules,id',
        ]);

        $this->engine->reorderRules(tenant()->id, $validated['rule_ids']);

        return response()->json(['reordered' => true]);
    }
}
