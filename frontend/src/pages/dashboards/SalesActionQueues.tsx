import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatCurrency, formatDate } from '@/lib/utils';
import { fetchSalesActionQueues, type SalesActionQueue } from '@/lib/salesActionQueues';

/**
 * Issue #365 — the Sales cockpit's action queues: what to do next (quotes to
 * send / expiring, approvals pending, orders to deliver / invoice, deliveries
 * to invoice, invoices to send, overdue invoices). Each tile shows the count
 * and the most urgent rows, and opens the matching filtered list. The API
 * returns only the queues the user may view, scoped to their legal entities;
 * "Only mine" narrows to the user's own documents. The section hides itself
 * when the endpoint is not available to the user.
 */
export function SalesActionQueues() {
  const [mine, setMine] = useState(false);

  const { data, isError } = useQuery({
    queryKey: ['sales-action-queues', mine],
    queryFn: () => fetchSalesActionQueues(mine),
    staleTime: 30 * 1000,
    retry: false,
  });

  if (isError || !data || data.queues.length === 0) {
    return null;
  }

  return (
    <section aria-label="Action queues" className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <Icon icon="solar:checklist-minimalistic-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">What to do next</h2>
        </div>
        <label className="flex items-center gap-2 text-xs text-muted-foreground">
          <input
            type="checkbox"
            checked={mine}
            onChange={(event) => setMine(event.target.checked)}
            data-testid="action-queues-mine"
          />
          Only my documents
        </label>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {data.queues.map((queue) => (
          <ActionQueueTile key={queue.key} queue={queue} />
        ))}
      </div>
    </section>
  );
}

function ActionQueueTile({ queue }: { queue: SalesActionQueue }) {
  return (
    <Card data-testid={`action-queue-${queue.key}`} className="flex flex-col">
      <CardHeader className="pb-2">
        <CardTitle className="flex items-baseline justify-between gap-2 text-sm font-medium">
          <Link to={queue.link} className="hover:underline" data-testid={`action-queue-link-${queue.key}`}>
            {queue.label}
          </Link>
          <span
            className={`text-2xl font-semibold ${queue.count > 0 ? 'text-foreground' : 'text-muted-foreground'}`}
            data-testid={`action-queue-count-${queue.key}`}
          >
            {queue.count}
          </span>
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-1 flex-col gap-2 pt-0">
        {queue.items.length === 0 ? (
          <p className="text-xs text-muted-foreground">Nothing waiting.</p>
        ) : (
          <ul className="space-y-1">
            {queue.items.map((item) => (
              <li key={item.id} className="flex items-center justify-between gap-2 text-xs">
                <Link to={item.link} className="min-w-0 truncate hover:underline">
                  <span className="font-medium">{item.number}</span>
                  {item.customer && <span className="text-muted-foreground"> · {item.customer}</span>}
                </Link>
                <span className="shrink-0 text-muted-foreground">
                  {item.amount !== null ? formatCurrency(item.amount, item.currency_code ?? undefined) : formatDate(item.date)}
                </span>
              </li>
            ))}
          </ul>
        )}
        <div className="mt-auto flex flex-wrap gap-3 pt-1 text-xs">
          <Link to={queue.link} className="font-medium text-primary hover:underline">
            View all
          </Link>
          {queue.secondary_link && (
            <Link to={queue.secondary_link} className="text-primary hover:underline">
              Customer follow-up
            </Link>
          )}
        </div>
      </CardContent>
    </Card>
  );
}
