import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
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
} from '@/components/ui/dialog'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Separator } from '@/components/ui/separator'
import { useToast } from '@/hooks/use-toast'
import { Pagination } from '@/components/ui/Pagination'
import { formatCurrency, formatDateTime, getApiErrorMessage, resolveImageUrl } from '@/lib/utils'

interface SalesOrder {
  id: number
  order_number: string
  source: 'pos' | 'web'
  status: string
  payment_status: string
  subtotal: number
  tax_amount: number
  discount_amount?: number
  loyalty_discount?: number
  shipping_cost?: number
  total: number
  currency: string
  items_count: number
  customer?: {
    id: number
    name: string
    email: string
  }
  cashier?: string
  created_at: string
  paid_at?: string
  shipped_at?: string
  delivered_at?: string
}

interface SalesOrderDetail extends SalesOrder {
  notes?: string | null
  email?: string | null
  phone?: string | null
  first_name?: string | null
  last_name?: string | null
  receipt_number?: string | null
  cashier_name?: string | null
  confirmed_at?: string | null
  shipping_address?: {
    first_name?: string
    last_name?: string
    address?: string
    city?: string
    state?: string
    postal_code?: string
    country?: string
    phone?: string
  } | null
  shipping_method?: {
    id?: string | null
    provider_code?: string | null
    provider_name?: string | null
    service_code?: string | null
    service_name?: string | null
    label?: string | null
    price?: number
  } | null
  customer?: {
    id: number
    code?: string
    name: string
    email: string
    phone?: string
    total_orders?: number
    total_purchases?: number
  }
  terminal?: {
    name?: string
  } | null
  session?: {
    name?: string
  } | null
  createdBy?: {
    name?: string
  } | null
  payments?: {
    id: number
    method?: string | null
    amount: number
    reference?: string | null
    notes?: string | null
    payment_method?: {
      name?: string | null
    }
    receivedBy?: {
      name?: string | null
    } | null
  }[]
  items?: {
    id: number
    quantity: number
    unit_price: number
    tax_amount?: number
    line_total: number
    name?: string
    sku?: string | null
    product_name?: string
    product_sku?: string | null
    product_image?: string | null
    product?: {
      name?: string
      image?: string | null
    } | null
    variant?: {
      name?: string
      image?: string | null
    } | null
  }[]
  invoice?: {
    id: number
    invoice_number: string
    status: string
  } | null
}

