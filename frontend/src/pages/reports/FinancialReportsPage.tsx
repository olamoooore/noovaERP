import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ReportViewer } from '@/components/reports/ReportViewer';
import { ReportTable, Column } from '@/components/reports/ReportTable';
import { ComparisonHierarchyTable, type ReportTreeNode } from '@/components/reports/ComparisonHierarchyTable';
import { useReportAutoRefresh } from '@/hooks/useReportAutoRefresh';
import { collectExpandableIds, ledgerCollapseKeys } from '@/lib/reportTree';
import api from '@/lib/api';
import reportApi from '@/lib/reportApi';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { ReportFilters as ReportFiltersType } from '@/types/reports';
import type { GeneralLedgerData } from '@/types/reports';
import {
  type FiscalYearOption,
  type FiscalYearSettings,
  fiscalYearOptions,
  formatFiscalYearOption,
} from '@/lib/fiscalYear';

// ─── Comparison period math ─────────────────────────────────────────
// Yearly/Quarterly/Monthly shift the selected window back by 12/3/1
// months (month-end clamped, e.g. 2026-03-31 -> 2026-02-28). Custom
// supplies explicit dates. Fiscal-year comparisons fall out naturally:
// select the fiscal window (1 Sep – 31 Aug) and pick "yearly".

type CompareMode = 'none' | 'yearly' | 'quarterly' | 'monthly' | 'custom';

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

type LedgerHierarchy = NonNullable<GeneralLedgerData['hierarchy']>;

