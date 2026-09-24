import { useState } from 'react'
import { useParams, Link, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Badge } from '@/components/ui/badge'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { useToast } from '@/hooks/use-toast'
import { WorkOrderStatusBadge } from '@/components/field-service/WorkOrderStatusBadge'
import { WorkOrderPriorityIcon } from '@/components/field-service/WorkOrderPriorityIcon'
import { PartsTable } from '@/components/field-service/PartsTable'
import { TimeTracker } from '@/components/field-service/TimeTracker'
import { ChatterPanel } from '@/components/field-service/ChatterPanel'
import WorksheetForm from '@/components/field-service/WorksheetForm'
import SignaturePad from '@/components/signature/SignaturePad'
import fieldServiceApi, { type WorkOrderStatus, type WorksheetResponse } from '@/lib/fieldServiceApi'

// ─── Worksheet Tab ────────────────────────────────────────────────────────────

function WorksheetTab({ workOrderId, readOnly }: { workOrderId: number; readOnly: boolean }) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [formValues, setFormValues] = useState<Record<string, unknown>>({})
  const [techSig, setTechSig] = useState('')
  const [custSig, setCustSig] = useState('')

  const { data: wsData, isLoading } = useQuery({
    queryKey: ['worksheet', workOrderId],
    queryFn: () => fieldServiceApi.getWorksheet(workOrderId).then(r => r.data),
  })

  const template = wsData?.template
  const response = wsData?.response as WorksheetResponse | null

  // Pre-fill form when response loaded
  useState(() => {
    if (response?.responses) setFormValues(response.responses as Record<string, unknown>)
    if (response?.technician_signature) setTechSig(response.technician_signature)
    if (response?.customer_signature) setTechSig(response.customer_signature)
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['worksheet', workOrderId] })

  const save = useMutation({
    mutationFn: () => fieldServiceApi.saveWorksheet(workOrderId, {
      template_id: template!.id,
      responses: formValues,
    }).then(r => r.data),
    onSuccess: () => { toast({ title: 'Worksheet saved' }); invalidate() },
    onError: () => toast({ title: 'Failed to save worksheet', variant: 'destructive' }),
  })

  const sign = useMutation({
    mutationFn: (payload: { signer: 'technician' | 'customer'; signature: string }) =>
      fieldServiceApi.signWorksheet(workOrderId, payload).then(r => r.data),
    onSuccess: () => { toast({ title: 'Signature saved' }); invalidate() },
    onError: () => toast({ title: 'Failed to save signature', variant: 'destructive' }),
  })

  if (isLoading) {
    return (
      <Card><CardContent className="p-8 flex items-center justify-center">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
      </CardContent></Card>
    )
  }

  if (!template) {
    return (
      <Card><CardContent className="p-8 text-center text-muted-foreground">
        <Icon icon="solar:document-text-bold-duotone" width={40} className="mx-auto opacity-30 mb-2" />
        <p className="text-sm">No worksheet template assigned to this job type.</p>
        <p className="text-xs mt-1">Assign a template via Field Service &gt; Worksheets.</p>
      </CardContent></Card>
    )
  }

  const isSigned = (response?.technician_signature && response?.customer_signature)
  const hasPdf = !!response?.pdf_path

  return (
    <div className="space-y-4">
      {/* Worksheet form */}
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-sm flex items-center gap-2">
            <Icon icon="solar:document-text-bold-duotone" width={16} />
            {template.name}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-5">
          <WorksheetForm
            fields={template.fields}
            values={formValues}
            onChange={(fieldId, value) => setFormValues(prev => ({ ...prev, [fieldId]: value }))}
            disabled={readOnly || !!isSigned}
          />
          {!readOnly && !isSigned && (
            <div className="mt-4 flex justify-end">
              <Button size="sm" onClick={() => save.mutate()} disabled={save.isPending}>
                {save.isPending ? 'Saving…' : 'Save Worksheet'}
              </Button>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Signatures */}
      {response && (
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm flex items-center gap-2">
              <Icon icon="solar:pen-bold-duotone" width={16} />
              Signatures
            </CardTitle>
          </CardHeader>
          <CardContent className="p-5 space-y-5">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <SignaturePad
                  label="Technician Signature"
                  value={response.technician_signature ?? techSig}
                  onChange={v => setTechSig(v ?? '')}
                  disabled={readOnly || !!response.technician_signature}
                />
                {!response.technician_signature && !readOnly && (
                  <Button
                    size="sm"
                    className="mt-2"
                    disabled={!techSig || sign.isPending}
                    onClick={() => sign.mutate({ signer: 'technician', signature: techSig })}
                  >
                    Confirm Technician Signature
                  </Button>
                )}
                {response.technician_signature && (
                  <p className="text-xs text-green-600 mt-1 flex items-center gap-1">
                    <Icon icon="solar:check-circle-bold" width={12} /> Signed
                  </p>
                )}
              </div>

              <div>
                <SignaturePad
                  label="Customer Signature"
                  value={response.customer_signature ?? custSig}
                  onChange={v => setCustSig(v ?? '')}
                  disabled={readOnly || !!response.customer_signature}
                />
                {!response.customer_signature && !readOnly && (
                  <Button
                    size="sm"
                    className="mt-2"
                    disabled={!custSig || sign.isPending}
                    onClick={() => sign.mutate({ signer: 'customer', signature: custSig })}
                  >
                    Confirm Customer Signature
                  </Button>
                )}
                {response.customer_signature && (
                  <p className="text-xs text-green-600 mt-1 flex items-center gap-1">
                    <Icon icon="solar:check-circle-bold" width={12} /> Signed
                  </p>
                )}
              </div>
            </div>

            {isSigned && (
              <div className="flex items-center gap-3 p-3 bg-green-50 dark:bg-green-950/20 rounded-lg border border-green-200 dark:border-green-900">
                <Icon icon="solar:check-circle-bold-duotone" width={20} className="text-green-600 flex-shrink-0" />
                <p className="text-sm text-green-700 dark:text-green-400 flex-1">
                  Worksheet fully signed by both parties.
                </p>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => window.open(fieldServiceApi.getWorksheetPdfUrl(workOrderId), '_blank')}
                >
                  <Icon icon="solar:file-download-bold-duotone" width={14} className="mr-1.5" />
                  Download PDF
                </Button>
              </div>
            )}

            {hasPdf && !isSigned && (
              <Button
                size="sm"
                variant="outline"
                onClick={() => window.open(fieldServiceApi.getWorksheetPdfUrl(workOrderId), '_blank')}
              >
                <Icon icon="solar:file-download-bold-duotone" width={14} className="mr-1.5" />
                Download PDF
              </Button>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  )
}

// ─── Main Page ────────────────────────────────────────────────────────────────

const NEXT_STATUS_TRANSITIONS: Record<WorkOrderStatus, { label: string; next: WorkOrderStatus }[]> = {
  draft:       [{ label: 'Schedule',     next: 'scheduled'   }, { label: 'Cancel', next: 'cancelled' }],
  scheduled:   [{ label: 'Start Work',   next: 'in_progress' }, { label: 'Cancel', next: 'cancelled' }],
  in_progress: [{ label: 'Put On Hold',  next: 'on_hold'     }, { label: 'Complete', next: 'completed' }],
  on_hold:     [{ label: 'Resume',       next: 'in_progress' }, { label: 'Cancel', next: 'cancelled'  }],
  completed:   [{ label: 'Generate Invoice', next: 'invoiced' }],
  invoiced:    [],
  cancelled:   [],
}

export function WorkOrderViewPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [statusNote, setStatusNote] = useState('')
  const [statusDialogOpen, setStatusDialogOpen] = useState(false)
  const [pendingStatus, setPendingStatus] = useState<WorkOrderStatus | null>(null)

  const { data: wo, isLoading } = useQuery({
    queryKey: ['work-order', Number(id)],
    queryFn: () => fieldServiceApi.getWorkOrder(Number(id)).then(r => r.data),
    enabled: !!id,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['work-order', Number(id)] })

  const updateStatus = useMutation({
    mutationFn: ({ status, notes }: { status: WorkOrderStatus; notes?: string }) =>
      fieldServiceApi.updateStatus(Number(id), status, notes),
    onSuccess: () => {
      toast({ title: 'Status updated' })
      setStatusDialogOpen(false)
      setStatusNote('')
      invalidate()
    },
    onError: () => toast({ title: 'Failed to update status', variant: 'destructive' }),
  })

  const generateInvoice = useMutation({
    mutationFn: () => fieldServiceApi.generateInvoice(Number(id)),
    onSuccess: () => { toast({ title: 'Invoice generated' }); invalidate() },
    onError: () => toast({ title: 'Failed to generate invoice', variant: 'destructive' }),
  })

  if (isLoading) {
    return (
      <div className="p-6 flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-primary" />
      </div>
    )
  }

  if (!wo) {
    return (
      <div className="p-6 text-center">
        <p className="text-muted-foreground">Work order not found.</p>
        <Button variant="outline" className="mt-4" onClick={() => navigate(-1)}>Go Back</Button>
      </div>
    )
  }

  const transitions = NEXT_STATUS_TRANSITIONS[wo.status] ?? []

  const openStatusDialog = (next: WorkOrderStatus) => {
    if (next === 'invoiced') {
      generateInvoice.mutate()
      return
    }
    setPendingStatus(next)
    setStatusDialogOpen(true)
  }

  const handleConfirmStatus = () => {
    if (pendingStatus) updateStatus.mutate({ status: pendingStatus, notes: statusNote })
  }

  return (
    <div className="p-6 space-y-5 max-w-5xl mx-auto">
      {/* Header */}
      <div className="flex items-start gap-3 flex-wrap">
        <Button variant="ghost" size="icon" onClick={() => navigate(-1)}>
          <Icon icon="solar:arrow-left-bold" width={20} />
        </Button>
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-xs text-muted-foreground font-mono">{wo.reference}</span>
            <WorkOrderStatusBadge status={wo.status} />
            <WorkOrderPriorityIcon priority={wo.priority} showLabel />
          </div>
          <h1 className="text-xl font-bold mt-1 leading-snug">{wo.title}</h1>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          {transitions.map(t => (
            <Button
              key={t.next}
              size="sm"
              variant={t.next === 'cancelled' ? 'destructive' : 'default'}
              onClick={() => openStatusDialog(t.next)}
              disabled={updateStatus.isPending || generateInvoice.isPending}
            >
              {t.label}
            </Button>
          ))}
          <Button variant="outline" size="sm" asChild>
            <Link to={`/field-service/work-orders/${wo.id}/edit`}>
              <Icon icon="solar:pen-bold-duotone" width={14} className="mr-1" />
              Edit
            </Link>
          </Button>
        </div>
      </div>

      {/* Summary cards */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        {[
          { label: 'Customer', value: wo.customer?.name ?? '—', icon: 'solar:user-bold-duotone' },
          { label: 'Type',     value: wo.type?.name ?? '—',     icon: 'solar:tools-bold-duotone' },
          { label: 'Team',     value: wo.team?.name ?? '—',     icon: 'solar:people-nearby-bold-duotone' },
          { label: 'Location', value: wo.location_address ?? '—', icon: 'solar:map-point-bold-duotone' },
        ].map(item => (
          <Card key={item.label} className="border border-border/60">
            <CardContent className="p-3 flex items-center gap-2">
              <Icon icon={item.icon} width={16} className="text-muted-foreground flex-shrink-0" />
              <div className="min-w-0">
                <p className="text-xs text-muted-foreground">{item.label}</p>
                <p className="text-sm font-medium truncate">{item.value}</p>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Tabs */}
      <Tabs defaultValue="overview">
        <TabsList>
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="parts">Parts & Materials</TabsTrigger>
          <TabsTrigger value="timesheets">Timesheets</TabsTrigger>
          <TabsTrigger value="worksheet">Worksheet</TabsTrigger>
          <TabsTrigger value="chatter">Chatter</TabsTrigger>
          {wo.invoice && <TabsTrigger value="invoice">Invoice</TabsTrigger>}
        </TabsList>

        <TabsContent value="overview" className="mt-4 space-y-4">
          <Card>
            <CardContent className="p-5 space-y-3">
              {wo.description && (
                <div>
                  <p className="text-xs text-muted-foreground uppercase tracking-wide mb-1">Description</p>
                  <p className="text-sm whitespace-pre-wrap">{wo.description}</p>
                </div>
              )}
              <div className="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <p className="text-xs text-muted-foreground">Scheduled</p>
                  <p>{wo.scheduled_start ? new Date(wo.scheduled_start).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : '—'}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Est. Duration</p>
                  <p>{wo.estimated_duration_hours ? `${wo.estimated_duration_hours}h` : '—'}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Actual Start</p>
                  <p>{wo.actual_start ? new Date(wo.actual_start).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : '—'}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Completed At</p>
                  <p>{wo.completed_at ? new Date(wo.completed_at).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : '—'}</p>
                </div>
              </div>

              {wo.technicians && wo.technicians.length > 0 && (
                <div>
                  <p className="text-xs text-muted-foreground uppercase tracking-wide mb-1">Assigned Technicians</p>
                  <div className="flex flex-wrap gap-2">
                    {wo.technicians.map((t: any) => (
                      <Badge key={t.id} variant="secondary">{t.user?.name}</Badge>
                    ))}
                  </div>
                </div>
              )}
            </CardContent>
          </Card>

          {/* Financial summary */}
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm">Financial Summary</CardTitle></CardHeader>
            <CardContent className="p-5 space-y-2 text-sm">
              <div className="flex justify-between"><span className="text-muted-foreground">Parts & Materials</span><span>{Number(wo.total_parts_amount).toFixed(2)}</span></div>
              <div className="flex justify-between"><span className="text-muted-foreground">Labor</span><span>{Number(wo.total_labor_amount).toFixed(2)}</span></div>
              <div className="flex justify-between font-semibold border-t pt-2"><span>Total</span><span>{Number(wo.total_amount).toFixed(2)}</span></div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="parts" className="mt-4">
          <Card>
            <CardContent className="p-5">
              <PartsTable
                workOrderId={wo.id}
                parts={wo.parts ?? []}
                readOnly={['invoiced', 'cancelled'].includes(wo.status)}
              />
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="timesheets" className="mt-4">
          <Card>
            <CardContent className="p-5">
              <TimeTracker
                workOrderId={wo.id}
                timeLogs={wo.timeLogs ?? []}
                readOnly={['invoiced', 'cancelled'].includes(wo.status)}
              />
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="worksheet" className="mt-4">
          <WorksheetTab workOrderId={wo.id} readOnly={['invoiced', 'cancelled'].includes(wo.status)} />
        </TabsContent>

        <TabsContent value="chatter" className="mt-4">
          <Card>
            <CardContent className="p-5">
              <ChatterPanel workOrderId={wo.id} activities={wo.activities ?? []} />
            </CardContent>
          </Card>
        </TabsContent>

        {wo.invoice && (
          <TabsContent value="invoice" className="mt-4">
            <Card>
              <CardContent className="p-5 flex items-center gap-4">
                <Icon icon="solar:bill-list-bold-duotone" width={32} className="text-primary" />
                <div>
                  <p className="text-sm text-muted-foreground">Invoice Number</p>
                  <p className="font-semibold">{wo.invoice.number}</p>
                </div>
                <Button size="sm" variant="outline" asChild className="ml-auto">
                  <Link to={`/invoices/${wo.invoice.id}`}>View Invoice</Link>
                </Button>
              </CardContent>
            </Card>
          </TabsContent>
        )}
      </Tabs>

      {/* Status change dialog */}
      <Dialog open={statusDialogOpen} onOpenChange={setStatusDialogOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Change Status to "{pendingStatus?.replace(/_/g, ' ')}"</DialogTitle>
          </DialogHeader>
          <div>
            <Label>Notes (optional)</Label>
            <Textarea
              placeholder="Add notes for this status change..."
              value={statusNote}
              onChange={e => setStatusNote(e.target.value)}
              rows={3}
              className="mt-1"
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setStatusDialogOpen(false)}>Cancel</Button>
            <Button onClick={handleConfirmStatus} disabled={updateStatus.isPending}>
              {updateStatus.isPending ? 'Updating...' : 'Confirm'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
