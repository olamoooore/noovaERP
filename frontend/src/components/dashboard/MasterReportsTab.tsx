import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { Button } from '@/components/ui/button'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { downloadReportDocx, downloadReportXlsx } from '@/lib/reportFiles'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { cn, formatCurrency } from '@/lib/utils'

type Range = '7d' | '30d' | '90d' | '180d' | '365d'

const RANGE_OPTIONS: Array<{ value: Range; label: string }> = [
  { value: '7d', label: 'Last 7 days' },
  { value: '30d', label: 'Last 30 days' },
  { value: '90d', label: 'Last 90 days' },
  { value: '180d', label: 'Last 6 months' },
  { value: '365d', label: 'Last 12 months' },
]

const RANGE_LABEL = Object.fromEntries(RANGE_OPTIONS.map((option) => [option.value, option.label])) as Record<Range, string>
type MetricKey = 'revenue' | 'outstanding' | 'customers' | 'products' | 'orders' | 'stockAlerts'
type ChartType = 'bar' | 'line' | 'area' | 'pie'

interface MasterReportsTabProps {
  stats: any
  range: Range
  onRangeChange: (range: Range) => void
}

const METRICS: Array<{ key: MetricKey; label: string; currency?: boolean }> = [
  { key: 'revenue', label: 'Revenue', currency: true },
  { key: 'outstanding', label: 'Outstanding receivables', currency: true },
  { key: 'customers', label: 'Customers' },
  { key: 'products', label: 'Products' },
  { key: 'orders', label: 'Orders today' },
  { key: 'stockAlerts', label: 'Stock alerts' },
]

const DOMAINS = [
  { title: 'Financial', description: 'Profitability, balance sheet and cash position', icon: 'solar:wallet-money-bold-duotone', href: '/reports/financial', color: 'bg-sky-100 text-sky-700' },
  { title: 'Sales', description: 'Customer, category and sales trend analysis', icon: 'solar:chart-2-bold-duotone', href: '/reports/sales-analytics', color: 'bg-violet-100 text-violet-700' },
  { title: 'Products', description: 'Product revenue, profit and margin performance', icon: 'solar:box-bold-duotone', href: '/products/report', color: 'bg-emerald-100 text-emerald-700' },
  { title: 'Inventory', description: 'Stock valuation, aging and turnover', icon: 'solar:inventory-management-bold-duotone', href: '/reports/inventory', color: 'bg-amber-100 text-amber-700' },
  { title: 'Expenses', description: 'Expense categories, vendors and trends', icon: 'solar:bill-list-bold-duotone', href: '/reports/expense-analytics', color: 'bg-rose-100 text-rose-700' },
  { title: 'Compliance', description: 'Statutory and operational compliance reporting', icon: 'solar:shield-check-bold-duotone', href: '/accounting/reporting/compliance', color: 'bg-cyan-100 text-cyan-700' },
]

const COLORS = ['#0ea5e9', '#10b981', '#8b5cf6', '#f59e0b', '#f43f5e', '#06b6d4']

