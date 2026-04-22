import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { closeTasksApi, closeCyclesApi, readinessApi, findingsApi, type CloseCycle, type CloseTask } from '@/services/closeApi'

const ENTITY_ID = 'entity-001'
const PERIOD_ID  = '2026-04'

function KpiCard({
  label,
  value,
  icon,
  iconClass,
  onClick,
}: {
  label: string
  value: string | number
  icon: string
  iconClass?: string
  onClick?: () => void
}) {
  return (
    <Card
      className={onClick ? 'cursor-pointer hover:shadow-md transition-shadow' : ''}
      onClick={onClick}
    >
      <CardContent className="pt-5 pb-4">
        <div className="flex items-start justify-between">
          <div>
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="text-3xl font-bold mt-1">{value}</p>
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

  const { data: readiness } = useQuery({
    queryKey: ['readiness', ENTITY_ID, PERIOD_ID],
    queryFn: () => readinessApi.get(ENTITY_ID, PERIOD_ID).then(r => r.data.data),
  })

  const now = new Date()
  const overdue = myTasks.filter(t => t.due_date && new Date(t.due_date) < now && t.status !== 'completed' && t.status !== 'cancelled')
  const blocked = myTasks.filter(t => t.status === 'blocked')
  const activeCycles = cycles.filter(c => c.status === 'opened' || c.status === 'ready_to_close')

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-3xl font-bold">Financial Close</h1>
        <p className="text-muted-foreground mt-1">Close orchestration dashboard — {PERIOD_ID}</p>
      </div>

      {/* KPI row */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KpiCard
          label="My Open Tasks"
          value={myTasks.filter(t => t.status !== 'completed' && t.status !== 'cancelled').length}
          icon="solar:list-check-bold-duotone"
          iconClass="bg-blue-500/10 text-blue-500"
          onClick={() => navigate('/financial-close/tasks')}
        />
        <KpiCard
          label="Overdue Tasks"
          value={overdue.length}
          icon="solar:alarm-bold-duotone"
          iconClass={overdue.length > 0 ? 'bg-red-500/10 text-red-500' : 'bg-primary/10 text-primary'}
          onClick={() => navigate('/financial-close/tasks')}
        />
        <KpiCard
          label="Blocked Tasks"
          value={blocked.length}
          icon="solar:lock-keyhole-bold-duotone"
          iconClass={blocked.length > 0 ? 'bg-orange-500/10 text-orange-500' : 'bg-primary/10 text-primary'}
          onClick={() => navigate('/financial-close/tasks')}
        />
        <KpiCard
          label="Active Cycles"
          value={activeCycles.length}
          icon="solar:calendar-bold-duotone"
          iconClass="bg-violet-500/10 text-violet-500"
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
          onClick={() => navigate('/financial-close/readiness')}
        />
        <KpiCard
          label="Open Blockers"
          value={readiness?.open_blockers ?? '—'}
          icon="solar:danger-bold-duotone"
          iconClass={(readiness?.open_blockers ?? 0) > 0 ? 'bg-red-500/10 text-red-500' : 'bg-primary/10 text-primary'}
          onClick={() => navigate('/financial-close/readiness')}
        />
        <KpiCard
          label="Open AI Findings"
          value={readiness?.open_findings ?? '—'}
          icon="solar:magic-stick-3-bold-duotone"
          iconClass="bg-yellow-500/10 text-yellow-500"
          onClick={() => navigate('/financial-close/findings')}
        />
        <KpiCard
          label="Accounts Signed Off"
          value={readiness ? `${readiness.signed_off_accounts} / ${readiness.total_accounts}` : '—'}
          icon="solar:clipboard-check-bold-duotone"
          iconClass="bg-green-500/10 text-green-500"
          onClick={() => navigate('/financial-close/readiness')}
        />
      </div>

      {/* Active Cycles */}
      <Card>
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
            <div className="divide-y">
              {activeCycles.map(c => (
                <div key={c.id} className="flex items-center justify-between py-3">
                  <div>
                    <p className="font-medium text-sm">{c.entity_id} — {c.period_id}</p>
                    {c.opened_at && (
                      <p className="text-xs text-muted-foreground">
                        Opened {new Date(c.opened_at).toLocaleDateString()}
                      </p>
                    )}
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge variant={cycleStatusColor[c.status] as 'default' | 'secondary' | 'destructive' | 'outline'}>
                      {cycleStatusLabel[c.status]}
                    </Badge>
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
            className="h-auto py-4 flex-col gap-2"
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
