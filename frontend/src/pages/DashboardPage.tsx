import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Link } from 'react-router-dom'
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
import { Button } from '@/components/ui/button'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { MasterReportsTab } from '@/components/dashboard/MasterReportsTab'
import { formatCurrency, formatDate } from '@/lib/utils'
import { useAuthStore } from '@/store/authStore'
import api from '@/lib/api'

type Range = '7d' | '30d' | '90d'

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
}

const MODULES: Array<{
  key: string
  label: string
  description: string
  icon: string
  to: string
  accent: string
  iconBg: string
}> = [
  {
    key: 'finance',
    label: 'Accounting',
    description: 'Ledger, AR/AP, close',
    icon: 'solar:wallet-money-bold-duotone',
    to: '/accounting/dashboard',
    accent: 'from-sky-50 to-white',
    iconBg: 'bg-sky-100 text-sky-700',
  },
  {
    key: 'inventory',
    label: 'Inventory',
    description: 'Stock, transfers, PO',
    icon: 'solar:box-bold-duotone',
    to: '/inventory',
    accent: 'from-emerald-50 to-white',
    iconBg: 'bg-emerald-100 text-emerald-700',
  },
  {
    key: 'contacts',
    label: 'Contacts',
    description: 'Customers, activity and status',
    icon: 'solar:users-group-rounded-bold-duotone',
    to: '/master-contacts',
    accent: 'from-violet-50 to-white',
    iconBg: 'bg-violet-100 text-violet-700',
  },
  {
    key: 'sales',
    label: 'Sales',
    description: 'Quotes & invoices',
    icon: 'solar:bill-list-bold-duotone',
    to: '/sales',
    accent: 'from-amber-50 to-white',
    iconBg: 'bg-amber-100 text-amber-700',
  },
  {
    key: 'template-builder',
    label: 'Template Builder',
    description: 'Document & PDF templates',
    icon: 'solar:document-text-bold-duotone',
    to: '/sales/settings',
    accent: 'from-indigo-50 to-white',
    iconBg: 'bg-indigo-100 text-indigo-700',
  },
  {
    key: 'hrm',
    label: 'HRM & Payroll',
    description: 'People & pay runs',
    icon: 'solar:users-group-two-rounded-bold-duotone',
    to: '/hrm',
    accent: 'from-rose-50 to-white',
    iconBg: 'bg-rose-100 text-rose-700',
  },
  {
    key: 'products',
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
  const user = useAuthStore((s) => s.user)
  const [range, setRange] = useState<Range>('30d')
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

  const greeting = useMemo(() => {
    const h = new Date().getHours()
    if (h < 12) return 'Good morning'
    if (h < 18) return 'Good afternoon'
    return 'Good evening'
  }, [])

  const today = new Date().toLocaleDateString(undefined, {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
    year: 'numeric',
  })

  const firstName = (user?.name ?? '').split(' ')[0] || 'there'

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold text-slate-950">Master Dashboard</h1>
        <p className="mt-1 text-sm text-slate-500">Overall system performance, key metrics and decision-ready reporting.</p>
      </div>

      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList className="grid w-full max-w-sm grid-cols-2">
          <TabsTrigger value="view"><Icon icon="solar:eye-bold-duotone" className="mr-2 h-4 w-4" />View</TabsTrigger>
          <TabsTrigger value="reports"><Icon icon="solar:document-text-bold-duotone" className="mr-2 h-4 w-4" />Reports</TabsTrigger>
        </TabsList>
      </Tabs>

      {activeTab === 'reports' ? (
        <MasterReportsTab stats={stats} range={range} onRangeChange={setRange} />
      ) : (
        <>
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm font-medium text-slate-500">{today}</p>
          <h1 className="mt-1 text-[28px] font-semibold tracking-tight text-slate-950 sm:text-[32px]">
            {greeting}, {firstName}.
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Here's the operating pulse across finance, retail, stock and service operations.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <div className="inline-flex rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
            {(Object.keys(RANGE_LABEL) as Range[]).map((r) => (
              <button
                key={r}
                onClick={() => setRange(r)}
                className={`rounded-md px-3 py-1.5 text-xs font-medium transition ${
                  range === r
                    ? 'bg-slate-900 text-white shadow-sm'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                {RANGE_LABEL[r]}
              </button>
            ))}
          </div>
          <Button asChild className="h-9 rounded-lg bg-slate-900 px-3 text-xs font-semibold hover:bg-slate-800">
            <Link to="/invoices/create">
              <Icon icon="solar:add-circle-bold" className="mr-1.5 h-4 w-4" />
              New invoice
            </Link>
          </Button>
        </div>
      </div>

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
              <p className="mt-1 text-xs text-slate-500">Daily invoice revenue · {RANGE_LABEL[range]}</p>
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
              {MODULES.map((m) => (
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

      <Card className="rounded-xl border-slate-200/80 shadow-sm">
        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
          <div>
            <CardTitle className="text-base font-semibold text-slate-900">Recent invoices</CardTitle>
            <p className="mt-1 text-xs text-slate-500">Latest activity in receivables.</p>
          </div>
          <Link
            to="/invoices"
            className="inline-flex items-center gap-1 text-xs font-medium text-slate-600 hover:text-slate-900"
          >
            View all
            <Icon icon="solar:arrow-right-bold" className="h-3.5 w-3.5" />
          </Link>
        </CardHeader>
        <CardContent className="px-0">
          <div className="overflow-hidden">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-y border-slate-200/80 bg-slate-50/60 text-[11px] font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-6 py-2.5 text-left">Invoice</th>
                  <th className="px-6 py-2.5 text-left">Customer</th>
                  <th className="px-6 py-2.5 text-left">Date</th>
                  <th className="px-6 py-2.5 text-left">Status</th>
                  <th className="px-6 py-2.5 text-right">Amount</th>
                </tr>
              </thead>
              <tbody>
                {((stats?.recent_invoices as any[]) ?? []).length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-6 py-10 text-center">
                      <div className="flex flex-col items-center gap-2 text-slate-400">
                        <Icon icon="solar:document-bold-duotone" className="h-10 w-10" />
                        <p className="text-sm font-medium text-slate-600">No invoices yet</p>
                        <p className="text-xs">Create your first invoice to see activity here.</p>
                        <Button asChild size="sm" className="mt-1 h-8 rounded-md bg-slate-900 px-3 text-xs hover:bg-slate-800">
                          <Link to="/invoices/create">Create invoice</Link>
                        </Button>
                      </div>
                    </td>
                  </tr>
                ) : (
                  ((stats?.recent_invoices as any[]) ?? []).map((invoice: any) => (
                    <tr key={invoice.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                      <td className="px-6 py-3 font-medium text-slate-900">{invoice.invoice_number}</td>
                      <td className="px-6 py-3 text-slate-700">{invoice.customer?.name ?? '-'}</td>
                      <td className="px-6 py-3 text-slate-500">{formatDate(invoice.invoice_date)}</td>
                      <td className="px-6 py-3">
                        <StatusPill status={invoice.status} />
                      </td>
                      <td className="px-6 py-3 text-right font-semibold text-slate-900">
                        {formatCurrency(invoice.total)}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
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
    paid: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    sent: 'bg-sky-50 text-sky-700 ring-sky-200',
    draft: 'bg-slate-100 text-slate-600 ring-slate-200',
    overdue: 'bg-rose-50 text-rose-700 ring-rose-200',
    void: 'bg-slate-100 text-slate-500 ring-slate-200',
  }
  const cls = map[status ?? 'draft'] ?? 'bg-slate-100 text-slate-600 ring-slate-200'
  return (
    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${cls}`}>
      {status ?? '-'}
    </span>
  )
}