export function MasterReportsTab({ stats, range, onRangeChange }: MasterReportsTabProps) {
  const [selectedMetrics, setSelectedMetrics] = useState<MetricKey[]>(['revenue', 'outstanding', 'customers'])
  const [chartType, setChartType] = useState<ChartType>('bar')
  const [generated, setGenerated] = useState<{ metrics: MetricKey[]; chart: ChartType } | null>(null)

  const values: Record<MetricKey, number> = {
    revenue: Number(stats?.total_revenue ?? 0),
    outstanding: Number(stats?.outstanding_invoices?.amount ?? 0),
    customers: Number(stats?.total_customers ?? 0),
    products: Number(stats?.total_products ?? 0),
    orders: Number(stats?.orders_today ?? stats?.pos_orders_today ?? 0),
    stockAlerts: Number(stats?.low_stock_products ?? 0),
  }

  const revenueTrend = useMemo(() => (
    (stats?.revenue_trend ?? stats?.revenue_by_month ?? []).map((point: any) => ({
      period: point.x ?? point.month ?? '',
      revenue: Number(point.v ?? point.revenue ?? 0),
    }))
  ), [stats])

  const operatingData = [
    { name: 'Customers', value: values.customers },
    { name: 'Products', value: values.products },
    { name: 'Open invoices', value: Number(stats?.outstanding_invoices?.count ?? 0) },
    { name: 'Orders today', value: values.orders },
    { name: 'Stock alerts', value: values.stockAlerts },
  ]

  const customData = (generated?.metrics ?? []).map((key) => ({
    name: METRICS.find((metric) => metric.key === key)?.label ?? key,
    value: values[key],
    key,
  }))

  const toggleMetric = (key: MetricKey) => {
    setSelectedMetrics((current) => current.includes(key)
      ? current.filter((metric) => metric !== key)
      : [...current, key])
  }

  const toMetricRows = (data: Array<{ name: string; value: number | string }>) => [
    ['Metric', 'Value'],
    ...data.map((row) => [row.name, String(row.value)]),
  ]

  const exportCustomXlsx = () =>
    downloadReportXlsx(toMetricRows(customData), {
      filename: `custom-master-report-${range}`,
      title: 'Custom Master Report',
    })

  const exportCustomDocx = () =>
    downloadReportDocx(toMetricRows(customData), {
      filename: `custom-master-report-${range}`,
      title: 'Custom Master Report',
      subtitle: `Reporting period: ${range}`,
    })

  const overallRows = [
    ['Metric', 'Value'],
    ['Revenue', formatCurrency(values.revenue)],
    ['Outstanding', formatCurrency(values.outstanding)],
    ['Customers', values.customers.toLocaleString()],
    ['Products', values.products.toLocaleString()],
  ]

  const exportOverallXlsx = () =>
    downloadReportXlsx(overallRows, {
      filename: `overall-report-${range}`,
      title: 'Overall Performance Report',
    })

  const exportOverallDocx = () =>
    downloadReportDocx(overallRows, {
      filename: `overall-report-${range}`,
      title: 'Overall Performance Report',
      subtitle: `Reporting period: ${range}`,
    })

  return (
    <Tabs defaultValue="overall" className="space-y-5">
      <TabsList className="w-fit max-w-full">
        <TabsTrigger value="overall">Overall reports</TabsTrigger>
        <TabsTrigger value="domain">Domain reports</TabsTrigger>
        <TabsTrigger value="custom">Custom reports</TabsTrigger>
      </TabsList>

      <TabsContent value="overall" className="space-y-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div><h2 className="text-xl font-semibold">Overall performance report</h2><p className="text-sm text-muted-foreground">A broad view of financial and operational performance.</p></div>
          <div className="flex gap-2">
            <Select value={range} onValueChange={(value) => onRangeChange(value as Range)}><SelectTrigger className="h-8 w-40 rounded-full text-xs"><SelectValue /></SelectTrigger><SelectContent>{RANGE_OPTIONS.map((option) => <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>)}</SelectContent></Select>
            <HeaderActionButton variant="outline" icon="solar:file-text-bold-duotone" onClick={exportOverallXlsx}>
              Excel
            </HeaderActionButton>
            <HeaderActionButton variant="outline" icon="solar:document-text-bold-duotone" onClick={exportOverallDocx}>
              Word
            </HeaderActionButton>
          </div>
        </div>

        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <ReportMetric label="Revenue" value={formatCurrency(values.revenue)} icon="solar:dollar-minimalistic-bold-duotone" />
          <ReportMetric label="Outstanding" value={formatCurrency(values.outstanding)} icon="solar:clock-circle-bold-duotone" />
          <ReportMetric label="Customers" value={values.customers.toLocaleString()} icon="solar:users-group-rounded-bold-duotone" />
          <ReportMetric label="Products" value={values.products.toLocaleString()} icon="solar:box-bold-duotone" />
        </div>

        <div className="grid gap-5 lg:grid-cols-2">
          <Card><CardHeader><CardTitle className="text-base">Revenue trend</CardTitle></CardHeader><CardContent><div className="h-72">{revenueTrend.length ? <ResponsiveContainer width="100%" height="100%"><LineChart data={revenueTrend}><CartesianGrid strokeDasharray="3 3" vertical={false} /><XAxis dataKey="period" tick={{ fontSize: 11 }} /><YAxis tick={{ fontSize: 11 }} /><Tooltip formatter={(value: number) => formatCurrency(value)} /><Line type="monotone" dataKey="revenue" stroke="#0ea5e9" strokeWidth={3} dot={false} /></LineChart></ResponsiveContainer> : <EmptyChart />}</div></CardContent></Card>
          <Card><CardHeader><CardTitle className="text-base">Operating overview</CardTitle></CardHeader><CardContent><div className="h-72"><ResponsiveContainer width="100%" height="100%"><BarChart data={operatingData}><CartesianGrid strokeDasharray="3 3" vertical={false} /><XAxis dataKey="name" tick={{ fontSize: 11 }} /><YAxis /><Tooltip /><Bar dataKey="value" fill="#0f172a" radius={[6, 6, 0, 0]} /></BarChart></ResponsiveContainer></div></CardContent></Card>
        </div>
      </TabsContent>

      <TabsContent value="domain" className="space-y-5">
        <div><h2 className="text-xl font-semibold">Domain-specific reports</h2><p className="text-sm text-muted-foreground">Open focused reports for a particular part of the organization.</p></div>
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {DOMAINS.map((domain) => (
            <Link key={domain.title} to={domain.href} className="group">
              <Card className="h-full rounded-xl border-slate-200/80 transition hover:border-primary/40 hover:shadow-md dark:border-slate-800">
                <CardContent className="flex items-center gap-3 p-4">
                  <div className={cn('flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', domain.color)}>
                    <Icon icon={domain.icon} className="h-5 w-5" />
                  </div>
                  <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-semibold">{domain.title}</h3>
                    <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{domain.description}</p>
                  </div>
                  <Icon
                    icon="solar:alt-arrow-right-bold"
                    className="h-4 w-4 shrink-0 text-muted-foreground transition group-hover:translate-x-0.5 group-hover:text-primary"
                  />
                </CardContent>
              </Card>
            </Link>
          ))}
        </div>
      </TabsContent>

      <TabsContent value="custom" className="space-y-5">
        <div><h2 className="text-xl font-semibold">Custom report builder</h2><p className="text-sm text-muted-foreground">Choose the measures, period and visualization for a tailored report.</p></div>
        <div className="grid gap-5 lg:grid-cols-[360px_1fr]">
          <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
            <CardHeader><CardTitle className="text-base">Report configuration</CardTitle></CardHeader>
            <CardContent className="space-y-5">
              <div className="space-y-2">
                <Label>Reporting period</Label>
                <Select value={range} onValueChange={(value) => onRangeChange(value as Range)}>
                  <SelectTrigger className="h-8 rounded-full text-xs"><SelectValue /></SelectTrigger>
                  <SelectContent>{RANGE_OPTIONS.map((option) => <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>)}</SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label>Metrics</Label>
                  <span className="text-[11px] text-muted-foreground">{selectedMetrics.length} selected</span>
                </div>
                <div className="grid grid-cols-2 gap-2">
                  {METRICS.map((metric) => {
                    const active = selectedMetrics.includes(metric.key)
                    return (
                      <button
                        key={metric.key}
                        type="button"
                        onClick={() => toggleMetric(metric.key)}
                        className={cn(
                          'flex items-center gap-2 rounded-xl border p-3 text-left text-sm font-medium transition-colors',
                          active
                            ? 'border-primary/40 bg-primary/5 text-foreground'
                            : 'border-slate-200/80 text-muted-foreground hover:bg-muted/40 dark:border-slate-800',
                        )}
                      >
                        <Icon
                          icon={active ? 'solar:check-circle-bold' : 'solar:circle-bold'}
                          className={cn('h-4 w-4 shrink-0', active ? 'text-primary' : 'opacity-50')}
                        />
                        <span className="truncate">{metric.label}</span>
                      </button>
                    )
                  })}
                </div>
              </div>

              <div className="space-y-2">
                <Label>Visualization</Label>
                <Select value={chartType} onValueChange={(value) => setChartType(value as ChartType)}>
                  <SelectTrigger className="h-8 rounded-full text-xs"><SelectValue /></SelectTrigger>
                  <SelectContent>
                  <SelectItem value="bar">Bar chart</SelectItem>
                  <SelectItem value="line">Line chart</SelectItem>
                  <SelectItem value="area">Area chart</SelectItem>
                  <SelectItem value="pie">Pie chart</SelectItem>
                </SelectContent>
                </Select>
              </div>

              <Button className="h-9 w-full rounded-full" disabled={!selectedMetrics.length} onClick={() => setGenerated({ metrics: selectedMetrics, chart: chartType })}>
                <Icon icon="solar:chart-square-bold-duotone" className="mr-1.5 h-4 w-4" />
                Generate custom report
              </Button>
            </CardContent>
          </Card>

          <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
            <CardHeader className="flex-row items-center justify-between">
              <div>
                <CardTitle className="text-base">Generated report</CardTitle>
                <p className="mt-1 text-sm text-muted-foreground">
                  {generated
                    ? `${generated.metrics.length} metric${generated.metrics.length === 1 ? '' : 's'} · ${RANGE_LABEL[range]}`
                    : 'Configure and generate a report to preview it.'}
                </p>
              </div>
              {generated && (
                <div className="flex gap-2">
                  <HeaderActionButton variant="outline" icon="solar:file-text-bold-duotone" onClick={exportCustomXlsx}>Excel</HeaderActionButton>
                  <HeaderActionButton variant="outline" icon="solar:document-text-bold-duotone" onClick={exportCustomDocx}>Word</HeaderActionButton>
                </div>
              )}
            </CardHeader>
            <CardContent>
              {!generated ? (
                <div className="flex h-96 flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 text-center dark:border-slate-700">
                  <Icon icon="solar:chart-2-bold-duotone" className="h-12 w-12 text-muted-foreground opacity-40" />
                  <p className="mt-3 font-medium">No custom report generated</p>
                  <p className="text-sm text-muted-foreground">Select metrics and a visualization to begin.</p>
                </div>
              ) : (
                <div className="h-96">
                  <ResponsiveContainer width="100%" height="100%">
                    {generated.chart === 'bar' && (
                      <BarChart data={customData}>
                        <CartesianGrid strokeDasharray="3 3" vertical={false} />
                        <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                        <YAxis />
                        <Tooltip />
                        <Bar dataKey="value" fill="#0ea5e9" radius={[6, 6, 0, 0]} />
                      </BarChart>
                    )}
                    {generated.chart === 'line' && (
                      <LineChart data={customData}>
                        <CartesianGrid strokeDasharray="3 3" vertical={false} />
                        <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                        <YAxis />
                        <Tooltip />
                        <Line type="monotone" dataKey="value" stroke="#0ea5e9" strokeWidth={3} dot={{ r: 4 }} />
                      </LineChart>
                    )}
                    {generated.chart === 'area' && (
                      <AreaChart data={customData}>
                        <defs>
                          <linearGradient id="customReportArea" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#0ea5e9" stopOpacity={0.35} />
                            <stop offset="100%" stopColor="#0ea5e9" stopOpacity={0.03} />
                          </linearGradient>
                        </defs>
                        <CartesianGrid strokeDasharray="3 3" vertical={false} />
                        <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                        <YAxis />
                        <Tooltip />
                        <Area type="monotone" dataKey="value" stroke="#0ea5e9" strokeWidth={2} fill="url(#customReportArea)" />
                      </AreaChart>
                    )}
                    {generated.chart === 'pie' && (
                      <PieChart>
                        <Pie data={customData} dataKey="value" nameKey="name" outerRadius={130} label>
                          {customData.map((_, index) => <Cell key={index} fill={COLORS[index % COLORS.length]} />)}
                        </Pie>
                        <Tooltip />
                        <Legend />
                      </PieChart>
                    )}
                  </ResponsiveContainer>
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </TabsContent>
    </Tabs>
  )
}

function ReportMetric({ label, value, icon }: { label: string; value: string; icon: string }) {
  return <Card><CardContent className="flex items-center justify-between p-5"><div><p className="text-sm text-muted-foreground">{label}</p><p className="mt-1 text-2xl font-semibold">{value}</p></div><div className="rounded-xl bg-slate-100 p-3 text-slate-700"><Icon icon={icon} className="h-6 w-6" /></div></CardContent></Card>
}

function EmptyChart() {
  return <div className="flex h-full flex-col items-center justify-center text-muted-foreground"><Icon icon="solar:chart-2-bold-duotone" className="h-10 w-10" /><p className="mt-2 text-sm">No trend data for this period.</p></div>
}