export function POSOrdersPage() {
  const { toast } = useToast()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('all')
  const [sourceFilter, setSourceFilter] = useState<string>('all')
  const [dateFilter, setDateFilter] = useState<string>('')
  const [selectedOrder, setSelectedOrder] = useState<SalesOrderDetail | null>(null)
  const [loadingOrderDetail, setLoadingOrderDetail] = useState(false)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)

  const { data, isLoading } = useQuery({
    queryKey: ['sales-orders', search, statusFilter, sourceFilter, dateFilter, page, pageSize],
    queryFn: async () => {
      const params: Record<string, any> = { page, per_page: pageSize }
      if (search) params.search = search
      if (statusFilter !== 'all') params.status = statusFilter
      if (sourceFilter !== 'all') params.source = sourceFilter
      if (dateFilter) params.date = dateFilter
      const response = await api.get('/pos/sales/orders', { params })
      return response.data
    },
  })

  const orders = data?.data || []
  const pagination = data?.meta || { current_page: 1, last_page: 1 }
  const stats = data?.stats || { total_orders: 0, pos_orders: 0, web_orders: 0, total_revenue: 0 }

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'paid':
        return <Badge className="bg-green-500">Paid</Badge>
      case 'completed':
      case 'delivered':
        return <Badge className="bg-green-500">Completed</Badge>
      case 'confirmed':
        return <Badge className="bg-blue-500">Confirmed</Badge>
      case 'processing':
        return <Badge className="bg-purple-500">Processing</Badge>
      case 'shipped':
        return <Badge className="bg-indigo-500">Shipped</Badge>
      case 'draft':
        return <Badge variant="secondary">Draft</Badge>
      case 'pending':
        return <Badge className="bg-yellow-500">Pending</Badge>
      case 'cancelled':
        return <Badge variant="destructive">Cancelled</Badge>
      default:
        return <Badge variant="outline">{status}</Badge>
    }
  }

  const getSourceBadge = (source: string) => {
    return source === 'pos' 
      ? <Badge variant="outline" className="border-purple-500 text-purple-500">POS</Badge>
      : <Badge variant="outline" className="border-blue-500 text-blue-500">Web</Badge>
  }

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-NG', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  }

  const formatPaymentMethod = (payment: NonNullable<SalesOrderDetail['payments']>[number]) => {
    const rawValue = payment.payment_method?.name || payment.method || 'Unknown'

    return rawValue
      .split(/[_\s]+/)
      .filter(Boolean)
      .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
      .join(' ')
  }

  const getDetailPaymentSummary = (order: SalesOrderDetail) => {
    if (order.source === 'pos') {
      if (!order.payments?.length) {
        return order.payment_status || 'Pending'
      }

      return order.payments.map(formatPaymentMethod).join(', ')
    }

    return order.payment_status ? order.payment_status.replace(/_/g, ' ') : 'Pending'
  }

  const getCustomerDisplayName = (order: SalesOrderDetail) => {
    if (order.customer?.name) {
      return order.customer.name
    }

    const fullName = [order.first_name, order.last_name].filter(Boolean).join(' ')
    return fullName || order.cashier_name || order.cashier || 'Walk-in Customer'
  }

  const getOrderItems = (order: SalesOrderDetail) => order.items || []

  const getShippingMethodLabel = (order: SalesOrderDetail) => {
    const method = order.shipping_method
    if (!method) return null

    if (method.label && method.label.trim() !== '') {
      return method.label
    }

    const provider = (method.provider_name || method.provider_code || '').trim()
    const service = (method.service_name || method.service_code || '').trim()
    const label = [provider, service].filter(Boolean).join(' - ').trim()

    return label || null
  }

  const handleViewOrder = async (order: SalesOrder) => {
    setLoadingOrderDetail(true)

    try {
      if (order.source === 'pos') {
        const response = await api.get(`/pos/orders/${order.id}`)
        setSelectedOrder({ ...response.data, source: 'pos', payment_status: order.payment_status })
      } else {
        const response = await api.get(`/admin/ecommerce/orders/${order.id}`)
        setSelectedOrder({ ...response.data.order, source: 'web' })
      }
    } catch (error: any) {
      toast({
        variant: 'destructive',
        title: 'Failed to load order',
        description: getApiErrorMessage(error, 'Could not load the order details.'),
      })
    } finally {
      setLoadingOrderDetail(false)
    }
  }

  return (
    <div className="flex h-full flex-col gap-4 p-4">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold">Orders</h1>
          <p className="text-muted-foreground">View and manage all orders (POS & Web)</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative w-64">
            <Icon icon="solar:magnifer-bold" className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Search order number, customer..."
              className="h-8 rounded-full pl-8 pr-3 text-xs"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <Select value={sourceFilter} onValueChange={setSourceFilter}>
            <SelectTrigger className="h-8 w-32 rounded-full text-xs">
              <SelectValue placeholder="Source" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Sources</SelectItem>
              <SelectItem value="pos">POS</SelectItem>
              <SelectItem value="web">Web</SelectItem>
            </SelectContent>
          </Select>
          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger className="h-8 w-36 rounded-full text-xs">
              <SelectValue placeholder="Status" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Status</SelectItem>
              <SelectItem value="paid">Paid</SelectItem>
              <SelectItem value="pending">Pending</SelectItem>
              <SelectItem value="confirmed">Confirmed</SelectItem>
              <SelectItem value="processing">Processing</SelectItem>
              <SelectItem value="shipped">Shipped</SelectItem>
              <SelectItem value="delivered">Delivered</SelectItem>
              <SelectItem value="cancelled">Cancelled</SelectItem>
            </SelectContent>
          </Select>
          <Input
            type="date"
            className="h-8 w-40 rounded-full px-3 text-xs"
            value={dateFilter}
            onChange={(e) => setDateFilter(e.target.value)}
          />
          {(search || statusFilter !== 'all' || sourceFilter !== 'all' || dateFilter) && (
            <Button
              variant="outline"
              size="sm"
              className="h-8 rounded-full px-3 text-xs"
              onClick={() => {
                setSearch('')
                setStatusFilter('all')
                setSourceFilter('all')
                setDateFilter('')
              }}
            >
              <Icon icon="solar:refresh-bold" className="mr-1.5 h-3.5 w-3.5" />
              Reset
            </Button>
          )}
          <Link to="/pos/terminal">
            <Button>
              <Icon icon="solar:cash-out-bold" className="h-4 w-4 mr-2" />
              New Sale
            </Button>
          </Link>
        </div>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <Card>
          <CardContent className="pt-4">
            <div className="text-2xl font-bold">{stats.total_orders}</div>
            <div className="text-sm text-muted-foreground">Total Orders</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-4">
            <div className="text-2xl font-bold text-purple-500">{stats.pos_orders}</div>
            <div className="text-sm text-muted-foreground">POS Orders</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-4">
            <div className="text-2xl font-bold text-blue-500">{stats.web_orders}</div>
            <div className="text-sm text-muted-foreground">Web Orders</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-4">
            <div className="text-2xl font-bold text-green-500">{formatCurrency(stats.total_revenue)}</div>
            <div className="text-sm text-muted-foreground">Total Revenue</div>
          </CardContent>
        </Card>
      </div>

      {/* Orders Table */}
      <Card className="flex-1">
        <CardContent className="p-0">
          {isLoading ? (
            <div className="flex items-center justify-center h-64">
              <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          ) : orders.length === 0 ? (
            <div className="flex flex-col items-center justify-center h-64 text-muted-foreground">
              <Icon icon="solar:document-text-bold" className="h-16 w-16 mb-4 opacity-50" />
              <p>No orders found</p>
            </div>
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Order #</TableHead>
                    <TableHead>Source</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead>Customer</TableHead>
                    <TableHead>Items</TableHead>
                    <TableHead>Total</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Payment</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {orders.map((order: SalesOrder) => (
                    <TableRow key={`${order.source}-${order.id}`}>
                      <TableCell className="font-medium">{order.order_number}</TableCell>
                      <TableCell>{getSourceBadge(order.source)}</TableCell>
                      <TableCell>{formatDate(order.created_at)}</TableCell>
                      <TableCell>
                        {order.customer ? (
                          <div>
                            <div className="font-medium">{order.customer.name}</div>
                            <div className="text-xs text-muted-foreground">{order.customer.email}</div>
                          </div>
                        ) : order.cashier ? (
                          <span className="text-muted-foreground">via {order.cashier}</span>
                        ) : (
                          <span className="text-muted-foreground">-</span>
                        )}
                      </TableCell>
                      <TableCell>
                        <Badge variant="secondary">{order.items_count}</Badge>
                      </TableCell>
                      <TableCell className="font-bold">{formatCurrency(Number(order.total))}</TableCell>
                      <TableCell>{getStatusBadge(order.status)}</TableCell>
                      <TableCell>
                        {order.payment_status === 'paid' ? (
                          <Badge className="bg-green-500">Paid</Badge>
                        ) : (
                          <Badge variant="outline">{order.payment_status}</Badge>
                        )}
                      </TableCell>
                      <TableCell className="text-right">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => handleViewOrder(order)}
                          disabled={loadingOrderDetail}
                        >
                          <Icon icon="solar:eye-bold-duotone" className="h-4 w-4" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>

              {/* Pagination */}
              <Pagination
                page={page}
                totalPages={pagination.last_page}
                onPageChange={setPage}
                from={pagination.from}
                to={pagination.to}
                total={pagination.total}
                label="orders"
                pageSize={pageSize}
                onPageSizeChange={(size) => { setPageSize(size); setPage(1) }}
              />
            </>
          )}
        </CardContent>
      </Card>

      {/* Order Detail Dialog */}
      <Dialog open={!!selectedOrder} onOpenChange={() => setSelectedOrder(null)}>
        <DialogContent className="sm:max-w-4xl">
          <DialogHeader>
            <DialogTitle className="flex items-center justify-between">
              <span>Order {selectedOrder?.order_number}</span>
              <div className="flex gap-2">
                {selectedOrder && getSourceBadge(selectedOrder.source)}
                {selectedOrder && getStatusBadge(selectedOrder.status)}
              </div>
            </DialogTitle>
          </DialogHeader>

          {selectedOrder && (
            <ScrollArea className="max-h-[80vh] pr-4">
              <div className="space-y-5">
                <div className="grid gap-3 md:grid-cols-3">
                  <div className="rounded-lg border bg-muted/30 p-3">
                    <p className="text-xs uppercase tracking-wide text-muted-foreground">Customer</p>
                    <p className="mt-1 font-medium">{getCustomerDisplayName(selectedOrder)}</p>
                    {selectedOrder.customer?.email && (
                      <p className="text-sm text-muted-foreground">{selectedOrder.customer.email}</p>
                    )}
                    {!selectedOrder.customer?.email && selectedOrder.email && (
                      <p className="text-sm text-muted-foreground">{selectedOrder.email}</p>
                    )}
                    {(selectedOrder.customer?.phone || selectedOrder.phone) && (
                      <p className="text-sm text-muted-foreground">{selectedOrder.customer?.phone || selectedOrder.phone}</p>
                    )}
                  </div>
                  <div className="rounded-lg border bg-muted/30 p-3">
                    <p className="text-xs uppercase tracking-wide text-muted-foreground">Payment</p>
                    <p className="mt-1 font-medium">{getDetailPaymentSummary(selectedOrder)}</p>
                    <p className="text-sm text-muted-foreground">Status: {selectedOrder.payment_status}</p>
                    {selectedOrder.invoice?.invoice_number && (
                      <p className="text-sm text-muted-foreground">Invoice: {selectedOrder.invoice.invoice_number}</p>
                    )}
                  </div>
                  <div className="rounded-lg border bg-muted/30 p-3">
                    <p className="text-xs uppercase tracking-wide text-muted-foreground">Handled By</p>
                    <p className="mt-1 font-medium">{selectedOrder.cashier_name || selectedOrder.cashier || selectedOrder.createdBy?.name || 'System'}</p>
                    {selectedOrder.receipt_number && (
                      <p className="text-sm text-muted-foreground">Receipt: {selectedOrder.receipt_number}</p>
                    )}
                    {selectedOrder.terminal?.name && (
                      <p className="text-sm text-muted-foreground">Terminal: {selectedOrder.terminal.name}</p>
                    )}
                  </div>
                </div>

                <div className="grid gap-3 text-sm md:grid-cols-4">
                  <div>
                    <span className="text-muted-foreground">Created</span>
                    <p className="font-medium">{formatDateTime(selectedOrder.created_at)}</p>
                  </div>
                  {selectedOrder.confirmed_at && (
                    <div>
                      <span className="text-muted-foreground">Confirmed</span>
                      <p className="font-medium">{formatDateTime(selectedOrder.confirmed_at)}</p>
                    </div>
                  )}
                  {selectedOrder.paid_at && (
                    <div>
                      <span className="text-muted-foreground">Paid At</span>
                      <p className="font-medium">{formatDateTime(selectedOrder.paid_at)}</p>
                    </div>
                  )}
                  {selectedOrder.shipped_at && (
                    <div>
                      <span className="text-muted-foreground">Shipped At</span>
                      <p className="font-medium">{formatDateTime(selectedOrder.shipped_at)}</p>
                    </div>
                  )}
                  {selectedOrder.delivered_at && (
                    <div>
                      <span className="text-muted-foreground">Delivered At</span>
                      <p className="font-medium">{formatDateTime(selectedOrder.delivered_at)}</p>
                    </div>
                  )}
                  {selectedOrder.session?.name && (
                    <div>
                      <span className="text-muted-foreground">Session</span>
                      <p className="font-medium">{selectedOrder.session.name}</p>
                    </div>
                  )}
                </div>

                {selectedOrder.shipping_address && (
                  <>
                    <Separator />
                    <div>
                      <h4 className="mb-2 font-medium">Shipping Address</h4>
                      <div className="rounded-lg border bg-muted/20 p-3 text-sm text-muted-foreground">
                        <p className="font-medium text-foreground">
                          {[selectedOrder.shipping_address.first_name, selectedOrder.shipping_address.last_name].filter(Boolean).join(' ') || getCustomerDisplayName(selectedOrder)}
                        </p>
                        {selectedOrder.shipping_address.address && <p>{selectedOrder.shipping_address.address}</p>}
                        <p>
                          {[
                            selectedOrder.shipping_address.city,
                            selectedOrder.shipping_address.state,
                            selectedOrder.shipping_address.postal_code,
                          ].filter(Boolean).join(', ')}
                        </p>
                        {selectedOrder.shipping_address.country && <p>{selectedOrder.shipping_address.country}</p>}
                        {selectedOrder.shipping_address.phone && <p>{selectedOrder.shipping_address.phone}</p>}
                      </div>
                      {getShippingMethodLabel(selectedOrder) && (
                        <p className="mt-2 text-sm text-muted-foreground">
                          Shipping Method: <span className="font-medium text-foreground">{getShippingMethodLabel(selectedOrder)}</span>
                        </p>
                      )}
                    </div>
                  </>
                )}

                <Separator />

                <div>
                  <h4 className="mb-2 font-medium">Order Lines</h4>
                  <div className="space-y-2">
                    {getOrderItems(selectedOrder).map((item) => {
                      const itemName = item.name || item.product_name || item.product?.name || 'Item'
                      const itemSku = item.sku || item.product_sku
                      const itemImage = item.variant?.image || item.product?.image || item.product_image

                      return (
                        <div key={item.id} className="flex items-center justify-between gap-3 rounded-lg border bg-muted/20 p-3 text-sm">
                          <div className="flex min-w-0 items-center gap-3">
                            {itemImage ? (
                              <img
                                src={resolveImageUrl(itemImage)}
                                alt={itemName}
                                className="h-12 w-12 rounded object-cover border bg-background"
                              />
                            ) : (
                              <div className="flex h-12 w-12 items-center justify-center rounded border bg-background text-muted-foreground">
                                <Icon icon="solar:box-bold-duotone" className="h-5 w-5" />
                              </div>
                            )}
                            <div className="min-w-0">
                              <p className="truncate font-medium">{itemName}</p>
                              {itemSku && <p className="truncate text-xs text-muted-foreground">SKU: {itemSku}</p>}
                              <p className="text-xs text-muted-foreground">
                                {item.quantity} x {formatCurrency(Number(item.unit_price))}
                                {Number(item.tax_amount || 0) > 0 && ` • Tax ${formatCurrency(Number(item.tax_amount || 0))}`}
                              </p>
                            </div>
                          </div>
                          <div className="shrink-0 text-right">
                            <p className="text-xs text-muted-foreground">Qty {item.quantity}</p>
                            <p className="font-medium">{formatCurrency(Number(item.line_total))}</p>
                          </div>
                        </div>
                      )
                    })}
                  </div>
                </div>

                {selectedOrder.source === 'pos' && selectedOrder.payments?.length ? (
                  <>
                    <Separator />
                    <div>
                      <h4 className="mb-2 font-medium">Payment Entries</h4>
                      <div className="space-y-2">
                        {selectedOrder.payments.map((payment) => (
                          <div key={payment.id} className="flex items-start justify-between gap-3 rounded-lg border border-green-200 bg-green-50/60 p-3 text-sm">
                            <div>
                              <p className="font-medium text-green-900">{formatPaymentMethod(payment)}</p>
                              {payment.reference && <p className="text-xs text-green-700/80">Reference: {payment.reference}</p>}
                              {payment.receivedBy?.name && <p className="text-xs text-green-700/80">Received by: {payment.receivedBy.name}</p>}
                              {payment.notes && <p className="text-xs text-green-700/80">{payment.notes}</p>}
                            </div>
                            <p className="font-medium text-green-900">{formatCurrency(Number(payment.amount))}</p>
                          </div>
                        ))}
                      </div>
                    </div>
                  </>
                ) : null}

                <Separator />

                <div className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Subtotal</span>
                    <span>{formatCurrency(Number(selectedOrder.subtotal))}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Tax</span>
                    <span>{formatCurrency(Number(selectedOrder.tax_amount))}</span>
                  </div>
                  {selectedOrder.shipping_cost && selectedOrder.shipping_cost > 0 && (
                    <div className="flex justify-between">
                      <span className="text-muted-foreground">Shipping</span>
                      <span>{formatCurrency(Number(selectedOrder.shipping_cost))}</span>
                    </div>
                  )}
                  {getShippingMethodLabel(selectedOrder) && (
                    <div className="flex justify-between">
                      <span className="text-muted-foreground">Shipping Method</span>
                      <span className="text-right">{getShippingMethodLabel(selectedOrder)}</span>
                    </div>
                  )}
                  {selectedOrder.discount_amount && selectedOrder.discount_amount > 0 && (
                    <div className="flex justify-between text-destructive">
                      <span>Discount</span>
                      <span>-{formatCurrency(Number(selectedOrder.discount_amount))}</span>
                    </div>
                  )}
                  {selectedOrder.loyalty_discount && selectedOrder.loyalty_discount > 0 && (
                    <div className="flex justify-between text-primary">
                      <span>Loyalty Discount</span>
                      <span>-{formatCurrency(Number(selectedOrder.loyalty_discount))}</span>
                    </div>
                  )}
                  <Separator />
                  <div className="flex justify-between text-lg font-bold">
                    <span>Total</span>
                    <span>{formatCurrency(Number(selectedOrder.total))}</span>
                  </div>
                </div>

                {selectedOrder.notes && (
                  <>
                    <Separator />
                    <div>
                      <h4 className="mb-1 font-medium text-sm">Notes</h4>
                      <p className="text-sm text-muted-foreground">{selectedOrder.notes}</p>
                    </div>
                  </>
                )}
              </div>
            </ScrollArea>
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}
