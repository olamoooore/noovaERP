import { useState, useEffect } from 'react'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { TableCell, TableRow } from '@/components/ui/table'
import { LineItemsTable } from '@/components/sales/LineItemsTable'
import {
  LINE_CELL,
  LINE_CELL_BOX,
  LINE_INPUT_NUMERIC,
  LINE_PRODUCT_WRAP,
  LINE_TEXTAREA,
  LINE_VALUE,
  productLineColumns,
  ProductLineActions,
} from '@/components/sales/ProductLineTable'
import { cn, formatCurrency } from '@/lib/utils'
import { todayDateOnly } from '@/lib/dateOnly'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { getProductLineDescription, ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DeliveryOrderColumnVisibility, SalesLineColumnMenu } from '@/components/sales/DeliveryOrderColumnVisibility'
import { ProductCatalogDialog } from '@/components/sales/ProductCatalogDialog'
import { StockAvailabilityChip } from '@/components/sales/StockAvailabilityChip'
import { DocumentTextLineRow, getDocumentSectionTotal, reorderDocumentLines } from '@/components/sales/DocumentTextLineRow'
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox'
import { SearchableSelect } from '@/components/ui/SearchableSelect'
import { TagPicker } from '@/components/tags/TagPicker'
import { ProductTrackingSelector, type TrackingSelection } from '@/components/inventory/ProductTrackingSelector'
import { resolvedAddressText, resolvedCustomerAddress } from '@/lib/customerAddresses'

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
  // MRD pricelist-multicurrency FR-6.2/6.3: server-side list resolution.
  price_mode?: 'auto' | 'manual' | 'carried'
  lot_number: string
  serial_number?: string
  product_batch_id?: number | null
  product_serial_id?: number | null
  tracking_method?: string
  expiry_date: string
}

const DO_CREATE_LINKED_COLUMNS = [
  'Product', 'Description', 'Product Notes', 'Unit Price', 'Ordered', 'Already Delivered',
  'Remaining', 'Deliver Now', 'Patient Name', 'Lot Number', 'Expiry Date',
].map((label, index) => ({ label, index: index + 1 }))

const DO_CREATE_STANDALONE_COLUMNS = [
  '#', 'Product', 'SKU', 'Description', 'Model #', 'Product Notes', 'Lot Number',
  'Expiry Date', 'Qty', 'Available', 'Unit Price', 'Line Total', 'Actions',
].map((label, index) => ({ label, index: index + 1 }))

