import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import reportApi from '@/lib/reportApi';
import { formatCurrency } from '@/lib/utils';
import type { ReportFilters as ReportFiltersType } from '@/types/reports';

/**
 * Issue #604 (RPT-D1): the sales reports' "ties to GL" footer.
 *
 * Shows the report's net revenue next to the posted GL revenue for the SAME
 * filters, a tick when they agree, and — when they differ — the difference
 * broken down by cause with a link to every document that explains it
 * (unposted invoices, manual journals, ...).
 */
export function GlReconciliationFooter({ filters }: { filters: ReportFiltersType }) {
  const { data } = useQuery({
    queryKey: ['sales-reconciliation', filters],
    queryFn: () => reportApi.getSalesReconciliation(filters),
    staleTime: 15 * 1000,
  });

  if (!data) {
    return null;
  }

  return (
    <div
      data-testid="gl-reconciliation-footer"
      className="rounded-xl border bg-muted/30 px-4 py-3 text-sm"
    >
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span className="font-medium text-muted-foreground">Ties to GL</span>
        <span aria-hidden>·</span>
        <span>
          Net revenue <span className="font-semibold tabular-nums">{formatCurrency(data.report_net_revenue)}</span>
        </span>
        <span aria-hidden>·</span>
        <span>
          GL revenue accounts{' '}
          <span className="font-semibold tabular-nums">{formatCurrency(data.gl_revenue)}</span>
        </span>
        {data.reconciled ? (
          <span className="font-semibold text-emerald-600">✓</span>
        ) : (
          <>
            <span aria-hidden>·</span>
            <span className="font-semibold text-amber-700">
              Difference <span className="tabular-nums">{formatCurrency(data.difference)}</span>
            </span>
          </>
        )}
      </div>

      {!data.reconciled && data.causes.length > 0 && (
        <div className="mt-2 space-y-1 border-t pt-2 text-xs">
          {data.causes.map((cause) => (
            <div
              key={`${cause.code}-${cause.direction}`}
              className="flex flex-wrap items-center gap-x-2 gap-y-0.5"
            >
              <span className="font-medium">{cause.label}</span>
              <span className="tabular-nums">{formatCurrency(cause.amount)}</span>
              {cause.documents.map((document) => (
                <Link
                  key={`${document.type}-${document.id}`}
                  to={document.url}
                  className="text-primary hover:underline"
                >
                  {document.label}
                </Link>
              ))}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

export default GlReconciliationFooter;
