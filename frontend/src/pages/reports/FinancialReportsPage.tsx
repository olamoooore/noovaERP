import React, { useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Button } from '@/components/ui/button';
import { ReportViewer } from '@/components/reports/ReportViewer';
import { CompactReportFilters } from '@/components/reports/CompactReportFilters';
import { ReportTable, Column } from '@/components/reports/ReportTable';
import { ComparisonHierarchyTable, type ReportTreeNode } from '@/components/reports/ComparisonHierarchyTable';
import { useReportAutoRefresh } from '@/hooks/useReportAutoRefresh';
import reportApi from '@/lib/reportApi';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { ReportFilters as ReportFiltersType } from '@/types/reports';

// ─── Comparison period math ─────────────────────────────────────────
// Yearly/Quarterly/Monthly shift the selected window back by 12/3/1
// months (month-end clamped, e.g. 2026-03-31 -> 2026-02-28). Custom
// supplies explicit dates. Fiscal-year comparisons fall out naturally:
// select the fiscal window (1 Sep – 31 Aug) and pick "yearly".

type CompareMode = 'none' | 'yearly' | 'quarterly' | 'monthly' | 'custom';

const COMPARE_MODES: { value: CompareMode; label: string }[] = [
  { value: 'none', label: 'Off' },
  { value: 'yearly', label: 'Yearly' },
  { value: 'quarterly', label: 'Quarterly' },
  { value: 'monthly', label: 'Monthly' },
  { value: 'custom', label: 'Custom range' },
];

const compareMonthsBack = (mode: CompareMode): number | null =>
  mode === 'yearly' ? 12 : mode === 'quarterly' ? 3 : mode === 'monthly' ? 1 : null;

function shiftDateStr(dateStr: string, monthsBack: number): string {
  const [y, m, d] = dateStr.split('-').map(Number);
  if (!y || !m || !d) return dateStr;
  let ty = y;
  let tm = m - monthsBack;
  while (tm <= 0) {
    tm += 12;
    ty -= 1;
  }
  const lastDay = new Date(ty, tm, 0).getDate();
  const td = Math.min(d, lastDay);
  return `${ty}-${String(tm).padStart(2, '0')}-${String(td).padStart(2, '0')}`;
}

const num = (v: unknown): number => (Number.isFinite(Number(v)) ? Number(v) : 0);

// ─── Balance Sheet tree merging ─────────────────────────────────────

interface BsGroupLike {
  code?: string;
  name?: string;
  total?: number;
  accounts?: Array<{ code?: string; name?: string; balance?: number }>;
}

function mergeBsGroups(
  currentGroups: BsGroupLike[],
  compareGroups: BsGroupLike[] | null
): ReportTreeNode[] {
  const withCompare = compareGroups !== null;
  const map = new Map<
    string,
    {
      code: string;
      name: string;
      sums: { current: number; compare: number };
      accounts: Map<string, { code: string; name: string; sums: { current: number; compare: number } }>;
    }
  >();

  const addSide = (groups: BsGroupLike[], side: 'current' | 'compare') => {
    groups.forEach((g, gi) => {
      const gkey = g.code || g.name || `grp-${gi}`;
      if (!map.has(gkey)) {
        map.set(gkey, { code: g.code || '', name: g.name || '', sums: { current: 0, compare: 0 }, accounts: new Map() });
      }
      const entry = map.get(gkey)!;
      const accounts = g.accounts ?? [];
      entry.sums[side] = accounts.length
        ? accounts.reduce((s, a) => s + num(a.balance), 0)
        : num(g.total);
      accounts.forEach((a, ai) => {
        const akey = a.code || a.name || `acc-${ai}`;
        const prev = entry.accounts.get(akey) ?? { code: a.code || '', name: a.name || '', sums: { current: 0, compare: 0 } };
        prev.sums[side] += num(a.balance);
        entry.accounts.set(akey, prev);
      });
    });
  };

  addSide(currentGroups, 'current');
  if (compareGroups) addSide(compareGroups, 'compare');

  return [...map.entries()]
    .sort((a, b) => (a[1].code || a[0]).localeCompare(b[1].code || b[0]))
    .map(([key, e]) => ({
      id: `bsg:${key}`,
      code: e.code,
      name: e.name,
      current: e.sums.current,
      compare: withCompare ? e.sums.compare : undefined,
      children: [...e.accounts.values()].map((a) => ({
        id: `bsa:${key}:${a.code || a.name}`,
        code: a.code,
        name: a.name,
        current: a.sums.current,
        compare: withCompare ? a.sums.compare : undefined,
      })),
    }));
}

