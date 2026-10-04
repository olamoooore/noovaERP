<?php

namespace App\Platform\Audit;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Issue #912: the single writer for the tamper-evident audit chain.
 *
 * Both #895 audit stores — `platform_system_logs` (observer / domain-event
 * rows) and `audit_logs` (explicit Sales and accounting lifecycle rows) — are
 * chained into one sequence per tenant schema. Each entry's `entry_hash` is
 * sha256 over a canonical serialization of (tenant, previous hash, actor,
 * operation, subject, payload, created_at) and `previous_hash` is the prior
 * entry's `entry_hash`, so editing or deleting any stored row breaks the
 * chain from that point on.
 *
 * The chain head (sequence + hash) lives in `audit_chain_heads` and is locked
 * FOR UPDATE while appending, so the hash is produced inside the same
 * transaction as the audit row and concurrent writers cannot fork the chain.
 *
 * Legacy rows written before this migration have null hashes and are skipped
 * by verification (they are not retro-hashed, which would rewrite history).
 */
class AuditHashChain
{
    public const HEADS_TABLE = 'audit_chain_heads';

    public const HEAD_ID = 'head';

    /**
     * Compute the chain metadata for a new audit entry.
     *
     * Must be called inside the transaction that inserts the audit row: the
     * head is advanced here and rolls back with the row if the insert fails.
     *
     * @param  array{tenant_id?: string|null, actor_id?: int|string|null, operation: string, subject: string, payload?: array|null, created_at: DateTimeInterface|string}  $entry
     * @return array{chain_sequence: int, previous_hash: string|null, entry_hash: string}
     */
    public function seal(array $entry): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('AuditHashChain::seal() must run inside the transaction that writes the audit row.');
        }

        $createdAt = $entry['created_at'] instanceof DateTimeInterface
            ? $entry['created_at']
            : Carbon::parse($entry['created_at']);

        $this->ensureHeadRow();

        $head = DB::table(self::HEADS_TABLE)
            ->where('id', self::HEAD_ID)
            ->lockForUpdate()
            ->first();

        $previousHash = $head->head_hash;
        $sequence = ((int) $head->chain_sequence) + 1;

        $entryHash = self::hashEntry(
            tenantId: $entry['tenant_id'] ?? null,
            previousHash: $previousHash,
            actorId: $entry['actor_id'] ?? null,
            operation: $entry['operation'],
            subject: $entry['subject'],
            payload: $entry['payload'] ?? null,
            createdAt: $createdAt,
        );

        DB::table(self::HEADS_TABLE)->where('id', self::HEAD_ID)->update([
            'tenant_id' => $entry['tenant_id'] ?? null,
            'chain_sequence' => $sequence,
            'head_hash' => $entryHash,
            'updated_at' => $createdAt,
        ]);

        return [
            'chain_sequence' => $sequence,
            'previous_hash' => $previousHash,
            'entry_hash' => $entryHash,
        ];
    }

    private function ensureHeadRow(): void
    {
        if (DB::table(self::HEADS_TABLE)->where('id', self::HEAD_ID)->exists()) {
            return;
        }

        DB::table(self::HEADS_TABLE)->insertOrIgnore([
            'id' => self::HEAD_ID,
            'tenant_id' => null,
            'chain_sequence' => 0,
            'head_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function head(): ?object
    {
        return DB::table(self::HEADS_TABLE)->where('id', self::HEAD_ID)->first();
    }

    /**
     * The canonical entry hash. Kept static so verification recomputes the
     * exact same digest without touching the head or a transaction.
     */
    public static function hashEntry(
        ?string $tenantId,
        ?string $previousHash,
        int|string|null $actorId,
        string $operation,
        string $subject,
        ?array $payload,
        DateTimeInterface $createdAt,
    ): string {
        return hash('sha256', self::canonicalJson([
            'tenant' => (string) ($tenantId ?? ''),
            'previous_hash' => (string) ($previousHash ?? ''),
            'actor' => (string) ($actorId ?? ''),
            'operation' => $operation,
            'subject' => $subject,
            'payload' => $payload,
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
        ]));
    }

    /**
     * Deterministic JSON: object keys sorted recursively, list order kept.
     */
    public static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }

        ksort($value);

        return array_map([self::class, 'canonicalize'], $value);
    }
}
