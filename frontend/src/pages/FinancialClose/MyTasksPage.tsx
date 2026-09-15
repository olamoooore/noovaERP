import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useState } from 'react'
import { Icon } from '@iconify/react'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { closeTasksApi, closeCyclesApi, type CloseTask } from '@/services/closeApi'
import { useToast } from '@/hooks/use-toast'

const statusConfig: Record<string, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
  created:     { label: 'Created',     variant: 'secondary' },
  assigned:    { label: 'Assigned',    variant: 'secondary' },
  in_progress: { label: 'In Progress', variant: 'default' },
  submitted:   { label: 'Submitted',   variant: 'outline' },
  completed:   { label: 'Completed',   variant: 'outline' },
  blocked:     { label: 'Blocked',     variant: 'destructive' },
  cancelled:   { label: 'Cancelled',   variant: 'secondary' },
}

const priorityConfig: Record<string, { label: string; class: string }> = {
  low:      { label: 'Low',      class: 'text-slate-500' },
  medium:   { label: 'Medium',   class: 'text-yellow-600' },
  high:     { label: 'High',     class: 'text-orange-500' },
  critical: { label: 'Critical', class: 'text-red-600 font-bold' },
}

interface BlockerItem {
  depends_on_task_id?: string
  depends_on_task_title?: string
  depends_on_status?: string
  type?: string
  message?: string
  details?: string[]
}

