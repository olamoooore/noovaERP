import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { Icon } from '@iconify/react'

export function DeliveryOrderCreatePage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const preselectedSoId = searchParams.get('sales_order_id')
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const [salesOrderId, setSalesOrderId] = useState<string>(preselectedSoId || '')
  const [deliveryDate, setDeliveryDate] = useState(new Date().toISOString().split('T')[0])
  const [expectedDate, setExpectedDate] = useState('')
  const [carrier, setCarrier] = useState('')
  const [trackingNumber, setTrackingNumber] = useState('')
  const [notes, setNotes] = useState('')
  const [lineQuantities, setLineQuantities] = useState<Record<number, number>>({})

  const { data: soData, isLoading: soLoading, refetch: fetchSO } = useQuery({
    queryKey: ['so-deliveries', salesOrderId],
    queryFn: async () => {
      const response = await api.get(`/sales/orders/${salesOrderId}/deliveries`)
      return response.data
    },
    enabled: !!salesOrderId,
  })

  const createMutation = useMutation({
    mutationFn: async (payload: any) => {
      const response = await api.post('/sales/delivery-orders', payload)
      return response.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      toast({ title: 'Delivery order created' })
      navigate(`/delivery-orders/${data.data.id}`)
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to create delivery order',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const handleSubmit = () => {
    if (!salesOrderId) {
      toast({ variant: 'destructive', title: 'Please enter a Sales Order ID' })
      return
    }

    const lines = (soData?.lines || [])
      .filter((line: any) => (lineQuantities[line.sales_order_line_id] || 0) > 0)
      .map((line: any) => ({
        sales_order_line_id: line.sales_order_line_id,
        quantity_delivered: lineQuantities[line.sales_order_line_id] || 0,
      }))

    if (lines.length === 0) {
      toast({ variant: 'destructive', title: 'Please set quantity for at least one line' })
      return
    }

    createMutation.mutate({
      sales_order_id: parseInt(salesOrderId),
      delivery_date: deliveryDate || undefined,
      expected_date: expectedDate || undefined,
      carrier: carrier || undefined,
      tracking_number: trackingNumber || undefined,
      notes: notes || undefined,
      lines,
    })
  }

  const updateLineQty = (lineId: number, qty: number) => {
    setLineQuantities(prev => ({ ...prev, [lineId]: qty }))
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="sm" onClick={() => navigate('/delivery-orders')}>
          <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
          Back
        </Button>
        <div>
          <h1 className="text-3xl font-bold">Create Delivery Order</h1>
          <p className="text-muted-foreground mt-1">Dispatch items from a confirmed sales order.</p>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Sales Order</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex gap-3 items-end">
            <div className="flex-1">
              <label className="text-sm font-medium">Sales Order ID</label>
              <Input
                type="number"
                value={salesOrderId}
                onChange={(e) => setSalesOrderId(e.target.value)}
                placeholder="Enter sales order ID"
                disabled={!!preselectedSoId}
              />
            </div>
            <Button onClick={() => fetchSO()} disabled={!salesOrderId || soLoading}>
              Load Order
            </Button>
          </div>

          {soData && (
            <div className="p-3 rounded-lg bg-blue-50 border border-blue-200 text-sm">
              <span className="font-medium">{soData.sales_order?.sales_order_number}</span>
              {' — '}
              <span className="text-muted-foreground">Status: {soData.sales_order?.status}</span>
            </div>
          )}
        </CardContent>
      </Card>

      {soData && (
        <>
          <Card>
            <CardHeader>
              <CardTitle>Delivery Details</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                  <label className="text-sm font-medium">Delivery Date</label>
                  <Input type="date" value={deliveryDate} onChange={(e) => setDeliveryDate(e.target.value)} />
                </div>
                <div>
                  <label className="text-sm font-medium">Expected Date</label>
                  <Input type="date" value={expectedDate} onChange={(e) => setExpectedDate(e.target.value)} />
                </div>
                <div>
                  <label className="text-sm font-medium">Carrier</label>
                  <Input value={carrier} onChange={(e) => setCarrier(e.target.value)} placeholder="e.g. DHL, FedEx" />
                </div>
                <div>
                  <label className="text-sm font-medium">Tracking #</label>
                  <Input value={trackingNumber} onChange={(e) => setTrackingNumber(e.target.value)} placeholder="Tracking number" />
                </div>
              </div>
              <div>
                <label className="text-sm font-medium">Notes</label>
                <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional notes" />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Line Items — Quantities to Deliver</CardTitle>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Product</TableHead>
                    <TableHead>Description</TableHead>
                    <TableHead className="text-right">Ordered</TableHead>
                    <TableHead className="text-right">Already Delivered</TableHead>
                    <TableHead className="text-right">Remaining</TableHead>
                    <TableHead className="text-right">Deliver Now</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {soData.lines?.map((line: any) => {
                    const remaining = line.quantity_ordered - line.quantity_delivered
                    const currentQty = lineQuantities[line.sales_order_line_id] || 0
                    return (
                      <TableRow key={line.sales_order_line_id}>
                        <TableCell className="font-medium">{line.product_name || '-'}</TableCell>
                        <TableCell>{line.description}</TableCell>
                        <TableCell className="text-right">{line.quantity_ordered}</TableCell>
                        <TableCell className="text-right">{line.quantity_delivered}</TableCell>
                        <TableCell className="text-right font-medium">{remaining}</TableCell>
                        <TableCell className="text-right">
                          <Input
                            type="number"
                            min="0"
                            max={remaining}
                            step="0.01"
                            value={currentQty || ''}
                            onChange={(e) => updateLineQty(line.sales_order_line_id, parseFloat(e.target.value) || 0)}
                            className="w-24 text-right"
                            placeholder="0"
                          />
                        </TableCell>
                      </TableRow>
                    )
                  })}
                </TableBody>
              </Table>

              <div className="flex justify-end mt-4">
                <Button onClick={handleSubmit} disabled={createMutation.isPending}>
                  {createMutation.isPending ? 'Creating...' : 'Create Delivery Order'}
                </Button>
              </div>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  )
}

export default DeliveryOrderCreatePage
