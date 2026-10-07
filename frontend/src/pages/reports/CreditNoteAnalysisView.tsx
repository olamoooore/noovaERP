import { useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatCard } from '@/components/ui/stat-card';
import { ReportExportToolbar } from '@/components/reports/ReportExportToolbar';
import reportApi from '@/lib/reportApi';
import { formatCurrency, formatNumber } from '@/lib/utils';
import type {
  CreditNoteAnalysisRow,
  CreditNoteGrouping,
  ReportFilters as ReportFiltersType,
} from '@/types/reports';
import type { SalesDrilldownRequest } from './SalesDrilldownPanel';

const GROUPINGS: { value: CreditNoteGrouping; label: string }[] = [
  { value: 'reason', label: 'Reason' },
  { value: 'product', label: 'Product' },
  { value: 'customer', label: 'Customer' },
  { value: 'salesperson', label: 'Salesperson' },
];

const formatRate = (rate: number | null | undefined) =>
  rate === null || rate === undefined ? '—' : `${formatNumber(rate, 2)}%`;

interface CreditNoteAnalysisViewProps {
  filters: ReportFiltersType;
  periodLabel: string;
  exportRange: string;
  onDrilldown: (request: SalesDrilldownRequest) => void;
}

/**
 * Issue #532 (SALES-ODOO): credit note analysis tab — credited vs invoiced
 * and the credit rate by reason, product, customer or salesperson, from the
 * same read model and filters as the other sales reports (posted credit notes
 * only, net of tax, in the base currency). A row drills to its credit notes.
 */
