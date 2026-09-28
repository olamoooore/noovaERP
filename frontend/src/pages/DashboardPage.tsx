import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Link, useNavigate } from 'react-router-dom'
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { MasterReportsTab } from '@/components/dashboard/MasterReportsTab'
import { useFeatureAccess } from '@/hooks/use-feature-access'
import { formatCurrency, formatDate } from '@/lib/utils'
import api from '@/lib/api'

type Range = '7d' | '30d' | '90d' | '180d' | '365d'

type RevenuePoint = {
  month?: string
  revenue?: number | string
  x?: string
  v?: number | string
}

const RANGE_LABEL: Record<Range, string> = {
  '7d': 'Last 7 days',
  '30d': 'Last 30 days',
  '90d': 'Last 90 days',
  '180d': 'Last 6 months',
  '365d': 'Last 12 months',
}

const MODULES: Array<{
  key: string
  label: string
  description: string
  icon: string
  to: string
  accent: string
  iconBg: string
  /** Tenant feature key this cockpit card is gated on. */
  featureKey: string
}> = [
  {
    key: 'finance',
    featureKey: 'accounting',
    label: 'Accounting',
    description: 'Ledger, AR/AP, close',
    icon: 'solar:wallet-money-bold-duotone',
    to: '/accounting/dashboard',
    accent: 'from-sky-50 to-white',
    iconBg: 'bg-sky-100 text-sky-700',
  },
  {
    key: 'inventory',
    featureKey: 'inventory',
    label: 'Inventory',
    description: 'Stock, transfers, PO',
    icon: 'solar:box-bold-duotone',
    to: '/inventory',
    accent: 'from-emerald-50 to-white',
    iconBg: 'bg-emerald-100 text-emerald-700',
  },
  {
    key: 'contacts',
    featureKey: 'master-contacts',
    label: 'Contacts',
    description: 'Customers, activity and status',
    icon: 'solar:users-group-rounded-bold-duotone',
    to: '/master-contacts',
    accent: 'from-violet-50 to-white',
    iconBg: 'bg-violet-100 text-violet-700',
  },
  {
    key: 'sales',
    featureKey: 'sales',
    label: 'Sales',
    description: 'Quotes & invoices',
    icon: 'solar:bill-list-bold-duotone',
    to: '/sales',
    accent: 'from-amber-50 to-white',
    iconBg: 'bg-amber-100 text-amber-700',
  },
  {
    key: 'template-builder',
    featureKey: 'template_builder',
    label: 'Template Builder',
    description: 'Document & PDF templates',
    icon: 'solar:document-text-bold-duotone',
    to: '/sales/settings',
    accent: 'from-indigo-50 to-white',
    iconBg: 'bg-indigo-100 text-indigo-700',
  },
  {
    key: 'hrm',
    featureKey: 'hrm',
    label: 'HRM & Payroll',
    description: 'People & pay runs',
    icon: 'solar:users-group-two-rounded-bold-duotone',
    to: '/hrm',
    accent: 'from-rose-50 to-white',
    iconBg: 'bg-rose-100 text-rose-700',
  },
  {
    key: 'products',
    featureKey: 'inventory',
    label: 'Products',
    description: 'Catalog, availability and reports',
    icon: 'solar:box-minimalistic-bold-duotone',
    to: '/products/dashboard',
    accent: 'from-cyan-50 to-white',
    iconBg: 'bg-cyan-100 text-cyan-700',
  },
]

const QUICK_ACTIONS = [
  { label: 'New invoice', icon: 'solar:document-add-bold-duotone', to: '/invoices/create' },
  { label: 'Add product', icon: 'solar:box-minimalistic-bold-duotone', to: '/products/new' },
  { label: 'New customer', icon: 'solar:user-plus-bold-duotone', to: '/customers' },
  { label: 'Record bill', icon: 'solar:bill-check-bold-duotone', to: '/accounting/vendors/bills/create' },
  { label: 'Open POS', icon: 'solar:cart-3-bold-duotone', to: '/pos' },
  { label: 'Run report', icon: 'solar:chart-2-bold-duotone', to: '/accounting/reporting/profit-loss' },
]

