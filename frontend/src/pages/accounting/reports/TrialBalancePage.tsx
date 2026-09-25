import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { Badge } from '@/components/ui/badge';
import api from '@/lib/api';
import reportApi from '@/lib/reportApi';
import { formatCurrency } from '@/lib/utils';
import { ComparisonTable } from '@/components/reports/ComparisonTable';
import { ReportExportToolbar } from '@/components/reports/ReportExportToolbar';
import {
  type FiscalYearOption,
  type FiscalYearSettings,
  fiscalYearOptions,
  formatFiscalYearOption,
  priorFiscalYear,
} from '@/lib/fiscalYear';

const todayIso = () => {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
};

const formatLongDate = (value?: string | null) => {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
};

const formatShortDate = (value?: string | null) => {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
};

export default function TrialBalancePage() {
  const navigate = useNavigate();
  const [asOfDate, setAsOfDate] = useState('');
  const [compareEnabled, setCompareEnabled] = useState(false);
  const [compareDate, setCompareDate] = useState('');
  const userPickedDate = useRef(false);
  const reportRef = useRef<HTMLDivElement>(null);

  // Fiscal-year settings drive the default as-of date (latest completed
  // fiscal year end) instead of "today".
  const { data: fiscalSettings } = useQuery({
    queryKey: ['fiscal-year'],
    queryFn: async () => {
      const response = await api.get('/accounting/settings/fiscal-year');
      return response.data.data as FiscalYearSettings;
    },
    staleTime: 5 * 60 * 1000,
  });

  const fyOptions: FiscalYearOption[] = useMemo(
    () =>
      fiscalSettings
        ? fiscalYearOptions(
            fiscalSettings.fiscal_year_start_month ?? 1,
            fiscalSettings.fiscal_year_start_day ?? 1,
          )
        : [],
    [fiscalSettings],
  );

  useEffect(() => {
    if (userPickedDate.current || fyOptions.length === 0 || asOfDate) return;
    const latestCompleted = fyOptions.find((option) => option.end <= todayIso()) ?? fyOptions[0];
    setAsOfDate(latestCompleted.end);
  }, [fyOptions, asOfDate]);

  const selectedFy = fyOptions.find((option) => option.end === asOfDate);
  const selectedCompareFy = fyOptions.find((option) => option.end === compareDate);

  const applyFiscalYear = (fy: FiscalYearOption) => {
    userPickedDate.current = true;
    setAsOfDate(fy.end);
  };

  const applyCompareFiscalYear = (fy: FiscalYearOption) => {
    setCompareDate(fy.end);
  };

  // Fetch trial balance
  const { data, isLoading, error } = useQuery({
    queryKey: ['trial-balance', asOfDate, compareEnabled, compareDate],
    queryFn: () =>
      reportApi.getTrialBalance({
        as_of_date: asOfDate,
        ...(compareEnabled && compareDate ? { compare_date: compareDate } : {}),
      }),
    enabled: !!asOfDate,
  });

  const trialBalance = data as any;

  // Build comparison rows for ComparisonTable
  const comparisonRows = () => {
    if (!trialBalance?.comparison) return [];

    const compareMap: Record<string, any> = {};
    (trialBalance.comparison.accounts || []).forEach((acc: any) => {
      compareMap[acc.code] = acc;
    });

    const rows: Array<{ label: string; current: number; compare: number; bold?: boolean; indent?: boolean; sectionHeader?: boolean }> = [];

    (trialBalance.accounts || []).forEach((account: any) => {
      const compareAcc = compareMap[account.code];
      const currentTotal = (account.debit || 0) - (account.credit || 0);
      const compareTotal = compareAcc ? ((compareAcc.debit || 0) - (compareAcc.credit || 0)) : 0;
      rows.push({
        label: `${account.code} - ${account.name}`,
        current: currentTotal,
        compare: compareTotal,
      });
    });

    rows.push({
      label: 'TOTAL DEBITS',
      current: trialBalance.total_debits || 0,
      compare: trialBalance.comparison.total_debits || 0,
      bold: true,
    });
    rows.push({
      label: 'TOTAL CREDITS',
      current: trialBalance.total_credits || 0,
      compare: trialBalance.comparison.total_credits || 0,
      bold: true,
    });

    return rows;
  };

  const isBalanced = Boolean(trialBalance?.is_balanced);

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Trial Balance</h1>
          <p className="mt-1 text-muted-foreground">Verify that debits equal credits</p>
        </div>
        <HeaderActionButton icon="solar:arrow-left-linear" onClick={() => navigate('/accounting')}>
          Back to Accounting
        </HeaderActionButton>
      </div>

      {/* Filters */}
      <Card className="rounded-xl p-4">
        <div className="flex flex-wrap items-end gap-3">
          {fyOptions.length > 0 && (
            <div className="space-y-1.5">
              <Label className="text-xs font-medium text-muted-foreground">Fiscal Year</Label>
              <Select
                value={selectedFy?.label ?? 'custom'}
                onValueChange={(value) => {
                  const fy = fyOptions.find((option) => option.label === value);
                  if (fy) applyFiscalYear(fy);
                }}
              >
                <SelectTrigger className="h-9 w-64 rounded-full text-sm" aria-label="Fiscal year">
                  <SelectValue placeholder="Fiscal year" />
                </SelectTrigger>
                <SelectContent>
                  {fyOptions.map((option) => (
                    <SelectItem key={option.label} value={option.label}>
                      {formatFiscalYearOption(option)}
                    </SelectItem>
                  ))}
                  <SelectItem value="custom">Custom date</SelectItem>
                </SelectContent>
              </Select>
            </div>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="as_of_date" className="text-xs font-medium text-muted-foreground">
              As of Date
            </Label>
            <Input
              id="as_of_date"
              type="date"
              value={asOfDate}
              onChange={(e) => {
                userPickedDate.current = true;
                setAsOfDate(e.target.value);
              }}
              className="h-9 w-40 rounded-full text-sm"
            />
          </div>

          <div className="mx-1 hidden h-9 w-px bg-border sm:block" />

          <label className="flex h-9 cursor-pointer items-center gap-2 text-sm text-muted-foreground">
            <input
              type="checkbox"
              id="compare_enabled"
              checked={compareEnabled}
              onChange={(e) => {
                const checked = e.target.checked;
                setCompareEnabled(checked);
                if (checked && !compareDate) {
                  // Default to the fiscal year before the selected as-of year.
                  const prior = priorFiscalYear(fyOptions, selectedFy, asOfDate) ?? fyOptions[1];
                  if (prior) setCompareDate(prior.end);
                }
                if (!checked) setCompareDate('');
              }}
              className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
            />
            Compare
          </label>

          {compareEnabled && (
            <>
              {fyOptions.length > 0 && (
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Comparison Year</Label>
                  <Select
                    value={selectedCompareFy?.label ?? 'custom'}
                    onValueChange={(value) => {
                      const fy = fyOptions.find((option) => option.label === value);
                      if (fy) applyCompareFiscalYear(fy);
                    }}
                  >
                    <SelectTrigger className="h-9 w-64 rounded-full text-sm" aria-label="Comparison fiscal year">
                      <SelectValue placeholder="Comparison year" />
                    </SelectTrigger>
                    <SelectContent>
                      {fyOptions.map((option) => (
                        <SelectItem key={option.label} value={option.label}>
                          {formatFiscalYearOption(option)}
                        </SelectItem>
                      ))}
                      <SelectItem value="custom">Custom date</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              )}
              <div className="space-y-1.5">
                <Label htmlFor="compare_date" className="text-xs font-medium text-muted-foreground">
                  Comparison Date
                </Label>
                <Input
                  id="compare_date"
                  type="date"
                  value={compareDate}
                  onChange={(e) => setCompareDate(e.target.value)}
                  className="h-9 w-40 rounded-full text-sm"
                />
              </div>
            </>
          )}
        </div>
      </Card>

      {/* Key metrics */}
      {trialBalance && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <Card className="rounded-xl border-blue-200 bg-blue-50 p-4">
            <div className="flex items-center gap-3">
              <div className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-100 text-blue-600">
                <Icon icon="solar:arrow-down-bold-duotone" className="h-5 w-5" />
              </div>
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Total Debits</p>
                <p className="font-mono text-lg font-semibold">{formatCurrency(trialBalance.total_debits || 0)}</p>
              </div>
            </div>
          </Card>

          <Card className="rounded-xl border-purple-200 bg-purple-50 p-4">
            <div className="flex items-center gap-3">
              <div className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-purple-100 text-purple-600">
                <Icon icon="solar:arrow-up-bold-duotone" className="h-5 w-5" />
              </div>
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Total Credits</p>
                <p className="font-mono text-lg font-semibold">{formatCurrency(trialBalance.total_credits || 0)}</p>
              </div>
            </div>
          </Card>

          <Card
            className={`rounded-xl p-4 ${
              isBalanced ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50'
            }`}
          >
            <div className="flex items-center gap-3">
              <div
                className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl ${
                  isBalanced ? 'bg-emerald-100 text-emerald-600' : 'bg-red-100 text-red-600'
                }`}
              >
                <Icon
                  icon={isBalanced ? 'solar:check-circle-bold' : 'solar:danger-circle-bold'}
                  className="h-5 w-5"
                />
              </div>
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Balance Check</p>
                <p className="font-mono text-lg font-semibold">
                  {isBalanced
                    ? 'Balanced'
                    : `Out by ${formatCurrency(Math.abs(trialBalance.difference || 0))}`}
                </p>
              </div>
            </div>
          </Card>
        </div>
      )}

      {/* Comparison Table */}
      {compareEnabled && trialBalance?.comparison && (
        <Card className="rounded-xl p-6">
          <ComparisonTable
            title="Trial Balance Comparison"
            asOfDate={formatShortDate(asOfDate)}
            compareDate={formatShortDate(trialBalance.comparison.as_of_date)}
            filename={`trial-balance-comparison-${asOfDate}`}
            currentLabel={`As of ${formatShortDate(asOfDate)}`}
            compareLabel={`As of ${formatShortDate(trialBalance.comparison.as_of_date)}`}
            rows={comparisonRows()}
            formatCurrency={formatCurrency}
          />
        </Card>
      )}

      {/* Report */}
      <Card className="rounded-xl">
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
          <div>
            <CardTitle className="text-base">Trial Balance</CardTitle>
            <p className="mt-0.5 text-sm text-muted-foreground">As of {formatLongDate(asOfDate)}</p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {trialBalance && (
              <Badge
                variant="outline"
                className={
                  isBalanced
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                    : 'border-red-200 bg-red-50 text-red-700'
                }
              >
                <Icon
                  icon={isBalanced ? 'solar:check-circle-bold' : 'solar:danger-circle-bold'}
                  className="mr-1 h-3.5 w-3.5"
                />
                {isBalanced ? 'Debits equal credits' : 'Out of balance'}
              </Badge>
            )}
            <ReportExportToolbar
              targetRef={reportRef}
              title="Trial Balance"
              subtitle={`As of ${formatLongDate(asOfDate)}`}
              asOfDate={asOfDate}
              filename={`trial-balance-${asOfDate}`}
            />
          </div>
        </CardHeader>

        <CardContent ref={reportRef} className="pt-6">
          {isLoading ? (
            <div className="py-12 text-center">
              <Icon icon="svg-spinners:ring-resize" className="mx-auto h-8 w-8" />
              <p className="mt-2 text-muted-foreground">Loading trial balance...</p>
            </div>
          ) : error ? (
            <div className="py-12 text-center text-destructive">
              <Icon icon="solar:danger-circle-bold-duotone" className="mx-auto mb-4 h-16 w-16 opacity-50" />
              <p>Failed to load trial balance</p>
            </div>
          ) : !trialBalance?.accounts || trialBalance.accounts.length === 0 ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="solar:balance-bold-duotone" className="mx-auto mb-4 h-16 w-16 opacity-50" />
              <p>No accounts found</p>
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-32">Account Code</TableHead>
                  <TableHead>Account Name</TableHead>
                  <TableHead className="text-right">Debit</TableHead>
                  <TableHead className="text-right">Credit</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {trialBalance.accounts.map((account: any) => (
                  <TableRow
                    key={account.id}
                    className="cursor-pointer hover:bg-muted/50"
                    onClick={() =>
                      navigate(
                        `/accounting/reporting/general-ledger?account_id=${account.id}&start_date=${selectedFy?.start ?? `${asOfDate.slice(0, 4)}-01-01`}&end_date=${asOfDate}`,
                      )
                    }
                  >
                    <TableCell className="font-mono text-xs text-muted-foreground">{account.code}</TableCell>
                    <TableCell>{account.name}</TableCell>
                    <TableCell className="text-right font-mono">
                      {account.debit > 0 ? formatCurrency(account.debit) : <span className="text-muted-foreground">—</span>}
                    </TableCell>
                    <TableCell className="text-right font-mono">
                      {account.credit > 0 ? formatCurrency(account.credit) : <span className="text-muted-foreground">—</span>}
                    </TableCell>
                  </TableRow>
                ))}
                <TableRow className="border-t-4 bg-primary/10 font-bold">
                  <TableCell colSpan={2} className="text-base">Total</TableCell>
                  <TableCell className="text-right font-mono text-base">
                    {formatCurrency(trialBalance.total_debits || 0)}
                  </TableCell>
                  <TableCell className="text-right font-mono text-base">
                    {formatCurrency(trialBalance.total_credits || 0)}
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
