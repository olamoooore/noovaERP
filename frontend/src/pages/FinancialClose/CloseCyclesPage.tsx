import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { closeCyclesApi, checklistTemplatesApi, type CloseCycle, type ChecklistTemplate } from '@/services/closeApi'
import { useToast } from '@/hooks/use-toast'
import { useState } from 'react'
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

const ENTITY_ID = 'entity-001' // default entity for this tenant

const cycleStatusConfig: Record<string, { label: string; color: string; icon: string }> = {
  draft:          { label: 'Draft',          color: 'secondary', icon: 'solar:document-bold-duotone' },
  opened:         { label: 'In Progress',    color: 'default',   icon: 'solar:play-circle-bold-duotone' },
  ready_to_close: { label: 'Ready to Close', color: 'warning',   icon: 'solar:check-circle-bold-duotone' },
  closed:         { label: 'Closed',         color: 'success',   icon: 'solar:lock-bold-duotone' },
}

export function CloseCyclesPage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [createOpen, setCreateOpen] = useState(false)
  const [periodId, setPeriodId] = useState('')
  const [selectedTemplateId, setSelectedTemplateId] = useState('none')

  const { data, isLoading } = useQuery({
    queryKey: ['close-cycles', ENTITY_ID],
    queryFn: () => closeCyclesApi.list(ENTITY_ID).then(r => r.data.data),
  })

  const { data: templatesData } = useQuery({
    queryKey: ['checklist-templates'],
    queryFn: () => checklistTemplatesApi.list('active').then(r => r.data.data),
  })
  const templates: ChecklistTemplate[] = templatesData ?? []

  const createMutation = useMutation({
    mutationFn: () => closeCyclesApi.create(ENTITY_ID, periodId, selectedTemplateId === 'none' ? undefined : selectedTemplateId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['close-cycles'] })
      setCreateOpen(false)
      setPeriodId('')
      setSelectedTemplateId('none')
      toast({ title: 'Cycle created.' })
    },
    onError: () => toast({ title: 'Failed to create cycle.', variant: 'destructive' }),
  })

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
                        <p className="font-semibold text-base">Period: {cycle.period_id}</p>
                        <p className="text-sm text-muted-foreground">Entity: {cycle.entity_id}</p>
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

      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>New Close Cycle</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="space-y-1.5">
              <Label htmlFor="period">Period ID</Label>
              <Input
                id="period"
                placeholder="e.g. 2026-Q1 or 2026-03"
                value={periodId}
                onChange={e => setPeriodId(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                Uniquely identifies this reporting period within the entity.
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
            <Button variant="outline" onClick={() => setCreateOpen(false)}>Cancel</Button>
            <Button
              onClick={() => createMutation.mutate()}
              disabled={!periodId.trim() || createMutation.isPending}
            >
              Create Cycle
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
