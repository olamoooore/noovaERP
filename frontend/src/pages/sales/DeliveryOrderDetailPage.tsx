import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import { ChatterDialog } from '@/components/chatter'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { formatDate } from '@/lib/utils'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DeliveryOrderColumnVisibility } from '@/components/sales/DeliveryOrderColumnVisibility'

export function DeliveryOrderDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [templateDialogOpen, setTemplateDialogOpen] = useState(false)
  const [selectedTemplateId, setSelectedTemplateId] = useState('')
  const [isDownloadingPdf, setIsDownloadingPdf] = useState(false)
  const [chatterOpen, setChatterOpen] = useState(false)

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
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" onClick={() => (window.history.length > 1 ? navigate(-1) : navigate('/delivery-orders'))}>
            <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
            Back
          </Button>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-3xl font-bold">{deliveryOrder.delivery_order_number}</h1>
              <Badge variant="secondary" className={getStatusBadge(deliveryOrder.status)}>
                {deliveryOrder.status}
              </Badge>
            </div>
            <p className="text-muted-foreground mt-1">
              {deliveryOrder.sales_order
                ? `For Sales Order ${deliveryOrder.sales_order.sales_order_number}`
                : 'Standalone Delivery'}
            </p>
          </div>
        </div>

        <div className="flex gap-2">
          <Button variant="outline" onClick={() => setChatterOpen(true)}>
            <Icon icon="solar:chat-round-dots-bold-duotone" className="mr-2 h-4 w-4" />
            Chatter
          </Button>
          <ChatterDialog
            open={chatterOpen}
            onOpenChange={setChatterOpen}
            subjectType="Modules\\Sales\\src\\Models\\DeliveryOrder"
            subjectId={id as string}
            documentLabel={deliveryOrder.delivery_order_number}
          />
          {deliveryOrder.status === 'draft' && (
            <>
              <Button onClick={() => confirmMutation.mutate()} disabled={confirmMutation.isPending}>
                <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
                {confirmMutation.isPending ? 'Confirming...' : 'Confirm'}
              </Button>
              <Button variant="outline" onClick={() => cancelMutation.mutate()} disabled={cancelMutation.isPending}>
                <Icon icon="solar:close-circle-bold" className="mr-2 h-4 w-4" />
                Cancel
              </Button>
              <Button variant="destructive" onClick={() => {
                if (confirm('Delete this delivery order?')) deleteMutation.mutate()
              }} disabled={deleteMutation.isPending}>
                <Icon icon="solar:trash-bin-minimalistic-bold" className="mr-2 h-4 w-4" />
                Delete
              </Button>
            </>
          )}
          {deliveryOrder.status === 'confirmed' && (
            <>
              <Button onClick={() => completeMutation.mutate()} disabled={completeMutation.isPending}>
                <Icon icon="solar:box-bold" className="mr-2 h-4 w-4" />
                {completeMutation.isPending ? 'Completing...' : 'Validate / Complete'}
              </Button>
              <Button variant="outline" onClick={() => cancelMutation.mutate()} disabled={cancelMutation.isPending}>
                <Icon icon="solar:close-circle-bold" className="mr-2 h-4 w-4" />
                Cancel
              </Button>
            </>
          )}
          {deliveryOrder.status === 'done' && (
            <Button
              variant="outline"
              onClick={() => {
                const reason = prompt('Reversal reason (required):')
                if (reason && reason.length >= 3) reverseMutation.mutate(reason)
              }}
              disabled={reverseMutation.isPending}
            >
              <Icon icon="solar:undo-left-round-bold" className="mr-2 h-4 w-4" />
              {reverseMutation.isPending ? 'Reversing...' : 'Reverse'}
            </Button>
          )}
          {deliveryOrder.status === 'cancelled' && (
            <Button
              variant="outline"
              onClick={() => {
                if (confirm('Reset this delivery order to draft? You will be able to edit and re-confirm it.')) {
                  resetToDraftMutation.mutate()
                }
              }}
              disabled={resetToDraftMutation.isPending}
            >
              <Icon icon="solar:undo-left-round-bold" className="mr-2 h-4 w-4" />
              {resetToDraftMutation.isPending ? 'Resetting...' : 'Reset to Draft'}
            </Button>
          )}
          <Button
            variant="outline"
            onClick={() => setTemplateDialogOpen(true)}
          >
            <Icon icon="solar:download-bold" className="h-4 w-4 mr-2" />
            Download PDF
          </Button>
          {deliveryOrder.status === 'draft' && (
            <Button
              variant="outline"
              onClick={() => navigate(`/delivery-orders/${id}/edit`)}
            >
              <Icon icon="solar:pen-bold" className="h-4 w-4 mr-2" />
              Edit
            </Button>
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

      {/* Odoo-style smart buttons */}
      <div className="flex gap-3">
        {deliveryOrder.sales_order && (
          <button
            onClick={() => navigate(`/sales/orders/${deliveryOrder.sales_order.id}`)}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:cart-large-2-bold-duotone" className="h-5 w-5 text-green-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{deliveryOrder.sales_order.sales_order_number}</div>
              <div className="text-xs text-muted-foreground">Sales Order</div>
            </div>
          </button>
        )}
        {deliveryOrder.invoice && (
          <button
            onClick={() => navigate(`/invoices/${deliveryOrder.invoice.id}`)}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:bill-list-bold-duotone" className="h-5 w-5 text-blue-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{deliveryOrder.invoice.invoice_number}</div>
              <div className="text-xs text-muted-foreground">Invoice</div>
            </div>
          </button>
        )}
        {deliveryOrder.quotation && (
          <button
            onClick={() => navigate(`/quotations/${deliveryOrder.quotation.id}`)}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:document-text-bold-duotone" className="h-5 w-5 text-purple-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{deliveryOrder.quotation.quotation_number}</div>
              <div className="text-xs text-muted-foreground">Quotation</div>
            </div>
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
                <div className="font-medium">{deliveryOrder.customer?.name || '-'}</div>
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
                  {line.line_type === 'note' || line.line_type === 'section' ? <TableCell colSpan={14} className={line.line_type === 'section' ? 'bg-muted/60 font-semibold' : 'bg-muted/30 italic text-muted-foreground'}>{line.description}</TableCell> : <>
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
    </div>
  )
}

export default DeliveryOrderDetailPage
