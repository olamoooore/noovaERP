import { useState } from 'react'
import { useAuthStore } from '@/store/authStore'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useToast } from '@/hooks/use-toast'
import { ChatterDock } from '@/components/chatter'
import { AuditTab } from '@/components/audit/AuditTab'
import { StockMovesTab } from '@/components/inventory/StockMovesTab'
import { StockCogsBadge } from '@/components/inventory/StockCogsBadge'
import { TagEditor } from '@/components/tags/TagEditor'
import { DocumentLinkChip } from '@/components/documents/DocumentLinkChip'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { formatCurrency, formatDate, resolveImageUrl, cn } from '@/lib/utils'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { PrintSendMenu } from '@/components/documents/PrintSendMenu'
import { DeliveryOrderColumnVisibility, SalesLineColumnMenu } from '@/components/sales/DeliveryOrderColumnVisibility'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { getDocumentSectionTotal } from '@/components/sales/DocumentTextLineRow'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import {
  DeliveryOrderInvoicingProgressSummary,
  DeliveryOrderLineInvoicingChip,
} from '@/components/sales/DocumentProgressChip'

const SHIPPED_ITEM_COLUMNS = [
  '#', 'Product', 'SKU', 'Description', 'Model #', 'Product Notes', 'Patient Name',
  'Qty Ordered', 'Qty Delivered', 'Unit', 'Unit Price', 'Line Total', 'Lot Number', 'Expiry Date',
].map((label, index) => ({ label, index: index + 1 }))