const bsAssetGroups = (d: any): BsGroupLike[] => [
  ...((d?.assets?.current as BsGroupLike[]) ?? []),
  ...((d?.assets?.non_current as BsGroupLike[]) ?? []),
];

const bsLiabilityGroups = (d: any): BsGroupLike[] => [
  ...((d?.liabilities?.current as BsGroupLike[]) ?? []),
  ...((d?.liabilities?.non_current as BsGroupLike[]) ?? []),
];

const bsEquityGroups = (d: any): BsGroupLike[] => {
  const groups = d?.equity?.groups;
  if (Array.isArray(groups) && groups.length) return groups as BsGroupLike[];
  const accounts = d?.equity?.accounts;
  if (Array.isArray(accounts) && accounts.length) {
    return [{ code: '3000', name: 'Equity', accounts }];
  }
  return [];
};

function buildBsRoots(data: any, compare: any | null): ReportTreeNode[] {
  const withCompare = compare !== null;
  const sum = (nodes: ReportTreeNode[], side: 'current' | 'compare') =>
    nodes.reduce((s, n) => s + (side === 'current' ? n.current : n.compare ?? 0), 0);

  const sections: Array<[string, string, BsGroupLike[], BsGroupLike[]]> = [
    ['assets', 'ASSETS', bsAssetGroups(data), withCompare ? bsAssetGroups(compare) : []],
    ['liabilities', 'LIABILITIES', bsLiabilityGroups(data), withCompare ? bsLiabilityGroups(compare) : []],
    ['equity', 'EQUITY', bsEquityGroups(data), withCompare ? bsEquityGroups(compare) : []],
  ];

  const roots: ReportTreeNode[] = [];
  const totals = { current: {} as Record<string, number>, compare: {} as Record<string, number> };

  for (const [key, label, curGroups, cmpGroups] of sections) {
    const children = mergeBsGroups(curGroups, withCompare ? cmpGroups : null);
    totals.current[key] = sum(children, 'current');
    if (withCompare) totals.compare[key] = sum(children, 'compare');
    roots.push({
      id: `bs-${key}`,
      name: label,
      current: totals.current[key],
      compare: withCompare ? totals.compare[key] : undefined,
      children,
    });
  }

  const totalA = totals.current.assets;
  const totalLE = totals.current.liabilities + totals.current.equity;
  roots.push(
    { id: 'bs-total-a', name: 'TOTAL ASSETS', current: totalA, compare: withCompare ? totals.compare.assets : undefined, summary: true },
    {
      id: 'bs-total-le',
      name: 'TOTAL LIABILITIES + EQUITY',
      current: totalLE,
      compare: withCompare ? totals.compare.liabilities + totals.compare.equity : undefined,
      summary: true,
    },
    {
      id: 'bs-check',
      name: 'BALANCE CHECK (A − L − E)',
      current: totalA - totalLE,
      compare: withCompare ? totals.compare.assets - totals.compare.liabilities - totals.compare.equity : undefined,
      summary: true,
    }
  );
  return roots;
}

// ─── P&L tree merging ───────────────────────────────────────────────

interface PlAccountLike {
  code?: string;
  account_name?: string;
  name?: string;
  amount?: number;
}

function mergePlAccounts(curList: PlAccountLike[], cmpList: PlAccountLike[] | null): ReportTreeNode[] {
  const withCompare = cmpList !== null;
  const map = new Map<string, { code: string; name: string; sums: { current: number; compare: number } }>();
  const addSide = (list: PlAccountLike[], side: 'current' | 'compare') => {
    list.forEach((a, i) => {
      const key = a.code || a.name || a.account_name || `acc-${i}`;
      const prev = map.get(key) ?? { code: a.code || '', name: a.name || a.account_name || '', sums: { current: 0, compare: 0 } };
      prev.sums[side] += num(a.amount);
      map.set(key, prev);
    });
  };
  addSide(curList, 'current');
  if (cmpList) addSide(cmpList, 'compare');
  return [...map.entries()]
    .sort((a, b) => (a[1].code || a[0]).localeCompare(b[1].code || b[0]))
    .map(([key, e]) => ({
      id: `pla:${key}`,
      code: e.code,
      name: e.name,
      current: e.sums.current,
      compare: withCompare ? e.sums.compare : undefined,
    }));
}

