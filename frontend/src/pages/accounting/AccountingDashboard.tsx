import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Icon } from '@iconify/react';
import { useNavigate } from 'react-router-dom';
import api from '@/lib/api';
import reportApi from '@/lib/reportApi';
import { LineChart } from '@/components/charts/LineChart';
import { BarChart } from '@/components/charts/BarChart';
import { PieChart } from '@/components/charts/PieChart';

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
  { value: 7, label: 'Last 7 days' },
  { value: 30, label: 'Last 30 days' },
  { value: 365, label: 'Last 365 days' },
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

const formatCurrency = (amount: number) =>
  new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount || 0);

const formatCompactCurrency = (amount: number) =>
  new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(amount || 0);

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

const getStatusClasses = (status: string) => {
  switch (status) {
    case 'overdue':
      return 'bg-red-100 text-red-700 border-red-200';
    case 'draft':
      return 'bg-amber-100 text-amber-700 border-amber-200';
    case 'posted':
    case 'sent':
      return 'bg-blue-100 text-blue-700 border-blue-200';
    case 'paid':
      return 'bg-green-100 text-green-700 border-green-200';
    default:
      return 'bg-slate-100 text-slate-700 border-slate-200';
  }
};

/**
 * Accounting Dashboard Page
 * Enterprise-style accounting command center for operations, controls, and performance.
 */