export function DeliveryOrderDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [chatterOpen, setChatterOpen] = useState(false)
  const [showInvoicesPanel, setShowInvoicesPanel] = useState(false)

  // Product Notes starts hidden, matching the previous default.
  const [hiddenShippedColumns, setHiddenShippedColumns] = useState<number[]>(
    () => SHIPPED_ITEM_COLUMNS.filter((c) => c.label.toLowerCase() === 'product notes').map((c) => c.index)
  )
  const [confirmAction, setConfirmAction] = useState<'delete' | 'reset' | null>(null)
  const [sendDialogOpen, setSendDialogOpen] = useState(false)
  const [sendToEmail, setSendToEmail] = useState('')
  const [sendCc, setSendCc] = useState('')

  const { data: deliveryOrder, isLoading } = useQuery({
    queryKey: ['delivery-order', id],
    queryFn: async () => {
      const response = await api.get(`/sales/delivery-orders/${id}`)
      return response.data
    },
    enabled: !!id,
  })

  // MRD pricelist-multicurrency FR-5.4/5.6: DOs carry the SO/quotation
  // currency + snapshot; totals show the base equivalent when foreign.
  const docCurrency = (deliveryOrder as any)?.currency_code || undefined
  const money = (value: number) => formatCurrency(value, docCurrency)
  const baseCurrency = (deliveryOrder as any)?.base_currency_code || undefined
  const fxRate = Number((deliveryOrder as any)?.exchange_rate ?? 1) || 1
  const showBaseEquivalent = Boolean(docCurrency && baseCurrency && docCurrency !== baseCurrency)
  const baseMoney = (value: number) => formatCurrency(value * fxRate, baseCurrency)

  const { hasPermission } = useAuthStore()
  // Posting/confirming sell-side documents is accounting-only (server enforces accounting.post).
  const canPost = hasPermission('accounting.post')

  const confirmMutation = useMutation({

    mutationFn: async () => {
      const response = await api.post(`/sales/delivery-orders/${id}/confirm`)
      return response.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['delivery-order', id] })
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      if (data?.requires_approval) {
        toast({
          title: 'Approval required',
          description: data?.message || 'An approval request has been submitted for this document.',
        })
        return
      }
      toast({ title: 'Delivery order confirmed' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to confirm',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const createInvoiceMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/sales/delivery-orders/${id}/create-invoice`)
      return response.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['delivery-order', id] })
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      if (data?.requires_approval) {
        toast({
          title: 'Approval required',
          description: data?.message || 'An approval request has been submitted for this document.',
        })
        return
      }
      const invoiceId = data?.data?.id
      const invoiceNeedsApproval = Boolean(data?.data?.requires_approval) && data?.data?.approval_status !== 'approved'
      toast({
        title: 'Invoice created from delivery order',
        description: invoiceNeedsApproval
          ? 'The invoice has been sent for approval before it can be posted.'
          : undefined,
      })
      if (invoiceId) {
        navigate(`/invoices/${invoiceId}`)
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to create invoice',
        description: error.response?.data?.message || error.response?.data?.error || 'An error occurred',
      })
    },
  })

  const completeMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/sales/delivery-orders/${id}/complete`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['delivery-order', id] })
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-tracking'] })
      queryClient.invalidateQueries({ queryKey: ['product-tracking-availability'] })
      queryClient.invalidateQueries({ queryKey: ['stock-movements'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-dashboard'] })
      toast({ title: 'Delivery completed — stock deducted' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to complete',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const reverseMutation = useMutation({
    mutationFn: async (reason: string) => {
      const response = await api.post(`/sales/delivery-orders/${id}/reverse`, { reason })
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['delivery-order', id] })
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-tracking'] })
      queryClient.invalidateQueries({ queryKey: ['product-tracking-availability'] })
      queryClient.invalidateQueries({ queryKey: ['stock-movements'] })
      queryClient.invalidateQueries({ queryKey: ['inventory-dashboard'] })
      toast({ title: 'Delivery reversed — stock restored' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to reverse',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const cancelMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/sales/delivery-orders/${id}/cancel`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['delivery-order', id] })
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      toast({ title: 'Delivery order cancelled' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to cancel',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async () => {
      const response = await api.delete(`/sales/delivery-orders/${id}`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      toast({ title: 'Delivery order deleted' })
      navigate('/delivery-orders')
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to delete',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const resetToDraftMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/sales/delivery-orders/${id}/reset-to-draft`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['delivery-order', id] })
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      toast({ title: 'Delivery order reset to draft' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to reset to draft',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const sendToCustomerMutation = useMutation({
    mutationFn: async ({ to, cc }: { to: string; cc: string }) => {
      const response = await api.post(`/sales/delivery-orders/${id}/customer-link`, {
        send_email: true,
        recipient_email: to || undefined,
        cc: cc || undefined,
      })
      return response.data
    },
    // The link is returned either way; keep it visible so it can be shared
    // manually when the email cannot be sent.
    onSuccess: (data: any) => {
      setSendDialogOpen(false)
      // The backend reports whether the mail actually went out: the link can be
      // issued without an email (no customer address, or the send failed), and
      // saying "sent" in that case is misleading.
      const recipient = data?.data?.delivery?.recipient
      const message = String(data?.message || '')
      if (recipient && !message.includes('email failed')) {
        toast({
          title: 'Delivery order emailed to the customer',
          description: `${recipient} can now review and electronically sign the document.`,
        })
      } else {
        toast({
          title: 'Customer link created',
          description: message.includes('no email')
            ? 'The customer has no email address — copy the link from the dialog.'
            : 'The email could not be sent — copy the link from the dialog.',
          variant: 'destructive',
        })
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to send delivery order',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const [approvalComment, setApprovalComment] = useState('')

  const approveMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/sales/delivery-orders/${id}/approve`)
      return response.data
    },
    onSuccess: () => {
      setApprovalComment('')
      queryClient.invalidateQueries({ queryKey: ['delivery-order', id] })
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      toast({ title: 'Delivery order approved', description: 'It can now be confirmed.' })
    },
    onError: (error: any) => {
      toast({ variant: 'destructive', title: 'Could not approve', description: error.response?.data?.message || 'An error occurred' })
    },
  })

  const declineMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/sales/delivery-orders/${id}/reject-approval`, { reason: approvalComment })
      return response.data
    },
    onSuccess: () => {
      setApprovalComment('')
      queryClient.invalidateQueries({ queryKey: ['delivery-order', id] })
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      toast({ title: 'Delivery order declined', description: 'It has been returned to draft.' })
    },
    onError: (error: any) => {
      toast({ variant: 'destructive', title: 'Could not decline', description: error.response?.data?.message || 'An error occurred' })
    },
  })

  const handleDecline = () => {
    if ((approvalComment.trim().length || 0) < 10) {
      toast({ variant: 'destructive', title: 'A reason is required', description: 'Please add a comment (at least 10 characters).' })
      return
    }
    declineMutation.mutate()
  }

  const getStatusBadge = (status: string) => {
    const styles: Record<string, string> = {
      draft: 'bg-gray-100 text-gray-800',
      confirmed: 'bg-blue-100 text-blue-800',
      done: 'bg-green-100 text-green-800',
      cancelled: 'bg-red-100 text-red-800',
    }
    return styles[status] || 'bg-gray-100 text-gray-800'
  }

  if (isLoading) {
    return (
      <div className="p-6">
        <div className="py-12 text-center">
          <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
          <p className="mt-4 text-sm text-muted-foreground">Loading delivery order...</p>
        </div>
      </div>
    )
  }

  if (!deliveryOrder) {
    return (
      <div className="p-6">
        <div className="py-12 text-center text-muted-foreground">
          <p>Delivery order not found.</p>
          <Button variant="outline" onClick={() => navigate('/delivery-orders')} className="mt-4">
            Back to Delivery Orders
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div className="space-y-2">
          <Button
            variant="ghost"
            size="sm"
            className="-ml-2 text-muted-foreground hover:text-foreground"
            onClick={() => (window.history.length > 1 ? navigate(-1) : navigate('/delivery-orders'))}
          >
            <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
            Back
          </Button>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-3xl font-bold">{deliveryOrder.delivery_order_number}</h1>
              {/* Mirror the list badge: a draft sitting in approval must not
                  read as a plain "draft". */}
              {deliveryOrder.status === 'draft' && deliveryOrder.approval_status === 'pending' ? (
                <Badge variant="secondary" className="bg-amber-100 text-amber-800">
                  Pending Approval
                </Badge>
              ) : deliveryOrder.status === 'draft' && deliveryOrder.approval_status === 'approved' ? (
                <Badge variant="secondary" className="bg-emerald-100 text-emerald-800">
                  Approved
                </Badge>
              ) : deliveryOrder.status === 'draft' && deliveryOrder.approval_status === 'rejected' ? (
                <Badge variant="secondary" className="bg-red-100 text-red-800">
                  Approval Rejected
                </Badge>
              ) : (
                <Badge
                  variant="secondary"
                  className={deliveryOrder.reversed_at ? 'bg-purple-100 text-purple-800' : getStatusBadge(deliveryOrder.status)}
                  title={deliveryOrder.reversed_at && deliveryOrder.reversal_reason ? `Reversed: ${deliveryOrder.reversal_reason}` : undefined}
                >
                  {deliveryOrder.reversed_at ? 'reversed' : deliveryOrder.status}
                </Badge>
              )}
            </div>
            <p className="text-muted-foreground mt-1">
              {deliveryOrder.quotation
                ? `For Quotation ${deliveryOrder.quotation.quotation_number}`
                : 'Standalone Delivery'}
            </p>
            {/* Issue #696 (INV-D3): stock & COGS reconciliation badge. */}
            <StockCogsBadge
              documentType="delivery_order"
              documentId={Number(id)}
              permission="sales.view"
              className="mt-2"
            />
            <TagEditor subjectType="delivery_order" subjectId={id!} />
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {/* Send first, subtle green — the most common customer action. */}
          {deliveryOrder.status !== 'cancelled' &&
            !['pending', 'rejected'].includes(deliveryOrder.approval_status ?? 'not_required') && (
              <HeaderActionButton
                variant="outline"
                icon="solar:letter-bold-duotone"
                className="border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 hover:text-emerald-800"
                onClick={() => {
                  setSendToEmail(deliveryOrder.customer?.email ?? '')
                  setSendCc('')
                  setSendDialogOpen(true)
                }}
              >
                Send
              </HeaderActionButton>
            )}
          {/* #459 (DO7): the server decides billability — a delivery order is
              only invoiced once completed, unless the tenant enables the
              bill-before-delivery policy. Anything else (draft/confirmed
              without the policy, fully billed, cancelled) hides the action. */}
          {deliveryOrder.can_invoice === true && (
            <HeaderActionButton
              icon="solar:bill-list-bold-duotone"
              loading={createInvoiceMutation.isPending}
              onClick={() => createInvoiceMutation.mutate()}
              disabled={createInvoiceMutation.isPending}
              title="Create an invoice for the remaining delivered quantities"
            >
              Create Invoice
            </HeaderActionButton>
          )}
          {deliveryOrder.status === 'draft' && canPost && (
            <HeaderActionButton
              icon="solar:check-circle-bold-duotone"
              loading={confirmMutation.isPending}
              onClick={() => confirmMutation.mutate()}
              disabled={confirmMutation.isPending}
            >
              Confirm
            </HeaderActionButton>
          )}
          {deliveryOrder.status === 'confirmed' && (
            <HeaderActionButton
              icon="solar:box-bold-duotone"
              loading={completeMutation.isPending}
              onClick={() => completeMutation.mutate()}
              disabled={completeMutation.isPending}
            >
              Validate / Complete
            </HeaderActionButton>
          )}
          {deliveryOrder.status === 'done' && (
            <HeaderActionButton
              variant="outline"
              icon="solar:undo-left-round-bold-duotone"
              loading={reverseMutation.isPending}
              onClick={() => {
                const reason = prompt('Reversal reason (required):')
                if (reason && reason.length >= 3) reverseMutation.mutate(reason)
              }}
              disabled={reverseMutation.isPending}
            >
              Reverse
            </HeaderActionButton>
          )}
          {deliveryOrder.status === 'cancelled' && (
            <HeaderActionButton
              variant="outline"
              icon="solar:undo-left-round-bold-duotone"
              loading={resetToDraftMutation.isPending}
              onClick={() => setConfirmAction('reset')}
              disabled={resetToDraftMutation.isPending}
            >
              Reset to Draft
            </HeaderActionButton>
          )}
          {deliveryOrder.status === 'draft' && (
            <HeaderActionButton
              variant="outline"
              icon="solar:pen-bold-duotone"
              onClick={() => navigate(`/delivery-orders/${id}/edit`)}
            >
              Edit
            </HeaderActionButton>
          )}
          {/* Drafts are deleted; Cancel is a confirmed-state action. */}
          {deliveryOrder.status === 'confirmed' && (
            <HeaderActionButton
              variant="outline"
              icon="solar:close-circle-bold-duotone"
              onClick={() => cancelMutation.mutate()}
              disabled={cancelMutation.isPending}
            >
              Cancel
            </HeaderActionButton>
          )}
          {/* #618: one print/send menu shared with every sales document. */}
          <PrintSendMenu
            documentType="delivery_order"
            pdfPath={`/delivery-orders/${id}/pdf`}
            filename={`DO-${deliveryOrder.delivery_order_number}.pdf`}
            title="Delivery Order"
            resolutionPath={`/documents/delivery_order/resolution?document_id=${id}`}
            onEmail={() => {
              setSendToEmail(deliveryOrder.customer?.email ?? '')
              setSendCc('')
              setSendDialogOpen(true)
            }}
          />
          {/* Destructive action always last. */}
          {deliveryOrder.status === 'draft' && (
            <HeaderActionButton
              variant="destructive"
              icon="solar:trash-bin-trash-bold-duotone"
              loading={deleteMutation.isPending}
              aria-label="Delete delivery order"
              onClick={() => setConfirmAction('delete')}
              disabled={deleteMutation.isPending}
            >
              Delete
            </HeaderActionButton>
          )}
        </div>
      </div>

      <Dialog open={sendDialogOpen} onOpenChange={setSendDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Send Delivery Order</DialogTitle>
            <DialogDescription>
              Confirm the recipient for {deliveryOrder.delivery_order_number} — you can add CC addresses.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="space-y-2">
              <Label htmlFor="do-send-to">To</Label>
              <Input
                id="do-send-to"
                type="email"
                value={sendToEmail}
                onChange={(e) => setSendToEmail(e.target.value)}
                placeholder="customer@example.com"
              />
              {!deliveryOrder.customer?.email && (
                <p className="text-xs text-amber-600">
                  The customer has no email on file — enter one to send the delivery order.
                </p>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="do-send-cc">CC (optional)</Label>
              <Input
                id="do-send-cc"
                value={sendCc}
                onChange={(e) => setSendCc(e.target.value)}
                placeholder="ops@example.com, finance@example.com"
              />
              <p className="text-xs text-muted-foreground">Separate multiple addresses with commas.</p>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setSendDialogOpen(false)} disabled={sendToCustomerMutation.isPending}>
              Cancel
            </Button>
            <Button
              onClick={() => sendToCustomerMutation.mutate({ to: sendToEmail.trim(), cc: sendCc.trim() })}
              disabled={sendToCustomerMutation.isPending || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(sendToEmail.trim())}
            >
              {sendToCustomerMutation.isPending ? 'Sending...' : 'Send'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Approval actions — shown to the reviewer while the DO awaits approval */}
      {deliveryOrder.can_approve && (
        <div className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/40">
          <div className="flex items-center gap-2">
            <Icon icon="solar:clipboard-check-bold-duotone" className="h-5 w-5 text-amber-600 dark:text-amber-400" />
            <span className="text-sm font-semibold text-amber-900 dark:text-amber-200">
              This delivery order is awaiting your approval
            </span>
          </div>
          <textarea
            value={approvalComment}
            onChange={(e) => setApprovalComment(e.target.value)}
            placeholder="Add a comment (required when declining)…"
            className="min-h-[80px] w-full rounded-md border bg-white p-3 text-sm dark:bg-background"
          />
          <div className="flex items-center gap-2">
            <Button
              onClick={() => approveMutation.mutate()}
              disabled={approveMutation.isPending || declineMutation.isPending}
              className="h-8 rounded-full bg-emerald-600 px-4 text-xs text-white hover:bg-emerald-700"
            >
              <Icon icon="solar:check-circle-bold-duotone" className="mr-1.5 h-4 w-4" />
              {approveMutation.isPending ? 'Approving…' : 'Approve'}
            </Button>
            <Button
              variant="outline"
              onClick={handleDecline}
              disabled={approveMutation.isPending || declineMutation.isPending}
              className="h-8 rounded-full border-red-300 px-4 text-xs text-red-700 hover:bg-red-50 hover:text-red-800 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/40"
            >
              <Icon icon="solar:close-circle-bold-duotone" className="mr-1.5 h-4 w-4" />
              {declineMutation.isPending ? 'Declining…' : 'Decline'}
            </Button>
          </div>
        </div>
      )}

      <Tabs defaultValue="document" className="space-y-6">
        <TabsList className="h-10 w-fit rounded-full">
          <TabsTrigger value="document" className="gap-2 rounded-full px-4">
            Document
          </TabsTrigger>
          <TabsTrigger value="audit" className="gap-2 rounded-full px-4">
            <Icon icon="solar:shield-check-bold-duotone" className="h-4 w-4" />
            Audit
          </TabsTrigger>
          <TabsTrigger value="stock-moves" className="gap-2 rounded-full px-4">
            <Icon icon="solar:box-bold-duotone" className="h-4 w-4" />
            Stock moves
          </TabsTrigger>
        </TabsList>

        <TabsContent value="document" className="space-y-6 mt-6">
      {/* Odoo-style smart buttons */}
      <div className="flex flex-wrap gap-3">
        {deliveryOrder.invoices && deliveryOrder.invoices.length > 0 && (
          <DocumentLinkChip
            kind="invoice"
            active={showInvoicesPanel}
            label={`${deliveryOrder.invoices.length} Invoice${deliveryOrder.invoices.length !== 1 ? 's' : ''}`}
            onClick={() => setShowInvoicesPanel(!showInvoicesPanel)}
            title="Toggle linked invoices"
          />
        )}
        {deliveryOrder.quotation && (
          <DocumentLinkChip
            kind="quotation"
            label={deliveryOrder.quotation.quotation_number}
            onClick={() => navigate(`/quotations/${deliveryOrder.quotation.id}`)}
          />
        )}
        {/* Issue #688 (INV-UX2): "Invoiced x/y" with the billing invoices. */}
        <DeliveryOrderInvoicingProgressSummary progress={deliveryOrder.invoicing_progress} />
      </div>

      {/* Linked invoices (expandable) */}
      {showInvoicesPanel && deliveryOrder.invoices && deliveryOrder.invoices.length > 0 && (
        <Card className="overflow-hidden">
          <div className="flex items-center gap-3 border-b bg-muted/20 px-6 py-4">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">
              <Icon icon="solar:bill-list-bold-duotone" className="h-5 w-5" />
            </div>
            <div className="flex-1">
              <h3 className="text-base font-semibold leading-none">Linked Invoices</h3>
              <p className="mt-1 text-xs text-muted-foreground">
                Invoices raised from this delivery order.
              </p>
            </div>
            <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
              {deliveryOrder.invoices.length}
            </span>
          </div>

          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Invoice #</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                  <TableHead className="text-right">Balance</TableHead>
                  <TableHead className="w-[80px]"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {deliveryOrder.invoices.map((inv: any) => {
                  const status = (inv.lifecycle_status || inv.status || '').toLowerCase()
                  const statusClass =
                    status === 'paid'
                      ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                      : status === 'posted' || status === 'sent'
                        ? 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300'
                        : status === 'overdue'
                          ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'
                          : 'bg-muted text-muted-foreground'

                  return (
                    <TableRow
                      key={inv.id}
                      className="cursor-pointer transition-colors hover:bg-muted/40"
                      onClick={() => navigate(`/invoices/${inv.id}`)}
                    >
                      <TableCell className="font-medium">{inv.invoice_number}</TableCell>
                      <TableCell>{inv.invoice_date ? formatDate(inv.invoice_date) : '-'}</TableCell>
                      <TableCell>
                        <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize', statusClass)}>
                          {status || 'draft'}
                        </span>
                      </TableCell>
                      <TableCell className="text-right">{formatCurrency(Number(inv.total ?? 0))}</TableCell>
                      <TableCell className="text-right">{formatCurrency(Number(inv.balance ?? 0))}</TableCell>
                      <TableCell className="text-right">
                        <Button variant="ghost" size="sm" className="h-8 px-2 text-primary">
                          View
                        </Button>
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Delivery Details</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
              <div>
                <div className="text-muted-foreground">Delivery Date</div>
                <div className="font-medium">{deliveryOrder.delivery_date ? formatDate(deliveryOrder.delivery_date) : '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Due Date</div>
                <div className="font-medium">{deliveryOrder.expected_date ? formatDate(deliveryOrder.expected_date) : '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Carrier</div>
                <div className="font-medium">{deliveryOrder.carrier || '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Tracking #</div>
                <div className="font-medium">{deliveryOrder.tracking_number || '-'}</div>
              </div>
            </div>

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm mt-4">
              <div>
                <div className="text-muted-foreground">Customer</div>
                <div className="font-medium">
                  {deliveryOrder.customer ? (
                    <Link
                      to={`/customers/${deliveryOrder.customer.id}`}
                      className="text-primary underline-offset-2 hover:underline"
                    >
                      {deliveryOrder.customer.name}
                    </Link>
                  ) : (
                    '-'
                  )}
                </div>
              </div>
              <div>
                <div className="text-muted-foreground">Warehouse</div>
                <div className="font-medium">{deliveryOrder.warehouse?.name || '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">PO Number</div>
                <div className="font-medium">{deliveryOrder.po_number || '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Salesperson</div>
                <div className="font-medium">{deliveryOrder.salesperson || '-'}</div>
              </div>
            </div>

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm mt-4">
              <div>
                <div className="text-muted-foreground">Attention To</div>
                <div className="font-medium">{deliveryOrder.attention_to || '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Created By</div>
                <div className="font-medium">{deliveryOrder.created_by_user?.name || '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Confirmed By</div>
                <div className="font-medium">{deliveryOrder.confirmed_by_user?.name || '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Customer signature</div>
                {deliveryOrder.signed_at || deliveryOrder.signed_document_path ? (
                  <div className="font-medium">
                    <span className="flex flex-wrap items-center gap-x-2">
                      <span className="inline-flex items-center gap-1 text-emerald-600">
                        <Icon icon="solar:check-circle-bold" className="h-3.5 w-3.5" />
                        Signed
                      </span>
                      {deliveryOrder.signed_by_name && <span>{deliveryOrder.signed_by_name}</span>}
                      {deliveryOrder.signed_at && (
                        <span className="text-muted-foreground">
                          {new Date(deliveryOrder.signed_at).toLocaleString('en-GB', {
                            day: '2-digit', month: 'short', year: 'numeric',
                            hour: '2-digit', minute: '2-digit',
                          })}
                        </span>
                      )}
                    </span>
                    {deliveryOrder.signed_by_email && (
                      <div className="text-xs text-muted-foreground">{deliveryOrder.signed_by_email}</div>
                    )}
                    {deliveryOrder.signed_document_path && (
                      <a
                        href={resolveImageUrl(deliveryOrder.signed_document_path)}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="mt-0.5 inline-flex items-center gap-1 text-xs text-primary hover:underline"
                      >
                        <Icon icon="solar:document-add-bold-duotone" className="h-3.5 w-3.5" />
                        View signed copy
                      </a>
                    )}
                  </div>
                ) : (
                  <div className="font-medium text-muted-foreground">Awaiting customer signature</div>
                )}
              </div>
              <div>
                <div className="text-muted-foreground">Invoice</div>
                <div className="font-medium">
                  {deliveryOrder.invoices && deliveryOrder.invoices.length > 0 ? (
                    deliveryOrder.invoices.map((inv: any, index: number) => (
                      <span key={inv.id}>
                        {index > 0 && <span className="text-muted-foreground">, </span>}
                        <button
                          onClick={() => navigate(`/invoices/${inv.id}`)}
                          className="text-primary hover:underline cursor-pointer"
                        >
                          {inv.invoice_number}
                        </button>
                      </span>
                    ))
                  ) : deliveryOrder.invoice ? (
                    <button
                      onClick={() => navigate(`/invoices/${deliveryOrder.invoice.id}`)}
                      className="text-primary hover:underline cursor-pointer"
                    >
                      {deliveryOrder.invoice.invoice_number}
                    </button>
                  ) : '-'}
                </div>
              </div>
              <div>
                <div className="text-muted-foreground">Quotation</div>
                <div className="font-medium">
                  {deliveryOrder.quotation ? (
                    <button
                      onClick={() => navigate(`/quotations/${deliveryOrder.quotation.id}`)}
                      className="text-primary hover:underline cursor-pointer"
                    >
                      {deliveryOrder.quotation.quotation_number}
                    </button>
                  ) : '-'}
                </div>
              </div>
            </div>

            {deliveryOrder.notes && (
              <div className="mt-4 p-3 rounded-lg bg-gray-50 border text-sm">
                <div className="text-muted-foreground mb-1">Notes</div>
                <div>{deliveryOrder.notes}</div>
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Shipping Address</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-sm whitespace-pre-wrap">
              {deliveryOrder.shipping_address || 'No shipping address provided.'}
            </p>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0">
          <CardTitle>Shipped Items</CardTitle>
          <SalesLineColumnMenu
            columns={SHIPPED_ITEM_COLUMNS}
            hidden={hiddenShippedColumns}
            setHidden={setHiddenShippedColumns}
          />
        </CardHeader>
        <CardContent>
            <DeliveryOrderColumnVisibility
              tableClass="do-detail-columns"
              columns={SHIPPED_ITEM_COLUMNS}
              hiddenColumns={hiddenShippedColumns}
              onHiddenColumnsChange={setHiddenShippedColumns}
              showMenu={false}
            >
            <Table>
            <TableHeader>
                <TableRow>
                 <TableHead>#</TableHead>
                  <TableHead>Product</TableHead>
                  <TableHead>SKU</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead>Model #</TableHead>
                  <TableHead>Product Notes</TableHead>
                  <TableHead>Patient Name</TableHead>
                  <TableHead className="text-right">Qty Ordered</TableHead>
                  <TableHead className="text-right">Qty Delivered</TableHead>
                  <TableHead className="text-right">Unit</TableHead>
                  <TableHead className="text-right">Unit Price</TableHead>
                  <TableHead className="text-right">Line Total</TableHead>
                  <TableHead>Lot Number</TableHead>
                  <TableHead>Expiry Date</TableHead>
                </TableRow>
              </TableHeader>
            <TableBody>
              {deliveryOrder.lines?.map((line: any, index: number) => (
                <TableRow key={line.id}>
                  {line.line_type === 'note' || line.line_type === 'section' ? <TableCell colSpan={14} className={line.line_type === 'section' ? 'bg-muted/60 font-semibold' : 'bg-muted/30 italic text-muted-foreground'}><div className="flex items-center justify-between gap-4"><span>{line.description}</span>{line.line_type === 'section' && <span className="whitespace-nowrap tabular-nums">{formatCurrency(getDocumentSectionTotal(deliveryOrder.lines || [], index, (item: any) => Number(item.line_total ?? (Number(item.quantity || 0) * Number(item.unit_price || 0)))))}</span>}</div></TableCell> : <>
                  <TableCell>
                    {(deliveryOrder.lines || [])
                      .slice(0, index + 1)
                      .filter((item: any) => (item.line_type ?? 'product') === 'product').length}
                  </TableCell>
                  <TableCell className="font-medium">{line.product?.name || '-'}</TableCell>
                  <TableCell className="text-muted-foreground">{line.product?.sku || '-'}</TableCell>
                  <TableCell>{line.description || '-'}</TableCell>
                  <TableCell className="text-muted-foreground">{line.model_number || line.product?.manufacturer_part_number || '-'}</TableCell>
                  <TableCell className="text-muted-foreground">{line.product_notes || '-'}</TableCell>
                  <TableCell>{line.patient_name || '-'}</TableCell>
                  <TableCell className="text-right">{line.quantity_ordered}</TableCell>
                  <TableCell className="text-right font-medium text-green-600">
                    <div>{line.quantity_delivered}</div>
                    <div className="mt-1 print:hidden">
                      <DeliveryOrderLineInvoicingChip progress={deliveryOrder.invoicing_progress} lineId={line.id} />
                    </div>
                  </TableCell>
                  <TableCell className="text-right">{line.unit}</TableCell>
                  <TableCell className="text-right">{line.unit_price ? money(Number(line.unit_price)) : '-'}</TableCell>
                  <TableCell className="text-right font-medium">
                    {(() => {
                      // Legacy lines can carry a price without a stored
                      // line_total; fall back to the computed amount instead of
                      // rendering '-' on an otherwise priced line.
                      if (line.line_total != null) return money(Number(line.line_total))
                      const unitPrice = Number(line.unit_price || 0)
                      const quantity = Number(line.quantity_delivered || line.quantity_ordered || 0)
                      return unitPrice > 0 ? money(unitPrice * quantity) : '-'
                    })()}
                  </TableCell>
                  <TableCell>{line.lot_number || '-'}</TableCell>
                  <TableCell>{line.expiry_date ? formatDate(line.expiry_date) : '-'}</TableCell>
                  </>}
                </TableRow>
              ))}
              {(!deliveryOrder.lines || deliveryOrder.lines.length === 0) && (
                <TableRow>
                  <TableCell colSpan={14} className="text-center text-muted-foreground py-8">
                    No lines in this delivery order.
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
          </DeliveryOrderColumnVisibility>
          {deliveryOrder.total && (
            <div className="flex justify-end mt-4 pt-4 border-t">
              <div className="text-right space-y-1">
                <div>
                  <span className="text-muted-foreground mr-4">Total:</span>
                  <span className="text-lg font-bold">{money(Number(deliveryOrder.total))}</span>
                </div>
                {showBaseEquivalent && (
                  <div className="text-sm text-muted-foreground">
                    ≈ {baseMoney(Number(deliveryOrder.total))} · 1 {docCurrency} = {fxRate.toFixed(4)} {baseCurrency}
                  </div>
                )}
              </div>
            </div>
          )}
        </CardContent>
      </Card>
        </TabsContent>

        <TabsContent value="audit" className="mt-6">
          <AuditTab endpoint={`/sales/delivery-orders/${id}/audit`} permission="sales.view" />
        </TabsContent>

        <TabsContent value="stock-moves" className="mt-6">
          <StockMovesTab endpoint={`/sales/delivery-orders/${id}/stock-moves`} permission="sales.view" />
        </TabsContent>
      </Tabs>

      <ConfirmDialog
        open={confirmAction === 'delete'}
        onOpenChange={(open) => !open && setConfirmAction(null)}
        title="Delete this delivery order?"
        description={
          <>
            Delivery order{' '}
            <span className="font-medium text-foreground">{deliveryOrder.delivery_order_number}</span> and its
            lines will be permanently removed. This cannot be undone.
          </>
        }
        confirmLabel="Delete"
        icon="solar:trash-bin-trash-bold-duotone"
        isPending={deleteMutation.isPending}
        onConfirm={() => {
          setConfirmAction(null)
          deleteMutation.mutate()
        }}
      />

      <ConfirmDialog
        open={confirmAction === 'reset'}
        onOpenChange={(open) => !open && setConfirmAction(null)}
        title="Reset this delivery order to draft?"
        description="It becomes editable again and must be re-confirmed. Stock restored on reversal stays restored."
        confirmLabel="Reset to Draft"
        cancelLabel="Keep as is"
        tone="warning"
        icon="solar:undo-left-round-bold-duotone"
        isPending={resetToDraftMutation.isPending}
        onConfirm={() => {
          setConfirmAction(null)
          resetToDraftMutation.mutate()
        }}
      />

      <ChatterDock
        subjectType={String.raw`Modules\Sales\Models\DeliveryOrder`}
        subjectId={id as string}
        documentLabel={deliveryOrder.delivery_order_number}
        open={chatterOpen}
        onOpenChange={setChatterOpen}
      />
    </div>
  )
}

export default DeliveryOrderDetailPage
