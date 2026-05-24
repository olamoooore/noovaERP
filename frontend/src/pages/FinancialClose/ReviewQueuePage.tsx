import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
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
import { closeTasksApi, escalationsApi, type CloseTask } from '@/services/closeApi'
import { useToast } from '@/hooks/use-toast'

export function ReviewQueuePage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [escalateTask, setEscalateTask] = useState<CloseTask | null>(null)
  const [escalateReason, setEscalateReason] = useState('')
  const [escalateTo, setEscalateTo] = useState('')

  const { data: tasks = [], isLoading } = useQuery<CloseTask[]>({
    queryKey: ['review-queue'],
    queryFn: () => closeTasksApi.reviewQueue().then(r => r.data.data),
  })

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ['review-queue'] })
  }

  const completeMutation = useMutation({
    mutationFn: (id: string) => closeTasksApi.complete(id),
    onSuccess: () => { invalidate(); toast({ title: 'Task approved and completed.' }) },
    onError: (err: { response?: { data?: { message?: string } } }) =>
      toast({ title: err?.response?.data?.message ?? 'Could not complete task.', variant: 'destructive' }),
  })

  const returnMutation = useMutation({
    mutationFn: (id: string) => closeTasksApi.returnTask(id),
    onSuccess: () => { invalidate(); toast({ title: 'Task returned to assignee.' }) },
    onError: () => toast({ title: 'Could not return task.', variant: 'destructive' }),
  })

  const escalateMutation = useMutation({
    mutationFn: ({ taskId, reason, to }: { taskId: string; reason: string; to: string }) =>
      escalationsApi.create(taskId, reason, to || undefined),
    onSuccess: () => {
      invalidate()
      setEscalateTask(null)
      setEscalateReason('')
      setEscalateTo('')
      toast({ title: 'Task escalated.' })
    },
    onError: () => toast({ title: 'Escalation failed.', variant: 'destructive' }),
  })

  const now = new Date()

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <Button variant="ghost" className="px-0 h-auto py-1" onClick={() => navigate(-1)}>
          <Icon icon="solar:arrow-left-bold" className="mr-1.5 h-4 w-4" />
          Back
        </Button>
        <h1 className="text-3xl font-bold">Review Queue</h1>
        <p className="text-muted-foreground mt-1">
          Tasks submitted for review. Approve, return, or escalate.
        </p>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center gap-2 py-16 text-muted-foreground">
          <Icon icon="solar:spinner-bold" className="h-5 w-5 animate-spin" />
          Loading queue…
        </div>
      ) : tasks.length === 0 ? (
        <Card>
          <CardContent className="py-16 text-center text-muted-foreground">
            <Icon icon="solar:inbox-bold-duotone" className="h-10 w-10 mx-auto mb-3 opacity-40" />
            <p className="font-medium">Review queue is empty.</p>
            <p className="text-sm mt-1">No tasks awaiting review at this time.</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-3">
          {tasks.map(task => {
            const overdue = task.due_date && new Date(task.due_date) < now
            return (
              <Card
                key={task.id}
                className={`hover:shadow-md transition-shadow ${overdue ? 'border-red-300 dark:border-red-700' : ''}`}
              >
                <CardContent className="pt-4 pb-3">
                  <div className="flex items-start gap-4">
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <p className="font-semibold text-sm">{task.title}</p>
                        {task.is_critical && (
                          <Badge variant="destructive" className="text-xs">Critical</Badge>
                        )}
                        <Badge variant="outline">Submitted</Badge>
                        <span className={`text-xs font-medium ${
                          task.priority === 'critical' ? 'text-red-600' :
                          task.priority === 'high'     ? 'text-orange-500' :
                          task.priority === 'medium'   ? 'text-yellow-600' : 'text-slate-500'
                        }`}>
                          {task.priority.charAt(0).toUpperCase() + task.priority.slice(1)} Priority
                        </span>
                      </div>
                      {task.assigned_to && (
                        <p className="text-xs text-muted-foreground mt-1 flex items-center gap-1">
                          <Icon icon="solar:user-bold" className="h-3.5 w-3.5" />
                          Submitted by: {task.assigned_to}
                        </p>
                      )}
                      {task.due_date && (
                        <span className={`text-xs flex items-center gap-1 mt-0.5 ${overdue ? 'text-red-500 font-medium' : 'text-muted-foreground'}`}>
                          <Icon icon="solar:calendar-bold" className="h-3.5 w-3.5" />
                          {overdue ? 'Overdue · ' : 'Due '}
                          {new Date(task.due_date).toLocaleDateString()}
                        </span>
                      )}
                    </div>

                    <div className="flex items-center gap-1.5 flex-shrink-0">
                      <Button
                        size="sm"
                        onClick={() => completeMutation.mutate(task.id)}
                        disabled={completeMutation.isPending}
                      >
                        <Icon icon="solar:check-circle-bold" className="mr-1.5 h-4 w-4" />
                        Approve
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => returnMutation.mutate(task.id)}
                        disabled={returnMutation.isPending}
                      >
                        Return
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        className="text-orange-600"
                        onClick={() => { setEscalateTask(task); setEscalateReason(''); setEscalateTo('') }}
                      >
                        Escalate
                      </Button>
                    </div>
                  </div>
                </CardContent>
              </Card>
            )
          })}
        </div>
      )}

      {/* Escalation dialog */}
      <Dialog open={!!escalateTask} onOpenChange={open => { if (!open) setEscalateTask(null) }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Escalate Task</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <p className="text-sm text-muted-foreground">
              Escalate <span className="font-medium text-foreground">"{escalateTask?.title}"</span>
            </p>
            <div className="space-y-1.5">
              <Label htmlFor="esc-reason">Reason <span className="text-red-500">*</span></Label>
              <Input
                id="esc-reason"
                placeholder="Why is this being escalated?"
                value={escalateReason}
                onChange={e => setEscalateReason(e.target.value)}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="esc-to">Escalate To (optional — user ID)</Label>
              <Input
                id="esc-to"
                placeholder="User ID or email"
                value={escalateTo}
                onChange={e => setEscalateTo(e.target.value)}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEscalateTask(null)}>Cancel</Button>
            <Button
              disabled={!escalateReason.trim() || escalateMutation.isPending}
              onClick={() => escalateMutation.mutate({ taskId: escalateTask!.id, reason: escalateReason, to: escalateTo })}
            >
              Escalate
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
