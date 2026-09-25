import React, { useEffect, useMemo, useRef, useState } from 'react';
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

interface Account {
  account_id: number;
  code: string;
  name: string;
  balance: number;
  currency_code?: string | null;
  account_group?: string;
  group_name?: string;
}

interface AccountGroup {
  code: string;
  name: string;
  accounts: Account[];
  total: number;
}

interface BalanceSheetData {
  assets: {
    current: Array<AccountGroup | Account>;
    non_current: Array<AccountGroup | Account>;
    total: number;
  };
  liabilities: {
    current: Array<AccountGroup | Account>;
    non_current: Array<AccountGroup | Account>;
    total: number;
  };
  equity: {
    groups?: Array<AccountGroup | Account>;
    accounts?: Array<AccountGroup | Account>;
    current_year_earnings?: number;
    total: number;
  };
  total_assets: number;
  total_liabilities: number;
  total_equity: number;
  total_liabilities_and_equity: number;
  balance_check: number;
  working_capital: number;
  comparison?: {
    as_of_date: string;
    total_assets: { amount: number; change: number; change_percent: number };
    total_liabilities: { amount: number; change: number; change_percent: number };
    total_equity: { amount: number; change: number; change_percent: number };
  };
}

interface BalanceSheetPageProps {
  pageTitle?: string;
  statementTitle?: string;
  financialStatementStyle?: boolean;
}

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

