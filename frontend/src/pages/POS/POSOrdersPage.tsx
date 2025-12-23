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

interface PosOrder {
  id: number
  order_number: string
  status: 'draft' | 'paid' | 'cancelled'
  subtotal: number
  tax_amount: number
  total: number
  currency: string
  notes?: string
  paid_at?: string
  created_at: string
  items?: PosOrderItem[]
  payments?: PosPayment[]
  created_by?: { name: string }
}

interface PosOrderItem {
  id: number
  name: string
  sku: string
  quantity: number
  unit_price: number
  tax_rate: number
  tax_amount: number
  line_total: number
}

interface PosPayment {
  id: number
  method: string
  amount: number
  reference?: string
  created_at: string
}

export function POSOrdersPage() {
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('all')
  const [dateFilter, setDateFilter] = useState<string>('')
  const [selectedOrder, setSelectedOrder] = useState<PosOrder | null>(null)
  const [page, setPage] = useState(1)

  const { data, isLoading } = useQuery({
    queryKey: ['pos-orders', search, statusFilter, dateFilter, page],
    queryFn: async () => {
      const params: Record<string, any> = { page, per_page: 20 }
      if (search) params.search = search
      if (statusFilter !== 'all') params.status = statusFilter
      if (dateFilter) params.date = dateFilter
      const response = await api.get('/pos/orders', { params })
      return response.data
    },
  })

  const orders = data?.data || data || []
  const pagination = data?.meta || { current_page: 1, last_page: 1 }

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'paid':
        return <Badge className="bg-green-500">Paid</Badge>
      case 'draft':
        return <Badge variant="secondary">Draft</Badge>
      case 'cancelled':
        return <Badge variant="destructive">Cancelled</Badge>
      default:
        return <Badge variant="outline">{status}</Badge>
    }
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

  const getPaymentMethodIcon = (method: string) => {
    switch (method) {
      case 'cash':
        return 'solar:money-bag-bold'
      case 'card':
        return 'solar:card-bold'
      case 'bank_transfer':
        return 'mdi:bank'
      default:
        return 'solar:wallet-bold'
    }
  }

  return (
    <div className="flex h-full flex-col gap-4 p-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">POS Orders</h1>
          <p className="text-muted-foreground">View and manage point of sale orders</p>
        </div>
        <Link to="/pos/terminal">
          <Button>
            <Icon icon="solar:cash-out-bold" className="h-4 w-4 mr-2" />
            New Sale
          </Button>
        </Link>
      </div>

      {/* Filters */}
      <Card>
        <CardContent className="pt-4">
          <div className="flex flex-wrap gap-4">
            <div className="relative flex-1 min-w-[200px]">
              <Icon icon="solar:magnifer-bold" className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search order number..."
                className="pl-9"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            <Select value={statusFilter} onValueChange={setStatusFilter}>
              <SelectTrigger className="w-[150px]">
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="paid">Paid</SelectItem>
                <SelectItem value="draft">Draft</SelectItem>
                <SelectItem value="cancelled">Cancelled</SelectItem>
              </SelectContent>
            </Select>
            <Input
              type="date"
              className="w-[180px]"
              value={dateFilter}
              onChange={(e) => setDateFilter(e.target.value)}
            />
            {(search || statusFilter !== 'all' || dateFilter) && (
              <Button
                variant="outline"
                onClick={() => {
                  setSearch('')
                  setStatusFilter('all')
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
                    <TableHead>Date</TableHead>
                    <TableHead>Items</TableHead>
                    <TableHead>Subtotal</TableHead>
                    <TableHead>Tax</TableHead>
                    <TableHead>Total</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {orders.map((order: PosOrder) => (
                    <TableRow key={order.id}>
                      <TableCell className="font-medium">{order.order_number}</TableCell>
                      <TableCell>{formatDate(order.created_at)}</TableCell>
                      <TableCell>
                        <Badge variant="secondary">{order.items?.length || 0}</Badge>
                      </TableCell>
                      <TableCell>{formatCurrency(Number(order.subtotal))}</TableCell>
                      <TableCell>{formatCurrency(Number(order.tax_amount))}</TableCell>
                      <TableCell className="font-bold">{formatCurrency(Number(order.total))}</TableCell>
                      <TableCell>{getStatusBadge(order.status)}</TableCell>
                      <TableCell className="text-right">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setSelectedOrder(order)}
                        >
                          <Icon icon="solar:eye-bold" className="h-4 w-4" />
                        </Button>
                        <Button variant="ghost" size="sm">
                          <Icon icon="solar:printer-bold" className="h-4 w-4" />
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
              {selectedOrder && getStatusBadge(selectedOrder.status)}
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
              </div>

              <Separator />

              {/* Items */}
              <div>
                <h4 className="font-medium mb-2">Items</h4>
                <ScrollArea className="max-h-48">
                  <div className="space-y-2">
                    {selectedOrder.items?.map((item) => (
                      <div key={item.id} className="flex justify-between text-sm p-2 rounded bg-muted/50">
                        <div>
                          <p className="font-medium">{item.name}</p>
                          <p className="text-xs text-muted-foreground">
                            {item.quantity} x {formatCurrency(Number(item.unit_price))}
                          </p>
                        </div>
                        <span className="font-medium">{formatCurrency(Number(item.line_total))}</span>
                      </div>
                    ))}
                  </div>
                </ScrollArea>
              </div>

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
                <Separator />
                <div className="flex justify-between font-bold text-lg">
                  <span>Total</span>
                  <span>{formatCurrency(Number(selectedOrder.total))}</span>
                </div>
              </div>

              {/* Payments */}
              {selectedOrder.payments && selectedOrder.payments.length > 0 && (
                <>
                  <Separator />
                  <div>
                    <h4 className="font-medium mb-2">Payments</h4>
                    <div className="space-y-2">
                      {selectedOrder.payments.map((payment) => (
                        <div key={payment.id} className="flex items-center justify-between text-sm p-2 rounded bg-green-50 dark:bg-green-950">
                          <div className="flex items-center gap-2">
                            <Icon icon={getPaymentMethodIcon(payment.method)} className="h-5 w-5" />
                            <span className="capitalize">{payment.method.replace('_', ' ')}</span>
                          </div>
                          <span className="font-medium">{formatCurrency(Number(payment.amount))}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                </>
              )}

              {/* Notes */}
              {selectedOrder.notes && (
                <>
                  <Separator />
                  <div>
                    <h4 className="font-medium mb-1">Notes</h4>
                    <p className="text-sm text-muted-foreground">{selectedOrder.notes}</p>
                  </div>
                </>
              )}

              {/* Actions */}
              <div className="flex gap-2 pt-4">
                <Button variant="outline" className="flex-1">
                  <Icon icon="solar:printer-bold" className="h-4 w-4 mr-2" />
                  Print Receipt
                </Button>
                <Button variant="outline" className="flex-1">
                  <Icon icon="solar:download-bold" className="h-4 w-4 mr-2" />
                  Download PDF
                </Button>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}
