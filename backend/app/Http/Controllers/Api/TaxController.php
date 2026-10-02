<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequirePermission;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\Tax;
use App\Services\TenantRoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaxController extends Controller
{
    public function index()
    {
        return response()->json(
            Tax::query()->orderBy('name')->get()
        );
    }

    /**
     * G-13 (#274): immutable audit lineage for tax rule changes.
     */
    public function auditTrail($tax)
    {
        $tax = Tax::withTrashed()->findOrFail($tax);

        return response()->json(
            AuditLog::where('reference_type', 'tax_rule')
                ->where('reference_id', $tax->id)
                ->orderByDesc('id')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'jurisdiction' => 'nullable|string|size:2',
            'regime' => 'nullable|string|max:30',
            'scope' => 'nullable|in:input,output,both',
            'treatment' => 'nullable|in:standard_rated,zero_rated,exempt,out_of_scope,reverse_charge',
            'creditable' => 'nullable|boolean',
            'blocked_expense_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
            'type' => 'nullable|in:gst,vat,sales,service,withholding,other',
            'rate' => 'required|numeric|min:0|max:100',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'is_inclusive' => 'boolean',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
        ]);

        if (! isset($validated['type'])) {
            $validated['type'] = 'vat';
        }

        if (! empty($validated['jurisdiction'])) {
            $validated['jurisdiction'] = strtoupper(trim($validated['jurisdiction']));
        }

        if (! empty($validated['is_default'])) {
            Tax::where('is_default', true)->update(['is_default' => false]);
        }

        // G-11 (#274): activating a rule on creation is a policy action.
        if (! empty($validated['is_active']) && ! $this->hasPermission($request, 'tax.policy.manage')) {
            return response()->json([
                'message' => 'Rule activation requires tax.policy.manage (separation of duties).',
                'required_permission' => 'tax.policy.manage',
            ], 403);
        }

        $tax = Tax::create($validated);
        $tax->refresh();

        $this->audit('tax_rule_created', $tax, null, $tax->attributesToArray(), $request);

        return response()->json($tax, 201);
    }

    public function update(Request $request, $tax)
    {
        $tax = Tax::findOrFail($tax);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|nullable|string|max:50',
            'jurisdiction' => 'sometimes|nullable|string|size:2',
            'regime' => 'sometimes|nullable|string|max:30',
            'scope' => 'sometimes|in:input,output,both',
            'treatment' => 'sometimes|in:standard_rated,zero_rated,exempt,out_of_scope,reverse_charge',
            'creditable' => 'sometimes|nullable|boolean',
            'blocked_expense_account_id' => 'sometimes|nullable|integer|exists:chart_of_accounts,id',
            'type' => 'sometimes|in:gst,vat,sales,service,withholding,other',
            'rate' => 'sometimes|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
            'is_inclusive' => 'sometimes|boolean',
            'effective_from' => 'sometimes|nullable|date',
            'effective_to' => 'sometimes|nullable|date|after_or_equal:effective_from',
            // G-10 (#274): optimistic concurrency token — no last-write-wins.
            'expected_version' => 'sometimes|integer|min:1',
            'lock_token' => 'sometimes|string',
        ]);

        // G-10 (#274): reject stale edits before touching the rule.
        $expectedVersion = $validated['expected_version'] ?? null;
        $lockToken = $validated['lock_token'] ?? null;
        unset($validated['expected_version'], $validated['lock_token']);

        if ($expectedVersion !== null && (int) $expectedVersion !== (int) $tax->version) {
            return response()->json([
                'message' => 'Tax rule was modified by someone else — re-read and retry.',
                'current_version' => (int) $tax->version,
                'updated_at' => $tax->updated_at?->toIso8601String(),
            ], 409);
        }

        if ($lockToken !== null && $tax->updated_at !== null && $lockToken !== $tax->updated_at->toIso8601String()) {
            return response()->json([
                'message' => 'Tax rule was modified by someone else — re-read and retry.',
                'current_version' => (int) $tax->version,
                'updated_at' => $tax->updated_at->toIso8601String(),
            ], 409);
        }

        // G-11 (#274): activating a rule is a policy action (SoD from authoring).
        if (array_key_exists('is_active', $validated) && $validated['is_active'] && ! $tax->is_active
            && ! $this->hasPermission($request, 'tax.policy.manage')) {
            return response()->json([
                'message' => 'Rule activation requires tax.policy.manage (separation of duties).',
                'required_permission' => 'tax.policy.manage',
            ], 403);
        }

        if (array_key_exists('jurisdiction', $validated) && $validated['jurisdiction'] !== null) {
            $validated['jurisdiction'] = strtoupper(trim($validated['jurisdiction']));
        }

        // #739 T6: the "never rewrite a live rule" rule now lives in
        // TaxRuleMutationService so country-pack upgrades get the identical
        // guarantee instead of a second, weaker implementation.
        $effective = app(\App\Services\TaxRuleMutationService::class)->apply($tax, $validated, $request);

        if ($effective->id === $tax->id) {
            return response()->json($effective);
        }

        $payload = $effective->fresh()->toArray();
        $payload['versioned'] = true;
        $payload['versioned_from'] = $tax->id;

        return response()->json($payload);
    }

    public function destroy($tax)
    {
        $tax = Tax::findOrFail($tax);

        // #276 F2: rules are RETIRED, and only when unreferenced.
        $referenced = $this->isRuleReferenced((int) $tax->id);

        if ($referenced !== null) {
            return response()->json(['message' => "Rule cannot be retired: referenced by {$referenced}."], 422);
        }

        $before = $tax->attributesToArray();
        $tax->update(['lifecycle_state' => 'RETIRED', 'is_active' => false]);
        $tax->refresh();

        $this->audit('tax_rule_RETIRED', $tax, $before, $tax->attributesToArray(), request());

        return response()->json(['message' => 'Tax rule retired successfully']);
    }

    /** #276 F2: active default / product link / posted determination. */
    private function isRuleReferenced(int $ruleId): ?string
    {
        $setting = Setting::getValue('tax', []);

        if ((int) ($setting['default_tax_id'] ?? 0) === $ruleId) {
            return 'the tenant default';
        }

        if (\App\Models\Product::query()->where('tax_id', $ruleId)->exists()) {
            return 'a product';
        }

        if (DB::table('tax_transaction_details')->where('tax_rule_id', $ruleId)->exists()) {
            return 'a posted document determination';
        }

        return null;
    }

    /**
     * G-11 (#274): controller-level policy check for activation SoD, resolved
     * through the same sources as RequirePermission (Issue #227 pattern).
     */
    private function hasPermission(Request $request, string $permission): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if (($user->is_super_admin ?? false) || $user->role === 'platform_super_admin') {
            return true;
        }

        $tenantId = RequirePermission::resolveTenantId($request);

        if (! $tenantId) {
            return false;
        }

        return app(TenantRoleService::class)->hasPermission(
            RequirePermission::resolveCentralUserId($user),
            $tenantId,
            $permission,
        );
    }

    /**
     * G-13 (#274): append one immutable audit row for a rule change.
     */
    private function audit(string $operation, Tax $tax, ?array $before, ?array $after, Request $request): void
    {
        AuditLog::create([
            'operation' => $operation,
            'reference_type' => 'tax_rule',
            'reference_id' => $tax->id,
            'actor_id' => $request->user()?->id,
            'source_route' => $request->path(),
            'before_state' => $before,
            'after_state' => $after,
        ]);
    }

    /** #276 F2: author submits their DRAFT rule for approval. */
    public function submitForApproval(Request $request, $tax)
    {
        return $this->transition($request, (int) $tax, 'PENDING_APPROVAL', null);
    }

    /** #276 F2: independent approver approves a pending rule. */
    public function approveRule(Request $request, $tax)
    {
        return $this->transition($request, (int) $tax, 'APPROVED', null);
    }

    /** #276 F2: activation of an approved rule (version token protected). */
    public function activateRule(Request $request, $tax)
    {
        $validated = $request->validate(['expected_version' => 'sometimes|integer|min:1']);

        return $this->transition($request, (int) $tax, 'ACTIVE', $validated['expected_version'] ?? null);
    }

    /** #276 F2: reject a pending rule back to DRAFT with a reason. */
    public function rejectRule(Request $request, $tax)
    {
        $validated = $request->validate(['reason' => 'required|string|min:3|max:500']);

        return $this->transition($request, (int) $tax, 'DRAFT', null, $validated['reason']);
    }

    /**
     * #276 F2: the single lifecycle transition path — state guards, SoD
     * (author cannot approve/activate), expected-version protection and
     * audit for every transition.
     */
    private function transition(Request $request, int $id, string $target, ?int $expectedVersion, ?string $reason = null)
    {
        $tax = Tax::findOrFail($id);
        $current = strtoupper((string) ($tax->lifecycle_state ?: 'ACTIVE'));

        $allowed = [
            'PENDING_APPROVAL' => ['DRAFT'],
            'APPROVED' => ['PENDING_APPROVAL'],
            'ACTIVE' => ['APPROVED'],
            'DRAFT' => ['PENDING_APPROVAL'],
        ];

        if (! in_array($current, $allowed[$target] ?? [], true)) {
            return response()->json([
                'message' => "Cannot move rule from {$current} to {$target}.",
                'current_state' => $current,
            ], 409);
        }

        if (in_array($target, ['APPROVED', 'ACTIVE'], true) && ! $this->hasPermission($request, 'tax.policy.manage')) {
            return response()->json([
                'message' => 'Approving or activating a tax rule requires tax.policy.manage.',
                'required_permission' => 'tax.policy.manage',
            ], 403);
        }

        if ($target === 'APPROVED' && $tax->submitted_by !== null && (int) $tax->submitted_by === (int) $request->user()?->id) {
            return response()->json([
                'message' => 'The author of a rule cannot approve it (separation of duties).',
            ], 403);
        }

        if ($target === 'ACTIVE' && $expectedVersion !== null && (int) $expectedVersion !== (int) $tax->version) {
            return response()->json([
                'message' => 'Tax rule was modified by someone else — re-read and retry.',
                'current_version' => (int) $tax->version,
            ], 409);
        }

        $before = $tax->attributesToArray();
        $updates = [
            'lifecycle_state' => $target,
            'is_active' => $target === 'ACTIVE',
        ];

        if ($target === 'PENDING_APPROVAL') {
            $updates['submitted_by'] = $request->user()?->id;
        }

        if ($target === 'APPROVED') {
            $updates['approved_by'] = $request->user()?->id;
            $updates['approved_at'] = now();
        }

        if ($target === 'ACTIVE') {
            $updates['version'] = (int) $tax->version + 1;
        }

        $tax->update($updates);
        $tax->refresh();

        $this->audit('tax_rule_'.$target, $tax, $before, array_merge($tax->attributesToArray(), ['reason' => $reason]), $request);

        return response()->json($tax);
    }
}
