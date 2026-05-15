import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { closeTasksApi, closeCyclesApi, readinessApi, type CloseCycle, type CloseTask, type ReadinessAccount, type ReadinessSummary } from '@/services/closeApi'

const ENTITY_ID = 'entity-001'
const PERIOD_ID  = '2026-04'

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
      className={onClick ? 'cursor-pointer border-slate-200/80 transition-all hover:-translate-y-0.5 hover:shadow-md dark:border-slate-800' : 'border-slate-200/80 dark:border-slate-800'}
      onClick={onClick}
    >
      <CardContent className="pt-5 pb-4">
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="text-3xl font-bold mt-1">{value}</p>
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

const cycleStatusColor: Record<string, string> = {
  draft:          'secondary',
  opened:         'default',
  ready_to_close: 'warning',
  closed:         'success',
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

  const { data: myTasks = [] } = useQuery<CloseTask[]>({
    queryKey: ['my-tasks'],
    queryFn: () => closeTasksApi.myTasks().then(r => r.data.data),
  })

  const { data: cycles = [] } = useQuery<CloseCycle[]>({
    queryKey: ['close-cycles', ENTITY_ID],
    queryFn: () => closeCyclesApi.list(ENTITY_ID).then(r => r.data.data),
  })

  const { data: readiness } = useQuery<ReadinessSummary>({
    queryKey: ['readiness', ENTITY_ID, PERIOD_ID],
    queryFn: () => readinessApi.get(ENTITY_ID, PERIOD_ID).then(r => r.data.data),
  })

  const now = new Date()
  const overdue = myTasks.filter(t => t.due_date && new Date(t.due_date) < now && t.status !== 'completed' && t.status !== 'cancelled')
  const blocked = myTasks.filter(t => t.status === 'blocked')
  const activeCycles = cycles.filter(c => c.status === 'opened' || c.status === 'ready_to_close')
  const attentionAccounts = [...(readiness?.accounts ?? [])].sort(attentionSort).slice(0, 3)
  const signedOffAccounts = readiness?.signed_off_accounts ?? 0
  const totalAccounts = readiness?.total_accounts ?? 0
  const openBlockers = readiness?.open_blockers ?? 0
  const openFindings = readiness?.open_findings ?? 0

  return (
    <div className="space-y-8">
      <Card className="overflow-hidden border-slate-200/80 bg-[radial-gradient(circle_at_top_left,_rgba(251,191,36,0.16),_transparent_35%),linear-gradient(135deg,_rgba(255,255,255,1),_rgba(248,250,252,0.95),_rgba(254,242,242,0.85))] dark:border-slate-800 dark:bg-[radial-gradient(circle_at_top_left,_rgba(251,191,36,0.12),_transparent_35%),linear-gradient(135deg,_rgba(2,6,23,1),_rgba(15,23,42,0.96),_rgba(24,24,27,0.96))]">
        <CardContent className="pt-6 pb-6">
          <div className="flex items-start justify-between gap-6 flex-wrap">
            <div className="space-y-4 max-w-2xl">
              <div>
                <h1 className="text-3xl font-bold">Financial Close</h1>
                <p className="text-muted-foreground mt-1">Command dashboard for {ENTITY_ID} — {PERIOD_ID}</p>
              </div>
              <div className="flex flex-wrap gap-2">
                <Badge variant="outline" className="px-3 py-1">{activeCycles.length} active cycle{activeCycles.length !== 1 ? 's' : ''}</Badge>
                <Badge variant="outline" className="px-3 py-1">{openFindings} open findings</Badge>
                <Badge variant="outline" className="px-3 py-1">{blocked.length} blocked task{blocked.length !== 1 ? 's' : ''}</Badge>
              </div>
              <p className="text-sm text-muted-foreground">
                Use this view as the demo launchpad: jump into readiness, walk the queue, and open AI findings from the accounts that need attention most.
              </p>
            </div>

            <div className="flex flex-wrap gap-2">
              <Button onClick={() => navigate('/financial-close/readiness')}>
                <Icon icon="solar:shield-check-bold-duotone" className="mr-2 h-4 w-4" />
                Open Readiness Cockpit
              </Button>
              <Button variant="outline" onClick={() => navigate('/financial-close/findings')}>
                <Icon icon="solar:magic-stick-3-bold-duotone" className="mr-2 h-4 w-4" />
                Open AI Findings
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

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
          onClick={() => navigate('/financial-close/tasks')}
        />
        <KpiCard
          label="Blocked Tasks"
          value={blocked.length}
          icon="solar:lock-keyhole-bold-duotone"
          iconClass={blocked.length > 0 ? 'bg-orange-500/10 text-orange-500' : 'bg-primary/10 text-primary'}
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
          onClick={() => navigate('/financial-close/readiness')}
        />
        <KpiCard
          label="Open Blockers"
          value={openBlockers}
          icon="solar:danger-bold-duotone"
          iconClass={openBlockers > 0 ? 'bg-red-500/10 text-red-500' : 'bg-primary/10 text-primary'}
          hint="Pivot into tasks and approvals"
          onClick={() => navigate('/financial-close/tasks')}
        />
        <KpiCard
          label="Open AI Findings"
          value={openFindings}
          icon="solar:magic-stick-3-bold-duotone"
          iconClass="bg-yellow-500/10 text-yellow-500"
          hint="Presentation-ready anomaly list"
          onClick={() => navigate('/financial-close/findings')}
        />
        <KpiCard
          label="Accounts Signed Off"
          value={`${signedOffAccounts} / ${totalAccounts}`}
          icon="solar:clipboard-check-bold-duotone"
          iconClass="bg-green-500/10 text-green-500"
          hint="Open account coverage"
          onClick={() => navigate('/financial-close/readiness')}
        />
      </div>

      <div className="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
        <Card className="border-slate-200/80 dark:border-slate-800">
          <CardHeader className="pb-3">
            <div className="flex items-center justify-between">
              <CardTitle className="text-base font-semibold">Active Cycles</CardTitle>
              <Button variant="ghost" size="sm" onClick={() => navigate('/financial-close/cycles')}>
                View All
                <Icon icon="solar:arrow-right-bold" className="ml-1.5 h-4 w-4" />
              </Button>
            </div>
          </CardHeader>
          <CardContent>
            {activeCycles.length === 0 ? (
              <p className="text-sm text-muted-foreground py-4 text-center">No active cycles.</p>
            ) : (
              <div className="space-y-3">
                {activeCycles.map(c => (
                  <div key={c.id} className="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-800">
                    <div className="flex items-center justify-between gap-3 flex-wrap">
                      <div>
                        <p className="font-medium text-sm">{c.entity_id} — {c.period_id}</p>
                        {c.opened_at && (
                          <p className="text-xs text-muted-foreground mt-1">
                            Opened {new Date(c.opened_at).toLocaleDateString()}
                          </p>
                        )}
                      </div>
                      <Badge variant="outline" className={cycleBadgeClass(c.status)}>
                        {cycleStatusLabel[c.status]}
                      </Badge>
                    </div>
                    <div className="flex items-center gap-2 mt-4">
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => navigate(`/financial-close/tasks?cycleId=${c.id}`)}
                      >
                        Tasks
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => navigate(`/financial-close/forecast?cycleId=${c.id}`)}
                      >
                        Forecast
                      </Button>
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
              <Button variant="ghost" size="sm" onClick={() => navigate('/financial-close/readiness')}>
                Open Cockpit
              </Button>
            </div>
          </CardHeader>
          <CardContent>
            {attentionAccounts.length === 0 ? (
              <p className="text-sm text-muted-foreground py-4 text-center">Refresh readiness to generate account coverage.</p>
            ) : (
              <div className="space-y-3">
                {attentionAccounts.map(account => (
                  <div key={account.review_id} className="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-800">
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <p className="text-sm font-semibold">{account.account_code} · {account.account_name}</p>
                        <p className="text-xs text-muted-foreground mt-1">
                          {account.open_findings} finding{account.open_findings !== 1 ? 's' : ''} · {account.score}% coverage
                        </p>
                      </div>
                      <span className={`text-sm font-semibold ${account.open_findings > 0 ? 'text-rose-500' : 'text-slate-500'}`}>
                        {account.open_findings > 0 ? 'Needs review' : 'On track'}
                      </span>
                    </div>
                    <div className="flex gap-2 mt-4">
                      <Button
                        size="sm"
                        onClick={() => navigate(account.open_findings > 0 ? `/financial-close/findings?reviewId=${account.review_id}` : '/financial-close/readiness')}
                      >
                        {account.open_findings > 0 ? 'Open Findings' : 'Open Cockpit'}
                      </Button>
                      <Button size="sm" variant="outline" onClick={() => navigate('/financial-close/readiness')}>
                        Coverage
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Quick Nav */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        {[
          { label: 'My Tasks',        href: '/financial-close/tasks',        icon: 'solar:list-check-bold-duotone' },
          { label: 'Review Queue',    href: '/financial-close/review-queue', icon: 'solar:inbox-bold-duotone' },
          { label: 'Readiness',       href: '/financial-close/readiness',    icon: 'solar:shield-check-bold-duotone' },
          { label: 'Executive View',  href: '/financial-close/executive',    icon: 'solar:chart-bold-duotone' },
        ].map(item => (
          <Button
            key={item.href}
            variant="outline"
            className="h-auto rounded-2xl border-slate-200/80 py-4 flex-col gap-2 transition-all hover:-translate-y-0.5 hover:shadow-md dark:border-slate-800"
            onClick={() => navigate(item.href)}
          >
            <Icon icon={item.icon} className="h-6 w-6" />
            <span className="text-sm font-medium">{item.label}</span>
          </Button>
        ))}
      </div>
    </div>
  )
}
