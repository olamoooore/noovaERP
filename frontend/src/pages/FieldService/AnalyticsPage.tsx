import { useState, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  BarChart, Bar, LineChart, Line, PieChart, Pie, Cell,
  XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer,
} from 'recharts'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import fieldServiceApi, { type WorkOrderStatus } from '@/lib/fieldServiceApi'

// ─── Helpers ─────────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
  draft: '#94a3b8',
  scheduled: '#60a5fa',
  in_progress: '#f59e0b',
  on_hold: '#f97316',
  completed: '#22c55e',
  invoiced: '#8b5cf6',
  cancelled: '#ef4444',
}

const PIE_COLORS = ['#60a5fa', '#22c55e', '#f59e0b', '#8b5cf6', '#ef4444', '#f97316', '#94a3b8']

function kpiFormat(n: number) {
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`
  if (n >= 1_000) return `${(n / 1_000).toFixed(1)}K`
  return n.toString()
}

function getDefaultDates() {
  const to = new Date()
  const from = new Date()
  from.setDate(from.getDate() - 30)
  return {
    from: from.toISOString().slice(0, 10),
    to: to.toISOString().slice(0, 10),
  }
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function KpiCard({ label, value, icon, sub }: { label: string; value: string | number; icon: string; sub?: string }) {
  return (
    <Card>
      <CardContent className="p-5 flex items-start gap-3">
        <div className="p-2 rounded-lg bg-primary/10 flex-shrink-0">
          <Icon icon={icon} width={22} className="text-primary" />
        </div>
        <div className="min-w-0">
          <p className="text-xs text-muted-foreground">{label}</p>
          <p className="text-2xl font-bold leading-tight">{value}</p>
          {sub && <p className="text-xs text-muted-foreground mt-0.5">{sub}</p>}
        </div>
      </CardContent>
    </Card>
  )
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export function AnalyticsPage() {
  const defaults = useMemo(() => getDefaultDates(), [])
  const [dateFrom, setDateFrom] = useState(defaults.from)
  const [dateTo, setDateTo] = useState(defaults.to)
  const [technicianId, setTechnicianId] = useState<string>('all')
  const [typeId, setTypeId] = useState<string>('all')
  const [statusFilter, setStatusFilter] = useState<string>('all')
  const [priorityFilter, setPriorityFilter] = useState<string>('all')
  const [jobPage, setJobPage] = useState(1)

  const { data: dashboard } = useQuery({
    queryKey: ['fs-dashboard'],
    queryFn: () => fieldServiceApi.getDashboard().then(r => r.data),
  })

  const { data: revenue } = useQuery({
    queryKey: ['fs-revenue', dateFrom, dateTo],
    queryFn: () => fieldServiceApi.getRevenue(dateFrom, dateTo).then(r => r.data),
  })

  const { data: techPerf } = useQuery({
    queryKey: ['fs-tech-perf', dateFrom, dateTo],
    queryFn: () => fieldServiceApi.getTechnicianPerformance(dateFrom, dateTo).then(r => r.data),
  })

  const jobFilters = useMemo(() => {
    const f: Record<string, string | number> = {
      date_from: dateFrom,
      date_to: dateTo,
      page: jobPage,
      per_page: 20,
    }
    if (technicianId !== 'all') f.technician_id = Number(technicianId)
    if (typeId !== 'all') f.type_id = Number(typeId)
    if (statusFilter !== 'all') f.status = statusFilter
    if (priorityFilter !== 'all') f.priority = priorityFilter
    return f
  }, [dateFrom, dateTo, technicianId, typeId, statusFilter, priorityFilter, jobPage])

  const { data: jobsData, isFetching: jobsFetching } = useQuery({
    queryKey: ['fs-jobs-analysis', jobFilters],
    queryFn: () => fieldServiceApi.getJobsAnalysis(jobFilters as any).then(r => r.data),
  })

  const { data: technicians } = useQuery({
    queryKey: ['fs-technicians'],
    queryFn: () => fieldServiceApi.getTechnicians().then(r => r.data),
  })

  const { data: workOrderTypes } = useQuery({
    queryKey: ['fs-work-order-types'],
    queryFn: () => fieldServiceApi.getWorkOrderTypes().then(r => r.data),
  })

  const handleExport = () => {
    const url = fieldServiceApi.exportJobsCsvUrl({
      date_from: dateFrom,
      date_to: dateTo,
      ...(technicianId !== 'all' && { technician_id: Number(technicianId) }),
      ...(typeId !== 'all' && { type_id: Number(typeId) }),
      ...(statusFilter !== 'all' && { status: statusFilter as WorkOrderStatus }),
    })
    window.location.href = url
  }

  // Build status pie data
  const statusPieData = useMemo(() => {
    if (!jobsData?.summary?.by_status) return []
    return Object.entries(jobsData.summary.by_status).map(([status, count]) => ({
      name: status.replace(/_/g, ' '),
      value: count as number,
    }))
  }, [jobsData])

  // Build type bar data
  const typePieData = useMemo(() => {
    if (!jobsData?.summary?.by_type) return []
    return Object.entries(jobsData.summary.by_type).map(([type, count]) => ({
      name: type,
      value: count as number,
    }))
  }, [jobsData])

  const revenueDailyData = useMemo(() => {
    if (!revenue?.daily) return []
    return revenue.daily as { date: string; revenue: number; jobs: number }[]
  }, [revenue])

  const techPerfData = useMemo(() => {
    if (!Array.isArray(techPerf)) return []
    return techPerf as { name: string; completed: number; avg_hours: number; revenue: number; rating: number }[]
  }, [techPerf])

  return (
    <div className="p-6 space-y-5 max-w-7xl mx-auto">
      {/* Header */}
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-2xl font-bold">Field Service Analytics</h1>
          <p className="text-sm text-muted-foreground mt-0.5">Performance insights and reporting</p>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          <Input
            type="date"
            value={dateFrom}
            onChange={e => { setDateFrom(e.target.value); setJobPage(1) }}
            className="w-36 text-sm"
          />
          <span className="text-muted-foreground text-sm">to</span>
          <Input
            type="date"
            value={dateTo}
            onChange={e => { setDateTo(e.target.value); setJobPage(1) }}
            className="w-36 text-sm"
          />
          <Button variant="outline" size="sm" onClick={handleExport}>
            <Icon icon="solar:export-bold-duotone" width={16} className="mr-1.5" />
            Export CSV
          </Button>
        </div>
      </div>

      <Tabs defaultValue="overview">
        <TabsList>
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="jobs">Jobs Analysis</TabsTrigger>
          <TabsTrigger value="performance">Technician Performance</TabsTrigger>
          <TabsTrigger value="revenue">Revenue</TabsTrigger>
        </TabsList>

        {/* ── OVERVIEW ── */}
        <TabsContent value="overview" className="mt-4 space-y-5">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <KpiCard
              label="Total Work Orders"
              value={kpiFormat(dashboard?.total_work_orders ?? 0)}
              icon="solar:clipboard-list-bold-duotone"
            />
            <KpiCard
              label="Completed Today"
              value={dashboard?.completed_today ?? 0}
              icon="solar:check-circle-bold-duotone"
            />
            <KpiCard
              label="In Progress"
              value={dashboard?.in_progress ?? 0}
              icon="solar:play-circle-bold-duotone"
            />
            <KpiCard
              label="Avg Completion (hrs)"
              value={typeof dashboard?.avg_completion_hours === 'number'
                ? dashboard.avg_completion_hours.toFixed(1)
                : '—'}
              icon="solar:clock-circle-bold-duotone"
            />
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            {/* Status distribution */}
            <Card>
              <CardHeader className="pb-2"><CardTitle className="text-sm">By Status</CardTitle></CardHeader>
              <CardContent>
                {statusPieData.length > 0 ? (
                  <ResponsiveContainer width="100%" height={220}>
                    <PieChart>
                      <Pie data={statusPieData} dataKey="value" nameKey="name" cx="50%" cy="50%" outerRadius={80}>
                        {statusPieData.map((entry, i) => (
                          <Cell key={entry.name} fill={STATUS_COLORS[entry.name.replace(/ /g, '_')] ?? PIE_COLORS[i % PIE_COLORS.length]} />
                        ))}
                      </Pie>
                      <Tooltip />
                    </PieChart>
                  </ResponsiveContainer>
                ) : (
                  <div className="h-[220px] flex items-center justify-center text-muted-foreground text-sm">No data</div>
                )}
              </CardContent>
            </Card>

            {/* By type */}
            <Card>
              <CardHeader className="pb-2"><CardTitle className="text-sm">By Job Type</CardTitle></CardHeader>
              <CardContent>
                {typePieData.length > 0 ? (
                  <ResponsiveContainer width="100%" height={220}>
                    <BarChart data={typePieData} layout="vertical" margin={{ left: 8, right: 16 }}>
                      <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                      <XAxis type="number" tick={{ fontSize: 11 }} />
                      <YAxis type="category" dataKey="name" tick={{ fontSize: 11 }} width={100} />
                      <Tooltip />
                      <Bar dataKey="value" fill="#60a5fa" radius={[0, 4, 4, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                ) : (
                  <div className="h-[220px] flex items-center justify-center text-muted-foreground text-sm">No data</div>
                )}
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        {/* ── JOBS ANALYSIS ── */}
        <TabsContent value="jobs" className="mt-4 space-y-4">
          {/* Filters */}
          <div className="flex flex-wrap gap-2">
            <Select value={statusFilter} onValueChange={v => { setStatusFilter(v); setJobPage(1) }}>
              <SelectTrigger className="w-36 text-sm"><SelectValue placeholder="Status" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                {(['draft','scheduled','in_progress','on_hold','completed','invoiced','cancelled'] as WorkOrderStatus[]).map(s => (
                  <SelectItem key={s} value={s}>{s.replace(/_/g, ' ')}</SelectItem>
                ))}
              </SelectContent>
            </Select>

            <Select value={priorityFilter} onValueChange={v => { setPriorityFilter(v); setJobPage(1) }}>
              <SelectTrigger className="w-32 text-sm"><SelectValue placeholder="Priority" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Priorities</SelectItem>
                {['low','normal','high','urgent'].map(p => (
                  <SelectItem key={p} value={p}>{p}</SelectItem>
                ))}
              </SelectContent>
            </Select>

            <Select value={typeId} onValueChange={v => { setTypeId(v); setJobPage(1) }}>
              <SelectTrigger className="w-40 text-sm"><SelectValue placeholder="Type" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Types</SelectItem>
                {(workOrderTypes ?? []).map((t: any) => (
                  <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>

            <Select value={technicianId} onValueChange={v => { setTechnicianId(v); setJobPage(1) }}>
              <SelectTrigger className="w-44 text-sm"><SelectValue placeholder="Technician" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Technicians</SelectItem>
                {(technicians ?? []).map((t: any) => (
                  <SelectItem key={t.id} value={String(t.id)}>{t.user?.name ?? `Technician ${t.id}`}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Summary cards */}
          {jobsData?.summary && (
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <KpiCard label="Total Jobs" value={jobsData.summary.total} icon="solar:clipboard-list-bold-duotone" />
              <KpiCard label="Completed" value={jobsData.summary.completed} icon="solar:check-circle-bold-duotone" />
              <KpiCard label="Invoiced" value={jobsData.summary.invoiced} icon="solar:bill-list-bold-duotone" />
              <KpiCard
                label="Revenue"
                value={`$${kpiFormat(jobsData.summary.revenue ?? 0)}`}
                icon="solar:dollar-minimalistic-bold-duotone"
              />
            </div>
          )}

          {/* Table */}
          <Card>
            <CardContent className="p-0 overflow-auto">
              <table className="w-full text-sm">
                <thead className="border-b bg-muted/40">
                  <tr>
                    {['Reference', 'Title', 'Status', 'Priority', 'Type', 'Technician', 'Scheduled', 'Total'].map(h => (
                      <th key={h} className="text-left px-4 py-2.5 font-medium text-muted-foreground whitespace-nowrap">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {jobsFetching ? (
                    <tr><td colSpan={8} className="text-center py-8 text-muted-foreground">Loading…</td></tr>
                  ) : (jobsData?.data ?? []).length === 0 ? (
                    <tr><td colSpan={8} className="text-center py-8 text-muted-foreground">No jobs found</td></tr>
                  ) : (
                    (jobsData!.data as any[]).map((wo: any) => (
                      <tr key={wo.id} className="border-b hover:bg-muted/20 transition-colors">
                        <td className="px-4 py-2.5 font-mono text-xs text-muted-foreground">{wo.reference}</td>
                        <td className="px-4 py-2.5 font-medium max-w-[180px] truncate">{wo.title}</td>
                        <td className="px-4 py-2.5">
                          <Badge
                            variant="secondary"
                            style={{ background: STATUS_COLORS[wo.status] + '22', color: STATUS_COLORS[wo.status], border: `1px solid ${STATUS_COLORS[wo.status]}44` }}
                          >
                            {wo.status.replace(/_/g, ' ')}
                          </Badge>
                        </td>
                        <td className="px-4 py-2.5 capitalize">{wo.priority}</td>
                        <td className="px-4 py-2.5">{wo.type?.name ?? '—'}</td>
                        <td className="px-4 py-2.5">{wo.assignedUser?.name ?? '—'}</td>
                        <td className="px-4 py-2.5 whitespace-nowrap text-xs">
                          {wo.scheduled_start ? new Date(wo.scheduled_start).toLocaleDateString('en-GB') : '—'}
                        </td>
                        <td className="px-4 py-2.5 tabular-nums">${Number(wo.total_amount ?? 0).toFixed(2)}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </CardContent>
          </Card>

          {/* Pagination */}
          {jobsData && (jobsData as any).last_page > 1 && (
            <div className="flex items-center justify-between text-sm text-muted-foreground">
              <span>Page {jobPage} of {(jobsData as any).last_page}</span>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" disabled={jobPage <= 1} onClick={() => setJobPage(p => p - 1)}>
                  Previous
                </Button>
                <Button variant="outline" size="sm" disabled={jobPage >= (jobsData as any).last_page} onClick={() => setJobPage(p => p + 1)}>
                  Next
                </Button>
              </div>
            </div>
          )}
        </TabsContent>

        {/* ── TECHNICIAN PERFORMANCE ── */}
        <TabsContent value="performance" className="mt-4 space-y-5">
          {techPerfData.length > 0 ? (
            <>
              <Card>
                <CardHeader className="pb-2"><CardTitle className="text-sm">Jobs Completed per Technician</CardTitle></CardHeader>
                <CardContent>
                  <ResponsiveContainer width="100%" height={300}>
                    <BarChart data={techPerfData} margin={{ left: 0, right: 16 }}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} />
                      <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                      <YAxis tick={{ fontSize: 11 }} />
                      <Tooltip />
                      <Bar dataKey="completed" name="Completed" fill="#22c55e" radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </CardContent>
              </Card>

              <Card>
                <CardHeader className="pb-2"><CardTitle className="text-sm">Avg Hours per Job</CardTitle></CardHeader>
                <CardContent>
                  <ResponsiveContainer width="100%" height={300}>
                    <BarChart data={techPerfData} margin={{ left: 0, right: 16 }}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} />
                      <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                      <YAxis tick={{ fontSize: 11 }} />
                      <Tooltip formatter={(v: unknown) => [`${(v as number).toFixed(1)}h`, 'Avg Hours']} />
                      <Bar dataKey="avg_hours" name="Avg Hours" fill="#60a5fa" radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </CardContent>
              </Card>

              <Card>
                <CardContent className="p-0 overflow-auto">
                  <table className="w-full text-sm">
                    <thead className="border-b bg-muted/40">
                      <tr>
                        {['Technician', 'Completed', 'Avg Hours', 'Revenue', 'Avg Rating'].map(h => (
                          <th key={h} className="text-left px-4 py-2.5 font-medium text-muted-foreground">{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {techPerfData.map((t, i) => (
                        <tr key={i} className="border-b hover:bg-muted/20">
                          <td className="px-4 py-2.5 font-medium">{t.name}</td>
                          <td className="px-4 py-2.5">{t.completed}</td>
                          <td className="px-4 py-2.5">{t.avg_hours?.toFixed(1) ?? '—'}h</td>
                          <td className="px-4 py-2.5">${Number(t.revenue ?? 0).toFixed(2)}</td>
                          <td className="px-4 py-2.5">
                            {t.rating ? (
                              <span className="flex items-center gap-1">
                                <Icon icon="solar:star-bold" width={14} className="text-amber-500" />
                                {t.rating.toFixed(1)}
                              </span>
                            ) : '—'}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </CardContent>
              </Card>
            </>
          ) : (
            <div className="flex flex-col items-center justify-center h-64 text-muted-foreground">
              <Icon icon="solar:chart-square-bold-duotone" width={48} className="opacity-30 mb-3" />
              <p>No technician performance data for this period.</p>
            </div>
          )}
        </TabsContent>

        {/* ── REVENUE ── */}
        <TabsContent value="revenue" className="mt-4 space-y-5">
          {revenueDailyData.length > 0 ? (
            <>
              <Card>
                <CardHeader className="pb-2"><CardTitle className="text-sm">Daily Revenue</CardTitle></CardHeader>
                <CardContent>
                  <ResponsiveContainer width="100%" height={300}>
                    <LineChart data={revenueDailyData} margin={{ left: 0, right: 16 }}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} />
                      <XAxis dataKey="date" tick={{ fontSize: 11 }} tickFormatter={d => new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })} />
                      <YAxis tick={{ fontSize: 11 }} tickFormatter={v => `$${kpiFormat(v)}`} />
                      <Tooltip formatter={(v: unknown) => [`$${(v as number).toFixed(2)}`, 'Revenue']} />
                      <Line type="monotone" dataKey="revenue" stroke="#8b5cf6" strokeWidth={2} dot={false} />
                    </LineChart>
                  </ResponsiveContainer>
                </CardContent>
              </Card>

              <Card>
                <CardHeader className="pb-2"><CardTitle className="text-sm">Daily Jobs Completed</CardTitle></CardHeader>
                <CardContent>
                  <ResponsiveContainer width="100%" height={240}>
                    <BarChart data={revenueDailyData} margin={{ left: 0, right: 16 }}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} />
                      <XAxis dataKey="date" tick={{ fontSize: 11 }} tickFormatter={d => new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })} />
                      <YAxis tick={{ fontSize: 11 }} allowDecimals={false} />
                      <Tooltip labelFormatter={l => new Date(l).toLocaleDateString('en-GB')} />
                      <Bar dataKey="jobs" name="Jobs" fill="#22c55e" radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </CardContent>
              </Card>
            </>
          ) : (
            <div className="flex flex-col items-center justify-center h-64 text-muted-foreground">
              <Icon icon="solar:dollar-minimalistic-bold-duotone" width={48} className="opacity-30 mb-3" />
              <p>No revenue data for this period.</p>
            </div>
          )}
        </TabsContent>
      </Tabs>
    </div>
  )
}

export default AnalyticsPage
