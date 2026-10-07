<?php

namespace Modules\FinancialClose\Services;

use App\Models\CloseDependencyDefinition;
use App\Models\FiscalPeriod;
use Modules\AccountingCountry\Services\TaxReportingService;
use Modules\FinancialClose\Models\CloseCycle;

/**
 * #278 F4 / ACC-MAT-001 B1D-HO-10: ACC-011 consumes the ACC-009 tax-readiness
 * fact for a close cycle. It aggregates the source fact (one per calendar
 * month of the cycle's fiscal period) and never recalculates tax truth.
 *
 * Whether a not-ready tax fact BLOCKS the close is decided by the tenant's
 * active close dependency definition for ACC-009 (CloseDependencyDefinition):
 *  - BLOCKING (dependency_mode REQUIRED + blocking_policy BLOCKING) → blocks;
 *  - WARNING / OPTIONAL / WARNING_ONLY → reported as warnings only;
 *  - no definition → reported, not blocking (same stance as ACC-010: a
 *    dependency blocks only under an explicit policy).
 */
class TaxReadinessDependencyService
{
    public const SOURCE_CAPABILITY = 'ACC-009';

    public const POLICY_BLOCKING = 'BLOCKING';

    public const POLICY_WARNING = 'WARNING';

    public const POLICY_NOT_CONFIGURED = 'NOT_CONFIGURED';

    /**
     * @return array<string, mixed>
     */
    public function evaluate(string $tenantId, CloseCycle $cycle): array
    {
        $definition = CloseDependencyDefinition::query()
            ->active()
            ->where('tenant_id', $tenantId)
            ->where('source_capability', self::SOURCE_CAPABILITY)
            ->orderBy('sequence')
            ->orderByDesc('id')
            ->first();

        $policy = $this->policyFor($definition);

        $entityId = ctype_digit((string) $cycle->entity_id) ? (int) $cycle->entity_id : null;
        $months = $this->monthsOf((string) $cycle->period_id);

        $reasons = [];
        $facts = [];

        if ($months === null) {
            $reasons[] = 'TAX_PERIOD_UNRESOLVED';
        } else {
            $reporting = app(TaxReportingService::class);

            foreach ($months as $month) {
                $fact = $reporting->readiness($month, $entityId);
                $facts[] = $fact;

                if ($fact['unfiled']) {
                    $reasons[] = "TAX_UNFILED:{$month}";
                }
                if (($fact['filing_state'] ?? null) === 'AMENDMENT_REQUIRED') {
                    $reasons[] = "TAX_AMENDMENT_REQUIRED:{$month}";
                }
                if (! ($fact['reconciliation']['balanced'] ?? false)) {
                    $reasons[] = "TAX_UNRECONCILED:{$month}";
                }
                if ($fact['close_blocking'] ?? false) {
                    $reasons[] = "TAX_CLOSE_BLOCKING_EXCEPTIONS:{$month}";
                }
            }
        }

        $notReady = $reasons !== [];

        return [
            'authority' => self::SOURCE_CAPABILITY,
            'policy' => $policy,
            'dependency_definition' => $definition ? [
                'id' => $definition->id,
                'dependency_code' => $definition->dependency_code,
                'version' => optional($definition->updated_at)->toIso8601String(),
            ] : null,
            'legal_entity_id' => $entityId,
            'months' => $facts,
            // The exact source versions consumed, plus the policy version.
            'version' => sha1(json_encode([
                array_column($facts, 'version'),
                $policy,
                $definition?->id,
                optional($definition?->updated_at)->toIso8601String(),
            ])),
            'ready' => ! $notReady,
            'reasons' => $reasons,
            'blocking' => $notReady && $policy === self::POLICY_BLOCKING,
            'warnings' => $notReady && $policy !== self::POLICY_BLOCKING ? $reasons : [],
        ];
    }

    private function policyFor(?CloseDependencyDefinition $definition): string
    {
        if ($definition === null) {
            return self::POLICY_NOT_CONFIGURED;
        }

        if ($definition->dependency_mode === CloseDependencyDefinition::MODE_REQUIRED
            && $definition->blocking_policy === CloseDependencyDefinition::POLICY_BLOCKING) {
            return self::POLICY_BLOCKING;
        }

        return self::POLICY_WARNING;
    }

    /**
     * Calendar months (YYYY-MM) of the cycle's fiscal period; null when the
     * cycle's period cannot be resolved to a fiscal period.
     *
     * @return array<int, string>|null
     */
    private function monthsOf(string $periodCode): ?array
    {
        $period = FiscalPeriod::where('code', $periodCode)->first();

        if (! $period || ! $period->period_start || ! $period->period_end) {
            return null;
        }

        $months = TaxReportingService::monthsBetween(
            $period->period_start->format('Y-m'),
            $period->period_end->format('Y-m'),
        );

        return $months === [] ? null : $months;
    }
}