function GeneralLedgerHierarchy({
  hierarchy,
  collapsed: controlledCollapsed,
  onCollapsedChange,
}: {
  hierarchy: LedgerHierarchy;
  collapsed?: Set<string>;
  onCollapsedChange?: (next: Set<string>) => void;
}) {
  const navigate = useNavigate();
  const [internalCollapsed, setInternalCollapsed] = useState<Set<string>>(new Set());
  const collapsed = controlledCollapsed ?? internalCollapsed;
  const setCollapsed = onCollapsedChange ?? setInternalCollapsed;
  const toggle = (key: string) => {
    const next = new Set(collapsed);
    if (next.has(key)) next.delete(key); else next.add(key);
    setCollapsed(next);
  };
  const naturalBalance = (type: string, debit: number, credit: number) => ['asset', 'expense'].includes(type) ? debit - credit : credit - debit;
  const row = (key: string, label: React.ReactNode, debit: number, credit: number, balance: number, depth: number, expandable: boolean, className = '') => (
    <tr key={key} className={`border-b ${className}`}>
      <td className="py-2 pr-3" style={{ paddingLeft: `${12 + depth * 24}px` }}>
        <div className="flex items-center gap-2">
          {expandable ? <button type="button" onClick={() => toggle(key)} className="rounded p-0.5 hover:bg-muted" aria-label={`${collapsed.has(key) ? 'Expand' : 'Collapse'} ledger group`}><Icon icon={collapsed.has(key) ? 'solar:alt-arrow-right-linear' : 'solar:alt-arrow-down-linear'} className="h-4 w-4" /></button> : <span className="w-5" />}
          {label}
        </div>
      </td>
      <td className="px-3 py-2 text-right font-mono">{debit ? formatCurrency(debit) : '—'}</td>
      <td className="px-3 py-2 text-right font-mono">{credit ? formatCurrency(credit) : '—'}</td>
      <td className="px-3 py-2 text-right font-mono font-medium">{formatCurrency(balance)}</td>
    </tr>
  );

  return <div className="overflow-x-auto rounded-md border">
    <table className="w-full text-sm">
      <thead><tr className="border-b bg-muted/50"><th className="px-3 py-2 text-left"></th><th className="px-3 py-2 text-right">Debit</th><th className="px-3 py-2 text-right">Credit</th><th className="px-3 py-2 text-right">Balance</th></tr></thead>
      <tbody>{hierarchy.types.flatMap((type) => {
        const typeKey = `type:${type.type}`;
        const rows: React.ReactNode[] = [row(typeKey, <span className="font-bold uppercase">{type.type}</span>, type.total_debit, type.total_credit, naturalBalance(type.type, type.total_debit, type.total_credit), 0, type.groups.length > 0, 'bg-purple-50/70')];
        if (collapsed.has(typeKey)) return rows;
        type.groups.forEach((group) => {
          const groupKey = `${typeKey}:group:${group.code || group.name}`;
          rows.push(row(groupKey, <>{group.code && <span className="font-mono text-muted-foreground">{group.code}</span>}<span className="font-semibold">{group.name}</span></>, group.total_debit, group.total_credit, naturalBalance(type.type, group.total_debit, group.total_credit), 1, group.accounts.length > 0, 'bg-blue-50/60'));
          if (collapsed.has(groupKey)) return;
          group.accounts.forEach((account) => {
            const accountKey = `${groupKey}:account:${account.id}`;
            rows.push(row(accountKey, <><span className="font-mono">{account.code}</span><span>{account.name}</span></>, account.total_debit, account.total_credit, naturalBalance(type.type, account.total_debit, account.total_credit), 2, account.fiscal_years.length > 0, 'font-medium'));
            if (collapsed.has(accountKey)) return;
            account.fiscal_years.forEach((year) => {
              const yearKey = `${accountKey}:fy:${year.year}`;
              rows.push(row(yearKey, <span className="font-semibold">Fiscal Year {year.year}</span>, year.total_debit, year.total_credit, naturalBalance(type.type, year.total_debit, year.total_credit), 3, year.months.length > 0, 'bg-muted/20'));
              if (collapsed.has(yearKey)) return;
              year.months.forEach((month) => {
                const monthKey = `${yearKey}:month:${month.key}`;
                rows.push(row(monthKey, <span>{month.label}</span>, month.total_debit, month.total_credit, naturalBalance(type.type, month.total_debit, month.total_credit), 4, month.transactions.length > 0));
                if (collapsed.has(monthKey)) return;
                month.transactions.forEach((transaction, index) => {
                  const transactionKey = `${monthKey}:tx:${transaction.journal_entry_id}:${index}`;
                  rows.push(row(transactionKey, <button type="button" className="grid min-w-0 w-full grid-cols-[6rem_9rem_1fr] gap-3 text-left text-xs text-primary hover:underline" onClick={() => navigate(`/accounting/core/journal-entries?entry_id=${transaction.journal_entry_id}`)}><span>{formatDate(transaction.date)}</span><span className="font-mono">{transaction.reference || '—'}</span><span className="truncate">{transaction.description || '—'}</span></button>, transaction.debit, transaction.credit, transaction.balance, 5, false));
                });
              });
            });
          });
        });
        return rows;
      })}</tbody>
    </table>
  </div>;
}

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
  // Expand/Collapse state lives on the page so the controls can sit in the
  // report toolbar row (next to Excel/PDF/Word and Refresh).
  const [plCollapsed, setPlCollapsed] = useState<Set<string>>(new Set());
  const [bsCollapsed, setBsCollapsed] = useState<Set<string>>(new Set());
  const [glCollapsed, setGlCollapsed] = useState<Set<string>>(new Set());
  const plRef = useRef<HTMLDivElement>(null);
  const bsRef = useRef<HTMLDivElement>(null);
  const cfRef = useRef<HTMLDivElement>(null);
  const tbRef = useRef<HTMLDivElement>(null);
  const glRef = useRef<HTMLDivElement>(null);
  const queryClient = useQueryClient();
  useReportAutoRefresh(); // auto-refresh reports after any tracked mutation
  const [filters, setFilters] = useState<ReportFiltersType>({
    start_date: new Date(new Date().getFullYear(), new Date().getMonth(), 1)
      .toISOString()
      .split('T')[0],
    end_date: new Date().toISOString().split('T')[0],
  });
  const userPickedPeriod = useRef(false);

  // Reporting periods follow the tenant fiscal-year setting (same as the
  // standalone P&L / Balance Sheet / Trial Balance pages).
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
    if (userPickedPeriod.current || fyOptions.length === 0) return;
    const current = fyOptions[0];
    setFilters((prev) =>
      prev.start_date === current.start && prev.end_date === current.end
        ? prev
        : { ...prev, start_date: current.start, end_date: current.end },
    );
  }, [fyOptions]);

  const selectedFy = fyOptions.find(
    (option) => option.start === filters.start_date && option.end === filters.end_date,
  );

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

  const plRoots = useMemo(
    () => (profitLossData ? buildPlRoots(profitLossData, plComparing ? plCompareData : null) : []),
    [profitLossData, plCompareData, plComparing],
  );
  const bsRoots = useMemo(
    () => (balanceSheetData ? buildBsRoots(balanceSheetData, bsComparing ? bsCompareData : null) : []),
    [balanceSheetData, bsCompareData, bsComparing],
  );
  const plExpandableIds = useMemo(() => collectExpandableIds(plRoots), [plRoots]);
  const bsExpandableIds = useMemo(() => collectExpandableIds(bsRoots), [bsRoots]);
  const isCompareFetching = activeTab === 'profit-loss' ? isPlCmpFetching : isBsCmpFetching;
  // Only P&L and the Balance Sheet support a comparison window here.
  const comparisonSupported = activeTab === 'profit-loss' || activeTab === 'balance-sheet';

  const plCurrentLabel = `${formatDate(filters.start_date || '')} – ${formatDate(filters.end_date || '')}`;
  const plCompareLabel = plCompareFilters
    ? `${formatDate(plCompareFilters.start_date || '')} – ${formatDate(plCompareFilters.end_date || '')}`
    : undefined;
  const bsCurrentLabel = `As of ${formatDate(filters.end_date || '')}`;
  const bsCompareLabel = bsCompareAsOf ? `As of ${formatDate(bsCompareAsOf)}` : undefined;


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

  const glCollapseKeyList = useMemo(
    () => (generalLedgerData?.hierarchy ? ledgerCollapseKeys(generalLedgerData.hierarchy) : []),
    [generalLedgerData],
  );

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

      {/* Filters — reporting period and comparison in one toolbar */}
      <Card className="rounded-xl p-4">
        <div className="flex flex-wrap items-end gap-3">
          {fyOptions.length > 0 && (
            <div className="space-y-1.5">
              <Label className="text-xs font-medium text-muted-foreground">Fiscal Year</Label>
              <Select
                value={selectedFy?.label ?? 'custom'}
                onValueChange={(value) => {
                  const fy = fyOptions.find((option) => option.label === value);
                  if (!fy) return;
                  userPickedPeriod.current = true;
                  setFilters((prev) => ({ ...prev, start_date: fy.start, end_date: fy.end }));
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
                  <SelectItem value="custom">Custom range</SelectItem>
                </SelectContent>
              </Select>
            </div>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="report-from" className="text-xs font-medium text-muted-foreground">
              From
            </Label>
            <Input
              id="report-from"
              type="date"
              value={filters.start_date || ''}
              onChange={(e) => {
                userPickedPeriod.current = true;
                setFilters((prev) => ({ ...prev, start_date: e.target.value }));
              }}
              className="h-9 w-40 rounded-full text-sm"
            />
          </div>
          <span className="pb-2 text-muted-foreground">→</span>
          <div className="space-y-1.5">
            <Label htmlFor="report-to" className="text-xs font-medium text-muted-foreground">
              To
            </Label>
            <Input
              id="report-to"
              type="date"
              value={filters.end_date || ''}
              onChange={(e) => {
                userPickedPeriod.current = true;
                setFilters((prev) => ({ ...prev, end_date: e.target.value }));
              }}
              className="h-9 w-40 rounded-full text-sm"
            />
          </div>

          {comparisonSupported && (
            <>
              <div className="mx-1 hidden h-9 w-px self-end bg-border sm:block" />

              <div className="space-y-1.5">
                <Label className="text-xs font-medium text-muted-foreground">Compare</Label>
                <Select value={compareMode} onValueChange={(value) => setCompareMode(value as CompareMode)}>
                  <SelectTrigger className="h-9 w-48 rounded-full text-sm" aria-label="Comparison period">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">No comparison</SelectItem>
                    <SelectItem value="yearly">Previous year</SelectItem>
                    <SelectItem value="quarterly">Previous quarter</SelectItem>
                    <SelectItem value="monthly">Previous month</SelectItem>
                    <SelectItem value="custom">Custom range</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              {compareMode === 'custom' && (
                <>
                  <div className="space-y-1.5">
                    <Label htmlFor="compare-from" className="text-xs font-medium text-muted-foreground">
                      Compare from
                    </Label>
                    <Input
                      id="compare-from"
                      type="date"
                      value={customCmp.start_date}
                      onChange={(e) => setCustomCmp((prev) => ({ ...prev, start_date: e.target.value }))}
                      className="h-9 w-40 rounded-full text-sm"
                    />
                  </div>
                  <span className="pb-2 text-muted-foreground">→</span>
                  <div className="space-y-1.5">
                    <Label htmlFor="compare-to" className="text-xs font-medium text-muted-foreground">
                      Compare to
                    </Label>
                    <Input
                      id="compare-to"
                      type="date"
                      value={customCmp.end_date}
                      onChange={(e) => setCustomCmp((prev) => ({ ...prev, end_date: e.target.value }))}
                      className="h-9 w-40 rounded-full text-sm"
                    />
                  </div>
                </>
              )}
            </>
          )}

          <div className="ml-auto flex items-center gap-3 pb-1.5">
            {comparisonSupported && compareMode === 'custom' && (!customCmp.start_date || !customCmp.end_date) && (
              <span className="text-xs text-amber-600">Select a complete custom range</span>
            )}
            {comparisonSupported && compareMode !== 'none' && compareMode !== 'custom' && (
              <span className="text-xs text-muted-foreground">
                {isCompareFetching
                  ? 'Loading comparison…'
                  : activeTab === 'balance-sheet'
                    ? (bsCompareLabel ?? 'Compares against the same period one step back')
                    : (plCompareLabel ? `vs ${plCompareLabel}` : 'Compares against the prior period')}
              </span>
            )}
            <HeaderActionButton
              variant="outline"
              icon="solar:refresh-linear"
              className="w-9 px-0"
              aria-label="Refresh reports"
              onClick={() => {
                queryClient.invalidateQueries({ queryKey: ['profit-loss'] });
                queryClient.invalidateQueries({ queryKey: ['balance-sheet'] });
                queryClient.invalidateQueries({ queryKey: ['cash-flow'] });
                queryClient.invalidateQueries({ queryKey: ['trial-balance'] });
                queryClient.invalidateQueries({ queryKey: ['general-ledger'] });
              }}
            />
          </div>
        </div>

        {comparisonSupported && compareMode === 'custom' && (
          <p className="mt-3 text-xs text-muted-foreground">
            Profit &amp; Loss compares this custom window; Balance Sheet compares as of its end date.
          </p>
        )}
      </Card>

      {/* Reports Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="w-fit max-w-full">
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
            exportTargetRef={plRef}
            exportFilename={`profit-and-loss-${filters.start_date || ''}-to-${filters.end_date || ''}`}
            actions={plExpandableIds.length > 0 ? (
              <>
                <HeaderActionButton
                  variant="outline"
                  icon="solar:maximize-linear"
                  onClick={() => setPlCollapsed(new Set())}
                >
                  Expand all
                </HeaderActionButton>
                <HeaderActionButton
                  variant="outline"
                  icon="solar:minimize-linear"
                  onClick={() => setPlCollapsed(new Set(plExpandableIds))}
                >
                  Collapse all
                </HeaderActionButton>
              </>
            ) : undefined}
          >
            {profitLossData && (
              <div ref={plRef} className="space-y-6">
                <ComparisonHierarchyTable
                  roots={plRoots}
                  compareEnabled={plComparing}
                  currentLabel={plCurrentLabel}
                  compareLabel={plCompareLabel}
                  formatMoney={formatCurrency}
                  collapsed={plCollapsed}
                  onCollapsedChange={setPlCollapsed}
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
            exportTargetRef={bsRef}
            exportFilename={`balance-sheet-${filters.end_date || ''}`}
            actions={bsExpandableIds.length > 0 ? (
              <>
                <HeaderActionButton
                  variant="outline"
                  icon="solar:maximize-linear"
                  onClick={() => setBsCollapsed(new Set())}
                >
                  Expand all
                </HeaderActionButton>
                <HeaderActionButton
                  variant="outline"
                  icon="solar:minimize-linear"
                  onClick={() => setBsCollapsed(new Set(bsExpandableIds))}
                >
                  Collapse all
                </HeaderActionButton>
              </>
            ) : undefined}
          >
            {balanceSheetData && (
              <div ref={bsRef} className="space-y-6">
                <ComparisonHierarchyTable
                  roots={bsRoots}
                  compareEnabled={bsComparing}
                  currentLabel={bsCurrentLabel}
                  compareLabel={bsCompareLabel}
                  formatMoney={formatCurrency}
                  collapsed={bsCollapsed}
                  onCollapsedChange={setBsCollapsed}
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
            exportTargetRef={cfRef}
            exportFilename={`cash-flow-${filters.start_date || ''}-to-${filters.end_date || ''}`}
          >
            {cashFlowData && (
              <div ref={cfRef} className="space-y-6">
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
            exportTargetRef={tbRef}
            exportFilename={`trial-balance-${filters.end_date || ''}`}
          >
            {trialBalanceData && (
              <div ref={tbRef} className="space-y-6">
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
            exportTargetRef={glRef}
            exportFilename={`general-ledger-${filters.start_date || ''}-to-${filters.end_date || ''}`}
            actions={glCollapseKeyList.length > 0 ? (
              <>
                <HeaderActionButton
                  variant="outline"
                  icon="solar:maximize-linear"
                  onClick={() => setGlCollapsed(new Set())}
                >
                  Expand all
                </HeaderActionButton>
                <HeaderActionButton
                  variant="outline"
                  icon="solar:minimize-linear"
                  onClick={() => setGlCollapsed(new Set(glCollapseKeyList))}
                >
                  Collapse all
                </HeaderActionButton>
              </>
            ) : undefined}
          >
            {generalLedgerData && (
              <div ref={glRef} className="space-y-6">
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

                {/* Account hierarchy for the all-accounts ledger; retain the transaction table for a single-account drill-down. */}
                {generalLedgerData.hierarchy ? (
                  generalLedgerData.hierarchy.types.length > 0 ? (
                    <GeneralLedgerHierarchy
                      hierarchy={generalLedgerData.hierarchy}
                      collapsed={glCollapsed}
                      onCollapsedChange={setGlCollapsed}
                    />
                  ) : (
                    <Card className="p-8 text-center text-muted-foreground">No posted ledger transactions found for this period.</Card>
                  )
                ) : (
                  <ReportTable
                    columns={glColumns}
                    data={generalLedgerData?.transactions ?? []}
                    showPagination
                    showSearch
                    pageSize={20}
                  />
                )}

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