function buildPlRoots(data: any, compare: any | null): ReportTreeNode[] {
  const withCompare = compare !== null;
  const sum = (nodes: ReportTreeNode[]) => nodes.reduce((s, n) => s + n.current, 0);
  const sumCmp = (nodes: ReportTreeNode[]) => nodes.reduce((s, n) => s + (n.compare ?? 0), 0);

  const section = (key: string, label: string): ReportTreeNode => {
    const children = mergePlAccounts(
      (data?.[key]?.accounts as PlAccountLike[]) ?? [],
      withCompare ? ((compare?.[key]?.accounts as PlAccountLike[]) ?? []) : null
    );
    return {
      id: `pl-${key}`,
      name: label,
      current: sum(children),
      compare: withCompare ? sumCmp(children) : undefined,
      children,
    };
  };

  const revenue = section('revenue', 'REVENUE');
  const cogs = section('cost_of_goods_sold', 'COST OF GOODS SOLD');
  const grossCur = revenue.current - cogs.current;
  const grossCmp = withCompare ? (revenue.compare ?? 0) - (cogs.compare ?? 0) : undefined;
  const gross = { id: 'pl-gross', name: 'GROSS PROFIT', current: grossCur, compare: grossCmp, summary: true } as ReportTreeNode;

  const opex = section('operating_expenses', 'OPERATING EXPENSES');
  const opCur = grossCur - opex.current;
  const opCmp = withCompare ? (grossCmp ?? 0) - (opex.compare ?? 0) : undefined;
  const operating = { id: 'pl-operating', name: 'OPERATING PROFIT', current: opCur, compare: opCmp, summary: true } as ReportTreeNode;

  const otherInc = section('other_income', 'OTHER INCOME');
  const otherExp = section('other_expenses', 'OTHER EXPENSES');
  const netCur = opCur + otherInc.current - otherExp.current;
  const netCmp = withCompare ? (opCmp ?? 0) + (otherInc.compare ?? 0) - (otherExp.compare ?? 0) : undefined;
  const net = { id: 'pl-net', name: 'NET PROFIT', current: netCur, compare: netCmp, summary: true } as ReportTreeNode;

  return [revenue, cogs, gross, opex, operating, otherInc, otherExp, net];
}

