import { useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { Input } from '@/components/ui/input';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { useToast } from '@/hooks/use-toast';
import { StatCard } from '@/components/ui/stat-card';
import { Icon } from '@iconify/react';
import { ReportExportToolbar } from '@/components/reports/ReportExportToolbar';
import api from '@/lib/api';

interface JournalRow {
  reporting_period: string;
  source_document_type: string;
  source_document_id: number;
  tax_code: string;
  tax_rate: number | null;
  tax_treatment: string | null;
  tax_regime: string | null;
  taxable_amount: number;
  tax_amount: number;
  direction: 'output' | 'input';
}

interface JournalResponse {
  data: {
    months: string[];
    rows: JournalRow[];
    by_tax_code: { tax_code: string; output_tax: number; input_tax: number; taxable_amount: number; rows: number }[];
    totals: { output_tax: number; input_tax: number; net: number };
  };
}

interface ReconciliationResponse {
  data: {
    period: string;
    balanced: boolean;
    tax_period_locked?: boolean;
    total_abs_variance: number;
    by_tax_code: { tax_code: string; direction: string; trail_tax: number; expected_gl_net: number; gl_net: number; variance: number }[];
    uncoded_gl_movement: { count: number; net: number };
    explanations: { kind: string; severity: string; message: string }[];
  };
}

interface FilingsResponse {
  data: {
    filings: { id: number; country: string; form: string; period: string; entity_key: number; filed_at: string | null; filed_by: number | null }[];
    open_periods: { country: string; period: string; rows: number }[];
  };
}

const money = (value: number) =>
  Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const DOC_LABELS: Record<string, string> = {
  invoice: 'Invoice',
  bill: 'Vendor bill',
  pos_order: 'POS sale',
  web_order: 'Web order',
  storefront_order: 'Storefront order',
  credit_note: 'Customer credit note',
  vendor_credit_note: 'Vendor credit note',
  vendor_debit_note: 'Vendor debit note',
};

function currentMonth() {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

export default function TaxReportPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const month = currentMonth();
  const [from, setFrom] = useState(month);
  const [to, setTo] = useState(month);
  const [entityId, setEntityId] = useState('');
  const [reconPeriod, setReconPeriod] = useState(month);

  const entityParams = entityId.trim() ? { entity_id: entityId.trim() } : {};

  const journalRef = useRef<HTMLDivElement | null>(null);
  const reconRef = useRef<HTMLDivElement | null>(null);

  const journal = useQuery<JournalResponse>({
    queryKey: ['tax-report-journal', from, to, entityId],
    queryFn: async () => (await api.get('/accounting/tax-report/journal', { params: { from, to, ...entityParams } })).data,
    enabled: /^\d{4}-\d{2}$/.test(from) && /^\d{4}-\d{2}$/.test(to),
  });

  const periodLock = useMutation<{ message?: string; data?: unknown }, Error, { period: string; action: 'close' | 'reopen' }>({
    mutationFn: async ({ period, action }) => (await api.post(`/accounting/tax-periods/${period}/${action}`)).data,
    onSuccess: (_d, vars) => {
      toast({ title: vars.action === 'close' ? `Tax period ${vars.period} closed` : `Tax period ${vars.period} reopened` });
      queryClient.invalidateQueries({ queryKey: ['tax-report-reconciliation'] });
      queryClient.invalidateQueries({ queryKey: ['tax-report-filings'] });
    },
    onError: (e) => toast({ title: 'Operation failed', description: e.message, variant: 'destructive' }),
  });

  const recon = useQuery<ReconciliationResponse>({
    queryKey: ['tax-report-reconciliation', reconPeriod, entityId],
    queryFn: async () => (await api.get('/accounting/tax-report/reconciliation', { params: { period: reconPeriod, ...entityParams } })).data,
    enabled: /^\d{4}-\d{2}$/.test(reconPeriod),
  });

  const filings = useQuery<FilingsResponse>({
    queryKey: ['tax-report-filings'],
    queryFn: async () => (await api.get('/accounting/tax-report/filings')).data,
  });

  const totals = journal.data?.data.totals;
  const rows = useMemo(() => journal.data?.data.rows ?? [], [journal.data]);

  const journalExportRows = useMemo<string[][]>(() => [
    ['Period', 'Document', 'Tax code', 'Side', 'Treatment', 'Regime', 'Rate %', 'Taxable', 'Tax'],
    ...rows.map((row) => [
      row.reporting_period,
      `${DOC_LABELS[row.source_document_type] ?? row.source_document_type} #${row.source_document_id}`,
      row.tax_code,
      row.direction,
      row.tax_treatment ?? '',
      row.tax_regime ?? '',
      row.tax_rate !== null ? String(Number(row.tax_rate)) : '',
      money(row.taxable_amount),
      money(row.tax_amount),
    ]),
    ...(totals ? [
      [],
      ['TOTAL', '', '', '', '', '', '', '', `Output ${money(totals.output_tax)}; Input ${money(totals.input_tax)}; Net ${money(totals.net)}`],
    ] : []),
  ], [rows, totals]);

  const reconData = recon.data?.data;

  const reconExportRows = useMemo<string[][]>(() => [
    ['Tax code', 'Side', 'Trail tax', 'Expected GL net', 'Actual GL net', 'Variance'],
    ...(reconData?.by_tax_code ?? []).map((row) => [
      row.tax_code, row.direction, money(row.trail_tax), money(row.expected_gl_net), money(row.gl_net), money(row.variance),
    ]),
    [],
    ['Untagged GL movement (no tax_code)', '', '', '', String(reconData?.uncoded_gl_movement.count ?? 0), money(reconData?.uncoded_gl_movement.net ?? 0)],
    ['Status', reconData?.balanced ? 'BALANCED' : `DRIFT ${money(reconData?.total_abs_variance ?? 0)}`],
  ], [reconData]);

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <HeaderActionButton
          variant="outline"
          icon="solar:alt-arrow-left-bold-duotone"
          onClick={() => navigate('/accounting')}
          className="mb-3"
        >
          Back to Accounting
        </HeaderActionButton>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">Tax Report</h1>
            <p className="mt-1 text-muted-foreground">
              Tax journal, ledger reconciliation and filing tracker — every return figure traces here
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <HeaderActionButton variant="outline" icon="solar:document-text-bold-duotone" onClick={() => navigate('/accounting/tax-returns')}>
              Statutory Returns
            </HeaderActionButton>
            <HeaderActionButton variant="outline" icon="solar:cpu-bolt-bold-duotone" onClick={() => navigate('/accounting/tax-engine')}>
              Tax Engine
            </HeaderActionButton>
          </div>
        </div>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex items-center gap-2">
          <span className="text-xs font-medium text-muted-foreground">Journal</span>
          <Input
            aria-label="Journal from (YYYY-MM)"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            placeholder="YYYY-MM"
            className="h-8 w-32 rounded-full px-3 font-mono text-xs"
          />
          <span className="text-xs text-muted-foreground">to</span>
          <Input
            aria-label="Journal to (YYYY-MM)"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            placeholder="YYYY-MM"
            className="h-8 w-32 rounded-full px-3 font-mono text-xs"
          />
        </div>
        <Input
          aria-label="Legal entity (optional)"
          value={entityId}
          onChange={(e) => setEntityId(e.target.value)}
          className="h-8 w-32 rounded-full px-3 text-xs"
          placeholder="All entities"
          inputMode="numeric"
        />
      </div>

      {/* KPIs */}
      {totals && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <StatCard
            label="Output tax (sales, signed)"
            value={money(totals.output_tax)}
            hint="Collected on sales"
            icon="solar:graph-up-bold-duotone"
            tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
          />
          <StatCard
            label="Input tax (purchases, signed)"
            value={money(totals.input_tax)}
            hint="Claimable on purchases"
            icon="solar:graph-down-bold-duotone"
            tone="bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400"
          />
          <StatCard
            label="Net tax position"
            value={totals.net >= 0 ? `${money(totals.net)} payable` : `${money(-totals.net)} refundable`}
            hint={totals.net >= 0 ? 'Payable to the authority' : 'Refundable'}
            icon="solar:wallet-money-bold-duotone"
            tone={totals.net >= 0
              ? 'bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400'
              : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400'}
          />
        </div>
      )}

      {/* Tax journal */}
      <div ref={journalRef}>
        <Card className="overflow-hidden rounded-xl">
          <CardHeader className="border-b">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400">
                  <Icon icon="solar:bill-list-bold-duotone" className="h-5 w-5" />
                </div>
                <div>
                  <CardTitle className="text-base">Tax journal</CardTitle>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {journal.data?.data.months.length ?? 0} period(s) · {rows.length} row(s)
                  </p>
                </div>
              </div>
              <ReportExportToolbar
                targetRef={journalRef}
                title="Tax Journal"
                subtitle={`Periods ${from} to ${to}${entityId.trim() ? ` · entity #${entityId.trim()}` : ''}`}
                asOfDate={to}
                rows={journalExportRows}
                columnAligns={['left', 'left', 'left', 'left', 'left', 'left', 'right', 'right', 'right']}
                filename={`tax-journal-${from}-${to}`}
              />
            </div>
          </CardHeader>
          <CardContent className="p-0">
            {rows.length === 0 ? (
              <div className="flex flex-col items-center gap-2 py-16 text-center text-muted-foreground">
                {journal.isFetching ? (
                  <Icon icon="eos-icons:loading" className="h-7 w-7 animate-spin text-primary" />
                ) : (
                  <>
                    <Icon icon="solar:bill-list-bold-duotone" className="h-10 w-10 opacity-30" />
                    <p className="text-sm font-medium">No tax transactions in this range</p>
                    <p className="text-xs">
                      Post tax-bearing documents{entityId ? ' for the selected entity' : ''} to populate the journal.
                    </p>
                  </>
                )}
              </div>
            ) : (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow className="bg-muted/50">
                      <TableHead>Period</TableHead>
                      <TableHead>Document</TableHead>
                      <TableHead>Tax code</TableHead>
                      <TableHead>Side</TableHead>
                      <TableHead>Treatment</TableHead>
                      <TableHead className="text-right">Rate</TableHead>
                      <TableHead className="text-right">Taxable</TableHead>
                      <TableHead className="text-right">Tax</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {rows.map((row, index) => (
                      <TableRow key={`${row.source_document_type}-${row.source_document_id}-${row.tax_code}-${index}`} className="hover:bg-muted/50">
                        <TableCell className="font-mono text-xs">{row.reporting_period}</TableCell>
                        <TableCell className="text-sm">
                          {DOC_LABELS[row.source_document_type] ?? row.source_document_type}
                          <span className="text-xs text-muted-foreground"> #{row.source_document_id}</span>
                        </TableCell>
                        <TableCell className="font-mono text-xs">{row.tax_code}</TableCell>
                        <TableCell>
                          <Badge
                            variant="secondary"
                            className={row.direction === 'output'
                              ? 'bg-orange-100 text-orange-700 dark:bg-orange-950/60 dark:text-orange-300'
                              : 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300'}
                          >
                            {row.direction}
                          </Badge>
                        </TableCell>
                        <TableCell className="text-xs text-muted-foreground">
                          {row.tax_treatment ?? '—'} {row.tax_regime ? `· ${row.tax_regime}` : ''}
                        </TableCell>
                        <TableCell className="text-right font-mono text-xs">{row.tax_rate !== null ? `${Number(row.tax_rate)}%` : '—'}</TableCell>
                        <TableCell className="text-right tabular-nums">{money(row.taxable_amount)}</TableCell>
                        <TableCell className={`text-right font-semibold tabular-nums ${Number(row.tax_amount) < 0 ? 'text-emerald-600 dark:text-emerald-400' : ''}`}>
                          {money(row.tax_amount)}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* Ledger reconciliation */}
        <div ref={reconRef}>
          <Card className="overflow-hidden rounded-xl">
            <CardHeader className="border-b">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                  <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${
                    recon.data && !recon.data.data.balanced
                      ? 'bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400'
                      : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400'
                  }`}>
                    <Icon icon="solar:scale-bold-duotone" className="h-5 w-5" />
                  </div>
                  <div>
                    <CardTitle className="text-base">Ledger reconciliation</CardTitle>
                    <p className="mt-1 text-sm text-muted-foreground">Trail vs live GL, per tax code.</p>
                  </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <Input
                    aria-label="Reconciliation period"
                    value={reconPeriod}
                    onChange={(e) => setReconPeriod(e.target.value)}
                    className="h-8 w-28 rounded-full px-3 font-mono text-xs"
                  />
                  {recon.data?.data.tax_period_locked && (
                    <Badge variant="secondary" className="bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300">
                      Locked
                    </Badge>
                  )}
                  <button
                    type="button"
                    disabled={periodLock.isPending}
                    className="h-8 rounded-full border px-3 text-xs font-medium hover:bg-muted disabled:opacity-50"
                    onClick={() => periodLock.mutate({ period: reconPeriod, action: recon.data?.data.tax_period_locked ? 'reopen' : 'close' })}
                  >
                    {recon.data?.data.tax_period_locked ? 'Reopen period' : 'Close period'}
                  </button>
                  {recon.data && (
                    <Badge
                      variant="secondary"
                      className={recon.data.data.balanced
                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                        : 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'}
                    >
                      {recon.data.data.balanced ? 'Balanced' : `Drift ${money(recon.data.data.total_abs_variance)}`}
                    </Badge>
                  )}
                  <ReportExportToolbar
                    targetRef={reconRef}
                    title="Tax Ledger Reconciliation"
                    subtitle={`Period ${reconPeriod}${entityId.trim() ? ` · entity #${entityId.trim()}` : ''}`}
                    asOfDate={reconPeriod}
                    rows={reconExportRows}
                    filename={`tax-reconciliation-${reconPeriod}`}
                  />
                </div>
              </div>
            </CardHeader>
            <CardContent className="space-y-3 p-4">
              <p className="text-xs text-muted-foreground">
                Trail (<code className="rounded bg-muted px-1 py-0.5 font-mono text-[11px]">tax_transaction_details</code>) vs live GL journal lines, per tax
                code. Untagged movements are manual journals or legacy postings that will never appear on a return.
              </p>
              <div className="overflow-hidden rounded-xl border border-slate-200/80 dark:border-slate-800">
                <Table>
                  <TableHeader>
                    <TableRow className="bg-muted/50">
                      <TableHead>Tax code</TableHead>
                      <TableHead className="text-right">Expected GL</TableHead>
                      <TableHead className="text-right">Actual GL</TableHead>
                      <TableHead className="text-right">Variance</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {(recon.data?.data.by_tax_code ?? []).map((row) => (
                      <TableRow key={row.tax_code}>
                        <TableCell className="font-mono text-xs">{row.tax_code}</TableCell>
                        <TableCell className="text-right tabular-nums">{money(row.expected_gl_net)}</TableCell>
                        <TableCell className="text-right tabular-nums">{money(row.gl_net)}</TableCell>
                        <TableCell className={`text-right font-semibold tabular-nums ${Math.abs(row.variance) >= 0.01 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'}`}>
                          {money(row.variance)}
                        </TableCell>
                      </TableRow>
                    ))}
                    {(recon.data?.data.by_tax_code ?? []).length === 0 && (
                      <TableRow>
                        <TableCell colSpan={4} className="py-10 text-center text-sm text-muted-foreground">
                          No tax codes with activity in this period.
                        </TableCell>
                      </TableRow>
                    )}
                  </TableBody>
                </Table>
              </div>
              {recon.data?.data.explanations.map((explanation) => (
                <div
                  key={explanation.kind + explanation.message}
                  className={`flex items-start gap-2 rounded-xl border p-3 text-xs ${
                    explanation.severity === 'explain_variance'
                      ? 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200'
                      : 'border-slate-200 bg-muted/40 text-muted-foreground dark:border-slate-800'
                  }`}
                >
                  <Icon
                    icon={explanation.severity === 'explain_variance' ? 'solar:danger-triangle-bold-duotone' : 'solar:info-circle-bold-duotone'}
                    className="mt-0.5 h-3.5 w-3.5 shrink-0"
                  />
                  {explanation.message}
                </div>
              ))}
            </CardContent>
          </Card>
        </div>

        {/* Filing tracker */}
        <Card className="overflow-hidden rounded-xl">
          <CardHeader className="border-b">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-500/10">
                <Icon icon="solar:clipboard-check-bold-duotone" className="h-5 w-5 text-blue-600 dark:text-blue-400" />
              </div>
              <div>
                <CardTitle className="text-base">Filing tracker</CardTitle>
                <p className="mt-1 text-sm text-muted-foreground">Open periods and recorded filing baselines.</p>
              </div>
            </div>
          </CardHeader>
          <CardContent className="space-y-4 p-4">
            <div>
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">
                Periods with activity, no filed return
              </p>
              {(filings.data?.data.open_periods ?? []).length === 0 ? (
                <div className="flex items-center gap-2 rounded-xl border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                  <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                  All active periods have a recorded filing baseline.
                </div>
              ) : (
                <div className="space-y-2">
                  {(filings.data?.data.open_periods ?? []).slice(0, 8).map((open) => (
                    <div
                      key={`${open.country}-${open.period}`}
                      className="flex flex-wrap items-center justify-between gap-2 rounded-xl border px-4 py-2.5"
                    >
                      <span className="flex items-center gap-2">
                        <Badge variant="outline" className="font-normal">{open.country}</Badge>
                        <span className="font-mono text-sm font-medium tabular-nums">{open.period}</span>
                        <span className="text-xs text-muted-foreground">{open.rows} trail row(s)</span>
                      </span>
                      <HeaderActionButton
                        variant="outline"
                        icon="solar:document-add-bold-duotone"
                        onClick={() => navigate('/accounting/tax-returns')}
                      >
                        Generate &amp; file
                      </HeaderActionButton>
                    </div>
                  ))}
                </div>
              )}
            </div>

            <div>
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Filed baselines</p>
              <div className="overflow-hidden rounded-xl border border-slate-200/80 dark:border-slate-800">
                <Table>
                  <TableHeader>
                    <TableRow className="bg-muted/50">
                      <TableHead>Form</TableHead>
                      <TableHead>Period</TableHead>
                      <TableHead>Entity</TableHead>
                      <TableHead>Filed at</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {(filings.data?.data.filings ?? []).map((filing) => (
                      <TableRow key={filing.id}>
                        <TableCell className="text-sm font-medium">
                          {filing.form} <span className="text-xs text-muted-foreground">({filing.country})</span>
                        </TableCell>
                        <TableCell className="font-mono text-xs">{filing.period}</TableCell>
                        <TableCell className="text-xs text-muted-foreground">{filing.entity_key === 0 ? 'all' : `#${filing.entity_key}`}</TableCell>
                        <TableCell className="text-xs text-muted-foreground">{filing.filed_at ? String(filing.filed_at).replace('T', ' ').slice(0, 16) : '—'}</TableCell>
                      </TableRow>
                    ))}
                    {(filings.data?.data.filings ?? []).length === 0 && (
                      <TableRow>
                        <TableCell colSpan={4} className="py-10 text-center text-sm text-muted-foreground">
                          Nothing filed yet.
                        </TableCell>
                      </TableRow>
                    )}
                  </TableBody>
                </Table>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