export function CreditNoteAnalysisView({
  filters,
  periodLabel,
  exportRange,
  onDrilldown,
}: CreditNoteAnalysisViewProps) {
  const tableRef = useRef<HTMLDivElement>(null);
  const [groupBy, setGroupBy] = useState<CreditNoteGrouping>('reason');

  const { data, isLoading, error } = useQuery({
    queryKey: ['sales-credit-notes', filters, groupBy],
    queryFn: () => reportApi.getCreditNoteAnalysis(filters, groupBy),
    staleTime: 15 * 1000,
  });

  const rows = data?.rows ?? [];
  const summary = data?.summary;
  const currency = summary?.base_currency;
  const groupLabel = GROUPINGS.find((g) => g.value === groupBy)?.label ?? 'Group';

  const drill = (row: CreditNoteAnalysisRow) =>
    onDrilldown({
      dimension: groupBy,
      key: row.key === null ? 'none' : String(row.key),
      document_type: 'credit_note',
      label: `Credit notes · ${row.label}`,
      // Credit notes reduce revenue: the drill-down lists them as negatives.
      rowValue: -row.credited_amount,
    });

  const exportRows = useMemo(
    () => [
      [groupLabel, 'Credit Notes', 'Credited (base)', 'Invoiced (base)', 'Credit Rate', 'Applied', 'Partially Applied', 'Customer Credit'],
      ...(data?.rows ?? []).map((row) => [
        row.label,
        String(row.credit_note_count),
        formatCurrency(row.credited_amount, currency),
        formatCurrency(row.invoiced_amount, currency),
        formatRate(row.credit_rate),
        String(row.outcome.applied),
        String(row.outcome.partially_applied),
        String(row.outcome.customer_credit),
      ]),
    ],
    [data, groupLabel, currency],
  );

  return (
    <div className="space-y-6">
      <Card className="rounded-xl">
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
          <div>
            <CardTitle className="text-base">Credit Note Analysis</CardTitle>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Posted credit notes vs posted invoices, net of tax, in the base currency · {periodLabel}
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <div role="group" aria-label="Group credit notes by" className="flex rounded-full bg-muted p-1">
              {GROUPINGS.map((g) => (
                <button
                  key={g.value}
                  type="button"
                  aria-pressed={groupBy === g.value}
                  onClick={() => setGroupBy(g.value)}
                  className={`rounded-full px-3 py-1 text-xs font-medium transition ${
                    groupBy === g.value ? 'bg-background shadow-sm' : 'text-muted-foreground hover:text-foreground'
                  }`}
                >
                  {g.label}
                </button>
              ))}
            </div>
            <ReportExportToolbar
              targetRef={tableRef}
              title="Credit Note Analysis"
              subtitle={`By ${groupLabel.toLowerCase()} · ${periodLabel}`}
              filename={`credit-note-analysis-${groupBy}-${exportRange}`}
              rows={exportRows}
              columnAligns={['left', 'right', 'right', 'right', 'right', 'right', 'right', 'right']}
            />
          </div>
        </CardHeader>

        <CardContent className="space-y-6 pt-6" ref={tableRef}>
          {isLoading && (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="svg-spinners:ring-resize" className="mx-auto h-8 w-8" />
              <p className="mt-2">Loading credit note analysis…</p>
            </div>
          )}

          {!isLoading && error && (
            <div className="py-12 text-center text-destructive">
              <p className="font-medium">Failed to load credit note analysis</p>
              <p className="mt-1 text-sm">{(error as Error).message}</p>
            </div>
          )}

          {!isLoading && !error && (
            <>
              <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard
                  label="Credited"
                  value={formatCurrency(summary?.total_credited ?? 0, currency)}
                  icon="solar:undo-left-round-bold-duotone"
                  tone="bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400"
                />
                <StatCard
                  label="Invoiced"
                  value={formatCurrency(summary?.total_invoiced ?? 0, currency)}
                  icon="solar:document-text-bold-duotone"
                  tone="bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400"
                />
                <StatCard
                  label="Credit Rate"
                  value={formatRate(summary?.credit_rate)}
                  icon="solar:chart-2-bold-duotone"
                  tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
                />
                <StatCard
                  label="Net Invoiced"
                  value={formatCurrency(summary?.net_invoiced ?? 0, currency)}
                  icon="solar:wallet-money-bold-duotone"
                  tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"
                />
              </div>

              {summary && summary.credit_note_count > 0 && (
                <p className="text-xs text-muted-foreground">
                  {summary.credit_note_count} credit note(s): {summary.outcome.applied} applied,{' '}
                  {summary.outcome.partially_applied} partially applied, {summary.outcome.customer_credit} held as
                  customer credit · refunded {formatCurrency(summary.outcome.refunded_amount, currency)}, remaining
                  credit {formatCurrency(summary.outcome.remaining_credit, currency)} (incl. tax)
                </p>
              )}

              {rows.length === 0 ? (
                <div className="py-12 text-center text-muted-foreground">
                  <Icon icon="solar:check-circle-bold-duotone" className="mx-auto mb-3 h-12 w-12 opacity-50" />
                  <p>No posted credit notes in this period.</p>
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b bg-muted/40">
                        <th className="px-3 py-2 text-left font-medium">{groupLabel}</th>
                        <th className="px-3 py-2 text-right font-medium">Credit Notes</th>
                        <th className="px-3 py-2 text-right font-medium">Credited</th>
                        <th className="px-3 py-2 text-right font-medium">Invoiced</th>
                        <th className="px-3 py-2 text-right font-medium">Credit Rate</th>
                        <th className="px-3 py-2 text-left font-medium">Outcome</th>
                      </tr>
                    </thead>
                    <tbody>
                      {rows.map((row) => (
                        <tr key={`${groupBy}:${row.key ?? 'none'}`} className="border-b last:border-b-0">
                          <td className="px-3 py-2">
                            <button
                              type="button"
                              onClick={() => drill(row)}
                              className="text-left font-medium hover:underline"
                            >
                              {row.label}
                            </button>
                          </td>
                          <td className="px-3 py-2 text-right tabular-nums">{row.credit_note_count}</td>
                          <td className="px-3 py-2 text-right font-semibold tabular-nums">
                            {formatCurrency(row.credited_amount, currency)}
                          </td>
                          <td className="px-3 py-2 text-right tabular-nums">
                            {formatCurrency(row.invoiced_amount, currency)}
                          </td>
                          <td className="px-3 py-2 text-right tabular-nums">{formatRate(row.credit_rate)}</td>
                          <td className="px-3 py-2 text-xs text-muted-foreground">
                            {row.outcome.applied} applied · {row.outcome.partially_applied} partial ·{' '}
                            {row.outcome.customer_credit} credit
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              {groupBy === 'reason' && rows.length > 0 && (
                <p className="text-xs text-muted-foreground">
                  Invoices carry no reason, so each reason&apos;s credit rate is over all invoicing in the period.
                </p>
              )}
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
