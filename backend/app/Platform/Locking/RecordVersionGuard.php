<?php

namespace App\Platform\Locking;

use App\Platform\Locking\Contracts\RecordLockingServiceContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Issue #896 (SALES-PLAT / PC2) — the platform-owned optimistic-concurrency
 * check shared by the invoice, quotation and sales-order update endpoints.
 *
 * A client may echo any one of:
 *   - `version`     — the `lock_version` integer exposed by every GET,
 *   - `updated_at`  — the document timestamp it loaded,
 *   - `If-Match`    — an ETag ("<lock_version>-<unix ts>"), a bare version or
 *                     a raw timestamp (weak `W/` prefixes and quotes accepted).
 *
 * When a token is supplied and does not match the locked, current row the
 * update is rejected with 409 and the current state — the last save no longer
 * silently wins. Requests without any token keep the legacy last-write-wins
 * behaviour (documented limitation: older clients do not send one).
 *
 * `assertFresh()` must run inside the same transaction that persists the
 * update so the row lock covers check + write; `advance()` bumps the version
 * after a successful write. The optional soft lock (who is editing) is
 * recorded through the existing RecordLockingService and surfaced on conflict
 * — it is advisory, never a hard block.
 */
class RecordVersionGuard
{
    public const CODE_STALE = 'stale_record';

    public function __construct(private RecordLockingServiceContract $locks) {}

    /**
     * Re-read the row under a row lock and throw when the client's token is
     * stale. Call inside the update transaction, before mutating.
     */
    public function assertFresh(Request $request, Model $record, string $resourceType): void
    {
        $token = $this->clientToken($request);

        if ($token === null || $token === '*') {
            return; // legacy client: no token supplied, keep last-write-wins
        }

        $current = $record->newQuery()
            ->whereKey($record->getKey())
            ->lockForUpdate()
            ->first();

        if (! $current || $this->tokenMatches($token, $current)) {
            return;
        }

        throw new RecordVersionConflictException($current, $resourceType, $token);
    }

    /**
     * Mark the row as changed once the update itself succeeded.
     */
    public function advance(Model $record): void
    {
        $record->newQuery()->whereKey($record->getKey())->increment('lock_version');

        if ($record->getAttribute('lock_version') !== null) {
            $record->setAttribute('lock_version', (int) $record->getAttribute('lock_version') + 1);
        }
    }

    /**
     * Optional soft lock: remember who last saved this record (with expiry)
     * so other users see "being edited by …" on conflict.
     */
    public function recordEditing(Request $request, Model $record, string $resourceType, int $ttlMinutes = 15): void
    {
        $userId = $request->user()?->getKey();

        if ($userId === null) {
            return;
        }

        try {
            $result = $this->locks->acquire($resourceType, (int) $record->getKey(), (int) $userId, $ttlMinutes);

            // The most recent successful editor owns the informational lock,
            // not whoever happened to open the editor first.
            if (($result->acquired ?? false) === false) {
                $this->locks->forceRelease($resourceType, (int) $record->getKey());
                $this->locks->acquire($resourceType, (int) $record->getKey(), (int) $userId, $ttlMinutes);
            }
        } catch (\Throwable $e) {
            // The soft lock is advisory; the enforced guard is the version
            // check above. Bookkeeping failures must not fail a valid save.
            Log::warning('RecordVersionGuard: soft lock bookkeeping failed', [
                'resource_type' => $resourceType,
                'resource_id' => $record->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The 409 body: current state plus current version/updated_at/ETag and,
     * when an active soft lock exists, who holds it and until when.
     */
    public function conflictResponse(RecordVersionConflictException $exception, array $with = []): JsonResponse
    {
        $current = $exception->current;

        if ($with !== []) {
            $current->load($with);
        }

        $body = [
            'message' => 'This document was changed by another user since you loaded it. Reload it and reapply your changes.',
            'code' => self::CODE_STALE,
            'conflict' => true,
            'current_version' => (int) ($current->getAttribute('lock_version') ?? 0),
            'current_updated_at' => optional($current->getAttribute('updated_at'))->toIso8601String(),
            'current_etag' => $this->etag($current),
            'data' => $current,
        ];

        $lock = $this->activeLock($exception->resourceType, (int) $current->getKey());
        if ($lock !== null) {
            $body['active_lock'] = [
                'user_id' => $lock->user_id,
                'expires_at' => optional($lock->expires_at)->toIso8601String(),
            ];
        }

        return response()->json($body, 409);
    }

    public function etag(Model $record): string
    {
        return sprintf(
            '"%d-%d"',
            (int) ($record->getAttribute('lock_version') ?? 0),
            (int) (optional($record->getAttribute('updated_at'))->getTimestamp() ?? 0),
        );
    }

    private function clientToken(Request $request): ?string
    {
        foreach (['version', 'updated_at'] as $field) {
            $value = $request->input($field);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        $ifMatch = $request->header('If-Match');

        return is_string($ifMatch) && trim($ifMatch) !== '' ? trim($ifMatch) : null;
    }

    private function tokenMatches(string $token, Model $record): bool
    {
        $token = preg_replace('/^W\//i', '', trim($token)) ?? $token;
        $token = trim($token, "\"' ");

        // ETag shape "<lock_version>-<unix timestamp>".
        if (preg_match('/^(\d+)-(\d+)$/', $token, $matches) === 1) {
            return (int) $matches[1] === (int) ($record->getAttribute('lock_version') ?? 0)
                && (int) $matches[2] === (int) (optional($record->getAttribute('updated_at'))->getTimestamp() ?? 0);
        }

        // Bare version.
        if (preg_match('/^\d+$/', $token) === 1) {
            return (int) $token === (int) ($record->getAttribute('lock_version') ?? 0);
        }

        // Timestamp token (updated_at echoed from a GET).
        try {
            $updatedAt = $record->getAttribute('updated_at');

            return $updatedAt !== null && Carbon::parse($token)->equalTo($updatedAt);
        } catch (\Throwable) {
            return false;
        }
    }

    private function activeLock(string $resourceType, int $resourceId): ?LockRecord
    {
        try {
            return $this->locks->activeLock($resourceType, $resourceId);
        } catch (\Throwable $e) {
            Log::warning('RecordVersionGuard: active lock lookup failed', [
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
