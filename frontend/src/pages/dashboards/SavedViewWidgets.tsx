import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import reportViewsApi from '@/lib/reportViewsApi';
import type { ReportDashboardWidget, ReportWidgetData } from '@/types/reports';

/**
 * Issue #603 — the Sales cockpit's pinned saved-view widgets.
 *
 * Each widget renders a saved report view (#598 storage) through the same
 * server-side renderer as its exports, so the saved filters apply. The API
 * re-authorises the view's visibility and entity scope on every render; a
 * widget the viewer may no longer read shows an access message instead of
 * data. The section hides itself entirely when the user has no pins (or no
 * reports.view, in which case the list query 403s).
 */
export function SavedViewWidgets() {
  const queryClient = useQueryClient();

  const { data: widgets = [], isError } = useQuery({
    queryKey: ['report-dashboard-widgets'],
    queryFn: () => reportViewsApi.dashboardWidgets(),
    staleTime: 30 * 1000,
    retry: false,
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['report-dashboard-widgets'] });
    queryClient.invalidateQueries({ queryKey: ['saved-report-views'] });
  };

  const removeMutation = useMutation({
    mutationFn: (id: number) => reportViewsApi.removeWidget(id),
    onSuccess: invalidate,
  });

  const reorderMutation = useMutation({
    mutationFn: (widgetIds: number[]) => reportViewsApi.reorderWidgets(widgetIds),
    onSuccess: invalidate,
  });

  if (isError || widgets.length === 0) {
    return null;
  }

  const move = (index: number, delta: number) => {
    const target = index + delta;
    if (target < 0 || target >= widgets.length) return;

    const ids = widgets.map((widget) => widget.id);
    [ids[index], ids[target]] = [ids[target], ids[index]];
    reorderMutation.mutate(ids);
  };

  return (
    <section aria-label="Saved report widgets" className="space-y-3">
      <div className="flex items-center gap-2">
        <Icon icon="solar:widget-5-bold-duotone" className="h-4 w-4 text-muted-foreground" />
        <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
          Saved view widgets
        </h2>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
        {widgets.map((widget, index) => (
          <SavedViewWidgetCard
            key={widget.id}
            widget={widget}
            canMoveUp={index > 0}
            canMoveDown={index < widgets.length - 1}
            busy={removeMutation.isPending || reorderMutation.isPending}
            onMoveUp={() => move(index, -1)}
            onMoveDown={() => move(index, 1)}
            onRemove={() => removeMutation.mutate(widget.id)}
          />
        ))}
      </div>
    </section>
  );
}

function SavedViewWidgetCard({
  widget,
  canMoveUp,
  canMoveDown,
  busy,
  onMoveUp,
  onMoveDown,
  onRemove,
}: {
  widget: ReportDashboardWidget;
  canMoveUp: boolean;
  canMoveDown: boolean;
  busy: boolean;
  onMoveUp: () => void;
  onMoveDown: () => void;
  onRemove: () => void;
}) {
  const name = widget.view.name;
  const { data, isLoading, isError } = useQuery<ReportWidgetData>({
    queryKey: ['report-widget-data', widget.id],
    queryFn: () => reportViewsApi.widgetData(widget.id),
    retry: false,
  });

  return (
    <Card className="flex flex-col overflow-hidden">
      <CardHeader className="border-b bg-muted/20 p-3">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <CardTitle className="truncate text-sm">{name}</CardTitle>
            <p className="truncate text-xs text-muted-foreground">
              {data?.period
                ? `${data.period.start_date} → ${data.period.end_date}`
                : `${widget.view.tab} report`}
            </p>
          </div>

          <div className="flex shrink-0 items-center gap-0.5">
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-7 w-7"
              aria-label={`Move ${name} up`}
              disabled={!canMoveUp || busy}
              onClick={onMoveUp}
            >
              <Icon icon="solar:alt-arrow-up-bold" className="h-4 w-4" />
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-7 w-7"
              aria-label={`Move ${name} down`}
              disabled={!canMoveDown || busy}
              onClick={onMoveDown}
            >
              <Icon icon="solar:alt-arrow-down-bold" className="h-4 w-4" />
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-7 w-7 text-destructive"
              aria-label={`Remove ${name} from dashboard`}
              disabled={busy}
              onClick={onRemove}
            >
              <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </CardHeader>

      <CardContent className="flex-1 p-0">
        {isLoading ? (
          <div className="space-y-2 p-4">
            {[1, 2, 3].map((row) => (
              <div key={row} className="h-4 w-full animate-pulse rounded bg-muted" />
            ))}
          </div>
        ) : isError ? (
          <p className="p-4 text-sm text-muted-foreground">
            You do not have access to this saved view.
          </p>
        ) : data && data.rows.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b bg-muted/10">
                  {data.columns.map((column) => (
                    <th
                      key={column}
                      className="whitespace-nowrap px-3 py-2 font-medium text-muted-foreground"
                    >
                      {column}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.rows.map((row, rowIndex) => (
                  <tr key={rowIndex} className="border-b last:border-0">
                    {data.columns.map((column) => (
                      <td key={column} className="whitespace-nowrap px-3 py-1.5 tabular-nums">
                        {formatWidgetCell(row[column])}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="p-4 text-sm text-muted-foreground">No data for this period.</p>
        )}
      </CardContent>

      {data && (
        <div className="border-t px-3 py-2 text-[11px] text-muted-foreground">
          {data.row_count === 1 ? '1 row' : `${data.row_count} rows`}
        </div>
      )}
    </Card>
  );
}

function formatWidgetCell(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'number') {
    return value.toLocaleString('en-US', { maximumFractionDigits: 2 });
  }
  return String(value);
}

export default SavedViewWidgets;
