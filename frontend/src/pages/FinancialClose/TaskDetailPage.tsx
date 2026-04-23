import { useQuery } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { closeTasksApi, type CloseTask, type Escalation } from '@/services/closeApi'

const statusConfig: Record<string, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
  created: { label: 'Created', variant: 'secondary' },
  in_progress: { label: 'In Progress', variant: 'default' },
  submitted: { label: 'Submitted', variant: 'outline' },
  completed: { label: 'Completed', variant: 'outline' },
  blocked: { label: 'Blocked', variant: 'destructive' },
  cancelled: { label: 'Cancelled', variant: 'secondary' },
}

const priorityConfig: Record<string, string> = {
  low: 'text-slate-500',
  medium: 'text-yellow-600',
  high: 'text-orange-500',
  critical: 'text-red-600',
}

function InfoRow({ label, value }: { label: string; value?: string | null }) {
  if (!value) {
    return null
  }

  return (
    <div className="flex items-start justify-between gap-4 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className="text-right font-medium text-foreground">{value}</span>
    </div>
  )
}

function EscalationCard({ escalation }: { escalation: Escalation }) {
  return (
    <div className="rounded-xl border p-3 space-y-1.5">
      <div className="flex items-center justify-between gap-3">
        <span className="text-sm font-semibold capitalize">{escalation.escalation_level} escalation</span>
        <Badge variant={escalation.status === 'resolved' ? 'outline' : 'destructive'}>
          {escalation.status}
        </Badge>
      </div>
      <p className="text-sm text-muted-foreground">{escalation.reason}</p>
      <p className="text-xs text-muted-foreground">
        Raised {new Date(escalation.created_at).toLocaleString()}
      </p>
    </div>
  )
}

