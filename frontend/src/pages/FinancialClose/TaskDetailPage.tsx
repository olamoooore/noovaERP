import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { useState } from 'react'
import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import { closeTasksApi, escalationsApi, type CloseTask, type Escalation } from '@/services/closeApi'
import { useToast } from '@/hooks/use-toast'
import { useAuthStore } from '@/store/authStore'

const statusConfig: Record<string, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
  created: { label: 'Created', variant: 'secondary' },
  assigned: { label: 'Assigned', variant: 'secondary' },
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
  if (!value) return null
  return (
    <div className="flex items-start justify-between gap-4 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className="text-right font-medium text-foreground">{value}</span>
    </div>
  )
}

export function TaskDetailPage() {
  const navigate = useNavigate()
  const { taskId = '' } = useParams()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const { role: userRole, user } = useAuthStore()

  // Role-based access control: Owner, Admin, Manager are reviewer roles
  const reviewerRoles = ['Owner', 'Admin', 'Manager']
  const isReviewer = userRole ? reviewerRoles.includes(userRole) : false

  const [blockDialogOpen, setBlockDialogOpen] = useState(false)
  const [blockReason, setBlockReason] = useState('')

  const [escalateDialogOpen, setEscalateDialogOpen] = useState(false)
  const [escalateReason, setEscalateReason] = useState('')
  const [escalateLevel, setEscalateLevel] = useState('medium')

  const [linkDocDialogOpen, setLinkDocDialogOpen] = useState(false)
  const [linkDocType, setLinkDocType] = useState('bill')
  const [linkDocId, setLinkDocId] = useState('')
  const [linkDocTitle, setLinkDocTitle] = useState('')

  const [reassignDialogOpen, setReassignDialogOpen] = useState(false)
  const [reassignUserId, setReassignUserId] = useState('')

  const { data: task, isLoading } = useQuery<CloseTask>({
    queryKey: ['task-detail', taskId],
    queryFn: () => closeTasksApi.get(taskId).then(r => r.data.data),
    enabled: !!taskId,
  })

  const { data: evidenceList, refetch: refetchEvidence } = useQuery({
    queryKey: ['task-evidence', taskId],
    queryFn: () => closeTasksApi.listEvidence(taskId).then(r => r.data.data),
    enabled: !!taskId,
  })

  const { data: blockersData } = useQuery({
    queryKey: ['task-blockers', taskId],
    queryFn: () => closeTasksApi.checkBlockers(taskId).then(r => r.data),
    enabled: !!taskId && task?.status === 'submitted',
  })

  const refetchTask = () => queryClient.invalidateQueries({ queryKey: ['task-detail', taskId] })

  const { data: users = [] } = useQuery<Array<{ id: number; name: string; email: string }>>({
    queryKey: ['close-users'],
    queryFn: () => closeTasksApi.users().then(r => r.data.data),
  })

  const reassignMutation = useMutation({
    mutationFn: () => closeTasksApi.reassign(taskId, reassignUserId),
    onSuccess: () => {
      const assignee = users.find(candidate => String(candidate.id) === String(reassignUserId))
      setReassignDialogOpen(false)
      setReassignUserId('')
      refetchTask()
      queryClient.invalidateQueries({ queryKey: ['my-tasks'] })
      queryClient.invalidateQueries({ queryKey: ['cycle-tasks'] })
      toast({
        title: 'Task reassigned.',
        description: assignee
          ? `${assignee.name} can now open the task and select Start Task.`
          : 'The new assignee can now open the task and select Start Task.',
      })
    },
    onError: () => toast({ title: 'Failed to reassign task.', variant: 'destructive' }),
  })

  const startMutation    = useMutation({ mutationFn: () => closeTasksApi.start(taskId),       onSuccess: () => { refetchTask(); toast({ title: 'Task started.' }) },       onError: () => toast({ title: 'Failed to start task.', variant: 'destructive' }) })
  const submitMutation   = useMutation({ mutationFn: () => closeTasksApi.submit(taskId),      onSuccess: () => { refetchTask(); toast({ title: 'Task submitted for review.' }) }, onError: () => toast({ title: 'Failed to submit task.', variant: 'destructive' }) })
  const completeMutation = useMutation({
    mutationFn: () => closeTasksApi.complete(taskId),
    onSuccess: () => { refetchTask(); toast({ title: 'Task completed.' }) },
    onError: (err: any) => {
      const msg = err?.response?.data?.message ?? 'Failed to complete task.'
      const blockers = err?.response?.data?.blockers
      if (blockers?.length) {
        const details = blockers.map((b: any) => b.message ?? b.depends_on_task_title).join('; ')
        toast({ title: msg, description: details, variant: 'destructive' })
      } else {
        toast({ title: msg, variant: 'destructive' })
      }
    }
  })
  const returnMutation   = useMutation({ mutationFn: () => closeTasksApi.returnTask(taskId),  onSuccess: () => { refetchTask(); toast({ title: 'Task returned.' }) },        onError: () => toast({ title: 'Failed to return task.', variant: 'destructive' }) })
  const blockMutation    = useMutation({
    mutationFn: () => closeTasksApi.block(taskId, blockReason),
    onSuccess: () => { refetchTask(); setBlockDialogOpen(false); setBlockReason(''); toast({ title: 'Task blocked.' }) },
    onError: () => toast({ title: 'Failed to block task.', variant: 'destructive' }),
  })

  const checklistCompleted: string[] = task?.checklist_completed ?? []
  const checklistItems: string[] = task?.checklist_items ?? []
  const documentLinks = task?.document_links ?? []
  const taskType = task?.metadata?.type as string | undefined
  const bankAccountId = task?.metadata?.bank_account_id as number | undefined

  const toggleChecklistItem = (item: string) => {
    const completed = checklistCompleted.includes(item)
      ? checklistCompleted.filter(i => i !== item)
      : [...checklistCompleted, item]
    saveChecklistMutation.mutate(completed)
  }

  const saveChecklistMutation = useMutation({
    mutationFn: (completedItems: string[]) => closeTasksApi.saveChecklist(taskId, completedItems),
    onSuccess: () => { refetchTask(); toast({ title: 'Checklist progress saved.' }) },
    onError: () => toast({ title: 'Failed to save checklist.', variant: 'destructive' }),
  })

  const escalateMutation = useMutation({
    mutationFn: () => escalationsApi.create(taskId, escalateReason, escalateLevel),
    onSuccess: () => { setEscalateDialogOpen(false); setEscalateReason(''); setEscalateLevel('medium'); refetchTask(); toast({ title: 'Escalation raised.' }) },
    onError: () => toast({ title: 'Failed to raise escalation.', variant: 'destructive' }),
  })

  const resolveEscalationMutation = useMutation({
    mutationFn: (escalationId: string) => escalationsApi.resolve(escalationId),
    onSuccess: () => { refetchTask(); toast({ title: 'Escalation resolved.' }) },
    onError: () => toast({ title: 'Failed to resolve escalation.', variant: 'destructive' }),
  })

  const returnEscalationMutation = useMutation({
    mutationFn: ({ escalationId, reason }: { escalationId: string; reason?: string }) =>
      escalationsApi.return(escalationId, reason),
    onSuccess: () => { refetchTask(); toast({ title: 'Escalation returned to preparer.' }) },
    onError: () => toast({ title: 'Failed to return escalation.', variant: 'destructive' }),
  })

  const approveEscalationMutation = useMutation({
    mutationFn: ({ escalationId, notes }: { escalationId: string; notes?: string }) =>
      escalationsApi.approve(escalationId, notes),
    onSuccess: () => { refetchTask(); toast({ title: 'Escalation approved.' }) },
    onError: () => toast({ title: 'Failed to approve escalation.', variant: 'destructive' }),
  })

  const uploadEvidenceMutation = useMutation({
    mutationFn: (file: File) => closeTasksApi.uploadEvidence(taskId, file),
    onSuccess: () => { refetchEvidence(); refetchTask(); toast({ title: 'Evidence uploaded.' }) },
    onError: () => toast({ title: 'Failed to upload evidence.', variant: 'destructive' }),
  })

  const linkDocMutation = useMutation({
    mutationFn: () => closeTasksApi.linkDocument(taskId, linkDocType, Number(linkDocId), linkDocTitle || undefined),
    onSuccess: () => { setLinkDocDialogOpen(false); setLinkDocId(''); setLinkDocTitle(''); refetchTask(); toast({ title: 'Document linked.' }) },
    onError: () => toast({ title: 'Failed to link document.', variant: 'destructive' }),
  })

  const unlinkDocMutation = useMutation({
    mutationFn: ({ docType, docId }: { docType: string; docId: number }) => closeTasksApi.unlinkDocument(taskId, docType, docId),
    onSuccess: () => { refetchTask(); toast({ title: 'Document unlinked.' }) },
    onError: () => toast({ title: 'Failed to unlink document.', variant: 'destructive' }),
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
          {task.document_links && task.document_links.length > 0 && (
            <InfoRow label="Source Documents" value={`${task.document_links.length} linked`} />
          )}
        </div>

        <div className="flex items-center gap-2">
          {['created', 'assigned'].includes(task.status) && (
            <Button size="sm" onClick={() => startMutation.mutate()} disabled={startMutation.isPending}>
              Start Task
            </Button>
          )}
          {task.status === 'in_progress' && (
            <>
              <Button size="sm" onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                Submit for Review
              </Button>
              <Button size="sm" variant="outline" className="text-orange-600" onClick={() => setBlockDialogOpen(true)}>
                Block
              </Button>
            </>
          )}
          {task.status === 'submitted' && isReviewer && String(task.assigned_to) !== String(user?.id) && (
            <>
              <Button
                size="sm"
                onClick={() => completeMutation.mutate()}
                disabled={completeMutation.isPending || (blockersData && !blockersData.can_complete)}
                title={blockersData && !blockersData.can_complete ? 'Resolve blockers before approving' : 'Approve this task'}
              >
                Approve
              </Button>
              {blockersData && !blockersData.can_complete && (
                <span className="text-xs text-orange-600 ml-2">
                  ({blockersData.blockers.length} blocker(s) to resolve)
                </span>
              )}
              <Button size="sm" variant="outline" onClick={() => returnMutation.mutate()} disabled={returnMutation.isPending}>
                Return
              </Button>
            </>
          )}
          {task.status === 'submitted' && (!isReviewer || String(task.assigned_to) === String(user?.id)) && (
            <span className="text-sm text-muted-foreground italic px-2">
              {isReviewer && String(task.assigned_to) === String(user?.id)
                ? 'Waiting for another reviewer to approve'
                : 'Pending review'}
            </span>
          )}
          {task.status === 'blocked' && (
            <span className="text-sm text-muted-foreground italic px-2">Blocked</span>
          )}
          {!['completed', 'cancelled'].includes(task.status) && (
            <Button size="sm" variant="outline" onClick={() => setReassignDialogOpen(true)}>
              <Icon icon="solar:user-switch-bold" className="h-4 w-4 mr-1" />
              Reassign
            </Button>
          )}
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
          {blockersData && !blockersData.can_complete && task.status === 'submitted' && (
            <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">
              <div className="font-semibold">Cannot complete task</div>
              <ul className="mt-2 space-y-1 list-disc list-inside">
                {blockersData.blockers.map((blocker, idx) => (
                  <li key={idx}>{blocker.message}{blocker.details ? `: ${blocker.details}` : ''}</li>
                ))}
              </ul>
            </div>
          )}
        </CardContent>
      </Card>

      <Tabs defaultValue={documentLinks.length > 0 ? 'sources' : 'checklist'} className="space-y-4">
        <TabsList>
          <TabsTrigger value="sources">Source Documents</TabsTrigger>
          <TabsTrigger value="checklist">Checklist</TabsTrigger>
          <TabsTrigger value="evidence">Evidence</TabsTrigger>
          <TabsTrigger value="dependencies">Dependencies</TabsTrigger>
          <TabsTrigger value="escalations">Escalations</TabsTrigger>
        </TabsList>

        <TabsContent value="sources" className="space-y-4">
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle className="text-base">Linked Source Documents</CardTitle>
                {!['completed', 'cancelled'].includes(task.status) && (
                  <Button size="sm" variant="outline" onClick={() => setLinkDocDialogOpen(true)}>
                    <Icon icon="solar:link-bold" className="h-4 w-4 mr-1" />
                    Link Document
                  </Button>
                )}
              </div>
            </CardHeader>
            <CardContent className="space-y-3">
              {documentLinks.length > 0 ? documentLinks.map((doc, idx) => (
                <div key={idx} className="rounded-xl border p-4 flex items-center justify-between gap-3 flex-wrap">
                  <div className="flex items-center gap-2">
                    <Icon
                      icon={doc.type === 'bill' ? 'solar:bill-list-bold' : doc.type === 'invoice' ? 'solar:file-text-bold' : doc.type === 'journal_entry' ? 'solar:book-bold' : doc.type === 'payment' ? 'solar:card-bold' : doc.type === 'report' ? 'solar:chart-square-bold' : 'solar:document-bold'}
                      className="h-4 w-4 text-muted-foreground"
                    />
                    <div>
                      <p className="text-sm font-semibold">{doc.title}</p>
                      <p className="text-xs text-muted-foreground capitalize">{doc.type.replace('_', ' ')} #{doc.id}</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <Button size="sm" variant="ghost" onClick={() => {
                      if (doc.type === 'journal_entry') {
                        if (taskType === 'unreconciled_transactions' && bankAccountId) {
                          navigate(`/accounting/core/reconciliation?account=${bankAccountId}`)
                        } else {
                          navigate(`/accounting/core/journal-entries?entry_id=${doc.id}`)
                        }
                        return
                      }

                      if (doc.type === 'report') {
                        const reportRoutes: Record<string, string> = {
                          'trial-balance': '/accounting/reporting/trial-balance',
                          'financial-statements': '/accounting/reporting/financial-statements',
                          'general-ledger': '/accounting/reporting/general-ledger',
                        }
                        const reportRoute = reportRoutes[doc.id as string] ?? '/accounting/reporting/trial-balance'
                        navigate(reportRoute)
                        return
                      }

                      const routes: Record<string, string> = {
                        bill: taskType === 'ap_subledger_reconciliation'
                          ? `/accounting/ap-reconciliation?task_id=${taskId}`
                          : `/accounting/vendors/bills/${doc.id}`,
                        invoice: `/invoices/${doc.id}`,
                        payment: '/accounting/customers/payments',
                        credit_note: `/accounting/vendors/credit-notes/${doc.id}`,
                        debit_note: `/accounting/vendors/debit-notes/${doc.id}`,
                      }
                      const route = routes[doc.type]
                      if (route) navigate(route)
                    }}>
                      <Icon icon="solar:eye-bold" className="h-4 w-4" />
                    </Button>
                    {!['completed', 'cancelled'].includes(task.status) && (
                      <Button size="sm" variant="ghost" onClick={() => unlinkDocMutation.mutate({ docType: doc.type, docId: doc.id })}>
                        <Icon icon="solar:link-minimalistic-remove-bold" className="h-4 w-4 text-destructive" />
                      </Button>
                    )}
                  </div>
                </div>
              )) : (
                <p className="text-sm text-muted-foreground">No source documents linked to this task.</p>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="checklist" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Evidence & Completion Guide</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div>
                <p className="text-sm font-medium mb-2">Checklist</p>
                <div className="space-y-2">
                  {(checklistItems.length ? checklistItems : ['No checklist items captured yet.']).map(item => (
                    <label key={item} className="flex items-start gap-2 text-sm cursor-pointer">
                      <input
                        type="checkbox"
                        checked={checklistCompleted.includes(item)}
                        onChange={() => toggleChecklistItem(item)}
                        className="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                        disabled={['completed', 'cancelled'].includes(task.status)}
                      />
                      <span className={checklistCompleted.includes(item) ? 'line-through text-muted-foreground' : ''}>
                        {item}
                      </span>
                    </label>
                  ))}
                </div>
                {checklistItems.length > 0 && !['completed', 'cancelled'].includes(task.status) && (
                  <Button
                    size="sm"
                    variant="outline"
                    className="mt-2"
                    onClick={() => saveChecklistMutation.mutate(checklistCompleted)}
                    disabled={saveChecklistMutation.isPending}
                  >
                    {saveChecklistMutation.isPending ? 'Saving...' : 'Save Progress'}
                  </Button>
                )}
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

        <TabsContent value="evidence" className="space-y-4">
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle className="text-base">Uploaded Evidence</CardTitle>
                {!['completed', 'cancelled'].includes(task.status) && (
                  <label className="cursor-pointer inline-flex items-center gap-1 px-3 py-1.5 border border-input rounded-md text-sm font-medium hover:bg-accent">
                    <Icon icon="solar:upload-bold" className="h-4 w-4" />
                    Upload File
                    <input
                      type="file"
                      className="hidden"
                      onChange={e => {
                        const file = e.target.files?.[0]
                        if (file) uploadEvidenceMutation.mutate(file)
                        e.target.value = ''
                      }}
                      disabled={uploadEvidenceMutation.isPending}
                    />
                  </label>
                )}
              </div>
            </CardHeader>
            <CardContent className="space-y-3">
              {evidenceList && evidenceList.length > 0 ? (
                <div className="space-y-2">
                  {evidenceList.map((ev: any) => (
                    <div key={ev.id} className="flex items-center justify-between rounded-lg border px-3 py-2 text-sm">
                      <div className="flex items-center gap-2">
                        <Icon icon="solar:file-text-bold" className="h-4 w-4 text-muted-foreground" />
                        <span className="font-medium">{ev.file_name}</span>
                        <Badge variant="outline" className="text-xs">{ev.status}</Badge>
                        {['completed', 'cancelled'].includes(task.status) && (
                          <Badge variant="secondary" className="text-xs">Locked</Badge>
                        )}
                      </div>
                      <div className="flex items-center gap-2">
                        <span className="text-xs text-muted-foreground">
                          {new Date(ev.created_at).toLocaleDateString()}
                        </span>
                        {ev.url && (
                          <a
                            href={closeTasksApi.downloadEvidenceUrl(taskId, ev.id)}
                            target="_blank"
                            rel="noopener noreferrer"
                          >
                            <Icon icon="solar:eye-bold" className="h-4 w-4 text-muted-foreground hover:text-foreground" />
                          </a>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">No evidence uploaded yet.</p>
              )}

              {['completed', 'cancelled'].includes(task.status) && (
                <div className="mt-4 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                  <p className="text-sm text-amber-800 dark:text-amber-200">
                    Evidence is locked after task completion. No changes are allowed.
                  </p>
                </div>
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
              <div className="flex items-center justify-between">
                <CardTitle className="text-base">Escalation History</CardTitle>
                {!['completed', 'cancelled'].includes(task.status) && (
                  <Button size="sm" variant="outline" className="text-orange-600" onClick={() => setEscalateDialogOpen(true)}>
                    <Icon icon="solar:danger-triangle-bold" className="h-4 w-4 mr-1" />
                    Escalate
                  </Button>
                )}
              </div>
            </CardHeader>
            <CardContent className="space-y-3">
              {(task.escalations?.length ?? 0) > 0 ? task.escalations!.map(escalation => (
                <div key={escalation.id} className="rounded-xl border p-3 space-y-1.5">
                  <div className="flex items-center justify-between gap-3">
                    <span className="text-sm font-semibold capitalize">{escalation.escalation_level} escalation</span>
                    <div className="flex items-center gap-2">
                      <Badge variant={escalation.status === 'resolved' ? 'outline' : 'destructive'}>
                        {escalation.status}
                      </Badge>
                      {escalation.status === 'open' && !['completed', 'cancelled'].includes(task.status) && (
                        <div className="flex items-center gap-1">
                          <Button
                            size="sm"
                            variant="outline"
                            className="text-blue-600"
                            onClick={() => returnEscalationMutation.mutate({ escalationId: escalation.id })}
                            disabled={returnEscalationMutation.isPending || approveEscalationMutation.isPending || resolveEscalationMutation.isPending}
                          >
                            Return
                          </Button>
                          <Button
                            size="sm"
                            variant="default"
                            className="bg-green-600 hover:bg-green-700"
                            onClick={() => approveEscalationMutation.mutate({ escalationId: escalation.id })}
                            disabled={returnEscalationMutation.isPending || approveEscalationMutation.isPending || resolveEscalationMutation.isPending}
                          >
                            Approve
                          </Button>
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => resolveEscalationMutation.mutate(escalation.id)}
                            disabled={returnEscalationMutation.isPending || approveEscalationMutation.isPending || resolveEscalationMutation.isPending}
                          >
                            Resolve
                          </Button>
                        </div>
                      )}
                    </div>
                  </div>
                  <p className="text-sm text-muted-foreground">{escalation.reason}</p>
                  <p className="text-xs text-muted-foreground">
                    Raised {new Date(escalation.created_at).toLocaleString()}
                    {escalation.resolved_at ? ` · Resolved ${new Date(escalation.resolved_at).toLocaleString()}` : ''}
                  </p>
                </div>
              )) : (
                <p className="text-sm text-muted-foreground">No escalations have been raised for this task.</p>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Block dialog */}
      <Dialog open={blockDialogOpen} onOpenChange={setBlockDialogOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Block Task</DialogTitle></DialogHeader>
          <div className="space-y-3 py-2">
            <p className="text-sm text-muted-foreground">
              Mark <span className="font-medium text-foreground">"{task.title}"</span> as blocked.
            </p>
            <div className="space-y-1.5">
              <Label htmlFor="detail-block-reason">Reason <span className="text-red-500">*</span></Label>
              <Input id="detail-block-reason" placeholder="Describe why this task is blocked…" value={blockReason} onChange={e => setBlockReason(e.target.value)} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setBlockDialogOpen(false)}>Cancel</Button>
            <Button variant="destructive" disabled={!blockReason.trim() || blockMutation.isPending} onClick={() => blockMutation.mutate()}>
              Confirm Block
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Escalate dialog */}
      <Dialog open={escalateDialogOpen} onOpenChange={setEscalateDialogOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Raise Escalation</DialogTitle></DialogHeader>
          <div className="space-y-3 py-2">
            <div className="space-y-1.5">
              <Label htmlFor="escalate-level">Level</Label>
              <select
                id="escalate-level"
                value={escalateLevel}
                onChange={e => setEscalateLevel(e.target.value)}
                className="w-full px-3 py-2 border border-input rounded-md bg-background text-sm"
              >
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
                <option value="critical">Critical</option>
              </select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="escalate-reason">Reason <span className="text-red-500">*</span></Label>
              <Input id="escalate-reason" placeholder="Why is this task being escalated?" value={escalateReason} onChange={e => setEscalateReason(e.target.value)} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEscalateDialogOpen(false)}>Cancel</Button>
            <Button variant="destructive" disabled={!escalateReason.trim() || escalateMutation.isPending} onClick={() => escalateMutation.mutate()}>
              {escalateMutation.isPending ? 'Raising...' : 'Raise Escalation'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Link Document dialog */}
      <Dialog open={linkDocDialogOpen} onOpenChange={setLinkDocDialogOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Link Source Document</DialogTitle></DialogHeader>
          <div className="space-y-3 py-2">
            <div className="space-y-1.5">
              <Label htmlFor="link-doc-type">Document Type</Label>
              <select
                id="link-doc-type"
                value={linkDocType}
                onChange={e => setLinkDocType(e.target.value)}
                className="w-full px-3 py-2 border border-input rounded-md bg-background text-sm"
              >
                <option value="bill">Bill</option>
                <option value="invoice">Invoice</option>
                <option value="payment">Payment</option>
                <option value="journal_entry">Journal Entry</option>
                <option value="credit_note">Credit Note</option>
                <option value="debit_note">Debit Note</option>
              </select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="link-doc-id">Document ID <span className="text-red-500">*</span></Label>
              <Input id="link-doc-id" type="number" placeholder="e.g., 11" value={linkDocId} onChange={e => setLinkDocId(e.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="link-doc-title">Display Title (optional)</Label>
              <Input id="link-doc-title" placeholder="e.g., BILL-2026-0011" value={linkDocTitle} onChange={e => setLinkDocTitle(e.target.value)} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setLinkDocDialogOpen(false)}>Cancel</Button>
            <Button disabled={!linkDocId.trim() || linkDocMutation.isPending} onClick={() => linkDocMutation.mutate()}>
              {linkDocMutation.isPending ? 'Linking...' : 'Link Document'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Reassign dialog */}
      <Dialog open={reassignDialogOpen} onOpenChange={setReassignDialogOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Reassign Task</DialogTitle></DialogHeader>
          <div className="space-y-3 py-2">
            <p className="text-sm text-muted-foreground">
              Reassign <span className="font-medium text-foreground">"{task.title}"</span> to a different user.
            </p>
            <div className="space-y-1.5">
              <Label htmlFor="reassign-user">Assign to <span className="text-red-500">*</span></Label>
              <select
                id="reassign-user"
                value={reassignUserId}
                onChange={e => setReassignUserId(e.target.value)}
                className="w-full px-3 py-2 border border-input rounded-md bg-background text-sm"
              >
                <option value="">Select a user…</option>
                {users.map(u => (
                  <option key={u.id} value={String(u.id)}>{u.name} ({u.email})</option>
                ))}
              </select>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setReassignDialogOpen(false)}>Cancel</Button>
            <Button disabled={!reassignUserId || reassignMutation.isPending} onClick={() => reassignMutation.mutate()}>
              {reassignMutation.isPending ? 'Reassigning...' : 'Confirm Reassign'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
