<?php

namespace App\Platform\Audit;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issue #912: periodic anchors.
 *
 * `audit:anchor` stores an HMAC-SHA256 digest (signed with the app key) of
 * the current chain head in `audit_chain_anchors`, so a later rewrite of the
 * chain — including deletion of its tail — cannot be made to look like a
 * clean chain without also forging the anchor.
 */
class AuditAnchorService
{
    public const ANCHORS_TABLE = 'audit_chain_anchors';

    /**
     * Store a signed digest of the current chain head. Returns null when the
     * tenant has no chained entries yet (nothing to anchor).
     */
    public function anchor(?string $period = null): ?object
    {
        $head = AuditHashChain::head();

        if ($head === null || $head->head_hash === null) {
            return null;
        }

        $period ??= now()->format('Y-m-d');
        $anchoredAt = now()->startOfSecond();

        $digest = self::digest(
            $head->tenant_id,
            (int) $head->chain_sequence,
            (string) $head->head_hash,
            $period,
            $anchoredAt,
        );

        $id = (string) Str::uuid();

        DB::table(self::ANCHORS_TABLE)->insert([
            'id' => $id,
            'tenant_id' => $head->tenant_id,
            'chain_sequence' => (int) $head->chain_sequence,
            'head_hash' => $head->head_hash,
            'period' => $period,
            'digest' => $digest,
            'anchored_at' => $anchoredAt,
            'created_at' => $anchoredAt,
            'updated_at' => $anchoredAt,
        ]);

        return DB::table(self::ANCHORS_TABLE)->where('id', $id)->first();
    }

    public static function digest(
        ?string $tenantId,
        int $chainSequence,
        string $headHash,
        string $period,
        DateTimeInterface $anchoredAt,
    ): string {
        return hash_hmac('sha256', AuditHashChain::canonicalJson([
            'tenant' => (string) ($tenantId ?? ''),
            'chain_sequence' => $chainSequence,
            'head_hash' => $headHash,
            'period' => $period,
            'anchored_at' => $anchoredAt->format('Y-m-d H:i:s'),
        ]), (string) config('app.key'));
    }
}
