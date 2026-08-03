import { useParams, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatCurrency, formatDate } from '@/lib/utils'
import api from '@/lib/api'

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
  unit_price: number
  tax_rate: number
  tax_amount: number
  discount_amount: number
  line_total: number
}

export function SalesOrderDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const { data: order, isLoading } = useQuery({
    queryKey: ['sales-order', id],
    queryFn: async () => {
      const response = await api.get(`/sales/orders/${id}`)
      return response.data as SalesOrder
    },
    enabled: !!id,
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

  return (
    <div className="container mx-auto py-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="outline" onClick={() => navigate('/sales/orders')}>
            <Icon icon="material-symbols:arrow-back" className="mr-2 h-4 w-4" />
            Back
          </Button>
          <div>
            <h1 className="text-2xl font-bold">Sales Order {order.sales_order_number}</h1>
            <p className="text-muted-foreground text-sm">
              Created on {formatDate(order.created_at)} for {order.customer?.name || 'Unknown Customer'}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {getStatusBadge(order.status)}
        </div>
      </div>

      {/* Smart Buttons */}
      <div className="flex gap-3">
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
    </div>
  )
}
