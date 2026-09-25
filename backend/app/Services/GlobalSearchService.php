<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Cross-module search used by the top-bar omnibox.
 *
 * Searches documents (invoices, quotations, sales orders, delivery orders),
 * master data (customers, products) and tags by a free-text term, and can be
 * narrowed with a list of tag slugs. Results are grouped by type with the
 * frontend URL each hit should open.
 */
class GlobalSearchService
{
    private const LIMIT_PER_GROUP = 6;

    private const GROUP_PERMISSIONS = [
        'invoices' => 'invoices.view',
        'quotations' => 'sales.view',
        'sales_orders' => 'sales.view',
        'delivery_orders' => 'sales.view',
        'customers' => 'customers.view',
        'products' => 'products.view',
    ];

    private ?bool $isSuperAdmin = null;

    private ?int $centralUserId = null;

    private ?string $tenantId = null;

    public function __construct(private readonly TenantRoleService $roleService) {}

    /**
     * @param  array<int, string>  $tagSlugs
     * @return array{query: string, tag_slugs: array<int, string>, groups: array<int, array<string, mixed>>, total: int}
     */
    public function search(string $query, array $tagSlugs = [], int $limit = self::LIMIT_PER_GROUP): array
    {
        $term = trim($query);
        $tagSlugs = array_values(array_filter(array_map('trim', $tagSlugs)));
        $limit = max(1, min($limit, 25));

        $groups = [];

        if ($term !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

            if ($this->canSearch('invoices')) {
                $groups[] = $this->group('invoices', 'Invoices', 'solar:bill-list-bold-duotone', $this->invoices($like, $tagSlugs, $limit));
            }
            if ($this->canSearch('quotations')) {
                $groups[] = $this->group('quotations', 'Quotations', 'solar:file-text-bold-duotone', $this->quotations($like, $tagSlugs, $limit));
            }
            if ($this->canSearch('sales_orders')) {
                $groups[] = $this->group('sales_orders', 'Sales Orders', 'solar:cart-large-2-bold-duotone', $this->salesOrders($like, $tagSlugs, $limit));
            }
            if ($this->canSearch('delivery_orders')) {
                $groups[] = $this->group('delivery_orders', 'Delivery Orders', 'solar:delivery-bold-duotone', $this->deliveryOrders($like, $tagSlugs, $limit));
            }
            if ($this->canSearch('customers')) {
                $groups[] = $this->group('customers', 'Customers', 'solar:users-group-rounded-bold-duotone', $this->customers($like, $tagSlugs, $limit));
            }
            if ($this->canSearch('products')) {
                $groups[] = $this->group('products', 'Products', 'solar:box-bold-duotone', $this->products($like, $limit));
            }
            $groups[] = $this->group('tags', 'Tags', 'solar:tag-bold-duotone', $this->tags($like));
        } elseif ($tagSlugs !== []) {
            // Tag-only browse: everything carrying the selected labels.
            if ($this->canSearch('invoices')) {
                $groups[] = $this->group('invoices', 'Invoices', 'solar:bill-list-bold-duotone', $this->invoices('%', $tagSlugs, $limit));
            }
            if ($this->canSearch('quotations')) {
                $groups[] = $this->group('quotations', 'Quotations', 'solar:file-text-bold-duotone', $this->quotations('%', $tagSlugs, $limit));
            }
            if ($this->canSearch('sales_orders')) {
                $groups[] = $this->group('sales_orders', 'Sales Orders', 'solar:cart-large-2-bold-duotone', $this->salesOrders('%', $tagSlugs, $limit));
            }
            if ($this->canSearch('delivery_orders')) {
                $groups[] = $this->group('delivery_orders', 'Delivery Orders', 'solar:delivery-bold-duotone', $this->deliveryOrders('%', $tagSlugs, $limit));
            }
            if ($this->canSearch('customers')) {
                $groups[] = $this->group('customers', 'Customers', 'solar:users-group-rounded-bold-duotone', $this->customers('%', $tagSlugs, $limit));
            }
        }

        $groups = array_values(array_filter($groups, fn ($g) => $g['items'] !== []));
        $total = array_sum(array_map(fn ($g) => count($g['items']), $groups));

        return [
            'query' => $term,
            'tag_slugs' => $tagSlugs,
            'groups' => $groups,
            'total' => $total,
        ];
    }

    private function canSearch(string $group): bool
    {
        $permission = self::GROUP_PERMISSIONS[$group] ?? null;

        if ($permission === null) {
            return true;
        }

        $user = request()->user();

        if (! $user) {
            return false;
        }

        if ($this->isSuperAdmin === null) {
            $this->isSuperAdmin = ($user->is_super_admin ?? false) || ($user->role ?? null) === 'platform_super_admin';
        }

        if ($this->isSuperAdmin) {
            return true;
        }

        if ($this->tenantId === null) {
            $this->tenantId = (function_exists('tenant') && tenant())
                ? (string) tenant('id')
                : (string) request()->header('X-Tenant-ID', '');

            $this->centralUserId = (int) (DB::table('public.users')->where('email', $user->email)->value('id') ?? $user->id);
        }

        if ($this->tenantId === '') {
            return false;
        }

        return $this->roleService->hasPermission((int) $this->centralUserId, $this->tenantId, $permission);
    }