export function FinancialReportsPage() {
  const [activeTab, setActiveTab] = useState('profit-loss');
  const queryClient = useQueryClient();
  useReportAutoRefresh(); // auto-refresh reports after any tracked mutation
  const [filters, setFilters] = useState<ReportFiltersType>({
    start_date: new Date(new Date().getFullYear(), new Date().getMonth(), 1)
      .toISOString()
      .split('T')[0],
    end_date: new Date().toISOString().split('T')[0],
  });

  // Profit & Loss Query — short staleTime ensures real-time data on revisit
  const {
    data: profitLossData,
    isLoading: isPLLoading,
    error: plError,
    refetch: refetchPL,
  } = useQuery({
    queryKey: ['profit-loss', filters],
    queryFn: () => reportApi.getProfitLoss(filters),
    enabled: activeTab === 'profit-loss',
    staleTime: 15 * 1000,
  });

  // Balance Sheet Query
  const {
    data: balanceSheetData,
    isLoading: isBSLoading,
    error: bsError,
    refetch: refetchBS,
  } = useQuery({
    queryKey: ['balance-sheet', filters],
    queryFn: () => reportApi.getBalanceSheet(filters),
    enabled: activeTab === 'balance-sheet',
    staleTime: 15 * 1000,
  });

  // ── Comparison (P&L + Balance Sheet) ─────────────────────────────
  const [compareMode, setCompareMode] = useState<CompareMode>('none');
  const [customCmp, setCustomCmp] = useState<{ start_date: string; end_date: string }>({
    start_date: '',
    end_date: '',
  });

  // P&L compares activity over two windows; Balance Sheet compares two
  // as-of dates (the BS window end acts as the as-of date).
  const plCompareFilters = useMemo<ReportFiltersType | null>(() => {
    if (compareMode === 'none' || activeTab !== 'profit-loss') return null;
    if (compareMode === 'custom') {
      if (!customCmp.start_date || !customCmp.end_date) return null;
      return { ...filters, start_date: customCmp.start_date, end_date: customCmp.end_date };
    }
    const months = compareMonthsBack(compareMode);
    if (!months || !filters.start_date || !filters.end_date) return null;
    return { ...filters, start_date: shiftDateStr(filters.start_date, months), end_date: shiftDateStr(filters.end_date, months) };
  }, [compareMode, filters, customCmp, activeTab]);

  const {
    data: plCompareData,
    isFetching: isPlCmpFetching,
  } = useQuery({
    queryKey: ['profit-loss-compare', plCompareFilters],
    queryFn: () => reportApi.getProfitLoss(plCompareFilters as ReportFiltersType),
    enabled: !!plCompareFilters,
    staleTime: 15 * 1000,
  });

  const bsCompareAsOf = useMemo<string | null>(() => {
    if (compareMode === 'none' || activeTab !== 'balance-sheet') return null;
    if (compareMode === 'custom') return customCmp.end_date || null;
    const months = compareMonthsBack(compareMode);
    const asOf = filters.end_date || filters.start_date;
    if (!months || !asOf) return null;
    return shiftDateStr(asOf, months);
  }, [compareMode, filters, customCmp, activeTab]);

  const {
    data: bsCompareData,
    isFetching: isBsCmpFetching,
  } = useQuery({
    queryKey: ['balance-sheet-compare', bsCompareAsOf, filters?.department_id, filters?.account_id],
    queryFn: () =>
      reportApi.getBalanceSheet({ ...filters, start_date: undefined, end_date: undefined, as_of_date: bsCompareAsOf as string }),
    enabled: !!bsCompareAsOf,
    staleTime: 15 * 1000,
  });

  const plComparing = compareMode !== 'none' && !!plCompareFilters && !!plCompareData;
  const bsComparing = compareMode !== 'none' && !!bsCompareAsOf && !!bsCompareData;

  const plCurrentLabel = `${formatDate(filters.start_date || '')} – ${formatDate(filters.end_date || '')}`;
  const plCompareLabel = plCompareFilters
    ? `${formatDate(plCompareFilters.start_date || '')} – ${formatDate(plCompareFilters.end_date || '')}`
    : undefined;
  const bsCurrentLabel = `As of ${formatDate(filters.end_date || '')}`;
  const bsCompareLabel = bsCompareAsOf ? `As of ${formatDate(bsCompareAsOf)}` : undefined;

  const compareToolbar = (
    <div className="flex flex-wrap items-center gap-2">
      <span className="text-sm font-medium text-muted-foreground">Comparison:</span>
      {COMPARE_MODES.map((m) => (
        <Button
          key={m.value}
          size="sm"
          variant={compareMode === m.value ? 'default' : 'outline'}
          className="h-8 text-xs"
          onClick={() => setCompareMode(m.value)}
        >
          {m.label}
        </Button>
      ))}
      {compareMode === 'custom' && (
        <div className="flex items-center gap-2 ml-2">
          <input
            type="date"
            className="h-8 rounded-md border border-input bg-background px-2 text-sm"
            value={customCmp.start_date}
            onChange={(e) => setCustomCmp((p) => ({ ...p, start_date: e.target.value }))}
          />
          <span className="text-muted-foreground">–</span>
          <input
            type="date"
            className="h-8 rounded-md border border-input bg-background px-2 text-sm"
            value={customCmp.end_date}
            onChange={(e) => setCustomCmp((p) => ({ ...p, end_date: e.target.value }))}
          />
          <span className="text-xs text-muted-foreground">
            (P&amp;L window; Balance Sheet uses the end date as its "as of")
          </span>
        </div>
      )}
      {compareMode !== 'none' && (
        <span className="text-xs text-muted-foreground ml-2">
          {activeTab === 'balance-sheet'
            ? !bsCompareAsOf
              ? 'Pick a complete custom range to compare.'
              : isBsCmpFetching && !bsCompareData
                ? 'Loading comparison…'
                : ''
            : !plCompareFilters
              ? 'Pick a complete custom range to compare.'
              : isPlCmpFetching && !plCompareData
                ? 'Loading comparison…'
                : ''}
        </span>
      )}
    </div>
  );

  // Cash Flow Query
  const {
    data: cashFlowData,
    isLoading: isCFLoading,
    error: cfError,
    refetch: refetchCF,
  } = useQuery({
    queryKey: ['cash-flow', filters],
    queryFn: () => reportApi.getCashFlow(filters),
    enabled: activeTab === 'cash-flow',
    staleTime: 15 * 1000,
  });

  // Trial Balance Query
  const {
    data: trialBalanceData,
    isLoading: isTBLoading,
    error: tbError,
    refetch: refetchTB,
  } = useQuery({
    queryKey: ['trial-balance', filters],
    queryFn: () => reportApi.getTrialBalance(filters),
    enabled: activeTab === 'trial-balance',
    staleTime: 15 * 1000,
  });

  // General Ledger Query
  const {
    data: generalLedgerData,
    isLoading: isGLLoading,
    error: glError,
    refetch: refetchGL,
  } = useQuery({
    queryKey: ['general-ledger', filters],
    queryFn: () => reportApi.getGeneralLedger(filters),
    enabled: activeTab === 'general-ledger',
    staleTime: 15 * 1000,
  });

  const handleFilterChange = (newFilters: ReportFiltersType) => {
    setFilters(newFilters);
  };

  const handleExport = () => {
    // TODO: Implement export functionality
    console.log('Export report:', activeTab);
  };

  const handlePrint = () => {
    window.print();
  };

  // Cash Flow Columns
  const cfColumns: Column[] = [
    {
      key: 'description',
      label: 'Description',
      sortable: true,
      filterable: true,
    },
    {
      key: 'amount',
      label: 'Amount',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
  ];

  // Trial Balance Columns
  const tbColumns: Column[] = [
    {
      key: 'code',
      label: 'Code',
      sortable: true,
    },
    {
      key: 'name',
      label: 'Account',
      sortable: true,
      filterable: true,
    },
    {
      key: 'account_type',
      label: 'Type',
      sortable: true,
      filterable: true,
    },
    {
      key: 'debit',
      label: 'Debit',
      sortable: true,
      align: 'right',
      format: (value) => (value > 0 ? formatCurrency(value) : '-'),
    },
    {
      key: 'credit',
      label: 'Credit',
      sortable: true,
      align: 'right',
      format: (value) => (value > 0 ? formatCurrency(value) : '-'),
    },
  ];

  // General Ledger Columns
  const glColumns: Column[] = [
    {
      key: 'date',
      label: 'Date',
      sortable: true,
      format: (value) => formatDate(value),
    },
    {
      key: 'reference',
      label: 'Reference',
      sortable: true,
      filterable: true,
    },
    {
      key: 'description',
      label: 'Description',
      sortable: true,
      filterable: true,
    },
    {
      key: 'debit',
      label: 'Debit',
      sortable: true,
      align: 'right',
      format: (value) => (value > 0 ? formatCurrency(value) : '-'),
    },
    {
      key: 'credit',
      label: 'Credit',
      sortable: true,
      align: 'right',
      format: (value) => (value > 0 ? formatCurrency(value) : '-'),
    },
    {
      key: 'balance',
      label: 'Balance',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
  ];

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Financial Reports</h1>
          <p className="text-gray-600 dark:text-gray-400 mt-1">
            View comprehensive financial statements and reports
          </p>
        </div>
      </div>

      {/* Filters — compact toolbar (no large filter card) */}
      <CompactReportFilters
        onFilterChange={handleFilterChange}
        initialFilters={filters}
        showDateRange
        showComparePeriod
        showDepartment
        showRefresh
        onRefresh={() => {
          queryClient.invalidateQueries({ queryKey: ['profit-loss'] });
          queryClient.invalidateQueries({ queryKey: ['balance-sheet'] });
          queryClient.invalidateQueries({ queryKey: ['cash-flow'] });
          queryClient.invalidateQueries({ queryKey: ['trial-balance'] });
          queryClient.invalidateQueries({ queryKey: ['general-ledger'] });
        }}
      />

      {/* Reports Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="grid w-full grid-cols-5">
          <TabsTrigger value="profit-loss">
            <Icon icon="solar:chart-bold" className="mr-2 h-4 w-4" />
            P&L
          </TabsTrigger>
          <TabsTrigger value="balance-sheet">
            <Icon icon="solar:balance-bold" className="mr-2 h-4 w-4" />
            Balance Sheet
          </TabsTrigger>
          <TabsTrigger value="cash-flow">
            <Icon icon="solar:dollar-minimalistic-bold" className="mr-2 h-4 w-4" />
            Cash Flow
          </TabsTrigger>
          <TabsTrigger value="trial-balance">
            <Icon icon="solar:calculator-bold" className="mr-2 h-4 w-4" />
            Trial Balance
          </TabsTrigger>
          <TabsTrigger value="general-ledger">
            <Icon icon="solar:book-bold" className="mr-2 h-4 w-4" />
            General Ledger
          </TabsTrigger>
        </TabsList>

        {/* Profit & Loss Tab */}
        <TabsContent value="profit-loss">
          <ReportViewer
            title="Profit & Loss Statement"
            description={
              plComparing
                ? `${plCurrentLabel} compared with ${plCompareLabel}`
                : `For the period ${formatDate(filters.start_date || '')} to ${formatDate(filters.end_date || '')}`
            }
            isLoading={isPLLoading}
            error={plError?.message || null}
            onRefresh={refetchPL}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {profitLossData && (
              <div className="space-y-6">
                {compareToolbar}
                <ComparisonHierarchyTable
                  roots={buildPlRoots(profitLossData, plComparing ? plCompareData : null)}
                  compareEnabled={plComparing}
                  currentLabel={plCurrentLabel}
                  compareLabel={plCompareLabel}
                  formatMoney={formatCurrency}
                />
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* Balance Sheet Tab */}
        <TabsContent value="balance-sheet">
          <ReportViewer
            title="Balance Sheet"
            description={
              bsComparing
                ? `${bsCurrentLabel} compared with ${bsCompareLabel}`
                : `As of ${formatDate(filters.end_date || '')}`
            }
            isLoading={isBSLoading}
            error={bsError?.message || null}
            onRefresh={refetchBS}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {balanceSheetData && (
              <div className="space-y-6">
                {compareToolbar}
                <ComparisonHierarchyTable
                  roots={buildBsRoots(balanceSheetData, bsComparing ? bsCompareData : null)}
                  compareEnabled={bsComparing}
                  currentLabel={bsCurrentLabel}
                  compareLabel={bsCompareLabel}
                  formatMoney={formatCurrency}
                />
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* Cash Flow Tab */}
        <TabsContent value="cash-flow">
          <ReportViewer
            title="Cash Flow Statement"
            description={`For the period ${formatDate(filters.start_date || '')} to ${formatDate(filters.end_date || '')}`}
            isLoading={isCFLoading}
            error={cfError?.message || null}
            onRefresh={refetchCF}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {cashFlowData && (
              <div className="space-y-6">
                {/* Operating Activities */}
                <div>
                  <h3 className="text-lg font-semibold mb-4 text-primary">
                    Operating Activities
                  </h3>
                  <ReportTable
                    columns={cfColumns}
                    data={cashFlowData?.operating_activities?.items ?? []}
                    showPagination={false}
                    showSearch={false}
                  />
                  <div className="flex justify-end mt-2 pr-4">
                    <div className="text-right">
                      <span className="font-semibold">Net Cash from Operations: </span>
                      <span className="text-lg font-bold">
                        {formatCurrency(cashFlowData?.operating_activities?.total ?? 0)}
                      </span>
                    </div>
                  </div>
                </div>

                {/* Investing Activities */}
                <div>
                  <h3 className="text-lg font-semibold mb-4 text-primary">
                    Investing Activities
                  </h3>
                  <ReportTable
                    columns={cfColumns}
                    data={cashFlowData?.investing_activities?.items ?? []}
                    showPagination={false}
                    showSearch={false}
                  />
                  <div className="flex justify-end mt-2 pr-4">
                    <div className="text-right">
                      <span className="font-semibold">Net Cash from Investing: </span>
                      <span className="text-lg font-bold">
                        {formatCurrency(cashFlowData?.investing_activities?.total ?? 0)}
                      </span>
                    </div>
                  </div>
                </div>

                {/* Financing Activities */}
                <div>
                  <h3 className="text-lg font-semibold mb-4 text-primary">
                    Financing Activities
                  </h3>
                  <ReportTable
                    columns={cfColumns}
                    data={cashFlowData?.financing_activities?.items ?? []}
                    showPagination={false}
                    showSearch={false}
                  />
                  <div className="flex justify-end mt-2 pr-4">
                    <div className="text-right">
                      <span className="font-semibold">Net Cash from Financing: </span>
                      <span className="text-lg font-bold">
                        {formatCurrency(cashFlowData?.financing_activities?.total ?? 0)}
                      </span>
                    </div>
                  </div>
                </div>

                {/* Summary Section */}
                <Card className="p-6 bg-gray-50 dark:bg-gray-800">
                  <div className="space-y-3">
                    <div className="flex justify-between items-center">
                      <span className="font-semibold">Opening Cash:</span>
                      <span className="text-lg font-bold">
                        {formatCurrency(cashFlowData?.opening_cash ?? 0)}
                      </span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="font-semibold">Net Cash Flow:</span>
                      <span
                        className={`text-lg font-bold ${
                          (cashFlowData?.net_cash_flow ?? 0) >= 0
                            ? 'text-green-600'
                            : 'text-red-600'
                        }`}
                      >
                        {formatCurrency(cashFlowData?.net_cash_flow ?? 0)}
                      </span>
                    </div>
                    <div className="flex justify-between items-center pt-3 border-t">
                      <span className="text-xl font-bold">Closing Cash:</span>
                      <span className="text-2xl font-bold text-blue-600">
                        {formatCurrency(cashFlowData?.closing_cash ?? 0)}
                      </span>
                    </div>
                  </div>
                </Card>
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* Trial Balance Tab */}
        <TabsContent value="trial-balance">
          <ReportViewer
            title="Trial Balance"
            description={`As of ${formatDate(filters.end_date || '')}`}
            isLoading={isTBLoading}
            error={tbError?.message || null}
            onRefresh={refetchTB}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {trialBalanceData && (
              <div className="space-y-6">
                <ReportTable
                  columns={tbColumns}
                  data={trialBalanceData?.accounts ?? []}
                  showPagination
                  showSearch
                  pageSize={20}
                />

                {/* Totals Section */}
                <Card className="p-6 bg-gray-50 dark:bg-gray-800">
                  <div className="space-y-3">
                    <div className="flex justify-between items-center">
                      <span className="font-semibold">Total Debits:</span>
                      <span className="text-lg font-bold">
                        {formatCurrency(trialBalanceData?.total_debits ?? 0)}
                      </span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="font-semibold">Total Credits:</span>
                      <span className="text-lg font-bold">
                        {formatCurrency(trialBalanceData?.total_credits ?? 0)}
                      </span>
                    </div>
                    <div className="flex justify-between items-center pt-3 border-t">
                      <span className="text-xl font-bold">Balance Status:</span>
                      <span
                        className={`text-xl font-bold ${
                          trialBalanceData?.is_balanced
                            ? 'text-green-600'
                            : 'text-red-600'
                        }`}
                      >
                        {trialBalanceData?.is_balanced ? 'Balanced ✓' : 'Not Balanced ✗'}
                      </span>
                    </div>
                  </div>
                </Card>
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* General Ledger Tab */}
        <TabsContent value="general-ledger">
          <ReportViewer
            title="General Ledger"
            description={`For the period ${formatDate(filters.start_date || '')} to ${formatDate(filters.end_date || '')}`}
            isLoading={isGLLoading}
            error={glError?.message || null}
            onRefresh={refetchGL}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {generalLedgerData && (
              <div className="space-y-6">
                {/* Account Info */}
                {generalLedgerData?.account && (
                  <Card className="p-4 bg-blue-50 dark:bg-blue-900/20">
                    <div className="flex items-center justify-between">
                      <div>
                        <h3 className="font-semibold text-lg">
                          {generalLedgerData.account.name}
                        </h3>
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                          Account Code: {generalLedgerData.account.code}
                        </p>
                      </div>
                      <div className="text-right">
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                          Opening Balance
                        </p>
                        <p className="text-lg font-bold">
                          {formatCurrency(generalLedgerData?.opening_balance ?? 0)}
                        </p>
                      </div>
                    </div>
                  </Card>
                )}

                {/* Transactions */}
                <ReportTable
                  columns={glColumns}
                  data={generalLedgerData?.transactions ?? []}
                  showPagination
                  showSearch
                  pageSize={20}
                />

                {/* Closing Balance */}
                <Card className="p-6 bg-gray-50 dark:bg-gray-800">
                  <div className="flex justify-between items-center">
                    <span className="text-xl font-bold">Closing Balance:</span>
                    <span className="text-2xl font-bold text-blue-600">
                      {formatCurrency(generalLedgerData?.closing_balance ?? 0)}
                    </span>
                  </div>
                </Card>
              </div>
            )}
          </ReportViewer>
        </TabsContent>
      </Tabs>
    </div>
  );
}
