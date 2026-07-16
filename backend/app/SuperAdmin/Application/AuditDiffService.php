<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

class AuditDiffService
{
    /** Fields that are noise and must never appear in diffs */
    private const SKIP = [
        'updated_at', 'created_at', 'remember_token',
        'password', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    /**
     * Compute a structured field-level diff between two snapshots.
     *
     * @param  array<mixed>|null  $before
     * @param  array<mixed>|null  $after
     * @return array<array{field: string, before: mixed, after: mixed}>
     */
    public function diff(?array $before, ?array $after): array
    {
        $before = $before ?? [];
        $after = $after ?? [];
        $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));

        $changes = [];
        foreach ($allKeys as $key) {
            if (in_array($key, self::SKIP, true)) {
                continue;
            }
            $bv = $before[$key] ?? null;
            $av = $after[$key] ?? null;

            // JSON-encode for deep comparison of nested arrays/objects
            if (json_encode($bv) !== json_encode($av)) {
                $changes[] = [
                    'field' => $key,
                    'before' => $bv,
                    'after' => $av,
                ];
            }
        }

        return $changes;
    }

    /**
     * Build a human-readable sentence for an audit event.
     *
     * @param  array<mixed>  $before
     * @param  array<mixed>  $after
     */
    public function humanize(
        string $action,
        ?string $actorName,
        ?string $targetType,
        ?string $targetId,
        array $before,
        array $after,
    ): string {
        $actor = $actorName ?? 'System';

        return match ($action) {
            'plan.created' => "{$actor} created plan \"{$this->pick($after, 'name', $targetId)}\"",
            'plan.updated' => "{$actor} updated plan \"{$this->pick($after, 'name', $this->pick($before, 'name', $targetId))}\"",
            'plan.version.created' => "{$actor} added a version to plan family #{$targetId}",
            'plan.prices.updated' => "{$actor} updated pricing for plan version #{$targetId}",
            'plan.module.attached' => "{$actor} attached a module to plan version #{$targetId}",
            'plan.module.detached' => "{$actor} detached a module from plan version #{$targetId}",
            'module.synced' => "{$actor} synced module catalog ({$this->pick($after, 'synced', '?')} modules)",
            'module.updated' => "{$actor} updated module \"{$this->pick($after, 'module_key', $targetId)}\"",
            'tenant.suspended' => "{$actor} suspended tenant #{$targetId}",
            'tenant.reinstated' => "{$actor} reinstated tenant #{$targetId}",
            'tenant.health.pinged' => "{$actor} ran health check on tenant #{$targetId}",
            'subscription.created' => "{$actor} created subscription for tenant #{$this->pick($after, 'tenant_id', $targetId)}",
            'subscription.canceled' => "{$actor} canceled subscription #{$targetId}",
            'subscription.reactivated' => "{$actor} reactivated subscription #{$targetId}",
            'subscription.plan_migrated' => $this->migrationMessage($actor, $before, $after, $targetId),
            'invoice.generated' => "{$actor} generated {$this->pick($after, 'billing_interval', '')} invoice #{$targetId}",
            'invoice.paid' => "{$actor} marked invoice #{$targetId} as paid",
            'invoice.voided' => "{$actor} voided invoice #{$targetId}",
            default => "{$actor} performed {$action}".($targetId ? " on {$targetType} #{$targetId}" : ''),
        };
    }

    /**
     * Classify severity from action + execution status.
     * failure → always critical.
     */
    public function severity(string $action, string $status): string
    {
        if ($status === 'failure') {
            return 'critical';
        }

        $warningActions = [
            'tenant.suspended', 'subscription.canceled', 'invoice.voided',
            'subscription.plan_migrated', 'subscription.reactivated', 'invoice.generated',
        ];

        return in_array($action, $warningActions, true) ? 'warning' : 'info';
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function pick(array $data, string $key, mixed $fallback): string
    {
        return isset($data[$key]) ? (string) $data[$key] : (string) $fallback;
    }

    private function migrationMessage(
        string $actor,
        array $before,
        array $after,
        ?string $targetId,
    ): string {
        $fromVersion = $before['plan_version_id'] ?? null;
        $toVersion = $after['plan_version_id'] ?? null;

        if ($fromVersion !== null && $toVersion !== null) {
            return "{$actor} migrated subscription #{$targetId} from plan v#{$fromVersion} to v#{$toVersion}";
        }

        return "{$actor} changed plan for subscription #{$targetId}";
    }
}
