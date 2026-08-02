import { useState, useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'

interface LineItem {
  product_id: number | null
  product_name: string
  sku: string
  model_number: string
  description: string
  product_notes: string
  patient_name: string
  quantity: number
  unit: string
  unit_price: number | null
  lot_number: string
}

export function DeliveryOrderCreatePage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const preselectedSoId = searchParams.get('sales_order_id')
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const [mode, setMode] = useState<'linked' | 'standalone'>(preselectedSoId ? 'linked' : 'standalone')
  const [salesOrderId, setSalesOrderId] = useState<string>(preselectedSoId || '')
  const [customerId, setCustomerId] = useState<string>('')
  const [warehouseId, setWarehouseId] = useState<string>('')
  const [deliveryDate, setDeliveryDate] = useState(new Date().toISOString().split('T')[0])
  const [expectedDate, setExpectedDate] = useState('')
  const [carrier, setCarrier] = useState('')
  const [trackingNumber, setTrackingNumber] = useState('')
  const [shippingAddress, setShippingAddress] = useState('')
  const [poNumber, setPoNumber] = useState('')
  const [salesperson, setSalesperson] = useState('')
  const [attentionTo, setAttentionTo] = useState('')
  const [notes, setNotes] = useState('')
  const [lineQuantities, setLineQuantities] = useState<Record<number, number>>({})
  const [lineLotNumbers, setLineLotNumbers] = useState<Record<number, string>>({})
  const [linePatientNames, setLinePatientNames] = useState<Record<number, string>>({})

  // Standalone mode line items
  const [standaloneLines, setStandaloneLines] = useState<LineItem[]>([
    { product_id: null, product_name: '', sku: '', model_number: '', description: '', product_notes: '', patient_name: '', quantity: 1, unit: 'pcs', unit_price: null, lot_number: '' },
  ])

  // Fetch customers for standalone mode
  const { data: customersData } = useQuery({
    queryKey: ['customers-list'],
    queryFn: async () => {
      const res = await api.get('/customers', { params: { per_page: 200 } })
      return res.data?.data || res.data || []
    },
    enabled: mode === 'standalone',
  })

  // Fetch warehouses
  const { data: warehousesData } = useQuery({
    queryKey: ['warehouses-list'],
    queryFn: async () => {
      const res = await api.get('/warehouses', { params: { per_page: 100 } })
      return res.data?.data || res.data || []
    },
  })

  // Fetch products for standalone mode
  const { data: productsData } = useQuery({
    queryKey: ['products-list'],
    queryFn: async () => {
      const res = await api.get('/products', { params: { per_page: 500 } })
      return res.data?.data || res.data || []
    },
    enabled: mode === 'standalone',
  })

  const { data: soData, isLoading: soLoading, refetch: fetchSO } = useQuery({
    queryKey: ['so-deliveries', salesOrderId],
    queryFn: async () => {
      const response = await api.get(`/sales/orders/${salesOrderId}/deliveries`)
      return response.data
    },
    enabled: !!salesOrderId && mode === 'linked',
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

  // Pre-select default warehouse
  useEffect(() => {
    if (warehousesData?.length && !warehouseId) {
      const defaultWh = warehousesData.find((w: any) => w.is_default)
      if (defaultWh) {
        setWarehouseId(defaultWh.id.toString())
      } else if (warehousesData.length === 1) {
        setWarehouseId(warehousesData[0].id.toString())
      }
    }
  }, [warehousesData, warehouseId])

  // Auto-fill shipping address when customer is selected
  const handleCustomerChange = (val: string) => {
    setCustomerId(val)
    const customer = customers.find((c: any) => c.id === parseInt(val))
    if (customer) {
      const addr = customer.shipping_address || customer.billing_address || ''
      setShippingAddress(addr)
    }
  }

  const handleSubmit = () => {
    if (mode === 'linked') {
      handleSubmitLinked()
    } else {
      handleSubmitStandalone()
    }
  }

  const handleSubmitLinked = () => {
    if (!salesOrderId) {
      toast({ variant: 'destructive', title: 'Please enter a Sales Order ID' })
      return
    }

    if (!warehouseId) {
      toast({ variant: 'destructive', title: 'Please select a warehouse' })
      return
    }

    const lines = (soData?.lines || [])
      .filter((line: any) => (lineQuantities[line.sales_order_line_id] || 0) > 0)
      .map((line: any) => ({
        sales_order_line_id: line.sales_order_line_id,
        quantity_delivered: lineQuantities[line.sales_order_line_id] || 0,
        lot_number: lineLotNumbers[line.sales_order_line_id] || undefined,
        patient_name: linePatientNames[line.sales_order_line_id] || undefined,
      }))

    if (lines.length === 0) {
      toast({ variant: 'destructive', title: 'Please set quantity for at least one line' })
      return
    }

    createMutation.mutate({
      sales_order_id: parseInt(salesOrderId),
      warehouse_id: warehouseId ? parseInt(warehouseId) : undefined,
      delivery_date: deliveryDate || undefined,
      expected_date: expectedDate || undefined,
      carrier: carrier || undefined,
      tracking_number: trackingNumber || undefined,
      shipping_address: shippingAddress || undefined,
      po_number: poNumber || undefined,
      salesperson: salesperson || undefined,
      attention_to: attentionTo || undefined,
      notes: notes || undefined,
      lines,
    })
  }

  const handleSubmitStandalone = () => {
    if (!customerId) {
      toast({ variant: 'destructive', title: 'Please select a customer' })
      return
    }

    if (!warehouseId) {
      toast({ variant: 'destructive', title: 'Warehouse is required' })
      return
    }

    const validLines = standaloneLines.filter((l) => l.quantity > 0)
    if (validLines.length === 0) {
      toast({ variant: 'destructive', title: 'Please add at least one line with quantity > 0' })
      return
    }

    const linesWithoutProduct = validLines.filter((l) => !l.product_id)
    if (linesWithoutProduct.length > 0) {
      toast({ variant: 'destructive', title: 'Each line must have a product selected' })
      return
    }

    createMutation.mutate({
      customer_id: parseInt(customerId),
      warehouse_id: parseInt(warehouseId),
      delivery_date: deliveryDate || undefined,
      expected_date: expectedDate || undefined,
      carrier: carrier || undefined,
      tracking_number: trackingNumber || undefined,
      shipping_address: shippingAddress || undefined,
      po_number: poNumber || undefined,
      salesperson: salesperson || undefined,
      attention_to: attentionTo || undefined,
      notes: notes || undefined,
      lines: validLines.map((l) => ({
        product_id: l.product_id,
        model_number: l.model_number || null,
        description: l.description,
        product_notes: l.product_notes || null,
        patient_name: l.patient_name || undefined,
        quantity_delivered: l.quantity,
        unit: l.unit,
        unit_price: l.unit_price || undefined,
        lot_number: l.lot_number || undefined,
      })),
    })
  }

  const updateLineQty = (lineId: number, qty: number) => {
    setLineQuantities((prev) => ({ ...prev, [lineId]: qty }))
  }

  const updateLineLot = (lineId: number, lot: string) => {
    setLineLotNumbers((prev) => ({ ...prev, [lineId]: lot }))
  }

  const updateLinePatientName = (lineId: number, patientName: string) => {
    setLinePatientNames((prev) => ({ ...prev, [lineId]: patientName }))
  }

  const addStandaloneLine = () => {
    setStandaloneLines((prev) => [...prev, { product_id: null, product_name: '', sku: '', model_number: '', description: '', product_notes: '', patient_name: '', quantity: 1, unit: 'pcs', unit_price: null, lot_number: '' }])
  }

  const removeStandaloneLine = (index: number) => {
    setStandaloneLines((prev) => prev.filter((_, i) => i !== index))
  }

  const updateStandaloneLine = (index: number, field: keyof LineItem, value: any) => {
    setStandaloneLines((prev) => prev.map((line, i) => (i === index ? { ...line, [field]: value } : line)))
  }

  const customers = Array.isArray(customersData) ? customersData : []
  const products = Array.isArray(productsData) ? productsData : []
  const warehouses = Array.isArray(warehousesData) ? warehousesData : []

  return (
    <div className="space-y-6 p-6">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="sm" onClick={() => navigate('/delivery-orders')}>
          <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
          Back
        </Button>
        <div>
          <h1 className="text-3xl font-bold">Create Delivery Order</h1>
          <p className="text-muted-foreground mt-1">
            {mode === 'linked' ? 'Dispatch items from a confirmed sales order.' : 'Create a standalone delivery.'}
          </p>
        </div>
      </div>

      {/* Mode Toggle */}
      {!preselectedSoId && (
        <Card>
          <CardContent className="pt-6">
            <div className="flex gap-4">
              <Button variant={mode === 'standalone' ? 'default' : 'outline'} onClick={() => setMode('standalone')}>
                <Icon icon="solar:package-bold" className="h-4 w-4 mr-2" />
                Standalone Delivery
              </Button>
              <Button variant={mode === 'linked' ? 'default' : 'outline'} onClick={() => setMode('linked')}>
                <Icon icon="solar:document-text-bold" className="h-4 w-4 mr-2" />
                From Sales Order
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Linked Mode: SO Input */}
      {mode === 'linked' && (
        <Card>
          <CardHeader>
            <CardTitle>Sales Order</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex gap-3 items-end">
              <div className="flex-1">
                <Label>Sales Order ID</Label>
                <Input
                  type="number"
                  value={salesOrderId}
                  onChange={(e) => setSalesOrderId(e.target.value)}
                  placeholder="Enter sales order ID"
                  disabled={!!preselectedSoId}
                />
              </div>
              <Button onClick={() => fetchSO()} disabled={!salesOrderId || soLoading}>
                {soLoading ? 'Loading...' : 'Load Order'}
              </Button>
            </div>
            {soData && (
              <div className="p-3 rounded-lg bg-blue-50 border border-blue-200 text-sm">
                <span className="font-medium">{soData.sales_order?.sales_order_number}</span>
                {' — '}
                <span className="text-muted-foreground">Status: {soData.sales_order?.status}</span>
              </div>
            )}
            <div>
              <Label>Warehouse <span className="text-destructive">*</span></Label>
              <Select value={warehouseId} onValueChange={setWarehouseId}>
                <SelectTrigger>
                  <SelectValue placeholder="Select warehouse" />
                </SelectTrigger>
                <SelectContent>
                  {warehouses.map((w: any) => (
                    <SelectItem key={w.id} value={w.id.toString()}>
                      {w.code ? `${w.code} — ` : ''}{w.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Standalone Mode: Customer + Warehouse */}
      {mode === 'standalone' && (
        <Card>
          <CardHeader>
            <CardTitle>Customer & Warehouse</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label>Customer <span className="text-destructive">*</span></Label>
                <Select value={customerId} onValueChange={handleCustomerChange}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select customer" />
                  </SelectTrigger>
                  <SelectContent>
                    {customers.map((c: any) => (
                      <SelectItem key={c.id} value={c.id.toString()}>
                        {c.code ? `${c.code} — ` : ''}{c.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label>Warehouse <span className="text-destructive">*</span></Label>
                <Select value={warehouseId} onValueChange={setWarehouseId}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select warehouse" />
                  </SelectTrigger>
                  <SelectContent>
                    {warehouses.map((w: any) => (
                      <SelectItem key={w.id} value={w.id.toString()}>
                        {w.code ? `${w.code} — ` : ''}{w.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Delivery Details */}
      <Card>
        <CardHeader>
          <CardTitle>Delivery Details</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
              <Label>Delivery Date</Label>
              <Input type="date" value={deliveryDate} onChange={(e) => setDeliveryDate(e.target.value)} />
            </div>
            <div>
              <Label>Expected Date</Label>
              <Input type="date" value={expectedDate} onChange={(e) => setExpectedDate(e.target.value)} />
            </div>
            <div>
              <Label>Carrier</Label>
              <Input value={carrier} onChange={(e) => setCarrier(e.target.value)} placeholder="e.g. DHL, FedEx" />
            </div>
            <div>
              <Label>Tracking #</Label>
              <Input value={trackingNumber} onChange={(e) => setTrackingNumber(e.target.value)} placeholder="Tracking number" />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label>PO Number</Label>
              <Input value={poNumber} onChange={(e) => setPoNumber(e.target.value)} placeholder="Purchase order number" />
            </div>
            <div>
              <Label>Salesperson</Label>
              <Input value={salesperson} onChange={(e) => setSalesperson(e.target.value)} placeholder="Salesperson name" />
            </div>
          </div>
          <div>
            <Label>Attention To</Label>
            <Input value={attentionTo} onChange={(e) => setAttentionTo(e.target.value)} placeholder="Attention to (e.g. Warehouse Manager)" />
          </div>
          <div>
            <Label>Shipping Address</Label>
            <textarea
              value={shippingAddress}
              onChange={(e) => setShippingAddress(e.target.value)}
              placeholder="Shipping address (auto-filled from customer, editable)"
              className="w-full px-3 py-2 border rounded-md text-sm min-h-[60px]"
            />
            {shippingAddress && (
              <p className="text-xs text-muted-foreground mt-1">Auto-filled from customer record. Edit if needed.</p>
            )}
          </div>
          <div>
            <Label>Notes</Label>
            <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional notes" />
          </div>
        </CardContent>
      </Card>

      {/* Linked Mode: SO Lines */}
      {mode === 'linked' && soData && (
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
                  <TableHead>Patient Name</TableHead>
                  <TableHead>Lot Number</TableHead>
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
                      <TableCell>
                        <Input
                          type="text"
                          value={linePatientNames[line.sales_order_line_id] || ''}
                          onChange={(e) => updateLinePatientName(line.sales_order_line_id, e.target.value)}
                          className="w-40"
                          placeholder="Patient name"
                        />
                      </TableCell>
                      <TableCell>
                        <Input
                          type="text"
                          value={lineLotNumbers[line.sales_order_line_id] || ''}
                          onChange={(e) => updateLineLot(line.sales_order_line_id, e.target.value)}
                          className="w-32"
                          placeholder="Lot #"
                        />
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {/* Standalone Mode: Line Items */}
      {mode === 'standalone' && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center justify-between">
              Line Items
              <Button variant="outline" size="sm" onClick={addStandaloneLine}>
                <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-1" />
                Add Line
              </Button>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-[180px]">Product</TableHead>
                  <TableHead className="w-[100px]">SKU</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead className="w-[100px]">Model #</TableHead>
                  <TableHead className="w-[180px]">Product Notes</TableHead>
                  <TableHead className="w-[180px]">Patient Name</TableHead>
                  <TableHead className="text-right w-[100px]">Quantity</TableHead>
                  <TableHead className="w-[80px]">Unit</TableHead>
                  <TableHead className="text-right w-[120px]">Unit Price</TableHead>
                  <TableHead className="w-[100px]">Lot Number</TableHead>
                  <TableHead className="w-[50px]"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {standaloneLines.map((line, idx) => (
                  <TableRow key={idx}>
                    <TableCell>
                      <ProductSearchCombobox
                        value={line.product_id?.toString() || ''}
                        onValueChange={(val, product) => {
                          updateStandaloneLine(idx, 'product_id', val ? parseInt(val) : null)
                          updateStandaloneLine(idx, 'product_name', product?.name || '')
                          if (!line.description && product) updateStandaloneLine(idx, 'description', product.name || '')
                          if (product) {
                            updateStandaloneLine(idx, 'sku', (product as any).sku || '')
                            updateStandaloneLine(idx, 'model_number', (product as any).manufacturer_part_number || '')
                            if (!line.unit_price) updateStandaloneLine(idx, 'unit_price', parseFloat((product as any).price || (product as any).selling_price || '0') || null)
                          }
                        }}
                        placeholder="Search product..."
                      />
                    </TableCell>
                    <TableCell>
                      <Input
                        value={line.sku}
                        readOnly
                        className="bg-gray-50 dark:bg-gray-900 text-slate-500"
                        placeholder="SKU"
                      />
                    </TableCell>
                    <TableCell>
                      <Input
                        value={line.description}
                        onChange={(e) => updateStandaloneLine(idx, 'description', e.target.value)}
                        placeholder="Description"
                      />
                    </TableCell>
                    <TableCell>
                      <Input
                        value={line.model_number}
                        readOnly
                        className="bg-gray-50 dark:bg-gray-900 text-slate-500"
                        placeholder="Model #"
                      />
                    </TableCell>
                    <TableCell>
                      <Input
                        value={line.product_notes}
                        onChange={(e) => updateStandaloneLine(idx, 'product_notes', e.target.value)}
                        placeholder="Product notes"
                      />
                    </TableCell>
                    <TableCell>
                      <Input
                        value={line.patient_name}
                        onChange={(e) => updateStandaloneLine(idx, 'patient_name', e.target.value)}
                        placeholder="Patient name"
                      />
                    </TableCell>
                    <TableCell className="text-right">
                      <Input
                        type="number"
                        min="0.01"
                        step="0.01"
                        value={line.quantity || ''}
                        onChange={(e) => updateStandaloneLine(idx, 'quantity', parseFloat(e.target.value) || 0)}
                        className="w-24 text-right"
                      />
                    </TableCell>
                    <TableCell>
                      <Input
                        value={line.unit}
                        onChange={(e) => updateStandaloneLine(idx, 'unit', e.target.value)}
                        className="w-20"
                      />
                    </TableCell>
                    <TableCell className="text-right">
                      <Input
                        type="number"
                        min="0"
                        step="0.01"
                        value={line.unit_price ?? ''}
                        onChange={(e) => updateStandaloneLine(idx, 'unit_price', parseFloat(e.target.value) || null)}
                        className="w-28 text-right"
                        placeholder="0.00"
                      />
                    </TableCell>
                    <TableCell>
                      <Input
                        value={line.lot_number}
                        onChange={(e) => updateStandaloneLine(idx, 'lot_number', e.target.value)}
                        className="w-28"
                        placeholder="Lot #"
                      />
                    </TableCell>
                    <TableCell>
                      {standaloneLines.length > 1 && (
                        <Button variant="ghost" size="sm" onClick={() => removeStandaloneLine(idx)}>
                          <Icon icon="solar:trash-bin-minimalistic-bold" className="h-4 w-4 text-destructive" />
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {/* Submit */}
      <div className="flex justify-end">
        <Button onClick={handleSubmit} disabled={createMutation.isPending} size="lg">
          {createMutation.isPending ? 'Creating...' : 'Create Delivery Order'}
        </Button>
      </div>
    </div>
  )
}

export default DeliveryOrderCreatePage
