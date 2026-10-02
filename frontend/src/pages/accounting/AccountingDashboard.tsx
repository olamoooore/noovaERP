import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Icon } from '@iconify/react';
import { useNavigate } from 'react-router-dom';
import api from '@/lib/api';
import reportApi from '@/lib/reportApi';
import { cn, formatCurrency } from '@/lib/utils';
import { useSettingsStore } from '@/store/settingsStore';
import { LineChart } from '@/components/charts/LineChart';
import { BarChart } from '@/components/charts/BarChart';
import { PieChart } from '@/components/charts/PieChart';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { StatCard } from '@/components/ui/stat-card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { TaxRateChangeBanner } from '@/components/tax/TaxRateChangeBanner';

interface DashboardMetrics {
  outstanding_receivables: {
    amount: number;
    formatted: string;
  };
  outstanding_payables: {
    amount: number;
    formatted: string;
  };
  bank_balance: {
    amount: number;
    formatted: string;
  };
  unreconciled_items: {
    count: number;
  };
  recent_invoices: Array<{
    id: number;
    invoice_number: string;
    invoice_date: string;
    total_amount: number;
    status: string;
    customer_name: string;
  }>;
  recent_bills: Array<{
    id: number;
    bill_number: string;
    bill_date: string;
    total_amount: number;
    status: string;
    vendor_name: string;
  }>;
}

interface BankAccountSummary {
  id: number;
  name: string;
  account_number: string;
  bank_name: string;
  gl_account_id: number;
  gl_account_name: string | null;
  last_reconciliation_date: string | null;
  last_reconciliation_balance: number | null;
  current_balance?: number;
}

const PERIOD_OPTIONS = [
  { value: 7, label: '7 days' },
  { value: 30, label: '30 days' },
  { value: 365, label: '12 months' },
];

const buildDateFilters = (days: number) => {
  const endDate = new Date();
  const startDate = new Date();
  startDate.setDate(endDate.getDate() - (days - 1));

  return {
    start_date: startDate.toISOString().split('T')[0],
    end_date: endDate.toISOString().split('T')[0],
  };
};

const formatCompactCurrency = (amount: number) => {
  const code = (useSettingsStore.getState().currencyCode || 'USD').toUpperCase();
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: code,
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(amount || 0);
};

const daysSince = (date: string | null) => {
  if (!date) return null;

  const then = new Date(date);
  const now = new Date();
  return Math.floor((now.getTime() - then.getTime()) / (1000 * 60 * 60 * 24));
};

const formatChartLabel = (value: string, periodDays: number) => {
  if (!value) return '';

  if (periodDays === 365) {
    return value;
  }

  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return value;
  }

  return parsed.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
  });
};

const formatStatus = (status: string) =>
  status
    .split('_')
    .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
    .join(' ');

const statusChipClass = (status: string) => {
  switch (status) {
    case 'overdue':
      return 'bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300';
    case 'draft':
      return 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300';
    case 'posted':
    case 'sent':
      return 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300';
    case 'partial':
      return 'bg-violet-100 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300';
    case 'paid':
      return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300';
    default:
      return 'bg-muted text-muted-foreground';
  }
};

const cardShell = 'rounded-xl border-slate-200/80 dark:border-slate-800';

/**
 * Accounting Dashboard
 * The accounting command center: cash position, receivables/payables,
 * performance analytics and the controls that need attention.
 */