function normalizeRevenueSeries(points?: RevenuePoint[]) {
  return (points ?? []).map((point) => {
    const rawLabel = point.x ?? point.month ?? ''
    const label = rawLabel
      ? new Date(`${String(rawLabel).slice(0, 10)}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
      : ''
    return {
      x: label,
      v: Number(point.v ?? point.revenue ?? 0),
    }
  })
}

function Delta({ value }: { value?: number }) {
  if (value === undefined || value === null || Number.isNaN(value)) return null
  const up = value >= 0
  return (
    <span
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ${
        up ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'
      }`}
    >
      <Icon icon={up ? 'solar:arrow-up-bold' : 'solar:arrow-down-bold'} className="h-3 w-3" />
      {Math.abs(value).toFixed(1)}%
    </span>
  )
}

export function DashboardPage() {
  const [range, setRange] = useState<Range>('30d')
  const navigate = useNavigate()
  // Module cockpit is entitlement-gated: only modules this tenant owns show.
  const { isFeatureEnabled } = useFeatureAccess()
  const visibleModules = MODULES.filter((module) => isFeatureEnabled(module.featureKey))
  const [activeTab, setActiveTab] = useState('view')

  const { data: stats } = useQuery({
    queryKey: ['dashboard', range],
    queryFn: async () => {
      const response = await api.get('/dashboard', { params: { range } })
      return response.data
    },
  })

  const totalRevenue: number = stats?.total_revenue ?? 0
  const outstandingAmount: number = stats?.outstanding_invoices?.amount ?? 0
  const outstandingCount: number = stats?.outstanding_invoices?.count ?? 0
  const totalCustomers: number = stats?.total_customers ?? 0
  const lowStock: number = stats?.low_stock_products ?? 0
  const totalProducts: number = stats?.total_products ?? 0

  const series = useMemo(
    () => normalizeRevenueSeries(stats?.revenue_trend ?? stats?.revenue_by_month),
    [stats?.revenue_trend, stats?.revenue_by_month],
  )

  const signalBars = useMemo(
    () => [
      { label: 'Customers', value: totalCustomers, color: '#0ea5e9' },
      { label: 'Products', value: totalProducts, color: '#10b981' },
      { label: 'Open AR', value: outstandingCount, color: '#f59e0b' },
      { label: 'Stock alerts', value: lowStock, color: '#f43f5e' },
    ],
    [totalCustomers, totalProducts, outstandingCount, lowStock],
  )

  return (
    <div className="space-y-4">
      {/* Single toolbar row: title · tabs · period · primary action */}
      <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
        <div className="min-w-0">
          <h1 className="text-3xl font-bold text-slate-950">Dashboard</h1>
          <p className="text-sm text-slate-500">Overall system performance, key metrics and decision-ready reporting.</p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Tabs value={activeTab} onValueChange={setActiveTab}>
            <TabsList className="w-fit max-w-full">
              <TabsTrigger value="view">
                <Icon icon="solar:eye-bold-duotone" className="mr-2 h-4 w-4" />
                View
              </TabsTrigger>
              <TabsTrigger value="reports">
                <Icon icon="solar:document-text-bold-duotone" className="mr-2 h-4 w-4" />
                Reports
              </TabsTrigger>
            </TabsList>
          </Tabs>

          {activeTab === 'view' && (
            <>
              <div className="flex h-8 items-center rounded-full bg-muted p-1">
                {(Object.keys(RANGE_LABEL) as Range[]).map((r) => (
                  <button
                    key={r}
                    onClick={() => setRange(r)}
                    className={`h-6 rounded-full px-3 text-xs font-medium transition-all ${
                      range === r
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground'
                    }`}
                  >
                    {RANGE_LABEL[r]}
                  </button>
                ))}
              </div>

            </>
          )}
        </div>
      </div>

      {activeTab === 'reports' ? (
        <MasterReportsTab stats={stats} range={range} onRangeChange={setRange} />
      ) : (
        <>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <KpiTile
          label="Revenue"
          value={formatCurrency(totalRevenue)}
          sub={RANGE_LABEL[range]}
          icon="solar:dollar-minimalistic-bold-duotone"
          iconClass="bg-emerald-100 text-emerald-700"
          delta={stats?.revenue_delta_pct}
        />
        <KpiTile
          label="AR Outstanding"
          value={formatCurrency(outstandingAmount)}
          sub={`${outstandingCount} open invoice${outstandingCount === 1 ? '' : 's'}`}
          icon="solar:clock-circle-bold-duotone"
          iconClass="bg-amber-100 text-amber-700"
          delta={stats?.outstanding_delta_pct}
        />
        <KpiTile
          label="Active Customers"
          value={totalCustomers.toLocaleString()}
          sub="Current active customer records"
          icon="solar:users-group-rounded-bold-duotone"
          iconClass="bg-violet-100 text-violet-700"
        />
        <KpiTile
          label="Stock Alerts"
          value={lowStock.toLocaleString()}
          sub="Items below reorder point"
          icon="solar:box-bold-duotone"
          iconClass="bg-rose-100 text-rose-700"
          tone={lowStock > 0 ? 'warn' : undefined}
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-[1.75fr_1fr]">
        <Card className="rounded-xl border-slate-200/80 shadow-sm">
          <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-2">
            <div>
              <CardTitle className="text-base font-semibold text-slate-900">Revenue trend</CardTitle>
              <p className="mt-1 text-xs text-slate-500">{range === '180d' || range === '365d' ? 'Monthly invoice revenue' : 'Daily invoice revenue'} · {RANGE_LABEL[range]}</p>
            </div>
            <div className="text-right">
              <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Total</p>
              <p className="text-lg font-semibold text-slate-900">{formatCurrency(totalRevenue)}</p>
            </div>
          </CardHeader>
          <CardContent className="pt-2">
            {series.length === 0 ? (
              <div className="flex h-[240px] flex-col items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50 text-center">
                <Icon icon="solar:chart-2-bold-duotone" className="h-10 w-10 text-slate-400" />
                <p className="mt-2 text-sm font-medium text-slate-700">No revenue trend yet</p>
                <p className="mt-1 max-w-sm text-xs text-slate-500">
                  Posted invoices will build this trend automatically.
                </p>
              </div>
            ) : (
              <div className="h-[240px] w-full">
                <ResponsiveContainer width="100%" height="100%">
                  <AreaChart data={series} margin={{ top: 8, right: 8, bottom: 0, left: -16 }}>
                    <defs>
                      <linearGradient id="revFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="#0ea5e9" stopOpacity={0.28} />
                        <stop offset="100%" stopColor="#0ea5e9" stopOpacity={0} />
                      </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="#eef2f7" vertical={false} />
                    <XAxis
                      dataKey="x"
                      stroke="#94a3b8"
                      tick={{ fontSize: 11 }}
                      tickLine={false}
                      axisLine={false}
                      minTickGap={24}
                    />
                    <YAxis
                      stroke="#94a3b8"
                      tick={{ fontSize: 11 }}
                      tickLine={false}
                      axisLine={false}
                      width={48}
                    />
                    <Tooltip
                      cursor={{ stroke: '#cbd5e1', strokeDasharray: '3 3' }}
                      contentStyle={{
                        borderRadius: 10,
                        border: '1px solid #e2e8f0',
                        boxShadow: '0 8px 24px rgba(15,23,42,0.08)',
                        fontSize: 12,
                      }}
                      formatter={(value: any) => formatCurrency(Number(value))}
                    />
                    <Area
                      type="monotone"
                      dataKey="v"
                      stroke="#0ea5e9"
                      strokeWidth={2}
                      fill="url(#revFill)"
                      activeDot={{ r: 4, strokeWidth: 0 }}
                    />
                  </AreaChart>
                </ResponsiveContainer>
              </div>
            )}
          </CardContent>
        </Card>

        <Card className="rounded-xl border-slate-200/80 shadow-sm">
          <CardHeader className="pb-2">
            <CardTitle className="text-base font-semibold text-slate-900">AR aging</CardTitle>
            <p className="mt-1 text-xs text-slate-500">Open receivables by bucket</p>
          </CardHeader>
          <CardContent className="space-y-3">
            {((stats?.ar_aging as Array<{ bucket: string; amount: number; color?: string }>) ?? [
              { bucket: 'Current', amount: 0, color: 'bg-emerald-500' },
              { bucket: '1 - 30 days', amount: 0, color: 'bg-sky-500' },
              { bucket: '31 - 60 days', amount: 0, color: 'bg-amber-500' },
              { bucket: '61 - 90 days', amount: 0, color: 'bg-orange-500' },
              { bucket: '90+ days', amount: 0, color: 'bg-rose-500' },
            ]).map((row, idx) => {
              const totalAR =
                ((stats?.ar_aging as Array<{ amount: number }>) ?? []).reduce(
                  (a, r) => a + (r.amount || 0),
                  0,
                ) || outstandingAmount || 1
              const pct = Math.min(100, Math.round(((row.amount || 0) / totalAR) * 100))
              return (
                <div key={idx}>
                  <div className="flex items-center justify-between text-xs">
                    <span className="font-medium text-slate-700">{row.bucket}</span>
                    <span className="text-slate-500">{formatCurrency(row.amount || 0)}</span>
                  </div>
                  <div className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                    <div
                      className={`h-full rounded-full ${row.color ?? 'bg-slate-400'}`}
                      style={{ width: `${pct}%` }}
                    />
                  </div>
                </div>
              )
            })}
            <Link
              to="/accounting/reporting/aged-receivables"
              className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-sky-700 hover:text-sky-900"
            >
              Open aged receivables
              <Icon icon="solar:arrow-right-bold" className="h-3.5 w-3.5" />
            </Link>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-[1.75fr_1fr]">
        <Card className="rounded-xl border-slate-200/80 shadow-sm">
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <div>
              <CardTitle className="text-base font-semibold text-slate-900">Module cockpit</CardTitle>
              <p className="mt-1 text-xs text-slate-500">Jump into the parts of the business you run.</p>
            </div>
            <Link
              to="/modules"
              className="inline-flex items-center gap-1 text-xs font-medium text-slate-600 hover:text-slate-900"
            >
              All modules
              <Icon icon="solar:arrow-right-bold" className="h-3.5 w-3.5" />
            </Link>
          </CardHeader>
          <CardContent>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {visibleModules.map((m) => (
                <Link
                  key={m.key}
                  to={m.to}
                  className={`group relative overflow-hidden rounded-lg border border-slate-200/80 bg-gradient-to-br ${m.accent} p-4 transition hover:border-slate-300 hover:shadow-sm`}
                >
                  <div className="flex items-start justify-between">
                    <div className={`flex h-10 w-10 items-center justify-center rounded-lg ${m.iconBg}`}>
                      <Icon icon={m.icon} className="h-5 w-5" />
                    </div>
                    <Icon
                      icon="solar:arrow-right-up-linear"
                      className="h-4 w-4 text-slate-400 transition group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-slate-900"
                    />
                  </div>
                  <p className="mt-3 text-sm font-semibold text-slate-900">{m.label}</p>
                  <p className="text-xs text-slate-500">{m.description}</p>
                </Link>
              ))}
            </div>
          </CardContent>
        </Card>

        <Card className="rounded-xl border-slate-200/80 shadow-sm">
          <CardHeader className="pb-2">
            <CardTitle className="text-base font-semibold text-slate-900">Quick actions</CardTitle>
            <p className="mt-1 text-xs text-slate-500">One click into the most-used flows.</p>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-2 gap-2">
              {QUICK_ACTIONS.map((a) => (
                <Link
                  key={a.label}
                  to={a.to}
                  className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-xs font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900"
                >
                  <Icon icon={a.icon} className="h-4 w-4 text-slate-500" />
                  {a.label}
                </Link>
              ))}
            </div>
            <div className="rounded-lg border border-slate-200 bg-slate-50/70 p-3">
              <div className="mb-2 flex items-center justify-between">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Operating signals</p>
                <Icon icon="solar:chart-square-bold-duotone" className="h-4 w-4 text-slate-400" />
              </div>
              <div className="h-[132px]">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={signalBars} margin={{ top: 4, right: 4, bottom: 0, left: -20 }}>
                    <XAxis dataKey="label" tick={{ fontSize: 10 }} tickLine={false} axisLine={false} />
                    <YAxis hide />
                    <Tooltip
                      cursor={{ fill: '#f1f5f9' }}
                      contentStyle={{ borderRadius: 10, border: '1px solid #e2e8f0', fontSize: 12 }}
                    />
                    <Bar dataKey="value" radius={[6, 6, 0, 0]} fill="#0f172a" />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
        <CardHeader className="flex flex-row items-center justify-between space-y-0">
          <div>
            <CardTitle className="text-lg">Recent invoices</CardTitle>
            <p className="mt-1 text-sm text-muted-foreground">Latest activity in receivables.</p>
          </div>
          <HeaderActionButton
            variant="ghost"
            icon="solar:alt-arrow-right-bold"
            onClick={() => navigate('/invoices')}
          >
            View all
          </HeaderActionButton>
        </CardHeader>
        <CardContent>
          {((stats?.recent_invoices as any[]) ?? []).length === 0 ? (
            <div className="rounded-xl border border-dashed border-slate-300 py-12 text-center dark:border-slate-700">
              <Icon icon="solar:document-text-bold-duotone" className="mx-auto mb-2 h-10 w-10 text-muted-foreground opacity-40" />
              <p className="text-sm font-medium">No invoices yet</p>
              <p className="mt-0.5 text-xs text-muted-foreground">Create your first invoice to see activity here.</p>
              <div className="mt-4 flex justify-center">
                <HeaderActionButton icon="solar:add-circle-bold" onClick={() => navigate('/invoices/create')}>
                  Create invoice
                </HeaderActionButton>
              </div>
            </div>
          ) : (
            <div className="overflow-hidden rounded-xl border border-slate-200/80 dark:border-slate-800">
              <Table>
                <TableHeader>
                  <TableRow className="bg-muted/40 hover:bg-muted/40">
                    <TableHead>Invoice</TableHead>
                    <TableHead>Customer</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Amount</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {((stats?.recent_invoices as any[]) ?? []).map((invoice: any) => (
                    <TableRow
                      key={invoice.id}
                      className="cursor-pointer"
                      onClick={() => navigate(`/invoices/${invoice.id}`, { state: { from: '/dashboard' } })}
                    >
                      <TableCell className="font-medium text-primary">{invoice.invoice_number}</TableCell>
                      <TableCell className="max-w-[220px] truncate text-muted-foreground">
                        {invoice.customer?.name ?? '—'}
                      </TableCell>
                      <TableCell className="text-muted-foreground">
                        {formatDate(invoice.invoice_date || invoice.created_at)}
                      </TableCell>
                      <TableCell>
                        <StatusPill status={invoice.status} />
                      </TableCell>
                      <TableCell className="text-right font-medium tabular-nums">
                        {formatCurrency(invoice.total)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>
        </>
      )}
    </div>
  )
}

function KpiTile({
  label,
  value,
  sub,
  icon,
  iconClass,
  delta,
  tone,
}: {
  label: string
  value: string
  sub: string
  icon: string
  iconClass: string
  delta?: number
  tone?: 'warn'
}) {
  return (
    <Card className="rounded-xl border-slate-200/80 shadow-sm transition hover:shadow-md">
      <CardContent className="p-5">
        <div className="flex items-start justify-between">
          <div className={`flex h-10 w-10 items-center justify-center rounded-lg ${iconClass}`}>
            <Icon icon={icon} className="h-5 w-5" />
          </div>
          <Delta value={delta} />
        </div>
        <p className="mt-4 text-[11px] font-medium uppercase tracking-wide text-slate-500">{label}</p>
        <p
          className={`mt-1 text-[26px] font-semibold leading-tight tracking-tight ${
            tone === 'warn' ? 'text-rose-600' : 'text-slate-900'
          }`}
        >
          {value}
        </p>
        <p className="mt-1 text-xs text-slate-500">{sub}</p>
      </CardContent>
    </Card>
  )
}

function StatusPill({ status }: { status?: string }) {
  const map: Record<string, string> = {
    paid: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
    sent: 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300',
    posted: 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300',
    partial: 'bg-violet-100 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300',
    draft: 'bg-muted text-muted-foreground',
    overdue: 'bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300',
    cancelled: 'bg-muted text-muted-foreground',
    void: 'bg-muted text-muted-foreground',
  }
  const cls = map[status ?? 'draft'] ?? 'bg-muted text-muted-foreground'
  return (
    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize ${cls}`}>
      {status ? status.replace(/_/g, ' ') : '-'}
    </span>
  )
}
