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

        // MRD R1.2: a rule that is already live must never have its rate or
        // identity rewritten in place — historical determinations, posted GL
        // lines and filed returns reference this row. Identity changes on a
        // live rule close the old row and spawn a new version row instead.
        $identityFields = ['rate', 'jurisdiction', 'regime', 'scope', 'treatment', 'code'];
        $identityChanged = false;

        foreach ($identityFields as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            // G-03 (R07a): compare semantically — '9' vs '9.00' (decimal cast)
            // and case/whitespace differences must not spawn a phantom version.
            $incoming = $field === 'rate' ? (float) $validated[$field] : mb_strtolower(trim((string) $validated[$field]));
            $current = $field === 'rate' ? (float) $tax->{$field} : mb_strtolower(trim((string) $tax->{$field}));

            if ($incoming !== $current) {
                $identityChanged = true;
                break;
            }
        }

        $today = now()->toDateString();
        $live = ($tax->effective_from === null || $tax->effective_from->toDateString() < $today)
            && ($tax->effective_to === null || $tax->effective_to->toDateString() >= $today);

        if (! $identityChanged || ! $live) {
            if (! empty($validated['is_default'])) {
                Tax::where('id', '!=', $tax->id)->where('is_default', true)->update(['is_default' => false]);
            }

            $before = $tax->attributesToArray();
            $tax->update($validated);
            $tax->increment('version');
            $tax->refresh();

            $this->audit('tax_rule_updated', $tax, $before, $tax->attributesToArray(), $request);

            return response()->json($tax);
        }

        $requestedFrom = ! empty($validated['effective_from'])
            ? \Carbon\Carbon::parse($validated['effective_from'])->toDateString()
            : $today;

        // G-03 (R07b): a client-supplied backdate must never rewrite history —
        // a new version starts no earlier than the old window start and today.
        $floor = max($today, $tax->effective_from ? $tax->effective_from->toDateString() : $today);
        $newFrom = $requestedFrom < $floor ? $floor : $requestedFrom;

        if ($tax->effective_from !== null && $tax->effective_from->toDateString() > $newFrom) {
            // Not yet effective in the future either — safe to edit in place.
            $before = $tax->attributesToArray();
            $tax->update($validated);
            $tax->increment('version');
            $tax->refresh();

            $this->audit('tax_rule_updated', $tax, $before, $tax->attributesToArray(), $request);

            return response()->json($tax);
        }

        $closeTo = \Carbon\Carbon::parse($newFrom)->subDay()->toDateString();

        $newTax = DB::transaction(function () use ($tax, $validated, $newFrom, $closeTo, $request) {
            $oldBefore = $tax->attributesToArray();
            $merged = array_merge($tax->only([
                'name', 'code', 'jurisdiction', 'regime', 'scope', 'treatment',
                'type', 'rate', 'is_active', 'is_default', 'is_inclusive',
            ]), $validated);
            $merged['effective_from'] = $newFrom;
            $merged['effective_to'] = $validated['effective_to'] ?? null;

            $fresh = Tax::create($merged);

            $tax->update([
                'effective_to' => $tax->effective_to !== null && $tax->effective_to->toDateString() < $closeTo
                    ? $tax->effective_to->toDateString()
                    : $closeTo,
                'is_default' => false,
            ]);
            $tax->increment('version');
            $tax->refresh();

            // G-13 (#274): lineage for the versioned replacement.
            $this->audit('tax_rule_versioned', $tax, $oldBefore, array_merge($tax->attributesToArray(), ['versioned_to' => $fresh->id]), $request);
            $this->audit('tax_rule_created', $fresh, null, $fresh->attributesToArray(), $request);

            // Re-point the tenant default so resolution keeps working forward.
            $taxSetting = Setting::getValue('tax', []);
            if (($taxSetting['default_tax_id'] ?? null) === $tax->id) {
                $taxSetting['default_tax_id'] = $fresh->id;
                Setting::setValue('tax', $taxSetting);
            }

            return $fresh;
        });

        $payload = $newTax->fresh()->toArray();
        $payload['versioned'] = true;
        $payload['versioned_from'] = $tax->id;

        return response()->json($payload);
    }

    public function destroy($tax)
    {
        $tax = Tax::findOrFail($tax);
        $before = $tax->attributesToArray();
        $tax->delete();

        $this->audit('tax_rule_deleted', $tax, $before, null, request());

        return response()->json(['message' => 'Tax deleted successfully']);
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
}
