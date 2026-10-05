import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import reportViewsApi, { type RecipientUser } from '@/lib/reportViewsApi';
import type {
  ReportFilters as ReportFiltersType,
  ReportSavedView,
  ReportViewSchedule,
  SalesViewGrouping,
  SalesViewTab,
} from '@/types/reports';

export interface SavedViewApplied {
  tab: SalesViewTab;
  filters: ReportFiltersType;
  grouping: SalesViewGrouping | null;
}

interface SavedViewBarProps {
  tab: SalesViewTab;
  filters: ReportFiltersType;
  grouping: SalesViewGrouping | null;
  onApply: (applied: SavedViewApplied) => void;
}

/**
 * Issue #598 (RPT-UX3): the sales reports page's saved-view bar.
 *
 * Lists the user's personal views plus every shared view, applies the user's
 * default view once on load, saves the current tab + filters + grouping, and
 * opens the schedule dialog for the selected view. Owner-only actions are
 * shown for `is_owner` views and re-enforced by the API (403).
 */
export function SavedViewBar({ tab, filters, grouping, onApply }: SavedViewBarProps) {
  const queryClient = useQueryClient();
  const [selectedId, setSelectedId] = useState('');
  const [showSave, setShowSave] = useState(false);
  const [showManage, setShowManage] = useState(false);
  const [scheduleView, setScheduleView] = useState<ReportSavedView | null>(null);
  const appliedDefault = useRef(false);

  const { data } = useQuery({
    queryKey: ['saved-report-views'],
    queryFn: () => reportViewsApi.list(),
    staleTime: 30 * 1000,
  });

  const views = Array.isArray(data?.views) ? data.views : [];
  const selected = views.find((view) => String(view.id) === selectedId) ?? null;

  const favouriteMutation = useMutation({
    mutationFn: (id: number) => reportViewsApi.setFavourite(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['saved-report-views'] }),
  });

  const applyView = (view: ReportSavedView) => {
    onApply({
      tab: view.tab,
      filters: view.filters ?? {},
      grouping: view.grouping ?? null,
    });
  };

  // The user's default view is applied once, as soon as the list arrives.
  useEffect(() => {
    if (appliedDefault.current || !data || !Array.isArray(data.views)) return;
    appliedDefault.current = true;

    const fallback = data.views.find((view) => view.is_default);
    const view = data.views.find((item) => item.id === data.default_view_id) ?? fallback;
    if (!view) return;

    setSelectedId(String(view.id));
    applyView(view);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data]);

  const handleSelect = (value: string) => {
    setSelectedId(value);
    const view = views.find((item) => String(item.id) === value);
    if (view) applyView(view);
  };

  return (
    <>
      <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-card p-3">
        <div className="flex items-center gap-2">
          <Icon icon="solar:bookmark-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          <Label htmlFor="saved-view-picker" className="text-xs font-medium text-muted-foreground">
            Saved view
          </Label>
        </div>

        <select
          id="saved-view-picker"
          aria-label="Saved view"
          value={selectedId}
          onChange={(event) => handleSelect(event.target.value)}
          className="h-9 min-w-[220px] rounded-full border border-input bg-background px-3 text-sm"
        >
          <option value="">Custom (unsaved)</option>
          {views.map((view) => (
            <option key={view.id} value={String(view.id)}>
              {view.is_favourite ? '★ ' : ''}
              {view.name}
              {view.visibility === 'shared' ? ' (shared)' : ''}
              {view.is_default ? ' (default)' : ''}
            </option>
          ))}
        </select>

        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="h-9 w-9"
          aria-label={selected?.is_favourite ? 'Remove from favourites' : 'Add to favourites'}
          title={
            selected && !selected.is_owner
              ? 'Only the view owner can favourite this view'
              : 'Favourite this view'
          }
          disabled={!selected || !selected.is_owner || favouriteMutation.isPending}
          onClick={() => selected && favouriteMutation.mutate(selected.id)}
        >
          <Icon
            icon={selected?.is_favourite ? 'solar:star-bold' : 'solar:star-line-duotone'}
            className={`h-4 w-4 ${
              selected?.is_favourite ? 'text-amber-500' : 'text-muted-foreground'
            }`}
          />
        </Button>

        <HeaderActionButton
          variant="outline"
          icon="solar:bookmark-add-bold"
          onClick={() => setShowSave(true)}
        >
          Save view
        </HeaderActionButton>

        <HeaderActionButton
          variant="outline"
          icon="solar:letter-bold"
          disabled={!selected}
          onClick={() => selected && setScheduleView(selected)}
        >
          Schedule
        </HeaderActionButton>

        <HeaderActionButton
          variant="ghost"
          icon="solar:settings-bold"
          onClick={() => setShowManage(true)}
        >
          Manage views
        </HeaderActionButton>
      </div>

      {showSave && (
        <SaveViewDialog
          tab={tab}
          filters={filters}
          grouping={grouping}
          onClose={() => setShowSave(false)}
          onSaved={(view) => {
            setShowSave(false);
            setSelectedId(String(view.id));
            queryClient.invalidateQueries({ queryKey: ['saved-report-views'] });
          }}
        />
      )}

      {showManage && (
        <ManageViewsDialog
          views={views}
          defaultViewId={data?.default_view_id ?? null}
          onClose={() => setShowManage(false)}
          onChanged={() => {
            queryClient.invalidateQueries({ queryKey: ['saved-report-views'] });
            queryClient.invalidateQueries({ queryKey: ['report-dashboard-widgets'] });
          }}
          onSchedule={(view) => {
            setShowManage(false);
            setSelectedId(String(view.id));
            setScheduleView(view);
          }}
        />
      )}

      {scheduleView && (
        <ScheduleViewDialog
          view={scheduleView}
          onClose={() => setScheduleView(null)}
        />
      )}
    </>
  );
}

