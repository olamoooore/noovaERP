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

  const { data: deliveryOrders, isLoading } = useQuery({
    queryKey: ['delivery-orders'],
    queryFn: async () => {
      const response = await api.get('/sales/delivery-orders')
      return response.data.data || []
    },
  })

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

  const filteredOrders = useMemo(() => {
    const items = Array.isArray(deliveryOrders) ? deliveryOrders : []
    if (!searchQuery.trim()) return items
    const term = searchQuery.toLowerCase()
    return items.filter((do_: any) =>
      String(do_.delivery_order_number || '').toLowerCase().includes(term)
      || String(do_.customer?.name || '').toLowerCase().includes(term)
      || String(do_.sales_order?.sales_order_number || '').toLowerCase().includes(term)
    )
  }, [deliveryOrders, searchQuery])

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
          <p className="text-muted-foreground">Track shipments dispatched from sales orders.</p>
        </div>
        <div className="flex gap-3">
          <input
            type="text"
            placeholder="Search deliveries..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="px-3 py-2 border rounded-md text-sm w-64"
          />
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Delivery Orders</CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="py-12 text-center">
              <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
              <p className="mt-4 text-sm text-muted-foreground">Loading deliveries...</p>
            </div>
          ) : filteredOrders.length === 0 ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="solar:package-bold-duotone" className="mx-auto h-12 w-12 opacity-30" />
              <p className="mt-4">No delivery orders found.</p>
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Delivery #</TableHead>
                  <TableHead>Sales Order</TableHead>
                  <TableHead>Customer</TableHead>
                  <TableHead>Delivery Date</TableHead>
                  <TableHead>Lines</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredOrders.map((do_: any) => (
                  <TableRow key={do_.id}>
                    <TableCell className="font-medium">{do_.delivery_order_number}</TableCell>
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
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => navigate(`/delivery-orders/${do_.id}`)}
                        >
                          View
                        </Button>
                        {do_.status === 'draft' && (
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => confirmMutation.mutate(do_.id)}
                          >
                            Confirm
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

export default DeliveryOrdersPage