export function TaskDetailPage() {
  const navigate = useNavigate()
  const { taskId = '' } = useParams()

  const { data: task, isLoading } = useQuery<CloseTask>({
    queryKey: ['task-detail', taskId],
    queryFn: () => closeTasksApi.get(taskId).then(r => r.data.data),
    enabled: !!taskId,
  })

  if (isLoading) {
    return (
      <div className="flex items-center justify-center gap-2 py-20 text-muted-foreground">
        <Icon icon="solar:spinner-bold" className="h-5 w-5 animate-spin" />
        Loading task details…
      </div>
    )
  }

  if (!task) {
    return (
      <Card>
        <CardContent className="py-16 text-center text-muted-foreground">
          <Icon icon="solar:file-text-bold-duotone" className="h-10 w-10 mx-auto mb-3 opacity-40" />
          <p className="font-medium">Task not found.</p>
          <Button className="mt-4" variant="outline" onClick={() => navigate('/financial-close/tasks')}>
            Back to Tasks
          </Button>
        </CardContent>
      </Card>
    )
  }

  const status = statusConfig[task.status] ?? statusConfig.created

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div className="space-y-3">
          <Button variant="ghost" className="px-0" onClick={() => navigate(-1)}>
            <Icon icon="solar:arrow-left-bold" className="mr-2 h-4 w-4" />
            Back
          </Button>
          <div>
            <p className="text-sm text-muted-foreground">Task Detail</p>
            <h1 className="text-3xl font-bold">{task.detail_title ?? task.title}</h1>
          </div>
          <div className="flex items-center gap-2 flex-wrap">
            <Badge variant={status.variant}>{status.label}</Badge>
            <Badge variant="outline" className={priorityConfig[task.priority] ?? ''}>{task.priority} priority</Badge>
            {task.is_critical && <Badge variant="destructive">Critical path</Badge>}
          </div>
        </div>

        <div className="rounded-2xl border bg-card px-4 py-3 min-w-[220px]">
          <InfoRow label="Owner" value={task.owner_label ?? task.assigned_to} />
          <InfoRow label="Due" value={task.due_date ? new Date(task.due_date).toLocaleString() : null} />
          <InfoRow label="Workstream" value={task.workstream} />
        </div>
      </div>

      <Card>
        <CardContent className="pt-6 space-y-4">
          <p className="text-base leading-7 text-foreground">
            {task.description ?? 'This task is part of the financial close orchestration workflow.'}
          </p>
          <div className="grid gap-4 md:grid-cols-3">
            <div className="rounded-xl bg-slate-50 dark:bg-slate-900 p-4">
              <p className="text-xs uppercase tracking-wide text-muted-foreground">Deliverable</p>
              <p className="text-sm font-medium mt-1">{task.deliverable ?? 'No deliverable captured yet.'}</p>
            </div>
            <div className="rounded-xl bg-slate-50 dark:bg-slate-900 p-4">
              <p className="text-xs uppercase tracking-wide text-muted-foreground">Business Impact</p>
              <p className="text-sm font-medium mt-1">{task.business_impact ?? 'No impact summary captured yet.'}</p>
            </div>
            <div className="rounded-xl bg-slate-50 dark:bg-slate-900 p-4">
              <p className="text-xs uppercase tracking-wide text-muted-foreground">Next Step</p>
              <p className="text-sm font-medium mt-1">{task.next_step ?? 'No next step recorded.'}</p>
            </div>
          </div>
          {task.blocker_reason && (
            <div className="rounded-xl border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-900 dark:border-orange-900 dark:bg-orange-950/30 dark:text-orange-200">
              <div className="font-semibold">Current blocker</div>
              <p className="mt-1">{task.blocker_reason}</p>
            </div>
          )}
        </CardContent>
      </Card>

      <Tabs defaultValue="checklist" className="space-y-4">
        <TabsList>
          <TabsTrigger value="checklist">Checklist</TabsTrigger>
          <TabsTrigger value="dependencies">Dependencies</TabsTrigger>
          <TabsTrigger value="escalations">Escalations</TabsTrigger>
        </TabsList>

        <TabsContent value="checklist" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Evidence & Completion Guide</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div>
                <p className="text-sm font-medium mb-2">Checklist</p>
                <div className="space-y-2">
                  {(task.checklist_items?.length ? task.checklist_items : ['No checklist items captured yet.']).map(item => (
                    <div key={item} className="flex items-start gap-2 text-sm">
                      <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4 text-green-500 mt-0.5" />
                      <span>{item}</span>
                    </div>
                  ))}
                </div>
              </div>
              <Separator />
              <div>
                <p className="text-sm font-medium mb-2">Expected Evidence</p>
                <div className="flex flex-wrap gap-2">
                  {(task.evidence_items?.length ? task.evidence_items : ['No evidence defined']).map(item => (
                    <Badge key={item} variant="outline">{item}</Badge>
                  ))}
                </div>
              </div>
              {!!task.related_accounts?.length && (
                <>
                  <Separator />
                  <div>
                    <p className="text-sm font-medium mb-2">Related Accounts</p>
                    <div className="flex flex-wrap gap-2">
                      {task.related_accounts.map(item => (
                        <Badge key={item} variant="secondary">{item}</Badge>
                      ))}
                    </div>
                  </div>
                </>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="dependencies" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Dependency Chain</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              {task.dependencies?.length ? task.dependencies.map(dependency => (
                <div key={dependency.id} className="rounded-xl border p-4 flex items-center justify-between gap-3 flex-wrap">
                  <div>
                    <p className="text-sm font-semibold">{dependency.depends_on_task_title ?? 'Upstream task'}</p>
                    <p className="text-xs text-muted-foreground mt-1">{dependency.dependency_type} dependency</p>
                  </div>
                  <Badge variant={dependency.depends_on_task_status === 'completed' ? 'outline' : 'secondary'}>
                    {dependency.depends_on_task_status ?? 'unknown'}
                  </Badge>
                </div>
              )) : (
                <p className="text-sm text-muted-foreground">No explicit task dependencies recorded for this task.</p>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="escalations" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Escalation History</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              {task.escalations?.length ? task.escalations.map(escalation => (
                <EscalationCard key={escalation.id} escalation={escalation} />
              )) : (
                <p className="text-sm text-muted-foreground">No escalations have been raised for this task.</p>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  )
}