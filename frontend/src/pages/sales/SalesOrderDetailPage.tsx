import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useToast } from '@/hooks/use-toast'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { TagEditor } from '@/components/tags/TagEditor'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatCurrency, formatDate } from '@/lib/utils'
import { ChatterDialog } from '@/components/chatter'
import api from '@/lib/api'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'

interface SalesOrder {
  id: number
  sales_order_number: string
  customer_id: number
  customer?: {
    id: number
    name: string
    email?: string
    phone?: string
    code?: string
  }
  order_date: string
  expected_delivery_date?: string
  status: string
  allowed_transitions?: string[]
  invoice_status?: string
  subtotal: number
  tax_amount: number
  discount_amount: number
  total: number
  notes?: string
  terms?: string
  customer_reference?: string
  internal_reference?: string
  created_at: string
  lines: SalesOrderLine[]
  quotation?: { id: number; quotation_number: string }
  invoices?: { id: number; invoice_number: string; status: string }[]
  delivery_orders?: { id: number; delivery_order_number: string; status: string }[]
}

interface SalesOrderLine {
  id: number
  line_type?: string
  product_id: number
  product?: {
    id: number
    name: string
    sku: string
    manufacturer_part_number?: string
  }
  sku?: string
  model_number?: string
  description: string
  product_notes?: string
  quantity: number
  quantity_delivered?: number | string
  quantity_cancelled?: number | string
  unit_price: number
  tax_rate: number
  tax_amount: number
  discount_amount: number
  line_total: number
}

type OrderAction = 'confirm' | 'allocate' | 'invoice' | 'reset-to-draft' | null

// #375 (O6): delivery orders are created from orders that are committed but
// not yet fully delivered. The backend (DeliveryOrderService) accepts
// confirmed / allocated / fulfilled / invoiced orders; `partially_delivered`
// is kept because the page's status badge already knows it.
const DELIVERY_ORDER_STATUSES = ['confirmed', 'allocated', 'partially_delivered', 'fulfilled', 'invoiced']

const pluralize = (count: number, noun: string) => `${count} ${noun}${count === 1 ? '' : 's'}`

/**
 * #375 (O6): an order is "Delivered" once every deliverable product line has
 * its ordered (minus cancelled) quantity fully delivered; otherwise there is
 * still something "To Deliver".
 */
function isFullyDelivered(lines: SalesOrderLine[]) {
  const deliverable = lines.filter(
    (line) =>
      (line.line_type ?? 'product') === 'product' &&
      Number(line.quantity) - Number(line.quantity_cancelled ?? 0) > 0,
  )

  return (
    deliverable.length > 0 &&
    deliverable.every(
      (line) =>
        Number(line.quantity_delivered ?? 0) + 0.0001 >=
        Number(line.quantity) - Number(line.quantity_cancelled ?? 0),
    )
  )
}

function getInvoiceStatusLabel(invoices: NonNullable<SalesOrder['invoices']>) {
  if (invoices.length === 0) return 'Not Invoiced'

  const statuses = invoices.map((invoice) => String(invoice.status || '').toLowerCase())
  if (statuses.every((status) => status === 'paid')) return 'Paid'
  if (statuses.some((status) => ['posted', 'sent', 'partial', 'overdue', 'paid'].includes(status))) {
    return 'Invoiced'
  }

  return 'Draft'
}

