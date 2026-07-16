<?php

namespace Modules\FinancialClose\Services;

use Modules\FinancialClose\Models\SubledgerTieout;
use Modules\FinancialClose\Models\UnreconciledItem;

class FinancialCloseTieoutService
{
    /**
     * Get all subledger tieouts for a review
     */
    public function listTieouts(string $tenantId, string $reviewId): array
    {
        return SubledgerTieout::where('tenant_id', $tenantId)
            ->where('review_id', $reviewId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Get all unreconciled items for a review
     */
    public function listUnreconciledItems(string $tenantId, string $reviewId): array
    {
        return UnreconciledItem::where('tenant_id', $tenantId)
            ->where('review_id', $reviewId)
            ->orderBy('aging_days', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Create or update a tieout projection
     * Tieouts are projections only -- they do not mutate accounting truth
     */
    public function upsertTieout(string $tenantId, string $reviewId, array $data): ?SubledgerTieout
    {
        $existing = SubledgerTieout::where('tenant_id', $tenantId)
            ->where('review_id', $reviewId)
            ->where('account_code', $data['account_code'])
            ->where('subledger_type', $data['subledger_type'])
            ->first();

        $variance = ($data['gl_balance'] ?? 0) - ($data['subledger_balance'] ?? 0);
        $status = abs($variance) < 0.01 ? 'tied' : 'not_tied';

        if ($existing) {
            $existing->update([
                'gl_balance' => $data['gl_balance'] ?? 0,
                'subledger_balance' => $data['subledger_balance'] ?? 0,
                'variance' => $variance,
                'status' => $status,
            ]);

            return $existing;
        }

        return SubledgerTieout::create([
            'tenant_id' => $tenantId,
            'review_id' => $reviewId,
            'entity_id' => $data['entity_id'],
            'account_code' => $data['account_code'],
            'subledger_type' => $data['subledger_type'],
            'gl_balance' => $data['gl_balance'] ?? 0,
            'subledger_balance' => $data['subledger_balance'] ?? 0,
            'variance' => $variance,
            'status' => $status,
        ]);
    }

    /**
     * Create an unreconciled item projection
     * Unreconciled items are projections only -- they do not mutate accounting truth
     */
    public function createUnreconciledItem(string $tenantId, string $reviewId, array $data): ?UnreconciledItem
    {
        return UnreconciledItem::create([
            'tenant_id' => $tenantId,
            'review_id' => $reviewId,
            'entity_id' => $data['entity_id'],
            'account_code' => $data['account_code'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'USD',
            'status' => 'open',
            'description' => $data['description'] ?? null,
            'aging_days' => $data['aging_days'] ?? 0,
        ]);
    }

    /**
     * Resolve an unreconciled item
     */
    public function resolveUnreconciledItem(string $tenantId, string $itemId): bool
    {
        $item = UnreconciledItem::where('tenant_id', $tenantId)
            ->where('id', $itemId)
            ->first();

        if (! $item) {
            return false;
        }

        $item->update(['status' => 'resolved']);

        return true;
    }

    /**
     * Get tieout summary for an entity/period
     */
    public function getTieoutSummary(string $tenantId, string $entityId, string $accountCode): array
    {
        $tieouts = SubledgerTieout::where('tenant_id', $tenantId)
            ->where('entity_id', $entityId)
            ->where('account_code', $accountCode)
            ->get();

        return [
            'total' => $tieouts->count(),
            'tied' => $tieouts->where('status', 'tied')->count(),
            'not_tied' => $tieouts->where('status', 'not_tied')->count(),
            'stale' => $tieouts->where('status', 'stale')->count(),
        ];
    }
}
