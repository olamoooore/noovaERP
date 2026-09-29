<?php

namespace App\Services;

use App\Http\Middleware\RequirePermission;
use Illuminate\Support\Facades\DB;

/**
 * Reference-based document tracing.
 *
 * Given a term (PO number, Customer Ref., Internal Ref., project or any
 * document number), find every document that carries it and then walk the
 * document links (quotation → sales order → delivery order → invoice) so the
 * caller receives the whole related family — the traceability source of truth
 * across sales documents.
 */
class DocumentTraceService
{
    private const MATCH_LIMIT = 10;

    private const RESULT_LIMIT = 25;

    public function __construct(private readonly TenantRoleService $roleService) {}

    /**
     * @return array{query: string, groups: array<int, array<string, mixed>>, total: int}
     */
    public function trace(string $term, int $limit = self::RESULT_LIMIT): array
    {
        $term = trim($term);
        $limit = max(1, min($limit, 50));

        if ($term === '') {
            return ['query' => '', 'groups' => [], 'total' => 0];
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        [$allowSales, $allowInvoices] = $this->permissions();
        $ids = ['quotations' => [], 'sales_orders' => [], 'delivery_orders' => [], 'invoices' => []];

        if ($allowSales) {
            $ids['quotations'] = DB::table('quotations')
                ->where(function ($q) use ($like) {
                    $q->where('quotation_number', 'ilike', $like)
                        ->orWhere('customer_reference', 'ilike', $like)
                        ->orWhere('internal_reference', 'ilike', $like);
                })
                ->orderByDesc('id')
                ->limit(self::MATCH_LIMIT)
                ->pluck('id')->all();

            $ids['sales_orders'] = DB::table('sales_orders')
                ->where(function ($q) use ($like) {
                    $q->where('sales_order_number', 'ilike', $like)
                        ->orWhere('customer_reference', 'ilike', $like)
                        ->orWhere('internal_reference', 'ilike', $like)
                        ->orWhere('po_number', 'ilike', $like);
                })
                ->orderByDesc('id')
                ->limit(self::MATCH_LIMIT)
                ->pluck('id')->all();

            $ids['delivery_orders'] = DB::table('delivery_orders')
                ->where(function ($q) use ($like) {
                    $q->where('delivery_order_number', 'ilike', $like)
                        ->orWhere('po_number', 'ilike', $like)
                        ->orWhere('customer_reference', 'ilike', $like)
                        ->orWhere('internal_reference', 'ilike', $like)
                        ->orWhere('external_reference', 'ilike', $like)
                        ->orWhere('tracking_number', 'ilike', $like);
                })
                ->orderByDesc('id')
                ->limit(self::MATCH_LIMIT)
                ->pluck('id')->all();
        }

        if ($allowInvoices) {
            $ids['invoices'] = DB::table('invoices')
                ->where(function ($q) use ($like) {
                    $q->where('invoice_number', 'ilike', $like)
                        ->orWhere('customer_reference', 'ilike', $like)
                        ->orWhere('internal_reference', 'ilike', $like)
                        ->orWhere('po_number', 'ilike', $like)
                        ->orWhere('pr_no', 'ilike', $like)
                        ->orWhere('contract_no', 'ilike', $like)
                        ->orWhere('case_number', 'ilike', $like);
                })
                ->orderByDesc('id')
                ->limit(self::MATCH_LIMIT)
                ->pluck('id')->all();
        }

        // Walk the document links to the full family (bounded to 3 passes).
        for ($pass = 0; $pass < 3; $pass++) {
            $before = array_map('count', $ids);
            $this->expand($ids, $allowSales, $allowInvoices);
            if (array_map('count', $ids) === $before) {
                break;
            }
        }

        $groups = [];
        if ($allowSales) {
            $groups[] = $this->quotationGroup(array_slice(array_unique($ids['quotations']), 0, $limit));
            $groups[] = $this->salesOrderGroup(array_slice(array_unique($ids['sales_orders']), 0, $limit));
            $groups[] = $this->deliveryOrderGroup(array_slice(array_unique($ids['delivery_orders']), 0, $limit));
        }
        if ($allowInvoices) {
            $groups[] = $this->invoiceGroup(array_slice(array_unique($ids['invoices']), 0, $limit));
        }

        $groups = array_values(array_filter($groups, fn ($group) => $group['items'] !== []));
        $total = array_sum(array_map(fn ($group) => count($group['items']), $groups));

        return ['query' => $term, 'groups' => $groups, 'total' => $total];
    }

    /**
     * One expansion pass over the FK links between the document families.
     */
    private function expand(array &$ids, bool $allowSales, bool $allowInvoices): void
    {
        $q = $ids['quotations'];
        $so = $ids['sales_orders'];
        $do = $ids['delivery_orders'];
        $inv = $ids['invoices'];

        $merge = function (string $key, array $values) use (&$ids) {
            $ids[$key] = array_values(array_unique(array_merge($ids[$key], array_filter($values))));
        };

        if ($allowSales && $q !== []) {
            $merge('sales_orders', DB::table('quotations')->whereIn('id', $q)->whereNotNull('sales_order_id')->pluck('sales_order_id')->all());
            $merge('delivery_orders', DB::table('delivery_orders')->whereIn('quotation_id', $q)->pluck('id')->all());
        }

        if ($allowSales && $so !== []) {
            $merge('quotations', DB::table('quotations')->whereIn('sales_order_id', $so)->pluck('id')->all());
            $merge('delivery_orders', DB::table('delivery_orders')->whereIn('sales_order_id', $so)->pluck('id')->all());
        }

        if ($allowInvoices && ($q !== [] || $so !== [])) {
            $invoices = DB::table('invoices');
            $invoices->where(function ($query) use ($q, $so) {
                if ($q !== []) {
                    $query->orWhereIn('quotation_id', $q);
                }
                if ($so !== []) {
                    $query->orWhereIn('sales_order_id', $so);
                }
            });
            $merge('invoices', $invoices->pluck('id')->all());
        }

        if ($allowSales && $do !== []) {
            foreach (DB::table('delivery_orders')->whereIn('id', $do)->get(['quotation_id', 'sales_order_id', 'invoice_id']) as $row) {
                if ($row->quotation_id) {
                    $merge('quotations', [$row->quotation_id]);
                }
                if ($row->sales_order_id) {
                    $merge('sales_orders', [$row->sales_order_id]);
                }
                if ($row->invoice_id) {
                    $merge('invoices', [$row->invoice_id]);
                }
            }
        }

        if ($allowInvoices && $inv !== []) {
            foreach (DB::table('invoices')->whereIn('id', $inv)->get(['quotation_id', 'sales_order_id']) as $row) {
                if ($row->quotation_id) {
                    $merge('quotations', [$row->quotation_id]);
                }
                if ($row->sales_order_id) {
                    $merge('sales_orders', [$row->sales_order_id]);
                }
            }
        }

        // Invoice ↔ delivery order pivot (the linked-invoices smart button).
        if ($do !== [] && $allowInvoices) {
            $merge('invoices', DB::table('invoice_delivery_orders')->whereIn('delivery_order_id', $do)->pluck('invoice_id')->all());
        }
        if ($inv !== [] && $allowSales) {
            $merge('delivery_orders', DB::table('invoice_delivery_orders')->whereIn('invoice_id', $inv)->pluck('delivery_order_id')->all());
            $merge('delivery_orders', DB::table('delivery_orders')->whereIn('invoice_id', $inv)->pluck('id')->all());
        }
    }

    /**
     * @return array{0: bool, 1: bool} [canSales, canInvoices]
     */
    private function permissions(): array
    {
        $user = request()->user();

        if (! $user) {
            return [false, false];
        }

        if (($user->is_super_admin ?? false) || ($user->role ?? null) === 'platform_super_admin') {
            return [true, true];
        }

        $tenantId = RequirePermission::resolveTenantId(request());
        $centralId = RequirePermission::resolveCentralUserId($user);

        if (! $tenantId) {
            return [false, false];
        }

        return [
            $this->roleService->hasPermission($centralId, $tenantId, 'sales.view'),
            $this->roleService->hasPermission($centralId, $tenantId, 'invoices.view'),
        ];
    }

    private function group(string $key, string $label, string $icon, array $items): array
    {
        return ['key' => $key, 'label' => $label, 'icon' => $icon, 'items' => $items];
    }

    private function quotationGroup(array $ids): array
    {
        if ($ids === []) {
            return $this->group('trace_quotations', 'Trace · Quotations', 'solar:file-text-bold-duotone', []);
        }

        $items = DB::table('quotations')
            ->leftJoin('customers', 'customers.id', '=', 'quotations.customer_id')
            ->whereIn('quotations.id', $ids)
            ->orderByDesc('quotations.id')
            ->get(['quotations.id', 'quotations.quotation_number', 'quotations.status', 'quotations.customer_reference', 'quotations.internal_reference', 'customers.name as customer_name'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'title' => $row->quotation_number,
                'subtitle' => $this->subtitle($row->customer_name, $row->status, [$row->customer_reference, $row->internal_reference]),
                'url' => "/quotations/{$row->id}",
                'badges' => array_values(array_filter([$row->customer_reference, $row->internal_reference])),
            ])->all();

        return $this->group('trace_quotations', 'Trace · Quotations', 'solar:file-text-bold-duotone', $items);
    }

    private function salesOrderGroup(array $ids): array
    {
        if ($ids === []) {
            return $this->group('trace_sales_orders', 'Trace · Sales Orders', 'solar:cart-large-2-bold-duotone', []);
        }

        $items = DB::table('sales_orders')
            ->leftJoin('customers', 'customers.id', '=', 'sales_orders.customer_id')
            ->whereIn('sales_orders.id', $ids)
            ->orderByDesc('sales_orders.id')
            ->get(['sales_orders.id', 'sales_orders.sales_order_number', 'sales_orders.status', 'sales_orders.customer_reference', 'sales_orders.internal_reference', 'sales_orders.po_number', 'customers.name as customer_name'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'title' => $row->sales_order_number,
                'subtitle' => $this->subtitle($row->customer_name, $row->status, [$row->customer_reference, $row->internal_reference, $row->po_number]),
                'url' => "/sales/orders/{$row->id}",
                'badges' => array_values(array_filter([$row->customer_reference, $row->internal_reference, $row->po_number])),
            ])->all();

        return $this->group('trace_sales_orders', 'Trace · Sales Orders', 'solar:cart-large-2-bold-duotone', $items);
    }

    private function deliveryOrderGroup(array $ids): array
    {
        if ($ids === []) {
            return $this->group('trace_delivery_orders', 'Trace · Delivery Orders', 'solar:delivery-bold-duotone', []);
        }

        $items = DB::table('delivery_orders')
            ->leftJoin('customers', 'customers.id', '=', 'delivery_orders.customer_id')
            ->whereIn('delivery_orders.id', $ids)
            ->orderByDesc('delivery_orders.id')
            ->get(['delivery_orders.id', 'delivery_orders.delivery_order_number', 'delivery_orders.status', 'delivery_orders.customer_reference', 'delivery_orders.internal_reference', 'delivery_orders.po_number', 'customers.name as customer_name'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'title' => $row->delivery_order_number,
                'subtitle' => $this->subtitle($row->customer_name, $row->status, [$row->po_number, $row->customer_reference, $row->internal_reference]),
                'url' => "/delivery-orders/{$row->id}",
                'badges' => array_values(array_filter([$row->po_number, $row->customer_reference, $row->internal_reference])),
            ])->all();

        return $this->group('trace_delivery_orders', 'Trace · Delivery Orders', 'solar:delivery-bold-duotone', $items);
    }

    private function invoiceGroup(array $ids): array
    {
        if ($ids === []) {
            return $this->group('trace_invoices', 'Trace · Invoices', 'solar:bill-list-bold-duotone', []);
        }

        $items = DB::table('invoices')
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereIn('invoices.id', $ids)
            ->orderByDesc('invoices.id')
            ->get(['invoices.id', 'invoices.invoice_number', 'invoices.status', 'invoices.customer_reference', 'invoices.internal_reference', 'invoices.po_number', 'customers.name as customer_name'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'title' => $row->invoice_number,
                'subtitle' => $this->subtitle($row->customer_name, $row->status, [$row->customer_reference, $row->internal_reference, $row->po_number]),
                'url' => "/invoices/{$row->id}",
                'badges' => array_values(array_filter([$row->customer_reference, $row->internal_reference, $row->po_number])),
            ])->all();

        return $this->group('trace_invoices', 'Trace · Invoices', 'solar:bill-list-bold-duotone', $items);
    }

    private function subtitle(?string $customer, ?string $status, array $references): string
    {
        $reference = array_values(array_filter($references))[0] ?? null;

        return implode(' · ', array_filter([$customer ?? 'No customer', $status, $reference]));
    }
}