export const AccountingDashboard: React.FC = () => {
  const navigate = useNavigate();
  const [periodDays, setPeriodDays] = useState(30);

  const { data, isLoading, error, refetch } = useQuery({
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
  const activeBankAccounts = bankAccounts.filter((account) => (account.current_balance ?? 0) > 0).length;

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

  const totalExpenseExposure =
    (metrics?.outstanding_payables.amount ?? 0) +
    (data?.profitLoss?.operating_expenses?.total ?? 0) +
    (data?.profitLoss?.other_expenses?.total ?? 0);
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
        }
      : null,
  ].filter(Boolean) as Array<{
    id: string;
    title: string;
    description: string;
    action: string;
    path: string;
    icon: string;
  }>;

  const summaryMessage = metrics
    ? `${formatCompactCurrency(metrics.outstanding_receivables.amount)} to collect, ${formatCompactCurrency(metrics.outstanding_payables.amount)} to pay, and ${formatCompactCurrency(metrics.bank_balance.amount)} across cash accounts. ${pendingActions.length > 0 ? `${pendingActions.length} control item${pendingActions.length > 1 ? 's are' : ' is'} waiting for attention.` : 'Cash and reconciliation controls are up to date.'}`
    : 'Loading accounting health summary...';

  const summaryCards = [
    {
      title: 'Receivables',
      value: metrics?.outstanding_receivables.formatted ?? '$0.00',
      caption: 'Customer invoices waiting for collection.',
      icon: 'solar:wallet-money-bold-duotone',
      tint: 'from-emerald-500/15 via-emerald-500/5 to-transparent',
      accent: 'text-emerald-700',
      actions: [
        { label: 'New invoice', path: '/accounting/customers/bills/create', icon: 'solar:add-circle-bold-duotone' },
        { label: 'Customer payments', path: '/accounting/customers/payments', icon: 'solar:card-recive-bold-duotone' },
        { label: 'Statements', path: '/accounting/customers/statements', icon: 'solar:document-text-bold-duotone' },
      ],
    },
    {
      title: 'Payables',
      value: metrics?.outstanding_payables.formatted ?? '$0.00',
      caption: 'Open vendor obligations and bill settlements.',
      icon: 'solar:card-send-bold-duotone',
      tint: 'from-amber-500/15 via-amber-500/5 to-transparent',
      accent: 'text-amber-700',
      actions: [
        { label: 'New bill', path: '/accounting/vendors/bills/create', icon: 'solar:add-circle-bold-duotone' },
        { label: 'Vendor payments', path: '/accounting/vendors/payments', icon: 'solar:card-send-bold-duotone' },
        { label: 'Review bills', path: '/accounting/vendors/bills', icon: 'solar:bill-list-bold-duotone' },
      ],
    },
    {
      title: 'Cash Balance',
      value: metrics?.bank_balance.formatted ?? '$0.00',
      caption: `${bankAccounts.length} bank account${bankAccounts.length === 1 ? '' : 's'} in scope.`,
      icon: 'solar:card-bold-duotone',
      tint: 'from-sky-500/15 via-sky-500/5 to-transparent',
      accent: 'text-sky-700',
      actions: [
        { label: 'Reconcile cash', path: '/accounting/core/reconciliation', icon: 'solar:check-read-bold-duotone' },
        { label: 'History', path: '/accounting/core/reconciliation/history', icon: 'solar:history-bold-duotone' },
        { label: 'Cash flow', path: '/accounting/reporting/cash-flow', icon: 'solar:chart-bold-duotone' },
      ],
    },
    {
      title: 'Controls',
      value: String(metrics?.unreconciled_items.count ?? 0),
      caption: 'Items still waiting for matching or review.',
      icon: 'solar:shield-check-bold-duotone',
      tint: 'from-violet-500/15 via-violet-500/5 to-transparent',
      accent: 'text-violet-700',
      actions: [
        { label: 'Journal entry', path: '/accounting/core/journal-entries', icon: 'solar:document-text-bold-duotone' },
        { label: 'Trial balance', path: '/accounting/reporting/trial-balance', icon: 'solar:calculator-bold-duotone' },
        { label: 'COA', path: '/accounting/core/chart-of-accounts', icon: 'solar:book-bookmark-bold-duotone' },
      ],
    },
  ];

  const transactionModules = [
    {
      title: 'Accounts Receivable',
      icon: 'solar:bill-list-bold-duotone',
      value: metrics?.outstanding_receivables.amount ?? 0,
      summary: `${metrics?.recent_invoices.length ?? 0} recent invoices, ${overdueInvoices.length} overdue.`,
      secondary: 'Track customer collections and statement follow-ups.',
      actions: [
        { label: 'Invoice list', path: '/accounting/customers/bills' },
        { label: 'Create invoice', path: '/accounting/customers/bills/create' },
        { label: 'Follow-up reports', path: '/accounting/customers/followup-reports' },
      ],
    },
    {
      title: 'Accounts Payable',
      icon: 'solar:card-send-bold-duotone',
      value: metrics?.outstanding_payables.amount ?? 0,
      summary: `${metrics?.recent_bills.length ?? 0} recent bills, ${overdueBills.length} overdue.`,
      secondary: 'Keep vendor commitments visible and ready for payment runs.',
      actions: [
        { label: 'Bill list', path: '/accounting/vendors/bills' },
        { label: 'Create bill', path: '/accounting/vendors/bills/create' },
        { label: 'Vendor payments', path: '/accounting/vendors/payments' },
      ],
    },
    {
      title: 'Cash & Bank',
      icon: 'solar:card-bold-duotone',
      value: metrics?.bank_balance.amount ?? 0,
      summary: `${activeBankAccounts} active cash account${activeBankAccounts === 1 ? '' : 's'} with positive balance.`,
      secondary: 'Monitor live balances and last reconciliation coverage.',
      actions: [
        { label: 'Reconcile', path: '/accounting/core/reconciliation' },
        { label: 'History', path: '/accounting/core/reconciliation/history' },
        { label: 'Cash flow', path: '/accounting/reporting/cash-flow' },
      ],
    },
    {
      title: 'Close & Control',
      icon: 'solar:shield-warning-bold-duotone',
      value: metrics?.unreconciled_items.count ?? 0,
      summary: `${staleBankAccounts.length} bank account${staleBankAccounts.length === 1 ? '' : 's'} need control review.`,
      secondary: 'Post journals, monitor lock dates, and keep books aligned.',
      actions: [
        { label: 'Journal entries', path: '/accounting/core/journal-entries' },
        { label: 'Lock dates', path: '/accounting/core/lock-dates' },
        { label: 'Entities', path: '/accounting/core/entities' },
      ],
    },
  ];

  if (isLoading) {
    return (
      <div className="p-6 space-y-6">
        <div className="text-center py-16">
          <Icon icon="svg-spinners:ring-resize" className="h-12 w-12 mx-auto text-primary" />
          <p className="mt-4 text-muted-foreground">Loading accounting command center...</p>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="p-6 space-y-6">
        <div className="text-center py-12">
          <Icon icon="solar:danger-circle-bold-duotone" className="h-12 w-12 mx-auto text-destructive" />
          <p className="mt-4 text-destructive">Error: {error instanceof Error ? error.message : 'Failed to load dashboard'}</p>
          <Button onClick={() => refetch()} className="mt-4">
            Retry
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-full bg-[radial-gradient(circle_at_top_left,_rgba(15,118,110,0.08),_transparent_35%),radial-gradient(circle_at_top_right,_rgba(59,130,246,0.08),_transparent_30%)] p-6 space-y-6">
      <div className="rounded-3xl border border-slate-200/80 bg-white/85 backdrop-blur-sm shadow-sm overflow-hidden">
        <div className="border-b border-slate-200/80 px-6 py-5">
          <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div className="space-y-2">
              <div className="flex items-center gap-3">
                <Badge className="bg-slate-900 text-white hover:bg-slate-900">Accounting</Badge>
                <Badge variant="outline">Dashboard overview</Badge>
              </div>
              <div>
                <h1 className="text-3xl font-bold tracking-tight">Accounting Command Center</h1>
                <p className="text-muted-foreground mt-2 max-w-3xl">{summaryMessage}</p>
              </div>
            </div>

            <div className="flex flex-wrap gap-2">
              {PERIOD_OPTIONS.map((option) => (
                <Button
                  key={option.value}
                  variant={periodDays === option.value ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => setPeriodDays(option.value)}
                >
                  {option.label}
                </Button>
              ))}
              <Button variant="outline" size="sm" onClick={() => refetch()}>
                <Icon icon="solar:refresh-bold-duotone" className="mr-2 h-4 w-4" />
                Refresh
              </Button>
            </div>
          </div>
        </div>

        <div className="px-6 py-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          {summaryCards.map((card) => (
            <Card key={card.title} className="border-slate-200 shadow-none overflow-hidden">
              <CardContent className={`p-5 bg-gradient-to-br ${card.tint}`}>
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="text-sm font-medium text-slate-600">{card.title}</p>
                    <div className={`mt-3 text-3xl font-bold ${card.accent}`}>{card.value}</div>
                    <p className="mt-2 text-sm text-slate-600">{card.caption}</p>
                  </div>
                  <div className="rounded-2xl bg-white/80 p-3 shadow-sm">
                    <Icon icon={card.icon} className={`h-6 w-6 ${card.accent}`} />
                  </div>
                </div>

                <div className="mt-5 flex flex-wrap gap-2">
                  {card.actions.map((action) => (
                    <Button
                      key={action.label}
                      variant="outline"
                      size="sm"
                      className="bg-white/70"
                      onClick={() => navigate(action.path)}
                    >
                      <Icon icon={action.icon} className="mr-2 h-4 w-4" />
                      {action.label}
                    </Button>
                  ))}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      </div>

      <div className="grid gap-6 xl:grid-cols-[1.6fr_1fr]">
        <Card className="border-slate-200/80 shadow-sm">
          <CardHeader className="flex flex-row items-center justify-between gap-4">
            <div>
              <CardTitle className="text-xl">Transaction Summary</CardTitle>
              <p className="text-sm text-muted-foreground mt-1">
                Direct access to each accounting stream with actions attached to the summary itself.
              </p>
            </div>
            <Badge variant="outline">Operational view</Badge>
          </CardHeader>
          <CardContent className="grid gap-4 md:grid-cols-2">
            {transactionModules.map((module) => (
              <div key={module.title} className="rounded-2xl border border-slate-200 p-4 bg-slate-50/70">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <div className="flex items-center gap-2">
                      <Icon icon={module.icon} className="h-5 w-5 text-slate-700" />
                      <h3 className="font-semibold text-slate-900">{module.title}</h3>
                    </div>
                    <div className="mt-3 text-2xl font-bold">{formatCompactCurrency(module.value)}</div>
                    <p className="mt-2 text-sm text-slate-600">{module.summary}</p>
                    <p className="mt-1 text-xs text-slate-500">{module.secondary}</p>
                  </div>
                  <Button variant="ghost" size="sm" onClick={() => navigate(module.actions[0].path)}>
                    <Icon icon="solar:arrow-right-up-bold-duotone" className="h-4 w-4" />
                  </Button>
                </div>
                <div className="mt-4 flex flex-wrap gap-2">
                  {module.actions.map((action) => (
                    <Button key={action.label} variant="outline" size="sm" onClick={() => navigate(action.path)}>
                      {action.label}
                    </Button>
                  ))}
                </div>
              </div>
            ))}
          </CardContent>
        </Card>

        <Card className="border-slate-200/80 shadow-sm">
          <CardHeader className="flex flex-row items-center justify-between gap-4">
            <div>
              <CardTitle className="text-xl">Pending Actions</CardTitle>
              <p className="text-sm text-muted-foreground mt-1">
                Reminders for overdue activity, reconciliation gaps, and stale controls.
              </p>
            </div>
            <Badge variant={pendingActions.length > 0 ? 'destructive' : 'outline'}>
              {pendingActions.length > 0 ? `${pendingActions.length} open` : 'All clear'}
            </Badge>
          </CardHeader>
          <CardContent className="space-y-3">
            {pendingActions.length > 0 ? (
              pendingActions.map((item) => (
                <div key={item.id} className="rounded-2xl border border-slate-200 p-4">
                  <div className="flex items-start gap-3">
                    <div className="rounded-xl bg-slate-100 p-2">
                      <Icon icon={item.icon} className="h-5 w-5 text-slate-700" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <h3 className="font-medium text-slate-900">{item.title}</h3>
                      <p className="mt-1 text-sm text-slate-600">{item.description}</p>
                    </div>
                  </div>
                  <Button variant="outline" size="sm" className="mt-3" onClick={() => navigate(item.path)}>
                    {item.action}
                  </Button>
                </div>
              ))
            ) : (
              <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-800">
                <div className="flex items-center gap-3">
                  <Icon icon="solar:shield-check-bold-duotone" className="h-6 w-6" />
                  <div>
                    <h3 className="font-semibold">No urgent accounting actions</h3>
                    <p className="text-sm mt-1">Collections, payables, and cash controls are currently within normal ranges.</p>
                  </div>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 xl:grid-cols-[1.4fr_1fr]">
        <Card className="border-slate-200/80 shadow-sm">
          <CardHeader className="flex flex-row items-center justify-between gap-4">
            <div>
              <CardTitle className="text-xl">Business Performance Analytics</CardTitle>
              <p className="text-sm text-muted-foreground mt-1">
                Revenue trend, expense composition, and cash concentration across the selected window.
              </p>
            </div>
            <Badge variant="outline">Configurable {periodDays}d window</Badge>
          </CardHeader>
          <CardContent className="grid gap-6 xl:grid-cols-2">
            <div className="rounded-2xl border border-slate-200 p-4">
              <div className="mb-4 flex items-start justify-between gap-3">
                <div>
                  <h3 className="font-semibold">Revenue Trend</h3>
                  <p className="text-sm text-muted-foreground">Last {periodDays} days of sales activity.</p>
                </div>
                <Badge className="bg-emerald-100 text-emerald-700 hover:bg-emerald-100">{formatCompactCurrency(data?.profitLoss?.revenue?.total ?? 0)}</Badge>
              </div>
              {revenueTrendData.length > 0 ? (
                <LineChart data={revenueTrendData} xKey="period" yKeys={['revenue']} formatYAxis="currency" height={280} />
              ) : (
                <div className="flex h-[280px] items-center justify-center rounded-xl bg-slate-50 text-sm text-muted-foreground">
                  No sales trend data available for this period.
                </div>
              )}
            </div>

            <div className="rounded-2xl border border-slate-200 p-4">
              <div className="mb-4 flex items-start justify-between gap-3">
                <div>
                  <h3 className="font-semibold">Expense Mix</h3>
                  <p className="text-sm text-muted-foreground">Top categories contributing to spend.</p>
                </div>
                <Badge className="bg-amber-100 text-amber-700 hover:bg-amber-100">{formatCompactCurrency(totalExpenseExposure)}</Badge>
              </div>
              {expenseMixData.length > 0 ? (
                <PieChart data={expenseMixData} nameKey="category" valueKey="amount" formatValue="currency" innerRadius={52} height={280} />
              ) : (
                <div className="flex h-[280px] items-center justify-center rounded-xl bg-slate-50 text-sm text-muted-foreground">
                  No expense breakdown is available for this period.
                </div>
              )}
            </div>

            <div className="rounded-2xl border border-slate-200 p-4 xl:col-span-2">
              <div className="mb-4 flex items-start justify-between gap-3">
                <div>
                  <h3 className="font-semibold">Cash Balance Summary</h3>
                  <p className="text-sm text-muted-foreground">Current balance by bank account, with reconciliation freshness.</p>
                </div>
                <Badge className="bg-sky-100 text-sky-700 hover:bg-sky-100">{bankAccounts.length} account{bankAccounts.length === 1 ? '' : 's'}</Badge>
              </div>
              {cashDistributionData.length > 0 ? (
                <BarChart data={cashDistributionData} xKey="account" yKeys={['balance']} formatYAxis="currency" height={260} />
              ) : (
                <div className="flex h-[260px] items-center justify-center rounded-xl bg-slate-50 text-sm text-muted-foreground">
                  No bank accounts are available for cash visualization.
                </div>
              )}
            </div>
          </CardContent>
        </Card>

        <div className="space-y-6">
          <Card className="border-slate-200/80 shadow-sm">
            <CardHeader>
              <CardTitle className="text-xl">AP & AR Reconciliation Summary</CardTitle>
              <p className="text-sm text-muted-foreground mt-1">
                View collection coverage, payables pressure, and cash control posture in one place.
              </p>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="rounded-2xl border border-slate-200 p-4">
                <p className="text-sm text-muted-foreground">Collection coverage</p>
                <div className="mt-2 flex items-end justify-between gap-3">
                  <div className="text-2xl font-bold">{collectionCoverage.toFixed(1)}%</div>
                  <Badge variant={collectionCoverage >= 100 ? 'outline' : 'destructive'}>
                    {collectionCoverage >= 100 ? 'Covered' : 'Below target'}
                  </Badge>
                </div>
                <p className="mt-2 text-sm text-slate-600">Open receivables versus open payables.</p>
              </div>

              <div className="rounded-2xl border border-slate-200 p-4">
                <p className="text-sm text-muted-foreground">Net working exposure</p>
                <div className="mt-2 text-2xl font-bold">
                  {formatCurrency((metrics?.outstanding_receivables.amount ?? 0) - (metrics?.outstanding_payables.amount ?? 0))}
                </div>
                <p className="mt-2 text-sm text-slate-600">Difference between open receivables and payables.</p>
              </div>

              <div className="rounded-2xl border border-slate-200 p-4">
                <p className="text-sm text-muted-foreground">Control posture</p>
                <div className="mt-2 text-2xl font-bold">{staleBankAccounts.length}</div>
                <p className="mt-2 text-sm text-slate-600">Bank accounts with stale or missing reconciliation coverage.</p>
              </div>
            </CardContent>
          </Card>

          <Card className="border-slate-200/80 shadow-sm">
            <CardHeader>
              <CardTitle className="text-xl">Cash Accounts</CardTitle>
              <p className="text-sm text-muted-foreground mt-1">
                Quick access to balances and last reconciliation checkpoints.
              </p>
            </CardHeader>
            <CardContent className="space-y-3">
              {bankAccounts.length > 0 ? (
                bankAccounts.slice(0, 5).map((account) => (
                  <div key={account.id} className="rounded-2xl border border-slate-200 p-4">
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <div className="font-medium text-slate-900">{account.name}</div>
                        <div className="text-sm text-slate-500">{account.bank_name} · {account.account_number}</div>
                      </div>
                      <div className="text-right">
                        <div className="font-semibold">{formatCurrency(account.current_balance ?? 0)}</div>
                        <div className="text-xs text-slate-500">
                          {account.last_reconciliation_date ? `Last rec ${account.last_reconciliation_date}` : 'Never reconciled'}
                        </div>
                      </div>
                    </div>
                  </div>
                ))
              ) : (
                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-muted-foreground">
                  No bank accounts available yet.
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card className="border-slate-200/80 shadow-sm">
          <CardHeader className="flex flex-row items-center justify-between gap-4">
            <div>
              <CardTitle>Recent Customer Invoices</CardTitle>
              <p className="text-sm text-muted-foreground mt-1">Latest customer-facing documents with quick drill-down.</p>
            </div>
            <Button variant="outline" size="sm" onClick={() => navigate('/accounting/customers/bills')}>
              View all
            </Button>
          </CardHeader>
          <CardContent>
            {metrics?.recent_invoices && metrics.recent_invoices.length > 0 ? (
              <div className="space-y-3">
                {metrics.recent_invoices.map((invoice) => (
                  <div
                    key={invoice.id}
                    className="flex items-center justify-between p-4 rounded-2xl border border-slate-200 hover:bg-slate-50 cursor-pointer transition-colors"
                    onClick={() => navigate(`/accounting/customers/bills/${invoice.id}`)}
                  >
                    <div className="flex-1">
                      <div className="flex items-center gap-2">
                        <div className="font-medium">{invoice.invoice_number}</div>
                        <span className={`inline-flex rounded-full border px-2 py-0.5 text-xs ${getStatusClasses(invoice.status)}`}>
                          {formatStatus(invoice.status)}
                        </span>
                      </div>
                      <div className="text-sm text-muted-foreground">{invoice.customer_name}</div>
                    </div>
                    <div className="text-right">
                      <div className="font-medium">{formatCurrency(invoice.total_amount)}</div>
                      <div className="text-xs text-muted-foreground">{new Date(invoice.invoice_date).toLocaleDateString()}</div>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="text-center py-10 text-muted-foreground">
                <Icon icon="solar:document-text-bold-duotone" className="h-12 w-12 mx-auto mb-2 opacity-50" />
                <p>No recent invoices</p>
              </div>
            )}
          </CardContent>
        </Card>

        <Card className="border-slate-200/80 shadow-sm">
          <CardHeader className="flex flex-row items-center justify-between gap-4">
            <div>
              <CardTitle>Recent Vendor Bills</CardTitle>
              <p className="text-sm text-muted-foreground mt-1">Latest supplier commitments with quick action access.</p>
            </div>
            <Button variant="outline" size="sm" onClick={() => navigate('/accounting/vendors/bills')}>
              View all
            </Button>
          </CardHeader>
          <CardContent>
            {metrics?.recent_bills && metrics.recent_bills.length > 0 ? (
              <div className="space-y-3">
                {metrics.recent_bills.map((bill) => (
                  <div
                    key={bill.id}
                    className="flex items-center justify-between p-4 rounded-2xl border border-slate-200 hover:bg-slate-50 cursor-pointer transition-colors"
                    onClick={() => navigate(`/accounting/vendors/bills/${bill.id}`)}
                  >
                    <div className="flex-1">
                      <div className="flex items-center gap-2">
                        <div className="font-medium">{bill.bill_number}</div>
                        <span className={`inline-flex rounded-full border px-2 py-0.5 text-xs ${getStatusClasses(bill.status)}`}>
                          {formatStatus(bill.status)}
                        </span>
                      </div>
                      <div className="text-sm text-muted-foreground">{bill.vendor_name}</div>
                    </div>
                    <div className="text-right">
                      <div className="font-medium">{formatCurrency(bill.total_amount)}</div>
                      <div className="text-xs text-muted-foreground">{new Date(bill.bill_date).toLocaleDateString()}</div>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="text-center py-10 text-muted-foreground">
                <Icon icon="solar:bill-list-bold-duotone" className="h-12 w-12 mx-auto mb-2 opacity-50" />
                <p>No recent bills</p>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
};

export default AccountingDashboard;
