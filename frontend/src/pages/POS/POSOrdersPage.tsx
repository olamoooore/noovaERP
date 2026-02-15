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
import { formatCurrency } from '@/lib/utils'

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

export function POSOrdersPage() {
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('all')
  const [sourceFilter, setSourceFilter] = useState<string>('all')
  const [dateFilter, setDateFilter] = useState<string>('')
  const [selectedOrder, setSelectedOrder] = useState<SalesOrder | null>(null)
  const [page, setPage] = useState(1)

  const { data, isLoading } = useQuery({
    queryKey: ['sales-orders', search, statusFilter, sourceFilter, dateFilter, page],
    queryFn: async () => {
      const params: Record<string, any> = { page, per_page: 20 }
      if (search) params.search = search
      if (statusFilter !== 'all') params.status = statusFilter
      if (sourceFilter !== 'all') params.source = sourceFilter
      if (dateFilter) params.date = dateFilter
      const response = await api.get('/sales/orders', { params })
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

  return (
    <div className="flex h-full flex-col gap-4 p-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Orders</h1>
          <p className="text-muted-foreground">View and manage all orders (POS & Web)</p>
        </div>
        <Link to="/pos/terminal">
          <Button>
            <Icon icon="solar:cash-out-bold" className="h-4 w-4 mr-2" />
            New Sale
          </Button>
        </Link>
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

      {/* Filters */}
      <Card>
        <CardContent className="pt-4">
          <div className="flex flex-wrap gap-4">
            <div className="relative flex-1 min-w-[200px]">
              <Icon icon="solar:magnifer-bold" className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search order number, customer..."
                className="pl-9"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            <Select value={sourceFilter} onValueChange={setSourceFilter}>
              <SelectTrigger className="w-[120px]">
                <SelectValue placeholder="Source" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Sources</SelectItem>
                <SelectItem value="pos">POS</SelectItem>
                <SelectItem value="web">Web</SelectItem>
              </SelectContent>
            </Select>
            <Select value={statusFilter} onValueChange={setStatusFilter}>
              <SelectTrigger className="w-[150px]">
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
              className="w-[180px]"
              value={dateFilter}
              onChange={(e) => setDateFilter(e.target.value)}
            />
            {(search || statusFilter !== 'all' || sourceFilter !== 'all' || dateFilter) && (
              <Button
                variant="outline"
                onClick={() => {
                  setSearch('')
                  setStatusFilter('all')
                  setSourceFilter('all')
                  setDateFilter('')
                }}
              >
                <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2" />
                Reset
              </Button>
            )}
          </div>
        </CardContent>
      </Card>

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
                          onClick={() => setSelectedOrder(order)}
                        >
                          <Icon icon="solar:eye-bold" className="h-4 w-4" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>

              {/* Pagination */}
              {pagination.last_page > 1 && (
                <div className="flex items-center justify-between p-4 border-t">
                  <span className="text-sm text-muted-foreground">
                    Page {pagination.current_page} of {pagination.last_page}
                  </span>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={page === 1}
                      onClick={() => setPage(p => p - 1)}
                    >
                      Previous
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={page === pagination.last_page}
                      onClick={() => setPage(p => p + 1)}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      {/* Order Detail Dialog */}
      <Dialog open={!!selectedOrder} onOpenChange={() => setSelectedOrder(null)}>
        <DialogContent className="sm:max-w-lg">
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
            <div className="space-y-4">
              {/* Order Info */}
              <div className="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <span className="text-muted-foreground">Date:</span>
                  <p className="font-medium">{formatDate(selectedOrder.created_at)}</p>
                </div>
                {selectedOrder.paid_at && (
                  <div>
                    <span className="text-muted-foreground">Paid At:</span>
                    <p className="font-medium">{formatDate(selectedOrder.paid_at)}</p>
                  </div>
                )}
                {selectedOrder.shipped_at && (
                  <div>
                    <span className="text-muted-foreground">Shipped At:</span>
                    <p className="font-medium">{formatDate(selectedOrder.shipped_at)}</p>
                  </div>
                )}
                {selectedOrder.delivered_at && (
                  <div>
                    <span className="text-muted-foreground">Delivered At:</span>
                    <p className="font-medium">{formatDate(selectedOrder.delivered_at)}</p>
                  </div>
                )}
              </div>

              {selectedOrder.customer && (
                <>
                  <Separator />
                  <div>
                    <h4 className="font-medium mb-2">Customer</h4>
                    <p className="font-medium">{selectedOrder.customer.name}</p>
                    <p className="text-sm text-muted-foreground">{selectedOrder.customer.email}</p>
                  </div>
                </>
              )}

              <Separator />

              {/* Totals */}
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
                <div className="flex justify-between font-bold text-lg">
                  <span>Total</span>
                  <span>{formatCurrency(Number(selectedOrder.total))}</span>
                </div>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}
