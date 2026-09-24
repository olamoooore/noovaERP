import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import { ChatterDock } from '@/components/chatter'
import { TagEditor } from '@/components/tags/TagEditor'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { formatCurrency, formatDate } from '@/lib/utils'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DeliveryOrderColumnVisibility } from '@/components/sales/DeliveryOrderColumnVisibility'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { getDocumentSectionTotal } from '@/components/sales/DocumentTextLineRow'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'

export function DeliveryOrderDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [templateDialogOpen, setTemplateDialogOpen] = useState(false)
  const [selectedTemplateId, setSelectedTemplateId] = useState('')
  const [isDownloadingPdf, setIsDownloadingPdf] = useState(false)
  const [chatterOpen, setChatterOpen] = useState(false)
  const [confirmAction, setConfirmAction] = useState<'delete' | 'reset' | null>(null)

  const { data: deliveryOrder, isLoading } = useQuery({
    queryKey: ['delivery-order', id],
    queryFn: async () => {
      const response = await api.get(`/sales/delivery-orders/${id}`)
      return response.data
    },
    enabled: !!id,
  })

  const { data: pdfTemplates = [], isLoading: templatesLoading } = useQuery({
    queryKey: ['delivery-order-pdf-templates'],
    queryFn: async () => {
      const response = await api.get('/delivery-orders/pdf-templates')
      return response.data?.data || []
    },
    enabled: templateDialogOpen,
  })

  const downloadPdf = async () => {
    if (!selectedTemplateId) {
      toast({ variant: 'destructive', title: 'Please select a template' })
      return
    }

    setIsDownloadingPdf(true)
    try {
      const response = await api.get(`/delivery-orders/${id}/pdf`, {
        params: { template: selectedTemplateId },
        responseType: 'blob',
      })
      const blob = new Blob([response.data], { type: 'application/pdf' })
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `DO-${deliveryOrder.delivery_order_number}.pdf`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      window.URL.revokeObjectURL(url)
      setTemplateDialogOpen(false)
    } catch (error) {
      toast({ variant: 'destructive', title: 'Failed to download PDF' })
    } finally {
      setIsDownloadingPdf(false)
    }
  }

  const confirmMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/sales/delivery-orders/${id}/confirm`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['delivery-order', id] })
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
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
    mutationFn: async () => {
      const response = await api.post(`/sales/delivery-orders/${id}/customer-link`)
      return response.data
    },
    onSuccess: () => {
      toast({ title: 'Delivery order sent to customer', description: 'The customer can now sign and upload their copy.' })
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
              {deliveryOrder.approval_status === 'pending' ? (
                <Badge variant="secondary" className="bg-amber-100 text-amber-800">
                  Pending Approval
                </Badge>
              ) : (
                <Badge variant="secondary" className={getStatusBadge(deliveryOrder.status)}>
                  {deliveryOrder.status}
                </Badge>
              )}
            </div>
            <p className="text-muted-foreground mt-1">
              {deliveryOrder.quotation
                ? `For Quotation ${deliveryOrder.quotation.quotation_number}`
                : 'Standalone Delivery'}
            </p>
            <TagEditor subjectType="delivery_order" subjectId={id!} />
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {deliveryOrder.status === 'draft' && (
            <>
              <HeaderActionButton
                icon="solar:check-circle-bold-duotone"
                loading={confirmMutation.isPending}
                onClick={() => confirmMutation.mutate()}
                disabled={confirmMutation.isPending}
              >
                Confirm
              </HeaderActionButton>
              <HeaderActionButton
                variant="outline"
                icon="solar:close-circle-bold-duotone"
                onClick={() => cancelMutation.mutate()}
                disabled={cancelMutation.isPending}
              >
                Cancel
              </HeaderActionButton>
              <HeaderActionButton
                variant="outline"
                icon="solar:trash-bin-trash-bold-duotone"
                className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                onClick={() => setConfirmAction('delete')}
                disabled={deleteMutation.isPending}
              >
                Delete
              </HeaderActionButton>
            </>
          )}
          {deliveryOrder.status === 'confirmed' && (
            <>
              <HeaderActionButton
                icon="solar:box-bold-duotone"
                loading={completeMutation.isPending}
                onClick={() => completeMutation.mutate()}
                disabled={completeMutation.isPending}
              >
                Validate / Complete
              </HeaderActionButton>
              <HeaderActionButton
                variant="outline"
                icon="solar:close-circle-bold-duotone"
                onClick={() => cancelMutation.mutate()}
                disabled={cancelMutation.isPending}
              >
                Cancel
              </HeaderActionButton>
            </>
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
          <HeaderActionButton
            variant="outline"
            icon="solar:download-minimalistic-bold-duotone"
            onClick={() => setTemplateDialogOpen(true)}
          >
            Download PDF
          </HeaderActionButton>
          {['confirmed', 'done'].includes(deliveryOrder.status) && (
            <HeaderActionButton
              variant="outline"
              icon="solar:letter-bold-duotone"
              loading={sendToCustomerMutation.isPending}
              onClick={() => sendToCustomerMutation.mutate()}
              disabled={sendToCustomerMutation.isPending}
            >
              Send to Customer
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
        </div>
      </div>

      <Dialog open={templateDialogOpen} onOpenChange={setTemplateDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Download Delivery Order PDF</DialogTitle>
            <DialogDescription>Select the template to use for this PDF.</DialogDescription>
          </DialogHeader>
          <Select value={selectedTemplateId} onValueChange={setSelectedTemplateId} disabled={templatesLoading}>
            <SelectTrigger>
              <SelectValue placeholder={templatesLoading ? 'Loading templates...' : 'Select a template'} />
            </SelectTrigger>
            <SelectContent>
              {pdfTemplates.map((template: any) => (
                <SelectItem key={template.id} value={template.id}>
                  {template.name}{template.is_system_default ? ' (Default)' : ''}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {!templatesLoading && pdfTemplates.length === 0 && (
            <p className="text-sm text-muted-foreground">No published Delivery Order templates are available.</p>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setTemplateDialogOpen(false)} disabled={isDownloadingPdf}>Cancel</Button>
            <Button onClick={downloadPdf} disabled={!selectedTemplateId || isDownloadingPdf || templatesLoading}>
              <Icon icon="solar:download-bold" className="mr-2 h-4 w-4" />
              {isDownloadingPdf ? 'Downloading...' : 'Download'}
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

      {/* Odoo-style smart buttons */}
      <div className="flex gap-3">
        {deliveryOrder.invoice && (
          <button
            type="button"
            onClick={() => navigate(`/invoices/${deliveryOrder.invoice.id}`)}
            className="inline-flex h-8 items-center gap-1.5 rounded-full border border-input bg-background px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
          >
            <Icon icon="solar:bill-list-bold-duotone" className="h-3.5 w-3.5" />
            {deliveryOrder.invoice.invoice_number}
            <span className="text-muted-foreground/70">Invoice</span>
          </button>
        )}
        {deliveryOrder.quotation && (
          <button
            type="button"
            onClick={() => navigate(`/quotations/${deliveryOrder.quotation.id}`)}
            className="inline-flex h-8 items-center gap-1.5 rounded-full border border-input bg-background px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
          >
            <Icon icon="solar:document-text-bold-duotone" className="h-3.5 w-3.5" />
            {deliveryOrder.quotation.quotation_number}
            <span className="text-muted-foreground/70">Quotation</span>
          </button>
        )}
      </div>

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
                <div className="font-medium">{deliveryOrder.createdBy?.name || '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Confirmed By</div>
                <div className="font-medium">{deliveryOrder.confirmedBy?.name || '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Invoice</div>
                <div className="font-medium">
                  {deliveryOrder.invoice ? (
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
        <CardHeader>
          <CardTitle>Shipped Items</CardTitle>
        </CardHeader>
        <CardContent>
            <DeliveryOrderColumnVisibility tableClass="do-detail-columns" columns={[
              '#', 'Product', 'SKU', 'Description', 'Model #', 'Product Notes', 'Patient Name', 'Qty Ordered', 'Qty Delivered', 'Unit', 'Unit Price', 'Line Total', 'Lot Number', 'Expiry Date',
            ].map((label, index) => ({ label, index: index + 1 }))}>
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
                  <TableCell>{index + 1}</TableCell>
                  <TableCell className="font-medium">{line.product?.name || '-'}</TableCell>
                  <TableCell className="text-muted-foreground">{line.product?.sku || '-'}</TableCell>
                  <TableCell>{line.description || '-'}</TableCell>
                  <TableCell className="text-muted-foreground">{line.model_number || line.product?.manufacturer_part_number || '-'}</TableCell>
                  <TableCell className="text-muted-foreground">{line.product_notes || '-'}</TableCell>
                  <TableCell>{line.patient_name || '-'}</TableCell>
                  <TableCell className="text-right">{line.quantity_ordered}</TableCell>
                  <TableCell className="text-right font-medium text-green-600">{line.quantity_delivered}</TableCell>
                  <TableCell className="text-right">{line.unit}</TableCell>
                  <TableCell className="text-right">{line.unit_price ? parseFloat(line.unit_price).toFixed(2) : '-'}</TableCell>
                  <TableCell className="text-right font-medium">{line.line_total ? parseFloat(line.line_total).toFixed(2) : '-'}</TableCell>
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
              <div className="text-right">
                <span className="text-muted-foreground mr-4">Total:</span>
                <span className="text-lg font-bold">{parseFloat(deliveryOrder.total).toFixed(2)}</span>
              </div>
            </div>
          )}
        </CardContent>
      </Card>
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
