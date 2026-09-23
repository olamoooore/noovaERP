import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  closeCyclesApi,
  checklistTemplatesApi,
  entityManagementApi,
  fiscalPeriodsApi,
  type CloseCycle,
  type ChecklistTemplate,
  type LegalEntity,
  type EntityBook,
  type FiscalPeriod,
} from '@/services/closeApi'
import { useToast } from '@/hooks/use-toast'
import { useState, useMemo } from 'react'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'

const cycleStatusConfig: Record<string, { label: string; color: string; icon: string }> = {
  draft:          { label: 'Draft',          color: 'secondary', icon: 'solar:document-bold-duotone' },
  opened:         { label: 'In Progress',    color: 'default',   icon: 'solar:play-circle-bold-duotone' },
  ready_to_close: { label: 'Ready to Close', color: 'warning',   icon: 'solar:check-circle-bold-duotone' },
  closed:         { label: 'Closed',         color: 'success',   icon: 'solar:lock-bold-duotone' },
}

const CUSTOM_PERIOD_VALUE = 'custom-period'

export function CloseCyclesPage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [createOpen, setCreateOpen] = useState(false)
  const [archiveTarget, setArchiveTarget] = useState<{ id: string; label?: string | null } | null>(null)

  // Create form state
  const [selectedEntityId, setSelectedEntityId] = useState('')
  const [selectedBookId, setSelectedBookId] = useState('')
  const [selectedPeriodValue, setSelectedPeriodValue] = useState('')
  const [customPeriod, setCustomPeriod] = useState('')
  const [cycleName, setCycleName] = useState('')
  const [selectedTemplateId, setSelectedTemplateId] = useState('none')

  const { data, isLoading } = useQuery({
    queryKey: ['close-cycles'],
    queryFn: () => closeCyclesApi.list().then(r => r.data.data),
  })

  const { data: templatesData } = useQuery({
    queryKey: ['checklist-templates'],
    queryFn: () => checklistTemplatesApi.list('active').then(r => r.data.data),
  })
  const templates: ChecklistTemplate[] = templatesData ?? []

  const { data: entitiesData } = useQuery({
    queryKey: ['legal-entities'],
    queryFn: () => entityManagementApi.listEntities().then(r => r.data),
  })
  const entities: LegalEntity[] = useMemo(() => {
    const raw = entitiesData ?? []
    return Array.isArray(raw) ? raw : (raw as any).data ?? []
  }, [entitiesData])

  const selectedEntity = useMemo(
    () => entities.find(e => e.id.toString() === selectedEntityId),
    [entities, selectedEntityId]
  )

  const { data: booksData } = useQuery({
    queryKey: ['entity-books', selectedEntityId],
    queryFn: () => entityManagementApi.listBooks(Number(selectedEntityId)).then(r => r.data),
    enabled: !!selectedEntityId,
  })
  const books: EntityBook[] = useMemo(() => {
    const raw = booksData ?? []
    return Array.isArray(raw) ? raw : (raw as any).data ?? []
  }, [booksData])

  const { data: periodsData } = useQuery({
    queryKey: ['fiscal-periods', 'open'],
    queryFn: () => fiscalPeriodsApi.list({ status: 'open' }).then(r => r.data.data),
  })
  const periods: FiscalPeriod[] = periodsData ?? []

  const createMutation = useMutation({
    mutationFn: () => {
      const periodId = selectedPeriodValue === CUSTOM_PERIOD_VALUE
        ? customPeriod.trim()
        : selectedPeriodValue
      return closeCyclesApi.create({
        entity_id: selectedEntity?.code || selectedEntityId,
        period_id: periodId,
        name: cycleName.trim() || undefined,
        accounting_book_id: selectedBookId || undefined,
        template_id: selectedTemplateId === 'none' ? undefined : selectedTemplateId,
      }).then(r => r.data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['close-cycles'] })
      setCreateOpen(false)
      resetCreateForm()
      toast({ title: 'Cycle created.' })
    },
    onError: (error: any) => toast({
      title: 'Failed to create cycle.',
      description: error?.message || error?.response?.data?.message || 'Please check your inputs.',
      variant: 'destructive',
    }),
  })

  const resetCreateForm = () => {
    setSelectedEntityId('')
    setSelectedBookId('')
    setSelectedPeriodValue('')
    setCustomPeriod('')
    setCycleName('')
    setSelectedTemplateId('none')
  }

  const openMutation = useMutation({
    mutationFn: (id: string) => closeCyclesApi.open(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['close-cycles'] })
      toast({ title: 'Cycle opened.' })
    },
    onError: () => toast({ title: 'Could not open cycle.', variant: 'destructive' }),
  })

  const closeMutation = useMutation({
    mutationFn: (id: string) => closeCyclesApi.close(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['close-cycles'] })
      toast({ title: 'Cycle closed.' })
    },
    onError: () => toast({ title: 'Cycle is not ready to close.', variant: 'destructive' }),
  })

  const archiveMutation = useMutation({
    mutationFn: (id: string) => closeCyclesApi.archive(id, 'Archived from cycle list'),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['close-cycles'] })
      toast({ title: 'Cycle archived.', description: 'The period is now free to start a new close cycle.' })
    },
    onError: (error: any) => toast({
      title: 'Could not archive cycle.',
      description: error?.response?.data?.message || 'Please try again.',
      variant: 'destructive',
    }),
  })

  const signoffMutation = useMutation({
    mutationFn: ({ cycleId, role }: { cycleId: string; role: string }) =>
      closeCyclesApi.signoff(cycleId, role),
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['close-cycles'] })
      queryClient.invalidateQueries({ queryKey: ['signoff-check', signoffCycleId] })
      const ready = res?.data?.ready_to_close
      const count = res?.data?.signoff_count
      toast({
        title: ready
          ? 'Sign-off recorded. Cycle is now ready to close.'
          : `Sign-off recorded (${count}). Complete remaining tasks to unlock close.`,
      })
    },
    onError: (err: { response?: { data?: { message?: string; incomplete_tasks?: number; open_escalations?: number } } }) => {
      const msg = err?.response?.data?.message ?? 'Sign-off failed.'
      const incomplete = err?.response?.data?.incomplete_tasks
      const escalations = err?.response?.data?.open_escalations
      const details = [
        incomplete ? `${incomplete} incomplete task(s)` : '',
        escalations ? `${escalations} open escalation(s)` : '',
      ].filter(Boolean).join(', ')
      toast({
        title: msg,
        description: details ? `Blockers: ${details}` : undefined,
        variant: 'destructive',
      })
    },
  })

  const generateTasksMutation = useMutation({
    mutationFn: ({ cycleId, templateId }: { cycleId: string; templateId?: string }) =>
      closeCyclesApi.generateTasks(cycleId, templateId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['close-cycles'] })
      queryClient.invalidateQueries({ queryKey: ['close-tasks'] })
      toast({ title: 'Tasks generated successfully.' })
    },
    onError: (error: any) => toast({
      title: 'Task generation failed.',
      description: error?.response?.data?.message || 'Ensure a checklist template is selected and active.',
      variant: 'destructive',
    }),
  })

  const cycles: CloseCycle[] = data ?? []

  // Sign-off confirmation dialog state
  const [signoffDialogOpen, setSignoffDialogOpen] = useState(false)
  const [signoffCycleId, setSignoffCycleId] = useState<string | null>(null)

  const { data: signoffCheckData, isLoading: signoffCheckLoading } = useQuery({
    queryKey: ['signoff-check', signoffCycleId],
    queryFn: () => closeCyclesApi.signoffCheck(signoffCycleId!).then(r => r.data.data),
    enabled: !!signoffCycleId && signoffDialogOpen,
  })

  const canSignoff = signoffCheckData?.can_signoff ?? false
  const signoffEvaluation = signoffCheckData?.evaluation

  function openSignoffDialog(cycleId: string) {
    setSignoffCycleId(cycleId)
    setSignoffDialogOpen(true)
  }

  function confirmSignoff() {
    if (!signoffCycleId) return
    signoffMutation.mutate({ cycleId: signoffCycleId, role: 'Admin' })
    setSignoffDialogOpen(false)
    setSignoffCycleId(null)
  }

  const isCreateDisabled =
    !selectedEntityId ||
    !selectedBookId ||
    (!selectedPeriodValue || (selectedPeriodValue === CUSTOM_PERIOD_VALUE && !customPeriod.trim())) ||
    createMutation.isPending

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Close Cycles</h1>
          <p className="text-muted-foreground mt-1">Manage financial close cycles for each reporting period.</p>
        </div>
        <Button onClick={() => setCreateOpen(true)}>
          <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
          New Cycle
        </Button>
      </div>

      {isLoading ? (
        <div className="flex items-center gap-2 text-muted-foreground py-12 justify-center">
          <Icon icon="solar:spinner-bold" className="h-5 w-5 animate-spin" />
          Loading cycles…
        </div>
      ) : cycles.length === 0 ? (
        <Card>
          <CardContent className="py-16 text-center text-muted-foreground">
            <Icon icon="solar:calendar-date-bold-duotone" className="h-10 w-10 mx-auto mb-3 opacity-40" />
            <p className="font-medium">No close cycles yet.</p>
            <p className="text-sm mt-1">Create a cycle to begin the financial close process.</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4">
          {cycles.map((cycle) => {
            const cfg = cycleStatusConfig[cycle.status] ?? cycleStatusConfig.draft
            return (
              <Card key={cycle.id} className="hover:shadow-md transition-shadow">
                <CardContent className="pt-5 pb-4">
                  <div className="flex items-start justify-between gap-4">
                    <div className="flex items-start gap-3">
                      <div className="mt-0.5 p-2 rounded-lg bg-primary/10 text-primary">
                        <Icon icon={cfg.icon} className="h-5 w-5" />
                      </div>
                      <div>
                        <p className="font-semibold text-base">
                          {cycle.name || `Period: ${cycle.period_id}`}
                        </p>
                        <p className="text-sm text-muted-foreground">
                          Entity: {cycle.entity_id}{' '}
                          {cycle.accounting_book_id && `| Book ID: ${cycle.accounting_book_id}`}
                        </p>
                        <p className="text-sm text-muted-foreground">
                          Period: {cycle.period_id}
                        </p>
                        {cycle.metadata && Array.isArray((cycle.metadata as Record<string, unknown>)?.cycle_signoffs) && (
                          <p className="text-xs text-blue-600 mt-1 flex items-center gap-1">
                            <Icon icon="solar:verified-check-bold" className="h-3.5 w-3.5" />
                            {((cycle.metadata as Record<string, unknown[]>).cycle_signoffs as unknown[]).length} sign-off(s) recorded
                          </p>
                        )}
                        {cycle.opened_at && (
                          <p className="text-xs text-muted-foreground mt-1">
                            Opened {new Date(cycle.opened_at).toLocaleDateString()}
                          </p>
                        )}
                        {cycle.closed_at && (
                          <p className="text-xs text-muted-foreground">
                            Closed {new Date(cycle.closed_at).toLocaleDateString()}
                          </p>
                        )}
                      </div>
                    </div>

                    <div className="flex items-center gap-2 flex-shrink-0">
                      <Badge variant={cfg.color as 'default' | 'secondary' | 'destructive' | 'outline'}>
                        {cfg.label}
                      </Badge>

                      {cycle.status === 'draft' && (
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => openMutation.mutate(cycle.id)}
                          disabled={openMutation.isPending}
                        >
                          Open Cycle
                        </Button>
                      )}

                      {['draft', 'opened', 'ready_to_close'].includes(cycle.status) && (
                        <Button
                          size="sm"
                          variant="ghost"
                          className="text-muted-foreground hover:text-red-600"
                          disabled={archiveMutation.isPending}
                          onClick={() => setArchiveTarget({ id: cycle.id, label: cycle.name })}
                        >
                          <Icon icon="solar:archive-bold-duotone" className="mr-1.5 h-4 w-4" />
                          Archive
                        </Button>
                      )}

                      {(cycle.status === 'opened' || cycle.status === 'ready_to_close') && (
                        <>
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => navigate(`/financial-close/tasks?cycleId=${cycle.id}`)}
                          >
                            <Icon icon="solar:list-check-bold-duotone" className="mr-1.5 h-4 w-4" />
                            Tasks
                          </Button>
                          {!cycle.template_id && templates.length > 0 && (
                            <Select
                              value={''}
                              onValueChange={(templateId) => generateTasksMutation.mutate({ cycleId: cycle.id, templateId })}
                            >
                              <SelectTrigger className="h-8 w-auto text-xs">
                                <Icon icon="solar:bolt-bold-duotone" className="mr-1 h-3 w-3" />
                                <SelectValue placeholder="Generate Tasks" />
                              </SelectTrigger>
                              <SelectContent>
                                {templates.map(t => (
                                  <SelectItem key={t.id} value={t.id}>{t.name}</SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                          )}
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => navigate(`/financial-close/forecast?cycleId=${cycle.id}`)}
                          >
                            <Icon icon="solar:chart-2-bold-duotone" className="mr-1.5 h-4 w-4" />
                            Forecast
                          </Button>
                          <Button
                            size="sm"
                            variant="outline"
                            className="border-blue-300 text-blue-700 hover:bg-blue-50"
                            onClick={() => openSignoffDialog(cycle.id)}
                            disabled={signoffMutation.isPending}
                          >
                            <Icon icon="solar:verified-check-bold-duotone" className="mr-1.5 h-4 w-4" />
                            Sign Off
                          </Button>
                          {cycle.status === 'ready_to_close' && (
                            <Button
                              size="sm"
                              onClick={() => closeMutation.mutate(cycle.id)}
                              disabled={closeMutation.isPending}
                            >
                              <Icon icon="solar:lock-bold-duotone" className="mr-1.5 h-4 w-4" />
                              Authorize Close
                            </Button>
                          )}
                        </>
                      )}
                    </div>
                  </div>
                </CardContent>
              </Card>
            )
          })}
        </div>
      )}

      <Dialog open={createOpen} onOpenChange={(open) => { if (!open) { setCreateOpen(false); resetCreateForm(); } }}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>New Close Cycle</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="space-y-1.5">
              <Label htmlFor="entity">Company / Entity *</Label>
              <Select value={selectedEntityId} onValueChange={(v) => { setSelectedEntityId(v); setSelectedBookId(''); }}>
                <SelectTrigger id="entity">
                  <SelectValue placeholder="Select a legal entity" />
                </SelectTrigger>
                <SelectContent>
                  {entities.map(e => (
                    <SelectItem key={e.id} value={e.id.toString()}>{e.name} ({e.code})</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {entities.length === 0 && (
                <p className="text-xs text-destructive">No legal entities found. Create one under Accounting → Multi-Entity Management.</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="book">Accounting Book *</Label>
              <Select value={selectedBookId} onValueChange={setSelectedBookId} disabled={!selectedEntityId}>
                <SelectTrigger id="book">
                  <SelectValue placeholder={selectedEntityId ? "Select an accounting book" : "Select an entity first"} />
                </SelectTrigger>
                <SelectContent>
                  {books.map(b => (
                    <SelectItem key={b.id} value={b.id.toString()}>{b.name} ({b.book_type})</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {selectedEntityId && books.length === 0 && (
                <p className="text-xs text-destructive">No books for this entity. Create one under Accounting → Multi-Entity Management.</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="period">Close Period *</Label>
              <Select value={selectedPeriodValue} onValueChange={setSelectedPeriodValue}>
                <SelectTrigger id="period">
                  <SelectValue placeholder="Select a fiscal period" />
                </SelectTrigger>
                <SelectContent>
                  {periods.map(p => (
                    <SelectItem key={p.id} value={p.code}>{p.name} ({p.code})</SelectItem>
                  ))}
                  <SelectItem value={CUSTOM_PERIOD_VALUE}>Custom period…</SelectItem>
                </SelectContent>
              </Select>
              {periods.length === 0 && (
                <p className="text-xs text-muted-foreground">No open fiscal periods found. Use “Custom period” or create periods under Accounting.</p>
              )}
              {selectedPeriodValue === CUSTOM_PERIOD_VALUE && (
                <Input
                  placeholder="e.g. 2026_06_Testing1"
                  value={customPeriod}
                  onChange={e => setCustomPeriod(e.target.value)}
                  className="mt-2"
                />
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="name">Close Cycle Name</Label>
              <Input
                id="name"
                placeholder="e.g. FC-CLOSE-2026-06-JD"
                value={cycleName}
                onChange={e => setCycleName(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                Descriptive name for this close cycle. Defaults to the period if left blank.
              </p>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="template">Checklist Template</Label>
              <Select value={selectedTemplateId} onValueChange={setSelectedTemplateId}>
                <SelectTrigger id="template">
                  <SelectValue placeholder="Select a checklist template (optional)" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">None (manual later)</SelectItem>
                  {templates.map(t => (
                    <SelectItem key={t.id} value={t.id}>{t.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                Tasks will be auto-generated from this template when the cycle is opened.
              </p>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => { setCreateOpen(false); resetCreateForm(); }}>Cancel</Button>
            <Button
              onClick={() => createMutation.mutate()}
              disabled={isCreateDisabled}
            >
              Create Cycle
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Sign-off Confirmation Dialog */}
      <Dialog open={signoffDialogOpen} onOpenChange={(open) => { if (!open) { setSignoffDialogOpen(false); setSignoffCycleId(null); } }}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Icon icon="solar:verified-check-bold-duotone" className="h-5 w-5 text-blue-600" />
              Confirm Sign-Off
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-2">
            {signoffCheckLoading ? (
              <div className="flex items-center gap-2 text-muted-foreground py-4 justify-center">
                <Icon icon="solar:spinner-bold" className="h-4 w-4 animate-spin" />
                Checking blockers...
              </div>
            ) : signoffEvaluation ? (
              <>
                {!canSignoff && (
                  <div className="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950/30">
                    <div className="flex items-start gap-3">
                      <Icon icon="solar:danger-bold" className="h-5 w-5 text-red-500 mt-0.5 flex-shrink-0" />
                      <div>
                        <p className="font-medium text-red-700 dark:text-red-400">Sign-off Blocked</p>
                        <p className="text-sm text-red-600 dark:text-red-300 mt-1">
                          All tasks must be completed and escalations resolved before sign-off.
                        </p>
                        <ul className="mt-2 space-y-1 text-sm text-red-600 dark:text-red-300">
                          {signoffEvaluation.incomplete_tasks > 0 && (
                            <li className="flex items-center gap-2">
                              <span className="font-mono text-xs bg-red-100 dark:bg-red-900/50 px-1.5 py-0.5 rounded">{signoffEvaluation.incomplete_tasks}</span>
                              incomplete task(s)
                            </li>
                          )}
                          {signoffEvaluation.open_escalations > 0 && (
                            <li className="flex items-center gap-2">
                              <span className="font-mono text-xs bg-red-100 dark:bg-red-900/50 px-1.5 py-0.5 rounded">{signoffEvaluation.open_escalations}</span>
                              open escalation(s)
                            </li>
                          )}
                        </ul>
                      </div>
                    </div>
                  </div>
                )}
                {canSignoff && (
                  <div className="rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950/30">
                    <div className="flex items-start gap-3">
                      <Icon icon="solar:check-circle-bold" className="h-5 w-5 text-green-500 mt-0.5 flex-shrink-0" />
                      <div>
                        <p className="font-medium text-green-700 dark:text-green-400">Ready to Sign Off</p>
                        <p className="text-sm text-green-600 dark:text-green-300 mt-1">
                          All tasks are complete and escalations resolved.
                        </p>
                        <ul className="mt-2 space-y-1 text-sm text-green-600 dark:text-green-300">
                          <li className="flex items-center gap-2">
                            <Icon icon="solar:check-read-bold" className="h-3.5 w-3.5" />
                            All tasks completed
                          </li>
                          <li className="flex items-center gap-2">
                            <Icon icon="solar:check-read-bold" className="h-3.5 w-3.5" />
                            No open escalations
                          </li>
                          <li className="flex items-center gap-2">
                            <Icon icon="solar:check-read-bold" className="h-3.5 w-3.5" />
                            {signoffEvaluation.authorised_signoffs} / {signoffEvaluation.required_signoffs} sign-off(s)
                          </li>
                        </ul>
                      </div>
                    </div>
                  </div>
                )}
                <p className="text-sm text-muted-foreground">
                  By signing off, you attest that all reconciliation work for this cycle is complete and accurate.
                </p>
              </>
            ) : (
              <p className="text-sm text-muted-foreground py-4 text-center">Unable to check blockers.</p>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => { setSignoffDialogOpen(false); setSignoffCycleId(null); }}>Cancel</Button>
            <Button
              onClick={confirmSignoff}
              disabled={!canSignoff || signoffCheckLoading || signoffMutation.isPending}
              className={!canSignoff ? 'opacity-50 cursor-not-allowed' : ''}
            >
              {signoffMutation.isPending ? (
                <>
                  <Icon icon="solar:spinner-bold" className="mr-2 h-4 w-4 animate-spin" />
                  Signing Off...
                </>
              ) : (
                <>
                  <Icon icon="solar:verified-check-bold-duotone" className="mr-2 h-4 w-4" />
                  Confirm Sign-Off
                </>
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={!!archiveTarget}
        onOpenChange={(open) => !open && setArchiveTarget(null)}
        title="Archive this close cycle?"
        description={
          <>
            {archiveTarget?.label ? (
              <>
                Cycle <span className="font-medium text-foreground">{archiveTarget.label}</span> — its period
                {' '}
              </>
            ) : (
              'Its period '
            )}
            will be freed so a new cycle can start. The record is kept for audit — nothing is deleted.
          </>
        }
        confirmLabel="Archive cycle"
        cancelLabel="Keep cycle"
        tone="warning"
        icon="solar:archive-bold-duotone"
        isPending={archiveMutation.isPending}
        onConfirm={() => {
          const target = archiveTarget
          setArchiveTarget(null)
          if (target) archiveMutation.mutate(target.id)
        }}
      />
    </div>
  )
}
