import { useQuery } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { cn } from '@/lib/utils'
import { closeTasksApi, closeCyclesApi, readinessApi, type CloseCycle, type CloseTask, type ReadinessAccount, type ReadinessSummary } from '@/services/closeApi'

function KpiCard({
  label,
  value,
  icon,
  iconClass,
  hint,
  onClick,
}: {
  label: string
  value: string | number
  icon: string
  iconClass?: string
  hint?: string
  onClick?: () => void
}) {
  return (
    <Card
      className={onClick ? 'cursor-pointer rounded-xl border-slate-200/80 transition-all hover:-translate-y-0.5 hover:shadow-md dark:border-slate-800' : 'rounded-xl border-slate-200/80 dark:border-slate-800'}
      onClick={onClick}
    >
      <CardContent className="pt-5 pb-4">
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="mt-1 text-3xl font-bold tabular-nums">{value}</p>
            {hint && <p className="text-xs text-muted-foreground mt-2">{hint}</p>}
          </div>
          <div className={`p-2.5 rounded-xl ${iconClass ?? 'bg-primary/10 text-primary'}`}>
            <Icon icon={icon} className="h-6 w-6" />
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

const cycleStatusLabel: Record<string, string> = {
  draft:          'Draft',
  opened:         'In Progress',
  ready_to_close: 'Ready to Close',
  closed:         'Closed',
}

function attentionSort(left: ReadinessAccount, right: ReadinessAccount) {
  if (right.open_findings !== left.open_findings) {
    return right.open_findings - left.open_findings
  }

  return left.score - right.score
}

function cycleBadgeClass(status: string) {
  switch (status) {
    case 'ready_to_close':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300'
    case 'opened':
      return 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-300'
    case 'closed':
      return 'border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200'
    default:
      return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300'
  }
}

export function CloseDashboard() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const entityId = params.get('entityId') ?? 'entity-001'
  const periodId = params.get('periodId') ?? '2026-04'

  const { data: entityOptions } = useQuery({
    queryKey: ['readiness-entities'],
    queryFn: () => readinessApi.entities().then(r => r.data.data),
  })

  const { data: myTasks = [] } = useQuery<CloseTask[]>({
    queryKey: ['my-tasks'],
    queryFn: () => closeTasksApi.myTasks().then(r => r.data.data),
  })

  const { data: cycles = [] } = useQuery<CloseCycle[]>({
    queryKey: ['close-cycles'],
    queryFn: () => closeCyclesApi.list().then(r => r.data.data),
  })

  const activeCycles = cycles.filter(c => c.status === 'opened' || c.status === 'ready_to_close')
  const matchCycle = activeCycles.find(c => c.entity_id === entityId && c.period_id === periodId)

  function updateParam(key: string, value: string) {
    const next = new URLSearchParams(params)
    next.set(key, value)
    setParams(next, { replace: true })
  }

  const { data: readiness } = useQuery<ReadinessSummary>({
    queryKey: ['readiness', entityId, periodId, matchCycle?.id],
    queryFn: () => readinessApi.get(entityId, periodId, matchCycle?.id).then(r => r.data.data),
  })

  const now = new Date()
  const oneWeekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000)
  const overdue = myTasks.filter(t => t.status !== 'completed' && t.status !== 'cancelled' && new Date(t.created_at) < oneWeekAgo)
  const completedTasks = myTasks.filter(t => t.status === 'completed').length
  const attentionAccounts = [...(readiness?.accounts ?? [])].sort(attentionSort).slice(0, 3)
  const signedOffAccounts = readiness?.signed_off_accounts ?? 0
  const totalAccounts = readiness?.total_accounts ?? 0
  const openBlockers = readiness?.open_blockers ?? 0
  const openFindings = readiness?.open_findings ?? 0

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">Financial Close</h1>
          <p className="mt-1 max-w-2xl text-muted-foreground">
            Close command center — review readiness, manage tasks, and investigate AI-generated findings from
            accounts that need attention.
          </p>
          <div className="mt-3 flex flex-wrap items-center gap-2">
            <Select value={entityId} onValueChange={(v) => updateParam('entityId', v)}>
              <SelectTrigger className="h-8 w-[170px] rounded-full text-xs" aria-label="Entity">
                <SelectValue placeholder="Entity" />
              </SelectTrigger>
              <SelectContent>
                {(entityOptions?.entities ?? ['entity-001']).map(e => (
                  <SelectItem key={e} value={e}>{e}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Select value={periodId} onValueChange={(v) => updateParam('periodId', v)}>
              <SelectTrigger className="h-8 w-[150px] rounded-full text-xs" aria-label="Period">
                <SelectValue placeholder="Period" />
              </SelectTrigger>
              <SelectContent>
                {(entityOptions?.periods ?? ['2026-04']).map(p => (
                  <SelectItem key={p} value={p}>{p}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Badge variant="outline" className="border-slate-200 bg-slate-100 px-2.5 py-0.5 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
              {activeCycles.length} active cycle{activeCycles.length !== 1 ? 's' : ''}
            </Badge>
            {openFindings > 0 && (
              <Badge variant="outline" className="border-amber-200 bg-amber-50 px-2.5 py-0.5 text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300">
                {openFindings} open finding{openFindings !== 1 ? 's' : ''}
              </Badge>
            )}
            {openBlockers > 0 && (
              <Badge variant="outline" className="border-rose-200 bg-rose-50 px-2.5 py-0.5 text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300">
                {openBlockers} blocked task{openBlockers !== 1 ? 's' : ''}
              </Badge>
            )}
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <HeaderActionButton
            icon="solar:shield-check-bold-duotone"
            onClick={() => navigate(`/financial-close/readiness?entityId=${entityId}&periodId=${periodId}${matchCycle ? `&cycleId=${matchCycle.id}` : ''}`)}
          >
            Open Readiness Cockpit
          </HeaderActionButton>
          <HeaderActionButton variant="outline" icon="solar:calendar-bold-duotone" onClick={() => navigate('/financial-close/cycles')}>
            Close Cycles
          </HeaderActionButton>
        </div>
      </div>

      {/* KPI row */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KpiCard
          label="My Open Tasks"
          value={myTasks.filter(t => t.status !== 'completed' && t.status !== 'cancelled').length}
          icon="solar:list-check-bold-duotone"
          iconClass="bg-blue-500/10 text-blue-500"
          hint="Assigned work still in play"
          onClick={() => navigate('/financial-close/tasks')}
        />
        <KpiCard
          label="Overdue Tasks"
          value={overdue.length}
          icon="solar:alarm-bold-duotone"
          iconClass={overdue.length > 0 ? 'bg-red-500/10 text-red-500' : 'bg-primary/10 text-primary'}
          hint="Time-sensitive tasks to call out"
          onClick={() => navigate('/financial-close/tasks?status=overdue')}
        />
        <KpiCard
          label="Blocked Tasks"
          value={openBlockers}
          icon="solar:lock-keyhole-bold-duotone"
          iconClass={openBlockers > 0 ? 'bg-orange-500/10 text-orange-500' : 'bg-primary/10 text-primary'}
          hint="Direct link into the blocked queue"
          onClick={() => navigate('/financial-close/tasks?status=blocked')}
        />
        <KpiCard
          label="Active Cycles"
          value={activeCycles.length}
          icon="solar:calendar-bold-duotone"
          iconClass="bg-violet-500/10 text-violet-500"
          hint="Current period orchestration"
          onClick={() => navigate('/financial-close/cycles')}
        />
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KpiCard
          label="Readiness Score"
          value={readiness ? `${readiness.readiness_score}%` : '—'}
          icon="solar:shield-check-bold-duotone"
          iconClass={
            !readiness ? 'bg-primary/10 text-primary' :
            readiness.readiness_score >= 80 ? 'bg-green-500/10 text-green-500' :
            readiness.readiness_score >= 60 ? 'bg-yellow-500/10 text-yellow-500' :
            'bg-red-500/10 text-red-500'
          }
          hint="Open the cockpit for account detail"
          onClick={() => navigate(`/financial-close/readiness?entityId=${entityId}&periodId=${periodId}`)}
        />
        <KpiCard
          label="Open AI Findings"
          value={openFindings}
          icon="solar:magic-stick-3-bold-duotone"
          iconClass="bg-yellow-500/10 text-yellow-500"
          hint="Presentation-ready anomaly list"
          onClick={() => navigate(`/financial-close/findings?entityId=${entityId}&periodId=${periodId}&status=open`)}
        />
        <KpiCard
          label="Accounts Signed Off"
          value={`${signedOffAccounts} / ${totalAccounts}`}
          icon="solar:clipboard-check-bold-duotone"
          iconClass="bg-green-500/10 text-green-500"
          hint="Open account coverage"
          onClick={() => navigate(`/financial-close/readiness?entityId=${entityId}&periodId=${periodId}`)}
        />
        <KpiCard
          label="Completed Tasks"
          value={completedTasks}
          icon="solar:check-circle-bold-duotone"
          iconClass="bg-emerald-500/10 text-emerald-500"
          hint="Tasks you have cleared"
        />
      </div>

      <div className="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
        <Card className="border-slate-200/80 dark:border-slate-800">
          <CardHeader className="pb-3">
            <div className="flex items-center justify-between">
              <CardTitle className="text-base font-semibold">Active Cycles</CardTitle>
              <HeaderActionButton variant="ghost" icon="solar:arrow-right-linear" onClick={() => navigate('/financial-close/cycles')}>
                View All
              </HeaderActionButton>
            </div>
          </CardHeader>
          <CardContent>
            {activeCycles.length === 0 ? (
              <p className="py-4 text-center text-sm text-muted-foreground">No active cycles.</p>
            ) : (
              <div className="divide-y divide-border/60">
                {activeCycles.map(c => (
                  <div key={c.id} className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                    <div className="min-w-0">
                      <p className="text-sm font-medium">{c.entity_id} · {c.period_id}</p>
                      <p className="mt-0.5 text-xs text-muted-foreground">
                        Opened {c.opened_at ? new Date(c.opened_at).toLocaleDateString() : '—'}
                      </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                      <Badge variant="outline" className={cycleBadgeClass(c.status)}>
                        {cycleStatusLabel[c.status]}
                      </Badge>
                      <HeaderActionButton
                        variant="outline"
                        icon="solar:list-check-bold-duotone"
                        onClick={() => navigate(`/financial-close/tasks?cycleId=${c.id}`)}
                      >
                        Tasks
                      </HeaderActionButton>
                      <HeaderActionButton
                        variant="outline"
                        icon="solar:chart-2-bold-duotone"
                        onClick={() => navigate(`/financial-close/forecast?cycleId=${c.id}`)}
                      >
                        Forecast
                      </HeaderActionButton>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        <Card className="border-slate-200/80 dark:border-slate-800">
          <CardHeader className="pb-3">
            <div className="flex items-center justify-between">
              <CardTitle className="text-base font-semibold">Account Attention</CardTitle>
              <HeaderActionButton variant="ghost" icon="solar:shield-check-bold-duotone" onClick={() => navigate(`/financial-close/readiness?entityId=${entityId}&periodId=${periodId}`)}>
                Open Cockpit
              </HeaderActionButton>
            </div>
          </CardHeader>
          <CardContent>
            {attentionAccounts.length === 0 ? (
              <p className="py-4 text-center text-sm text-muted-foreground">
                Refresh readiness to generate account coverage.
              </p>
            ) : (
              <div className="divide-y divide-border/60">
                {attentionAccounts.map(account => {
                  const coverage = Math.max(0, Math.min(100, account.score))
                  const needsReview = account.open_findings > 0

                  return (
                    <div key={account.review_id} className="py-3 first:pt-0 last:pb-0">
                      <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="min-w-0">
                          <p className="text-sm font-medium">{account.account_code} · {account.account_name}</p>
                          <p className="mt-0.5 text-xs text-muted-foreground">
                            {account.open_findings} finding{account.open_findings !== 1 ? 's' : ''} · {coverage}% coverage
                          </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                          <span className={cn('text-xs font-medium', needsReview ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400')}>
                            {needsReview ? 'Needs review' : 'On track'}
                          </span>
                          <HeaderActionButton
                            variant={needsReview ? 'default' : 'outline'}
                            icon={needsReview ? 'solar:magic-stick-3-bold-duotone' : 'solar:shield-check-bold-duotone'}
                            onClick={() =>
                              navigate(
                                needsReview
                                  ? `/financial-close/findings?reviewId=${account.review_id}`
                                  : `/financial-close/readiness?entityId=${entityId}&periodId=${periodId}`,
                              )
                            }
                          >
                            {needsReview ? 'Open Findings' : 'Open Cockpit'}
                          </HeaderActionButton>
                          <HeaderActionButton
                            variant="ghost"
                            icon="solar:chart-2-bold-duotone"
                            onClick={() => navigate(`/financial-close/readiness?entityId=${entityId}&periodId=${periodId}`)}
                          >
                            Coverage
                          </HeaderActionButton>
                        </div>
                      </div>
                      <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                        <div
                          className={cn('h-full rounded-full', needsReview ? 'bg-rose-500' : 'bg-emerald-500')}
                          style={{ width: `${coverage}%` }}
                        />
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Quick nav — one row of six */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {[
          { label: 'My Tasks',       href: '/financial-close/tasks',        icon: 'solar:list-check-bold-duotone' },
          { label: 'Review Queue',   href: '/financial-close/review-queue', icon: 'solar:inbox-bold-duotone' },
          { label: 'Readiness',      href: `/financial-close/readiness?entityId=${entityId}&periodId=${periodId}`, icon: 'solar:shield-check-bold-duotone' },
          { label: 'Executive View', href: '/financial-close/executive',    icon: 'solar:chart-bold-duotone' },
          { label: 'AI Findings',    href: `/financial-close/findings?entityId=${entityId}&periodId=${periodId}&status=open`, icon: 'solar:magic-stick-3-bold-duotone' },
          { label: 'Audit Trail',    href: '/financial-close/audit-trail',  icon: 'solar:clipboard-list-bold-duotone' },
        ].map(item => (
          <button
            key={item.href}
            type="button"
            onClick={() => navigate(item.href)}
            className="flex items-center gap-3 rounded-xl border border-slate-200/80 bg-card px-3 py-2.5 text-left transition-all hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md dark:border-slate-800"
          >
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
              <Icon icon={item.icon} className="h-4 w-4" />
            </span>
            <span className="text-xs font-medium">{item.label}</span>
          </button>
        ))}
      </div>
    </div>
  )
}