    private function group(string $key, string $label, string $icon, array $items): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'items' => $items,
        ];
    }

    /**
     * Restrict a query to rows carrying at least one of the given tag slugs.
     */
    private function applyTagFilter($query, string $modelClass, array $tagSlugs): void
    {
        if ($tagSlugs === []) {
            return;
        }

        $query->whereIn('id', function ($sub) use ($modelClass, $tagSlugs) {
            $sub->select('taggables.taggable_id')
                ->from('taggables')
                ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                ->where('taggables.taggable_type', (new $modelClass)->getMorphClass())
                ->whereIn('tags.slug', $tagSlugs);
        });
    }

    private function tagsOf(string $type, int $id): array
    {
        return DB::table('taggables')
            ->join('tags', 'tags.id', '=', 'taggables.tag_id')
            ->where('taggables.taggable_type', $type)
            ->where('taggables.taggable_id', $id)
            ->orderBy('tags.name')
            ->pluck('tags.name')
            ->all();
    }

    private function invoices(string $like, array $tagSlugs, int $limit): array
    {
        $query = Invoice::query()
            ->with('customer:id,name')
            ->where(function ($q) use ($like) {
                $q->where('invoice_number', 'ilike', $like)
                    ->orWhere('customer_reference', 'ilike', $like)
                    ->orWhere('po_number', 'ilike', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', $like));
            })
            ->orderByDesc('id')
            ->limit($limit);

        $this->applyTagFilter($query, Invoice::class, $tagSlugs);

        return $query->get()->map(fn (Invoice $i) => [
            'id' => $i->id,
            'title' => $i->invoice_number,
            'subtitle' => trim(($i->customer?->name ?? 'No customer').' · '.$i->status),
            'url' => "/invoices/{$i->id}",
            'badges' => $this->tagsOf((new Invoice)->getMorphClass(), $i->id),
        ])->all();
    }

    private function quotations(string $like, array $tagSlugs, int $limit): array
    {
        $query = Quotation::query()
            ->with('customer:id,name')
            ->where(function ($q) use ($like) {
                $q->where('quotation_number', 'ilike', $like)
                    ->orWhere('customer_reference', 'ilike', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', $like));
            })
            ->orderByDesc('id')
            ->limit($limit);

        $this->applyTagFilter($query, Quotation::class, $tagSlugs);

        return $query->get()->map(fn (Quotation $q) => [
            'id' => $q->id,
            'title' => $q->quotation_number,
            'subtitle' => trim(($q->customer?->name ?? 'No customer').' · '.$q->status),
            'url' => "/quotations/{$q->id}",
            'badges' => $this->tagsOf((new Quotation)->getMorphClass(), $q->id),
        ])->all();
    }

    private function salesOrders(string $like, array $tagSlugs, int $limit): array
    {
        $model = \Modules\Sales\Models\SalesOrder::class;

        $query = $model::query()
            ->with('customer:id,name')
            ->where(function ($q) use ($like) {
                $q->where('sales_order_number', 'ilike', $like)
                    ->orWhere('customer_reference', 'ilike', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', $like));
            })
            ->orderByDesc('id')
            ->limit($limit);

        $this->applyTagFilter($query, $model, $tagSlugs);

        return $query->get()->map(fn ($o) => [
            'id' => $o->id,
            'title' => $o->sales_order_number,
            'subtitle' => trim(($o->customer?->name ?? 'No customer').' · '.$o->status),
            'url' => "/sales/orders/{$o->id}",
            'badges' => $this->tagsOf((new $model)->getMorphClass(), $o->id),
        ])->all();
    }

    private function deliveryOrders(string $like, array $tagSlugs, int $limit): array
    {
        $model = \Modules\Sales\Models\DeliveryOrder::class;

        $query = $model::query()
            ->with('customer:id,name')
            ->where(function ($q) use ($like) {
                $q->where('delivery_order_number', 'ilike', $like)
                    ->orWhere('tracking_number', 'ilike', $like)
                    ->orWhere('po_number', 'ilike', $like)
                    ->orWhere('external_reference', 'ilike', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', $like));
            })
            ->orderByDesc('id')
            ->limit($limit);

        $this->applyTagFilter($query, $model, $tagSlugs);

        return $query->get()->map(fn ($d) => [
            'id' => $d->id,
            'title' => $d->delivery_order_number,
            'subtitle' => trim(($d->customer?->name ?? 'No customer').' · '.$d->status),
            'url' => "/delivery-orders/{$d->id}",
            'badges' => $this->tagsOf((new $model)->getMorphClass(), $d->id),
        ])->all();
    }

    private function customers(string $like, array $tagSlugs, int $limit): array
    {
        $query = Customer::query()
            ->where(function ($q) use ($like) {
                $q->where('name', 'ilike', $like)
                    ->orWhere('code', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like);
            })
            ->orderBy('name')
            ->limit($limit);

        $this->applyTagFilter($query, Customer::class, $tagSlugs);

        return $query->get()->map(fn (Customer $c) => [
            'id' => $c->id,
            'title' => $c->name,
            'subtitle' => trim(($c->email ?: $c->phone ?: 'Customer')),
            'url' => "/customers/{$c->id}",
            'badges' => $this->tagsOf((new Customer)->getMorphClass(), $c->id),
        ])->all();
    }

    private function products(string $like, int $limit): array
    {
        return \App\Models\Product::query()
            ->where(function ($q) use ($like) {
                $q->where('name', 'ilike', $like)
                    ->orWhere('sku', 'ilike', $like)
                    ->orWhere('manufacturer_part_number', 'ilike', $like);
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'sku'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->name,
                'subtitle' => $p->sku ? "SKU {$p->sku}" : 'Product',
                'url' => "/products/{$p->id}",
                'badges' => [],
            ])->all();
    }

    private function tags(string $like): array
    {
        return Tag::query()
            ->where('name', 'ilike', $like)
            ->withCount('invoices')
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn (Tag $t) => [
                'id' => $t->id,
                'title' => $t->name,
                'subtitle' => 'Tag · filter all documents',
                'url' => "/search?tag={$t->slug}",
                'badges' => [$t->name],
                'tag_slug' => $t->slug,
            ])->all();
    }
}