function SaveViewDialog({
  tab,
  filters,
  grouping,
  onClose,
  onSaved,
}: {
  tab: SalesViewTab;
  filters: ReportFiltersType;
  grouping: SalesViewGrouping | null;
  onClose: () => void;
  onSaved: (view: ReportSavedView) => void;
}) {
  const [name, setName] = useState('');
  const [visibility, setVisibility] = useState<'personal' | 'shared'>('personal');
  const [isDefault, setIsDefault] = useState(false);
  const [isFavourite, setIsFavourite] = useState(false);

  const saveMutation = useMutation({
    mutationFn: () =>
      reportViewsApi.create({
        name: name.trim(),
        report_key: 'sales-analytics',
        tab,
        filters,
        grouping,
        visibility,
        is_default: isDefault,
        is_favourite: isFavourite,
      }),
    onSuccess: (view) => onSaved(view),
  });

  return (
    <Dialog open onOpenChange={(next) => { if (!next) onClose(); }}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Save view</DialogTitle>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="saved-view-name" className="text-xs font-medium text-muted-foreground">
              View name
            </Label>
            <Input
              id="saved-view-name"
              aria-label="View name"
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder="e.g. My monthly sales by salesperson"
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="saved-view-visibility" className="text-xs font-medium text-muted-foreground">
              Visibility
            </Label>
            <select
              id="saved-view-visibility"
              aria-label="Visibility"
              value={visibility}
              onChange={(event) => setVisibility(event.target.value as 'personal' | 'shared')}
              className="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm"
            >
              <option value="personal">Personal — only me</option>
              <option value="shared">Shared — everyone with report access</option>
            </select>
          </div>

          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              aria-label="Set as my default view"
              checked={isDefault}
              onChange={(event) => setIsDefault(event.target.checked)}
              className="h-4 w-4 rounded border-input"
            />
            Set as my default view
          </label>

          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              aria-label="Mark as favourite"
              checked={isFavourite}
              onChange={(event) => setIsFavourite(event.target.checked)}
              className="h-4 w-4 rounded border-input"
            />
            Mark as favourite
          </label>

          {saveMutation.isError && (
            <p className="text-sm text-destructive">
              {(saveMutation.error as Error)?.message ?? 'Failed to save the view.'}
            </p>
          )}
        </div>

        <DialogFooter className="gap-2 border-t pt-4">
          <Button type="button" variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button
            type="button"
            disabled={!name.trim() || saveMutation.isPending}
            onClick={() => saveMutation.mutate()}
          >
            {saveMutation.isPending ? 'Saving…' : 'Save'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function ManageViewsDialog({
  views,
  defaultViewId,
  onClose,
  onChanged,
  onSchedule,
}: {
  views: ReportSavedView[];
  defaultViewId: number | null;
  onClose: () => void;
  onChanged: () => void;
  onSchedule: (view: ReportSavedView) => void;
}) {
  const defaultMutation = useMutation({
    mutationFn: (id: number) => reportViewsApi.setDefault(id),
    onSuccess: onChanged,
  });
  const deleteMutation = useMutation({
    mutationFn: (id: number) => reportViewsApi.remove(id),
    onSuccess: onChanged,
  });
  const pinMutation = useMutation({
    mutationFn: (viewId: number) => reportViewsApi.addWidget(viewId),
    onSuccess: onChanged,
  });

  const error = defaultMutation.error ?? deleteMutation.error ?? pinMutation.error;

  return (
    <Dialog open onOpenChange={(next) => { if (!next) onClose(); }}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Manage saved views</DialogTitle>
        </DialogHeader>

        {views.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">
            No saved views yet. Use “Save view” to create one.
          </p>
        ) : (
          <ul className="divide-y">
            {views.map((view) => (
              <li key={view.id} className="flex flex-wrap items-center gap-2 py-3">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">
                    {view.is_favourite ? '★ ' : ''}
                    {view.name}
                    {view.id === defaultViewId ? ' (default)' : ''}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {view.visibility === 'shared' ? 'Shared' : 'Personal'} · {view.tab}
                    {!view.is_owner ? ' · owned by another user' : ''}
                  </p>
                </div>

                {view.is_pinned ? (
                  <Button type="button" variant="ghost" size="sm" disabled>
                    <Icon icon="solar:widget-5-bold" className="mr-1 h-3.5 w-3.5" />
                    On dashboard
                  </Button>
                ) : (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    aria-label={`Add ${view.name} to dashboard`}
                    onClick={() => pinMutation.mutate(view.id)}
                  >
                    <Icon icon="solar:widget-add-bold" className="mr-1 h-3.5 w-3.5" />
                    Add to dashboard
                  </Button>
                )}

                {view.is_owner && (
                  <>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => onSchedule(view)}
                    >
                      Schedule
                    </Button>
                    {view.id !== defaultViewId && (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => defaultMutation.mutate(view.id)}
                      >
                        Set default
                      </Button>
                    )}
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="text-destructive"
                      onClick={() => deleteMutation.mutate(view.id)}
                    >
                      Delete
                    </Button>
                  </>
                )}
              </li>
            ))}
          </ul>
        )}

        {error && (
          <p className="text-sm text-destructive">
            {(error as Error)?.message ?? 'The action failed.'}
          </p>
        )}

        <DialogFooter className="border-t pt-4">
          <Button type="button" variant="outline" onClick={onClose}>
            Close
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function ScheduleViewDialog({
  view,
  onClose,
}: {
  view: ReportSavedView;
  onClose: () => void;
}) {
  const queryClient = useQueryClient();
  const [frequency, setFrequency] = useState<'daily' | 'weekly' | 'monthly'>('monthly');
  const [dayOfWeek, setDayOfWeek] = useState(1);
  const [dayOfMonth, setDayOfMonth] = useState(1);
  const [timeOfDay, setTimeOfDay] = useState('08:00');
  const [format, setFormat] = useState<'csv' | 'pdf'>('csv');
  const [recipientIds, setRecipientIds] = useState<number[]>([]);

  const { data: recipients = [] } = useQuery({
    queryKey: ['saved-view-recipients'],
    queryFn: () => reportViewsApi.recipients(),
    staleTime: 60 * 1000,
  });

  const { data: schedules = [] } = useQuery({
    queryKey: ['saved-view-schedules', view.id],
    queryFn: () => reportViewsApi.schedules(view.id),
  });

  const createMutation = useMutation({
    mutationFn: () =>
      reportViewsApi.createSchedule(view.id, {
        frequency,
        day_of_week: frequency === 'weekly' ? dayOfWeek : undefined,
        day_of_month: frequency === 'monthly' ? dayOfMonth : undefined,
        time_of_day: timeOfDay,
        recipients: recipientIds,
        format,
      }),
    onSuccess: () => {
      setRecipientIds([]);
      queryClient.invalidateQueries({ queryKey: ['saved-view-schedules', view.id] });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (scheduleId: number) => reportViewsApi.deleteSchedule(view.id, scheduleId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['saved-view-schedules', view.id] }),
  });

  const toggleRecipient = (id: number) =>
    setRecipientIds((previous) =>
      previous.includes(id) ? previous.filter((value) => value !== id) : [...previous, id],
    );

  return (
    <Dialog open onOpenChange={(next) => { if (!next) onClose(); }}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Schedule “{view.name}”</DialogTitle>
        </DialogHeader>

        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="schedule-frequency" className="text-xs font-medium text-muted-foreground">
                Frequency
              </Label>
              <select
                id="schedule-frequency"
                aria-label="Frequency"
                value={frequency}
                onChange={(event) => setFrequency(event.target.value as 'daily' | 'weekly' | 'monthly')}
                className="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm"
              >
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
              </select>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="schedule-time" className="text-xs font-medium text-muted-foreground">
                Time
              </Label>
              <Input
                id="schedule-time"
                aria-label="Time"
                type="time"
                value={timeOfDay}
                onChange={(event) => setTimeOfDay(event.target.value)}
              />
            </div>
          </div>

          {frequency === 'weekly' && (
            <div className="space-y-1.5">
              <Label htmlFor="schedule-weekday" className="text-xs font-medium text-muted-foreground">
                Day of week
              </Label>
              <select
                id="schedule-weekday"
                aria-label="Day of week"
                value={dayOfWeek}
                onChange={(event) => setDayOfWeek(Number(event.target.value))}
                className="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm"
              >
                {['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'].map(
                  (label, index) => (
                    <option key={label} value={index}>
                      {label}
                    </option>
                  ),
                )}
              </select>
            </div>
          )}

          {frequency === 'monthly' && (
            <div className="space-y-1.5">
              <Label htmlFor="schedule-monthday" className="text-xs font-medium text-muted-foreground">
                Day of month
              </Label>
              <Input
                id="schedule-monthday"
                aria-label="Day of month"
                type="number"
                min={1}
                max={28}
                value={dayOfMonth}
                onChange={(event) => setDayOfMonth(Number(event.target.value))}
              />
            </div>
          )}

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="schedule-format" className="text-xs font-medium text-muted-foreground">
                Format
              </Label>
              <select
                id="schedule-format"
                aria-label="Format"
                value={format}
                onChange={(event) => setFormat(event.target.value as 'csv' | 'pdf')}
                className="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm"
              >
                <option value="csv">CSV</option>
                <option value="pdf">PDF</option>
              </select>
            </div>
          </div>

          <div className="space-y-1.5">
            <Label className="text-xs font-medium text-muted-foreground">
              Recipients (only users who can view reports)
            </Label>
            <div className="max-h-48 space-y-1 overflow-y-auto rounded-lg border p-3">
              {recipients.map((user: RecipientUser) => (
                <label key={user.id} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    aria-label={user.name}
                    checked={recipientIds.includes(user.id)}
                    onChange={() => toggleRecipient(user.id)}
                    className="h-4 w-4 rounded border-input"
                  />
                  {user.name}
                  <span className="text-xs text-muted-foreground">{user.email}</span>
                </label>
              ))}
            </div>
          </div>

          {createMutation.isError && (
            <p className="text-sm text-destructive">
              {(createMutation.error as Error)?.message ?? 'Failed to create the schedule.'}
            </p>
          )}

          {schedules.length > 0 && (
            <div className="space-y-2">
              <Label className="text-xs font-medium text-muted-foreground">Existing schedules</Label>
              <ul className="divide-y rounded-lg border">
                {schedules.map((schedule: ReportViewSchedule) => (
                  <li key={schedule.id} className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                    <span>
                      {schedule.frequency} · {schedule.time_of_day} · {schedule.format.toUpperCase()} ·{' '}
                      {schedule.recipients.length} recipient(s)
                    </span>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="text-destructive"
                      onClick={() => deleteMutation.mutate(schedule.id)}
                    >
                      Delete
                    </Button>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>

        <DialogFooter className="gap-2 border-t pt-4">
          <Button type="button" variant="outline" onClick={onClose}>
            Close
          </Button>
          <Button
            type="button"
            disabled={recipientIds.length === 0 || createMutation.isPending}
            onClick={() => createMutation.mutate()}
          >
            {createMutation.isPending ? 'Creating…' : 'Create schedule'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default SavedViewBar;
