<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPortalLink;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;

/**
 * Issue #337 — customer portal: a signed, expiring, revocable per-customer
 * link (no customer login accounts exist in the Sales/AR domain).
 *
 * Security model:
 *   - The token is 64 random characters, shown to staff ONCE. Only its
 *     SHA-256 hash is stored, both in the tenant `customer_portal_links` row
 *     and in the central #466 `public.document_token_lookup` registry that the
 *     public route uses to resolve the owning tenant in one indexed lookup.
 *   - A link is scoped to exactly one customer and, when issued under a legal
 *     entity, to that entity's invoices, orders and quotations. Every
 *     document route re-checks the document against that scope; anything
 *     outside it is a 404 (never a 403, which would confirm the id exists).
 *   - Revocation removes the central mapping, so a revoked token no longer
 *     even resolves a tenant.
 */
class CustomerPortalService
{
    public const DEFAULT_TTL_DAYS = 30;

    public const MAX_TTL_DAYS = 90;

    /** Quotation statuses a customer may see: sent (open) or confirmed (accepted). */
    public const VISIBLE_QUOTATION_STATUSES = ['sent', 'confirmed'];

    /**
     * Issue #337 (re-verification): OPEN sales orders a customer may see —
     * confirmed and not yet fully invoiced or cancelled (draft orders were
     * never sent; invoiced orders surface as their invoices).
     */
    public const OPEN_ORDER_STATUSES = ['confirmed', 'allocated', 'fulfilled'];

    public function __construct(private DocumentTokenRegistry $registry) {}

    /**
     * Issue a new portal link. Returns the raw token exactly once.
     *
     * @return array{link: CustomerPortalLink, token: string, url: string}
     */
    public function issue(Customer $customer, ?int $legalEntityId, int $ttlDays, ?int $userId, ?string $label = null): array
    {
        $ttlDays = max(1, min(self::MAX_TTL_DAYS, $ttlDays));
        $token = Str::random(64);
        $hash = $this->registry->hash($token);
        $expiresAt = now()->addDays($ttlDays);

        $link = DB::transaction(function () use ($customer, $legalEntityId, $hash, $expiresAt, $userId, $label, $token) {
            $link = CustomerPortalLink::query()->create([
                'customer_id' => $customer->id,
                'legal_entity_id' => $legalEntityId,
                'token_hash' => $hash,
                'label' => $label,
                'expires_at' => $expiresAt,
                'created_by' => $userId,
            ]);

            $this->registry->register(
                $token,
                tenant('id'),
                DocumentTokenRegistry::TYPE_CUSTOMER_PORTAL,
                $link->getKey(),
                $expiresAt,
            );

            return $link;
        });

        return [
            'link' => $link,
            'token' => $token,
            'url' => $this->portalUrl($token),
        ];
    }

    public function revoke(CustomerPortalLink $link, ?int $userId): void
    {
        DB::transaction(function () use ($link, $userId) {
            if ($link->revoked_at === null) {
                $link->forceFill(['revoked_at' => now(), 'revoked_by' => $userId])->save();
            }

            $this->registry->forgetHash((string) $link->token_hash, DocumentTokenRegistry::TYPE_CUSTOMER_PORTAL);
        });
    }

    /** The tenant-schema link for a raw token, or null. */
    public function findByToken(string $token): ?CustomerPortalLink
    {
        if ($token === '' || strlen($token) > 255) {
            return null;
        }

        return CustomerPortalLink::query()
            ->where('token_hash', $this->registry->hash($token))
            ->first();
    }

    /**
     * Owning tenant of a portal token via the central #466 registry. No
     * legacy all-tenant probe: portal tokens only ever exist registered.
     */
    public function resolveTenantForToken(string $token): ?Tenant
    {
        return $this->registry->resolveTenant($token, DocumentTokenRegistry::TYPE_CUSTOMER_PORTAL);
    }