export const AccountingDashboard: React.FC = () => {
  const navigate = useNavigate();
  const [periodDays, setPeriodDays] = useState(30);

  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey: ['accounting-dashboard', periodDays],
    queryFn: async () => {
      const filters = buildDateFilters(periodDays);
      const interval = periodDays === 365 ? 'month' : 'day';

      const [metricsResponse, bankAccountsResponse, salesTrendsResponse, expensesResponse, profitLossResponse] =
        await Promise.all([
          api.get<DashboardMetrics>('/accounting/dashboard'),
          reportApi.getBankAccounts().catch(() => ({ data: [] as BankAccountSummary[] })),
          reportApi.getSalesTrends({ ...filters, interval }).catch(() => ({ trends: [] as any[] })),
          reportApi.getExpensesByCategory(filters).catch(() => ({ categories: [] as any[] })),
          reportApi.getProfitLoss(filters).catch(() => null),
        ]);

      return {
        metrics: metricsResponse.data,
        bankAccounts: bankAccountsResponse.data,
        salesTrends: salesTrendsResponse.trends ?? [],
        expensesByCategory: expensesResponse.categories ?? [],
        profitLoss: profitLossResponse,
      };
    },
  });

  const metrics = data?.metrics;
  const bankAccounts = [...(data?.bankAccounts ?? [])].sort(
    (left, right) => (right.current_balance ?? 0) - (left.current_balance ?? 0)
  );
  const overdueInvoices = metrics?.recent_invoices.filter((invoice) => invoice.status === 'overdue') ?? [];
  const overdueBills = metrics?.recent_bills.filter((bill) => bill.status === 'overdue') ?? [];
  const staleBankAccounts = bankAccounts.filter((account) => {
    const age = daysSince(account.last_reconciliation_date);
    return age === null || age > 30;
  });

  const revenueTrendData = (data?.salesTrends ?? []).map((trend: any) => ({
    period: formatChartLabel(String(trend.period || trend.date || ''), periodDays),
    revenue: Number(trend.total_sales ?? trend.total_revenue ?? 0),
  }));

  const expenseMixData = (data?.expensesByCategory ?? []).slice(0, 6).map((item: any) => ({
    category: String(item.account_name || item.category_name || 'Unassigned'),
    amount: Number(item.total_amount || 0),
  }));

  const cashDistributionData = bankAccounts.slice(0, 6).map((account) => ({
    account: account.name,
    balance: Number(account.current_balance ?? 0),
  }));

  const collectionCoverage = metrics?.outstanding_payables.amount
    ? (metrics.outstanding_receivables.amount / metrics.outstanding_payables.amount) * 100
    : 0;

  const pendingActions = [
    overdueInvoices.length > 0
      ? {
          id: 'overdue-invoices',
          title: `${overdueInvoices.length} overdue customer invoice${overdueInvoices.length > 1 ? 's' : ''}`,
          description: 'Prioritize collections or issue follow-up statements.',
          action: 'Review receivables',
          path: '/accounting/customers/bills',
          icon: 'solar:bill-list-bold-duotone',
          tone: 'bg-destructive/10 text-destructive',
        }
      : null,
    overdueBills.length > 0
      ? {
          id: 'overdue-bills',
          title: `${overdueBills.length} overdue vendor bill${overdueBills.length > 1 ? 's' : ''}`,
          description: 'Settle urgent payables before they age further.',
          action: 'Review payables',
          path: '/accounting/vendors/bills',
          icon: 'solar:card-send-bold-duotone',
          tone: 'bg-destructive/10 text-destructive',
        }
      : null,
    (metrics?.unreconciled_items.count ?? 0) > 0
      ? {
          id: 'reconciliation',
          title: `${metrics?.unreconciled_items.count ?? 0} unreconciled ledger item${(metrics?.unreconciled_items.count ?? 0) > 1 ? 's' : ''}`,
          description: 'Close open matches and keep cash positions accurate.',
          action: 'Reconcile now',
          path: '/accounting/core/reconciliation',
          icon: 'solar:check-read-bold-duotone',
          tone: 'bg-sky-100 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400',
        }
      : null,
    staleBankAccounts.length > 0
      ? {
          id: 'stale-banks',
          title: `${staleBankAccounts.length} bank account${staleBankAccounts.length > 1 ? 's' : ''} need reconciliation review`,
          description: 'One or more accounts have never been reconciled or are older than 30 days.',
          action: 'Open cash controls',
          path: '/accounting/core/reconciliation/history',
          icon: 'solar:card-search-bold-duotone',
          tone: 'bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400',
        }
      : null,
  ].filter(Boolean) as Array<{
    id: string;
    title: string;
    description: string;
    action: string;
    path: string;
    icon: string;
    tone: string;
  }>;

  const summaryMessage = metrics
    ? `${formatCompactCurrency(metrics.outstanding_receivables.amount)} to collect, ${formatCompactCurrency(metrics.outstanding_payables.amount)} to pay, and ${formatCompactCurrency(metrics.bank_balance.amount)} across cash accounts. ${pendingActions.length > 0 ? `${pendingActions.length} control item${pendingActions.length > 1 ? 's are' : ' is'} waiting for attention.` : 'Cash and reconciliation controls are up to date.'}`
    : 'Loading accounting health summary...';

  const quickActions = [
    { label: 'New invoice', path: '/accounting/customers/bills/create', icon: 'solar:add-circle-bold-duotone' },
    { label: 'Customer payments', path: '/accounting/customers/payments', icon: 'solar:card-recive-bold-duotone' },
    { label: 'New bill', path: '/accounting/vendors/bills/create', icon: 'solar:add-circle-bold-duotone' },
    { label: 'Vendor payments', path: '/accounting/vendors/payments', icon: 'solar:card-send-bold-duotone' },
    { label: 'Reconcile', path: '/accounting/core/reconciliation', icon: 'solar:check-read-bold-duotone' },
    { label: 'Journal entry', path: '/accounting/core/journal-entries', icon: 'solar:document-text-bold-duotone' },
    { label: 'Trial balance', path: '/accounting/reporting/trial-balance', icon: 'solar:calculator-bold-duotone' },
    { label: 'Cash flow', path: '/accounting/reporting/cash-flow', icon: 'solar:chart-2-bold-duotone' },
  ];

  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center gap-4 py-24">
        <div className="h-10 w-10 animate-spin rounded-full border-b-2 border-primary" />
        <p className="text-sm text-muted-foreground">Loading accounting dashboard…</p>
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-24 text-center">
        <Icon icon="solar:danger-circle-bold-duotone" className="h-12 w-12 text-destructive opacity-80" />
        <p className="text-sm text-destructive">
          {error instanceof Error ? error.message : 'Failed to load the accounting dashboard.'}
        </p>
        <HeaderActionButton variant="outline" icon="solar:refresh-bold" onClick={() => refetch()}>
          Retry
        </HeaderActionButton>
      </div>
    );
  }

  return (
    <div className="space-y-6 pb-10">
      {/* #751 TU5: upcoming pack rate changes + affected open documents. */}
      <TaxRateChangeBanner />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-3xl font-bold tracking-tight">Accounting Dashboard</h1>
          <p className="mt-1 max-w-3xl text-sm text-muted-foreground">{summaryMessage}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="flex h-8 items-center rounded-full bg-muted p-1">
            {PERIOD_OPTIONS.map((option) => (
              <button
                key={option.value}
                type="button"
                onClick={() => setPeriodDays(option.value)}
                className={cn(
                  'h-6 rounded-full px-3 text-xs font-medium transition-all',
                  periodDays === option.value
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground',
                )}
              >
                {option.label}
              </button>
            ))}
          </div>
          <HeaderActionButton
            variant="outline"
            icon="solar:refresh-bold-duotone"
            loading={isFetching}
            onClick={() => refetch()}
          >
            Refresh
          </HeaderActionButton>
        </div>
      </div>

      {/* KPI strip */}
      <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <StatCard
          label="Receivables"
          value={metrics?.outstanding_receivables.formatted ?? formatCurrency(0)}
          hint="Customer invoices awaiting collection"
          icon="solar:wallet-money-bold-duotone"
          tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"
          onClick={() => navigate('/accounting/customers/bills')}
        />
        <StatCard
          label="Payables"
          value={metrics?.outstanding_payables.formatted ?? formatCurrency(0)}
          hint="Open vendor obligations"
          icon="solar:card-send-bold-duotone"
          tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
          onClick={() => navigate('/accounting/vendors/bills')}
        />
        <StatCard
          label="Cash Balance"
          value={metrics?.bank_balance.formatted ?? formatCurrency(0)}
          hint={`${bankAccounts.length} bank account${bankAccounts.length === 1 ? '' : 's'} in scope`}
          icon="solar:card-bold-duotone"
          tone="bg-sky-100 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400"
          onClick={() => navigate('/accounting/core/reconciliation')}
        />
        <StatCard
          label="Unreconciled"
          value={metrics?.unreconciled_items.count ?? 0}
          hint="Items awaiting matching or review"
          icon="solar:shield-check-bold-duotone"
          tone="bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400"
          onClick={() => navigate('/accounting/core/reconciliation')}
        />
      </div>

      {/* Quick actions */}
      <Card className={cardShell}>
        <CardContent className="flex flex-wrap items-center gap-2 p-4">
          <span className="mr-1 text-sm font-medium text-muted-foreground">Quick actions</span>
          {quickActions.map((action) => (
            <HeaderActionButton
              key={action.label}
              variant="outline"
              icon={action.icon}
              onClick={() => navigate(action.path)}
            >
              {action.label}
            </HeaderActionButton>
          ))}
        </CardContent>
      </Card>

      {/* Business performance */}
      <Card className={cardShell}>
        <CardHeader className="flex flex-row items-center justify-between space-y-0">
          <div>
            <CardTitle className="text-lg">Business Performance</CardTitle>
            <CardDescription>Revenue trend, expense composition and cash concentration.</CardDescription>
          </div>
          <span className="inline-flex items-center rounded-full bg-muted px-2.5 py-1 text-[11px] font-semibold text-muted-foreground">
            Last {periodDays === 365 ? '12 months' : `${periodDays} days`}
          </span>
        </CardHeader>
        <CardContent className="grid gap-4 xl:grid-cols-3">
          <div className="rounded-xl border border-slate-200/80 p-4 dark:border-slate-800">
            <div className="mb-4 flex items-start justify-between gap-3">
              <div>
                <h3 className="text-sm font-semibold">Revenue Trend</h3>
                <p className="text-xs text-muted-foreground">Sales activity over the selected window.</p>
              </div>
              <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                {formatCompactCurrency(data?.profitLoss?.revenue?.total ?? 0)}
              </span>
            </div>
            {revenueTrendData.length > 0 ? (
              <LineChart data={revenueTrendData} xKey="period" yKeys={['revenue']} formatYAxis="currency" height={260} />
            ) : (
              <div className="flex h-[260px] items-center justify-center rounded-xl border border-dashed border-slate-300 text-sm text-muted-foreground dark:border-slate-700">
                No sales trend data for this period
              </div>
            )}
          </div>

          <div className="rounded-xl border border-slate-200/80 p-4 dark:border-slate-800">
            <div className="mb-4 flex items-start justify-between gap-3">
              <div>
                <h3 className="text-sm font-semibold">Expense Mix</h3>
                <p className="text-xs text-muted-foreground">Top categories contributing to spend.</p>
              </div>
              <span className="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-semibold text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">
                {formatCompactCurrency(
                  (data?.profitLoss?.operating_expenses?.total ?? 0) +
                    (data?.profitLoss?.other_expenses?.total ?? 0),
                )}
              </span>
            </div>
            {expenseMixData.length > 0 ? (
              <PieChart data={expenseMixData} nameKey="category" valueKey="amount" formatValue="currency" innerRadius={52} height={260} />
            ) : (
              <div className="flex h-[260px] items-center justify-center rounded-xl border border-dashed border-slate-300 text-sm text-muted-foreground dark:border-slate-700">
                No expense breakdown for this period
              </div>
            )}
          </div>

          <div className="rounded-xl border border-slate-200/80 p-4 dark:border-slate-800">
            <div className="mb-4 flex items-start justify-between gap-3">
              <div>
                <h3 className="text-sm font-semibold">Cash Balance Summary</h3>
                <p className="text-xs text-muted-foreground">Current balance by bank account.</p>
              </div>
              <span className="inline-flex items-center rounded-full bg-sky-100 px-2.5 py-1 text-[11px] font-semibold text-sky-700 dark:bg-sky-950/60 dark:text-sky-300">
                {bankAccounts.length} account{bankAccounts.length === 1 ? '' : 's'}
              </span>
            </div>
            {cashDistributionData.length > 0 ? (
              <BarChart data={cashDistributionData} xKey="account" yKeys={['balance']} formatYAxis="currency" height={260} />
            ) : (
              <div className="flex h-[260px] items-center justify-center rounded-xl border border-dashed border-slate-300 text-sm text-muted-foreground dark:border-slate-700">
                No bank accounts to visualise
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Pending actions */}
      <Card className={cardShell}>
        <CardHeader className="flex flex-row items-center justify-between space-y-0">
          <div>
            <CardTitle className="text-lg">Pending Actions</CardTitle>
            <CardDescription>Overdue activity, reconciliation gaps and stale controls.</CardDescription>
          </div>
          {pendingActions.length > 0 ? (
            <span className="inline-flex items-center rounded-full bg-destructive/10 px-2.5 py-1 text-[11px] font-semibold text-destructive">
              {pendingActions.length} open
            </span>
          ) : (
            <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
              All clear
            </span>
          )}
        </CardHeader>
        <CardContent className="space-y-3">
          {pendingActions.length > 0 ? (
            pendingActions.map((item) => (
              <div
                key={item.id}
                className="flex flex-wrap items-center gap-4 rounded-xl border border-slate-200/80 p-4 dark:border-slate-800"
              >
                <div className={cn('flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', item.tone)}>
                  <Icon icon={item.icon} className="h-5 w-5" />
                </div>
                <div className="min-w-0 flex-1">
                  <h3 className="font-medium">{item.title}</h3>
                  <p className="mt-0.5 text-sm text-muted-foreground">{item.description}</p>
                </div>
                <HeaderActionButton variant="outline" onClick={() => navigate(item.path)}>
                  {item.action}
                </HeaderActionButton>
              </div>
            ))
          ) : (
            <div className="flex items-center gap-3 rounded-xl border border-dashed border-emerald-300 bg-emerald-50/60 p-5 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300">
              <Icon icon="solar:shield-check-bold-duotone" className="h-8 w-8 shrink-0" />
              <div>
                <h3 className="font-semibold">No urgent accounting actions</h3>
                <p className="mt-0.5 text-sm">Collections, payables and cash controls are within normal ranges.</p>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Reconciliation + cash accounts */}
      <div className="grid gap-6 xl:grid-cols-2">
        <Card className={cardShell}>
          <CardHeader>
            <CardTitle className="text-lg">AP &amp; AR Reconciliation</CardTitle>
            <CardDescription>Coverage of payables by receivables and cash-control freshness.</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-3 divide-x divide-slate-200 dark:divide-slate-800">
              <div className="pr-4">
                <p className="text-xs text-muted-foreground">Collection coverage</p>
                <div className="mt-1 flex items-baseline gap-2">
                  <span className="text-xl font-bold tabular-nums">{collectionCoverage.toFixed(1)}%</span>
                  <span className={cn('text-xs font-medium', collectionCoverage >= 100 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500')}>
                    {collectionCoverage >= 100 ? 'Covered' : 'Below target'}
                  </span>
                </div>
              </div>
              <div className="px-4">
                <p className="text-xs text-muted-foreground">Net exposure</p>
                <div className="mt-1 text-xl font-bold tabular-nums">
                  {formatCurrency((metrics?.outstanding_receivables.amount ?? 0) - (metrics?.outstanding_payables.amount ?? 0))}
                </div>
              </div>
              <div className="pl-4">
                <p className="text-xs text-muted-foreground">Stale accounts</p>
                <div className="mt-1 flex items-baseline gap-2">
                  <span className="text-xl font-bold tabular-nums">{staleBankAccounts.length}</span>
                  {staleBankAccounts.length > 0 && (
                    <span className="text-xs font-medium text-amber-600 dark:text-amber-400">Needs review</span>
                  )}
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className={cardShell}>
          <CardHeader>
            <CardTitle className="text-lg">Cash Accounts</CardTitle>
            <CardDescription>Balances and last reconciliation checkpoints.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {bankAccounts.length > 0 ? (
              bankAccounts.slice(0, 5).map((account) => {
                const age = daysSince(account.last_reconciliation_date);
                const stale = account.last_reconciliation_date === null || (age !== null && age > 30);
                return (
                  <div
                    key={account.id}
                    className="flex items-center justify-between gap-4 rounded-xl border border-slate-200/80 p-4 dark:border-slate-800"
                  >
                    <div className="min-w-0">
                      <div className="font-medium">{account.name}</div>
                      <div className="truncate text-sm text-muted-foreground">
                        {account.bank_name} · {account.account_number}
                      </div>
                    </div>
                    <div className="shrink-0 text-right">
                      <div className="font-semibold tabular-nums">{formatCurrency(account.current_balance ?? 0)}</div>
                      <span
                        className={cn(
                          'mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium',
                          stale
                            ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'
                            : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
                        )}
                      >
                        {account.last_reconciliation_date
                          ? age === 0 ? 'Reconciled today' : `Last reconciled ${age}d ago`
                          : 'Never reconciled'}
                      </span>
                    </div>
                  </div>
                );
              })
            ) : (
              <div className="rounded-xl border border-dashed border-slate-300 py-10 text-center text-sm text-muted-foreground dark:border-slate-700">
                No bank accounts available yet
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Recent documents */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {[
          {
            title: 'Recent Invoices',
            description: 'Latest customer invoices issued.',
            listPath: '/accounting/customers/bills',
            detailPath: (id: number) => `/accounting/customers/bills/${id}`,
            rows: (metrics?.recent_invoices ?? []).map((invoice) => ({
              id: invoice.id,
              number: invoice.invoice_number,
              party: invoice.customer_name,
              amount: invoice.total_amount,
              date: invoice.invoice_date,
              status: invoice.status,
            })),
            icon: 'solar:document-text-bold-duotone',
          },
          {
            title: 'Recent Bills',
            description: 'Latest vendor bills received.',
            listPath: '/accounting/vendors/bills',
            detailPath: (id: number) => `/accounting/vendors/bills/${id}`,
            rows: (metrics?.recent_bills ?? []).map((bill) => ({
              id: bill.id,
              number: bill.bill_number,
              party: bill.vendor_name,
              amount: bill.total_amount,
              date: bill.bill_date,
              status: bill.status,
            })),
            icon: 'solar:bill-list-bold-duotone',
          },
        ].map((panel) => (
          <Card key={panel.title} className={cardShell}>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
              <div>
                <CardTitle className="text-lg">{panel.title}</CardTitle>
                <CardDescription>{panel.description}</CardDescription>
              </div>
              <HeaderActionButton
                variant="ghost"
                icon="solar:alt-arrow-right-bold"
                onClick={() => navigate(panel.listPath)}
              >
                View all
              </HeaderActionButton>
            </CardHeader>
            <CardContent>
              {panel.rows.length > 0 ? (
                <div className="overflow-hidden rounded-xl border border-slate-200/80 dark:border-slate-800">
                  <Table>
                    <TableHeader>
                      <TableRow className="bg-muted/40 hover:bg-muted/40">
                        <TableHead>Document</TableHead>
                        <TableHead>Party</TableHead>
                        <TableHead className="text-right">Amount</TableHead>
                        <TableHead className="text-right">Date</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {panel.rows.map((row) => (
                        <TableRow
                          key={row.id}
                          className="cursor-pointer"
                          onClick={() => navigate(panel.detailPath(row.id))}
                        >
                          <TableCell>
                            <div className="flex items-center gap-2">
                              <span className="font-medium text-primary">{row.number}</span>
                              <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold', statusChipClass(row.status))}>
                                {formatStatus(row.status)}
                              </span>
                            </div>
                          </TableCell>
                          <TableCell className="max-w-[160px] truncate text-muted-foreground">
                            {row.party || 'Unknown'}
                          </TableCell>
                          <TableCell className="text-right font-medium tabular-nums">{formatCurrency(row.amount)}</TableCell>
                          <TableCell className="text-right text-muted-foreground tabular-nums">
                            {new Date(row.date).toLocaleDateString()}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              ) : (
                <div className="rounded-xl border border-dashed border-slate-300 py-12 text-center text-muted-foreground dark:border-slate-700">
                  <Icon icon={panel.icon} className="mx-auto mb-2 h-10 w-10 opacity-40" />
                  <p className="text-sm">Nothing here yet</p>
                </div>
              )}
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
};

export default AccountingDashboard;