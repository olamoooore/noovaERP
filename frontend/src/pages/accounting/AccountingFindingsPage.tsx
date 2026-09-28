import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { StatCard } from '@/components/ui/stat-card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { findingsApi, readinessApi, type AiFinding } from '@/services/closeApi';
import { useToast } from '@/hooks/use-toast';
import { useAuthStore } from '@/store/authStore';
import { formatDate } from '@/lib/utils';

/**
 * Accounting Findings — the review inbox for anomalies detected by the close
 * automation (negative asset / liability balances, unusual balances, missing
 * support, unposted entries, overdue invoices, …) plus anything raised
 * manually.
 *
 * Source of truth for detected findings is the close-analysis store
 * (`/close/findings`); the analysis writes findings on every scan run
 * (Financial Close → AI Findings → Refresh, or the "Run scan" button here).
 */

const RISK_STYLES: Record<string, string> = {
  low: 'bg-sky-100 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300',
  medium: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
  high: 'bg-orange-100 text-orange-700 dark:bg-orange-950/60 dark:text-orange-300',
  critical: 'bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300',
};

const STATUS_STYLES: Record<string, { label: string; className: string }> = {
  open: { label: 'Open', className: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300' },
  accepted: { label: 'Accepted', className: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300' },
  dismissed: { label: 'Dismissed', className: 'bg-muted text-muted-foreground' },
  escalated: { label: 'Escalated', className: 'bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300' },
  edited: { label: 'Edited', className: 'bg-muted text-muted-foreground' },
};

const TYPE_LABELS: Record<string, string> = {
  anomaly: 'Anomaly',
  missing_support: 'Missing support',
  unposted_entries: 'Unposted entries',
  overdue_invoices: 'Overdue invoices',
};

const typeLabel = (type?: string) =>
  TYPE_LABELS[type ?? ''] ?? (type ? type.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase()) : '—');

export default function AccountingFindingsPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const { hasPermission } = useAuthStore();

  const [entityFilter, setEntityFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all');
  const [riskFilter, setRiskFilter] = useState('all');
  const [selected, setSelected] = useState<AiFinding | null>(null);
  const [showDetail, setShowDetail] = useState(false);

  const canManage = hasPermission('accounting.close.finding_write');

  const { data: scope } = useQuery({
    queryKey: ['close-finding-scope'],
    queryFn: () => readinessApi.entities().then((r) => r.data.data),
    staleTime: 5 * 60 * 1000,
  });

  const { data: findings = [], isLoading, isError } = useQuery<AiFinding[]>({
    queryKey: ['ai-findings', entityFilter, statusFilter, riskFilter],
    queryFn: () =>
      findingsApi
        .listAll({
          entity_id: entityFilter === 'all' ? undefined : entityFilter,
          status: statusFilter === 'all' ? undefined : statusFilter,
          risk_status: riskFilter === 'all' ? undefined : riskFilter,
        })
        .then((r) => r.data.data),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['ai-findings'] });

  const acceptMutation = useMutation({
    mutationFn: (id: string) => findingsApi.accept(id),
    onSuccess: () => {
      invalidate();
      setShowDetail(false);
      toast({ title: 'Finding accepted' });
    },
    onError: () => toast({ title: 'Action failed', variant: 'destructive' }),
  });

  const dismissMutation = useMutation({
    mutationFn: (id: string) => findingsApi.dismiss(id),
    onSuccess: () => {
      invalidate();
      setShowDetail(false);
      toast({ title: 'Finding dismissed' });
    },
    onError: () => toast({ title: 'Action failed', variant: 'destructive' }),
  });

  const escalateMutation = useMutation({
    mutationFn: (id: string) => findingsApi.escalate(id),
    onSuccess: () => {
      invalidate();
      setShowDetail(false);
      toast({ title: 'Finding escalated' });
    },
    onError: () => toast({ title: 'Action failed', variant: 'destructive' }),
  });

  const scanMutation = useMutation({
    mutationFn: () =>
      findingsApi.refreshFindings(entityFilter, scope?.periods?.[0] ?? ''),
    onSuccess: (res) => {
      invalidate();
      const created = res.data?.findings_created ?? 0;
      toast({
        title: created > 0 ? `Scan complete — ${created} new finding(s)` : 'Scan complete — no new findings',
      });
    },
    onError: () => toast({ title: 'Scan failed', variant: 'destructive' }),
  });

  const openDetail = (finding: AiFinding) => {
    setSelected(finding);
    setShowDetail(true);
  };

  const total = findings.length;
  const openCount = findings.filter((f) => f.status === 'open').length;
  const highCriticalCount = findings.filter(
    (f) => f.risk_status === 'critical' || f.risk_status === 'high',
  ).length;
  const escalatedCount = findings.filter((f) => f.status === 'escalated').length;

  const renderRiskBadge = (risk: string) => (
    <Badge variant="outline" className={`border-transparent font-normal capitalize ${RISK_STYLES[risk] ?? 'bg-muted text-muted-foreground'}`}>
      {risk}
    </Badge>
  );

  const renderStatusBadge = (status: string) => {
    const cfg = STATUS_STYLES[status] ?? { label: status, className: 'bg-muted text-muted-foreground' };
    return (
      <Badge variant="outline" className={`border-transparent font-normal ${cfg.className}`}>
        {cfg.label}
      </Badge>
    );
  };

  const renderActions = (finding: AiFinding, compact = false) => {
    const isOpen = finding.status === 'open';
    const pending =
      acceptMutation.isPending || dismissMutation.isPending || escalateMutation.isPending;

    if (!canManage || !isOpen) {
      return compact ? <span className="text-xs text-muted-foreground">—</span> : null;
    }

    const btn = compact ? 'h-7 w-7' : 'h-8';

    return (
      <div className="flex items-center gap-1">
        <Button
          size="icon"
          variant="ghost"
          className={`${btn} text-emerald-600 hover:text-emerald-700`}
          title="Accept finding"
          aria-label="Accept finding"
          disabled={pending}
          onClick={() => acceptMutation.mutate(finding.id)}
        >
          <Icon icon="solar:check-circle-bold-duotone" className="h-3.5 w-3.5" />
        </Button>
        <Button
          size="icon"
          variant="ghost"
          className={`${btn} text-muted-foreground`}
          title="Dismiss finding"
          aria-label="Dismiss finding"
          disabled={pending}
          onClick={() => dismissMutation.mutate(finding.id)}
        >
          <Icon icon="solar:close-circle-bold-duotone" className="h-3.5 w-3.5" />
        </Button>
        <Button
          size="icon"
          variant="ghost"
          className={`${btn} text-red-600 hover:text-red-700`}
          title="Escalate finding"
          aria-label="Escalate finding"
          disabled={pending}
          onClick={() => escalateMutation.mutate(finding.id)}
        >
          <Icon icon="solar:arrow-up-bold-duotone" className="h-3.5 w-3.5" />
        </Button>
      </div>
    );
  };

  return (
    <div className="p-6 space-y-6">
      {/* Header — filters sit with the actions so the page does not spend a
          whole row on three selects. */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Accounting Findings</h1>
          <p className="mt-1 text-muted-foreground">
            Review and resolve accounting findings and anomalies detected by the close automation.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Select value={entityFilter} onValueChange={setEntityFilter}>
            <SelectTrigger className="h-8 w-40 rounded-full text-xs" aria-label="Filter by entity">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All entities</SelectItem>
              {(scope?.entities ?? []).map((entity) => (
                <SelectItem key={entity} value={entity}>
                  {entity}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger className="h-8 w-36 rounded-full text-xs" aria-label="Filter by status">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              <SelectItem value="open">Open</SelectItem>
              <SelectItem value="accepted">Accepted</SelectItem>
              <SelectItem value="escalated">Escalated</SelectItem>
              <SelectItem value="dismissed">Dismissed</SelectItem>
            </SelectContent>
          </Select>

          <Select value={riskFilter} onValueChange={setRiskFilter}>
            <SelectTrigger className="h-8 w-36 rounded-full text-xs" aria-label="Filter by risk">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All risk levels</SelectItem>
              <SelectItem value="critical">Critical</SelectItem>
              <SelectItem value="high">High</SelectItem>
              <SelectItem value="medium">Medium</SelectItem>
              <SelectItem value="low">Low</SelectItem>
            </SelectContent>
          </Select>

          {canManage && (
            <HeaderActionButton
              icon="solar:refresh-bold-duotone"
              variant="outline"
              loading={scanMutation.isPending}
              disabled={entityFilter === 'all' || scanMutation.isPending}
              title={
                entityFilter === 'all'
                  ? 'Select an entity to run a new scan'
                  : 'Run the close analysis again for this entity'
              }
              onClick={() => scanMutation.mutate()}
            >
              Run scan
            </HeaderActionButton>
          )}
        </div>
      </div>

      {/* Summary */}
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Total" value={total} icon="solar:document-text-bold-duotone" />
        <StatCard
          label="Open"
          value={openCount}
          icon="solar:hourglass-bold-duotone"
          tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
        />
        <StatCard
          label="High & critical"
          value={highCriticalCount}
          icon="solar:danger-triangle-bold-duotone"
          tone="bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400"
        />
        <StatCard
          label="Escalated"
          value={escalatedCount}
          icon="solar:arrow-up-bold-duotone"
          tone="bg-orange-100 text-orange-600 dark:bg-orange-950/60 dark:text-orange-400"
        />
      </div>

      {/* Findings list */}
      <Card className="overflow-hidden rounded-xl">
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
          <CardTitle className="text-base">Findings</CardTitle>
          {total > 0 && (
            <span className="text-sm font-normal text-muted-foreground">
              {total} finding(s)
            </span>
          )}
        </CardHeader>
        <CardContent className="p-0">
          {isLoading ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="eos-icons:loading" className="mx-auto h-7 w-7 animate-spin" />
              <p className="mt-2 text-sm">Loading findings…</p>
            </div>
          ) : isError ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="solar:danger-triangle-bold-duotone" className="mx-auto h-8 w-8 text-red-500" />
              <p className="mt-2 text-sm">Could not load findings. Try again.</p>
            </div>
          ) : total === 0 ? (
            <div className="flex flex-col items-center justify-center gap-2 px-4 py-14 text-center">
              <Icon icon="solar:check-circle-bold-duotone" className="h-10 w-10 text-emerald-500/60" />
              <p className="text-sm font-medium">No findings found</p>
              <p className="max-w-md text-xs text-muted-foreground">
                Nothing is outstanding for the current filters. Findings appear here after the close
                analysis runs — select an entity and use “Run scan”, or change the filters above.
              </p>
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Finding</TableHead>
                  <TableHead className="w-[9rem]">Type</TableHead>
                  <TableHead className="w-[7rem]">Account</TableHead>
                  <TableHead className="w-[6.5rem]">Risk</TableHead>
                  <TableHead className="w-[7rem]">Status</TableHead>
                  <TableHead className="w-[7.5rem]">Detected</TableHead>
                  <TableHead className="w-[1%] whitespace-nowrap text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {findings.map((finding) => (
                  <TableRow
                    key={finding.id}
                    className="cursor-pointer hover:bg-muted/50"
                    onClick={() => openDetail(finding)}
                  >
                    <TableCell>
                      <div className="line-clamp-2 text-sm">{finding.description}</div>
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {typeLabel(finding.finding_type)}
                    </TableCell>
                    <TableCell className="text-sm tabular-nums">
                      {finding.account_code || '—'}
                    </TableCell>
                    <TableCell>{renderRiskBadge(finding.risk_status)}</TableCell>
                    <TableCell>{renderStatusBadge(finding.status)}</TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {finding.created_at ? formatDate(finding.created_at) : '—'}
                    </TableCell>
                    <TableCell
                      className="text-right"
                      onClick={(event) => event.stopPropagation()}
                    >
                      {renderActions(finding, true)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Detail */}
      <Dialog open={showDetail} onOpenChange={setShowDetail}>
        <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle className="flex flex-wrap items-center gap-2">
              {selected && renderRiskBadge(selected.risk_status)}
              {selected && renderStatusBadge(selected.status)}
              <span>{typeLabel(selected?.finding_type)}</span>
            </DialogTitle>
            <DialogDescription>
              {selected?.account_code ? `Account ${selected.account_code}` : 'Accounting finding'}
              {selected?.confidence != null && ` · confidence ${Math.round(selected.confidence * 100)}%`}
              {selected?.entity_id && ` · ${selected.entity_id}`}
            </DialogDescription>
          </DialogHeader>

          {selected && (
            <div className="space-y-4 py-2">
              <div className="rounded-lg border bg-muted/30 p-4 text-sm">{selected.description}</div>

              {selected.likely_cause && (
                <div>
                  <p className="text-sm font-medium">Likely cause</p>
                  <p className="mt-1 text-sm text-muted-foreground">{selected.likely_cause}</p>
                </div>
              )}

              {selected.recommendation && (
                <div>
                  <p className="text-sm font-medium">Recommendation</p>
                  <p className="mt-1 text-sm text-muted-foreground">{selected.recommendation}</p>
                </div>
              )}

              {(selected.detail_items?.length ?? 0) > 0 && (
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  {selected.detail_items.map((item) => (
                    <div key={item.label} className="rounded-lg border p-3">
                      <p className="text-xs text-muted-foreground">{item.label}</p>
                      <p className="mt-0.5 text-sm font-medium tabular-nums">{item.value}</p>
                    </div>
                  ))}
                </div>
              )}

              {(selected.source_refs?.length ?? 0) > 0 && (
                <div>
                  <p className="text-sm font-medium">Sources</p>
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    {selected.source_refs!.map((ref) => (
                      <button
                        key={`${ref.type}-${ref.id}`}
                        type="button"
                        disabled={!ref.route}
                        onClick={() => ref.route && navigate(ref.route)}
                        className="inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted disabled:opacity-60"
                        title={ref.meta?.reference ? `Reference ${ref.meta.reference}` : ref.label}
                      >
                        <Icon icon="solar:link-bold-duotone" className="h-3 w-3" />
                        {ref.label}
                      </button>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}

          <DialogFooter>
            <Button variant="outline" onClick={() => setShowDetail(false)}>
              Close
            </Button>
            {selected && renderActions(selected)}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
