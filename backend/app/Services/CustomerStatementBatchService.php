<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Issue #607 (RPT-F1) — the batch read model behind the Sales statements tab.
 *
 * Both the preview list and the queued batch job resolve their customer set
 * through this one service and derive every closing/overdue figure from the
 * canonical CustomerStatementService payload (never a parallel AR query), so
 * the preview the user approves is exactly what the batch generates.
 */
class CustomerStatementBatchService
{
    /** Hard cap so a mis-set filter cannot queue an unbounded run. */
    public const MAX_CUSTOMERS = 1000;

    private const EPSILON = 0.005;

    public function __construct(
        private readonly CustomerStatementService $statementService,
        private readonly EntityAccessService $entityAccess,
    ) {}

    /**
     * Customers matching the batch filters, scoped to the user's accessible
     * legal entities (granted entities only; no grants keeps the historical
     * unscoped view — same fail-open default as EnsureEntityAccess).
     *
     * @return Collection<int, Customer>
     */
    public function customersFor(int $userId, array $filters): Collection
    {
        $query = Customer::query()->where('is_active', true);

        $entityIds = $this->entityAccess->accessibleEntityIds($userId);
        if ($entityIds !== []) {
            $query->whereIn('legal_entity_id', $entityIds);
        }

        if (! empty($filters['legal_entity_id'])) {
            $query->where('legal_entity_id', (int) $filters['legal_entity_id']);
        }

        if (! empty($filters['salesperson_id'])) {
            $query->where('salesperson_id', (int) $filters['salesperson_id']);
        }

        if (! empty($filters['customer_group'])) {
            $query->where('customer_group', $filters['customer_group']);
        }

        if (! empty($filters['customer_ids']) && is_array($filters['customer_ids'])) {
            $query->whereIn('id', array_map('intval', $filters['customer_ids']));
        }

        return $query->orderBy('name')->limit(self::MAX_CUSTOMERS)->get();
    }

    /**
     * The customers the run actually covers: the base filter plus the
     * "has a balance" and "overdue > N days" refinements, computed from the
     * canonical as-of payload. The queued job uses this same set the preview
     * showed.
     *
     * @return Collection<int, Customer>
     */
    public function eligibleCustomers(int $userId, array $filters): Collection
    {
        $customers = array_map(
            fn (array $summary) => $summary['customer'],
            $this->summaries($userId, $filters),
        );

        return new Collection($customers);
    }

    /**
     * Preview list: one row per eligible customer with its canonical closing
     * balance and overdue position.
     */
    public function preview(int $userId, array $filters): array
    {
        $rows = [];
        $totalClosing = 0.0;
        $totalOverdue = 0.0;

        foreach ($this->summaries($userId, $filters) as $summary) {
            /** @var Customer $customer */
            $customer = $summary['customer'];
            $payload = $summary['payload'];

            $rows[] = [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->mobile ?: $customer->phone,
                'legal_entity_id' => $customer->legal_entity_id,
                'salesperson_id' => $customer->salesperson_id,
                'customer_group' => $customer->customer_group,
                'currency' => $payload['currency'],
                'as_of_date' => $payload['as_of_date'],
                'closing_balance' => round((float) $payload['closing_balance'], 2),
                'overdue_amount' => $summary['overdue_amount'],
                'oldest_overdue_days' => $summary['oldest_overdue_days'],
            ];

            $totalClosing += (float) $payload['closing_balance'];
            $totalOverdue += $summary['overdue_amount'];
        }

        return [
            'data' => $rows,
            'summary' => [
                'customer_count' => count($rows),
                'total_closing_balance' => round($totalClosing, 2),
                'total_overdue' => round($totalOverdue, 2),
            ],
        ];
    }

    /**
     * @return array<int, array{customer: Customer, payload: array, overdue_amount: float, oldest_overdue_days: int}>
     */
    private function summaries(int $userId, array $filters): array
    {
        $asOf = Carbon::parse($filters['as_of'] ?? now())->startOfDay();
        $withBalance = filter_var($filters['with_balance'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $overdueDays = isset($filters['overdue_days']) && $filters['overdue_days'] !== ''
            ? (int) $filters['overdue_days']
            : null;

        $summaries = [];

        foreach ($this->customersFor($userId, $filters) as $customer) {
            $payload = $this->statementService->buildAsOfPayload($customer, $asOf);
            [$overdueAmount, $oldestOverdueDays] = $this->overdueSummary($payload, $asOf);

            if ($withBalance && abs((float) $payload['closing_balance']) < self::EPSILON) {
                continue;
            }

            if ($overdueDays !== null && $oldestOverdueDays <= $overdueDays) {
                continue;
            }

            $summaries[] = [
                'customer' => $customer,
                'payload' => $payload,
                'overdue_amount' => $overdueAmount,
                'oldest_overdue_days' => $oldestOverdueDays,
            ];
        }

        return $summaries;
    }

    /**
     * @return array{0: float, 1: int} overdue outstanding and the oldest
     *                                 overdue age, from the same invoice rows
     *                                 the statement prints.
     */
    private function overdueSummary(array $payload, Carbon $asOf): array
    {
        $amount = 0.0;
        $oldest = 0;

        /** @var SupportCollection<int, array<string, mixed>> $transactions */
        $transactions = collect($payload['transactions'] ?? []);

        foreach ($transactions as $row) {
            if (($row['type'] ?? null) !== 'Invoice') {
                continue;
            }

            if (round((float) ($row['outstanding'] ?? 0), 2) < self::EPSILON) {
                continue;
            }

            $dueDate = trim((string) ($row['due_date'] ?? ''));
            if ($dueDate === '') {
                continue;
            }

            $due = Carbon::parse($dueDate)->startOfDay();
            if ($due->gte($asOf)) {
                continue;
            }

            $amount += (float) $row['outstanding'];
            $oldest = max($oldest, (int) $due->diffInDays($asOf));
        }

        return [round($amount, 2), $oldest];
    }
}
