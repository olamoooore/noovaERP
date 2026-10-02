import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import api from '@/lib/api';

/**
 * #751 TU5: dismissible banner for upcoming pack rate changes.
 *
 * The rates and the affected-document counts come from the server
 * (`/accounting/tax-engine/upcoming-changes`) — the browser never derives a
 * tax rate. Dismissal is per rule-code + effective date.
 */
interface RateChange {
  jurisdiction: string;
  type: string;
  code: string | null;
  label?: string | null;
  new_rate: number;
  current_rate: number | null;
  effective_from: string;
  days_until: number;
  affected: { quotations: number; sales_orders: number };
}

const dismissKey = (c: RateChange) => `tax-rate-banner:${c.code ?? c.jurisdiction}:${c.effective_from}`;

export function TaxRateChangeBanner() {
  const [, setTick] = useState(0);

  const { data } = useQuery<{ data: RateChange[] }>({
    queryKey: ['tax-upcoming-changes'],
    queryFn: async () => (await api.get('/accounting/tax-engine/upcoming-changes', { params: { days: 30 } })).data,
  });

  const changes = (data?.data ?? []).filter((c) => localStorage.getItem(dismissKey(c)) !== '1');

  if (changes.length === 0) {
    return null;
  }

  return (
    <div className="space-y-2">
      {changes.map((c) => (
        <div
          key={dismissKey(c)}
          className="flex items-start justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950/30"
        >
          <div className="flex items-start gap-2">
            <Icon icon="solar:bell-bing-bold-duotone" className="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
            <div>
              <p className="font-medium">
                {c.jurisdiction} {c.type} tax changes to {c.new_rate}%
                {c.current_rate != null ? ` (from ${c.current_rate}%)` : ''} on{' '}
                {new Date(c.effective_from).toLocaleDateString()}
              </p>
              <p className="text-xs text-muted-foreground">
                {c.affected.quotations} open quotation(s) and {c.affected.sales_orders} open sales order(s) predate the change.
              </p>
            </div>
          </div>
          <button
            type="button"
            aria-label="Dismiss rate-change notice"
            className="shrink-0 text-muted-foreground hover:text-foreground"
            onClick={() => {
              localStorage.setItem(dismissKey(c), '1');
              setTick((t) => t + 1);
            }}
          >
            <Icon icon="solar:close-circle-linear" className="h-5 w-5" />
          </button>
        </div>
      ))}
    </div>
  );
}