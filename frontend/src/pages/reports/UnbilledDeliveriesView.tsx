import { useMemo, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatCard } from '@/components/ui/stat-card';
import { ReportExportToolbar } from '@/components/reports/ReportExportToolbar';
import reportApi from '@/lib/reportApi';
import { formatCurrency, formatNumber } from '@/lib/utils';
import type { ReportFilters as ReportFiltersType } from '@/types/reports';
import { safeDate } from './salesAnalyticsRows';

interface UnbilledDeliveriesViewProps {
  filters: ReportFiltersType;
  periodLabel: string;
  exportRange: string;
}

/**
 * Issue #602: the "unbilled deliveries" tab. Lists the completed
 * delivery-order lines whose delivered quantity still exceeds what has been
 * invoiced — the DO-side companion of the pivot's Qty to Invoice measure —
 * with the delivered / invoiced / to-invoice totals side by side.
 */
export function UnbilledDeliveriesView({
  filters,
  periodLabel,
  exportRange,
}: UnbilledDeliveriesViewProps) {
  const tableRef = useRef<HTMLDivElement>(null);

  const { data, isLoading, error } = useQuery({
    queryKey: ['sales-unbilled-deliveries', filters],
    queryFn: () => reportApi.getUnbilledDeliveries(filters),
    staleTime: 15 * 1000,
  });

  const deliveries = data?.deliveries ?? [];
  const summary = data?.summary;

  const exportRows = useMemo(
    () => [
      [
        'Delivery Order',
        'Delivery Date',
        'Customer',
        'Product',
        'SKU',
        'Qty Delivered',
        'Qty Invoiced',
        'Qty to Invoice',
        'Unit Price',
        'Amount to Invoice (base)',
      ],
      ...(data?.deliveries ?? []).map((line) => [
        line.delivery_order_number,
        safeDate(line.delivery_date),
        line.customer_name ?? 'Walk-in Customer',
        line.product_name ?? 'Unknown product',
        line.sku ?? '',
        formatNumber(line.quantity_delivered),
        formatNumber(line.quantity_invoiced),
        formatNumber(line.quantity_to_invoice),
        formatCurrency(line.unit_price, line.currency_code ?? undefined),
        formatCurrency(line.amount_to_invoice),
      ]),
    ],
    [data],
  );

  return (
    <div className="space-y-6">
      <Card className="rounded-xl">
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
          <div>
            <CardTitle className="text-base">Unbilled Deliveries</CardTitle>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Delivered goods still awaiting an invoice · {periodLabel}
            </p>
          </div>
          <ReportExportToolbar
            targetRef={tableRef}
            title="Unbilled Deliveries"
            subtitle={`For the period ${periodLabel}`}
            filename={`unbilled-deliveries-${exportRange}`}
            rows={exportRows}
            columnAligns={['left', 'left', 'left', 'left', 'left', 'right', 'right', 'right', 'right', 'right']}
          />
        </CardHeader>

        <CardContent className="space-y-6 pt-6" ref={tableRef}>
          {isLoading && (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="svg-spinners:ring-resize" className="mx-auto h-8 w-8" />
              <p className="mt-2">Loading unbilled deliveries…</p>
            </div>
          )}

          {!isLoading && error && (
            <div className="py-12 text-center text-destructive">
              <p className="font-medium">Failed to load unbilled deliveries</p>
              <p className="mt-1 text-sm">{(error as Error).message}</p>
            </div>
          )}

          {!isLoading && !error && (
            <>
              <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard
                  label="Qty Delivered"
                  value={formatNumber(summary?.total_quantity_delivered ?? 0)}
                  icon="solar:box-bold-duotone"
                  tone="bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400"
                />
                <StatCard
                  label="Qty Invoiced"
                  value={formatNumber(summary?.total_quantity_invoiced ?? 0)}
                  icon="solar:document-text-bold-duotone"
                  tone="bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400"
                />
                <StatCard
                  label="Qty to Invoice"
                  value={formatNumber(summary?.total_quantity_to_invoice ?? 0)}
                  icon="solar:danger-triangle-bold-duotone"
                  tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
                />
                <StatCard
                  label="Amount to Invoice"
                  value={formatCurrency(summary?.total_amount_to_invoice ?? 0)}
                  icon="solar:wallet-money-bold-duotone"
                  tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"
                />
              </div>

              {deliveries.length === 0 ? (
                <div className="py-12 text-center text-muted-foreground">
                  <Icon icon="solar:check-circle-bold-duotone" className="mx-auto mb-3 h-12 w-12 opacity-50" />
                  <p>Everything delivered has been invoiced for this period.</p>
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b bg-muted/40">
                        <th className="px-3 py-2 text-left font-medium">Delivery Order</th>
                        <th className="px-3 py-2 text-left font-medium">Date</th>
                        <th className="px-3 py-2 text-left font-medium">Customer</th>
                        <th className="px-3 py-2 text-left font-medium">Product</th>
                        <th className="px-3 py-2 text-left font-medium">SKU</th>
                        <th className="px-3 py-2 text-right font-medium">Qty Delivered</th>
                        <th className="px-3 py-2 text-right font-medium">Qty Invoiced</th>
                        <th className="px-3 py-2 text-right font-medium">Qty to Invoice</th>
                        <th className="px-3 py-2 text-right font-medium">Unit Price</th>
                        <th className="px-3 py-2 text-right font-medium">Amount to Invoice</th>
                      </tr>
                    </thead>
                    <tbody>
                      {deliveries.map((line) => (
                        <tr key={line.delivery_order_line_id ?? `${line.delivery_order_id}-${line.product_id}`} className="border-b last:border-b-0">
                          <td className="whitespace-nowrap px-3 py-2 font-medium">{line.delivery_order_number}</td>
                          <td className="whitespace-nowrap px-3 py-2">{safeDate(line.delivery_date)}</td>
                          <td className="px-3 py-2">{line.customer_name ?? 'Walk-in Customer'}</td>
                          <td className="px-3 py-2">{line.product_name ?? 'Unknown product'}</td>
                          <td className="px-3 py-2 text-muted-foreground">{line.sku ?? '—'}</td>
                          <td className="px-3 py-2 text-right tabular-nums">{formatNumber(line.quantity_delivered)}</td>
                          <td className="px-3 py-2 text-right tabular-nums">{formatNumber(line.quantity_invoiced)}</td>
                          <td className="px-3 py-2 text-right font-semibold tabular-nums text-amber-600 dark:text-amber-400">
                            {formatNumber(line.quantity_to_invoice)}
                          </td>
                          <td className="px-3 py-2 text-right tabular-nums">
                            {formatCurrency(line.unit_price, line.currency_code ?? undefined)}
                          </td>
                          <td className="px-3 py-2 text-right font-semibold tabular-nums">
                            {formatCurrency(line.amount_to_invoice)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                    <tfoot>
                      <tr className="border-t-2 font-semibold">
                        <td className="px-3 py-2" colSpan={5}>
                          Total · {summary?.line_count ?? 0} line(s) on {summary?.delivery_order_count ?? 0} delivery order(s)
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums">
                          {formatNumber(summary?.total_quantity_delivered ?? 0)}
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums">
                          {formatNumber(summary?.total_quantity_invoiced ?? 0)}
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums">
                          {formatNumber(summary?.total_quantity_to_invoice ?? 0)}
                        </td>
                        <td className="px-3 py-2" />
                        <td className="px-3 py-2 text-right tabular-nums">
                          {formatCurrency(summary?.total_amount_to_invoice ?? 0)}
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

export default UnbilledDeliveriesView;
