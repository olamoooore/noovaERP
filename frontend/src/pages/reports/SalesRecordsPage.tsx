import { useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import reportApi from '@/lib/reportApi';
import type {
  SalesRecordsFilters,
  SalesRecordsGroupBy,
} from '@/types/reports';
import {
  buildSalesRecordsDisplayItems,
  buildSalesRecordsExportRows,
  salesRecordsMoney as money,
  salesRecordsQty as qty,
} from './salesRecordsView';
import { Card, CardContent } from '@/components/ui/card';
import { StatCard } from '@/components/ui/stat-card';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox';
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox';
import { ReportExportToolbar } from '@/components/reports/ReportExportToolbar';
import { formatDate, formatNumber } from '@/lib/utils';

/**
 * #1020: the client's "Sales Records" sheet reproduced in the report module.
 * One row per invoice (customer, date, invoice number, lot), Qty/S$ column
 * pairs for the chosen grouping (By item code / brand / supplier / customer /
 * category) and the sheet's monthly totals. Qty includes FOC units; S$ is net
 * of tax (see SalesRecordsService).
 */

export default function SalesRecordsPage() {
  const now = new Date();
  const [filters, setFilters] = useState<SalesRecordsFilters>({
    start_date: `${now.getFullYear()}-01-01`,
    end_date: now.toISOString().split('T')[0],
    group_by: 'item_code',
  });
  const [itemCodeInput, setItemCodeInput] = useState('');

  const update = (patch: Partial<SalesRecordsFilters>) =>
    setFilters((current) => ({ ...current, ...patch }));

  const { data, isLoading, error } = useQuery({
    queryKey: ['sales-records', filters],
    queryFn: () => reportApi.getSalesRecords(filters),
    staleTime: 15 * 1000,
  });

  const { data: brands = [] } = useQuery({
    queryKey: ['sales-records', 'brands'],
    queryFn: async () => {
      const res = await api.get('/brands', { params: { per_page: 100, is_active: true } });
      return (res.data?.data ?? res.data ?? []) as Array<{ id: number; name: string }>;
    },
    staleTime: 5 * 60 * 1000,
  });

  const { data: suppliers = [] } = useQuery({
    queryKey: ['sales-records', 'suppliers'],
    queryFn: async () => {
      const res = await api.get('/suppliers', { params: { per_page: 100, is_active: true } });
      return (res.data?.data ?? res.data ?? []) as Array<{ id: number; name: string }>;
    },
    staleTime: 5 * 60 * 1000,
  });

  const { data: categories = [] } = useQuery({
    queryKey: ['sales-records', 'categories'],
    queryFn: async () => {
      const res = await api.get('/product-categories');
      return (Array.isArray(res.data) ? res.data : res.data?.data ?? []) as Array<{ id: number; name: string }>;
    },
    staleTime: 5 * 60 * 1000,
  });

  const columns = data?.columns ?? [];

  const displayItems = useMemo(
    () => buildSalesRecordsDisplayItems(data?.rows ?? [], data?.month_totals ?? []),
    [data],
  );

  const exportRows = useMemo(() => buildSalesRecordsExportRows(data), [data]);

  const reportRef = useRef<HTMLElement | null>(null);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Sales Records</h1>
          <p className="mt-1 text-muted-foreground">
            Invoice records with monthly totals — By item code, brand, supplier, customer or category
          </p>
        </div>
        <ReportExportToolbar
          targetRef={reportRef}
          title="Sales Records"
          subtitle={data ? `${data.period.start_date} — ${data.period.end_date}` : undefined}
          filename="sales-records"
          rows={exportRows}
        />
      </div>

      {/* Filters */}
      <Card className="rounded-xl">
        <CardContent className="grid gap-3 pt-6 md:grid-cols-3 xl:grid-cols-4">
          <div className="space-y-1.5">
            <Label htmlFor="sales-records-from">From</Label>
            <Input
              id="sales-records-from"
              type="date"
              value={filters.start_date ?? ''}
              onChange={(event) => update({ start_date: event.target.value || undefined })}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="sales-records-to">To</Label>
            <Input
              id="sales-records-to"
              type="date"
              value={filters.end_date ?? ''}
              onChange={(event) => update({ end_date: event.target.value || undefined })}
            />
          </div>
          <div className="space-y-1.5">
            <Label>Group by</Label>
            <Select
              value={filters.group_by ?? 'item_code'}
              onValueChange={(value) => update({ group_by: value as SalesRecordsGroupBy })}
            >
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="item_code">By item code</SelectItem>
                <SelectItem value="brand">By brand</SelectItem>
                <SelectItem value="supplier">By supplier</SelectItem>
                <SelectItem value="customer">By customer</SelectItem>
                <SelectItem value="category">By category</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label>Product</Label>
            <ProductSearchCombobox
              value={filters.product_id ? String(filters.product_id) : ''}
              onValueChange={(value) => update({ product_id: value ? Number(value) : undefined })}
              placeholder="All products"
              activeOnly={false}
            />
          </div>
          <div className="space-y-1.5">
            <Label>Brand</Label>
            <Select
              value={filters.brand_id ? String(filters.brand_id) : 'all'}
              onValueChange={(value) => update({ brand_id: value === 'all' ? undefined : Number(value) })}
            >
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All brands</SelectItem>
                {brands.map((brand) => (
                  <SelectItem key={brand.id} value={String(brand.id)}>{brand.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label>Supplier</Label>
            <Select
              value={filters.supplier_id ? String(filters.supplier_id) : 'all'}
              onValueChange={(value) => update({ supplier_id: value === 'all' ? undefined : Number(value) })}
            >
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All suppliers</SelectItem>
                {suppliers.map((supplier) => (
                  <SelectItem key={supplier.id} value={String(supplier.id)}>{supplier.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label>Category</Label>
            <Select
              value={filters.category_id ? String(filters.category_id) : 'all'}
              onValueChange={(value) => update({ category_id: value === 'all' ? undefined : Number(value) })}
            >
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All categories</SelectItem>
                {categories.map((category) => (
                  <SelectItem key={category.id} value={String(category.id)}>{category.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label>Customer</Label>
            <CustomerSearchCombobox
              value={filters.customer_id ? String(filters.customer_id) : ''}
              onValueChange={(value) => update({ customer_id: value ? Number(value) : undefined })}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="sales-records-item-code">Item code</Label>
            <Input
              id="sales-records-item-code"
              value={itemCodeInput}
              placeholder="e.g. F240115150"
              onChange={(event) => setItemCodeInput(event.target.value)}
              onBlur={() => update({ item_code: itemCodeInput || undefined })}
              onKeyDown={(event) => {
                if (event.key === 'Enter') update({ item_code: itemCodeInput || undefined });
              }}
            />
          </div>
        </CardContent>
      </Card>

      {/* Summary */}
      {data && (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
          <StatCard label="Invoices" value={formatNumber(data.summary.invoice_count)} icon="solar:document-text-bold-duotone" tone="bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400" />
          <StatCard label="Quantity (incl. FOC)" value={formatNumber(data.summary.total_quantity)} icon="solar:box-bold-duotone" tone="bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400" />
          <StatCard label="Total S$ (net)" value={money(data.summary.total_value)} icon="solar:wallet-money-bold-duotone" tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400" />
        </div>
      )}

      {/* Report table */}
      <Card className="rounded-xl">
        <CardContent className="overflow-x-auto pt-6">
          {isLoading && <div className="py-12 text-center text-muted-foreground">Loading report…</div>}
          {!isLoading && error && (
            <div className="py-12 text-center text-destructive">Failed to load the Sales Records report.</div>
          )}
          {!isLoading && !error && data && (
            <div ref={(node) => { reportRef.current = node; }}>
              {data.rows.length === 0 ? (
                <div className="py-12 text-center text-muted-foreground">No sales records in this period.</div>
              ) : (
                <table className="w-full min-w-[1100px] border-collapse text-sm">
                  <thead>
                    <tr className="border-b bg-muted/40">
                      <th className="px-3 py-2 text-left font-medium">Customer Name</th>
                      <th className="px-3 py-2 text-left font-medium">Date of Invoice/DO</th>
                      <th className="px-3 py-2 text-left font-medium">Invoice/DO Number</th>
                      <th className="px-3 py-2 text-left font-medium">Lot Number</th>
                      {columns.map((column) => (
                        <th key={column.key} colSpan={2} className="border-l px-3 py-2 text-center font-medium">
                          <div>{column.label}</div>
                          {column.code && column.code !== column.label && (
                            <div className="text-[10px] font-normal text-muted-foreground">{column.code}</div>
                          )}
                        </th>
                      ))}
                      <th className="border-l px-3 py-2 text-right font-medium">Total</th>
                      <th className="border-l px-3 py-2 text-left font-medium">Bonusing Scheme</th>
                      <th className="px-3 py-2 text-left font-medium">Due Date</th>
                      <th className="px-3 py-2 text-left font-medium">Status</th>
                    </tr>
                    <tr className="border-b bg-muted/20 text-xs text-muted-foreground">
                      <th />
                      <th />
                      <th />
                      <th />
                      {columns.flatMap((column) => [
                        <th key={`${column.key}-qty`} className="border-l px-3 py-1 text-right font-medium">Qty</th>,
                        <th key={`${column.key}-value`} className="px-3 py-1 text-right font-medium">S$</th>,
                      ])}
                      <th className="border-l px-3 py-1 text-right font-medium">S$</th>
                      <th />
                      <th />
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {displayItems.map((item) =>
                      item.kind === 'row' ? (
                        <tr key={`row-${item.row.invoice_id}`} className="border-b last:border-b-0">
                          <td className="px-3 py-2">{item.row.customer_name}</td>
                          <td className="px-3 py-2 whitespace-nowrap">{formatDate(item.row.date)}</td>
                          <td className="px-3 py-2 whitespace-nowrap">{item.row.document_number}</td>
                          <td className="px-3 py-2 text-muted-foreground">{item.row.lot_number || '—'}</td>
                          {columns.flatMap((column) => {
                            const value = item.row.values[column.key];
                            return [
                              <td key={`${item.row.invoice_id}-${column.key}-qty`} className="border-l px-3 py-2 text-right tabular-nums">
                                {value ? qty(value.quantity) : '—'}
                              </td>,
                              <td key={`${item.row.invoice_id}-${column.key}-value`} className="px-3 py-2 text-right tabular-nums">
                                {value ? money(value.value) : '—'}
                              </td>,
                            ];
                          })}
                          <td className="border-l px-3 py-2 text-right font-medium tabular-nums">{money(item.row.total)}</td>
                          <td className="border-l px-3 py-2">{item.row.bonus_scheme ?? '—'}</td>
                          <td className="px-3 py-2 whitespace-nowrap">{item.row.due_date ? formatDate(item.row.due_date) : '—'}</td>
                          <td className="px-3 py-2 whitespace-nowrap">{item.row.status}</td>
                        </tr>
                      ) : (
                        <tr key={`total-${item.total.month}`} className="border-b bg-muted/40 font-semibold last:border-b-0">
                          <td className="px-3 py-2" colSpan={4}>{item.total.label} Total</td>
                          {columns.flatMap((column) => {
                            const value = item.total.values[column.key];
                            return [
                              <td key={`${item.total.month}-${column.key}-qty`} className="border-l px-3 py-2 text-right tabular-nums">
                                {value ? qty(value.quantity) : '—'}
                              </td>,
                              <td key={`${item.total.month}-${column.key}-value`} className="px-3 py-2 text-right tabular-nums">
                                {value ? money(value.value) : '—'}
                              </td>,
                            ];
                          })}
                          <td className="border-l px-3 py-2 text-right tabular-nums">{money(item.total.total)}</td>
                          <td className="border-l px-3 py-2" colSpan={3} />
                        </tr>
                      ),
                    )}
                  </tbody>
                </table>
              )}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
