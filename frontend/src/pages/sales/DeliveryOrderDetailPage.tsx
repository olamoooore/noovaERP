import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { formatDate } from '@/lib/utils'

export function DeliveryOrderDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const { data: deliveryOrder, isLoading } = useQuery({
    queryKey: ['delivery-order', id],
    queryFn: async () => {
      const response = await api.get(`/sales/delivery-orders/${id}`)
      return response.data
    },
    enabled: !!id,
  })

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
          <Button variant="ghost" size="sm" onClick={() => navigate('/delivery-orders')}>
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
          {deliveryOrder.sales_order && (
            <Button
              variant="outline"
              onClick={() => navigate(`/sales/orders/${deliveryOrder.sales_order.id}`)}
            >
              View Sales Order
            </Button>
          )}
        </div>
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
                <div className="text-muted-foreground">Expected Date</div>
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
                <div className="text-muted-foreground">Created By</div>
                <div className="font-medium">{deliveryOrder.createdBy?.name || '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Confirmed By</div>
                <div className="font-medium">{deliveryOrder.confirmedBy?.name || '-'}</div>
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
            <Table>
            <TableHeader>
                <TableRow>
                 <TableHead>#</TableHead>
                  <TableHead>Product</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead className="text-right">Qty Ordered</TableHead>
                  <TableHead className="text-right">Qty Delivered</TableHead>
                  <TableHead className="text-right">Unit</TableHead>
                  <TableHead>Lot Number</TableHead>
                </TableRow>
              </TableHeader>
            <TableBody>
              {deliveryOrder.lines?.map((line: any, index: number) => (
                <TableRow key={line.id}>
                  <TableCell>{index + 1}</TableCell>
                  <TableCell className="font-medium">{line.product?.name || '-'}</TableCell>
                  <TableCell>{line.description || '-'}</TableCell>
                  <TableCell className="text-right">{line.quantity_ordered}</TableCell>
                  <TableCell className="text-right font-medium text-green-600">{line.quantity_delivered}</TableCell>
                  <TableCell className="text-right">{line.unit}</TableCell>
                  <TableCell>{line.lot_number || '-'}</TableCell>
                </TableRow>
              ))}
              {(!deliveryOrder.lines || deliveryOrder.lines.length === 0) && (
                <TableRow>
                  <TableCell colSpan={6} className="text-center text-muted-foreground py-8">
                    No lines in this delivery order.
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  )
}

export default DeliveryOrderDetailPage