export function DeliveryOrderCreatePage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const preselectedSoId = searchParams.get('sales_order_id')
  const preselectedQuotationId = searchParams.get('quotation_id')
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const [mode, setMode] = useState<'linked' | 'standalone'>(preselectedSoId ? 'linked' : 'standalone')

  // Product Notes starts hidden on both tables, matching the previous default.
  const [hiddenLinkedColumns, setHiddenLinkedColumns] = useState<number[]>(
    () => DO_CREATE_LINKED_COLUMNS.filter((c) => c.label.toLowerCase() === 'product notes').map((c) => c.index)
  )
  const [hiddenStandaloneColumns, setHiddenStandaloneColumns] = useState<number[]>(
    () => DO_CREATE_STANDALONE_COLUMNS.filter((c) => c.label.toLowerCase() === 'product notes').map((c) => c.index)
  )
  const [tags, setTags] = useState<string[]>([])
  const [salesOrderId, setSalesOrderId] = useState<string>(preselectedSoId || '')
  const [customerId, setCustomerId] = useState<string>('')
  const [warehouseId, setWarehouseId] = useState<string>('')
  const [deliveryDate, setDeliveryDate] = useState(() => todayDateOnly())
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

  // Issue #540: tenant document defaults via the read-only endpoint every
  // DO creator can reach (GET /settings/sales is OWNER/ADMIN-only). Used for
  // display only — the server applies the approval policy, so the payload
  // never carries requires_approval.
  const { data: salesDefaults } = useQuery<{ delivery_order?: { default_requires_approval?: boolean } }>({
    queryKey: ['sales-settings', 'defaults'],
    queryFn: async () => {
      const res = await api.get('/settings/sales/defaults')
      return res.data?.data ?? res.data ?? {}
    },
  })

  const requiresApproval = salesDefaults?.delivery_order?.default_requires_approval ?? false

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

  // Issue #408: carry the sales order's commitment (promised) date onto the
  // delivery order form, and when the policy is "when all products are ready"
  // pre-fill every remaining line (partial deliveries are refused server-side).
  useEffect(() => {
    if (mode !== 'linked' || !soData?.sales_order) return

    const promise = soData.sales_order.commitment_date || soData.sales_order.expected_date
    if (promise) {
      setExpectedDate((current) => current || String(promise).split('T')[0])
    }

    if (soData.sales_order.shipping_policy === 'when_all_ready') {
      const prefill: Record<number, number> = {}
      for (const line of soData.lines || []) {
        if ((line.line_type || 'product') !== 'product') continue
        const remaining = Number(line.quantity_ordered) - Number(line.quantity_delivered)
        if (remaining > 0) prefill[line.sales_order_line_id] = remaining
      }
      setLineQuantities((current) =>
        Object.values(current).some((quantity) => Number(quantity) > 0) ? current : prefill,
      )
    }
  }, [soData, mode])

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
    // ADR-003B W2b: the customer's delivery address from the shared rule
    // (falls back to the bill-to); older text columns only if the API
    // response has no resolved addresses.
    const resolved = resolvedCustomerAddress(selectedCustomer, 'shipping')
    setShippingAddress(
      resolved !== undefined
        ? resolvedAddressText(resolved)
        : selectedCustomer.shipping_address || selectedCustomer.billing_address || '',
    )
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
      ...(tags.length ? { tags } : {}),
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
      ...(tags.length ? { tags } : {}),
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
        price_mode: l.price_mode || undefined,
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

  const updateLinePatientName = (lineId: number, patientName: string) => {
    setLinePatientNames((prev) => ({ ...prev, [lineId]: patientName }))
  }

  const addStandaloneLine = () => {
    setStandaloneLines((prev) => [...prev, { product_id: null, product_name: '', sku: '', model_number: '', description: '', product_notes: '', patient_name: '', quantity: 1, unit: 'pcs', unit_price: null, lot_number: '', expiry_date: '' }])
  }
  const addStandaloneTextLine = (line_type: 'note' | 'section') => setStandaloneLines((prev) => [...prev, { line_type, product_id: null, product_name: '', sku: '', model_number: '', description: '', product_notes: '', patient_name: '', quantity: 0, unit: '', unit_price: null, lot_number: '', expiry_date: '' }])
  const addCatalogProducts = (selected: Array<{ product: any; quantity: number }>) => setStandaloneLines((prev) => [...prev, ...selected.map(({ product, quantity }) => ({ line_type: 'product' as const, product_id: product.id, product_name: product.name || '', sku: product.sku || '', model_number: product.manufacturer_part_number || '', description: product.name || '', product_notes: '', patient_name: '', quantity, unit: product.unit || 'pcs', unit_price: Number(product.price || 0), price_mode: 'auto' as const, lot_number: '', expiry_date: '' }))])

  const removeStandaloneLine = (index: number) => {
    setStandaloneLines((prev) => prev.filter((_, i) => i !== index))
  }

  const updateStandaloneLine = (index: number, field: keyof LineItem, value: any) => {
    setStandaloneLines((prev) => prev.map((line, i) => (i === index ? { ...line, [field]: value } : line)))
  }

  const moveStandaloneLine = (index: number, direction: 'up' | 'down') => {
    setStandaloneLines((prev) => {
      const target = direction === 'up' ? index - 1 : index + 1
      if (target < 0 || target >= prev.length) return prev
      const next = [...prev]
      ;[next[index], next[target]] = [next[target], next[index]]
      return next
    })
  }

  const duplicateStandaloneLine = (index: number) => {
    setStandaloneLines((prev) => {
      const source = prev[index]
      const next = [...prev]
      next.splice(index + 1, 0, { ...source })
      return next
    })
  }

  const resetStandaloneLine = (index: number) => {
    setStandaloneLines((prev) =>
      prev.map((line, i) =>
        i === index
          ? { product_id: null, product_name: '', sku: '', model_number: '', description: '', product_notes: '', patient_name: '', quantity: 1, unit: 'pcs', unit_price: null, lot_number: '', expiry_date: '' }
          : line,
      ),
    )
  }

  const warehouses = Array.isArray(warehousesData) ? warehousesData : []
  const invoices = Array.isArray(invoicesData) ? invoicesData : []
  const quotations = Array.isArray(quotationsData) ? quotationsData : []

  return (
    <div className="space-y-6 p-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => navigate('/delivery-orders')}
            className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
          >
            <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
            Back
          </Button>
          <h1 className="text-3xl font-bold">Create Delivery Order</h1>
          <p className="text-muted-foreground mt-1">
            {mode === 'linked' ? 'Dispatch items from a confirmed sales order.' : 'Create a standalone delivery.'}
          </p>
          <div className="mt-2">
            <TagPicker value={tags} onChange={setTags} />
          </div>
        </div>

        {!preselectedSoId && (
          <div className="flex shrink-0 items-center gap-1.5 pt-8">
            <Button
              variant={mode === 'standalone' ? 'default' : 'outline'}
              size="sm"
              className="h-8 rounded-full px-3 text-xs"
              onClick={() => setMode('standalone')}
            >
              <Icon icon="solar:package-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
              Standalone Delivery
            </Button>
            <Button
              variant={mode === 'linked' ? 'default' : 'outline'}
              size="sm"
              className="h-8 rounded-full px-3 text-xs"
              onClick={() => setMode('linked')}
            >
              <Icon icon="solar:document-text-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
              From Sales Order
            </Button>
          </div>
        )}
      </div>

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
              {requiresApproval && (
                <div className="mt-1.5 flex items-center gap-1.5 rounded-md border border-orange-200 bg-orange-50 px-2 py-1 text-xs text-orange-700 dark:border-orange-900 dark:bg-orange-950/40 dark:text-orange-300">
                  <Icon icon="solar:checklist-bold-duotone" className="h-3.5 w-3.5" />
                  <span>
                    Will require approval before confirming
                    <span className="text-orange-600/70"> · set in Sales Settings</span>
                  </span>
                </div>
              )}
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
                {requiresApproval && (
                  <div className="mt-1.5 flex items-center gap-1.5 rounded-md border border-orange-200 bg-orange-50 px-2 py-1 text-xs text-orange-700 dark:border-orange-900 dark:bg-orange-950/40 dark:text-orange-300">
                    <Icon icon="solar:checklist-bold-duotone" className="h-3.5 w-3.5" />
                    <span>
                      Will require approval before confirming
                      <span className="text-orange-600/70"> · set in Sales Settings</span>
                    </span>
                  </div>
                )}
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
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <Label>Invoice No.</Label>
              <SearchableSelect
                value={invoiceId || 'none'}
                onValueChange={(value) => setInvoiceId(value === 'none' ? '' : value)}
                items={invoices.map((invoice: any) => ({ value: invoice.id.toString(), label: invoice.invoice_number }))}
                placeholder="Search invoice…"
              />
            </div>
            <div>
              <Label>Quotation No.</Label>
              <SearchableSelect
                value={quotationId || 'none'}
                onValueChange={(value) => setQuotationId(value === 'none' ? '' : value)}
                items={quotations.map((quotation: any) => ({ value: quotation.id.toString(), label: quotation.quotation_number }))}
                placeholder="Search quotation…"
              />
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
          <CardHeader className="flex flex-row items-center justify-between space-y-0">
            <CardTitle>Line Items — Quantities to Deliver</CardTitle>
            <SalesLineColumnMenu
              columns={DO_CREATE_LINKED_COLUMNS}
              hidden={hiddenLinkedColumns}
              setHidden={setHiddenLinkedColumns}
            />
          </CardHeader>
          <CardContent>
            {/* Issue #408: the order's policy decides whether this delivery may
                be partial; the backend refuses partials for when-all-ready. */}
            {soData.sales_order?.shipping_policy === 'when_all_ready' && (
              <div className="mb-3 flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300">
                <Icon icon="solar:box-bold-duotone" className="h-4 w-4 shrink-0" />
                <span>
                  Shipping policy: <strong>When all products are ready</strong> — this delivery order must cover
                  every remaining line in full; partial deliveries are refused.
                </span>
              </div>
            )}
            <div className="overflow-x-auto">
            <DeliveryOrderColumnVisibility
              tableClass="do-create-linked-columns"
              columns={DO_CREATE_LINKED_COLUMNS}
              hiddenColumns={hiddenLinkedColumns}
              onHiddenColumnsChange={setHiddenLinkedColumns}
              showMenu={false}
            >
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
            </div>
          </CardContent>
        </Card>
      )}

      {/* Standalone Mode: Line Items */}
      {mode === 'standalone' && (
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0">
            <CardTitle>Line Items</CardTitle>
            <SalesLineColumnMenu
              columns={DO_CREATE_STANDALONE_COLUMNS}
              hidden={hiddenStandaloneColumns}
              setHidden={setHiddenStandaloneColumns}
            />
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
                        <DeliveryOrderColumnVisibility
              tableClass="do-create-standalone-columns"
              columns={DO_CREATE_STANDALONE_COLUMNS}
              hiddenColumns={hiddenStandaloneColumns}
              onHiddenColumnsChange={setHiddenStandaloneColumns}
              showMenu={false}
            >
            <LineItemsTable
              variant="raw"
              tableClassName="w-full min-w-[1300px] text-sm"
              headRowClassName="border-b bg-muted/30"
              columns={productLineColumns({
                sku: true,
                description: true,
                modelNumber: true,
                productNotes: true,
                lotNumber: true,
                expiryDate: true,
                stock: true,
              })}
            >
                {standaloneLines.map((line, idx) => (
                  <tr
                    key={idx}
                    draggable={(line.line_type ?? 'product') === 'product'}
                    onDragStart={() => setDraggedLineIndex(idx)}
                    onDragOver={(event) => event.preventDefault()}
                    onDrop={() => {
                      if (draggedLineIndex !== null && draggedLineIndex !== idx) setStandaloneLines(reorderDocumentLines(standaloneLines, draggedLineIndex, idx))
                      setDraggedLineIndex(null)
                    }}
                    className="border-b transition-colors hover:bg-muted/20"
                  >
                    {line.line_type === 'note' || line.line_type === 'section' ? (
                      <DocumentTextLineRow
                        type={line.line_type}
                        text={line.description}
                        colSpan={13}
                        total={line.line_type === 'section' ? formatCurrency(getDocumentSectionTotal(standaloneLines, idx, (item) => (Number(item.quantity) || 0) * (Number(item.unit_price) || 0))) : undefined}
                        onChange={(text) => updateStandaloneLine(idx, 'description', text)}
                        onDelete={() => removeStandaloneLine(idx)}
                        onDragStart={() => setDraggedLineIndex(idx)}
                        onDrop={() => { if (draggedLineIndex !== null) setStandaloneLines(reorderDocumentLines(standaloneLines, draggedLineIndex, idx)); setDraggedLineIndex(null) }}
                      />
                    ) : <>
                      <td className={cn(LINE_CELL, 'w-10')}>
                        <div className={cn(LINE_CELL_BOX, 'justify-center text-xs font-medium text-muted-foreground')}>
                          {standaloneLines.slice(0, idx + 1).filter((item) => (item.line_type ?? 'product') === 'product').length}
                        </div>
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[17rem]', LINE_PRODUCT_WRAP)}>
                        <ProductSearchCombobox
                          value={line.product_id?.toString() || ''}
                          onValueChange={(val, product) => {
                            const productId = val ? parseInt(val) : null
                            // Switching products re-prices the line from the NEW
                            // product; re-selecting the same one keeps edits.
                            const productChanged = productId !== line.product_id
                            updateStandaloneLine(idx, 'product_id', productId)
                            updateStandaloneLine(idx, 'product_name', product?.name || '')
                            if (product && (productChanged || !line.description)) updateStandaloneLine(idx, 'description', getProductLineDescription(product))
                            if (product) {
                              updateStandaloneLine(idx, 'sku', (product as any).sku || (productChanged ? '' : line.sku))
                              updateStandaloneLine(idx, 'model_number', (product as any).manufacturer_part_number || (productChanged ? '' : line.model_number))
                              if (productChanged || !line.unit_price) updateStandaloneLine(idx, 'unit_price', parseFloat((product as any).price || (product as any).selling_price || '0') || null)
                              updateStandaloneLine(idx, 'price_mode', 'auto' as any)
                              updateStandaloneLine(idx, 'tracking_method', (product as any).tracking_method || 'none')
                              updateStandaloneLine(idx, 'product_batch_id', null)
                              updateStandaloneLine(idx, 'product_serial_id', null)
                              updateStandaloneLine(idx, 'lot_number', '')
                              updateStandaloneLine(idx, 'serial_number', '')
                              updateStandaloneLine(idx, 'expiry_date', '')
                            }
                          }}
                          placeholder="Search product..."
                          wrapText
                        />
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[10rem]')}>
                        <div className={LINE_VALUE}>{line.sku || <span className="opacity-60">SKU</span>}</div>
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[16rem]')}>
                        <textarea
                          value={line.description}
                          onChange={(event) => updateStandaloneLine(idx, 'description', event.target.value)}
                          rows={2}
                          className={LINE_TEXTAREA}
                          placeholder="Description"
                        />
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[7rem]')}>
                        <div className={LINE_VALUE}>{line.model_number || <span className="opacity-60">Model #</span>}</div>
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[10rem]')}>
                        <textarea
                          value={line.product_notes}
                          onChange={(event) => updateStandaloneLine(idx, 'product_notes', event.target.value)}
                          rows={2}
                          className={LINE_TEXTAREA}
                          placeholder="Product notes"
                        />
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[7.5rem]')}>
                        <ProductTrackingSelector
                          productId={line.product_id}
                          warehouseId={warehouseId}
                          trackingMethod={line.tracking_method}
                          batchId={line.product_batch_id}
                          serialId={line.product_serial_id}
                          onChange={(selection) => setStandaloneLines((current) => current.map((item, itemIndex) => itemIndex === idx ? { ...item, ...selection } : item))}
                        />
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[9.5rem]')}>
                        <div className={LINE_VALUE}>{line.expiry_date || <span className="opacity-60">Expiry</span>}</div>
                      </td>

                      <td className={cn(LINE_CELL, 'w-[5.5rem] min-w-[4.5rem]')}>
                        <div className={LINE_CELL_BOX}>
                          <input
                            type="number"
                            min="0"
                            step="0.01"
                            value={line.quantity ?? ''}
                            onChange={(event) => {
                              const raw = event.target.value
                              if (raw === '') { updateStandaloneLine(idx, 'quantity', ''); return }
                              const parsed = parseFloat(raw)
                              if (!Number.isNaN(parsed) && parsed >= 0) updateStandaloneLine(idx, 'quantity', parsed)
                            }}
                            className={LINE_INPUT_NUMERIC}
                          />
                        </div>
                      </td>

                      <td className={cn(LINE_CELL, 'w-[8.5rem] min-w-[7.5rem]')}>
                        <div className={cn(LINE_CELL_BOX, 'justify-end')}>
                          <StockAvailabilityChip
                            productId={line.product_id}
                            warehouseId={warehouseId}
                            requested={Number(line.quantity) || 0}
                          />
                        </div>
                      </td>

                      <td className={cn(LINE_CELL, 'w-[7rem] min-w-[6rem]')}>
                        <div className={LINE_CELL_BOX}>
                          <input
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="0.00"
                            value={line.unit_price ?? ''}
                            onChange={(event) => updateStandaloneLine(idx, 'unit_price', event.target.value === '' ? null : parseFloat(event.target.value))}
                            className={LINE_INPUT_NUMERIC}
                          />
                        </div>
                      </td>

                      <td className={cn(LINE_CELL, 'w-[7.5rem] min-w-[6.5rem]')}>
                        <div className={cn(LINE_CELL_BOX, 'justify-end text-sm font-semibold tabular-nums')}>
                          {formatCurrency((Number(line.quantity) || 0) * (Number(line.unit_price) || 0))}
                        </div>
                      </td>

                      <td className={cn(LINE_CELL, 'w-[10.5rem]')}>
                        <div className={cn(LINE_CELL_BOX, 'justify-end')}>
                          <ProductLineActions
                            onMoveUp={() => moveStandaloneLine(idx, 'up')}
                            onMoveDown={() => moveStandaloneLine(idx, 'down')}
                            canMoveUp={idx !== 0}
                            canMoveDown={idx !== standaloneLines.length - 1}
                            onDuplicate={() => duplicateStandaloneLine(idx)}
                            onReset={() => resetStandaloneLine(idx)}
                            onDelete={() => removeStandaloneLine(idx)}
                          />
                        </div>
                      </td>
                    </>}
                  </tr>
                ))}
            </LineItemsTable>
            </DeliveryOrderColumnVisibility>
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
              <ProductCatalogDialog products={productsData || []} onAdd={addCatalogProducts} />
              <HeaderActionButton type="button" variant="outline" icon="solar:add-circle-bold" onClick={addStandaloneLine}>
                Add Line
              </HeaderActionButton>
              <HeaderActionButton type="button" variant="outline" onClick={() => addStandaloneTextLine('section')}>Add Section</HeaderActionButton>
              <HeaderActionButton type="button" variant="outline" onClick={() => addStandaloneTextLine('note')}>Add Note</HeaderActionButton>
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