    public function portalUrl(string $token): string
    {
        return rtrim((string) config('frontend.url', config('app.url')), '/').'/customer-portal/'.$token;
    }

    /** Posted, active-version invoices of the link's customer (and entity). */
    public function invoicesQuery(CustomerPortalLink $link): Builder
    {
        $query = Invoice::query()
            ->where('customer_id', $link->customer_id)
            ->where('lifecycle_status', 'posted')
            ->where('is_active_version', true);

        if ($link->legal_entity_id !== null) {
            // Fail closed: an invoice with no entity is not shown on an
            // entity-scoped portal.
            $query->where('legal_entity_id', $link->legal_entity_id);
        }

        return $query;
    }

    /**
     * Sent/accepted quotations of the link's customer (and entity).
     *
     * Quotations carry no legal entity column. On an entity-scoped link they
     * follow the platform rule (SalesDocumentEntityScope: a quotation belongs
     * to its customer's entity), but fail closed like invoices do:
     *   - a quotation converted into an order belongs to that ORDER's entity
     *     (the one real per-document signal), so it is shown only when the
     *     order is in the link's entity;
     *   - an unconverted quotation (or one whose order has no entity) is
     *     shown only when the customer itself belongs to the link's entity —
     *     a shared customer (no entity) could hold quotations for any entity,
     *     so none of those are shown.
     */
    public function quotationsQuery(CustomerPortalLink $link): Builder
    {
        $query = Quotation::query()
            ->where('quotations.customer_id', $link->customer_id)
            ->whereIn('quotations.status', self::VISIBLE_QUOTATION_STATUSES);

        if ($link->legal_entity_id === null) {
            return $query;
        }

        $entityId = (int) $link->legal_entity_id;
        $customerInEntity = Customer::query()
            ->whereKey($link->customer_id)
            ->where('legal_entity_id', $entityId)
            ->exists();

        return $query->where(function (Builder $scope) use ($entityId, $customerInEntity) {
            $scope->whereIn('quotations.sales_order_id', fn ($orders) => $orders->select('id')
                ->from('sales_orders')
                ->where('legal_entity_id', $entityId));

            if ($customerInEntity) {
                $scope->orWhere(fn (Builder $unconverted) => $unconverted
                    ->whereNull('quotations.sales_order_id')
                    ->orWhereIn('quotations.sales_order_id', fn ($orders) => $orders->select('id')
                        ->from('sales_orders')
                        ->whereNull('legal_entity_id')));
            }
        });
    }

    /**
     * Open sales orders of the link's customer (and entity). Orders carry
     * their own legal entity (#140); like invoices, an order with no entity
     * is not shown on an entity-scoped portal (fail closed).
     */
    public function salesOrdersQuery(CustomerPortalLink $link): Builder
    {
        $query = SalesOrder::query()
            ->where('customer_id', $link->customer_id)
            ->whereIn('status', self::OPEN_ORDER_STATUSES);

        if ($link->legal_entity_id !== null) {
            $query->where('legal_entity_id', $link->legal_entity_id);
        }

        return $query;
    }

    /**
     * The statement service is not entity-aware. On an entity-scoped link it
     * is only served when every statement document of the customer belongs to
     * that entity, so it can never reveal another entity's activity.
     */
    public function statementWouldMixEntities(CustomerPortalLink $link): bool
    {
        if ($link->legal_entity_id === null) {
            return false;
        }

        foreach (['invoices', 'payments', 'customer_credit_notes'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'legal_entity_id')) {
                continue;
            }

            $mixed = DB::table($table)
                ->where('customer_id', $link->customer_id)
                ->where(function ($q) use ($link) {
                    $q->whereNull('legal_entity_id')
                        ->orWhere('legal_entity_id', '!=', $link->legal_entity_id);
                })
                ->when(Schema::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->exists();

            if ($mixed) {
                return true;
            }
        }

        return false;
    }
}
