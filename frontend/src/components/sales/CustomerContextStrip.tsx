import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, Ban, CalendarClock, CreditCard, Wallet } from 'lucide-react';
import api from '@/lib/api';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import type { CreditCheckVerdict } from '@/lib/creditCheck';

export interface CustomerContext {
  customer_id: number;
  currency_code: string;
  outstanding: number;
  current: number;
  overdue: number;
  overdue_count: number;
  oldest_overdue_days: number;
  credit_limit: number | null;
  credit_utilization_pct: number | null;
  credit_available: number | null;
  last_invoice_date: string | null;
  /** Issue #340: the invoice behind last_invoice_date, for the strip's link. */
  last_invoice_id?: number | null;
  last_invoice_number?: string | null;
  credit_check?: CreditCheckVerdict;
}

const CHIP = 'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs';
const CHIP_NEUTRAL = 'border-border bg-muted/50 text-muted-foreground';

/**
 * D2 (Oct-2026 invoice review): compact customer AR context strip shown after
 * a customer is picked on a document entry screen — outstanding balance,
 * overdue count, credit utilization and last invoice date. Self-fetching so
 * every document screen shares the same source (AgedReceivablesService).
 */
export function CustomerContextStrip({
  customerId,
  className,
}: {
  customerId: string | number | null;
  className?: string;
}) {
  const [context, setContext] = useState<CustomerContext | null>(null);

  useEffect(() => {
    let cancelled = false;

    if (!customerId) {
      setContext(null);
      return;
    }

    api
      .get(`/customers/${customerId}/context`)
      .then((response) => {
        if (!cancelled) setContext(response.data);
      })
      .catch(() => {
        if (!cancelled) setContext(null);
      });

    return () => {
      cancelled = true;
    };
  }, [customerId]);

  if (!customerId || !context) return null;

  const currency = context.currency_code;
  const creditPct = context.credit_utilization_pct;

  return (
    <div className={cn('flex flex-wrap items-center gap-2', className)}>
      <span
        className={cn(CHIP, CHIP_NEUTRAL)}
        title="Open balance across unpaid invoices (base-currency equivalent)"
      >
        <Wallet className="h-3.5 w-3.5" />
        Outstanding{' '}
        <span className="font-semibold tabular-nums text-foreground">
          {formatCurrency(context.outstanding, currency)}
        </span>
      </span>

      {context.overdue_count > 0 ? (
        <span
          className={cn(
            CHIP,
            'border-red-200 bg-red-50 text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300'
          )}
          title={`Oldest overdue invoice is ${context.oldest_overdue_days} day(s) past due`}
        >
          <AlertTriangle className="h-3.5 w-3.5" />
          {context.overdue_count} overdue ·{' '}
          <span className="font-semibold tabular-nums">{formatCurrency(context.overdue, currency)}</span>
        </span>
      ) : (
        <span
          className={cn(
            CHIP,
            'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-300'
          )}
        >
          <AlertTriangle className="h-3.5 w-3.5" />
          No overdue
        </span>
      )}

      {context.credit_limit != null && (
        <span
          className={cn(
            CHIP,
            creditPct != null && creditPct > 100
              ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300'
              : creditPct != null && creditPct >= 80
                ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-300'
                : CHIP_NEUTRAL
          )}
          title={`Credit limit ${formatCurrency(context.credit_limit, currency)}`}
        >
          <CreditCard className="h-3.5 w-3.5" />
          Credit <span className="font-semibold tabular-nums">{creditPct ?? 0}%</span> used
          {context.credit_available != null && context.credit_available > 0 && (
            <> · {formatCurrency(context.credit_available, currency)} left</>
          )}
        </span>
      )}

      {context.credit_check && !context.credit_check.approved && (
        <span
          className={cn(
            CHIP,
            'border-red-200 bg-red-50 text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300'
          )}
          title={context.credit_check.reason}
          data-testid="credit-check-chip"
        >
          <Ban className="h-3.5 w-3.5" />
          {context.credit_check.policy === 'block' ? 'Credit check fails · posting blocked' : 'Credit check fails · warning only'}
        </span>
      )}

      <span className={cn(CHIP, CHIP_NEUTRAL)}>
        <CalendarClock className="h-3.5 w-3.5" />
        {context.last_invoice_date ? (
          <>
            Last invoice{' '}
            {context.last_invoice_id && context.last_invoice_number ? (
              <Link
                to={`/invoices/${context.last_invoice_id}`}
                className="font-medium text-primary hover:underline"
                title="Open the customer's last invoice"
              >
                {context.last_invoice_number}
              </Link>
            ) : null}{' '}
            {formatDate(context.last_invoice_date)}
          </>
        ) : (
          'No invoices yet'
        )}
      </span>
    </div>
  );
}