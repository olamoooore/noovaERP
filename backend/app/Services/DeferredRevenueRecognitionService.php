<?php

namespace App\Services;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\PostingEngine;
use App\Models\DeferredRevenueSchedule;
use App\Models\Invoice;
use App\Support\TenantUserHelper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Issue #882 (SA9) — deferred revenue recognition.
 *
 * The invoice posting credits deferred revenue for the unearned amount and
 * writes one DeferredRevenueSchedule per deferred line. This service owns the
 * release side: `releaseAsOf()` recognises the portion earned by a date (the
 * monthly job's entry point) by moving it from the deferred account to the
 * line's income account through PostingEngine — never by writing journal rows
 * directly. `applyCreditReversal()` and `cancelForInvoice()` keep the
 * subledger in step with credit notes and cancellations.
 */
class DeferredRevenueRecognitionService
{
    public function __construct(
        private readonly PostingEngine $postingEngine,
    ) {}

    /**
     * Release every active schedule's portion earned by $asOf.
     *
     * @return array{released: float, schedules: int, journal_entry_ids: array<int, int>}
     */
    public function releaseAsOf(\DateTimeInterface|string $asOf, ?int $legalEntityId = null): array
    {
        $date = CarbonImmutable::parse($asOf)->startOfDay();

        $query = DeferredRevenueSchedule::query()
            ->where('status', DeferredRevenueSchedule::STATUS_ACTIVE)
            ->whereDate('start_date', '<=', $date->toDateString());

        if ($legalEntityId !== null) {
            $query->where('legal_entity_id', $legalEntityId);
        }

        $released = 0.0;
        $releasedSchedules = 0;
        $entryIds = [];

        foreach ($query->orderBy('id')->get() as $schedule) {
            $delta = $this->releaseSchedule($schedule, $date);

            if ($delta > 0) {
                $released = round($released + $delta, 2);
                $releasedSchedules++;
                $entryIds[] = (int) $schedule->last_journal_entry_id;
            }
        }

        return [
            'released' => $released,
            'schedules' => $releasedSchedules,
            'journal_entry_ids' => $entryIds,
        ];
    }

    /**
     * Release the portion of ONE schedule earned by $asOf and return the
     * amount posted (0.00 when nothing is due). The ledger posting and the
     * schedule update commit atomically.
     */
    public function releaseSchedule(DeferredRevenueSchedule $schedule, \DateTimeInterface|string $asOf): float
    {
        $date = CarbonImmutable::parse($asOf)->startOfDay();
        $cumulative = $this->recognizedThrough($schedule, $date);
        $delta = round($cumulative - (float) $schedule->recognized_amount, 2);

        if ($delta <= 0) {
            return 0.0;
        }

        $description = "Deferred revenue release #{$schedule->id} (line #{$schedule->invoice_line_id})";

        DB::transaction(function () use ($schedule, $date, $delta, $cumulative, $description) {
            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'deferred_revenue_recognition',
                // A monthly batch has no single source document — the posting
                // is referenced by schedule + period, so the engine creates no
                // (schedule, 'post') idempotency link and a later month can
                // post again.
                documentId: null,
                postingDate: $date->toDateString(),
                description: $description,
                lines: [
                    new PostingRequestLine(
                        accountMappingKey: 'deferred_revenue',
                        accountId: (int) $schedule->deferred_account_id,
                        debit: $delta,
                        credit: 0,
                        description: $description,
                        currencyCode: null,
                        departmentId: $schedule->department_id !== null ? (int) $schedule->department_id : null,
                        projectId: $schedule->project_id !== null ? (int) $schedule->project_id : null,
                        locationId: $schedule->location_id !== null ? (int) $schedule->location_id : null,
                    ),
                    new PostingRequestLine(
                        accountMappingKey: 'sales_revenue',
                        accountId: (int) $schedule->income_account_id,
                        debit: 0,
                        credit: $delta,
                        description: $description,
                        currencyCode: null,
                        departmentId: $schedule->department_id !== null ? (int) $schedule->department_id : null,
                        projectId: $schedule->project_id !== null ? (int) $schedule->project_id : null,
                        locationId: $schedule->location_id !== null ? (int) $schedule->location_id : null,
                    ),
                ],
                reference: "DR-{$schedule->id}-{$date->format('Y-m')}",
                userId: TenantUserHelper::requireTenantUserId(),
                legalEntityId: $schedule->legal_entity_id !== null ? (int) $schedule->legal_entity_id : null,
                entityBookId: $this->resolveEntityBookId($schedule),
                trigger: 'job:RecognizeDeferredRevenue',
            ));

            if (! $result->success) {
                throw new \RuntimeException(
                    "Deferred revenue release failed for schedule #{$schedule->id}: {$result->error}"
                );
            }

            $schedule->recognized_amount = $cumulative;
            $schedule->last_recognized_on = $date->toDateString();
            $schedule->last_journal_entry_id = $result->journalEntryId;
            $schedule->status = $cumulative >= round((float) $schedule->amount, 2)
                ? DeferredRevenueSchedule::STATUS_COMPLETED
                : DeferredRevenueSchedule::STATUS_ACTIVE;
            $schedule->save();
        });

        return $delta;
    }

    /**
     * The cumulative amount earned from the service start through $asOf,
     * pro-rated by whole days over the service period.
     */
    public function recognizedThrough(DeferredRevenueSchedule $schedule, \DateTimeInterface|string $asOf): float
    {
        $start = CarbonImmutable::parse($schedule->start_date)->startOfDay();
        $end = CarbonImmutable::parse($schedule->end_date)->startOfDay();
        $date = CarbonImmutable::parse($asOf)->startOfDay();

        $periodDays = max(1, (int) $start->diffInDays($end));

        if ($date->lessThanOrEqualTo($start)) {
            return 0.0;
        }

        $elapsed = min($periodDays, (int) $start->diffInDays($date));

        return min(
            round((float) $schedule->amount, 2),
            round((float) $schedule->amount * $elapsed / $periodDays, 2),
        );
    }

    /**
     * Reverse up to $creditBase of a schedule's unreleased balance (a credit
     * note of the deferred service). The amount debited from deferred revenue
     * is removed from `amount`'s unearned pool; a fully-credited schedule is
     * closed so the recognition job stops touching it.
     */
    public function applyCreditReversal(DeferredRevenueSchedule $schedule, float $creditBase): float
    {
        $remaining = $schedule->remainingAmount();
        $reversed = round(min(max($creditBase, 0.0), max($remaining, 0.0)), 2);

        if ($reversed <= 0) {
            return 0.0;
        }

        $schedule->reversed_amount = round((float) $schedule->reversed_amount + $reversed, 2);

        if ($schedule->remainingAmount() <= 0) {
            $schedule->status = (float) $schedule->recognized_amount > 0
                ? DeferredRevenueSchedule::STATUS_COMPLETED
                : DeferredRevenueSchedule::STATUS_REVERSED;
        }

        $schedule->save();

        return $reversed;
    }

    /**
     * Cancelling the invoice reverses its whole posting (ledger side) — the
     * schedule must not release anything afterwards.
     */
    public function cancelForInvoice(Invoice $invoice): int
    {
        return DeferredRevenueSchedule::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', DeferredRevenueSchedule::STATUS_ACTIVE)
            ->update(['status' => DeferredRevenueSchedule::STATUS_CANCELLED]);
    }

    private function resolveEntityBookId(DeferredRevenueSchedule $schedule): ?int
    {
        if ($schedule->legal_entity_id === null) {
            return null;
        }

        return app(\App\Services\LegalEntityContextResolver::class)
            ->resolveBookId((int) $schedule->legal_entity_id);
    }
}
