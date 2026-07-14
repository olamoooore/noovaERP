import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { useNavigate } from 'react-router-dom'
import { formatDate } from '@/lib/utils'

export function DeliveryOrdersPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [searchQuery, setSearchQuery] = useState('')
  const [typeFilter, setTypeFilter] = useState<string>('all')
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [page, setPage] = useState(1)
  const [perPage] = useState(20)

  const { data: response, isLoading } = useQuery({
    queryKey: ['delivery-orders', page, perPage, typeFilter, searchQuery],
    queryFn: async () => {
      const params: any = { page, per_page: perPage }
      if (typeFilter !== 'all') params.type = typeFilter
      if (searchQuery.trim()) params.search = searchQuery.trim()
      const res = await api.get('/sales/delivery-orders', { params })
      return res.data
    },
  })

  const deliveryOrders = response?.data || []
  const meta = response?.meta || { current_page: 1, last_page: 1, total: 0 }

  const confirmMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.post(`/sales/delivery-orders/${id}/confirm`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      toast({ title: 'Delivery order confirmed' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to confirm delivery',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const bulkDeleteMutation = useMutation({
    mutationFn: async (ids: number[]) => {
      const res = await api.post('/sales/delivery-orders/bulk-delete', { ids })
      return res.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      setSelectedIds([])
      toast({ title: `${data.deleted} delivery order(s) deleted` })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Bulk delete failed',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const toggleSelectAll = () => {
    if (selectedIds.length === deliveryOrders.length) {
      setSelectedIds([])
    } else {
      setSelectedIds(deliveryOrders.map((d: any) => d.id))
    }
  }

  const toggleSelect = (id: number) => {
    setSelectedIds((prev) => (prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]))
  }

  const getStatusBadge = (status: string) => {
    const styles: Record<string, string> = {
      draft: 'bg-gray-100 text-gray-800',
      confirmed: 'bg-green-100 text-green-800',
      cancelled: 'bg-red-100 text-red-800',
    }
    return styles[status] || 'bg-gray-100 text-gray-800'
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Delivery Orders</h1>
          <p className="text-muted-foreground">Track shipments dispatched from sales orders or standalone.</p>
        </div>
        <div className="flex gap-3">
          <Button variant="outline" onClick={() => navigate('/delivery-orders/import')}>
            <Icon icon="solar:upload-bold" className="h-4 w-4 mr-2" />
            Import
          </Button>
          <Button onClick={() => navigate('/delivery-orders/create')}>
            <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-2" />
            Create Delivery Order
          </Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span>Delivery Orders</span>
            {selectedIds.length > 0 && (
              <Button
                variant="destructive"
                size="sm"
                onClick={() => {
                  if (confirm(`Delete ${selectedIds.length} delivery order(s)?`)) {
                    bulkDeleteMutation.mutate(selectedIds)
                  }
                }}
              >
                <Icon icon="solar:trash-bin-minimalistic-bold" className="h-4 w-4 mr-1" />
                Delete ({selectedIds.length})
              </Button>
            )}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex gap-3 mb-4">
            <input
              type="text"
              placeholder="Search deliveries..."
              value={searchQuery}
              onChange={(e) => { setSearchQuery(e.target.value); setPage(1) }}
              className="px-3 py-2 border rounded-md text-sm w-64"
            />
            <select
              value={typeFilter}
              onChange={(e) => { setTypeFilter(e.target.value); setPage(1) }}
              className="px-3 py-2 border rounded-md text-sm"
            >
              <option value="all">All Types</option>
              <option value="standalone">Standalone</option>
              <option value="linked">From Sales Order</option>
            </select>
          </div>

          {isLoading ? (
            <div className="py-12 text-center">
              <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
              <p className="mt-4 text-sm text-muted-foreground">Loading deliveries...</p>
            </div>
          ) : deliveryOrders.length === 0 ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="solar:package-bold-duotone" className="mx-auto h-12 w-12 opacity-30" />
              <p className="mt-4">No delivery orders found.</p>
            </div>
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-[40px]">
                      <input type="checkbox" checked={selectedIds.length === deliveryOrders.length && deliveryOrders.length > 0} onChange={toggleSelectAll} />
                    </TableHead>
                    <TableHead>Delivery #</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Sales Order</TableHead>
                    <TableHead>Customer</TableHead>
                    <TableHead>Delivery Date</TableHead>
                    <TableHead>Lines</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {deliveryOrders.map((do_: any) => (
                    <TableRow key={do_.id}>
                      <TableCell>
                        <input type="checkbox" checked={selectedIds.includes(do_.id)} onChange={() => toggleSelect(do_.id)} />
                      </TableCell>
                      <TableCell className="font-medium">{do_.delivery_order_number}</TableCell>
                      <TableCell>
                        <Badge variant="outline" className={do_.sales_order_id ? '' : 'border-blue-300 text-blue-700'}>
                          {do_.sales_order_id ? 'Linked' : 'Standalone'}
                        </Badge>
                      </TableCell>
                      <TableCell>{do_.sales_order?.sales_order_number || '-'}</TableCell>
                      <TableCell>{do_.customer?.name || '-'}</TableCell>
                      <TableCell>{do_.delivery_date ? formatDate(do_.delivery_date) : '-'}</TableCell>
                      <TableCell>{do_.lines?.length || 0}</TableCell>
                      <TableCell>
                        <Badge variant="secondary" className={getStatusBadge(do_.status)}>
                          {do_.status}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <div className="flex gap-1">
                          <Button variant="ghost" size="sm" onClick={() => navigate(`/delivery-orders/${do_.id}`)}>
                            View
                          </Button>
                          {do_.status === 'draft' && (
                            <Button variant="ghost" size="sm" onClick={() => confirmMutation.mutate(do_.id)}>
                              Confirm
                            </Button>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>

              {/* Pagination */}
              {meta.last_page > 1 && (
                <div className="flex items-center justify-between mt-4">
                  <p className="text-sm text-muted-foreground">
                    Page {meta.current_page} of {meta.last_page} ({meta.total} total)
                  </p>
                  <div className="flex gap-2">
                    <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                      Previous
                    </Button>
                    <Button variant="outline" size="sm" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

export default DeliveryOrdersPage
