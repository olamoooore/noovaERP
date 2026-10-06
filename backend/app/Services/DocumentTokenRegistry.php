<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DocumentTokenLookup;
use App\Models\Tenant;

/**
 * Issue #466 (DO14) — the one place public document tokens are mapped to their
 * owning tenant.
 *
 * Public document routes (delivery-order and invoice acceptance) must resolve
 * the tenant from the token before tenancy is initialized, and the old
 * implementation did that by scanning every tenant schema until the token was
 * found — O(tenants) per unauthenticated request. Tokens are now registered
 * here when they are issued (e-sign requests, portal acceptance links, sales
 * acceptance links) and resolved with a single indexed lookup.
 *
 * Only the token hash is stored (tokens are bearer credentials in a central
 * table). Expiry is recorded but deliberately NOT enforced at resolution time:
 * the controllers own their existing expired-link semantics (invoice portal
 * pages render an "expired" state, the DO upload rejects with 410) and gating
 * here would change those responses.
 */
class DocumentTokenRegistry
{
    public const TYPE_DELIVERY_ORDER = 'delivery_order';

    public const TYPE_INVOICE = 'invoice';

    public const TYPE_QUOTATION = 'quotation';

    public const TYPE_SALES_ORDER = 'sales_order';

    /**
     * Record (or refresh) the token → tenant mapping. Safe to call outside a
     * tenant context: it is then a no-op, because no tenant can be recorded.
     */
    public function register(
        ?string $token,
        ?string $tenantId,
        string $documentType,
        int|string|null $documentId,
        ?\DateTimeInterface $expiresAt = null,
    ): void {
        if ($token === null || $token === '' || $tenantId === null || $tenantId === '') {
            return;
        }

        $now = now();

        DocumentTokenLookup::query()->upsert(
            [[
                'token_hash' => $this->hash($token),
                'tenant_id' => $tenantId,
                'document_type' => $documentType,
                'document_id' => $documentId !== null ? (int) $documentId : null,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['token_hash'],
            ['tenant_id', 'document_type', 'document_id', 'expires_at', 'updated_at'],
        );
    }

    /**
     * Resolve the owning tenant for a token.
     *
     * The central lookup is authoritative and O(1). Tokens issued before the
     * registry existed (or hand-written rows in older schemas) are found by
     * the optional legacy probe, which is then registered centrally so every
     * subsequent request is O(1) too. The probe receives the candidate tenant
     * and must return null or ['document_id' => int, 'expires_at' => mixed].
     *
     * @param  callable(Tenant, string):?array{document_id?:int|string|null,expires_at?:\DateTimeInterface|null}  $legacyProbe
     */
    public function resolveTenant(string $token, string $documentType, ?callable $legacyProbe = null): ?Tenant
    {
        if ($token === '') {
            return null;
        }

        $row = DocumentTokenLookup::query()
            ->where('token_hash', $this->hash($token))
            ->where('document_type', $documentType)
            ->first();

        if ($row) {
            return Tenant::find($row->tenant_id);
        }

        if ($legacyProbe === null) {
            return null;
        }

        foreach (Tenant::all() as $tenant) {
            try {
                $hit = $legacyProbe($tenant, $token);
            } catch (\Throwable $e) {
                // A tenant whose schema predates the token column/table.
                continue;
            }

            if ($hit === null) {
                continue;
            }

            $this->register(
                $token,
                (string) $tenant->id,
                $documentType,
                $hit['document_id'] ?? null,
                $hit['expires_at'] ?? null,
            );

            return $tenant;
        }

        return null;
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