export function SalesOrderDetailPage() {
  const { id } = useParams<{ id: string }>()
  const [chatterOpen, setChatterOpen] = useState(false)
  const [action, setAction] = useState<OrderAction>(null)
  const [cancelOpen, setCancelOpen] = useState(false)
  const [cancelReason, setCancelReason] = useState('')
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const { data: order, isLoading } = useQuery({
    queryKey: ['sales-order', id],
    queryFn: async () => {
      const response = await api.get(`/sales/orders/${id}`)
      return response.data as SalesOrder
    },
    enabled: !!id,
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['sales-order', id] })
    queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
  }

  const actionMutation = useMutation({
    mutationFn: async (target: Exclude<OrderAction, null>) => {
      const response = await api.post(`/sales/orders/${id}/${target}`)
      return response.data
    },
    onSuccess: (data, target) => {
      refresh()
      setAction(null)
      if (target === 'invoice' && data?.invoice?.id) {
        toast({ title: 'Invoice created', description: data.invoice.invoice_number })
        navigate(`/invoices/${data.invoice.id}`)
        return
      }
      toast({ title: 'Success', description: data?.message || 'Order updated' })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update order',
        variant: 'destructive',
      })
    },
  })

  const cancelMutation = useMutation({
    mutationFn: async () => {
      await api.post(`/sales/orders/${id}/cancel`, { reason: cancelReason })
    },
    onSuccess: () => {
      refresh()
      setCancelOpen(false)
      setCancelReason('')
      toast({ title: 'Success', description: 'Order cancelled' })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to cancel order',
        variant: 'destructive',
      })
    },
  })

  const getStatusBadge = (status: string) => {
    const variants: Record<string, { variant: 'default' | 'secondary' | 'destructive' | 'outline'; label: string }> = {
      draft: { variant: 'secondary', label: 'Draft' },
      confirmed: { variant: 'default', label: 'Confirmed' },
      partially_delivered: { variant: 'outline', label: 'Partial' },
      delivered: { variant: 'default', label: 'Delivered' },
      cancelled: { variant: 'destructive', label: 'Cancelled' },
    }
    const config = variants[status] || { variant: 'secondary', label: status }
    return <Badge variant={config.variant}>{config.label}</Badge>
  }

  if (isLoading) {
    return (
      <div className="container mx-auto py-6 space-y-6">
        <div className="flex items-center justify-center h-64">
          <Icon icon="eos-icons:loading" className="h-8 w-8 animate-spin text-primary" />
        </div>
      </div>
    )
  }

  if (!order) {
    return (
      <div className="container mx-auto py-6 space-y-6">
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <Icon icon="material-symbols:receipt-long-off" className="h-16 w-16 text-muted-foreground mb-4" />
            <h2 className="text-xl font-semibold mb-2">Sales Order Not Found</h2>
            <p className="text-muted-foreground mb-4">The sales order you're looking for doesn't exist or has been deleted.</p>
            <Button onClick={() => navigate('/sales/orders')}>
              <Icon icon="material-symbols:arrow-back" className="mr-2 h-4 w-4" />
              Back to Sales Orders
            </Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  const showFulfilmentSmartButtons = !['draft', 'cancelled'].includes(order.status)
  const fullyDelivered = isFullyDelivered(order.lines ?? [])
  const deliveryOrdersCount = order.delivery_orders?.length ?? 0
  const linkedInvoices = order.invoices ?? []

  return (
    <div className="container mx-auto py-6 space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0 flex-1 space-y-2">
        <Button
          variant="ghost"
          size="sm"
          className="-ml-2 text-muted-foreground hover:text-foreground"
          onClick={() => (window.history.length > 1 ? navigate(-1) : navigate('/sales/orders'))}
        >
          <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
          Back
        </Button>
          <div className="min-w-0">
            <h1 className="truncate text-2xl font-bold">Sales Order {order.sales_order_number}</h1>
            <p
              className="mt-1 max-w-lg truncate text-sm text-muted-foreground"
              title={`Created on ${formatDate(order.created_at)} for ${order.customer?.name || 'Unknown Customer'}`}
            >
              Created on {formatDate(order.created_at)} for {order.customer?.name || 'Unknown Customer'}
            </p>
          </div>
        </div>
        <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
          {getStatusBadge(order.status)}
          <TagEditor subjectType="sales_order" subjectId={id!} />
          <HeaderActionButton
            variant="outline"
            icon="solar:chat-round-dots-bold-duotone"
            onClick={() => setChatterOpen(true)}
          >
            Chatter
          </HeaderActionButton>
          {order.status === 'draft' && (
            <HeaderActionButton
              icon="solar:check-circle-bold-duotone"
              loading={actionMutation.isPending}
              onClick={() => actionMutation.mutate('confirm')}
              disabled={actionMutation.isPending}
            >
              Confirm
            </HeaderActionButton>
          )}
          {(order.allowed_transitions ?? []).includes('allocated') && (
            <HeaderActionButton
              icon="solar:box-bold-duotone"
              loading={actionMutation.isPending}
              onClick={() => actionMutation.mutate('allocate')}
              disabled={actionMutation.isPending}
            >
              Allocate
            </HeaderActionButton>
          )}
          {DELIVERY_ORDER_STATUSES.includes(order.status) && (
            <HeaderActionButton
              variant="outline"
              icon="solar:delivery-bold-duotone"
              onClick={() => navigate(`/delivery-orders/create?sales_order_id=${order.id}`)}
            >
              Create Delivery Order
            </HeaderActionButton>
          )}
          {(order.allowed_transitions ?? []).includes('invoiced') && order.invoice_status !== 'fully_invoiced' && (
            <HeaderActionButton
              variant="outline"
              icon="solar:file-text-bold-duotone"
              loading={actionMutation.isPending}
              onClick={() => actionMutation.mutate('invoice')}
              disabled={actionMutation.isPending}
            >
              Create Invoice
            </HeaderActionButton>
          )}
          {(order.allowed_transitions ?? []).includes('draft') && (
            <HeaderActionButton
              variant="outline"
              icon="solar:undo-left-round-bold-duotone"
              loading={actionMutation.isPending}
              onClick={() => actionMutation.mutate('reset-to-draft')}
              disabled={actionMutation.isPending}
            >
              Reset to Draft
            </HeaderActionButton>
          )}
          {(order.allowed_transitions ?? []).includes('cancelled') && (
            <HeaderActionButton
              variant="outline"
              icon="solar:close-circle-bold-duotone"
              className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
              onClick={() => setCancelOpen(true)}
            >
              Cancel
            </HeaderActionButton>
          )}
          <ChatterDialog
            open={chatterOpen}
            onOpenChange={setChatterOpen}
            subjectType="Modules\\Sales\\Models\\SalesOrder"
            subjectId={id as string}
            documentLabel={order.sales_order_number}
          />
        </div>
      </div>

      {/* Smart Buttons */}
      <div className="flex flex-wrap gap-3">
        {showFulfilmentSmartButtons && (
          <button
            onClick={() => navigate(`/delivery-orders?sales_order_id=${order.id}`)}
            title={`${pluralize(deliveryOrdersCount, 'delivery order')} — open the sales order's deliveries`}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:delivery-bold-duotone" className="h-5 w-5 text-orange-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{fullyDelivered ? 'Delivered' : 'To Deliver'}</div>
              <div className="text-xs text-muted-foreground">{pluralize(deliveryOrdersCount, 'Delivery Order')}</div>
            </div>
          </button>
        )}
        {showFulfilmentSmartButtons && (
          <button
            onClick={() => navigate(`/invoices?sales_order_id=${order.id}`)}
            title={`${pluralize(linkedInvoices.length, 'invoice')} — open the sales order's invoices`}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:bill-list-bold-duotone" className="h-5 w-5 text-blue-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{getInvoiceStatusLabel(linkedInvoices)}</div>
              <div className="text-xs text-muted-foreground">{pluralize(linkedInvoices.length, 'Invoice')}</div>
            </div>
          </button>
        )}
        {order.quotation && (
          <button
            onClick={() => navigate(`/quotations/${order.quotation!.id}`)}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:document-text-bold-duotone" className="h-5 w-5 text-purple-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{order.quotation.quotation_number}</div>
              <div className="text-xs text-muted-foreground">Quotation</div>
            </div>
          </button>
        )}
        {order.invoices && order.invoices.length > 0 && order.invoices.map((inv) => (
          <button
            key={inv.id}
            onClick={() => navigate(`/invoices/${inv.id}`)}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:bill-list-bold-duotone" className="h-5 w-5 text-blue-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{inv.invoice_number}</div>
              <div className="text-xs text-muted-foreground">Invoice</div>
            </div>
          </button>
        ))}
        {order.delivery_orders && order.delivery_orders.length > 0 && order.delivery_orders.map((d) => (
          <button
            key={d.id}
            onClick={() => navigate(`/delivery-orders/${d.id}`)}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:package-bold" className="h-5 w-5 text-orange-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{d.delivery_order_number}</div>
              <div className="text-xs text-muted-foreground">Delivery</div>
            </div>
          </button>
        ))}
      </div>

      {/* Order Details */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Customer</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="font-medium">{order.customer?.name || '-'}</p>
            {order.customer?.email && <p className="text-sm text-muted-foreground">{order.customer.email}</p>}
            {order.customer?.phone && <p className="text-sm text-muted-foreground">{order.customer.phone}</p>}
            {order.customer?.code && <p className="text-sm text-muted-foreground">Code: {order.customer.code}</p>}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Order Info</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-1">
              <div className="flex justify-between">
                <span className="text-sm text-muted-foreground">Order Date:</span>
                <span className="text-sm">{formatDate(order.order_date)}</span>
              </div>
              {order.expected_delivery_date && (
                <div className="flex justify-between">
                  <span className="text-sm text-muted-foreground">Expected Delivery:</span>
                  <span className="text-sm">{formatDate(order.expected_delivery_date)}</span>
                </div>
              )}
              {order.customer_reference && (
                <div className="flex justify-between">
                  <span className="text-sm text-muted-foreground">Customer Ref:</span>
                  <span className="text-sm">{order.customer_reference}</span>
                </div>
              )}
              {order.internal_reference && (
                <div className="flex justify-between">
                  <span className="text-sm text-muted-foreground">Internal Ref:</span>
                  <span className="text-sm">{order.internal_reference}</span>
                </div>
              )}
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Totals</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-1">
              <div className="flex justify-between">
                <span className="text-sm text-muted-foreground">Subtotal:</span>
                <span className="text-sm">{formatCurrency(order.subtotal)}</span>
              </div>
              {order.tax_amount > 0 && (
                <div className="flex justify-between">
                  <span className="text-sm text-muted-foreground">Tax:</span>
                  <span className="text-sm">{formatCurrency(order.tax_amount)}</span>
                </div>
              )}
              {order.discount_amount > 0 && (
                <div className="flex justify-between">
                  <span className="text-sm text-muted-foreground">Discount:</span>
                  <span className="text-sm">{formatCurrency(order.discount_amount)}</span>
                </div>
              )}
              <div className="flex justify-between font-bold pt-1 border-t">
                <span className="text-sm">Total:</span>
                <span className="text-sm">{formatCurrency(order.total)}</span>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Line Items */}
      <Card>
        <CardHeader>
          <CardTitle>Line Items</CardTitle>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>#</TableHead>
                <TableHead>Product</TableHead>
                <TableHead>SKU</TableHead>
                <TableHead>Description</TableHead>
                <TableHead>Model #</TableHead>
                <TableHead>Product Notes</TableHead>
                <TableHead className="text-right">Qty</TableHead>
                <TableHead className="text-right">Unit Price</TableHead>
                <TableHead className="text-right">Tax</TableHead>
                <TableHead className="text-right">Total</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {order.lines?.map((line, index) => (
                <TableRow key={line.id}>
                  <TableCell>{index + 1}</TableCell>
                  <TableCell className="font-medium">{line.product?.name || '-'}</TableCell>
                  <TableCell className="text-muted-foreground">{line.product?.sku || line.sku || '-'}</TableCell>
                  <TableCell>{line.description || '-'}</TableCell>
                  <TableCell className="text-muted-foreground">{line.model_number || line.product?.manufacturer_part_number || '-'}</TableCell>
                  <TableCell className="text-muted-foreground">{line.product_notes || '-'}</TableCell>
                  <TableCell className="text-right">{line.quantity}</TableCell>
                  <TableCell className="text-right">{formatCurrency(line.unit_price)}</TableCell>
                  <TableCell className="text-right">{formatCurrency(line.tax_amount)}</TableCell>
                  <TableCell className="text-right font-medium">{formatCurrency(line.line_total)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      {/* Notes */}
      {(order.notes || order.terms) && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {order.notes && (
            <Card>
              <CardHeader>
                <CardTitle>Notes</CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-sm whitespace-pre-wrap">{order.notes}</p>
              </CardContent>
            </Card>
          )}
          {order.terms && (
            <Card>
              <CardHeader>
                <CardTitle>Terms</CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-sm whitespace-pre-wrap">{order.terms}</p>
              </CardContent>
            </Card>
          )}
        </div>
      )}
      <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Cancel Sales Order</DialogTitle>
            <DialogDescription>
              This releases any stock reservations. Provide a reason for the audit trail.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="cancel-reason">Reason</Label>
            <Input
              id="cancel-reason"
              value={cancelReason}
              onChange={(event) => setCancelReason(event.target.value)}
              placeholder="Why is this order being cancelled?"
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setCancelOpen(false)}>
              Keep Order
            </Button>
            <Button
              variant="destructive"
              disabled={cancelMutation.isPending || cancelReason.trim().length < 3}
              onClick={() => cancelMutation.mutate()}
            >
              {cancelMutation.isPending ? 'Cancelling...' : 'Cancel Order'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
