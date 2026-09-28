import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency, formatDate } from '@/lib/utils'
import api from '@/lib/api'

interface ApprovalRequest {
  id: number
  request_type: string
  subject_type: string
  subject_id: number
  request_number: string
  status: 'pending' | 'approved' | 'rejected' | 'expired' | 'withdrawn'
  amount: number | string
  reason: string
  notes: string | null
  review_notes: string | null
  metadata: Record<string, any> | null
  requested_by: {
    id: number
    name: string
    email: string
  }
  requested_at: string
  reviewed_by: {
    id: number
    name: string
    email: string
  } | null
  reviewed_at: string | null
  expires_at: string | null
  invoice?: {
    id: number
    invoice_number: string
    customer?: { id: number; name: string }
    total: number | string
    currency_code: string
  }
}

const statusVariant = (status: string) => {
  switch (status) {
    case 'pending': return 'outline'
    case 'approved': return 'default'
    case 'rejected': return 'destructive'
    case 'expired': return 'secondary'
    case 'withdrawn': return 'secondary'
    default: return 'secondary'
  }
}

export default function ApprovalsInboxPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const [tab, setTab] = useState<'pending' | 'history'>('pending')
  const [decisionDialog, setDecisionDialog] = useState<{
    request: ApprovalRequest
    action: 'approve' | 'reject'
  } | null>(null)
  const [decisionNotes, setDecisionNotes] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['approvals', tab],
    queryFn: async () => {
      if (tab === 'pending') {
        const res = await api.get('/approvals')
        return { data: res.data.data ?? [], count: res.data.count ?? 0 }
      }
      const res = await api.get('/approvals/my-requests', { params: { status: 'all' } })
      const items = Array.isArray(res.data?.data) ? res.data.data : (Array.isArray(res.data) ? res.data : [])
      return { data: items, count: items.length }
    },
    refetchInterval: 30000,
  })

  const decisionMutation = useMutation({
    mutationFn: async ({ id, action, notes }: { id: number; action: 'approve' | 'reject'; notes: string }) => {
      if (action === 'approve') {
        const res = await api.post(`/approvals/${id}/approve`, { notes })
        return res.data
      }
      const res = await api.post(`/approvals/${id}/reject`, { reason: notes })
      return res.data
    },
    onSuccess: (_data, vars) => {
      queryClient.invalidateQueries({ queryKey: ['approvals'] })
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
      toast({
        title: vars.action === 'approve' ? 'Request approved' : 'Request rejected',
        description: 'The requester has been notified.',
      })
      setDecisionDialog(null)
      setDecisionNotes('')
    },
    onError: (error: any, vars) => {
      toast({
        variant: 'destructive',
        title: vars.action === 'approve' ? 'Approval failed' : 'Rejection failed',
        description: error.response?.data?.error || error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const handleAction = (request: ApprovalRequest, action: 'approve' | 'reject') => {
    setDecisionDialog({ request, action })
    setDecisionNotes('')
  }

  const submitDecision = () => {
    if (!decisionDialog) return
    if (decisionDialog.action === 'reject' && decisionNotes.trim().length < 10) {
      toast({ variant: 'destructive', title: 'Reason required', description: 'Rejection reason must be at least 10 characters.' })
      return
    }
    decisionMutation.mutate({
      id: decisionDialog.request.id,
      action: decisionDialog.action,
      notes: decisionNotes,
    })
  }

  const items: ApprovalRequest[] = data?.data ?? []

  return (
    <div className="container mx-auto py-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Approvals Inbox</h1>
          <p className="text-sm text-muted-foreground">
            Invoices, quotations and delivery orders waiting for your decision
          </p>
        </div>
        <Button variant="outline" onClick={() => navigate('/notifications')}>
          <Icon icon="solar:bell-bold-duotone" className="mr-2 h-4 w-4" />
          View all notifications
        </Button>
      </div>

      <Tabs value={tab} onValueChange={(v: any) => setTab(v)}>
        <TabsList>
          <TabsTrigger value="pending">
            Pending
            <Badge variant="secondary" className="ml-2 h-4 px-1.5 text-[10px]">
              {tab === 'pending' ? items.length : ''}
            </Badge>
          </TabsTrigger>
          <TabsTrigger value="history">History</TabsTrigger>
        </TabsList>
      </Tabs>

      <Card>
        <CardHeader>
          <CardTitle>
            {tab === 'pending' ? 'Awaiting your decision' : 'Submitted by you'}
          </CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="flex items-center justify-center py-12">
              <Icon icon="eos-icons:loading" className="h-8 w-8 animate-spin text-primary" />
            </div>
          ) : items.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-16 text-muted-foreground">
              <Icon icon="solar:checklist-bold-duotone" className="mb-3 h-12 w-12 opacity-40" />
              <p className="text-sm">
                {tab === 'pending' ? 'No pending approvals' : 'No approval history yet'}
              </p>
            </div>
          ) : (
            <div className="space-y-3">
              {items.map((request) => {
                const meta = request.metadata || {}
                const type = request.request_type || ''
                const typeMeta = type.startsWith('delivery_order')
                  ? { label: 'Delivery Order', icon: 'solar:box-bold-duotone', path: `/delivery-orders/${request.subject_id}` }
                  : type.startsWith('quotation')
                    ? { label: 'Quotation', icon: 'solar:document-text-bold-duotone', path: `/quotations/${request.subject_id}` }
                    : { label: 'Invoice', icon: 'solar:bill-list-bold-duotone', path: `/invoices/${request.subject_id}` }
                const customerName = meta.customer_name || request.invoice?.customer?.name
                const reference = meta.delivery_order_number || meta.quotation_number || meta.invoice_number || request.invoice?.invoice_number || `#${request.subject_id}`
                const amount = request.amount ?? 0
                const currency = meta.currency || request.invoice?.currency_code || 'USD'
                const isPending = request.status === 'pending'

                return (
                  <div key={request.id} className="rounded-lg border bg-card p-4 transition-colors hover:bg-muted/30">
                    <div className="flex items-start justify-between gap-4">
                      <div className="flex-1 space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="font-mono text-sm font-semibold">{request.request_number}</span>
                          <Badge variant={statusVariant(request.status) as any}>
                            {request.status}
                          </Badge>
                          <Badge variant="outline" className="gap-1 font-normal">
                            <Icon icon={typeMeta.icon} className="h-3 w-3" />
                            {typeMeta.label}
                          </Badge>
                          <Badge variant="outline" className="font-mono">
                            {reference}
                          </Badge>
                          {customerName && (
                            <span className="text-sm text-muted-foreground">
                              <Icon icon="solar:user-bold-duotone" className="mr-1 inline h-3.5 w-3.5" />
                              {customerName}
                            </span>
                          )}
                          <span className="text-sm font-medium text-primary">
                            {formatCurrency(Number(amount), currency)}
                          </span>
                        </div>

                        <div className="text-sm">
                          <span className="font-medium">Reason: </span>
                          <span className="text-muted-foreground">{request.reason}</span>
                        </div>

                        {request.notes && (
                          <div className="text-sm">
                            <span className="font-medium">Submitter notes: </span>
                            <span className="text-muted-foreground">{request.notes}</span>
                          </div>
                        )}

                        {request.review_notes && (
                          <div className="rounded-md border-l-4 border-primary/40 bg-muted/30 p-2 text-sm">
                            <span className="font-medium">
                              {request.status === 'approved' ? 'Approval' : 'Rejection'} note
                              {request.reviewed_by ? ` by ${request.reviewed_by.name}` : ''}:
                            </span>{' '}
                            <span className="text-muted-foreground">{request.review_notes}</span>
                          </div>
                        )}

                        <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                          <span>
                            <Icon icon="solar:user-circle-bold-duotone" className="mr-1 inline h-3 w-3" />
                            {request.requested_by?.name ?? 'Unknown'}
                          </span>
                          <span>Submitted {formatDate(request.requested_at)}</span>
                          {request.expires_at && isPending && (
                            <span>
                              <Icon icon="solar:hourglass-bold-duotone" className="mr-1 inline h-3 w-3" />
                              Expires {formatDate(request.expires_at)}
                            </span>
                          )}
                          {request.reviewed_at && (
                            <span>Reviewed {formatDate(request.reviewed_at)}</span>
                          )}
                        </div>
                      </div>

                      <div className="flex shrink-0 flex-col items-end gap-2">
                        {request.subject_id && (
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => navigate(typeMeta.path)}
                          >
                            <Icon icon="solar:eye-bold-duotone" className="mr-1 h-4 w-4" />
                            View {typeMeta.label.toLowerCase()}
                          </Button>
                        )}
                        {isPending && (
                          <div className="flex gap-2">
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() => handleAction(request, 'reject')}
                              className="border-red-300 text-red-700 hover:bg-red-50 hover:text-red-700"
                            >
                              <Icon icon="solar:close-circle-bold-duotone" className="mr-1 h-4 w-4" />
                              Reject
                            </Button>
                            <Button
                              size="sm"
                              onClick={() => handleAction(request, 'approve')}
                              className="bg-green-600 hover:bg-green-700"
                            >
                              <Icon icon="solar:check-circle-bold-duotone" className="mr-1 h-4 w-4" />
                              Approve
                            </Button>
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </CardContent>
      </Card>

      <Dialog open={!!decisionDialog} onOpenChange={(open) => { if (!open) setDecisionDialog(null) }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {decisionDialog?.action === 'approve' ? 'Approve' : 'Reject'} invoice
            </DialogTitle>
            <DialogDescription>
              {decisionDialog && (
                <>
                  {decisionDialog.action === 'approve'
                    ? 'Optional: add a note explaining your decision (visible to the requester).'
                    : 'Please provide a reason (minimum 10 characters) so the requester can address it.'}
                </>
              )}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-2">
            <Label htmlFor="decision-notes">
              {decisionDialog?.action === 'approve' ? 'Notes (optional)' : 'Reason for rejection'}
            </Label>
            <Textarea
              id="decision-notes"
              placeholder={
                decisionDialog?.action === 'approve'
                  ? 'Looks good — go ahead and post.'
                  : 'E.g. Customer PO not attached, please add support doc.'
              }
              value={decisionNotes}
              onChange={(e) => setDecisionNotes(e.target.value)}
              rows={4}
              maxLength={500}
            />
            <p className="text-xs text-muted-foreground">{decisionNotes.length}/500</p>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setDecisionDialog(null)}>
              Cancel
            </Button>
            <Button
              onClick={submitDecision}
              disabled={decisionMutation.isPending}
              className={
                decisionDialog?.action === 'approve'
                  ? 'bg-green-600 hover:bg-green-700'
                  : 'bg-red-600 hover:bg-red-700'
              }
            >
              {decisionMutation.isPending ? (
                <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2" />
              ) : null}
              {decisionDialog?.action === 'approve' ? 'Confirm approve' : 'Confirm reject'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
