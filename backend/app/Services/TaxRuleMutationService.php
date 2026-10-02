<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\Tax;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * MRD R1.2 / G-03 / G-13 (#274): the ONE place a tax rule is mutated.
 *
 * A rule that is already live must never have its rate or identity rewritten
 * in place — historical determinations, posted GL lines and filed returns
 * reference this row. Identity changes on a live rule therefore end-date the
 * old row and spawn a new version row instead, with audit lineage for both.
 *
 * #739 T6: this logic used to live inside TaxController::update(). Country
 * pack upgrades need the same guarantee ("never change a rule a posted
 * document already used"), so it lives here and both callers share it rather
 * than forking a second, weaker implementation.
 */
class TaxRuleMutationService
{
    /**
     * Apply $changes to $tax and return the rule that is authoritative from
     * now on: the same row (updated in place) or a new version row.
     */
    public function apply(Tax $tax, array $changes, ?Request $request = null): Tax
    {
        $identityChanged = $this->identityChanged($tax, $changes);
        $live = $this->isLive($tax);

        if (! $identityChanged || ! $live) {
            return $this->updateInPlace($tax, $changes, $request);
        }

        $today = now()->toDateString();
        $requestedFrom = ! empty($changes['effective_from'])
            ? \Carbon\Carbon::parse($changes['effective_from'])->toDateString()
            : $today;

        // G-03 (R07b): a client-supplied backdate must never rewrite history —
        // a new version starts no earlier than the old window start and today.
        $floor = max($today, $tax->effective_from ? $tax->effective_from->toDateString() : $today);
        $newFrom = $requestedFrom < $floor ? $floor : $requestedFrom;

        if ($tax->effective_from !== null && $tax->effective_from->toDateString() > $newFrom) {
            // Not yet effective in the future either — safe to edit in place.
            return $this->updateInPlace($tax, $changes, $request);
        }

        $closeTo = \Carbon\Carbon::parse($newFrom)->subDay()->toDateString();

        return DB::transaction(fn () => $this->createVersionedReplacement($tax, $changes, $newFrom, $closeTo, $request));
    }

    /**
     * @return array<int, string>
     */
    private function identityFields(): array
    {
        return ['rate', 'jurisdiction', 'regime', 'scope', 'treatment', 'code'];
    }

    private function identityChanged(Tax $tax, array $changes): bool
    {
        foreach ($this->identityFields() as $field) {
            if (! array_key_exists($field, $changes)) {
                continue;
            }

            // G-03 (R07a): compare semantically — '9' vs '9.00' (decimal cast)
            // and case/whitespace differences must not spawn a phantom version.
            $incoming = $field === 'rate' ? (float) $changes[$field] : mb_strtolower(trim((string) $changes[$field]));
            $current = $field === 'rate' ? (float) $tax->{$field} : mb_strtolower(trim((string) $tax->{$field}));

            if ($incoming !== $current) {
                return true;
            }
        }

        return false;
    }

    private function isLive(Tax $tax): bool
    {
        $today = now()->toDateString();

        return ($tax->effective_from === null || $tax->effective_from->toDateString() < $today)
            && ($tax->effective_to === null || $tax->effective_to->toDateString() >= $today);
    }

    private function updateInPlace(Tax $tax, array $changes, ?Request $request): Tax
    {
        if (! empty($changes['is_default'])) {
            Tax::where('id', '!=', $tax->id)->where('is_default', true)->update(['is_default' => false]);
        }

        $before = $tax->attributesToArray();
        $tax->update($changes);
        $tax->increment('version');
        $tax->refresh();

        $this->audit('tax_rule_updated', $tax, $before, $tax->attributesToArray(), $request);

        return $tax;
    }

    private function createVersionedReplacement(Tax $tax, array $changes, string $newFrom, string $closeTo, ?Request $request): Tax
    {
        $oldBefore = $tax->attributesToArray();
        $merged = array_merge($tax->only([
            'name', 'code', 'jurisdiction', 'regime', 'scope', 'treatment',
            'type', 'rate', 'is_active', 'is_default', 'is_inclusive',
        ]), $changes);
        $merged['effective_from'] = $newFrom;
        $merged['effective_to'] = $changes['effective_to'] ?? null;

        $fresh = Tax::create($merged);

        // The closed row stays ACTIVE and is bounded by its end date: the
        // engine resolves by date window, so pre-change documents keep deriving
        // the historical rate from this row.
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
    }

    /**
     * G-13 (#274): immutable audit lineage for tax rule changes.
     */
    public function audit(string $operation, Tax $tax, ?array $before, ?array $after, ?Request $request = null): void
    {
        AuditLog::create([
            'operation' => $operation,
            'reference_type' => 'tax_rule',
            'reference_id' => $tax->id,
            'actor_id' => $request?->user()?->id,
            'source_route' => $request?->path(),
            'before_state' => $before,
            'after_state' => $after,
        ]);
    }
}
