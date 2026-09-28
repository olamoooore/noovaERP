import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import {
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
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { formatCurrency } from '@/lib/utils'

type Range = '7d' | '30d' | '90d'
type MetricKey = 'revenue' | 'outstanding' | 'customers' | 'products' | 'orders' | 'stockAlerts'
type ChartType = 'bar' | 'pie'

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
            <Select value={range} onValueChange={(value) => onRangeChange(value as Range)}><SelectTrigger className="w-40"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="7d">Last 7 days</SelectItem><SelectItem value="30d">Last 30 days</SelectItem><SelectItem value="90d">Last 90 days</SelectItem></SelectContent></Select>
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
          {DOMAINS.map((domain) => <Link key={domain.title} to={domain.href} className="group"><Card className="h-full transition hover:border-primary/40 hover:shadow-md"><CardContent className="p-6"><div className={`inline-flex rounded-xl p-3 ${domain.color}`}><Icon icon={domain.icon} className="h-6 w-6" /></div><h3 className="mt-4 font-semibold">{domain.title}</h3><p className="mt-1 text-sm text-muted-foreground">{domain.description}</p><span className="mt-5 inline-flex items-center text-sm font-medium text-primary">Generate report <Icon icon="solar:arrow-right-linear" className="ml-1 h-4 w-4 transition group-hover:translate-x-1" /></span></CardContent></Card></Link>)}
        </div>
      </TabsContent>

      <TabsContent value="custom" className="space-y-5">
        <div><h2 className="text-xl font-semibold">Custom report builder</h2><p className="text-sm text-muted-foreground">Choose the measures, period and visualization for a tailored report.</p></div>
        <div className="grid gap-5 lg:grid-cols-[360px_1fr]">
          <Card><CardHeader><CardTitle className="text-base">Report configuration</CardTitle></CardHeader><CardContent className="space-y-5"><div className="space-y-2"><Label>Reporting period</Label><Select value={range} onValueChange={(value) => onRangeChange(value as Range)}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="7d">Last 7 days</SelectItem><SelectItem value="30d">Last 30 days</SelectItem><SelectItem value="90d">Last 90 days</SelectItem></SelectContent></Select></div><div className="space-y-3"><Label>Metrics</Label>{METRICS.map((metric) => <label key={metric.key} className="flex cursor-pointer items-center gap-3 rounded-md border p-3"><Checkbox checked={selectedMetrics.includes(metric.key)} onCheckedChange={() => toggleMetric(metric.key)} /><span className="text-sm">{metric.label}</span></label>)}</div><div className="space-y-2"><Label>Visualization</Label><Select value={chartType} onValueChange={(value) => setChartType(value as ChartType)}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="bar">Bar chart</SelectItem><SelectItem value="pie">Pie chart</SelectItem></SelectContent></Select></div><Button className="w-full" disabled={!selectedMetrics.length} onClick={() => setGenerated({ metrics: selectedMetrics, chart: chartType })}><Icon icon="solar:chart-square-bold-duotone" className="mr-2 h-4 w-4" />Generate custom report</Button></CardContent></Card>
          <Card><CardHeader className="flex-row items-center justify-between"><div><CardTitle className="text-base">Generated report</CardTitle><p className="mt-1 text-sm text-muted-foreground">{generated ? `${generated.metrics.length} metrics · ${range}` : 'Configure and generate a report to preview it.'}</p></div>{generated && <div className="flex gap-2"><HeaderActionButton variant="outline" icon="solar:file-text-bold-duotone" onClick={exportCustomXlsx}>Excel</HeaderActionButton><HeaderActionButton variant="outline" icon="solar:document-text-bold-duotone" onClick={exportCustomDocx}>Word</HeaderActionButton></div>}</CardHeader><CardContent>{!generated ? <div className="flex h-96 flex-col items-center justify-center rounded-lg border border-dashed text-center"><Icon icon="solar:chart-2-bold-duotone" className="h-12 w-12 text-muted-foreground" /><p className="mt-3 font-medium">No custom report generated</p><p className="text-sm text-muted-foreground">Select metrics and a visualization to begin.</p></div> : <div className="h-96"><ResponsiveContainer width="100%" height="100%">{generated.chart === 'bar' ? <BarChart data={customData}><CartesianGrid strokeDasharray="3 3" vertical={false} /><XAxis dataKey="name" tick={{ fontSize: 11 }} /><YAxis /><Tooltip /><Bar dataKey="value" fill="#0ea5e9" radius={[6, 6, 0, 0]} /></BarChart> : <PieChart><Pie data={customData} dataKey="value" nameKey="name" outerRadius={130} label>{customData.map((_, index) => <Cell key={index} fill={COLORS[index % COLORS.length]} />)}</Pie><Tooltip /><Legend /></PieChart>}</ResponsiveContainer></div>}</CardContent></Card>
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
