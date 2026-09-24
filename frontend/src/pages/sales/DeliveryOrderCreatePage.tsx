import { useState, useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { TableCell, TableRow } from '@/components/ui/table'
import { LineItemsTable } from '@/components/sales/LineItemsTable'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { getProductLineDescription, ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DeliveryOrderColumnVisibility } from '@/components/sales/DeliveryOrderColumnVisibility'
import { ProductCatalogDialog } from '@/components/sales/ProductCatalogDialog'
import { DocumentTextLineRow, getDocumentSectionTotal, reorderDocumentLines } from '@/components/sales/DocumentTextLineRow'
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox'
import { ProductTrackingSelector, type TrackingSelection } from '@/components/inventory/ProductTrackingSelector'

interface LineItem {
  line_type?: 'product' | 'note' | 'section'
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
  serial_number?: string
  product_batch_id?: number | null
  product_serial_id?: number | null
  tracking_method?: string
  expiry_date: string
}

export function DeliveryOrderCreatePage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const preselectedSoId = searchParams.get('sales_order_id')
  const preselectedQuotationId = searchParams.get('quotation_id')
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const [mode, setMode] = useState<'linked' | 'standalone'>(preselectedSoId ? 'linked' : 'standalone')
  const [salesOrderId, setSalesOrderId] = useState<string>(preselectedSoId || '')
  const [customerId, setCustomerId] = useState<string>('')
  const [warehouseId, setWarehouseId] = useState<string>('')
  const [deliveryDate, setDeliveryDate] = useState(new Date().toISOString().split('T')[0])
  const [expectedDate, setExpectedDate] = useState('')
  const [paymentTerms, setPaymentTerms] = useState('')
  const [invoiceId, setInvoiceId] = useState('')
  const [quotationId, setQuotationId] = useState(preselectedQuotationId || '')
  const [carrier, setCarrier] = useState('')
  const [trackingNumber, setTrackingNumber] = useState('')
  const [shippingAddress, setShippingAddress] = useState('')
  const [poNumber, setPoNumber] = useState('')
  const [salesperson, setSalesperson] = useState('')
  const [attentionTo, setAttentionTo] = useState('')
  const [notes, setNotes] = useState('')
  const [lineQuantities, setLineQuantities] = useState<Record<number, number>>({})
  const [lineLotNumbers, setLineLotNumbers] = useState<Record<number, string>>({})
  const [lineExpiryDates, setLineExpiryDates] = useState<Record<number, string>>({})
  const [lineTrackingSelections, setLineTrackingSelections] = useState<Record<number, TrackingSelection>>({})
  const [linePatientNames, setLinePatientNames] = useState<Record<number, string>>({})
  const [lineProductNotes, setLineProductNotes] = useState<Record<number, string>>({})

  // Standalone mode line items
  const [standaloneLines, setStandaloneLines] = useState<LineItem[]>([
    { product_id: null, product_name: '', sku: '', model_number: '', description: '', product_notes: '', patient_name: '', quantity: 1, unit: 'pcs', unit_price: null, lot_number: '', expiry_date: '' },
  ])
  const [draggedLineIndex, setDraggedLineIndex] = useState<number | null>(null)

  const { data: selectedCustomer } = useQuery({
    queryKey: ['customer-detail', customerId],
    queryFn: async () => {
      const res = await api.get(`/customers/${customerId}`)
      return res.data?.data || res.data
    },
    enabled: mode === 'standalone' && !!customerId,
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

  const referenceCustomerId = mode === 'standalone'
    ? customerId
    : String(soData?.sales_order?.customer_id || soData?.customer?.id || '')

  const { data: invoicesData } = useQuery({
    queryKey: ['delivery-order-invoices', referenceCustomerId],
    queryFn: async () => {
      const res = await api.get('/invoices', { params: { per_page: 200, customer_id: referenceCustomerId || undefined } })
      return res.data?.data || []
    },
  })

  const { data: quotationsData } = useQuery({
    queryKey: ['delivery-order-quotations', referenceCustomerId],
    queryFn: async () => {
      const res = await api.get('/quotations', { params: { per_page: 200, customer_id: referenceCustomerId || undefined } })
      return res.data?.data || []
    },
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
  const handleCustomerChange = (val: string) => setCustomerId(val)

  useEffect(() => {
    if (!selectedCustomer) return
    setShippingAddress(selectedCustomer.shipping_address || selectedCustomer.billing_address || '')
  }, [selectedCustomer])

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

    const hasDeliverableProduct = (soData?.lines || []).some((line: any) => {
      const lineType = line.line_type || 'product'
      return lineType === 'product' && (lineQuantities[line.sales_order_line_id] || 0) > 0
    })

    if (!hasDeliverableProduct) {
      toast({
        variant: 'destructive',
        title: 'Enter a Deliver Now quantity',
        description: 'Set Deliver Now greater than 0 for at least one product before creating the delivery order.',
      })
      return
    }

    const lines = (soData?.lines || [])
      .filter((line: any) => (line.line_type === 'note' || line.line_type === 'section') || (lineQuantities[line.sales_order_line_id] || 0) > 0)
      .map((line: any) => ({
        line_type: line.line_type || 'product',
        sequence: line.sequence || 0,
        sales_order_line_id: line.sales_order_line_id,
        quantity_delivered: line.line_type === 'note' || line.line_type === 'section' ? 0 : (lineQuantities[line.sales_order_line_id] || 0),
        description: line.description || undefined,
        unit: line.unit || undefined,
        unit_price: line.unit_price ?? undefined,
        lot_number: lineLotNumbers[line.sales_order_line_id] || undefined,
        serial_number: lineTrackingSelections[line.sales_order_line_id]?.serial_number || undefined,
        product_batch_id: lineTrackingSelections[line.sales_order_line_id]?.product_batch_id || undefined,
        product_serial_id: lineTrackingSelections[line.sales_order_line_id]?.product_serial_id || undefined,
        expiry_date: lineExpiryDates[line.sales_order_line_id] || undefined,
        patient_name: linePatientNames[line.sales_order_line_id] || undefined,
        product_notes: lineProductNotes[line.sales_order_line_id] ?? line.product_notes ?? undefined,
      }))

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
      payment_terms: paymentTerms || undefined,
      invoice_id: invoiceId ? parseInt(invoiceId) : undefined,
      quotation_id: quotationId ? parseInt(quotationId) : undefined,
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

    const validLines = standaloneLines.filter((l) => l.line_type === 'note' || l.line_type === 'section' || l.quantity > 0)
    if (validLines.length === 0) {
      toast({ variant: 'destructive', title: 'Please add at least one line with quantity > 0' })
      return
    }

    const missingProductIndex = standaloneLines.findIndex((line) => (line.line_type ?? 'product') === 'product' && !line.product_id)
    if (missingProductIndex >= 0) {
      toast({
        variant: 'destructive',
        title: `Product required on line ${missingProductIndex + 1}`,
        description: 'Select a product before saving.',
      })
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
      payment_terms: paymentTerms || undefined,
      invoice_id: invoiceId ? parseInt(invoiceId) : undefined,
      quotation_id: quotationId ? parseInt(quotationId) : undefined,
      lines: validLines.map((l) => ({
        line_type: l.line_type || 'product',
        sequence: validLines.indexOf(l),
        product_id: l.product_id,
        model_number: l.model_number || null,
        description: l.description,
        product_notes: l.product_notes || null,
        patient_name: l.patient_name || undefined,
        quantity_delivered: l.quantity,
        unit: l.unit,
        unit_price: l.unit_price || undefined,
        lot_number: l.lot_number || undefined,
        serial_number: l.serial_number || undefined,
        product_batch_id: l.product_batch_id || undefined,
        product_serial_id: l.product_serial_id || undefined,
        expiry_date: l.expiry_date || undefined,
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
    setStandaloneLines((prev) => [...prev, { product_id: null, product_name: '', sku: '', model_number: '', description: '', product_notes: '', patient_name: '', quantity: 1, unit: 'pcs', unit_price: null, lot_number: '', expiry_date: '' }])
  }
  const addStandaloneTextLine = (line_type: 'note' | 'section') => setStandaloneLines((prev) => [...prev, { line_type, product_id: null, product_name: '', sku: '', model_number: '', description: '', product_notes: '', patient_name: '', quantity: 0, unit: '', unit_price: null, lot_number: '', expiry_date: '' }])
  const addCatalogProducts = (selected: Array<{ product: any; quantity: number }>) => setStandaloneLines((prev) => [...prev, ...selected.map(({ product, quantity }) => ({ line_type: 'product' as const, product_id: product.id, product_name: product.name || '', sku: product.sku || '', model_number: product.manufacturer_part_number || '', description: product.name || '', product_notes: '', patient_name: '', quantity, unit: product.unit || 'pcs', unit_price: Number(product.price || 0), lot_number: '', expiry_date: '' }))])

  const removeStandaloneLine = (index: number) => {
    setStandaloneLines((prev) => prev.filter((_, i) => i !== index))
  }

  const updateStandaloneLine = (index: number, field: keyof LineItem, value: any) => {
    setStandaloneLines((prev) => prev.map((line, i) => (i === index ? { ...line, [field]: value } : line)))
  }

  const products = Array.isArray(productsData) ? productsData : []
  const warehouses = Array.isArray(warehousesData) ? warehousesData : []
  const invoices = Array.isArray(invoicesData) ? invoicesData : []
  const quotations = Array.isArray(quotationsData) ? quotationsData : []

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
            <CardTitle>{preselectedSoId ? 'Warehouse' : 'Sales Order'}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {!preselectedSoId && (
              <>
                <div className="flex gap-3 items-end">
                  <div className="flex-1">
                    <Label>Sales Order ID</Label>
                    <Input
                      type="number"
                      value={salesOrderId}
                      onChange={(e) => setSalesOrderId(e.target.value)}
                      placeholder="Enter sales order ID"
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
              </>
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
                <CustomerSearchCombobox
                  value={customerId}
                  onValueChange={handleCustomerChange}
                  placeholder="Search customer name or code..."
                />
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
              <Label>Due Date</Label>
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
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <Label>Invoice No.</Label>
              <Select value={invoiceId || 'none'} onValueChange={(value) => setInvoiceId(value === 'none' ? '' : value)}>
                <SelectTrigger><SelectValue placeholder="Select invoice" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">None</SelectItem>
                  {invoices.map((invoice: any) => (
                    <SelectItem key={invoice.id} value={invoice.id.toString()}>
                      {invoice.invoice_number}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label>Quotation No.</Label>
              <Select value={quotationId || 'none'} onValueChange={(value) => setQuotationId(value === 'none' ? '' : value)}>
                <SelectTrigger><SelectValue placeholder="Select quotation" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">None</SelectItem>
                  {quotations.map((quotation: any) => (
                    <SelectItem key={quotation.id} value={quotation.id.toString()}>
                      {quotation.quotation_number}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label>Payment Terms</Label>
              <Input value={paymentTerms} onChange={(e) => setPaymentTerms(e.target.value)} placeholder="e.g. Net 30" />
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
            <DeliveryOrderColumnVisibility tableClass="do-create-linked-columns" columns={[
              'Product', 'Description', 'Product Notes', 'Unit Price', 'Ordered', 'Already Delivered', 'Remaining', 'Deliver Now', 'Patient Name', 'Lot Number', 'Expiry Date',
            ].map((label, index) => ({ label, index: index + 1 }))}>
            <LineItemsTable
              variant="shadcn"
              columns={[
                { key: 'product', label: 'Product', minWidth: 'min-w-[9rem]' },
                { key: 'description', label: 'Description', minWidth: 'min-w-[18rem]' },
                { key: 'product_notes', label: 'Product Notes', minWidth: 'min-w-[10rem]' },
                { key: 'unit_price', label: 'Unit Price', align: 'right', minWidth: 'min-w-[7rem]' },
                { key: 'ordered', label: 'Ordered', align: 'right', minWidth: 'min-w-[6rem]' },
                { key: 'already_delivered', label: 'Already Delivered', align: 'right', minWidth: 'min-w-[8rem]' },
                { key: 'remaining', label: 'Remaining', align: 'right', minWidth: 'min-w-[6rem]' },
                { key: 'deliver_now', label: 'Deliver Now', align: 'right', minWidth: 'min-w-[7rem]' },
                { key: 'patient_name', label: 'Patient Name', minWidth: 'min-w-[10rem]' },
                { key: 'lot_number', label: 'Lot Number', minWidth: 'min-w-[7rem]' },
                { key: 'expiry_date', label: 'Expiry Date', minWidth: 'min-w-[8rem]' },
              ]}
            >
                {soData.lines?.map((line: any) => {
                  const lineType = line.line_type || 'product'
                  if (lineType === 'section' || lineType === 'note') {
                    return (
                      <TableRow key={line.sales_order_line_id}>
                        <TableCell
                          colSpan={11}
                          className={lineType === 'section'
                            ? 'bg-muted/60 px-4 py-3 font-semibold'
                            : 'bg-muted/30 px-4 py-3 italic text-muted-foreground'}
                        >
                          {line.description}
                        </TableCell>
                      </TableRow>
                    )
                  }
                  const remaining = line.quantity_ordered - line.quantity_delivered
                  const currentQty = lineQuantities[line.sales_order_line_id] || 0
                  return (
                    <TableRow key={line.sales_order_line_id}>
                      <TableCell className="font-medium">{line.product_name || '-'}</TableCell>
                      <TableCell className="min-w-[320px] whitespace-pre-wrap break-words align-top">{line.description || '-'}</TableCell>
                      <TableCell>
                        <Input
                          type="text"
                          value={lineProductNotes[line.sales_order_line_id] ?? line.product_notes ?? ''}
                          onChange={(e) => setLineProductNotes((prev) => ({ ...prev, [line.sales_order_line_id]: e.target.value }))}
                          className="w-44"
                          placeholder="Product notes"
                        />
                      </TableCell>
                      <TableCell className="text-right tabular-nums">{Number(line.unit_price || 0).toFixed(2)}</TableCell>
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
                        <ProductTrackingSelector
                          productId={line.product_id}
                          warehouseId={warehouseId}
                          trackingMethod={line.product?.tracking_method}
                          batchId={lineTrackingSelections[line.sales_order_line_id]?.product_batch_id}
                          serialId={lineTrackingSelections[line.sales_order_line_id]?.product_serial_id}
                          onChange={(selection) => {
                            setLineTrackingSelections((current) => ({ ...current, [line.sales_order_line_id]: selection }))
                            setLineLotNumbers((current) => ({ ...current, [line.sales_order_line_id]: selection.lot_number }))
                            setLineExpiryDates((current) => ({ ...current, [line.sales_order_line_id]: selection.expiry_date }))
                          }}
                        />
                      </TableCell>
                      <TableCell>
                        <Input type="date" value={lineExpiryDates[line.sales_order_line_id] || ''} readOnly className="w-36 bg-gray-50 dark:bg-gray-900" />
                        {lineTrackingSelections[line.sales_order_line_id]?.serial_number ? <div className="mt-1 text-xs text-muted-foreground">Serial: {lineTrackingSelections[line.sales_order_line_id].serial_number}</div> : null}
                      </TableCell>
                    </TableRow>
                  )
                })}
            </LineItemsTable>
            </DeliveryOrderColumnVisibility>
          </CardContent>
        </Card>
      )}

      {/* Standalone Mode: Line Items */}
      {mode === 'standalone' && (
        <Card>
          <CardHeader>
            <CardTitle>Line Items</CardTitle>
          </CardHeader>
          <CardContent>
            <DeliveryOrderColumnVisibility tableClass="do-create-standalone-columns" columns={[
              'Product', 'SKU', 'Description', 'Model #', 'Product Notes', 'Patient Name', 'Quantity', 'Unit', 'Unit Price', 'Lot Number', 'Expiry Date', 'Actions',
            ].map((label, index) => ({ label, index: index + 1 }))}>
            <LineItemsTable
              variant="shadcn"
              columns={[
                { key: 'product', label: 'Product', minWidth: 'min-w-[9rem]' },
                { key: 'sku', label: 'SKU', minWidth: 'min-w-[5.5rem]' },
                { key: 'description', label: 'Description', minWidth: 'min-w-[18rem]' },
                { key: 'model_number', label: 'Model #', minWidth: 'min-w-[6rem]' },
                { key: 'product_notes', label: 'Product Notes', minWidth: 'min-w-[10rem]' },
                { key: 'patient_name', label: 'Patient Name', minWidth: 'min-w-[10rem]' },
                { key: 'quantity', label: 'Quantity', align: 'right', minWidth: 'min-w-[6rem]' },
                { key: 'unit', label: 'Unit', minWidth: 'min-w-[5rem]' },
                { key: 'unit_price', label: 'Unit Price', align: 'right', minWidth: 'min-w-[7rem]' },
                { key: 'lot_number', label: 'Lot Number', minWidth: 'min-w-[6rem]' },
                { key: 'expiry_date', label: 'Expiry Date', minWidth: 'min-w-[8rem]' },
                { key: 'actions', minWidth: 'min-w-[3rem]' },
              ]}
            >
                {standaloneLines.map((line, idx) => (
                  <TableRow key={idx} draggable={(line.line_type ?? 'product') === 'product'} onDragStart={() => setDraggedLineIndex(idx)} onDragOver={(event) => event.preventDefault()} onDrop={() => { if (draggedLineIndex !== null && draggedLineIndex !== idx) setStandaloneLines(reorderDocumentLines(standaloneLines, draggedLineIndex, idx)); setDraggedLineIndex(null); }}>
                    {line.line_type === 'note' || line.line_type === 'section' ? <DocumentTextLineRow type={line.line_type} text={line.description} colSpan={12} total={line.line_type === 'section' ? getDocumentSectionTotal(standaloneLines, idx, (item) => item.quantity * Number(item.unit_price || 0)).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : undefined} onChange={(text) => updateStandaloneLine(idx, 'description', text)} onDelete={() => removeStandaloneLine(idx)} onDragStart={() => setDraggedLineIndex(idx)} onDrop={() => { if (draggedLineIndex !== null) setStandaloneLines(reorderDocumentLines(standaloneLines, draggedLineIndex, idx)); setDraggedLineIndex(null); }} /> : <>
                    <TableCell>
                      <ProductSearchCombobox
                        value={line.product_id?.toString() || ''}
                        onValueChange={(val, product) => {
                          updateStandaloneLine(idx, 'product_id', val ? parseInt(val) : null)
                          updateStandaloneLine(idx, 'product_name', product?.name || '')
                          if (!line.description && product) updateStandaloneLine(idx, 'description', getProductLineDescription(product))
                          if (product) {
                            updateStandaloneLine(idx, 'sku', (product as any).sku || '')
                            updateStandaloneLine(idx, 'model_number', (product as any).manufacturer_part_number || '')
                            if (!line.unit_price) updateStandaloneLine(idx, 'unit_price', parseFloat((product as any).price || (product as any).selling_price || '0') || null)
                            updateStandaloneLine(idx, 'tracking_method', (product as any).tracking_method || 'none')
                            updateStandaloneLine(idx, 'product_batch_id', null)
                            updateStandaloneLine(idx, 'product_serial_id', null)
                            updateStandaloneLine(idx, 'lot_number', '')
                            updateStandaloneLine(idx, 'serial_number', '')
                            updateStandaloneLine(idx, 'expiry_date', '')
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
                    <TableCell className="min-w-[320px] align-top">
                      <textarea
                        value={line.description}
                        onChange={(e) => updateStandaloneLine(idx, 'description', e.target.value)}
                        rows={2}
                        className="w-full whitespace-pre-wrap break-words rounded border border-gray-300 bg-white px-2 py-1 text-sm resize-y dark:border-gray-600 dark:bg-gray-800"
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
                        value={line.quantity ?? ''}
                        onChange={(e) => {
                          // HR71-08: keep literal intent — empty while typing,
                          // no silent coercion to 0.
                          const raw = e.target.value;
                          if (raw === '') { updateStandaloneLine(idx, 'quantity', ''); return; }
                          const parsed = parseFloat(raw);
                          if (!Number.isNaN(parsed) && parsed >= 0) updateStandaloneLine(idx, 'quantity', parsed);
                        }}
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
                      <ProductTrackingSelector
                        productId={line.product_id}
                        warehouseId={warehouseId}
                        trackingMethod={line.tracking_method}
                        batchId={line.product_batch_id}
                        serialId={line.product_serial_id}
                        onChange={(selection) => setStandaloneLines((current) => current.map((item, itemIndex) => itemIndex === idx ? { ...item, ...selection } : item))}
                      />
                    </TableCell>
                    <TableCell>
                      <Input type="date" value={line.expiry_date} readOnly className="w-36 bg-gray-50 dark:bg-gray-900" />
                      {line.serial_number ? <div className="mt-1 text-xs text-muted-foreground">Serial: {line.serial_number}</div> : null}
                    </TableCell>
                    <TableCell>
                      {standaloneLines.length > 1 && (
                        <Button variant="ghost" size="sm" onClick={() => removeStandaloneLine(idx)}>
                          <Icon icon="solar:trash-bin-minimalistic-bold" className="h-4 w-4 text-destructive" />
                        </Button>
                      )}
                    </TableCell>
                    </>}
                  </TableRow>
                ))}
            </LineItemsTable>
            </DeliveryOrderColumnVisibility>
            <div className="mt-3 flex flex-wrap gap-2">
              <ProductCatalogDialog products={productsData || []} onAdd={addCatalogProducts} />
              <Button variant="outline" size="sm" onClick={addStandaloneLine}>
                <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-1" />
                Add Line
              </Button>
              <Button variant="outline" size="sm" onClick={() => addStandaloneTextLine('section')}>Add Section</Button>
              <Button variant="outline" size="sm" onClick={() => addStandaloneTextLine('note')}>Add Note</Button>
            </div>
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