export function MyTasksPage() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const cycleId = params.get('cycleId')
  const initialStatusFilter = params.get('status') ?? 'all'
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [blockDialogTask, setBlockDialogTask] = useState<CloseTask | null>(null)
  const [blockReason, setBlockReason] = useState('')
  const [blockersModal, setBlockersModal] = useState<BlockerItem[]>([])
  const [statusFilter, setStatusFilter] = useState<string>(initialStatusFilter)

  const { data: tasks = [], isLoading } = useQuery<CloseTask[]>({
    queryKey: ['my-tasks', cycleId, statusFilter],
    queryFn: () => closeTasksApi.myTasks(statusFilter).then(r => r.data.data),
  })

  const byCycleQuery = useQuery<CloseTask[]>({
    queryKey: ['cycle-tasks', cycleId],
    queryFn: () => closeTasksApi.byCycle(cycleId!).then(r => r.data.data),
    enabled: !!cycleId,
  })

  const cycleQuery = useQuery({
    queryKey: ['close-cycle-detail', cycleId],
    queryFn: () => closeCyclesApi.list().then(r => {
      const cycles = r.data.data
      return cycles.find((c: { id: string }) => c.id === cycleId) ?? null
    }),
    enabled: !!cycleId,
  })

  const now = new Date()

  function isOverdue(task: CloseTask) {
    return task.due_date && new Date(task.due_date) < now && task.status !== 'completed' && task.status !== 'cancelled'
  }

  const displayTasks = cycleId ? (byCycleQuery.data ?? []) : tasks

  // For myTasks endpoint, backend handles status filtering.
  // For cycle tasks, apply client-side filter.
  const filteredTasks = cycleId
    ? (statusFilter === 'all'
        ? displayTasks
        : statusFilter === 'overdue'
          ? displayTasks.filter(t => isOverdue(t))
          : displayTasks.filter(t => t.status === statusFilter))
    : displayTasks

  function mutationOpts(label: string) {
    return {
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: ['my-tasks'] })
        queryClient.invalidateQueries({ queryKey: ['cycle-tasks'] })
        toast({ title: label })
      },
      onError: (err: { response?: { data?: { blockers?: BlockerItem[]; message?: string } } }) => {
        const blockers = err?.response?.data?.blockers
        if (blockers?.length) {
          setBlockersModal(blockers)
        } else {
          toast({ title: err?.response?.data?.message ?? 'Action failed.', variant: 'destructive' })
        }
      },
    }
  }

  const startMutation    = useMutation({ mutationFn: (id: string) => closeTasksApi.start(id),       ...mutationOpts('Task started.') })
  const submitMutation   = useMutation({ mutationFn: (id: string) => closeTasksApi.submit(id),      ...mutationOpts('Task submitted for review.') })
  const completeMutation = useMutation({ mutationFn: (id: string) => closeTasksApi.complete(id),    ...mutationOpts('Task completed.') })
  const returnMutation   = useMutation({ mutationFn: (id: string) => closeTasksApi.returnTask(id),  ...mutationOpts('Task returned.') })

  const blockMutation = useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) => closeTasksApi.block(id, reason),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['my-tasks'] })
      queryClient.invalidateQueries({ queryKey: ['cycle-tasks'] })
      setBlockDialogTask(null)
      setBlockReason('')
      toast({ title: 'Task marked as blocked.' })
    },
    onError: () => toast({ title: 'Failed to block task.', variant: 'destructive' }),
  })

  function openTask(taskId: string) {
    navigate(`/financial-close/tasks/${taskId}`)
  }

  function stopCardClick(event: React.MouseEvent<HTMLElement>) {
    event.stopPropagation()
  }

  function navigateToDocLink(link: { type: string; id: number | string; title?: string; route?: string }) {
    if (link.route) {
      navigate(link.route)
      return
    }

    if (link.type === 'journal_entry') {
      navigate(`/accounting/core/journal-entries?entry_id=${link.id}`)
      return
    }

    if (link.type === 'report') {
      const reportRoutes: Record<string, string> = {
        'trial-balance': '/accounting/reporting/trial-balance',
        'financial-statements': '/accounting/reporting/financial-statement',
        'general-ledger': '/accounting/reporting/general-ledger',
      }
      navigate(reportRoutes[link.id as string] ?? '/accounting/reporting/trial-balance')
      return
    }

    const routes: Record<string, string> = {
      invoice: '/invoices/',
      bill: '/accounting/vendors/bills/',
      payment: '/accounting/customers/payments',
      credit_note: '/accounting/vendors/credit-notes/',
      debit_note: '/accounting/vendors/debit-notes/',
      bank_reconciliation: '/accounting/core/reconciliation',
    }
    const base = routes[link.type] ?? '/accounting/core/journal-entries'
    navigate(base.endsWith('/') ? base + link.id : base)
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="space-y-1">
          {cycleId && (
            <Button variant="ghost" className="px-0 h-auto py-1" onClick={() => navigate('/financial-close/cycles')}>
              <Icon icon="solar:arrow-left-bold" className="mr-1.5 h-4 w-4" />
              Back to Cycles
            </Button>
          )}
          <h1 className="text-3xl font-bold">My Tasks</h1>
          <p className="text-muted-foreground mt-1">
            {cycleId
              ? `Tasks for cycle ${cycleQuery.data?.name || cycleQuery.data?.period_id || 'Unknown'}`
              : 'All tasks assigned to you'}
          </p>
        </div>
        <Select value={statusFilter} onValueChange={setStatusFilter}>
          <SelectTrigger className="w-44">
            <SelectValue placeholder="Filter status" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All Statuses</SelectItem>
            {Object.entries(statusConfig).map(([k, v]) => (
              <SelectItem key={k} value={k}>{v.label}</SelectItem>
            ))}
            <SelectItem value="overdue">Overdue</SelectItem>
          </SelectContent>
        </Select>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center gap-2 py-16 text-muted-foreground">
          <Icon icon="solar:spinner-bold" className="h-5 w-5 animate-spin" />
          Loading tasks…
        </div>
      ) : filteredTasks.length === 0 ? (
        <Card>
          <CardContent className="py-16 text-center text-muted-foreground">
            <Icon icon="solar:list-check-bold-duotone" className="h-10 w-10 mx-auto mb-3 opacity-40" />
            <p className="font-medium">No tasks{statusFilter !== 'all' ? ` with status "${statusFilter}"` : ''}.</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-3">
          {filteredTasks.map(task => {
            const overdue = isOverdue(task)
            return (
              <Card
                key={task.id}
                className={`cursor-pointer transition-shadow hover:shadow-md ${overdue ? 'border-red-300 dark:border-red-700' : ''}`}
                onClick={() => openTask(task.id)}
              >
                <CardContent className="pt-4 pb-3">
                  <div className="flex items-start gap-4">
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <p className="font-semibold text-sm truncate">{task.title}</p>
                        {task.is_critical && (
                          <Badge variant="destructive" className="text-xs">Critical</Badge>
                        )}
                        <Badge variant={statusConfig[task.status]?.variant ?? 'secondary'}>
                          {statusConfig[task.status]?.label ?? task.status}
                        </Badge>
                        <span className={`text-xs font-medium ${priorityConfig[task.priority]?.class ?? ''}`}>
                          {priorityConfig[task.priority]?.label ?? task.priority}
                        </span>
                      </div>
                      <div className="flex items-center gap-4 mt-1.5">
                        {task.due_date && (
                          <span className={`text-xs flex items-center gap-1 ${overdue ? 'text-red-500 font-medium' : 'text-muted-foreground'}`}>
                            <Icon icon="solar:calendar-bold" className="h-3.5 w-3.5" />
                            {overdue ? 'Overdue · ' : ''}
                            Due {new Date(task.due_date).toLocaleDateString()}
                          </span>
                        )}
                        {task.soft_due_date && (
                          <span className="text-xs text-muted-foreground flex items-center gap-1">
                            <Icon icon="solar:clock-bold" className="h-3.5 w-3.5" />
                            Soft due {new Date(task.soft_due_date).toLocaleDateString()}
                          </span>
                        )}
                        <button
                          type="button"
                          onClick={(event) => {
                            stopCardClick(event)
                            openTask(task.id)
                          }}
                          className="text-xs text-primary font-medium inline-flex items-center gap-1 hover:underline"
                        >
                          View details
                          <Icon icon="solar:arrow-right-bold" className="h-3.5 w-3.5" />
                        </button>
                      </div>
                      {!!(task.document_links && task.document_links.length > 0) && (
                        <div className="flex flex-wrap gap-1.5 mt-2">
                          <span className="text-xs text-muted-foreground flex items-center gap-0.5">
                            <Icon icon="solar:link-bold" className="h-3 w-3" />
                            Source:
                          </span>
                          {task.document_links.slice(0, 3).map((link, idx) => (
                            <button
                              key={`doc-${task.id}-${idx}`}
                              type="button"
                              onClick={(event) => {
                                stopCardClick(event)
                                navigateToDocLink(link)
                              }}
                              className="inline-flex items-center gap-1 rounded border border-violet-300 bg-violet-50 px-1.5 py-0.5 text-xs text-violet-700 hover:bg-violet-100 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-300"
                            >
                              <Icon icon="solar:document-text-bold" className="h-3 w-3" />
                              {link.title ?? `${link.type} #${link.id}`}
                            </button>
                          ))}
                          {task.document_links.length > 3 && (
                            <span className="text-xs text-muted-foreground">
                              +{task.document_links.length - 3} more
                            </span>
                          )}
                        </div>
                      )}
                    </div>

                    <div className="flex items-center gap-1.5 flex-shrink-0">
                      {['created', 'assigned'].includes(task.status) && (
                        <Button size="sm" variant="outline" onClick={(event) => { stopCardClick(event); startMutation.mutate(task.id) }} disabled={startMutation.isPending}>
                          Start
                        </Button>
                      )}
                      {task.status === 'in_progress' && (
                        <>
                          <Button size="sm" variant="outline" onClick={(event) => { stopCardClick(event); submitMutation.mutate(task.id) }} disabled={submitMutation.isPending}>
                            Submit
                          </Button>
                          <Button
                            size="sm"
                            variant="outline"
                            className="text-orange-600"
                            onClick={(event) => { stopCardClick(event); setBlockDialogTask(task); setBlockReason('') }}
                          >
                            Block
                          </Button>
                        </>
                      )}
                      {task.status === 'submitted' && (
                        <>
                          <Button size="sm" onClick={(event) => { stopCardClick(event); completeMutation.mutate(task.id) }} disabled={completeMutation.isPending}>
                            Complete
                          </Button>
                          <Button size="sm" variant="outline" onClick={(event) => { stopCardClick(event); returnMutation.mutate(task.id) }} disabled={returnMutation.isPending}>
                            Return
                          </Button>
                        </>
                      )}
                      {task.status === 'blocked' && (
                        <span className="text-xs text-muted-foreground italic px-2">Blocked</span>
                      )}
                    </div>
                  </div>
                </CardContent>
              </Card>
            )
          })}
        </div>
      )}

      {/* Block reason dialog */}
      <Dialog open={!!blockDialogTask} onOpenChange={open => { if (!open) setBlockDialogTask(null) }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Block Task</DialogTitle>
          </DialogHeader>
          <div className="space-y-3 py-2">
            <p className="text-sm text-muted-foreground">
              Mark <span className="font-medium text-foreground">"{blockDialogTask?.title}"</span> as blocked.
            </p>
            <div className="space-y-1.5">
              <Label htmlFor="block-reason">Reason <span className="text-red-500">*</span></Label>
              <Input
                id="block-reason"
                placeholder="Describe why this task is blocked…"
                value={blockReason}
                onChange={e => setBlockReason(e.target.value)}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setBlockDialogTask(null)}>Cancel</Button>
            <Button
              variant="destructive"
              disabled={!blockReason.trim() || blockMutation.isPending}
              onClick={() => blockMutation.mutate({ id: blockDialogTask!.id, reason: blockReason })}
            >
              Confirm Block
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Dependency blockers modal */}
      <Dialog open={blockersModal.length > 0} onOpenChange={() => setBlockersModal([])}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-orange-500">
              <Icon icon="solar:lock-keyhole-bold-duotone" className="h-5 w-5" />
              Cannot Complete Task
            </DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground">
            The following issues must be resolved before this task can be completed:
          </p>
          <div className="space-y-2 mt-2">
            {blockersModal.map((b, idx) => (
              <div key={idx} className="rounded-lg border px-3 py-2">
                {b.type ? (
                  <div>
                    <span className="text-sm font-medium capitalize">{b.type}</span>
                    <p className="text-sm text-muted-foreground mt-1">{b.message}</p>
                    {b.details && b.details.length > 0 && (
                      <ul className="mt-1 list-disc list-inside text-xs text-muted-foreground">
                        {b.details.map((d, i) => <li key={i}>{d}</li>)}
                      </ul>
                    )}
                  </div>
                ) : (
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-medium">{b.depends_on_task_title}</span>
                    <Badge variant="secondary" className="text-xs">{b.depends_on_status}</Badge>
                  </div>
                )}
              </div>
            ))}
          </div>
          <DialogFooter>
            <Button onClick={() => setBlockersModal([])}>OK</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
