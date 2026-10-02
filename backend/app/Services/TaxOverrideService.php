<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * #277 F5: the single guarded path for manual tax overrides.
 *
 * - every override needs a reason code (coded list + optional free text);
 * - high-risk treatment transitions need an INDEPENDENT approver (no
 *   self-approval);
 * - engine vs applied values + tax delta are recorded for audit/report.
 */
class TaxOverrideService
{
    public const REASON_CODES = [
        'EXEMPT_SUPPLY', 'ZERO_RATED_FACT', 'OUT_OF_SCOPE', 'CONTRACT_TERMS',
        'AUTHORITY_RULING', 'CHANNEL_PRICE', 'DATA_CORRECTION', 'OTHER',
    ];

    /**
     * @param  array<string, mixed>  $engine  engine-resolved values
     * @param  array<string, mixed>  $applied  caller-supplied override values (with reason_code / approver_id)
     */
    public function guardAndRecordDocumentOverride(
        string $documentType,
        int $documentId,
        array $engine,
        array $applied,
        ?float $engineTax = null,
        ?float $appliedTax = null,
    ): ?array {
        $differs = false;

        foreach (['jurisdiction', 'tax_regime', 'treatment'] as $key) {
            if (! array_key_exists($key, $applied) || $applied[$key] === null) {
                continue;
            }

            $engineValue = strtolower(trim((string) ($engine[$key] ?? '')));
            $appliedValue = strtolower(trim((string) $applied[$key]));

            if ($engineValue !== $appliedValue) {
                $differs = true;
                break;
            }
        }

        if (! $differs) {
            return null;
        }

        $reasonCode = strtoupper(trim((string) ($applied['reason_code'] ?? '')));

        if ($reasonCode === '') {
            throw new \InvalidArgumentException('TAX_OVERRIDE_REASON_REQUIRED: a reason code is mandatory for tax overrides.');
        }

        if (! in_array($reasonCode, self::REASON_CODES, true)) {
            throw new \InvalidArgumentException('TAX_OVERRIDE_REASON_INVALID: unknown reason code '.$reasonCode.'.');
        }

        $highRisk = $this->isHighRisk((string) ($engine['treatment'] ?? ''), (string) ($applied['treatment'] ?? ''));
        $approverId = isset($applied['approver_id']) ? (int) $applied['approver_id'] : null;
        $actorId = auth()->id() !== null ? (int) auth()->id() : null;

        if ($highRisk) {
            if ($approverId === null) {
                throw new \InvalidArgumentException('TAX_OVERRIDE_APPROVER_REQUIRED: this override needs an independent approver.');
            }

            if ($approverId === $actorId) {
                throw new \InvalidArgumentException('TAX_OVERRIDE_SELF_APPROVAL: the same user cannot approve their own override.');
            }
        }

        $row = [
            'document_type' => $documentType,
            'document_id' => $documentId,
            'actor_id' => $actorId,
            'reason_code' => $reasonCode,
            'reason_text' => $applied['reason_text'] ?? null,
            'engine_values' => json_encode($engine),
            'applied_values' => json_encode($applied),
            'delta_tax_amount' => round(($appliedTax ?? 0.0) - ($engineTax ?? 0.0), 2),
            'high_risk' => $highRisk,
            'approver_id' => $approverId,
            'approved_at' => $highRisk ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $id = (int) DB::table('tax_override_audits')->insertGetId($row);

        return array_merge($row, ['id' => $id]);
    }

    private function isHighRisk(string $engineTreatment, string $appliedTreatment): bool
    {
        $risky = ['exempt', 'out_of_scope', 'zero_rated'];
        $engine = strtolower(trim($engineTreatment));
        $applied = strtolower(trim($appliedTreatment));

        if ($applied === '') {
            return false;
        }

        // Conservatively high-risk whenever the APPLIED treatment lands in a
        // risky bucket (exempt/zero/out-of-scope) or crosses the bucket line —
        // the header-level engine treatment may be unknown.
        return in_array($applied, $risky, true)
            || ($engine !== '' && in_array($engine, $risky, true) !== in_array($applied, $risky, true));
    }

    /**
     * Override report (#277): overridden documents only, filterable by
     * period, user, reason and document type, with the tax delta.
     *
     * @return array<int, array<string, mixed>>
     */
    public function report(?string $from = null, ?string $to = null, ?int $actorId = null, ?string $reasonCode = null, ?string $documentType = null): array
    {
        return DB::table('tax_override_audits')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->when($actorId, fn ($q) => $q->where('actor_id', $actorId))
            ->when($reasonCode, fn ($q) => $q->where('reason_code', strtoupper($reasonCode)))
            ->when($documentType, fn ($q) => $q->where('document_type', $documentType))
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'document_type' => $row->document_type,
                'document_id' => (int) $row->document_id,
                'actor_id' => $row->actor_id !== null ? (int) $row->actor_id : null,
                'reason_code' => $row->reason_code,
                'reason_text' => $row->reason_text,
                'engine_values' => json_decode($row->engine_values, true),
                'applied_values' => json_decode($row->applied_values, true),
                'delta_tax_amount' => (float) $row->delta_tax_amount,
                'high_risk' => (bool) $row->high_risk,
                'approver_id' => $row->approver_id !== null ? (int) $row->approver_id : null,
                'created_at' => $row->created_at,
            ])
            ->all();
    }
}
