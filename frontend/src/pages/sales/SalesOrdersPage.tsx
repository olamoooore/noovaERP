import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { useNavigate } from 'react-router-dom'
import { formatDate, formatCurrency } from '@/lib/utils'
import { SalesLineColumnVisibility, listingColumns } from '@/components/sales/DeliveryOrderColumnVisibility'
import { Pagination } from '@/components/ui/Pagination'
import { SortableHead } from '@/components/ui/SortableHead'
import { useSort } from '@/hooks/useSort'

const STATUS_FILTERS = [
  { value: 'all', label: 'All Statuses' },
  { value: 'draft', label: 'Draft' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'allocated', label: 'Allocated' },
  { value: 'fulfilled', label: 'Fulfilled' },
  { value: 'invoiced', label: 'Invoiced' },
  { value: 'cancelled', label: 'Cancelled' },
]

const CHANNEL_FILTERS = [
  { value: 'all', label: 'All Channels' },
  { value: 'admin', label: 'Admin' },
  { value: 'pos', label: 'POS' },
  { value: 'ecommerce', label: 'Ecommerce' },
]

export function SalesOrdersPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [searchQuery, setSearchQuery] = useState('')
  // Wide search hits the DB per term; debounce typing like the other lists do.
  const [debouncedSearchQuery, setDebouncedSearchQuery] = useState('')
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearchQuery(searchQuery), 300)
    return () => clearTimeout(timer)
  }, [searchQuery])
  const [statusFilter, setStatusFilter] = useState('all')
  const [channelFilter, setChannelFilter] = useState('all')

  const { data, isLoading } = useQuery({
    queryKey: ['sales-orders', { page, pageSize, debouncedSearchQuery, statusFilter, channelFilter }],
    queryFn: async () => {
      const params: any = { per_page: pageSize, page }
      if (debouncedSearchQuery.trim()) params.search = debouncedSearchQuery.trim()
      if (statusFilter !== 'all') params.status = statusFilter
      if (channelFilter !== 'all') params.channel = channelFilter
      const response = await api.get('/sales/orders', { params })
      return response.data
    },
  })

  const confirmMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.post(`/sales/orders/${id}/confirm`)
      return response.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      if (data?.requires_approval) {
        toast({
          title: 'Approval required',
          description: data?.message || 'An approval request has been submitted for this order.',
        })
        return
      }
      toast({ title: 'Order confirmed — stock reserved' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to confirm',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const cancelMutation = useMutation({
    mutationFn: async ({ id, reason }: { id: number; reason: string }) => {
      return await api.post(`/sales/orders/${id}/cancel`, { reason })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      toast({ title: 'Order cancelled — reservations released' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to cancel',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const orders = data?.data || []
  const { sorted: sortedOrders, sortKey, sortDir, toggle: toggleSort } = useSort<any>(orders, 'created_at', 'desc')
  const meta = data?.meta || data

  const getStatusBadge = (status: string) => {
    const styles: Record<string, string> = {
      draft: 'bg-gray-100 text-gray-800',
      confirmed: 'bg-blue-100 text-blue-800',
      allocated: 'bg-yellow-100 text-yellow-800',
      fulfilled: 'bg-green-100 text-green-800',
      invoiced: 'bg-purple-100 text-purple-800',
      cancelled: 'bg-red-100 text-red-800',
    }
    return styles[status] || 'bg-gray-100 text-gray-800'
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Sales Orders</h1>
          <p className="text-muted-foreground">All sales orders across every channel (POS, web, admin).</p>
        </div>
        <div className="flex gap-3">
          <input
            type="text"
            placeholder="Search order, customer, contact…"
            value={searchQuery}
            onChange={(e) => { setSearchQuery(e.target.value); setPage(1) }}
            className="px-3 py-2 border rounded-md text-sm w-56"
          />
          <Select value={statusFilter} onValueChange={(v) => { setStatusFilter(v); setPage(1) }}>
            <SelectTrigger className="w-[150px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {STATUS_FILTERS.map((f) => (
                <SelectItem key={f.value} value={f.value}>{f.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select value={channelFilter} onValueChange={(v) => { setChannelFilter(v); setPage(1) }}>
            <SelectTrigger className="w-[140px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {CHANNEL_FILTERS.map((f) => (
                <SelectItem key={f.value} value={f.value}>{f.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Sales Orders ({meta?.total ?? orders.length})</CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="py-12 text-center">
              <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
              <p className="mt-4 text-sm text-muted-foreground">Loading sales orders...</p>
            </div>
          ) : orders.length === 0 ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="solar:document-text-bold-duotone" className="mx-auto h-12 w-12 opacity-30" />
              <p className="mt-4">No sales orders found.</p>
            </div>
          ) : (
            <>
              <SalesLineColumnVisibility tableClass="sales-orders-listing-columns" columns={listingColumns(['Order #', 'Customer', 'Channel', 'Date', 'Created Date', 'Total', 'Status', 'Actions'])}>
              <Table>
                <TableHeader>
                  <TableRow>
                    <SortableHead label="Order #" sortKey="sales_order_number" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Customer" sortKey="customer.name" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Channel" sortKey="channel" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Date" sortKey="order_date" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Created Date" sortKey="created_at" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Total" sortKey="total" activeKey={sortKey} direction={sortDir} onSort={toggleSort} align="right" />
                    <SortableHead label="Status" sortKey="status" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <TableHead>Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {sortedOrders.map((order: any) => (
                    <TableRow key={order.id}>
                      <TableCell className="font-medium">
                        <div>{order.sales_order_number}</div>
                        <div className="flex gap-1.5 mt-1">
                          {order.invoice_links_count > 0 && (
                            <button
                              onClick={() => navigate(`/invoices?sales_order_id=${order.id}`)}
                              className="inline-flex items-center gap-1 rounded bg-blue-50 dark:bg-blue-950 px-2 py-0.5 text-xs font-medium text-blue-700 dark:text-blue-300 hover:bg-blue-100 dark:hover:bg-blue-900"
                            >
                              <Icon icon="solar:bill-list-bold" className="h-3 w-3" />
                              {order.invoice_links_count} Inv
                            </button>
                          )}
                          {order.delivery_orders_count > 0 && (
                            <button
                              onClick={() => navigate(`/delivery-orders?sales_order_id=${order.id}`)}
                              className="inline-flex items-center gap-1 rounded bg-amber-50 dark:bg-amber-950 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900"
                            >
                              <Icon icon="solar:delivery-bold" className="h-3 w-3" />
                              {order.delivery_orders_count} DO
                            </button>
                          )}
                        </div>
                      </TableCell>
                      <TableCell>
                        <div>{order.customer?.name || '-'}</div>
                        {/* Wide search can match a contact/address/line/tag on the order's customer. */}
                        {(order.matched_contacts?.length ?? 0) > 0 && (
                          <div className="truncate text-xs text-primary" title={order.matched_contacts.join(', ')}>
                            Contact: {order.matched_contacts.join(', ')}
                          </div>
                        )}
                        {(order.matched_tags?.length ?? 0) > 0 && (
                          <div className="truncate text-xs text-amber-600" title={order.matched_tags.join(', ')}>
                            Tag: {order.matched_tags.join(', ')}
                          </div>
                        )}
                        {(order.matched_lines?.length ?? 0) > 0 && (
                          <div className="truncate text-xs text-muted-foreground" title={order.matched_lines.join(' · ')}>
                            Line: {order.matched_lines.join(' · ')}
                          </div>
                        )}
                        {(order.matched_addresses?.length ?? 0) > 0 && (
                          <div className="truncate text-xs text-muted-foreground" title={order.matched_addresses.join(' · ')}>
                            Address: {order.matched_addresses.join(' · ')}
                          </div>
                        )}
                      </TableCell>
                      <TableCell>
                        <Badge variant="outline" className="capitalize">{order.channel || 'admin'}</Badge>
                      </TableCell>
                      <TableCell>{order.order_date ? formatDate(order.order_date) : '-'}</TableCell>
                      <TableCell>{formatDate(order.created_at || order.order_date)}</TableCell>
                      <TableCell className="text-right">{formatCurrency(order.total || 0)}</TableCell>
                      <TableCell>
                        <Badge variant="secondary" className={getStatusBadge(order.status)}>
                          {order.status}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <div className="flex gap-1">
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => navigate(`/sales/orders/${order.id}`)}
                          >
                            View
                          </Button>
                          {order.status === 'draft' && (
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => confirmMutation.mutate(order.id)}
                              disabled={confirmMutation.isPending}
                            >
                              Confirm
                            </Button>
                          )}
                          {(order.status === 'draft' || order.status === 'confirmed' || order.status === 'allocated') && (
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => {
                                const reason = prompt('Cancellation reason:')
                                if (reason && reason.length >= 3) {
                                  cancelMutation.mutate({ id: order.id, reason })
                                }
                              }}
                              disabled={cancelMutation.isPending}
                            >
                              Cancel
                            </Button>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              </SalesLineColumnVisibility>

              {/* Pagination */}
              <Pagination
                page={page}
                totalPages={meta?.last_page ?? 1}
                onPageChange={setPage}
                from={(page - 1) * pageSize + 1}
                to={Math.min(page * pageSize, meta?.total ?? 0)}
                total={meta?.total ?? 0}
                label="sales orders"
                pageSize={pageSize}
                onPageSizeChange={(size) => { setPageSize(size); setPage(1) }}
              />
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

export default SalesOrdersPage
