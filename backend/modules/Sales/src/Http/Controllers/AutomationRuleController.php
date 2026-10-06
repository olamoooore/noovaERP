<?php

namespace Modules\Sales\Http\Controllers;

use App\Services\EntityAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sales\Http\Requests\DryRunAutomationRuleRequest;
use Modules\Sales\Http\Requests\StoreAutomationRuleRequest;
use Modules\Sales\Http\Requests\UpdateAutomationRuleRequest;
use Modules\Sales\Models\AutomationExecution;
use Modules\Sales\Models\AutomationRule;
use Modules\Sales\Models\AutomationRuleVersion;
use Modules\Sales\Services\AutomationRuleService;
use Modules\Sales\Support\AutomationCatalog;

/**
 * AutomationRuleController — #913 admin API for no-code automation rules over
 * the #901 sales event catalogue.
 *
 * All routes are gated by the existing seeded `sales.settings` permission
 * (Owner/Admin) via RequirePermission; rules are additionally scoped to the
 * caller's legal entities (when the tenant tracks entity access).
 */
class AutomationRuleController
{
    public function __construct(
        private readonly AutomationRuleService $service,
        private readonly EntityAccessService $entityAccess,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->entityScoped(AutomationRule::query(), $request)
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($request->filled('event_name')) {
            $query->where('event_name', (string) $request->query('event_name'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $rules = $query->limit(200)->get();

        return response()->json(['data' => $rules]);
    }

    public function store(StoreAutomationRuleRequest $request): JsonResponse
    {
        $rule = $this->service->create($request->validated(), $request->user()?->id);

        return response()->json(['data' => $rule], 201);
    }

    public function show(Request $request, AutomationRule $rule): JsonResponse
    {
        $this->assertVisible($request, $rule);

        return response()->json(['data' => $rule]);
    }

    public function update(UpdateAutomationRuleRequest $request, AutomationRule $rule): JsonResponse
    {
        $this->assertVisible($request, $rule);

        $validated = $request->validated();

        if (isset($validated['legal_entity_id']) && (int) $validated['legal_entity_id'] !== (int) $rule->legal_entity_id) {
            return response()->json([
                'message' => 'A rule cannot be moved to another legal entity; create a new rule instead.',
            ], 422);
        }

        unset($validated['legal_entity_id']);

        $rule = $this->service->update($rule, $validated, $request->user()?->id);

        return response()->json(['data' => $rule]);
    }

    public function destroy(Request $request, AutomationRule $rule): JsonResponse
    {
        $this->assertVisible($request, $rule);

        $rule->delete();

        return response()->json(null, 204);
    }

    public function versions(Request $request, AutomationRule $rule): JsonResponse
    {
        $this->assertVisible($request, $rule);

        return response()->json([
            'data' => AutomationRuleVersion::query()
                ->where('rule_id', $rule->id)
                ->orderByDesc('version')
                ->get(),
        ]);
    }

    public function dryRun(DryRunAutomationRuleRequest $request, AutomationRule $rule): JsonResponse
    {
        $this->assertVisible($request, $rule);

        $payload = $request->validated('payload');

        return response()->json([
            'data' => $this->service->dryRun($rule, is_array($payload) ? $payload : null),
        ]);
    }

    public function executions(Request $request): JsonResponse
    {
        $query = AutomationExecution::query()->orderByDesc('id');

        $entityIds = $this->accessibleEntityIds($request);

        if ($entityIds !== null) {
            $query->where(function ($q) use ($entityIds) {
                $q->whereIn('legal_entity_id', $entityIds)->orWhereNull('legal_entity_id');
            });
        }

        $entityContext = $request->attributes->get('legal_entity_id');

        if ($entityContext) {
            $query->where('legal_entity_id', (int) $entityContext);
        }

        if ($request->filled('rule_id')) {
            $query->where('rule_id', (int) $request->query('rule_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('event_name')) {
            $query->where('event_name', (string) $request->query('event_name'));
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        return response()->json($query->with('rule')->paginate($perPage));
    }

    public function catalog(): JsonResponse
    {
        return response()->json(['data' => AutomationCatalog::toArray()]);
    }

    /**
     * Scope a rule query to the entity context and, when the tenant uses
     * explicit per-user entity access, to the caller's accessible entities.
     */
    private function entityScoped($query, Request $request)
    {
        $entityIds = $this->accessibleEntityIds($request);

        if ($entityIds !== null) {
            $query->whereIn('legal_entity_id', $entityIds);
        }

        $entityContext = $request->attributes->get('legal_entity_id');

        if ($entityContext) {
            $query->where('legal_entity_id', (int) $entityContext);
        }

        return $query;
    }

    private function assertVisible(Request $request, AutomationRule $rule): void
    {
        $entityIds = $this->accessibleEntityIds($request);

        if ($entityIds !== null && ! in_array((int) $rule->legal_entity_id, $entityIds, true)) {
            abort(404, 'Automation rule not found.');
        }

        $entityContext = $request->attributes->get('legal_entity_id');

        if ($entityContext && (int) $rule->legal_entity_id !== (int) $entityContext) {
            abort(404, 'Automation rule not found.');
        }
    }

    /**
     * @return int[]|null null = no explicit per-user entity restriction
     */
    private function accessibleEntityIds(Request $request): ?array
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        $ids = $this->entityAccess->accessibleEntityIds((int) $user->id);

        return $ids === [] ? null : $ids;
    }
}