export default function BalanceSheetPage({
  pageTitle = 'Balance Sheet (SFRS)',
  statementTitle = 'Balance Sheet',
  financialStatementStyle = false,
}: BalanceSheetPageProps) {
  const navigate = useNavigate();
  const [asOfDate, setAsOfDate] = useState('');
  const [compareEnabled, setCompareEnabled] = useState(false);
  const [compareDate, setCompareDate] = useState('');
  const [collapsedRows, setCollapsedRows] = useState<Set<string>>(new Set());
  const userPickedDate = useRef(false);
  const reportRef = useRef<HTMLDivElement>(null);

  // Fiscal-year settings drive the default as-of date (the latest completed
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

  const toggleRow = (id: string) => {
    setCollapsedRows((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const { data, isLoading, error } = useQuery({
    queryKey: ['balance-sheet', asOfDate, compareEnabled, compareDate],
    queryFn: () =>
      reportApi.getBalanceSheet({
        as_of_date: asOfDate,
        ...(compareEnabled && compareDate ? { compare_date: compareDate } : {}),
      }),
    enabled: !!asOfDate,
  });

  const balanceSheet = data as BalanceSheetData | undefined;

  const normalizeGroups = (items: Array<AccountGroup | Account> = []): AccountGroup[] => {
    return items.map((item, index) => {
      if ('accounts' in item) {
        return item;
      }

      return {
        code: item.account_group || item.code || `GROUP-${index + 1}`,
        name: item.group_name || item.name,
        accounts: [item],
        total: item.balance,
      };
    });
  };

  const currentAssetsTotal = normalizeGroups(balanceSheet?.assets?.current || []).reduce(
    (sum, group) => sum + group.total,
    0,
  );
  const currentLiabilitiesTotal = normalizeGroups(balanceSheet?.liabilities?.current || []).reduce(
    (sum, group) => sum + group.total,
    0,
  );

  const renderAccountGroup = (group: AccountGroup, sectionId = '') => {
    const rowId = `group:${sectionId}:${group.code}`;
    const isCollapsed = collapsedRows.has(rowId);

    return (
      <React.Fragment key={rowId}>
        <TableRow
          className={
            financialStatementStyle
              ? 'bg-muted/40'
              : 'border-l-4 border-l-blue-300 bg-blue-50/60'
          }
        >
          <TableCell className="pl-6">
            <div className="flex items-center gap-2">
              <button
                type="button"
                aria-label={isCollapsed ? `Expand ${group.name}` : `Collapse ${group.name}`}
                className="shrink-0 rounded p-0.5 text-muted-foreground hover:bg-black/5"
                onClick={() => toggleRow(rowId)}
              >
                <Icon
                  icon={isCollapsed ? 'solar:alt-arrow-right-linear' : 'solar:alt-arrow-down-linear'}
                  className="h-4 w-4"
                />
              </button>
              {!financialStatementStyle && (
                <Badge className="bg-blue-100 text-xs font-semibold text-blue-800">L2</Badge>
              )}
              <span className="font-semibold">{group.name}</span>
            </div>
          </TableCell>
          <TableCell className="text-right font-mono font-semibold">
            {formatCurrency(group.total)}
          </TableCell>
        </TableRow>
        {!isCollapsed &&
          group.accounts.map((account) => (
            <TableRow
              key={account.account_id}
              className="cursor-pointer hover:bg-muted/50"
              onClick={() =>
                navigate(
                  `/accounting/reporting/general-ledger?account_id=${account.account_id}&start_date=${selectedFy?.start ?? `${asOfDate.slice(0, 4)}-01-01`}&end_date=${asOfDate}`,
                )
              }
            >
              <TableCell className="pl-14">
                <div className="flex items-center gap-2">
                  {!financialStatementStyle && (
                    <Badge className="bg-gray-100 text-xs text-gray-600">L3</Badge>
                  )}
                  <span className="font-mono text-xs text-muted-foreground">{account.code}</span>
                  <span>{account.name}</span>
                  {account.currency_code && (
                    <Badge variant="outline" className="text-xs">{account.currency_code}</Badge>
                  )}
                </div>
              </TableCell>
              <TableCell className="text-right font-mono">{formatCurrency(account.balance)}</TableCell>
            </TableRow>
          ))}
      </React.Fragment>
    );
  };

  const renderSection = (title: string, groups: AccountGroup[], total: number) => {
    if (!groups || groups.length === 0) return null;
    const sectionId = `section:${title.toLowerCase().replace(/ /g, '-')}`;
    const isCollapsed = collapsedRows.has(sectionId);

    return (
      <React.Fragment key={sectionId}>
        <TableRow
          className={
            financialStatementStyle
              ? 'bg-muted/60'
              : 'border-l-4 border-l-purple-400 bg-purple-50/80'
          }
        >
          <TableCell className="py-3 pl-4">
            <div className="flex items-center gap-2">
              <button
                type="button"
                aria-label={isCollapsed ? `Expand ${title}` : `Collapse ${title}`}
                className="shrink-0 rounded p-0.5 text-muted-foreground hover:bg-black/5"
                onClick={() => toggleRow(sectionId)}
              >
                <Icon
                  icon={isCollapsed ? 'solar:alt-arrow-right-linear' : 'solar:alt-arrow-down-linear'}
                  className="h-4 w-4"
                />
              </button>
              {!financialStatementStyle && (
                <Badge className="bg-purple-100 text-xs font-bold text-purple-800">L1</Badge>
              )}
              <span className={`text-base font-bold ${financialStatementStyle ? '' : 'text-purple-900'}`}>
                {title}
              </span>
            </div>
          </TableCell>
          <TableCell />
        </TableRow>
        {!isCollapsed && groups.map((group) => renderAccountGroup(group, sectionId))}
        {!isCollapsed && (
          <TableRow className="border-t font-bold">
            <TableCell className="pl-4">Total {title}</TableCell>
            <TableCell className="text-right font-mono text-base">{formatCurrency(total)}</TableCell>
          </TableRow>
        )}
      </React.Fragment>
    );
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">{pageTitle}</h1>
          <p className="mt-1 text-muted-foreground">
            Singapore Financial Reporting Standards compliant
          </p>
        </div>
        <HeaderActionButton icon="solar:arrow-left-linear" onClick={() => navigate('/accounting')}>
          Back
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
      {!financialStatementStyle && balanceSheet && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <Card
            className={`rounded-xl p-4 ${
              Math.abs(balanceSheet.balance_check || 0) < 0.01
                ? 'border-emerald-200 bg-emerald-50'
                : 'border-red-200 bg-red-50'
            }`}
          >
            <div className="flex items-center gap-3">
              <div
                className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl ${
                  Math.abs(balanceSheet.balance_check || 0) < 0.01
                    ? 'bg-emerald-100 text-emerald-600'
                    : 'bg-red-100 text-red-600'
                }`}
              >
                <Icon
                  icon={
                    Math.abs(balanceSheet.balance_check || 0) < 0.01
                      ? 'solar:check-circle-bold'
                      : 'solar:danger-circle-bold'
                  }
                  className="h-5 w-5"
                />
              </div>
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Balance Check</p>
                <p className="font-mono text-lg font-semibold">
                  {Math.abs(balanceSheet.balance_check || 0) < 0.01
                    ? 'Balanced'
                    : formatCurrency(Math.abs(balanceSheet.balance_check || 0))}
                </p>
              </div>
            </div>
          </Card>

          <Card className="rounded-xl border-blue-200 bg-blue-50 p-4">
            <div className="flex items-center gap-3">
              <div className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-100 text-blue-600">
                <Icon icon="solar:wallet-bold-duotone" className="h-5 w-5" />
              </div>
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Working Capital</p>
                <p className="font-mono text-lg font-semibold">{formatCurrency(balanceSheet.working_capital || 0)}</p>
              </div>
            </div>
          </Card>

          <Card className="rounded-xl border-purple-200 bg-purple-50 p-4">
            <div className="flex items-center gap-3">
              <div className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-purple-100 text-purple-600">
                <Icon icon="solar:chart-2-bold-duotone" className="h-5 w-5" />
              </div>
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Current Ratio</p>
                <p className="font-mono text-lg font-semibold">
                  {currentLiabilitiesTotal > 0
                    ? (currentAssetsTotal / currentLiabilitiesTotal).toFixed(2)
                    : '∞'}
                </p>
              </div>
            </div>
          </Card>
        </div>
      )}

      {/* Comparison */}
      {compareEnabled && balanceSheet?.comparison && (
        <Card className="rounded-xl p-6">
          <ComparisonTable
            title={`${statementTitle} Comparison`}
            asOfDate={formatShortDate(asOfDate)}
            compareDate={formatShortDate(balanceSheet.comparison.as_of_date)}
            filename={`balance-sheet-comparison-${asOfDate}`}
            currentLabel={`As of ${formatShortDate(asOfDate)}`}
            compareLabel={`As of ${formatShortDate(balanceSheet.comparison.as_of_date)}`}
            rows={[
              { label: 'ASSETS', current: 0, compare: 0, sectionHeader: true },
              {
                label: 'Total Current Assets',
                current: currentAssetsTotal,
                compare: currentAssetsTotal - (balanceSheet.comparison?.total_assets?.change || 0),
                indent: true,
              },
              {
                label: 'Total Non-Current Assets',
                current: normalizeGroups(balanceSheet.assets?.non_current || []).reduce((sum, group) => sum + group.total, 0),
                compare:
                  normalizeGroups(balanceSheet.assets?.non_current || []).reduce((sum, group) => sum + group.total, 0) -
                  (balanceSheet.comparison?.total_assets?.change || 0),
                indent: true,
              },
              {
                label: 'TOTAL ASSETS',
                current: balanceSheet.total_assets || 0,
                compare: balanceSheet.comparison.total_assets.amount,
                bold: true,
              },
              { label: 'LIABILITIES', current: 0, compare: 0, sectionHeader: true },
              {
                label: 'Total Current Liabilities',
                current: currentLiabilitiesTotal,
                compare: currentLiabilitiesTotal - (balanceSheet.comparison?.total_liabilities?.change || 0),
                indent: true,
              },
              {
                label: 'Total Non-Current Liabilities',
                current: normalizeGroups(balanceSheet.liabilities?.non_current || []).reduce((sum, group) => sum + group.total, 0),
                compare:
                  normalizeGroups(balanceSheet.liabilities?.non_current || []).reduce((sum, group) => sum + group.total, 0) -
                  (balanceSheet.comparison?.total_liabilities?.change || 0),
                indent: true,
              },
              {
                label: 'TOTAL LIABILITIES',
                current: balanceSheet.total_liabilities || 0,
                compare: balanceSheet.comparison.total_liabilities.amount,
                bold: true,
              },
              { label: 'EQUITY', current: 0, compare: 0, sectionHeader: true },
              {
                label: 'TOTAL EQUITY',
                current: balanceSheet.total_equity || 0,
                compare: balanceSheet.comparison.total_equity.amount,
                bold: true,
              },
              {
                label: 'TOTAL LIABILITIES + EQUITY',
                current: balanceSheet.total_liabilities_and_equity || 0,
                compare: balanceSheet.comparison.total_liabilities.amount + balanceSheet.comparison.total_equity.amount,
                bold: true,
              },
            ]}
            formatCurrency={formatCurrency}
          />
        </Card>
      )}

      {/* Statement */}
      <Card className="rounded-xl">
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
          <div>
            <CardTitle className="text-base">{statementTitle}</CardTitle>
            <p className="mt-0.5 text-sm text-muted-foreground">
              As of {formatLongDate(asOfDate)}
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant="outline" className="border-emerald-200 bg-emerald-50 text-emerald-700">
              SFRS Compliant
            </Badge>
            <HeaderActionButton
              variant="outline"
              icon="solar:maximize-linear"
              onClick={() => setCollapsedRows(new Set())}
            >
              Expand all
            </HeaderActionButton>
            <HeaderActionButton
              variant="outline"
              icon="solar:minimize-linear"
              onClick={() =>
                setCollapsedRows(
                  new Set([
                    'section:current-assets',
                    'section:non-current-assets',
                    'section:current-liabilities',
                    'section:non-current-liabilities',
                    'section:equity',
                  ]),
                )
              }
            >
              Collapse all
            </HeaderActionButton>
            <ReportExportToolbar
              targetRef={reportRef}
              title={statementTitle}
              subtitle={`As of ${formatLongDate(asOfDate)}`}
              asOfDate={asOfDate}
              filename={`${statementTitle.toLowerCase().replace(/\s+/g, '-')}-${asOfDate}`}
            />
          </div>
        </CardHeader>

        <CardContent ref={reportRef} className="pt-6">
          {isLoading ? (
            <div className="py-12 text-center">
              <Icon icon="svg-spinners:ring-resize" className="mx-auto h-8 w-8" />
              <p className="mt-2 text-muted-foreground">Loading balance sheet...</p>
            </div>
          ) : error ? (
            <div className="py-12 text-center text-destructive">
              <Icon icon="solar:danger-circle-bold-duotone" className="mx-auto mb-4 h-16 w-16 opacity-50" />
              <p>Failed to load balance sheet</p>
            </div>
          ) : !balanceSheet ? (
            <div className="py-12 text-center text-muted-foreground">
              <p>No data available</p>
            </div>
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-2/3">Account</TableHead>
                    <TableHead className="text-right">Amount</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow className={financialStatementStyle ? 'bg-muted/50' : 'bg-slate-100/80'}>
                    <TableCell className="py-4 text-lg font-bold tracking-wide">ASSETS</TableCell>
                    <TableCell />
                  </TableRow>

                  {renderSection('Current Assets', normalizeGroups(balanceSheet.assets?.current || []), currentAssetsTotal)}

                  {renderSection(
                    'Non-Current Assets',
                    normalizeGroups(balanceSheet.assets?.non_current || []),
                    normalizeGroups(balanceSheet.assets?.non_current || []).reduce((sum, group) => sum + group.total, 0),
                  )}

                  <TableRow
                    className={
                      financialStatementStyle ? 'border-t-2 font-bold' : 'border-t-4 bg-primary/10 font-bold'
                    }
                  >
                    <TableCell className="py-4 text-lg">TOTAL ASSETS</TableCell>
                    <TableCell className="text-right font-mono text-lg">
                      {formatCurrency(balanceSheet.total_assets || 0)}
                    </TableCell>
                  </TableRow>

                  <TableRow>
                    <TableCell colSpan={2} className="h-6" />
                  </TableRow>

                  <TableRow className={financialStatementStyle ? 'bg-muted/50' : 'bg-slate-100/80'}>
                    <TableCell className="py-4 text-lg font-bold tracking-wide">LIABILITIES</TableCell>
                    <TableCell />
                  </TableRow>

                  {renderSection(
                    'Current Liabilities',
                    normalizeGroups(balanceSheet.liabilities?.current || []),
                    currentLiabilitiesTotal,
                  )}

                  {renderSection(
                    'Non-Current Liabilities',
                    normalizeGroups(balanceSheet.liabilities?.non_current || []),
                    normalizeGroups(balanceSheet.liabilities?.non_current || []).reduce((sum, group) => sum + group.total, 0),
                  )}

                  <TableRow
                    className={
                      financialStatementStyle ? 'border-t-2 font-bold' : 'border-t-4 bg-primary/10 font-bold'
                    }
                  >
                    <TableCell className="py-4 text-lg">TOTAL LIABILITIES</TableCell>
                    <TableCell className="text-right font-mono text-lg">
                      {formatCurrency(balanceSheet.total_liabilities || 0)}
                    </TableCell>
                  </TableRow>

                  <TableRow
                    className={
                      financialStatementStyle ? 'border-y-2 font-bold' : 'border-y-4 bg-muted/60 font-bold'
                    }
                  >
                    <TableCell className="py-4 text-lg">NET ASSETS/(LIABILITIES)</TableCell>
                    <TableCell className="text-right font-mono text-lg">
                      {formatCurrency((balanceSheet.total_assets || 0) - (balanceSheet.total_liabilities || 0))}
                    </TableCell>
                  </TableRow>

                  <TableRow>
                    <TableCell colSpan={2} className="h-6" />
                  </TableRow>

                  <TableRow className={financialStatementStyle ? 'bg-muted/50' : 'bg-slate-100/80'}>
                    <TableCell className="py-4 text-lg font-bold tracking-wide">
                      <button type="button" className="flex items-center gap-2" onClick={() => toggleRow('section:equity')}>
                        <Icon
                          icon={
                            collapsedRows.has('section:equity')
                              ? 'solar:alt-arrow-right-linear'
                              : 'solar:alt-arrow-down-linear'
                          }
                          className="h-4 w-4"
                        />
                        EQUITY
                      </button>
                    </TableCell>
                    <TableCell />
                  </TableRow>

                  {!collapsedRows.has('section:equity') &&
                    (financialStatementStyle
                      ? (() => {
                          const equityAccounts = normalizeGroups(
                            balanceSheet.equity?.groups || balanceSheet.equity?.accounts || [],
                          ).flatMap((group) => group.accounts);
                          const shareCapital = equityAccounts
                            .filter((account) => /share capital/i.test(account.name))
                            .reduce((sum, account) => sum + account.balance, 0);
                          const retainedProfit = (balanceSheet.total_equity || 0) - shareCapital;

                          return (
                            <>
                              <TableRow>
                                <TableCell className="pl-6">Share Capital</TableCell>
                                <TableCell className="text-right font-mono">{formatCurrency(shareCapital)}</TableCell>
                              </TableRow>
                              <TableRow>
                                <TableCell className="pl-6">Retained Profit</TableCell>
                                <TableCell className="text-right font-mono">{formatCurrency(retainedProfit)}</TableCell>
                              </TableRow>
                            </>
                          );
                        })()
                      : normalizeGroups(balanceSheet.equity?.groups || balanceSheet.equity?.accounts || []).map(
                          (group) => renderAccountGroup(group, 'section:equity'),
                        ))}

                  {/* Current year earnings: separate line on the standard balance sheet. */}
                  {!collapsedRows.has('section:equity') &&
                    !financialStatementStyle &&
                    balanceSheet.equity?.current_year_earnings !== undefined && (
                      <TableRow className="border-t">
                        <TableCell className="py-3 pl-14 font-semibold">
                          Current Year Earnings (Net Profit/Loss)
                        </TableCell>
                        <TableCell
                          className={`text-right font-mono ${
                            balanceSheet.equity.current_year_earnings < 0 ? 'text-red-600' : 'text-emerald-600'
                          }`}
                        >
                          {formatCurrency(balanceSheet.equity.current_year_earnings)}
                        </TableCell>
                      </TableRow>
                    )}

                  {!collapsedRows.has('section:equity') && (
                    <TableRow
                      className={
                        financialStatementStyle
                          ? 'border-y-2 font-bold'
                          : 'border-t-4 bg-primary/10 font-bold'
                      }
                    >
                      <TableCell className="py-4 text-lg">
                        {financialStatementStyle ? 'TOTAL EQUITY/(CAPITAL DEFICIENCY)' : 'TOTAL EQUITY'}
                      </TableCell>
                      <TableCell className="text-right font-mono text-lg">
                        {formatCurrency(balanceSheet.total_equity || 0)}
                      </TableCell>
                    </TableRow>
                  )}

                  {!financialStatementStyle && (
                    <>
                      <TableRow>
                        <TableCell colSpan={2} className="h-4" />
                      </TableRow>
                      <TableRow className="border-y-4 bg-primary/20 font-bold">
                        <TableCell className="py-4 text-lg">TOTAL LIABILITIES + EQUITY</TableCell>
                        <TableCell className="text-right font-mono text-lg">
                          {formatCurrency(balanceSheet.total_liabilities_and_equity || 0)}
                        </TableCell>
                      </TableRow>
                    </>
                  )}
                </TableBody>
              </Table>
            </>
          )}
        </CardContent>
      </Card>

      {/* SFRS hierarchy legend */}
      {!financialStatementStyle && (
        <div className="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
          <span className="font-medium text-foreground">SFRS 3-level hierarchy</span>
          <span className="flex items-center gap-1.5">
            <Badge className="bg-purple-100 text-xs font-bold text-purple-800">L1</Badge>
            Main categories
          </span>
          <span className="flex items-center gap-1.5">
            <Badge className="bg-blue-100 text-xs font-semibold text-blue-800">L2</Badge>
            Account groups
          </span>
          <span className="flex items-center gap-1.5">
            <Badge className="bg-gray-100 text-xs text-gray-600">L3</Badge>
            Individual accounts
          </span>
          <span className="ml-auto hidden text-muted-foreground sm:inline">
            Click an account to open its general ledger
          </span>
        </div>
      )}
    </div>
  );
}
