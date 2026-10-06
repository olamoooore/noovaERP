import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { SlideOver, SlideOverContent, SlideOverTitle } from '@/components/ui/slide-over';
import reportApi from '@/lib/reportApi';
import { formatCurrency } from '@/lib/utils';
import type {
  ReportFilters,
  SalesDrilldownDocument,
  SalesDrilldownTarget,
} from '@/types/reports';
import { drilldownDocumentLink, formatPeriodDate } from './salesAnalyticsRows';

/**
 * Issue #596: a report row the user clicked — what to drill (dimension/key),
 * how to name it in the panel, and the row's own value to check against.
 */
export interface SalesDrilldownRequest extends SalesDrilldownTarget {
  label: string;
  rowValue: number;
}

const DOCUMENT_TYPE_LABELS: Record<SalesDrilldownDocument['document_type'], string> = {
  invoice: 'Invoice',
  credit_note: 'Credit note',
  pos_order: 'POS sale',
  sales_order: 'Sales order (booking)',
};

/**
 * Issue #596 (RPT-UX1): side panel listing the source documents behind one
 * sales-report row — number (linked), date and net amount — read from the
 * same read model and filters as the row, so the total equals the row.
 */
export function SalesDrilldownPanel({
  request,
  filters,
  onClose,
}: {
  request: SalesDrilldownRequest | null;
  filters: ReportFilters;
  onClose: () => void;
}) {
  const target: SalesDrilldownTarget | null = request
    ? {
        dimension: request.dimension,
        key: request.key,
        ...(request.dimension === 'period' ? { interval: request.interval ?? 'day' } : {}),
      }
    : null;

  const { data, isLoading, error } = useQuery({
    queryKey: ['sales-drilldown', filters, target],
    queryFn: () => reportApi.getSalesDrilldown(filters, target!),
    enabled: target !== null,
    staleTime: 15 * 1000,
  });

  const documents = data?.documents ?? [];
  const total = Number(data?.summary?.total_net_amount ?? 0);
  const mismatch = request !== null && data !== undefined && Math.abs(total - request.rowValue) >= 0.005;

  return (
    <SlideOver open={request !== null} onOpenChange={(open) => !open && onClose()}>
      <SlideOverContent className="flex flex-col gap-4 overflow-y-auto sm:w-[560px]" aria-describedby={undefined}>
        <div className="pr-8">
          <SlideOverTitle>{request?.label ?? ''}</SlideOverTitle>
          <p className="mt-0.5 text-sm text-muted-foreground">
            Source documents · {formatPeriodDate(filters.start_date)} → {formatPeriodDate(filters.end_date)}
          </p>
        </div>

        {isLoading ? (
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Icon icon="svg-spinners:ring-resize" className="h-4 w-4" /> Loading documents…
          </div>
        ) : error ? (
          <p className="text-sm text-destructive">Could not load the documents for this row.</p>
        ) : documents.length === 0 ? (
          <p className="text-sm text-muted-foreground">No documents in this period.</p>
        ) : (
          <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
              <thead className="bg-muted/50 text-xs text-muted-foreground">
                <tr>
                  <th className="px-3 py-2 text-left font-medium">Document</th>
                  <th className="px-3 py-2 text-left font-medium">Date</th>
                  <th className="px-3 py-2 text-left font-medium">Customer</th>
                  <th className="px-3 py-2 text-right font-medium">Net amount</th>
                </tr>
              </thead>
              <tbody>
                {documents.map((doc) => (
                  <tr key={`${doc.document_type}:${doc.document_id}:${doc.customer_id ?? ''}`} className="border-t">
                    <td className="px-3 py-2">
                      <Link to={drilldownDocumentLink(doc)} className="font-medium hover:underline">
                        {doc.document_number}
                      </Link>
                      <div className="text-xs text-muted-foreground">{DOCUMENT_TYPE_LABELS[doc.document_type]}</div>
                    </td>
                    <td className="whitespace-nowrap px-3 py-2">{formatPeriodDate(doc.document_date)}</td>
                    <td className="px-3 py-2">{doc.customer_name}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(doc.net_amount)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="border-t bg-muted/30 font-semibold">
                  <td className="px-3 py-2" colSpan={3}>
                    Total ({documents.length} document{documents.length === 1 ? '' : 's'})
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums" data-testid="drilldown-total">
                    {formatCurrency(total)}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        )}

        {mismatch && (
          <p className="rounded-lg bg-amber-500/10 px-3 py-2 text-xs font-medium text-amber-700 dark:text-amber-300">
            The documents total {formatCurrency(total)} does not match the row ({formatCurrency(request!.rowValue)}).
            Refresh the report — the data may have changed since it loaded.
          </p>
        )}
      </SlideOverContent>
    </SlideOver>
  );
}
