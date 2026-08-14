<?php

namespace App\Services;

use App\Models\Accounting\AccountingFinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountingFindingService
{
    /**
     * Create a new accounting finding.
     */
    public function createFinding(array $data): AccountingFinding
    {
        $validated = $this->validateFindingData($data);

        return DB::transaction(function () use ($validated) {
            $finding = AccountingFinding::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => tenant()->id,
                'entity_id' => $validated['entity_id'],
                'finding_type' => $validated['finding_type'],
                'severity' => $validated['severity'] ?? 'medium',
                'status' => 'open',
                'source_type' => $validated['source_type'] ?? null,
                'source_id' => $validated['source_id'] ?? null,
                'account_code' => $validated['account_code'] ?? null,
                'reference_number' => $validated['reference_number'] ?? null,
                'title' => $validated['title'],
                'description' => $validated['description'],
                'recommendation' => $validated['recommendation'] ?? null,
                'amount' => $validated['amount'] ?? null,
                'currency' => $validated['currency'] ?? null,
                'confidence' => $validated['confidence'] ?? 0,
                'metadata' => $validated['metadata'] ?? null,
            ]);

            return $finding;
        });
    }

    /**
     * Acknowledge a finding.
     */
    public function acknowledge(string $findingId, string|int $userId): AccountingFinding
    {
        $finding = AccountingFinding::findOrFail($findingId);
        $finding->acknowledge((string) $userId);

        return $finding;
    }

    /**
     * Mark a finding as under investigation.
     */
    public function investigate(string $findingId, string|int $userId): AccountingFinding
    {
        $finding = AccountingFinding::findOrFail($findingId);
        $finding->investigate((string) $userId);

        return $finding;
    }

    /**
     * Resolve a finding.
     */
    public function resolve(string $findingId, ?string $notes = null): AccountingFinding
    {
        $finding = AccountingFinding::findOrFail($findingId);
        $finding->resolve($notes);

        return $finding;
    }

    /**
     * Dismiss a finding.
     */
    public function dismiss(string $findingId, ?string $reason = null): AccountingFinding
    {
        $finding = AccountingFinding::findOrFail($findingId);
        $finding->dismiss($reason);

        return $finding;
    }

    /**
     * Escalate a finding.
     */
    public function escalate(string $findingId, ?string $reason = null): AccountingFinding
    {
        $finding = AccountingFinding::findOrFail($findingId);
        $finding->escalate($reason);

        return $finding;
    }

    /**
     * Get findings for an entity with optional filters.
     */
    public function getFindings(
        string $entityId,
        ?string $status = null,
        ?string $severity = null,
        ?string $type = null,
        int $limit = 50
    ): \Illuminate\Database\Eloquent\Collection {
        $query = AccountingFinding::where('tenant_id', tenant()->id)
            ->where('entity_id', $entityId);

        if ($status) {
            $query->where('status', $status);
        }

        if ($severity) {
            $query->where('severity', $severity);
        }

        if ($type) {
            $query->where('finding_type', $type);
        }

        return $query->orderByDesc('severity')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get summary statistics for findings.
     */
    public function getSummary(string $entityId): array
    {
        $query = AccountingFinding::where('tenant_id', tenant()->id)
            ->where('entity_id', $entityId);

        return [
            'total' => (clone $query)->count(),
            'open' => (clone $query)->where('status', 'open')->count(),
            'acknowledged' => (clone $query)->where('status', 'acknowledged')->count(),
            'investigating' => (clone $query)->where('status', 'investigating')->count(),
            'resolved' => (clone $query)->where('status', 'resolved')->count(),
            'dismissed' => (clone $query)->where('status', 'dismissed')->count(),
            'escalated' => (clone $query)->where('status', 'escalated')->count(),
            'critical' => (clone $query)->where('severity', 'critical')->where('status', '!=', 'resolved')->count(),
            'high' => (clone $query)->where('severity', 'high')->where('status', '!=', 'resolved')->count(),
            'by_type' => (clone $query)->selectRaw('finding_type, count(*) as count')
                ->where('status', '!=', 'resolved')
                ->groupBy('finding_type')
                ->pluck('count', 'finding_type')
                ->toArray(),
        ];
    }

    /**
     * Validate finding data.
     */
    private function validateFindingData(array $data): array
    {
        $required = ['entity_id', 'finding_type', 'title', 'description'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!array_key_exists($data['finding_type'], AccountingFinding::FINDING_TYPES)) {
            throw new \InvalidArgumentException("Invalid finding_type: {$data['finding_type']}");
        }

        if (isset($data['severity']) && !array_key_exists($data['severity'], AccountingFinding::SEVERITIES)) {
            throw new \InvalidArgumentException("Invalid severity: {$data['severity']}");
        }

        return $data;
    }
}
